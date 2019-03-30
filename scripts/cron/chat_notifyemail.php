<?php
# Notify by email of unread chats

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');
global $dbhr, $dbhm;

$lockh = lockScript(basename(__FILE__));

$c = new ChatRoom($dbhr, $dbhm);

error_log("Start User2Mod " . date("Y-m-d H:i:s", time()));
$count = $c->notifyByEmail(NULL, ChatRoom::TYPE_USER2MOD);
error_log("Sent $count for User2Mod " . date("Y-m-d H:i:s", time()));

error_log("Start User2User " . date("Y-m-d H:i:s", time()));
$count = $c->notifyByEmail(NULL, ChatRoom::TYPE_USER2USER);
error_log("Sent $count to User2User " . date("Y-m-d H:i:s", time()));

unlockScript($lockh);