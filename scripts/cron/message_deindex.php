<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/message/Message.php');

# Only interested in being able to search within the last 30 days.
$date = date('Y-m-d', strtotime("30 days ago"));
$msgs = $dbhr->preQuery("SELECT messages.id FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id AND messages_groups.collection = 'Approved' WHERE messages_groups.arrival < ? ORDER BY messages_groups.arrival DESC;", [
    $date
]);

error_log("Deindex " . count($msgs));

$done = 0;

foreach ($msgs as $msg) {
    $m = new Message($dbhr, $dbhm, $msg['id']);
    $m->deindex();

    $done++;

    if ($done % 1000 === 0) {
        error_log("...$done / " . count($msgs));
    }
}