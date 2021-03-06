<?php
# This makes sure we get all stats
define('MODTOOLS', TRUE);

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/misc/Stats.php');
require_once(IZNIK_BASE . '/include/dashboard/Dashboard.php');
require_once(IZNIK_BASE . '/include/user/User.php');

# Update record of which groups are on TN.
#
# Not in a single call as this seems to hit a deadlock.
$groups = $dbhr->preQuery("SELECT id FROM groups WHERE ontn = 1;");
foreach ($groups as $group) {
    $dbhm->preExec("UPDATE groups SET ontn = 0 WHERE id = ?;", [
        $group['id']
    ]);
}

$tngroups = file_get_contents("https://trashnothing.com/modtools/api/freegle-groups?key=" . TNKEY);
$tngroups = str_replace("{u'", "{'", $tngroups);
$tngroups = str_replace(", u'", ", '", $tngroups);
$tngroups = str_replace("'", '"', $tngroups);
$tngroups = json_decode($tngroups, TRUE);

# Ensure the polyindex is set correctly.  It can get out of step if someone updates the DB manually.
$dbhm->preExec("UPDATE groups SET polyindex = GeomFromText(COALESCE(poly, polyofficial, 'POINT(0 0)')) where type = 'Freegle' AND polyindex != GeomFromText(COALESCE(poly, polyofficial, 'POINT(0 0)'));");

$g = new Group($dbhr, $dbhm);
foreach ($tngroups as $gname => $tngroup) {
    if ($tngroup['listed']) {
        $gid = $g->findByShortName($gname);
        $dbhm->preExec("UPDATE groups SET ontn = 1 WHERE id = ?;", [$gid]);
    }
}

$date = date('Y-m-d', strtotime("yesterday"));
$groups = $dbhr->preQuery("SELECT * FROM groups;");
foreach ($groups as $group) {
    error_log($group['nameshort']);
    $s = new Stats($dbhr, $dbhm, $group['id']);
    $s->generate($date);
}

# Find what proportion of overall activity an individual group is responsible for.  We will use this when calculating
# a fundraising target.
$date = date('Y-m-d', strtotime("30 days ago"));
$totalact = $dbhr->preQuery("SELECT SUM(count) AS total FROM stats INNER JOIN groups ON stats.groupid = groups.id WHERE stats.type = ? AND groups.type = ? AND publish = 1 AND onhere = 1 AND date >= ?;", [
    Stats::APPROVED_MESSAGE_COUNT,
    Group::GROUP_FREEGLE,
    $date
]);

$target = DONATION_TARGET;
$fundingcalc = 0;

foreach ($totalact as $total) {
    $tot = $total['total'];

    $groups = $dbhr->preQuery("SELECT groups.*, DATEDIFF(NOW(), lastyahoomembersync) AS lastsync FROM groups WHERE type = ? AND publish = 1 AND onhere = 1 ORDER BY LOWER(nameshort) ASC;", [
        Group::GROUP_FREEGLE
    ]);

    foreach ($groups as $group) {
        $acts = $dbhr->preQuery("SELECT SUM(count) AS count FROM stats WHERE stats.type = ? AND groupid = ? AND date >= ?;", [
            Stats::APPROVED_MESSAGE_COUNT,
            $group['id'],
            $date
        ]);

        #error_log("#{$group['id']} {$group['nameshort']} pc = $pc from {$acts[0]['count']} vs $tot");
        $pc = 100 * $acts[0]['count'] / $tot;

        $dbhm->preExec("UPDATE groups SET activitypercent = ? WHERE id = ?;", [
            $pc,
            $group['id']
        ]);

        # Calculate fundraising target.  Our fair share would be $pc * $target / 100, but we stretch that out a bit
        # because the larger groups are likely to include more affluent people.
        #
        # Round up to £50 for small groups.
        $portion = ceil($pc * $target / 100) * 10;
        $portion = max(50, $portion);
        error_log("{$group['nameshort']} target £$portion");
        $fundingcalc += $portion;

        $dbhm->preExec("UPDATE groups SET fundingtarget = ? WHERE id = ?;", [
            $portion,
            $group['id']
        ]);

        # We decide if they're active on here by whether they've had a Yahoo member sync or approved a message.
        $timeq = $group['lastmodactive'] ? ("timestamp >= '" . date("Y-m-d H:i:s", strtotime($group['lastmodactive'])) . "' AND ") : '';
        $acts = $dbhr->preQuery("SELECT MAX(timestamp) AS moderated FROM logs WHERE $timeq groupid = ? AND logs.type = 'Message' AND subtype = 'Approved';", [$group['id']]);
        $lastmodactive = NULL;

        foreach ($acts as $act) {
            $lastsync = $group['lastyahoomembersync'] ? strtotime($group['lastyahoomembersync']) : NULL;
            $lastact = $act['moderated'] ? strtotime($act['moderated']) : NULL;
            $max = ($lastsync || $lastact) ? max($lastsync, $lastact) : NULL;

            if ($max) {
                $lastmodactive = date("Y-m-d H:i:s", $max);
                $dbhm->preExec("UPDATE groups SET lastmodactive = ? WHERE id = ?;", [
                    $lastmodactive,
                    $group['id']
                ]);
            }
        }

        # Find when the group was last moderated - max of that and when they approved a message (which could be
        # on Yahoo).
        $sql = "SELECT MAX(arrival) AS max FROM messages_groups WHERE groupid = ? AND approvedby IS NOT NULL;";
        $maxs = $dbhr->preQuery($sql, [$group['id']]);
        $dbhm->preExec("UPDATE groups SET lastmoderated = ? WHERE id = ?;", [
            strtotime($lastmodactive) > strtotime($maxs[0]['max']) ? $lastmodactive : $maxs[0]['max'],
            $group['id']
        ]);

        # Find the last auto-approved message
        $timeq = $group['lastautoapprove'] ? ("timestamp >= '" . date("Y-m-d H:i:s", strtotime($group['lastautoapprove'])) . "' AND ") : '';
        $logs = $dbhr->preQuery("SELECT MAX(timestamp) AS max FROM logs INNER JOIN messages_groups ON logs.msgid = messages_groups.msgid WHERE $timeq messages_groups.groupid = ? AND logs.type = 'Message' AND logs.subtype = 'Autoapproved';", [
            $group['id']
        ]);
        $dbhm->preExec("UPDATE groups SET lastautoapprove = ? WHERE id = ?;", [
            $logs[0]['max'],
            $group['id']
        ]);

        # Count mods active in the last 30 days.
        $start = date('Y-m-d', strtotime("30 days ago"));
        $sql = "SELECT COUNT(DISTINCT(approvedby)) AS count FROM messages_groups WHERE groupid = ? AND arrival > ? AND approvedby IS NOT NULL;";
        $actives = $dbhr->preQuery($sql, [
            $group['id'],
            $start
        ]);

        # If we didn't find any approvals, but we know a mod was active, then there's at least 1.
        $dbhm->preExec("UPDATE groups SET activemodcount = ? WHERE id = ?;", [
            $actives[0]['count'] == 0 && $lastmodactive ? 1 : $actives[0]['count'],
            $group['id']
        ]);

        # Count owners and mods not active on this group but active on other groups in the last 30 days.
        $start = date('Y-m-d', strtotime("30 days ago"));
        $mods = $dbhr->preQuery("SELECT COUNT(DISTINCT(userid)) AS count FROM memberships WHERE groupid = ? AND role IN ('Owner') AND userid NOT IN (SELECT DISTINCT(approvedby) FROM messages_groups WHERE groupid = ? AND arrival > ? AND approvedby IS NOT NULL) AND userid IN (SELECT DISTINCT(approvedby) FROM messages_groups WHERE groupid != ? AND arrival > ? AND approvedby IS NOT NULL);", [
            $group['id'],
            $group['id'],
            $start,
            $group['id'],
            $start
        ]);
        $dbhm->preExec("UPDATE groups SET backupownersactive = ? WHERE id = ?;", [
            $mods[0]['count'],
            $group['id']
        ]);

        $mods = $dbhr->preQuery("SELECT COUNT(DISTINCT(userid)) AS count FROM memberships WHERE groupid = ? AND role IN ('Moderator') AND userid NOT IN (SELECT DISTINCT(approvedby) FROM messages_groups WHERE groupid = ? AND arrival > ? AND approvedby IS NOT NULL) AND userid IN (SELECT DISTINCT(approvedby) FROM messages_groups WHERE groupid != ? AND arrival > ? AND approvedby IS NOT NULL);", [
            $group['id'],
            $group['id'],
            $start,
            $group['id'],
            $start
        ]);
        $dbhm->preExec("UPDATE groups SET backupmodsactive = ? WHERE id = ?;", [
            $mods[0]['count'],
            $group['id']
        ]);
    }
}

error_log("\n\nTotal target £$fundingcalc");
# Update outcomes stats
$mysqltime = date("Y-m-01", strtotime("13 months ago"));
$stats = $dbhr->preQuery("SELECT groupid, SUM(count) AS count, CONCAT(YEAR(date), '-', LPAD(MONTH(date), 2, '0')) AS date FROM stats WHERE type = ? AND date > ? GROUP BY groupid, YEAR(date), MONTH(date);", [
    Stats::OUTCOMES,
    $mysqltime
]);

foreach ($stats as $stat) {
    error_log($stat['groupid']);
    $dbhm->preExec("REPLACE INTO stats_outcomes (groupid, count, date) VALUES (?, ?, ?);", [
        $stat['groupid'],
        $stat['count'],
        $stat['date']
    ]);
}

# Look for any dashboards which need refreshing.  Any which exist need updating now as we are run by cron
# at the start of a new day.  Any which don't exist will be created on demand.
$todos = $dbhr->preQuery("SELECT id, `key`, type, userid, systemwide, groupid, start FROM users_dashboard;");

foreach ($todos as $todo) {
    $me = $todo['userid'] ? (new User($dbhr, $dbhm, $todo['userid'])) : NULL;
    $_SESSION['id'] = $me ? $me->getId() : NULL;
    $d = new Dashboard($dbhr, $dbhm, $me);
    error_log("Update dashboard user {$todo['userid']}, group {$todo['groupid']}, type {$todo['type']}, start {$todo['start']}");
    $d->get($todo['systemwide'], $todo['groupid'] == NULL, $todo['groupid'], NULL, $todo['type'], $todo['start'], 'today', TRUE, $todo['key']);
}

if (RETURN_PATH) {
    # Ensure that our seedlist is current and will be mailed.
    $dbhm->preExec("UPDATE users SET lastaccess = NOW() WHERE id IN (SELECT userid FROM returnpath_seedlist) AND DATE(lastaccess) < CURDATE();");
}
