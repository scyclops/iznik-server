<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';
require_once IZNIK_BASE . '/include/message/MessageCollection.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class messagesTest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
    }

    public function testApproved() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: OFFER: Thing (Place)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('22 Aug 2015', '22 Aug 2035', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $this->log("Approved id $id");

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::APPROVED);
        $a = new Message($this->dbhr, $this->dbhm, $id);
        $a->setPrivate('source', Message::PLATFORM);

        # Should be able to see this message even logged out, as this is a Freegle group...once we have consent.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $group1
        ]);
        $this->log("Get when logged out but no permission" . var_export($ret, true));
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Should be able to get a summary of the id, subject and any attachments.

        $sender = User::get($this->dbhr, $this->dbhm, $a->getFromuser());
        $sender->setPrivate('publishconsent', 1);

        $ret = $this->call('messages', 'GET', [
            'groupid' => $group1
        ]);
        $this->log("Get when logged out with permission" . var_export($ret, true));
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertFalse(array_key_exists('source', $msgs[0])); # Only a member, shouldn't see mod att

        # Now join and check we can see see it.
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $id);
        $u->addMembership($group1, User::ROLE_OWNER);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Omit groupid - should use groups for currently logged in user.
        $ret = $this->call('messages', 'GET', [
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        #$this->log(var_export($msgs, TRUE));
        assertEquals(1, count($msgs));

        # Test search by word
        $u->addMembership($group1, User::ROLE_MEMBER);
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'searchmess',
            'groupid' => $group1,
            'search' => 'thing'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertFalse(array_key_exists('source', $msgs[0])); # Only a member, shouldn't see mod att

        # Test search by id
        $this->log("Test by id");
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'searchmess',
            'groupid' => $group1,
            'search' => $a->getID()
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);

        # Test search by member
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'searchmemb',
            'groupid' => $group1,
            'search' => 'test@test.com'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertFalse(array_key_exists('source', $msgs[0])); # Only a member, shouldn't see mod att

        # Search by member on current groups
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'searchmemb',
            'search' => 'test@test.com'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertFalse(array_key_exists('source', $msgs[0])); # Only a member, shouldn't see mod att

        # Check the log.
        $u->setRole(User::ROLE_MODERATOR, $group1);

        # Get messages for our logged in groups.
        $ret = $this->call('messages', 'GET', [
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertTrue(array_key_exists('source', $msgs[0]));

        # Get messages for this specific user
        $ret = $this->call('messages', 'GET', [
            'fromuser' => $a->getFromuser()
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);

        # Get messages for another user
        $ret = $this->call('messages', 'GET', [
            'fromuser' => $id
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Filter by type
        $ret = $this->call('messages', 'GET', [
            'types' => [ Message::TYPE_OFFER ]
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));

        $ret = $this->call('messages', 'GET', [
            'types' => [ Message::TYPE_OTHER ]
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # A bad type.  This will be the same as if we didn't supply any; the key thing is the SQL injection defence.
        $ret = $this->call('messages', 'GET', [
            'types' => [ 'wibble' ]
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));

        # Sleep for background logging
        $this->waitBackground();

        $this->log("Fromuser is " . $a->getFromuser());
        $ret = $this->call('user', 'GET', [
            'id' => $a->getFromuser(),
            'logs' => TRUE
        ]);
        $this->log("Logs".  var_export($ret, true));
        $log = $this->findLog('Message', 'Received', $ret['user']['logs']);
        $this->log("Got log " . var_export($log, TRUE));
        assertEquals($group1, $log['group']['id']);
        assertEquals($a->getFromuser(), $log['user']['id']);
        assertEquals($a->getID(), $log['message']['id']);

        $id = $a->getID();
        $this->log("Delete it");
        $a->delete();

        # Actually delete the message to force a codepath.
        $this->log("Delete msg $id");
        $rc = $this->dbhm->preExec("DELETE FROM messages WHERE id = ?;", [ $id ]);
        assertEquals(1, $rc);
        $this->waitBackground();

        # The delete should show in the log.
        $ret = $this->call('user', 'GET', [
            'id' => $a->getFromuser(),
            'logs' => TRUE
        ]);
        $log = $this->findLog('Message', 'Received', $ret['user']['logs']);
        assertEquals($group1, $log['group']['id']);
        assertEquals($a->getFromuser(), $log['user']['id']);
        assertEquals(1, $log['message']['deleted']);

        }

    public function testSpam() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup', Group::GROUP_REUSE);

        # Create a group with a message on it
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_ireplace('To: FreeglePlayground <freegleplayground@yahoogroups.com>', 'To: "testgroup@yahoogroups.com" <testgroup@yahoogroups.com>', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_PENDING, 'from1@test.com', 'to@test.com', $msg);
        $this->log("Spam msgid $id");
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::SPAM);
        $a = new Message($this->dbhr, $this->dbhm, $id);

        # Shouldn't be able to see spam
        $ret = $this->call('messages', 'GET', [
            'groupid' => $group1,
            'collection' => 'Spam'
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Now join - shouldn't be able to see a spam message
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $id);
        $u->addMembership($group1);

        $ret = $this->call('messages', 'GET', [
            'groupid' => $group1,
            'collection' => 'Spam'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Promote to owner - should be able to see it.
        $u->setRole(User::ROLE_OWNER, $group1);
        assertEquals(User::ROLE_OWNER, $u->getRoleForGroup($group1));
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $ret = $this->call('messages', 'GET', [
            'groupid' => $group1,
            'collection' => 'Spam',
            'start' => '2100-01-01T06:00:00Z'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        $this->log(var_export($msgs, true));
        assertTrue(array_key_exists('source', $msgs[0])); # An owner, should see mod att

        $a->delete();

        }

    public function testError() {
        $ret = $this->call('messages', 'GET', [
            'groupid' => 0,
            'collection' => 'wibble'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['messages']));

        }

    public function testPending() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup', Group::GROUP_REUSE);

        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::PENDING);
        $a = new Message($this->dbhr, $this->dbhm, $id);

        # Shouldn't be able to see pending logged out.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $group1,
            'collection' => 'Pending'
        ]);
        self::assertEquals(1, $ret['ret']);

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $id);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Shouldn't be able to see pending logged in but not a member.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $group1,
            'collection' => 'Pending'
        ]);

        $this->log("Shouldn't see pending " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Now join - shouldn't be able to see a pending message
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $id);
        $u->addMembership($group1);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('messages', 'GET', [
            'groupid' => $group1,
            'collection' => 'Pending'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Promote to mod - should be able to see it.
        $this->log("Check as mod for " . $a->getID());
        $u->setRole(User::ROLE_MODERATOR, $group1);
        assertEquals(User::ROLE_MODERATOR, $u->getRoleForGroup($group1));
        $ret = $this->call('messages', 'GET', [
            'groupid' => $group1,
            'collection' => 'Pending'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertEquals($group1, $msgs[0]['groups'][0]['groupid']);
        assertTrue(array_key_exists('source', $msgs[0])); # A mod, should see mod att

        $a->delete();

        }

    public function testPut() {
        $this->log(__METHOD__ . " start");

        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup', Group::GROUP_REUSE);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));

        $ret = $this->call('messages', 'PUT', [
            'groupid' => $group1,
            'source' => Message::YAHOO_PENDING,
            'from' => 'test@test.com',
            'yahoopendingid' => 833,
            'message' => $this->unique($msg)
        ]);

        # Should fail - not a mod
        assertEquals(2, $ret['ret']);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($group1, User::ROLE_MODERATOR);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('messages', 'PUT', [
            'groupid' => $group1,
            'source' => Message::YAHOO_PENDING,
            'from' => 'test@test.com',
            'yahoopendingid' => 833,
            'message' => $this->unique($msg)
        ]);

        # Should work
        assertEquals(0, $ret['ret']);
        assertEquals(MailRouter::PENDING, $ret['routed']);

        # Should fail - invalid source
        $ret = $this->call('messages', 'PUT', [
            'groupid' => $group1,
            'source' => 'wibble',
            'from' => 'test@test.com',
            'yahooapprovedid' => 833,
            'message' => $this->unique($msg)
        ]);

        assertEquals(2, $ret['ret']);

        $ret = $this->call('messages', 'PUT', [
            'groupid' => $group1,
            'source' => Message::YAHOO_APPROVED,
            'from' => 'test@test.com',
            'yahooapprovedid' => 833,
            'message' => $this->unique($msg)
        ]);

        # Should work
        assertEquals(0, $ret['ret']);
        assertEquals(MailRouter::APPROVED, $ret['routed']);

        }

    public function testNear() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup', Group::GROUP_FREEGLE);

        # Need a location and polygon for near testing.
        $g->setPrivate('lng', 179.15);
        $g->setPrivate('lat', 8.4);
        $g->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $g->setPrivate('onhere', 1);

        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('22 Aug 2015', '22 Aug 2035', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $this->log("Approved id $id");

        # Ensure we have consent to see this message
        $a = new Message($this->dbhr, $this->dbhm, $id);
        $sender = User::get($this->dbhr, $this->dbhm, $a->getFromuser());
        $sender->setPrivate('publishconsent', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');

        $ret = $this->call('messages', 'GET', [
            'search' => 'basic t',
            'subaction' => 'searchmess',
            'nearlocation' => $lid
        ]);
        $this->log("Get near " . var_export($ret, true));
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($id, $msgs[0]['id']);

        }

    public function testPendingWithdraw() {
        # Set up a pending message on a native group.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 0);

        $u = new User($this->dbhr, $this->dbhm);
        $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@test.com');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $u->addMembership($gid);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mid = $r->received(Message::EMAIL, 'from@test.com', 'testgroup@groups.ilovefreegle.org', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $this->log("From " . $m->getFromuser() . "," . $m->getFromaddr());
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_WITHDRAWN
        ]);

        assertEquals(0, $ret['ret']);
        self::assertEquals(TRUE, $ret['deleted']);

        }

//    public function testEH() {
////        $u = new User($this->dbhr, $this->dbhm);
//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;
//
//        $u = new User($this->dbhr, $this->dbhm);
//
//        $uid = $u->findByEmail('edward@ehibbert.org.uk');
//        $u = new User($this->dbhr, $this->dbhm, $uid);
//        $_SESSION['id'] = $uid;
//        $ret = $this->call('messages', 'GET', [
//            'collection' => MessageCollection::ALLUSER,
//            'summary' => TRUE,
//            'types' => [
//                Message::TYPE_OFFER,
//                Message::TYPE_WANTED
//            ],
//            'fromuser' => $uid,
//        ]);
//
//        assertEquals(0, $ret['ret']);
//        error_log("Took {$ret['duration']} DB {$ret['dbwaittime']}");
//        error_log(var_export($ret['context'], TRUE));
//        error_log("Got " . count($ret['messages']) . " messages");
//    }
}

