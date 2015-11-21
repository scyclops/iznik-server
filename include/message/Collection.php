<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/message/Attachment.php');
require_once(IZNIK_BASE . '/include/message/Message.php');

class Collection
{
    # These match the collection enumeration
    const INCOMING = 'Incoming';
    const APPROVED = 'Approved';
    const PENDING = 'Pending';
    const SPAM = 'Spam';

    /** @var  $dbhr LoggedPDO */
    public $dbhr;
    /** @var  $dbhm LoggedPDO */
    public $dbhm;

    private $collection;

    /**
     * @return null
     */
    public function getCollection()
    {
        return $this->collection;
    }
    
    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $collection)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        switch ($collection) {
            case Collection::APPROVED:
            case Collection::PENDING:
            case Collection::SPAM:
                $this->collection = $collection;
                break;
            default:
                $this->collection = NULL;
        }
    }

    function get($start, $limit, $groupfilter) {
        $groups = [];
        $groupids = [];
        $msgs = [];

        if ($start) {
            $args = [
                $start,
                $this->collection
            ];
            $startq = "messages.date < ? ";
        } else {
            $args = [
                $this->collection
            ];
            $startq = '1=1';
        }

        foreach ($groupfilter as $groupid) {
            $g = new Group($this->dbhr, $this->dbhm, $groupid);
            $groups[$groupid] = $g->getPublic();
            $groupids[] = $groupid;
        }

        if (count($groupids) > 0) {
            $groupq = " AND groupid IN (" . implode(',', $groupids) . ") ";

            # At the moment we only support ordering by date DESC.
            $sql = "SELECT msgid, groupid FROM messages_groups INNER JOIN messages ON messages_groups.msgid = messages.id WHERE $startq $groupq AND collection = ? AND deleted = 0 ORDER BY messages.date DESC LIMIT $limit";
            $msglist = $this->dbhr->preQuery($sql, $args);

            # Don't return the message attribute as it will be huge.  They can get that via a call to the
            # message API call.
            foreach ($msglist as $msg) {
                $m = new Message($this->dbhr, $this->dbhm, $msg['msgid']);
                $role = $m->getRoleForMessage();

                switch ($this->collection) {
                    case Collection::APPROVED:
                        $n = $m->getPublic();
                        unset($n['message']);
                        $msgs[] = $n;
                        break;
                    case Collection::PENDING:
                        if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
                            # Only visible to moderators or owners
                            $n = $m->getPublic();
                            unset($n['message']);
                            $msgs[] = $n;
                        }
                        break;
                    case Collection::SPAM:
                        if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
                            # Only visible to moderators or owners
                            $n = $m->getPublic();
                            unset($n['message']);
                            $msgs[] = $n;
                        }
                        break;
                }
            }
        }

        return([$groups, $msgs]);
    }

    function findByYahooApprovedId($groupid, $id) {
        $sql = "SELECT msgid FROM messages_groups WHERE groupid = ? AND yahooapprovedid = ?;";
        $msglist = $this->dbhr->preQuery($sql, [
            $groupid,
            $id
        ]);

        if (count($msglist) == 1) {
            return($msglist[0]['msgid']);
        } else {
            return NULL;
        }
    }

    function findByYahooPendingId($groupid, $id) {
        $sql = "SELECT msgid FROM messages_groups WHERE groupid = ? AND yahoopendingid = ?;";
        $msglist = $this->dbhr->preQuery($sql, [
            $groupid,
            $id
        ]);

        if (count($msglist) == 1) {
            return($msglist[0]['msgid']);
        } else {
            return NULL;
        }
    }
}