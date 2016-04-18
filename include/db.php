<?php

use Pheanstalk\Pheanstalk;
require_once('config.php');
require_once(IZNIK_BASE . '/include/utils.php');

# Everyone has a custom DB class.  We have ours primarily for Percona clustering.  That can cause operations
# to fail due to conflict with other servers. In that case we retry a few times here, and then if that doesn't
# work - which it may not if we are inside a transaction - then we throw an exception which will cause us to
# retry the whole API call from scratch.
#
# We use aggregation rather than extension because otherwise we hit issues with PHPUnit, which finds
# it hard to mock PDOs.

$dbconfig = array (
    'host' => '127.0.0.1',
    'user' => SQLUSER,
    'pass' => SQLPASSWORD,
    'database' => SQLDB
);

class DBException extends Exception
{
}

class LoggedPDO {

    protected $_db;
    private $inTransaction = FALSE;
    private $tries = 10;
    private $errorLog = FALSE;
    private $lastInsert = NULL;
    private $transactionStart = NULL;
    private $dbwaittime = 0;
    private $pheanstalk = NULL;
    private $readconn;

    const DUPLICATE_KEY = 1062;
    const MAX_LOG_SIZE = 100000;

    /**
     * @param int $tries
     */
    public function setTries($tries)
    {
        $this->tries = $tries;
    }

    /**
     * @param boolean $errorLog
     */
    public function setErrorLog($errorLog)
    {
        $this->errorLog = $errorLog;
    }

    /**
     * @param null $pheanstalk
     */
    public function setPheanstalk($pheanstalk)
    {
        $this->pheanstalk = $pheanstalk;
    }

    public function __construct($dsn, $username, $password, $options, $readonly = FALSE, LoggedPDO $readconn = NULL)
    {
        $start = microtime(true);
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->options = $options;
        $this->readonly = $readonly;
        $this->readconn = $readconn;

        $this->_db = new PDO($dsn, $username, $password, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
        $this->dbwaittime += microtime(true) - $start;

        return $this;
    }

    public function getWaitTime() {
        return $this->dbwaittime;
    }

    # Our most commonly used method is a combine prepare and execute, wrapped in
    # a retry.  This is SQL injection safe and handles Percona failures.
    public function preExec($sql, $params = NULL, $log = TRUE) {
        return($this->prex($sql, $params, FALSE, $log));
    }

    public function preQuery($sql, $params = NULL, $log = FALSE) {
        return($this->prex($sql, $params, TRUE, $log));
    }

    public function parentPrepare($sql) {
        return($this->_db->prepare($sql));
    }

    public function getErrorInfo($sth) {
        # Split into function for UT
        return($sth->errorInfo());
    }

    public function executeStatement($sth, $params) {
        # Split into function for UT
        return($sth->execute($params));
    }

    private function prex($sql, $params = NULL, $select, $log) {
        $try = 0;
        $ret = NULL;
        $msg = '';
        $worked = false;
        $start = microtime(true);

        do {
            try {
                $sth = $this->parentPrepare($sql);
                $rc = $this->executeStatement($sth, $params);

                if (!$select) {
                    $this->lastInsert = $this->_db->lastInsertId();
                }

                if ($rc) {
                    # For selects we return all the rows found; for updates we return the return value.
                    $ret = $select ? $sth->fetchAll() : $rc;
                    $worked = true;

                    if ($log) {
                        $duration = microtime(true) - $start;
                    }
                } else {
                    $msg = var_export($this->getErrorInfo($sth), true);
                    if (stripos($msg, 'has gone away') !== FALSE) {
                        # This can happen if we have issues with the DB, e.g. one server dies or the connection is
                        # timed out.  We re-open the connection and try again.
                        $try++;
                        $this->_db = NULL;
                        $this->_db = new PDO($this->dsn, $this->username, $this->password);
                    }
                }

                $try++;
            } catch (Exception $e) {
                if (stripos($e->getMessage(), 'deadlock') !== FALSE) {
                    # It's a Percona deadlock - retry.
                    $try++;
                    $msg = $e->getMessage();
                } else {
                    $msg = "Non-deadlock DB Exception " . $e->getMessage() . " $sql";
                    $try = $this->tries;
                }
            }
        } while (!$worked && $try < $this->tries);

        if ($worked && $try > 1) {
            error_log("prex succeeded after $try for $sql");
        } else if (!$worked)
            $this->giveUp($msg . " for $sql " . var_export($params, true) . " " . var_export($this->_db->errorInfo(), true));

        $this->dbwaittime += microtime(true) - $start;

        if ($log && SQLLOG) {
            $mysqltime = date("Y-m-d H:i:s", time());
            $duration = microtime(true) - $start;
            $logret = $select ? count($ret) : ("$ret:" . $this->lastInsert);

            if (isset($_SESSION)) {
                $logparams = var_export($params, TRUE);
                $logparams = substr($logparams, 0, LoggedPDO::MAX_LOG_SIZE);
                $logsql = "INSERT INTO logs_sql (userid, date, duration, session, request, response) VALUES (" .
                    presdef('id', $_SESSION, 'NULL') .
                    ", '$mysqltime', $duration, " .
                    $this->quote(session_id()) . "," .
                    $this->quote($sql . ", " . $this->quote($logparams)) . "," .
                    $this->quote($logret) . ");";
                $this->background($logsql);
            }
        }

        if ($this->errorLog) {
            error_log(((microtime(true) - $start) * 1000) . "ms for $sql " . var_export($params, TRUE));
        }

        return($ret);
    }

    public function parentExec($sql) {
        return($this->_db->exec($sql));
    }

    function retryExec($sql) {
        $try = 0;
        $ret = NULL;
        $msg = '';
        $worked = false;
        $start = microtime(true);

        do {
            try {
                $ret = $this->parentExec($sql);

                if ($ret !== FALSE) {
                    $worked = true;
                } else {
                    $msg = var_export($this->errorInfo(), true);
                    $try++;
                    if (stripos($msg, 'has gone away') !== FALSE) {
                        # This can happen if we have issues with the DB, e.g. one server dies or the connection is
                        # timed out.  We re-open the connection and try again.
                        $this->_db = NULL;
                        $this->_db = new PDO($this->dsn, $this->username, $this->password);
                    }
                }
            } catch (Exception $e) {
                if (stripos($e->getMessage(), 'deadlock') !== FALSE) {
                    # It's a Percona deadlock - retry.
                    $try++;
                    $msg = $e->getMessage();
                } else {
                    $msg = "Non-deadlock DB Exception $sql " . $e->getMessage();
                    $try = $this->tries;
                }
            }
        } while (!$worked && $try < $this->tries);

        if ($worked && $try > 0) {
            error_log("retryExec succeeded after $try for $sql");
        } else if (!$worked)
            $this->giveUp($msg);

        $this->dbwaittime += microtime(true) - $start;

        return($ret);
    }

    public function parentQuery($sql) {
        return($this->_db->query($sql));
    }

    public function retryQuery($sql) {
        $try = 0;
        $ret = NULL;
        $worked = false;
        $start = microtime(true);
        $msg = '';

        do {
            try {
                $ret = $this->parentQuery($sql);

                if ($ret !== FALSE) {
                    $worked = true;
                } else {
                    $try++;
                    $msg = var_export($this->errorInfo(), true);
                    if (stripos($msg, 'has gone away') !== FALSE) {
                        # This can happen if we have issues with the DB, e.g. one server dies or the connection is
                        # timed out.  We re-open the connection and try again.
                        $this->_db = NULL;
                        $this->_db = new PDO($this->dsn, $this->username, $this->password);
                    }
                }
            } catch (Exception $e) {
                if (stripos($e->getMessage(), 'deadlock') !== FALSE) {
                    # Retry.
                    $try++;
                    $msg = $e->getMessage();
                } else {
                    $msg = "Non-deadlock DB Exception $sql " . $e->getMessage();
                    $try = $this->tries;
                }
            }
        } while (!$worked && $try < $this->tries);

        if ($worked && $try > 0) {
            error_log("retryQuery succeeded after $try");
        } else if (!$worked)
            $this->giveUp($msg); // No brace because of coverage oddity

        #error_log("Query took " . (microtime(true) - $start) . " $sql" );
        $this->dbwaittime += microtime(true) - $start;

        return($ret);
    }

    public function inTransaction() {
        return($this->inTransaction) ;
    }

    public function quote($str) {
        return($this->_db->quote($str));
    }

    public function errorInfo() {
        return($this->_db ? $this->_db->errorInfo() : 'No DB handle');
    }

    public function rollBack() {
        $this->inTransaction = FALSE;

        $time = microtime(true);
        $rc = $this->_db->rollBack();
        $duration = microtime(true) - $time;
        $mysqltime = date("Y-m-d H:i:s", time());

        if (SQLLOG) {
            $logsql = "INSERT INTO logs_sql (userid, date, duration, session, request, response) VALUES (" . presdef('id', $_SESSION, 'NULL') . ", '$mysqltime', $duration, " . $this->quote(session_id()) . "," . $this->quote('ROLLBACK;') . "," . $this->quote($rc) . ");";
            $this->background($logsql);
        }

        return($rc);
    }

    public function beginTransaction() {
        $this->inTransaction = TRUE;
        $this->transactionStart = microtime(true);
        $ret = $this->_db->beginTransaction();
        $duration = microtime(true) - $this->transactionStart;
        $this->dbwaittime += $duration;

        if (SQLLOG) {
            $mysqltime = date("Y-m-d H:i:s", time());
            $logsql = "INSERT INTO logs_sql (userid, date, duration, session, request, response) VALUES (" . presdef('id', $_SESSION, 'NULL') . ", '$mysqltime', $duration, " . $this->quote(session_id()) . "," . $this->quote('BEGIN TRANSACTION;') . "," . $this->quote($ret . ":" . $this->lastInsert) . ");";
            $this->background($logsql);
        }

        return($ret);
    }

    function commit() {
        $time = microtime(true);
        # PDO's commit() isn't reliable - it can return true
        $this->_db->query('COMMIT;');
        $rc = $this->_db->errorCode() == '0000';

        # ...but issue it anyway to get the states in sync
        $this->_db->commit();
        $duration = microtime(true) - $time;

        $this->dbwaittime += $duration;

        if (SQLLOG) {
            $mysqltime = date("Y-m-d H:i:s", time());
            $logsql = "INSERT INTO logs_sql (userid, date, duration, session, request, response) VALUES (" . presdef('id', $_SESSION, 'NULL') . ", '$mysqltime', $duration, " . $this->quote(session_id()) . "," . $this->quote('COMMIT;') . "," . $this->quote($rc) . ");";
            $this->background($logsql);
        }

        $this->inTransaction = FALSE;

        return($rc);
    }

    public function exec ($sql, $log = true)    {
        $time = microtime(true);
        $ret = $this->retryExec($sql);
        $this->lastInsert = $this->_db->lastInsertId();

        if ($log && SQLLOG) {
            $mysqltime = date("Y-m-d H:i:s", time());
            $duration = microtime(true) - $time;
            $logsql = "INSERT INTO logs_sql (userid, date, duration, session, request, response) VALUES (" . (defined('_SESSION') ? presdef('id', $_SESSION, 'NULL') : 'NULL') . ", '$mysqltime', $duration, " . $this->quote(session_id()) . "," . $this->quote($sql) . "," . $this->quote($ret . ":" . $this->lastInsert) . ");";
            $this->background($logsql);
        }

        return($ret);
    }

    public function query($sql) {
        $ret = $this->retryQuery($sql);
        return($ret);
    }

    public function lastInsertId() {
        return($this->lastInsert);
    }

    public function background($sql) {
        $count = 0;
        do {
            $done = FALSE;
            try {
                # This SQL needs executing, but not in the foreground, and it's not the end of the
                # world if we drop it, or duplicate it.
                if (!$this->pheanstalk) {
                    $this->pheanstalk = new Pheanstalk(PHEANSTALK_SERVER);
                }

                $id = $this->pheanstalk->put(json_encode(array(
                    'type' => 'sql',
                    'queued' => time(),
                    'sql' => $sql,
                    'ttr' => 300
                )));
                #error_log("Backgroupd $id for $sql");
                $done = TRUE;
            } catch (Exception $e) {
                # Try again in case it's a temporary error.
                error_log("Beanstalk exception " . $e->getMessage() . " on sql of len " . strlen($sql));
                $this->pheanstalk = NULL;
                $count++;
            }
        } while (!$done && $count < 10);
    }

    private function giveUp($msg) {
        throw new DBException("Unexpected database error $msg", 999);
    }
}

# We have two handles; one for reads, and one for writes, which we need because we might have a complex
# DB architecture where the master is split out from a replicated copy.
#
# Don't use persistent connections as they don't play nice - PDO can use a connection which was already
# closed.  It's possible that our retrying would handle this ok, but we should only rely on that if
# we've tested it better and we need the perf gain.
$dsn = "mysql:host={$dbconfig['host']};dbname={$dbconfig['database']};charset=utf8";

$dbhr = new LoggedPDO($dsn, $dbconfig['user'], $dbconfig['pass'], array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => FALSE
), TRUE);

$dbhm = new LoggedPDO($dsn, $dbconfig['user'], $dbconfig['pass'], array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => FALSE
), FALSE, $dbhr);
