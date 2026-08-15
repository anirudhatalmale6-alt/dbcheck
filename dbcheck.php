<?php
/**
 * dbcheck.php - read-only database connection diagnostic
 * Upload into the folder of the site you are testing, then open:
 *     https://<the-site>/dbcheck.php?k=oester-check-2026
 * DELETE THIS FILE AGAIN WHEN YOU ARE DONE.
 *
 * It does not change anything. It only reports why a connection fails.
 * Passwords are never printed - only their length is shown.
 */

if (!isset($_GET['k']) || $_GET['k'] !== 'oester-check-2026') {
    header('HTTP/1.0 403 Forbidden');
    exit('forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
@ini_set('display_errors', '1');
@error_reporting(E_ALL);

// PHP 8.1+ makes mysqli throw exceptions on failure; we want plain false back.
if (function_exists('mysqli_report') && defined('MYSQLI_REPORT_OFF')) {
    @mysqli_report(MYSQLI_REPORT_OFF);
}

function out($label, $value) { echo str_pad($label, 26) . ' : ' . $value . "\n"; }
function mask($p) { return ($p === null || $p === '') ? '(empty)' : '(' . strlen($p) . ' characters)'; }

echo "=== dbcheck ===\n";
out('PHP version', PHP_VERSION);
out('Document root', isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '?');
out('ext mysql (old)', function_exists('mysql_connect') ? 'available' : 'NOT AVAILABLE');
out('ext mysqli', function_exists('mysqli_connect') ? 'available' : 'NOT AVAILABLE');
out('ext pdo_mysql', class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers()) ? 'available' : 'NOT AVAILABLE');
out('mysql.default_socket', ini_get('mysql.default_socket'));
out('mysqli.default_socket', ini_get('mysqli.default_socket'));
echo "\n";

/* ---- 1. find likely config files ------------------------------------ */
$dir  = dirname(__FILE__);
$names = array('config.php', 'configuration.php', 'connect.php', 'connection.php',
               'db.php', 'database.php', 'dbconnect.php', 'settings.php', 'conf.php',
               'inc/config.php', 'includes/config.php', 'include/config.php',
               'inc/db.php', 'includes/db.php', 'config/config.php', 'admin/config.php');

$found = array();
foreach ($names as $n) {
    $p = $dir . '/' . $n;
    if (is_file($p)) { $found[] = $p; }
}
echo "Config files found:\n";
if (!$found) {
    echo "  none of the usual names - list the folder yourself and look for the file\n";
    echo "  that contains the database settings.\n";
} else {
    foreach ($found as $p) { echo '  ' . $p . "\n"; }
}
echo "\n";

/* ---- 2. pull credentials out of them, without running them ---------- */
$creds = array();
foreach ($found as $p) {
    $src = @file_get_contents($p);
    if ($src === false) { continue; }
    $c = array('host' => null, 'user' => null, 'pass' => null, 'name' => null, 'file' => $p);

    // Exact names only, and the name must be followed by "=" or "=>".
    // Fuzzy prefix matching is what makes $dbhost get picked up as the database
    // name, so do not do that.
    $map = array(
        'host' => array('db_host', 'dbhost', 'mysql_host', 'sql_host', 'hostname', 'dbserver', 'db_server', 'host', 'server'),
        'user' => array('db_user', 'dbuser', 'mysql_user', 'sql_user', 'db_username', 'dbusername', 'username', 'user'),
        'pass' => array('db_password', 'dbpassword', 'mysql_password', 'sql_password', 'db_pass', 'dbpass', 'mysql_pass', 'password', 'passwd', 'pass'),
        'name' => array('db_name', 'dbname', 'mysql_database', 'mysql_db', 'sql_db', 'database', 'db'),
    );
    foreach ($map as $key => $alts) {
        $alt = '(?:' . implode('|', $alts) . ')';
        // $variable = 'value';  /  define('NAME', 'value');  /  'key' => 'value'
        $patterns = array(
            '/\$' . $alt . '\s*=\s*[\'"]([^\'"]*)[\'"]/i',
            '/define\s*\(\s*[\'"]' . $alt . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/i',
            '/[\'"]' . $alt . '[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i',
        );
        foreach ($patterns as $re) {
            if (preg_match($re, $src, $m)) { $c[$key] = $m[count($m) - 1]; break; }
        }
    }
    if ($c['user'] !== null || $c['name'] !== null) { $creds[] = $c; }
}

if (!$creds) {
    echo "No database credentials could be read automatically.\n";
    echo "Open the config file by hand and note host / user / password / database name.\n";
    exit;
}

/* ---- 3. try to connect --------------------------------------------- */
foreach ($creds as $c) {
    echo "--- credentials from " . $c['file'] . "\n";
    out('  host', $c['host'] === null ? '(not found)' : $c['host']);
    out('  user', $c['user'] === null ? '(not found)' : $c['user']);
    out('  password', mask($c['pass']));
    out('  database', $c['name'] === null ? '(not found)' : $c['name']);
    echo "\n";

    $hosts = array();
    if ($c['host'] !== null && $c['host'] !== '') { $hosts[] = $c['host']; }
    foreach (array('localhost', '127.0.0.1') as $h) {
        if (!in_array($h, $hosts)) { $hosts[] = $h; }
    }

    foreach ($hosts as $h) {
        // mysqli
        if (function_exists('mysqli_connect')) {
            $link = @mysqli_connect($h, (string)$c['user'], (string)$c['pass']);
            if ($link) {
                echo "  mysqli  @ $h : CONNECTED";
                if ($c['name'] !== null && $c['name'] !== '') {
                    echo @mysqli_select_db($link, $c['name'])
                        ? ", database '" . $c['name'] . "' OK\n"
                        : ", but database '" . $c['name'] . "' FAILED: " . mysqli_error($link) . "\n";
                } else { echo "\n"; }
                @mysqli_close($link);
            } else {
                echo "  mysqli  @ $h : FAILED - " . mysqli_connect_error() . "\n";
            }
        }
        // old mysql
        if (function_exists('mysql_connect')) {
            $link = @mysql_connect($h, (string)$c['user'], (string)$c['pass']);
            if ($link) {
                echo "  mysql   @ $h : CONNECTED";
                if ($c['name'] !== null && $c['name'] !== '') {
                    echo @mysql_select_db($c['name'], $link)
                        ? ", database '" . $c['name'] . "' OK\n"
                        : ", but database '" . $c['name'] . "' FAILED: " . mysql_error($link) . "\n";
                } else { echo "\n"; }
                @mysql_close($link);
            } else {
                echo "  mysql   @ $h : FAILED - " . mysql_error() . "\n";
            }
        }
    }
    echo "\n";
}

echo "=== end ===\n";
echo "Delete dbcheck.php and phpinfo.php from the server when you are finished.\n";
