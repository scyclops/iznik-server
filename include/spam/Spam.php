<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');

use GeoIp2\Database\Reader;
use LanguageDetection\Language;

class Spam {
    CONST TYPE_SPAMMER = 'Spammer';
    CONST TYPE_WHITELIST = 'Whitelisted';
    CONST TYPE_PENDING_ADD = 'PendingAdd';
    CONST TYPE_PENDING_REMOVE = 'PendingRemove';
    CONST SPAM = 'Spam';
    CONST HAM = 'Ham';

    CONST USER_THRESHOLD = 5;
    CONST GROUP_THRESHOLD = 20;
    CONST SUBJECT_THRESHOLD = 30;  // SUBJECT_THRESHOLD must be > GROUP_THRESHOLD for UT

    # For checking users as suspect.
    CONST SEEN_THRESHOLD = 16; // Number of groups to join or apply to before considered suspect
    CONST ESCALATE_THRESHOLD = 2; // Level of suspicion before a user is escalated to support/admin for review

    CONST REASON_NOT_SPAM = 'NotSpam';
    CONST REASON_COUNTRY_BLOCKED = 'CountryBlocked';
    CONST REASON_IP_USED_FOR_DIFFERENT_USERS = 'IPUsedForDifferentUsers';
    CONST REASON_IP_USED_FOR_DIFFERENT_GROUPS = 'IPUsedForDifferentGroups';
    CONST REASON_SUBJECT_USED_FOR_DIFFERENT_GROUPS = 'SubjectUsedForDifferentGroups';
    CONST REASON_SPAMASSASSIN = 'SpamAssassin';
    CONST REASON_GREETING = 'Greetings spam';
    CONST REASON_REFERRED_TO_SPAMMER = 'Referenced known spammer';
    CONST REASON_KNOWN_KEYWORD = 'Known spam keyword';
    CONST REASON_DBL = 'URL on DBL';
    CONST REASON_BULK_VOLUNTEER_MAIL = 'BulkVolunteerMail';

    const ACTION_SPAM = 'Spam';
    const ACTION_REVIEW = 'Review';

    # A common type of spam involves two lines with greetings.
    private $greetings = [
        'hello', 'salutations', 'hey', 'good morning', 'sup', 'hi', 'good evening', 'good afternoon', 'greetings'
    ];

    /** @var  $dbhr LoggedPDO */
    private $dbhr;

    /** @var  $dbhm LoggedPDO */
    private $dbhm;
    private $reader;

    private $spamwords = NULL;

    function __construct($dbhr, $dbhm, $id = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->reader = new Reader(MMDB);
        $this->log = new Log($this->dbhr, $this->dbhm);
    }

    public function checkMessage(Message $msg) {
        $ip = $msg->getFromIP();
        $host = NULL;

        if ($ip) {
            if (strpos($ip, "10.") === 0) {
                # We've picked up an internal IP, ignore it.
                $ip = NULL;
            } else {
                $host = $msg->getFromhost();
                if (preg_match('/mail.*yahoo\.com/', $host)) {
                    # Posts submitted by email to Yahoo show up with an X-Originating-IP of one of Yahoo's MTAs.  We don't
                    # want to consider those as spammers.
                    $ip = NULL;
                    $msg->setFromIP($ip);
                } else {
                    # Check if it's whitelisted
                    $sql = "SELECT * FROM spam_whitelist_ips WHERE ip = ?;";
                    $ips = $this->dbhr->preQuery($sql, [$ip]);
                    foreach ($ips as $wip) {
                        $ip = NULL;
                        $msg->setFromIP($ip);
                    }
                }
            }
        }

        if ($ip) {
            # We have an IP, we reckon.  It's unlikely that someone would fake an IP which gave a spammer match, so
            # we don't have to worry too much about false positives.
            try {
                $record = $this->reader->country($ip);
                $country = $record->country->name;
                $msg->setPrivate('fromcountry', $record->country->isoCode);
            } catch (Exception $e) {
                # Failed to look it up.
                error_log("Failed to look up $ip " . $e->getMessage());
                $country = NULL;
            }

            # Now see if we're blocking all mails from that country.  This is legitimate if our service is for a
            # single country and we are vanishingly unlikely to get legitimate emails from certain others.
            $countries = $this->dbhr->preQuery("SELECT * FROM spam_countries WHERE country = ?;", [$country]);
            foreach ($countries as $country) {
                # Gotcha.
                return(array(true, Spam::REASON_COUNTRY_BLOCKED, "Blocking IP $ip as it's in {$country['country']}"));
            }

            # Now see if this IP has been used for too many different users.  That is likely to
            # be someone masquerading to fool people.
            #
            # Should check address, but we don't yet have the canonical address so will be fooled by FBUser
            # TODO
            $sql = "SELECT fromname FROM messages_history WHERE fromip = ? GROUP BY fromname;";
            $users = $this->dbhr->preQuery($sql, [$ip]);
            $numusers = count($users);

            if ($numusers > Spam::USER_THRESHOLD) {
                $list = [];
                foreach ($users as $user) {
                    $list[] = $user['fromname'];
                }
                return(array(true, Spam::REASON_IP_USED_FOR_DIFFERENT_USERS, "IP $ip " . ($host ? "($host)" : "") . " recently used for $numusers different users (" . implode(', ', $list) . ")"));
            }

            # Now see if this IP has been used for too many different groups.  That's likely to
            # be someone spamming.
            $sql = "SELECT groups.nameshort FROM messages_history INNER JOIN groups ON groups.id = messages_history.groupid WHERE fromip = ? GROUP BY groupid;";
            $groups = $this->dbhr->preQuery($sql, [$ip]);
            $numgroups = count($groups);

            if ($numgroups >= Spam::GROUP_THRESHOLD) {
                $list = [];
                foreach ($groups as $group) {
                    $list[] = $group['nameshort'];
                }
                return(array(true, Spam::REASON_IP_USED_FOR_DIFFERENT_GROUPS, "IP $ip ($host) recently used for $numgroups different groups (" . implode(', ', $list) . ")"));
            }
        }

        # Now check whether this subject (pace any location) is appearing on many groups.
        #
        # Don't check very short subjects - might be something like "TAKEN".
        $subj = $msg->getPrunedSubject();

        if (strlen($subj) >= 10) {
            $sql = "SELECT COUNT(DISTINCT groupid) AS count FROM messages_history WHERE prunedsubject LIKE ? AND groupid IS NOT NULL;";
            $counts = $this->dbhr->preQuery($sql, [
                "$subj%"
            ]);

            foreach ($counts as $count) {
                if ($count['count'] >= Spam::SUBJECT_THRESHOLD) {
                    # Possible spam subject - but check against our whitelist.
                    $found = FALSE;
                    $sql = "SELECT id FROM spam_whitelist_subjects WHERE subject = ?;";
                    $whites = $this->dbhr->preQuery($sql, [$subj]);
                    foreach ($whites as $white) {
                        $found = TRUE;
                    }

                    if (!$found) {
                        return (array(true, Spam::REASON_SUBJECT_USED_FOR_DIFFERENT_GROUPS, "Warning - subject $subj recently used on {$count['count']} groups"));
                    }
                }
            }
        }

        # Now check if this sender has mailed a lot of owners recently.
        $sql = "SELECT COUNT(*) AS count FROM messages WHERE envelopefrom = ? and envelopeto LIKE '%-volunteers@" . GROUP_DOMAIN . "' AND arrival >= '" . date("Y-m-d H:i:s", strtotime("24 hours ago")) . "'";
        $counts = $this->dbhr->preQuery($sql, [
            $msg->getEnvelopefrom()
        ]);

        foreach ($counts as $count) {
            if ($count['count'] >= Spam::GROUP_THRESHOLD) {
                return (array(true, Spam::REASON_BULK_VOLUNTEER_MAIL, "Warning - " . $msg->getEnvelopefrom() . " mailed {$count['count']} group volunteer addresses recently"));
            }
        }

        # Get the text to scan.  No point in scanning any text we would strip before passing it on.
        $text = $msg->stripQuoted();

        # Check if this is a greetings spam.
        if (stripos($text, 'http') || stripos($text, '.php')) {
            $p = strpos($text, "\n");
            $q = strpos($text, "\n", $p + 1);
            $r = strpos($text, "\n", $q + 1);

            $line1 = $p ? substr($text, 0, $p) : '';
            $line3 = $q ? substr($text, $q + 1, $r) : '';

            $line1greeting = FALSE;
            $line3greeting = FALSE;
            $subjgreeting = FALSE;

            foreach ($this->greetings as $greeting) {
                if (stripos($subj, $greeting) === 0) {
                    $subjgreeting = TRUE;
                }

                if (stripos($line1, $greeting) === 0) {
                    $line1greeting = TRUE;
                }

                if (stripos($line3, $greeting) === 0) {
                    $line3greeting = TRUE;
                }
            }

            if ($subjgreeting && $line1greeting || $line1greeting && $line3greeting) {
                return (array(true, Spam::REASON_GREETING, "Message looks like a greetings spam"));
            }
        }

        $spammail = $this->checkReferToSpammer($text);

        if ($spammail) {
            return (array(true, Spam::REASON_REFERRED_TO_SPAMMER, "Refers to known spammer $spammail"));
        }

        # For messages we want to spot any dubious items.
        $r = $this->checkSpam($text, [ Spam::ACTION_REVIEW, Spam::ACTION_SPAM ]);
        if ($r) {
            return ($r);
        }

        # It's fine.  So far as we know.
        return(NULL);
    }

    private function getSpamWords() {
        if (!$this->spamwords) {
            $this->spamwords = $this->dbhr->preQuery("SELECT * FROM spam_keywords;");
        }
    }

    public function checkReview($message, $language, $blankok = FALSE) {
        # Spammer trick is to encode the dot in URLs.
        $message = str_replace('&#12290;', '.', $message);

        #error_log("Check review $message len " . strlen($message) . " blankok? $blankok");
        $check = strlen($message) == 0;

        if (!$check && stripos($message, '<script') !== FALSE) {
            # Looks dodgy.
            $check = TRUE;
        }

        if (!$check) {
            # Check for URLs.
            global $urlPattern, $urlBad;

            if (preg_match_all($urlPattern, $message, $matches)) {
                # A link.  Some domains are ok - where they have been whitelisted several times (to reduce bad whitelists).
                $ourdomains = $this->dbhr->preQuery("SELECT domain FROM spam_whitelist_links WHERE count >= 3 AND LENGTH(domain) > 5 AND domain NOT LIKE '%linkedin%' AND domain NOT LIKE '%goo.gl%' AND domain NOT LIKE '%bit.ly%' AND domain NOT LIKE '%tinyurl%';");

                $valid = 0;
                $count = 0;
                $badurl = NULL;

                foreach ($matches as $val) {
                    foreach ($val as $url) {
                        $bad = FALSE;
                        $url2 = str_replace('http:', '', $url);
                        $url2 = str_replace('https:', '', $url2);
                        foreach ($urlBad as $badone) {
                            if (strpos($url2, $badone) !== FALSE) {
                                $bad = TRUE;
                            }
                        }

                        if (!$bad && strlen($url) > 0) {
                            $url = substr($url, strpos($url, '://') + 3);
                            $count++;
                            $trusted = FALSE;

                            foreach ($ourdomains as $domain) {
                                if (stripos($url, $domain['domain']) === 0) {
                                    # One of our domains.
                                    $valid++;
                                    $trusted = TRUE;
                                }
                            }

                            $badurl = $trusted ? $badurl : $url;
                        }
                    }
                }

                if ($valid < $count) {
                    # At least one URL which we don't trust.
                    $check = TRUE;
                }
            }
        }

        if (!$check) {
            # Check keywords
            $this->getSpamWords();
            foreach ($this->spamwords as $word) {
                $w = $word['type'] == 'Literal' ? preg_quote($word['word']) : $word['word'];

                if ($word['action'] == 'Review' &&
                    preg_match('/\b' . $w . '\b/i', $message) &&
                    (!$word['exclude'] || !preg_match('/' . $word['exclude'] . '/i', $message))) {
                    #error_log("Spam keyword {$word['word']}");
                    $check = TRUE;
                }
            }
        }

        if (!$check && (strpos($message, '$') !== FALSE || strpos($message, '£') !== FALSE || strpos($message, '(a)') !== FALSE)) {
            $check = TRUE;
        }

        # Email addresses are suspect too; a scammer technique is to take the conversation offlist.
        if (!$check && preg_match_all(Message::EMAIL_REGEXP, $message, $matches)) {
            foreach ($matches as $val) {
                foreach ($val as $email) {
                    if (!ourDomain($email) && strpos($email, 'trashnothing') === FALSE && strpos($email, 'yahoogroups') === FALSE) {
                        $check = TRUE;
                    }
                }
            }
        }

        if (!$check && $this->checkReferToSpammer($message)) {
            $check = TRUE;
        }

        if (!$check && $language) {
            # Check language is English.  This isn't out of some kind of misplaced nationalistic fervour, but just
            # because our spam filters work less well on e.g. French.
            #
            # Short strings like 'test' or 'ok thanks' or 'Eileen', don't always come out as English, so only check
            # slightly longer messages where the identification is more likely to work.
            #
            # We check that English is the most likely, or fairly likely compared to the one chosen.
            #
            # This is a fairly lax test but spots text which is very probably in another language.
            $message = strtolower(trim($message));

            if (strlen($message) > 50) {
                $ld = new Language;
                $lang = $ld->detect($message)->close();
                reset($lang);
                $firstlang = key($lang);
                $firstprob = presdef($firstlang, $lang, 0);
                $enprob = presdef('en', $lang, 0);
                $cyprob = presdef('cy', $lang, 0);
                $ourprob = max($enprob, $cyprob);

                $check = !($firstlang == 'en' || $firstlang == 'cy' || $ourprob >= 0.8 * $firstprob);

                if ($check) {
                    error_log("$message not in English " . var_export($lang, TRUE));
                }
            }
        }

        return($check);
    }

    public function checkSpam($message, $actions) {
        $ret = NULL;

        # Strip out any job text, which might have spam keywords.
        $message = preg_replace('/\<https\:\/\/www\.ilovefreegle\.org\/jobs\/.*\>.*$/im', '', $message);

        # Some elusive spam has percent signs.
        if (strpos($message, '%') !== FALSE) {
            $ret = [ TRUE, Spam::REASON_KNOWN_KEYWORD, 'Has percent sign - possible medication spam' ];
        }

        # Check keywords which are known as spam.
        $this->getSpamWords();
        foreach ($this->spamwords as $word) {
            if (strlen(trim($word['word'])) > 0) {
                $exp = '/\b' . preg_quote($word['word']) . '\b/i';
                if (in_array($word['action'], $actions) &&
                    preg_match($exp, $message) &&
                    (!$word['exclude'] || !preg_match('/' . $word['exclude'] . '/i', $message))) {
                    $ret = array(true, Spam::REASON_KNOWN_KEYWORD, "Refers to keyword {$word['word']}");
                }
            }
        }

        # Check whether any URLs are in Spamhaus DBL black list.
        global $urlPattern, $urlBad;

        if (preg_match_all($urlPattern, $message, $matches)) {
            $checked = [];

            foreach ($matches as $val) {
                foreach ($val as $url) {
                    $bad = FALSE;
                    $url2 = str_replace('http:', '', $url);
                    $url2 = str_replace('https:', '', $url2);
                    foreach ($urlBad as $badone) {
                        if (strpos($url2, $badone) !== FALSE) {
                            $bad = TRUE;
                        }
                    }

                    if (!$bad && strlen($url) > 0) {
                        $url = substr($url, strpos($url, '://') + 3);

                        if (array_key_exists($url, $checked)) {
                            # We do this part for performance and part because we've seen hangs in dns_get_record
                            # when checking Spamhaus repeatedly in UT.g
                            $ret = $checked[$url];
                        }

                        if (checkSpamhaus("http://$url")) {
                            $ret = array(true, Spam::REASON_DBL, "Blacklisted url $url");
                            $checked[$url] = $ret;
                        }
                    }
                }
            }
        }

        return($ret);
    }

    public function checkReferToSpammer($text) {
        $ret = NULL;

        if (strpos($text, '@') !== FALSE) {
            # Check if it contains a reference to a known spammer.
            if (preg_match_all(Message::EMAIL_REGEXP, $text, $matches)) {
                foreach ($matches as $val) {
                    foreach ($val as $email) {
                        $spammers = $this->dbhr->preQuery("SELECT users_emails.email FROM spam_users INNER JOIN users_emails ON spam_users.userid = users_emails.userid WHERE collection = ? AND email LIKE ?;", [
                            Spam::TYPE_SPAMMER,
                            $email
                        ]);

                        $ret = count($spammers) > 0 ? $spammers[0]['email'] : NULL;

                        if ($ret) {
                            break;
                        }
                    }
                }
            }
        }

        return($ret);
    }

    public function notSpamSubject($subj) {
        $sql = "INSERT IGNORE INTO spam_whitelist_subjects (subject, comment) VALUES (?, 'Marked as not spam');";
        $this->dbhm->preExec($sql, [ $subj ]);
    }

    public function checkUser($userid) {
        # Called when something has happened to a user which makes them more likely to be a spammer, and therefore
        # needs rechecking.
        $me = whoAmI($this->dbhr, $this->dbhm);

        $suspect = FALSE;
        $reason = NULL;

        # Check whether they have applied to a suspicious number of groups, but exclude whitelisted members.
        $sql = "SELECT COUNT(DISTINCT(groupid)) AS count FROM memberships  LEFT JOIN spam_users ON spam_users.userid = memberships.userid AND spam_users.collection = 'Whitelisted' WHERE memberships.userid = ? AND spam_users.userid IS NULL;";
        $counts = $this->dbhr->preQuery($sql, [ $userid ]);

        if ($counts[0]['count'] > Spam::SEEN_THRESHOLD) {
            $suspect = TRUE;
            $reason = "Seen on many groups";
        }

        if ($suspect) {
            # This user is suspect.  We will mark it as so, which means that it'll show up to mods on relevant groups,
            # and they will review it.
            $this->log->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_SUSPECT,
                'byuser' => $me ? $me->getId() : NULL,
                'user' => $userid,
                'text' => $reason
            ]);

            $this->dbhm->preExec("UPDATE users SET suspectcount = suspectcount + 1, suspectreason = ? WHERE id = ?;",
                [
                    $reason,
                    $userid
                ]);
            User::clearCache($userid);
        }
    }

    public function collectionCounts() {
        $sql = "SELECT COUNT(*) AS count, collection FROM spam_users WHERE collection IN (?, ?) GROUP BY collection;";
        $counts = $this->dbhr->preQuery($sql, [
            Spam::TYPE_PENDING_ADD,
            Spam::TYPE_PENDING_REMOVE
        ]);

        $ret = [
            Spam::TYPE_PENDING_ADD => 0,
            Spam::TYPE_PENDING_REMOVE => 0
        ];

        foreach ($counts as $count) {
            $ret[$count['collection']] = $count['count'];
        }

        return($ret);
    }

    public function exportSpammers() {
        $sql = "SELECT spam_users.id, spam_users.added, reason, email FROM spam_users INNER JOIN users_emails ON spam_users.userid = users_emails.userid WHERE collection = ?;";
        $spammers = $this->dbhr->preQuery($sql, [ Spam::TYPE_SPAMMER ]);
        return($spammers);
    }

    public function listSpammers($collection, $search, &$context) {
        # We exclude anyone who isn't a User (e.g. mods, support, admin) so that they don't appear on the list and
        # get banned.
        $me = whoAmI($this->dbhr, $this->dbhm);
        $seeall = $me && $me->isAdminOrSupport();
        $collectionq = ($collection ? " AND collection = '$collection'" : '');
        $startq = $context ? (" AND spam_users.id <  " . intval($context['id']) . " ") : '';
        $searchq = $search == NULL ? '' : (" AND (users_emails.email LIKE " . $this->dbhr->quote("%$search%") . " OR users.fullname LIKE " . $this->dbhr->quote("%$search%") . ") ");
        $sql = "SELECT DISTINCT spam_users.* FROM spam_users INNER JOIN users ON spam_users.userid = users.id LEFT JOIN users_emails ON users_emails.userid = spam_users.userid WHERE 1=1 $startq $collectionq $searchq ORDER BY spam_users.id DESC LIMIT 10;";
        $context = [];

        $spammers = $this->dbhr->preQuery($sql);

        foreach ($spammers as &$spammer) {
            $u = User::get($this->dbhr, $this->dbhm, $spammer['userid']);
            $spammer['user'] = $u->getPublic(NULL, TRUE, $seeall);
            $spammer['user']['email'] = $u->getEmailPreferred();

            $emails = $u->getEmails();

            $others = [];
            foreach ($emails as $anemail) {
                if ($anemail['email'] != $spammer['user']['email']) {
                    $others[] = $anemail;
                }
            }

            uasort($others, function($a, $b) {
                return(strcmp($a['email'], $b['email']));
            });

            $spammer['user']['otheremails'] = $others;

            if ($spammer['byuserid']) {
                $u = User::get($this->dbhr, $this->dbhm, $spammer['byuserid']);
                $spammer['byuser'] = $u->getPublic();

                if ($me->isModerator()) {
                    $spammer['byuser']['email'] = $u->getEmailPreferred();
                }
            }

            $spammer['added'] = ISODate($spammer['added']);
            $context['id'] = $spammer['id'];
        }

        return($spammers);
    }

    public function getSpammer($id) {
        $sql = "SELECT * FROM spam_users WHERE id = ?;";
        $ret = NULL;

        $spams = $this->dbhr->preQuery($sql, [ $id ]);

        foreach ($spams as $spam) {
            $ret = $spam;
        }

        return($ret);
    }

    public function getSpammerByUserid($userid, $collection = Spam::TYPE_SPAMMER) {
        $sql = "SELECT * FROM spam_users WHERE userid = ? AND collection = ?;";
        $ret = NULL;

        $spams = $this->dbhr->preQuery($sql, [ $userid, $collection ]);

        foreach ($spams as $spam) {
            $ret = $spam;
        }

        return($ret);
    }

    public function removeSpamMembers($groupid = NULL) {
        $count = 0;
        $groupq = $groupid ? " AND groupid = $groupid " : "";

        # Find anyone in the spammer list with a current (approved or pending) membership.  Don't remove mods
        # in case someone wrongly gets onto the list.
        $sql = "SELECT * FROM memberships INNER JOIN spam_users ON memberships.userid = spam_users.userid AND spam_users.collection = ? AND memberships.role = 'Member' $groupq;";
        $spammers = $this->dbhr->preQuery($sql, [ Spam::TYPE_SPAMMER ]);

        foreach ($spammers as $spammer) {
            error_log("Found spammer {$spammer['userid']}");
            $g = Group::get($this->dbhr, $this->dbhm, $spammer['groupid']);
            $spamcheck = $g->getSetting('spammers', [ 'check' => 1, 'remove' => 1]);
            error_log("Spam check " . var_export($spamcheck, TRUE));
            if ($spamcheck['check'] && $spamcheck['remove']) {
                $u = User::get($this->dbhr, $this->dbhm, $spammer['userid']);
                error_log("Remove spammer {$spammer['userid']}");
                $u->removeMembership($spammer['groupid'], TRUE, TRUE);
                $count++;
            }
        }

        # Find any messages from spammers which are on groups.
        $groupq = $groupid ? " AND messages_groups.groupid = $groupid " : "";
        $sql = "SELECT DISTINCT messages.id, reason, messages_groups.groupid FROM `messages` INNER JOIN spam_users ON messages.fromuser = spam_users.userid AND spam_users.collection = ? AND messages.deleted IS NULL INNER JOIN messages_groups ON messages.id = messages_groups.msgid INNER JOIN users ON messages.fromuser = users.id AND users.systemrole = 'User' $groupq AND messages_groups.collection IN ('Approved', 'Pending');";
        $spammsgs = $this->dbhr->preQuery($sql, [ Spam::TYPE_SPAMMER ]);

        foreach ($spammsgs as $spammsg) {
            $g = Group::get($this->dbhr, $this->dbhm, $spammsg['groupid']);

            # Only remove on Freegle groups by default.
            $spamcheck = $g->getSetting('spammers', [ 'check' => 1, 'remove' => $g->getPrivate('type') == Group::GROUP_FREEGLE]);
            if ($spamcheck['check'] && $spamcheck['remove']) {
                error_log("Found spam message {$spammsg['id']}");
                $m = new Message($this->dbhr, $this->dbhm, $spammsg['id']);
                $m->delete("From known spammer {$spammsg['reason']}");
                $count++;
            }
        }

        # Find any chat messages from spammers.
        $chats = $this->dbhr->preQuery("SELECT id, chatid FROM chat_messages WHERE userid IN (SELECT userid FROM spam_users WHERE collection = 'Spammer');");
        foreach ($chats as $chat) {
            $sql = "UPDATE chat_messages SET reviewrejected = 1 WHERE id = ?";
            $this->dbhm->preExec($sql, [ $chat['id'] ]);
        }

        # Delete any newsfeed items from spammers.
        $newsfeeds = $this->dbhr->preQuery("SELECT id FROM newsfeed WHERE userid IN (SELECT userid FROM spam_users WHERE collection = 'Spammer');");
        foreach ($newsfeeds as $newsfeed) {
            $sql = "DELETE FROM newsfeed WHERE id = ?;";
            $this->dbhm->preExec($sql, [ $newsfeed['id'] ]);
        }

        # Delete any notifications from spammers
        $notifs = $this->dbhr->preQuery("SELECT id FROM users_notifications WHERE fromuser IN (SELECT userid FROM spam_users WHERE collection = 'Spammer');");
        foreach ($notifs as $notif) {
            $sql = "DELETE FROM users_notifications WHERE id = ?;";
            $this->dbhm->preExec($sql, [ $notif['id'] ]);
        }

        return($count);
    }

    public function addSpammer($userid, $collection, $reason) {
        $me = whoAmI($this->dbhr, $this->dbhm);
        $text = NULL;
        $id = NULL;

        switch ($collection) {
            case Spam::TYPE_WHITELIST: {
                $text = "Whitelisted: $reason";

                # Ensure nobody who is whitelisted is banned.
                $this->dbhm->preExec("DELETE FROM users_banned WHERE userid IN (SELECT userid FROM spam_users WHERE collection = ?);", [
                    Spam::TYPE_WHITELIST
                ]);
                break;
            }
            case Spam::TYPE_PENDING_ADD: {
                $text = "Reported: $reason";
                break;
            }
        }

        $this->log->log([
            'type' => Log::TYPE_USER,
            'subtype' => Log::SUBTYPE_SUSPECT,
            'byuser' => $me ? $me->getId() : NULL,
            'user' => $userid,
            'text' => $text
        ]);

        $proceed = TRUE;

        if ($collection == Spam::TYPE_PENDING_ADD) {
            # We don't want to overwrite an existing entry in the spammer list just because someone tries to
            # report it again.
            $spammers = $this->dbhr->preQuery("SELECT * FROM spam_users WHERE userid = ?;", [ $userid ]);
            foreach ($spammers as $spammer) {
                $proceed = FALSE;
            }
        }

        if ($proceed) {
            $sql = "REPLACE INTO spam_users (userid, collection, reason, byuserid) VALUES (?,?,?,?);";
            $rc = $this->dbhm->preExec($sql, [
                $userid,
                $collection,
                $reason,
                $me ? $me->getId() : NULL
            ]);

            $id = $rc ? $this->dbhm->lastInsertId() : NULL;
        }

        return($id);
    }

    public function updateSpammer($id, $userid, $collection, $reason) {
        $me = whoAmI($this->dbhr, $this->dbhm);

        switch ($collection) {
            case Spam::TYPE_SPAMMER: {
                $text = "Confirmed as spammer";
                break;
            }
            case Spam::TYPE_WHITELIST: {
                $text = "Whitelisted: $reason";
                break;
            }
            case Spam::TYPE_PENDING_ADD: {
                $text = "Reported: $reason";
                break;
            }
            case Spam::TYPE_PENDING_REMOVE: {
                $text = "Requested removal: $reason";
                break;
            }
        }

        $this->log->log([
            'type' => Log::TYPE_USER,
            'subtype' => Log::SUBTYPE_SUSPECT,
            'byuser' => $me ? $me->getId() : NULL,
            'user' => $userid,
            'text' => $text
        ]);

        # Don't want to lose any existing reason, but update the user when removal is requested so that we
        # know who's asking.
        $spammers = $this->dbhr->preQuery("SELECT * FROM spam_users WHERE id = ?;", [ $id ]);
        foreach ($spammers as $spammer) {
            $sql = "UPDATE spam_users SET collection = ?, reason = ?, byuserid = ? WHERE id = ?;";
            $rc = $this->dbhm->preExec($sql, [
                $collection,
                $reason ? $reason : $spammer['reason'],
                $collection == Spam::TYPE_PENDING_REMOVE && $me ? $me->getId() : $spammer['byuserid'],
                $id
            ]);
        }

        $id = $rc ? $this->dbhm->lastInsertId() : NULL;

        return($id);
    }

    public function deleteSpammer($id, $reason) {
        $me = whoAmI($this->dbhr, $this->dbhm);
        $spammers = $this->dbhr->preQuery("SELECT * FROM spam_users WHERE id = ?;", [ $id ]);

        $rc = FALSE;

        foreach ($spammers as $spammer) {
            $rc = $this->dbhm->preExec("DELETE FROM spam_users WHERE id = ?;", [
                $id
            ]);

            $this->log->log([
                'type' => Log::TYPE_USER,
                'subtype' => Log::SUBTYPE_SUSPECT,
                'byuser' => $me ? $me->getId() : NULL,
                'user' => $spammer['userid'],
                'text' => "Removed: $reason"
            ]);
        }

        return($rc);
    }

    public function isSpammer($email) {
        $ret = FALSE;

        if ($email) {
            $u = new User($this->dbhr, $this->dbhm);
            $uid = $u->findByEmail($email);

            if ($uid) {
                $spammers = $this->dbhr->preQuery("SELECT id FROM spam_users WHERE userid = ? AND collection = ?;", [
                    $uid,
                    Spam::TYPE_SPAMMER
                ]);

                foreach ($spammers as $spammer) {
                    $ret = TRUE;
                }
            }
        }

        return($ret);
    }
}