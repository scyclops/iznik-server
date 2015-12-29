<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTest.php';
require_once IZNIK_BASE . '/include/message/Message.php';
require_once IZNIK_BASE . '/include/misc/Search.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class searchTest extends IznikTest
{
    private $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DROP TABLE IF EXISTS test_index");
        $this->dbhm->preExec("CREATE TABLE test_index LIKE messages_index");
        $this->s = new Search($this->dbhr, $this->dbhm, 'test_index', 'msgid', 'arrival', 'words');
        $this->dbhm->preExec("DELETE FROM words WHERE word = 'zzzutzzz';");
    }

    protected function tearDown()
    {
        parent::tearDown();
        #$this->dbhm->preExec("DROP TABLE IF EXISTS test_index");
    }

    public function __construct()
    {
    }

    public function testBasic()
    {
        error_log(__METHOD__);

        $msg = file_get_contents('msgs/basic');
        $msg = str_replace('Basic test', 'OFFER: Test zzzutzzz', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->setSearch($this->s);
        $m->parse(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $id1 = $m->save();
        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        $m1->setSearch($this->s);
        error_log("Created message id $id1");

        # Search for various terms
        $ctx = NULL;
        $ret = $m->search("Test", $ctx);
        assertEquals($id1, $ret[0]);

        $ctx = NULL;
        $ret = $m->search("Test zzzutzzz", $ctx);
        assertEquals($id1, $ret[0]);

        $ctx = NULL;
        $ret = $m->search("zzzutzzz", $ctx);
        assertEquals($id1, $ret[0]);

        # Test fuzzy
        $ctx = NULL;
        error_log("Test fuzzy");
        $ret = $m->search("tuesday", $ctx);
        error_log("Fuzzy " . var_export($ctx, true));
        assertEquals($id1, $ret[0]);
        assertEquals($id1, $ctx['SoundsLike']);

        # Test typo
        $ctx = NULL;
        $ret = $m->search("Tets", $ctx);
        error_log("Typo " . var_export($ctx, true));
        assertEquals($id1, $ret[0]);
        assertEquals($id1, $ctx['Typo']);

        # Too far
        $ctx = NULL;
        $ret = $m->search("Tetx", $ctx);
        assertEquals(0, count($ret));

        # Test restricted search
        $ctx = NULL;
        $ret = $m->search("zzzutzzz", $ctx, Search::Limit, [ $id1 ]);
        assertEquals($id1, $ret[0]);

        $ctx = NULL;
        $ret = $m->search("zzzutzzz", $ctx, Search::Limit, []);
        assertEquals($id1, $ret[0]);

        # Search again using the same context - will find starts with
        error_log("CTX " . var_export($ctx, true));
        $ret = $m->search("zzzutzzz", $ctx);
        assertEquals($id1, $ret[0]);

        # And again - will find sounds like
        $ret = $m->search("zzzutzzz", $ctx);
        assertEquals($id1, $ret[0]);

        # And again - will find typo
        $ret = $m->search("zzzutzzz", $ctx);
        assertEquals($id1, $ret[0]);

        $ret = $m->search("zzzutzzz", $ctx);
        assertEquals(0, count($ret));

        # Delete - shouldn't be returned after that.
        $m1->delete();

        $ctx = NULL;
        $ret = $m->search("Test", $ctx);
        assertEquals(0, count($ret));

        error_log(__METHOD__ . " end");
    }

    public function testMultiple()
    {
        error_log(__METHOD__);

        $msg = file_get_contents('msgs/basic');
        $msg = str_replace('Basic test', 'OFFER: Test zzzutzzz', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->setSearch($this->s);
        $m->parse(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $id1 = $m->save();
        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        $m1->setSearch($this->s);
        error_log("Created message id $id1");

        $msg = file_get_contents('msgs/basic');
        $msg = str_replace('Basic test', 'OFFER: Test yyyutyyy', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->setSearch($this->s);
        $m->parse(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $id2 = $m->save();
        $m2 = new Message($this->dbhr, $this->dbhm, $id2);
        $m2->setSearch($this->s);
        error_log("Created message id $id2");

        # Search for various terms
        $ctx = NULL;
        $ret = $m->search("Test", $ctx);
        assertEquals(2, count($ret));
        assertEquals($id2, $ret[0]);
        assertEquals($id1, $ret[1]);

        $ctx = NULL;
        $ret = $m->search("Test zzzutzzz", $ctx);
        assertEquals(2, count($ret));
        assertEquals($id1, $ret[0]);
        assertEquals($id2, $ret[1]);

        $ctx = NULL;
        $ret = $m->search("Test yyyutyyy", $ctx);
        assertEquals(2, count($ret));
        assertEquals($id2, $ret[0]);
        assertEquals($id1, $ret[1]);

        $ctx = NULL;
        $ret = $m->search("zzzutzzz", $ctx);
        assertEquals(1, count($ret));
        assertEquals($id1, $ret[0]);

        $ctx = NULL;
        $ret = $m->search("yyyutyyy", $ctx);
        assertEquals(1, count($ret));
        assertEquals($id2, $ret[0]);

        # Test restricted search
        $ctx = NULL;
        $ret = $m->search("test", $ctx, Search::Limit, [ $id1 ]);
        assertEquals(1, count($ret));
        assertEquals($id1, $ret[0]);

        $m1->delete();
        $m2->delete();

        error_log(__METHOD__ . " end");
    }
}
