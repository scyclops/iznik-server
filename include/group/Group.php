<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/user/MembershipCollection.php');
require_once(IZNIK_BASE . '/include/misc/Shortlink.php');
require_once(IZNIK_BASE . '/include/message/Attachment.php');
require_once(IZNIK_BASE . '/include/group/Facebook.php');

class Group extends Entity
{
    # We have a cache of groups, because we create groups a _lot_, and this can speed things up significantly by avoiding
    # hitting the DB.  This is only preserved within this process.
    static $processCache = [];
    static $processCacheDeleted = [];
    const PROCESS_CACHE_SIZE = 100;

    # We also cache the objects in redis, to reduce DB load.  This is shared across processes.
    const REDIS_CACHE_EXPIRY = 600;
    
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'nameshort', 'namefull', 'nameabbr', 'namedisplay', 'settings', 'type', 'region', 'logo', 'publish',
        'onyahoo', 'onhere', 'ontn', 'trial', 'licenserequired', 'licensed', 'licenseduntil', 'membercount', 'modcount', 'lat', 'lng',
        'profile', 'cover', 'onmap', 'tagline', 'legacyid', 'showonyahoo', 'external', 'welcomemail', 'description',
        'contactmail', 'fundingtarget', 'affiliationconfirmed', 'affiliationconfirmedby', 'mentored', 'privategroup', 'defaultlocation');

    const GROUP_REUSE = 'Reuse';
    const GROUP_FREEGLE = 'Freegle';
    const GROUP_OTHER = 'Other';
    const GROUP_UT = 'UnitTest';

    const POSTING_MODERATED = 'MODERATED';
    const POSTING_PROHIBITED = 'PROHIBITED';
    const POSTING_DEFAULT = 'DEFAULT';
    const POSTING_UNMODERATED = 'UNMODERATED';

    const FILTER_NONE = 0;
    const FILTER_WITHCOMMENTS = 1;
    const FILTER_MODERATORS = 2;
    const FILTER_BOUNCING = 3;
    const FILTER_MOSTACTIVE = 4;

    /** @var  $log Log */
    private $log;

    public $defaultSettings;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $atts = NULL)
    {
        if ($atts) {
            # We've been passed all the atts we need to construct the group
            $this->fetch($dbhr, $dbhm, $id, 'groups', 'group', $this->publicatts, $atts, FALSE);
        } else {
            # We cache groups in redis, to reduce DB load.  Because we do it at the group level, we don't use the
            # generalised DB query caching in db.php, so we disable that by appropriate parameters to DB calls and
            # fetch().
            $this->cachekey = $id ? "group-$id" : NULL;

            # Check if this group is in redis.
            $cached = $this->getRedis()->mget([ $this->cachekey ]);

            if ($cached && $cached[0]) {
                # We got it.  That saves us some DB ops.
                $obj = unserialize($cached[0]);

                foreach ($obj as $key => $val) {
                    #error_log("Restore $key => " . var_export($val, TRUE));
                    $this->$key = $val;
                }

                # We didn't serialise the PDO objects.
                $this->dbhr = $dbhr;
                $this->dbhm = $dbhm;
            } else {
                # We didn't find it in redis.
                $this->fetch($dbhr, $dbhm, $id, 'groups', 'group', $this->publicatts, NULL, FALSE);

                if ($id && !$this->id) {
                    # We were passed an id, but didn't find the group.  See if the id is a legacyid.
                    #
                    # This assumes that the legacy and current ids don't clash.  Which they don't.  So that's a good assumption.
                    $groups = $this->dbhr->preQuery("SELECT id FROM groups WHERE legacyid = ?;", [ $id ]);
                    foreach ($groups as $group) {
                        $this->fetch($dbhr, $dbhm, $group['id'], 'groups', 'group', $this->publicatts, NULL, FALSE);
                    }
                }

                if ($id) {
                    # Store object in redis for next time.
                    $this->dbhm = NULL;
                    $this->dbhr = NULL;
                    $s = serialize($this);
                    $this->dbhm = $dbhm;
                    $this->dbhr = $dbhr;

                    $this->getRedis()->setex($this->cachekey, Group::REDIS_CACHE_EXPIRY, $s);
                }
            }
        }

        $this->setDefaults();
    }

    public function setDefaults() {
        $this->defaultSettings = [
            'showchat' => 1,
            'communityevents' => 1,
            'volunteering' => 1,
            'stories' => 1,
            'includearea' => 1,
            'includepc' => 1,
            'moderated' => 0,
            'allowedits' => [
                'moderated' => 1,
                'group' => 1
            ],
            'autoapprove' => [
                'members' => 0,
                'messages' => 0
            ], 'duplicates' => [
                'check' => 1,
                'offer' => 14,
                'taken' => 14,
                'wanted' => 14,
                'received' => 14
            ], 'spammers' => [
                'check' => $this->group['type'] == Group::GROUP_FREEGLE,
                'remove' => $this->group['type'] == Group::GROUP_FREEGLE,
                'chatreview' => $this->group['type'] == Group::GROUP_FREEGLE,
                'messagereview' => 1
            ], 'joiners' => [
                'check' => 1,
                'threshold' => 5
            ], 'keywords' => [
                'OFFER' => 'OFFER',
                'TAKEN' => 'TAKEN',
                'WANTED' => 'WANTED',
                'RECEIVED' => 'RECEIVED'
            ], 'reposts' => [
                'offer' => 3,
                'wanted' => 7,
                'max' => 5,
                'chaseups' => 5
            ],
            'relevant' => 1,
            'newsfeed' => 1,
            'newsletter' => 1,
            'businesscards' => 1,
            'autoadmins' => 1,
            'approvemembers' => 0,
            'mentored' => 0
        ];

        if (!$this->group['settings'] || strlen($this->group['settings']) == 0) {
            $this->group['settings'] = json_encode($this->defaultSettings);
        }

        $this->log = new Log($this->dbhr, $this->dbhm);
    }

    public static function get(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $gsecache = TRUE) {
        if ($id) {
            # We cache the constructed group.
            if ($gsecache && array_key_exists($id, Group::$processCache) && Group::$processCache[$id]->getId() == $id) {
                # We found it.
                #error_log("Found $id in cache");

                # @var Group
                $g = Group::$processCache[$id];

                if (!Group::$processCacheDeleted[$id]) {
                    # And it's not zapped - so we can use it.
                    #error_log("Not zapped");
                    return ($g);
                } else {
                    # It's zapped - so refetch.
                    #error_log("Zapped, refetch " . $id);
                    $g->fetch($g->dbhr, $g->dbhm, $id, 'groups', 'group', $g->publicatts, NULL, FALSE);

                    if (!$g->group['settings'] || strlen($g->group['settings']) == 0) {
                        $g->group['settings'] = json_encode($g->defaultSettings);
                    }

                    Group::$processCache[$id] = $g;
                    Group::$processCacheDeleted[$id] = FALSE;
                    return($g);
                }
            }
        }

        #error_log("$id not in cache");
        $g = new Group($dbhr, $dbhm, $id);

        if ($id && count(Group::$processCache) < Group::PROCESS_CACHE_SIZE) {
            # Store for next time in this process.
            #error_log("store $id in cache");
            Group::$processCache[$id] = $g;
            Group::$processCacheDeleted[$id] = FALSE;
        }

        return($g);
    }

    public static function clearCache($id = NULL) {
        # Remove this group from our process cache.
        #error_log("Clear $id from cache");
        if ($id) {
            Group::$processCacheDeleted[$id] = TRUE;
        } else {
            Group::$processCache = [];
            Group::$processCacheDeleted = [];
        }

        # And redis.
        $cache = new Redis();
        @$cache->pconnect(REDIS_CONNECT);

        if ($cache->isConnected()) {
            $cache->del("group-$id");
        }
    }

    /**
     * @param LoggedPDO $dbhm
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    public function getDefaults() {
        return($this->defaultSettings);
    }

    public function setPrivate($att, $val) {
        # We override this in order to clear our cache, which would otherwise be out of date.
        parent::setPrivate($att, $val);

        if ($att == 'poly' || $att == 'polyofficial') {
            $this->dbhm->preExec("UPDATE groups SET polyindex = GeomFromText(COALESCE(poly, polyofficial, 'POINT(0 0)')) WHERE id = ?;", [
                $this->id
            ]);
        }
        Group::clearCache($this->id);
    }

    public function create($shortname, $type) {
        try {
            # Check for duplicate.  Might still occur in a timing window but in that rare case we'll get an exception
            # and catch that, failing the call.
            $groups = $this->dbhm->preQuery("SELECT id FROM groups WHERE nameshort = ?;", [ $shortname ]);
            foreach ($groups as $group) {
                return(NULL);
            }

            $rc = $this->dbhm->preExec("INSERT INTO groups (nameshort, type, founded, licenserequired, onyahoo, polyindex) VALUES (?, ?, NOW(),?,?,POINT(0, 0))", [
                $shortname,
                $type,
                $type != Group::GROUP_FREEGLE ? 0 : 1,
                $type != Group::GROUP_FREEGLE ? 1 : 0
            ]);

            $id = $this->dbhm->lastInsertId();

            if ($type == Group::GROUP_FREEGLE) {
                # Also create a shortlink.
                $linkname = str_ireplace('Freegle', '', $shortname);
                $linkname = str_replace('-', '', $linkname);
                $linkname = str_replace('_', '', $linkname);
                $s = new Shortlink($this->dbhr, $this->dbhm);
                $sid = $s->create($linkname, Shortlink::TYPE_GROUP, $id);

                # And a group chat.
                $r = new ChatRoom($this->dbhr, $this->dbhm);
                $r->createGroupChat("$shortname Volunteers", $id, TRUE, TRUE);
                $r->setPrivate('description', "$shortname Volunteers");
            }
        } catch (Exception $e) {
            error_log("Create group exception " . $e->getMessage());
            $id = NULL;
            $rc = 0;
        }

        if ($rc && $id) {
            $this->fetch($this->dbhm, $this->dbhm, $id, 'groups', 'group', $this->publicatts);
            $this->log->log([
                'type' => Log::TYPE_GROUP,
                'subtype' => Log::SUBTYPE_CREATED,
                'groupid' => $id,
                'text' => $shortname
            ]);

            return($id);
        } else {
            return(NULL);
        }
    }

    public function getMods() {
        $sql = "SELECT users.id FROM users INNER JOIN memberships ON users.id = memberships.userid AND memberships.groupid = ? AND role IN ('Owner', 'Moderator');";
        $mods = $this->dbhr->preQuery($sql, [ $this->id ]);
        $ret = [];
        foreach ($mods as $mod) {
            $ret[] = $mod['id'];
        }
        return($ret);
    }

    public function getModsEmail() {
        # This is an address used when we are sending to volunteers, or in response to an action by a volunteer.
        if (pres('contactmail', $this->group)) {
            $ret = $this->group['contactmail'];
        } else if (pres('onyahoo', $this->group)) {
            $ret = $this->group['nameshort'] . "-owner@yahoogroups.com";
        } else {
            $ret = $this->group['nameshort'] . "-volunteers@" . GROUP_DOMAIN;
        }

        return($ret);
    }

    public function getAutoEmail() {
        # This is an address used when we are sending automatic emails for a group.
        if ($this->group['contactmail']) {
            $ret = $this->group['contactmail'];
        } else {
            $ret = $this->group['nameshort'] . "-auto@" . GROUP_DOMAIN;
        }

        return($ret);
    }

    public function getGroupEmail() {
        if ($this->group['onyahoo']) {
            $ret = $this->group['nameshort'] . "@yahoogroups.com";
        } else {
            $ret = $this->group['nameshort'] . '@' . GROUP_DOMAIN;
        }

        return($ret);
    }

    public function getGroupSubscribe() {
        return($this->group['nameshort'] . "-subscribe@yahoogroups.com");
    }

    public function getGroupUnsubscribe() {
        return($this->group['nameshort'] . "-unsubscribe@yahoogroups.com");
    }

    public function getGroupNoEmail() {
        return($this->group['nameshort'] . "-nomail@yahoogroups.com");
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM groups WHERE id = ?;", [$this->id]);
        if ($rc) {
            $this->log->log([
                'type' => Log::TYPE_GROUP,
                'subtype' => Log::SUBTYPE_DELETED,
                'groupid' => $this->id
            ]);
        }

        return($rc);
    }

    public function findByShortName($name) {
        $groups = $this->dbhr->preQuery("SELECT id FROM groups WHERE nameshort LIKE ?;",
            [
                trim($name)
            ]);

        foreach ($groups as $group) {
            return($group['id']);
        }

        return(NULL);
    }

    public function getWorkCounts($mysettings, $groupids) {
        $ret = [];

        if ($groupids) {
            $groupq = "(" . implode(',', $groupids) . ")";

            $earliestmsg = date("Y-m-d", strtotime("Midnight 31 days ago"));
            $eventsqltime = date("Y-m-d H:i:s", time());

            # We only want to show spam messages upto 31 days old to avoid seeing too many, especially on first use.
            #
            # See also MessageCollection.
            $pendingspamcounts = $this->dbhr->preQuery("SELECT messages_groups.groupid, COUNT(*) AS count, messages_groups.collection, messages.heldby IS NOT NULL AS held FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid AND messages_groups.groupid IN $groupq AND messages_groups.collection IN (?, ?) AND messages_groups.deleted = 0 AND messages.deleted IS NULL AND messages.arrival >= '$earliestmsg' GROUP BY messages_groups.groupid, messages_groups.collection, held;", [
                MessageCollection::PENDING,
                MessageCollection::SPAM
            ], FALSE, FALSE);

            $heldmembercounts = $this->dbhr->preQuery("SELECT memberships.groupid, COUNT(*) AS count, collection, heldby IS NOT NULL AS held FROM memberships WHERE collection = ? AND groupid IN $groupq GROUP BY memberships.groupid, collection, held;", [
                MembershipCollection::PENDING
            ], FALSE, FALSE);

            $spammembercounts = $this->dbhr->preQuery(
                "SELECT memberships.groupid, COUNT(*) AS count, heldby IS NOT NULL AS held FROM memberships 
INNER JOIN users ON users.id = memberships.userid AND suspectcount > 0
WHERE groupid IN $groupq 
GROUP BY memberships.groupid, held     
UNION
SELECT memberships.groupid, COUNT(*) AS count, heldby IS NOT NULL AS held FROM memberships 
INNER JOIN spam_users ON spam_users.userid = memberships.userid AND spam_users.collection = '" . Spam::TYPE_SPAMMER . "'
WHERE groupid IN $groupq 
GROUP BY memberships.groupid, held;     
", [], FALSE, FALSE);

            $pendingeventcounts = $this->dbhr->preQuery("SELECT groupid, COUNT(DISTINCT communityevents.id) AS count FROM communityevents INNER JOIN communityevents_dates ON communityevents_dates.eventid = communityevents.id INNER JOIN communityevents_groups ON communityevents.id = communityevents_groups.eventid WHERE communityevents_groups.groupid IN $groupq AND communityevents.pending = 1 AND communityevents.deleted = 0 AND end >= ? GROUP BY groupid;", [
                $eventsqltime
            ]);

            $pendingvolunteercounts = $this->dbhr->preQuery("SELECT groupid, COUNT(DISTINCT volunteering.id) AS count FROM volunteering LEFT JOIN volunteering_dates ON volunteering_dates.volunteeringid = volunteering.id INNER JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid WHERE volunteering_groups.groupid IN $groupq AND volunteering.pending = 1 AND volunteering.deleted = 0 AND volunteering.expired = 0 AND (applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?) GROUP BY groupid;", [
                $eventsqltime,
                $eventsqltime
            ]);

            $pendingadmins = $this->dbhr->preQuery("SELECT groupid, COUNT(DISTINCT admins.id) AS count FROM admins WHERE admins.groupid IN $groupq AND admins.complete IS NULL AND admins.pending = 1 GROUP BY groupid;");

            # We only want to show edit reviews upto 7 days old - after that assume they're ok.
            #
            # See also MessageCollection.
            $mysqltime = date("Y-m-d", strtotime("Midnight 7 days ago"));
            $editreviewcounts = $this->dbhr->preQuery("SELECT groupid, COUNT(DISTINCT messages_edits.msgid) AS count FROM messages_edits INNER JOIN messages_groups ON messages_edits.msgid = messages_groups.msgid WHERE timestamp > '$mysqltime' AND reviewrequired = 1 AND messages_groups.groupid IN $groupq AND messages_groups.deleted = 0 GROUP BY groupid;");

            foreach ($groupids as $groupid) {
                # Depending on our group settings we might not want to show this work as primary; "other" work is displayed
                # less prominently in the client.
                #
                # If we have the active flag use that; otherwise assume that the legacy showmessages flag tells us.  Default
                # to active.
                # TODO Retire showmessages entirely and remove from user configs.
                $active = array_key_exists('active', $mysettings[$groupid]) ? $mysettings[$groupid]['active'] : (!array_key_exists('showmessages', $mysettings[$groupid]) || $mysettings[$groupid]['showmessages']);

                $thisone = [
                    'pending' => 0,
                    'pendingother' => 0,
                    'spam' => 0,
                    'pendingmembers' => 0,
                    'pendingmembersother' => 0,
                    'pendingevents' => 0,
                    'pendingvolunteering' => 0,
                    'spammembers' => 0,
                    'spammembersother' => 0,
                    'editreview' => 0,
                    'pendingadmins' => 0
                ];

                if ($active) {
                    foreach ($pendingspamcounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            if ($count['collection'] == MessageCollection::PENDING) {
                                if ($count['held']) {
                                    $thisone['pendingother'] = $count['count'];
                                } else {
                                    $thisone['pending'] = $count['count'];
                                }
                            } else {
                                $thisone['spam'] = $count['count'];
                            }
                        }
                    }

                    foreach ($heldmembercounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            if ($count['held']) {
                                $thisone['pendingmembersother'] = $count['count'];
                            } else {
                                $thisone['pendingmembers'] = $count['count'];
                            }
                        }
                    }

                    foreach ($spammembercounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            if ($count['held']) {
                                $thisone['spammembersother'] = $count['count'];
                            } else {
                                $thisone['spammembers'] = $count['count'];
                            }
                        }
                    }

                    foreach ($pendingeventcounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['pendingevents'] = $count['count'];
                        }
                    }

                    foreach ($pendingvolunteercounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['pendingvolunteering'] = $count['count'];
                        }
                    }

                    foreach ($editreviewcounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['editreview'] = $count['count'];
                        }
                    }

                    foreach ($pendingadmins as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['pendingadmins'] = $count['count'];
                        }
                    }
                } else {
                    foreach ($pendingspamcounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            if ($count['collection'] == MessageCollection::SPAM) {
                                $thisone['spamother'] = $count['count'];
                            }
                        }
                    }

                    foreach ($heldmembercounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            if ($count['collection'] == MembershipCollection::SPAM) {
                            } else {
                                $thisone['spammembersother'] = $count['count'];
                            }
                        }
                    }

                    foreach ($spammembercounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['spammembersother'] = $count['count'];
                        }
                    }
                }

                $ret[$groupid] = $thisone;
            }
        }

        return($ret);
    }

    public function getPublic($summary = FALSE) {
        $atts = parent::getPublic();

        # Contact mails
        $atts['modsemail'] = $this->getModsEmail();
        $atts['autoemail'] = $this->getAutoEmail();
        $atts['groupemail'] = $this->getGroupEmail();

        # Add in derived properties.
        $atts['namedisplay'] = $atts['namefull'] ? $atts['namefull'] : $atts['nameshort'];
        $atts['lastyahoomembersync'] = ISODate($this->group['lastyahoomembersync']);
        $atts['lastyahoomessagesync'] = ISODate($this->group['lastyahoomessagesync']);
        $settings = json_decode($atts['settings'], true);

        if ($settings) {
            $atts['settings'] = array_replace_recursive($this->defaultSettings, $settings);
        } else {
            $atts['settings'] = $this->defaultSettings;
        }

        $atts['founded'] = ISODate($this->group['founded']);

        foreach (['trial', 'licensed', 'licenseduntil', 'affiliationconfirmed'] as $datefield) {
            $atts[$datefield] = pres($datefield, $atts) ? ISODate($atts[$datefield]) : NULL;
        }

        # Images.  We pass those ids in to get the paths.  This removes the DB operations for constructing the
        # Attachment, which is valuable for people on many groups.
        if (defined('IMAGE_DOMAIN')) {
            $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);
            $b = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);

            $atts['profile'] = $atts['profile'] ? $a->getPath(FALSE, $atts['profile']) : NULL;
            $atts['cover'] = $atts['cover'] ? $b->getPath(FALSE, $atts['cover']) : NULL;
        }

        $atts['url'] = $atts['onhere'] ? ('https://' . USER_SITE . '/explore/' . $atts['nameshort']) : ("https://groups.yahoo.com/neo/groups/" . $atts['nameshort'] . "/info");

        if ($summary) {
            foreach (['settings', 'description', 'welcomemail'] as $att) {
                unset($atts[$att]);
            }
        } else {
            if (pres('defaultlocation', $atts)) {
                $l = new Location($this->dbhr, $this->dbhm, $atts['defaultlocation']);
                $atts['defaultlocation'] = $l->getPublic();
            }
        }

        return($atts);
    }

    public function exportYahoo($groupid) {
        $members = $this->dbhr->preQuery("SELECT members FROM memberships_yahoo_dump WHERE groupid = ?;", [ $groupid ]);
        foreach ($members as $member) {
            return(json_decode($member['members'], TRUE));
        }

        return(NULL);
    }

    public function getMembers($limit = 10, $search = NULL, &$ctx = NULL, $searchid = NULL, $collection = MembershipCollection::APPROVED, $groupids = NULL, $yps = NULL, $ydt = NULL, $ops = NULL, $filter = Group::FILTER_NONE) {
        $ret = [];
        $groupids = $groupids ? $groupids : ($this->id ? [ $this-> id ] : NULL);

        if ($search) {
            # Remove wildcards - people put them in, but that's not how it works.
            $search = str_replace('*', '', $search);
        }

        # If we're searching for a notify address, switch to the user it.
        $search = preg_match('/notify-(.*)-(.*)' . USER_DOMAIN . '/', $search, $matches) ? $matches[2] : $search;

        $date = $ctx == NULL ? NULL : $this->dbhr->quote(date("Y-m-d H:i:s", $ctx['Added']));
        $addq = $ctx == NULL ? '' : (" AND (memberships.added < $date OR memberships.added = $date AND memberships.id < " . $this->dbhr->quote($ctx['id']) . ") ");
        $groupq = $groupids ? " memberships.groupid IN (" . implode(',', $groupids) . ") " : " 1=1 ";
        $opsq = $ops ? (" AND memberships.ourPostingStatus = " . $this->dbhr->quote($ydt)) : '';
        $modq = '';
        $bounceq = '';
        $filterq = '';
        $uq = '';

        switch ($filter) {
            case Group::FILTER_WITHCOMMENTS:
                $filterq = ' INNER JOIN users_comments ON users_comments.userid = memberships.userid ';
                $filterq = $groupids ? ("$filterq AND users_comments.groupid IN (" . implode(',', $groupids) . ") ") : $filterq;
                break;
            case Group::FILTER_MODERATORS:
                $filterq = '';
                $modq = " AND memberships.role IN ('Owner', 'Moderator') ";
                break;
            case Group::FILTER_BOUNCING:
                $bounceq = ' AND users.bouncing = 1 ';
                $uq = $uq ? $uq : ' INNER JOIN users ON users.id = memberships.userid ';
                break;
            default:
                $filterq = '';
                break;
        }

        # Collection filter.  If we're searching on a specific id then don't put it in.
        $collectionq = '';

        if (!$searchid) {
            if ($collection == MembershipCollection::SPAM) {
                # This collection is handled separately; we use the suspectcount field.
                #
                # This is to avoid moving members into a spam collection and then having to remember whether they
                # came from Pending or Approved.
                $collectionq = " AND (suspectcount > 0 OR memberships.userid IN (SELECT userid FROM spam_users WHERE spam_users.collection = '" . Spam::TYPE_SPAMMER . "')) ";
                $uq = $uq ? $uq : ' INNER JOIN users ON users.id = memberships.userid ';
            } else if ($collection) {
                $collectionq = ' AND memberships.collection = ' . $this->dbhr->quote($collection) . ' ';
            }
        }

        $sqlpref = "SELECT DISTINCT memberships.*, groups.onyahoo FROM memberships 
              INNER JOIN groups ON groups.id = memberships.groupid
              $uq
              $filterq";

        if ($search) {
            # We're searching.  It turns out to be more efficient to get the userids using the indexes, and then
            # get the rest of the stuff we need.
            $q = $this->dbhr->quote("$search%");
            $bq = $this->dbhr->quote(strrev($search) . "%");
            $p = strpos($search, ' ');
            $namesearch = $p === FALSE ? '' : ("(SELECT id FROM users WHERE firstname LIKE " . $this->dbhr->quote(substr($search, 0, $p) . '%') . " AND lastname LIKE " . $this->dbhr->quote(substr($search, $p + 1) . '%')) . ') UNION';
            $sql = "$sqlpref 
              INNER JOIN users ON users.id = memberships.userid 
              LEFT JOIN users_emails ON memberships.userid = users_emails.userid 
              WHERE users.id IN (SELECT * FROM (
                (SELECT userid FROM users_emails WHERE email LIKE $q) UNION
                (SELECT userid FROM users_emails WHERE backwards LIKE $bq) UNION
                (SELECT id FROM users WHERE id = " . $this->dbhr->quote($search) . ") UNION
                (SELECT id FROM users WHERE fullname LIKE $q) UNION
                (SELECT id FROM users WHERE yahooid LIKE $q) UNION
                $namesearch
                (SELECT userid FROM memberships_yahoo INNER JOIN memberships ON memberships_yahoo.membershipid = memberships.id WHERE yahooAlias LIKE $q)
              ) t) AND 
              $groupq $collectionq $addq $opsq";
        } else {
            $searchq = $searchid ? (" AND memberships.userid = " . $this->dbhr->quote($searchid) . " ") : '';
            $sql = "$sqlpref WHERE $groupq $collectionq $addq $searchq $opsq $modq $bounceq";
        }

        $sql .= " ORDER BY memberships.added DESC, memberships.id DESC LIMIT $limit;";

        $members = $this->dbhr->preQuery($sql);

        $ctx = [ 'Added' => NULL ];

        foreach ($members as $member) {
            $u = User::get($this->dbhr, $this->dbhm, $member['userid']);
            $thisone = $u->getPublic($groupids, TRUE);
            #error_log("{$member['userid']} has " . count($thisone['comments']));

            # We want to return an id of the membership, because the same user might be pending on two groups, and
            # a userid of the user's id.
            $thisone['userid'] = $thisone['id'];
            $thisone['id'] = $member['id'];

            $thisepoch = strtotime($member['added']);

            if ($ctx['Added'] == NULL || $thisepoch < $ctx['Added']) {
                $ctx['Added'] = $thisepoch;
            }

            $ctx['id'] = $member['id'];

            # We want to return both the email used on this group and any others we have.
            $emails = $u->getEmails();
            $email = NULL;
            $others = [];

            # Groups we host only use a single email.
            $email = $u->getEmailPreferred();
            foreach ($emails as $anemail) {
                if ($anemail['email'] != $email) {
                    $others[] = $anemail;
                }
            }

            $thisone['joined'] = ISODate($member['added']);

            # Defaults match ones in User.php
            #error_log("Settings " . var_export($member, TRUE));
            $thisone['settings'] = $member['settings'] ? json_decode($member['settings'], TRUE) : [
                'active' => 1,
                'showchat' => 1,
                'pushnotify' => 1,
                'eventsallowed' => 1
            ];

            # Sort so that we have a deterministic order for UT.
            usort($others, function($a, $b) {
                return(strcmp($a['email'], $b['email']));
            });

            $thisone['settings']['configid'] = $member['configid'];
            $thisone['email'] = $email;
            $thisone['groupid'] = $member['groupid'];
            $thisone['otheremails'] = $others;
            $thisone['role'] = $u->getRoleForGroup($member['groupid'], FALSE);
            $thisone['emailfrequency'] = $member['emailfrequency'];
            $thisone['eventsallowed'] = $member['eventsallowed'];
            $thisone['volunteeringallowed'] = $member['volunteeringallowed'];

            # Our posting status only applies for groups we host.  In that case, the default is moderated.
            $thisone['ourpostingstatus'] = presdef('ourPostingStatus', $member, Group::POSTING_MODERATED);

            $thisone['heldby'] = $member['heldby'];

            if (pres('heldby', $thisone)) {
                $u = User::get($this->dbhr, $this->dbhm, $thisone['heldby']);
                $ctx = NULL;
                $thisone['heldby'] = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE);
            }

            if ($filter === Group::FILTER_MODERATORS) {
                # Also add in the last mod time.
                $acts = $this->dbhr->preQuery("SELECT MAX(timestamp) AS moderated FROM logs WHERE byuser = ? AND groupid = ? AND logs.type = ? AND subtype = ?;", [
                    $thisone['userid'],
                    $thisone['groupid'],
                    Log::TYPE_MESSAGE,
                    Log::SUBTYPE_APPROVED
                ]);

                foreach ($acts as $act) {
                    $thisone['lastmoderated'] = ISODate($act['moderated']);
                }
            }

            $ret[] = $thisone;
        }

        return($ret);
    }

    public function getHappinessMembers($groupids, &$ctx) {
        $start = microtime(TRUE);
        $ret = [];
        $groupids = $groupids ? $groupids : ($this->id ? [ $this-> id ] : NULL);
        $groupq2 = $groupids ? " messages_groups.groupid IN (" . implode(',', $groupids) . ") " : " 1=1 ";

        $ctxq = $ctx == NULL ? "" : " WHERE (messages_outcomes.timestamp < '{$ctx['timestamp']}' OR (messages_outcomes.timestamp = '{$ctx['timestamp']}' AND messages_outcomes.id < {$ctx['id']}))";

        $sql = "SELECT t.*, messages.fromuser, messages_groups.groupid, messages.subject FROM 
(SELECT * FROM messages_outcomes $ctxq) t
INNER JOIN messages_groups ON messages_groups.msgid = t.msgid AND $groupq2
INNER JOIN messages ON messages.id = t.msgid
ORDER BY t.timestamp DESC, t.id DESC LIMIT 10
";
        $members = $this->dbhr->preQuery($sql, [], FALSE, FALSE);
        $last = NULL;

        foreach ($members as $member) {
            # Ignore dups.
            if ($last && $member['msgid'] == $last) {
                continue;
            }

            $last = $member['msgid'];

            $ctx = [
                'id' => $member['id'],
                'timestamp' => $member['timestamp']
            ];

            $u = User::get($this->dbhr, $this->dbhm, $member['fromuser']);
            $atts = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE, NULL, FALSE);

            $member['user']  = $atts;
            $member['user']['email'] = $u->getEmailPreferred();
            #unset($member['userid']);

            $m = new Message($this->dbhr, $this->dbhm, $member['msgid']);
            $member['message'] = [
                'id' => $member['msgid'],
                'subject' => $member['subject'],
            ];
            unset($member['msgid']);
            unset($member['subject']);

            $member['timestamp'] = ISODate($member['timestamp']);
            $ret[] = $member;
        }

        return($ret);
    }

    private function getYahooRole($memb) {
        $yahoorole = User::ROLE_MEMBER;
        if (pres('yahooModeratorStatus', $memb)) {
            if ($memb['yahooModeratorStatus'] == 'MODERATOR') {
                $yahoorole = User::ROLE_MODERATOR;
            } else if ($memb['yahooModeratorStatus'] == 'OWNER') {
                $yahoorole = User::ROLE_OWNER;
            }
        }

        return($yahoorole);
    }

    public function queueSetMembers($members, $synctime) {
        # This is used for Approved members only, and will be picked up by a background script which calls
        # setMembers.  This is used to move this expensive processing off the application server.
        $this->dbhm->preExec("REPLACE INTO memberships_yahoo_dump (groupid, members, lastupdated, synctime) VALUES (?,?,NOW(),?);", [$this->id, json_encode($members), $synctime]);
    }

    public function processSetMembers($groupid = NULL) {
        # This is called from the background script.  It's serialised, so we don't need to worry about other
        # copies.
        $sql = $groupid ? "SELECT * FROM memberships_yahoo_dump WHERE groupid = $groupid;" : "SELECT * FROM memberships_yahoo_dump WHERE lastprocessed IS NULL AND backgroundok = 1 UNION SELECT * FROM memberships_yahoo_dump WHERE needsprocessing = 1 AND backgroundok = 1;";
        $groups = $this->dbhr->preQuery($sql);
        $count = 0;

        foreach ($groups as $group) {
            $g = Group::get($this->dbhm, $this->dbhm, $group['groupid']);
            try {
                # Use master for sync to avoid caching, which can break our sync process.
                error_log("Sync group " . $g->getPrivate('nameshort') . " $count / " . count($groups) . " time {$group['synctime']}");
                $g->setMembers(json_decode($group['members'], TRUE),  MembershipCollection::APPROVED, $group['synctime']);
                $this->dbhm->preExec("UPDATE memberships_yahoo_dump SET lastprocessed = NOW() WHERE groupid = ?;", [ $group['groupid']]);
            } catch (Exception $e) {
                error_log("Sync failed with " . $e->getMessage());
            }

            $count++;
        }
    }

    public function setNativeRoles() {
        # This is used when migrating a group from Yahoo to this platform.  We find the owners and mods on Yahoo,
        # and give them that status on here.
        $mods = $this->dbhr->preQuery("SELECT memberships.userid, memberships_yahoo.role FROM memberships_yahoo INNER JOIN memberships ON memberships.id = memberships_yahoo.membershipid WHERE groupid = ? AND memberships_yahoo.role IN ('Owner', 'Moderator');",
            [
                $this->id
            ]);

        foreach ($mods as $mod) {
            $this->dbhm->preExec("UPDATE memberships SET role = ? WHERE userid = ? AND groupid = ?;", [
                $mod['role'],
                $mod['userid'],
                $this->id,
            ]);
        }
    }

    public function ourPS($status) {
        # For historical reasons, the ourPostingStatus field has various values, equivalent to those on Yahoo.  But
        # we only support two settings - MODERATED, and DEFAULT aka Group Settings.
        switch ($status) {
            case NULL: $status = NULL; break;
            case Group::POSTING_MODERATED: $status = Group::POSTING_MODERATED; break;
            case Group::POSTING_PROHIBITED: $status = Group::POSTING_MODERATED; break;
            default: $status = Group::POSTING_DEFAULT; break;
        }

        return($status);
    }

    public function setNativeModerationStatus() {
        # This is used when migrating a group from Yahoo to this platform.
        $mods = $this->dbhr->preQuery("SELECT memberships.userid, memberships_yahoo.yahooPostingStatus FROM memberships_yahoo INNER JOIN memberships ON memberships.id = memberships_yahoo.membershipid WHERE groupid = ? AND memberships_yahoo.collection = 'Approved';",
            [
                $this->id
            ]);

        foreach ($mods as $mod) {
            $this->dbhm->preExec("UPDATE memberships SET ourPostingStatus = ? WHERE userid = ? AND groupid = ?;", [
                $this->ourPS($mod['yahooPostingStatus']),
                $mod['userid'],
                $this->id,
            ]);
        }
    }

    public function setMembers($members, $collection, $synctime = NULL) {
        # This is used to set the whole of the membership list for a group.  It's only used when the group is
        # mastered on Yahoo, rather than by us.
        #
        # Slightly surprisingly, we don't need to do this in a transaction.  This is because:
        # - adding memberships on here which exist on Yahoo is fine to fail halfway through, we've just got some
        #   of them which is better than we were before we started, and the remainder will get added on the next
        #   sync.
        # - updating membership details from Yahoo to here is similarly fine
        # - deleting memberships on here which are no longer on Yahoo is a single statement, but even if that
        #   failed partway through it would still be fine; we'd have removed some of them which is better than
        #   nothing, and the remainder would get removed on the next sync.
        #
        # So as long as we only return a success when it's worked, we don't need to be in a transaction.  This is
        # good as it would be a large transaction and would hit lock timeouts.
        $ret = [
            'ret' => 0,
            'status' => 'Success'
        ];

        $synctime = $synctime ? $synctime : date("Y-m-d H:i:s", time());

        # Really don't want to remove all members by mistake, so don't allow it.
        if (!$members && $collection == MembershipCollection::APPROVED) { return($ret); }

        try {
            #$this->dbhm->setErrorLog(TRUE);

            # First make sure we have users set up for all the new members.  The input might have duplicate members;
            # save off the uid, and work out the role.
            $u = User::get($this->dbhm, $this->dbhm);
            $overallroles = [];
            $count = 0;

            #$news = $this->dbhm->preQuery("SELECT * FROM memberships_yahoo INNER JOIN memberships ON memberships_yahoo.membershipid = memberships.id AND groupid = {$this->id};");
            #error_log("Yahoo membs before scan" . var_export($news, TRUE));

            #error_log("Scan members {$this->group['nameshort']}");
            $gotanowner = FALSE;

            foreach ($members as &$memb) {
                # Long
                set_time_limit(60);

                if (pres('email', $memb)) {
                    # First check if we already know about this user.  This is a good time to pick up duplicates -
                    # the same yahooid or yahooUserId means this is the same user, so we should merge.
                    #
                    # If the merge fails for some reason we'd still want to continue the sync.
                    $yuid = presdef('yahooid', $memb, NULL) ? $u->findByYahooId($memb['yahooid']) : NULL;
                    $yiduid = presdef('yahooUserId', $memb, NULL) ? $u->findByYahooUserId($memb['yahooUserId']) : NULL;
                    $emailinfo = $u->getIdForEmail($memb['email']);
                    $emailid = $emailinfo ? $emailinfo['userid'] : NULL;

                    $reason = "SetMembers {$this->group['nameshort']} - YahooId " . presdef('yahooid', $memb, '') . " = $yuid, YahooUserId " . presdef('yahooUserId', $memb, '') . " = $yiduid, Email {$memb['email']} = $emailid";

                    # Now merge any different ones.
                    if ($emailid && $yuid && $emailid != $yuid) {
                        $mergerc = $u->merge($emailid, $yuid, $reason);

                        # If the merge failed then zap the id to stop us setting it below.
                        $memb['yahooid'] = $mergerc ? $memb['yahooid'] : NULL;
                        #error_log($reason);
                    }

                    if ($emailid && $yiduid && $emailid != $yiduid && $yiduid != $yuid) {
                        $mergerc = $u->merge($emailid, $yiduid, $reason);

                        # If the merge failed then zap the id to stop us setting it below.
                        $memb['yahooUserId'] = $mergerc ? $memb['yahooUserId'] : NULL;
                        #error_log($reason);
                    }

                    # Pick a non-null one.
                    $uid = $emailid ? $emailid : ($yuid ? $yuid : $yiduid);
                    #error_log("uid $uid yuid $yuid yiduid $yiduid");

                    if (!$uid) {
                        # We don't - create them.
                        preg_match('/(.*)@/', $memb['email'], $matches);
                        $name = presdef('name', $memb, $matches[1]);
                        $uid = $u->create(NULL, NULL, $name, "During SetMembers for {$this->group['nameshort']}", presdef('yahooUserId', $memb, NULL), presdef('yahooid', $memb, NULL));
                        #error_log("Create $uid will have email " . presdef('email', $memb, '') . " yahooid " . presdef('yahooid', $memb, ''));
                    } else {
                        $u = User::get($this->dbhr, $this->dbhm, $uid);
                    }

                    # Make sure that the email is associated with this user.  Note that this may be required even
                    # if we succeeded in our findByEmail above, as that may have found a different email with the
                    # same canon value.
                    #
                    # Don't flag it as a primary email otherwise we might override the one we have.
                    $memb['emailid'] = $u->addEmail($memb['email'], 0, FALSE);

                    if (pres('yahooUserId', $memb) && $u->getPrivate('yahooUserId') != $memb['yahooUserId']) {
                        $u->setPrivate('yahooUserId', $memb['yahooUserId']);
                    }

                    # If we don't have a yahooid for this user, update it.  If we already have one, then stick with it
                    # to avoid updating a user with an old Yahoo id
                    if (pres('yahooid', $memb) && !$u->getPrivate('yahooid') && (!$yuid)) {
                        $u->setPrivate('yahooid', $memb['yahooid']);
                    }

                    # Remember the uid for later below.
                    $memb['uid'] = $uid;
                    $distinctmembers[$uid] = TRUE;

                    # Get the role.  We might have the same underlying user who is a member using multiple email addresses
                    # so we need to take the max role that they have.
                    $yahoorole = $this->getYahooRole($memb);
                    $overallrole = pres($uid, $overallroles) ? $u->roleMax($overallroles[$uid], $yahoorole) : $yahoorole;
                    $overallroles[$uid] = $overallrole;

                    $gotanowner = ($yahoorole == User::ROLE_OWNER) ? TRUE : $gotanowner;
                }

                $count++;

                if ($count % 1000 == 0) {
                    error_log("...$count");
                }
            }

            #$news = $this->dbhm->preQuery("SELECT * FROM memberships_yahoo INNER JOIN memberships ON memberships_yahoo.membershipid = memberships.id AND groupid = {$this->id};");
            #error_log("Yahoo membs after scan" . var_export($news, TRUE));

            #error_log("Scanned members {$this->group['nameshort']}");

            if ($gotanowner || $collection !== MembershipCollection::APPROVED) {
                $me = whoAmI($this->dbhr, $this->dbhm);
                $myrole = $me ? $me->getRoleForGroup($this->id) : User::ROLE_NONMEMBER;
                #error_log("myrole in setGroup $myrole id " . $me->getId() . " from " . $me->getRoleForGroup($this->id) . " session " . $_SESSION['id']);

                # Save off the list of members which currently exist, so that after we've processed the ones which currently
                # exist, we can remove any which should no longer be present.
                #
                # We only want the members upto the point where the sync started, otherwise we might remove a member who has
                # just joined.
                $mysqltime = date("Y-m-d H:i:s", strtotime($synctime));
                $this->dbhm->preExec("DROP TEMPORARY TABLE IF EXISTS syncdelete; CREATE TEMPORARY TABLE syncdelete (emailid INT UNSIGNED, PRIMARY KEY idkey(emailid));");
                $sql = "INSERT INTO syncdelete (SELECT DISTINCT memberships_yahoo.emailid FROM memberships_yahoo INNER JOIN memberships ON memberships.id = memberships_yahoo.membershipid WHERE groupid = {$this->id} AND memberships_yahoo.collection = '$collection' AND memberships_yahoo.added < '$mysqltime');";
                $this->dbhm->preExec($sql);

                #$resid = $this->dbhm->preQuery("SELECT memberships_yahoo.id, emailid FROM memberships_yahoo WHERE emailid IN (SELECT emailid FROM syncdelete);");
                #error_log("Syncdelete at start" . var_export($resid, TRUE));

                $bulksql = '';
                $tried = 0;

                #error_log("Update members {$this->group['nameshort']} role $myrole");

                for ($count = 0; $count < count($members); $count++) {
                    # Long
                    set_time_limit(60);

                    $member = $members[$count];
                    #error_log("Update member " . var_export($member, TRUE));

                    if (pres('uid', $member)) {
                        $tried++;
                        $overallrole = $overallroles[$member['uid']];

                        # Use a single SQL statement rather than the usual methods for performance reasons.  And then
                        # batch them up into groups because that performs better in a cluster.
                        $yps = presdef('yahooPostingStatus', $member, NULL);
                        $ydt = presdef('yahooDeliveryType', $member, NULL);
                        $yahooAlias = presdef('yahooAlias', $member, NULL);
                        $joincomment = pres('joincomment', $member) ? $this->dbhm->quote($member['joincomment']) : 'NULL';

                        # Get any existing Yahoo membership for this user with this email.
                        $sql = "SELECT memberships_yahoo.* FROM memberships_yahoo INNER JOIN memberships ON memberships.id = memberships_yahoo.membershipid WHERE userid = ? AND groupid = ? AND emailid = ?;";
                        $yahoomembs = $this->dbhm->preQuery($sql, [
                            $member['uid'],
                            $this->id,
                            $member['emailid']
                        ]);
                        #error_log("$sql, {$member['uid']}, {$this->id}, {$member['emailid']}");

                        $new = count($yahoomembs) == 0;

                        $added = pres('date', $member) ? ("'" . date("Y-m-d H:i:s", strtotime($member['date'])) . "'") : 'NULL';

                        if ($member['emailid']) {
                            if ($new) {
                                # Make sure the top-level and the Yahoo memberships are both present.
                                # We don't want to REPLACE as that might lose settings.
                                # We also don't want to just do INSERT IGNORE without having checked first as that doesn't
                                # perform well in clusters.
                                $bulksql .= "INSERT IGNORE INTO memberships (userid, groupid, collection) VALUES ({$member['uid']}, {$this->id}, '$collection');";
                                $bulksql .= "INSERT IGNORE INTO memberships_yahoo (membershipid, emailid, collection) VALUES ((SELECT id FROM memberships WHERE userid = {$member['uid']} AND groupid = {$this->id}), {$member['emailid']}, '$collection');";

                                # Default the Yahoo membership to a user.
                                $yahoomembs = [
                                    [
                                        'role' => User::ROLE_MEMBER,
                                        'collection' => $collection
                                    ]
                                ];

                                # Make sure we have a history entry.
                                $sql = "SELECT * FROM memberships_history WHERE userid = ? AND groupid = ?;";
                                $hists = $this->dbhr->preQuery($sql, [$member['uid'], $this->id]);

                                if (count($hists) == 0) {
                                    $bulksql .= "INSERT INTO memberships_history (userid, groupid, collection, added) VALUES ({$member['uid']},{$this->id},'$collection',$added);";
                                }
                            }

                            # If we are promoting a member, then we can only promote as high as we are.  This prevents
                            # moderators setting owner status.
                            if ($overallrole == User::ROLE_OWNER &&
                                $myrole != User::ROLE_OWNER &&
                                $yahoomembs[0]['role'] != User::ROLE_OWNER
                            ) {
                                $overallrole = User::ROLE_MODERATOR;
                            }

                            # Now update with any new settings.  Having this if test looks a bit clunky but it means that
                            # when resyncing a group where most members have not changed settings, we can avoid many UPDATEs.
                            #
                            # This will have the effect of moving members between collections, if required.
                            $yahoorole = $this->getYahooRole($member);

                            if ($new ||
                                $yahoomembs[0]['role'] != $yahoorole || $yahoomembs[0]['collection'] != $collection || $yahoomembs[0]['yahooPostingStatus'] != $yps || $yahoomembs[0]['yahooDeliveryType'] != $ydt || $yahoomembs[0]['joincomment'] != $joincomment || $yahoomembs[0]['emailid'] != $member['emailid'] || $yahoomembs[0]['added'] != $added || $yahoomembs[0]['yahooAlias'] != $yahooAlias)
                            {
                                $bulksql .=  "UPDATE memberships SET role = '$overallrole', collection = '$collection', added = $added WHERE userid = " .
                                    "{$member['uid']} AND groupid = {$this->id};";
                                $sql = "UPDATE memberships_yahoo SET role = '$yahoorole', collection = '$collection', yahooPostingStatus = " . $this->dbhm->quote($yps) . ", yahooAlias = " . $this->dbhm->quote($yahooAlias) .
                                    ", yahooDeliveryType = " . $this->dbhm->quote($ydt) . ", joincomment = $joincomment, added = $added WHERE membershipid = (SELECT id FROM memberships WHERE userid = " .
                                    "{$member['uid']} AND groupid = {$this->id}) AND emailid = {$member['emailid']};";
                                $bulksql .= $sql;
                            }

                            # If this is a mod/owner, make sure the systemrole reflects that.
                            if ($overallrole == User::ROLE_MODERATOR || $overallrole == User::ROLE_OWNER) {
                                $sql = "UPDATE users SET systemrole = 'Moderator' WHERE id = {$member['uid']} AND systemrole = 'User';";
                                User::clearCache($member['uid']);
                                $bulksql .= $sql;
                            }

                            # Record that this membership still exists by deleting their id from the temp table
                            #error_log("Delete from syncdelete " . var_export($member, TRUE));
                            $sql = "DELETE FROM syncdelete WHERE emailid = {$member['emailid']};";
                            $bulksql .= $sql;

                            if ($count > 0 && $count % 1000 == 0) {
                                # Do a chunk of work.  If this doesn't work correctly we'll end up with fewer members
                                # and fail the count below.  Or we'll have incorrect settings until the next sync, but
                                # that's ok - better than failing it.
                                #error_log($bulksql);
                                #error_log("Execute batch $count {$this->group['nameshort']}");
                                $this->dbhm->exec($bulksql);
                                #error_log("Executed batch $count {$this->group['nameshort']}");
                                $bulksql = '';
                            }
                        }
                    }
                }

                if ($bulksql != '') {
                    # Do remaining SQL.  If this fails then we'll fail the count check below.
                    #error_log("Bulksql $bulksql");
                    $this->dbhm->exec($bulksql);
                }

                #error_log("Updated members {$this->group['nameshort']}");

                #$news = $this->dbhm->preQuery("SELECT * FROM memberships_yahoo INNER JOIN memberships ON memberships_yahoo.membershipid = memberships.id AND groupid = {$this->id};");
                #error_log("Yahoo membs after update" . var_export($news, TRUE));

                # Delete any residual Yahoo memberships.
                #$resid = $this->dbhm->preQuery("SELECT memberships_yahoo.id, emailid FROM memberships_yahoo WHERE emailid IN (SELECT emailid FROM syncdelete) AND membershipid IN (SELECT id FROM memberships WHERE groupid = ? AND collection = ?);", [$this->id, $collection]);
                #error_log(var_export($resid, TRUE));
                $emailids = $this->dbhm->preQuery("SELECT emailid FROM syncdelete;");
                foreach ($emailids as $emailid) {
                    #error_log("Check for delete {$emailid['emailid']}");
                    $rc = $this->dbhm->preExec("DELETE FROM memberships_yahoo WHERE emailid = ? AND membershipid IN (SELECT id FROM memberships WHERE groupid = ?);",
                        [
                            $emailid['emailid'],
                            $this->id
                        ]);
                }
                #error_log("Deleted $rc Yahoo Memberships");

                #$news = $this->dbhm->preQuery("SELECT * FROM memberships_yahoo INNER JOIN memberships ON memberships_yahoo.membershipid = memberships.id AND groupid = {$this->id};");
                #error_log("Yahoo membs delete Yahoo " . var_export($news, TRUE));

                # Now that we've deleted the Yahoo membership, see if this means that we no longer have any Yahoo
                # memberships on this group (recall that we might have multiple Yahoo memberships with different email
                # addresses for the same group).  If so, then we want to delete the overall membership, and also log
                # the deletes so that we can see why memberships disappear.
                $todeletes = $this->dbhm->preQuery("SELECT memberships.id, memberships.userid FROM memberships LEFT JOIN memberships_yahoo ON memberships.id = memberships_yahoo.membershipid WHERE membershipid IS NULL AND groupid = ? AND memberships.collection = ?;", [$this->id, $collection]);
                #error_log("Overall to delete " . var_export($todeletes, TRUE));
                #error_log("Delete overall memberships " . count($todeletes));
                $meid = $me ? $me->getId() : NULL;
                foreach ($todeletes as $todelete) {
                    # Long
                    set_time_limit(60);

                    if ($collection == MembershipCollection::APPROVED) {
                        # No point logging removal of pending members - that's normal.
                        $this->log->log([
                            'type' => Log::TYPE_GROUP,
                            'subtype' => Log::SUBTYPE_LEFT,
                            'user' => $todelete['userid'],
                            'byuser' => $meid,
                            'groupid' => $this->id,
                            'text' => "Sync of whole $collection membership list"
                        ]);
                    }

                    $this->dbhm->preExec("DELETE FROM memberships WHERE id = ?;", [
                        $todelete['id']
                    ]);
                }

                # Having logged them, delete them.
                $this->dbhm->preExec("DROP TEMPORARY TABLE syncdelete;");

                #error_log("Tidied members {$this->group['nameshort']}");
            }

            if ($collection == MessageCollection::APPROVED) {
                # Record the sync.
                $this->dbhm->preExec("UPDATE groups SET lastyahoomembersync = NOW() WHERE id = ?;", [$this->id]);
                Group::clearCache($this->id);
            }
        } catch (Exception $e) {
            $ret = [ 'ret' => 2, 'status' => "Sync failed with " . $e->getMessage() ];
            error_log(var_export($ret, TRUE));
        }

        return($ret);
    }

    public function setSettings($settings)
    {
        $str = json_encode($settings);
        $me = whoAmI($this->dbhr, $this->dbhm);
        $this->dbhm->preExec("UPDATE groups SET settings = ? WHERE id = ?;", [ $str, $this->id ]);
        Group::clearCache($this->id);
        $this->group['settings'] = $str;
        $this->log->log([
            'type' => Log::TYPE_GROUP,
            'subtype' => Log::SUBTYPE_EDIT,
            'groupid' => $this->id,
            'byuser' => $me ? $me->getId() : NULL,
            'text' => $this->getEditLog([
                'settings' => $settings
            ])
        ]);

        return(true);
    }

    public function getSetting($key, $def) {
        $settings = json_decode($this->group['settings'], true);
        return(array_key_exists($key, $settings) ? $settings[$key] : $def);
    }

    private function getKey($message) {
        # Both pending and approved messages have unique IDs, though they are only unique within pending and approved,
        # not between them.
        #
        # It would be nice to believe in a world where Message-ID was unique.
        $key = NULL;
        if (pres('yahoopendingid', $message)) {
            $key = "P-{$message['yahoopendingid']}";
        } else if (pres('yahooapprovedid', $message)) {
            $key = "A-{$message['yahooapprovedid']}";
        }

        return($key);
    }

    public function correlate($collections, $messages) {
        $missingonserver = [];
        $missingonclient = [];

        # Check whether any of the messages in $messages are not present on the server or vice-versa.
        $supplied = [];
        $cs = [];

        # First find messages which are missing on the server, i.e. present in $messages but not
        # present in any of $collections.
        $pending = FALSE;
        $approved = FALSE;

        foreach ($collections as $collection) {
            # We can get called with Spam for either an approved or a pending correlate; we want to
            # know which it is.
            if ($collection == MessageCollection::APPROVED) {
                $approved = TRUE;
            }
            if ($collection == MessageCollection::PENDING) {
                $pending = TRUE;
            }

            $c = new MessageCollection($this->dbhr, $this->dbhm, $collection);
            $cs[] = $c;

            if ($collection = MessageCollection::APPROVED) {
                $this->dbhm->preExec("UPDATE groups SET lastyahoomessagesync = NOW() WHERE id = ?;", [
                    $this->id
                ]);
                Group::clearCache($this->id);
            }
        }

        if ($messages) {
            foreach ($messages as $message) {
                $key = $this->getKey($message);
                $supplied[$key] = true;
                $id = NULL;

                # Don't use the collection to find it, as it could be in spam.
                if (pres('yahooapprovedid', $message)) {
                    $id = $c->findByYahooApprovedId($this->id, $message['yahooapprovedid']);
                } else if (pres('yahoopendingid', $message)) {
                    $id = $c->findByYahooPendingId($this->id, $message['yahoopendingid']);
                }

                if (!$id) {
                    $missingonserver[] = $message;
                }
            }
        }

        # Now find recent messages which are missing on the client, i.e. present in $collections but not present in
        # $messages.
        /** @var MessageCollection $c */
        foreach ($cs as $c) {
            $mysqltime = date ("Y-m-d", strtotime("Midnight 31 days ago"));
            $sql = "SELECT id, source, fromaddr, yahoopendingid, yahooapprovedid, subject, date, messageid FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid AND messages_groups.groupid = ? AND messages_groups.collection = ? AND messages_groups.deleted = 0 WHERE messages_groups.arrival > ?;";
            $ourmsgs = $this->dbhr->preQuery(
                $sql,
                [
                    $this->id,
                    $c->getCollection()[0],
                    $mysqltime
                ]
            );

            foreach ($ourmsgs as $msg) {
                $key = $this->getKey($msg);
                if (!array_key_exists($key, $supplied)) {
                    # We check where the message came from to decide whether to return it.  This is because we
                    # might have a message currently in spam from YAHOO_APPROVED, and we might be doing a
                    # correlate on pending, and we don't want to return that message as missing.
                    #
                    # We could have a message in originally in Pending which we have later received because it's been
                    # approved elsewhere, in which case we'll have updated the source to Approved, but we want to
                    # include that.
                    $source = $msg['source'];
                    #error_log("Consider {$msg['id']} missing on client $pending, $approved, $source");
                    if (($pending && ($source == Message::YAHOO_PENDING || ($msg['yahoopendingid'] && $source == Message::YAHOO_APPROVED))) ||
                        ($approved && $source == Message::YAHOO_APPROVED)) {
                        $missingonclient[] = [
                            'id' => $msg['id'],
                            'email' => $msg['fromaddr'],
                            'subject' => $msg['subject'],
                            'collection' => $c->getCollection()[0],
                            'date' => ISODate($msg['date']),
                            'messageid' => $msg['messageid'],
                            'yahoopendingid' => $msg['yahoopendingid'],
                            'yahooapprovedid' => $msg['yahooapprovedid']
                        ];
                    }
                }
            }
        }

        return ([$missingonserver, $missingonclient]);
    }

    public function getConfirmKey() {
        $key = NULL;

        # Don't reset the key each time, otherwise we can have timing windows where the key is reset, thereby
        # invalidating an invitation which is in progress.
        $groups = $this->dbhr->preQuery("SELECT confirmkey FROM groups WHERE id = ?;" , [ $this->id ]);
        foreach ($groups as $group) {
            $key = $group['confirmkey'];
        }

        if (!$key) {
            $key = randstr(32);
            $sql = "UPDATE groups SET confirmkey = ? WHERE id = ?;";
            $rc = $this->dbhm->preExec($sql, [ $key, $this->id ]);
            Group::clearCache($this->id);
        }

        return($key);
    }

    public function createVoucher() {

        do {
            $voucher = randstr(20);
            $sql = "INSERT INTO vouchers (voucher) VALUES (?);";
            $rc = $this->dbhm->preExec($sql, [ $voucher ]);
        } while (!$rc);

        return($voucher);
    }

    public function redeemVoucher($voucher) {
        $ret = FALSE;
        $me = whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        $sql = "SELECT * FROM vouchers WHERE voucher = ? AND used IS NULL;";
        $vs = $this->dbhr->preQuery($sql , [ $voucher ]);

        foreach ($vs as $v) {
            $this->dbhm->beginTransaction();

            $sql = "UPDATE groups SET publish = 1, licensed = CURDATE(), licenseduntil = CASE WHEN licenseduntil > CURDATE() THEN licenseduntil + INTERVAL 1 YEAR ELSE CURDATE() + INTERVAL 1 YEAR END WHERE id = ?;";
            $rc = $this->dbhm->preExec($sql, [ $this->id ]);
            Group::clearCache($this->id);

            if ($rc) {
                $sql = "UPDATE vouchers SET used = NOW(), userid = ?, groupid = ? WHERE id = ?;";
                $rc = $this->dbhm->preExec($sql, [
                    $myid,
                    $this->id,
                    $v['id']
                ]);

                if ($rc) {
                    $rc = $this->dbhm->commit();

                    if ($rc) {
                        $ret = TRUE;
                        $this->log->log([
                            'type' => Log::TYPE_GROUP,
                            'subtype' => Log::SUBTYPE_LICENSED,
                            'groupid' => $this->id,
                            'text' => "Using voucher $voucher"
                        ]);
                    }
                }
            }
        }

        return($ret);
    }

    public function onYahoo() {
        return(pres('onyahoo', $this->group));
    }

    public function getName() {
        return($this->group['namefull'] ? $this->group['namefull'] : $this->group['nameshort']);
    }

    public function listByType($type, $support, $polys = FALSE) {
        $typeq = $type ? "type = ?" : '1=1';
        $showq = $support ? '' : 'AND publish = 1 AND listable = 1';
        $suppfields = $support ? ", founded, lastmoderated, lastmodactive, lastautoapprove, activemodcount, backupmodsactive, backupownersactive, onmap, affiliationconfirmed, affiliationconfirmedby": '';
        $polyfields = $polys ? ", CASE WHEN poly IS NULL THEN polyofficial ELSE poly END AS poly, polyofficial" : '';

        $sql = "SELECT groups.id, groups_images.id AS attid, nameshort, region, namefull, lat, lng, publish $suppfields $polyfields, mentored, onhere, onyahoo, ontn, onmap, external, showonyahoo, profile, tagline, contactmail, authorities.name AS authority FROM groups LEFT JOIN groups_images ON groups_images.groupid = groups.id LEFT JOIN authorities ON authorities.id = groups.authorityid WHERE $typeq ORDER BY CASE WHEN namefull IS NOT NULL THEN namefull ELSE nameshort END, groups_images.id DESC;";
        $groups = $this->dbhr->preQuery($sql, [ $type ]);
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);

        if ($support) {
            $start = date('Y-m-d', strtotime("midnight 31 days ago"));
            $approves = $this->dbhr->preQuery("SELECT COUNT(*) AS count, groupid FROM logs WHERE timestamp >= ? AND type = ? AND subtype = ? GROUP BY groupid;", [
                $start,
                Log::TYPE_MESSAGE,
                Log::SUBTYPE_AUTO_APPROVED
            ]);
        }

        $lastname = NULL;
        $ret = [];

        foreach ($groups as $group) {
            if (!$lastname || $lastname != $group['nameshort']) {
                $group['namedisplay'] = $group['namefull'] ? $group['namefull'] : $group['nameshort'];
                $group['profile'] = $group['profile'] ? $a->getPath(FALSE, $group['attid']) : NULL;

                if ($group['contactmail']) {
                    $group['modsmail'] = $group['contactmail'];
                } else if ($group['onyahoo']) {
                    $group['modsmail'] = $group['nameshort'] . "-owner@yahoogroups.com";
                } else {
                    $group['modsmail'] = $group['nameshort'] . "-volunteers@" . GROUP_DOMAIN;
                }

                if ($support) {
                    foreach ($approves as $approve) {
                        if ($approve['groupid'] === $group['id']) {
                            $group['recentautoapproves'] = $approve['count'];
                        }
                    }
                }

                $ret[] = $group;
            }

            $lastname = $group['nameshort'];
        }

        return($ret);
    }

    public function moveToNative() {
        $me = whoAmI($this->dbhr, $this->dbhm);
        $email = $me ? $me->getEmailPreferred() : MODERATOR_EMAIL;

        # We are switching a group over from being on Yahoo to not being.  Enshrine the owner/
        # mod roles and moderation status.
        $this->setNativeRoles();
        $this->setNativeModerationStatus();

        #  Notify TrashNothing so that it can also do that, and talk to us rather than Yahoo.
        $url = "https://trashnothing.com/modtools/api/switch-to-freegle-direct?key=" . TNKEY . "&group_id=" . $this->getPrivate('nameshort') . "&moderator_email=" . $email;
        $rsp = file_get_contents($url);
        error_log("Move to FD on TN " . var_export($rsp, TRUE));
    }
}