<?php
/**
 * dbcheck.php v2 - read-only database connection diagnostic
 *
 * Upload into the folder of the site you are testing, then open:
 *     https://<the-site>/dbcheck.php?k=oester-check-2026
 * DELETE THIS FILE AGAIN WHEN YOU ARE DONE.
 *
 * v2 searches the whole site folder for the file that opens the database
 * connection, instead of guessing at common file names.
 *
 * It changes nothing. Passwords are never printed - only their length.
 */

$KEY = 'oester-check-2026';

if (!isset($_GET['k']) || $_GET['k'] !== $KEY) {
    header('Content-Type: text/plain; charset=utf-8');
    header('HTTP/1.0 403 Forbidden');
    echo "Forbidden.\n\n";
    echo "This page needs the key on the end of the address.\n";
    echo "Add  ?k=" . $KEY . "  to the URL, so it looks like this:\n\n";
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'your-site';
    $self = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '/dbcheck.php';
    echo "    https://" . $host . $self . "?k=" . $KEY . "\n";
    exit;
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

echo "=== dbcheck v2 ===\n";
out('PHP version', PHP_VERSION);
out('Document root', isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '?');
out('ext mysql (old)', function_exists('mysql_connect') ? 'available' : 'NOT AVAILABLE');
out('ext mysqli', function_exists('mysqli_connect') ? 'available' : 'NOT AVAILABLE');
out('ext pdo_mysql', class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers()) ? 'available' : 'NOT AVAILABLE');
echo "\n";

/* ---- 1. walk the folder and find files that open a connection --------- */
$dir  = dirname(__FILE__);
$skip = array('assets', 'node_modules', 'cache', 'uploads', 'upload', 'images', 'image',
              'img', 'js', 'css', 'fonts', 'font', 'vendor', 'tmp', 'temp', 'logs', 'log',
              'backup', 'backups', 'media', 'files', 'plugins', 'bower_components');

$files = array();
$scanned = 0;
$walk = null;
$walk = function ($path, $depth) use (&$walk, &$files, &$scanned, $skip) {
    if ($depth > 3 || $scanned > 4000) { return; }
    $h = @opendir($path);
    if (!$h) { return; }
    while (false !== ($e = readdir($h))) {
        if ($e === '.' || $e === '..') { continue; }
        $full = $path . '/' . $e;
        if (is_dir($full)) {
            if (in_array(strtolower($e), $skip) || substr($e, 0, 1) === '.') { continue; }
            $walk($full, $depth + 1);
        } elseif (preg_match('/\.(php|inc|php5)$/i', $e)) {
            $scanned++;
            $files[] = $full;
        }
    }
    closedir($h);
};
$walk($dir, 0);

$self = basename(__FILE__);
$hits = array();
foreach ($files as $f) {
    if (basename($f) === $self) { continue; }
    $src = @file_get_contents($f);
    if ($src === false) { continue; }
    if (preg_match('/mysql_connect|mysqli_connect|new\s+mysqli|new\s+PDO|mysql_pconnect|mysqli_real_connect/i', $src)) {
        $hits[$f] = $src;
    }
}

out('PHP files scanned', count($files));
echo "\nFiles that open a database connection:\n";
if (!$hits) {
    echo "  none found within 3 folder levels\n";
    foreach (array_slice($files, 0, 40) as $f) { echo "    (seen) " . $f . "\n"; }
} else {
    foreach ($hits as $f => $src) { echo "  " . $f . "\n"; }
}
echo "\n";

/* ---- 2. pull credentials out, without executing anything ------------- */
$map = array(
    'host' => array('db_host', 'dbhost', 'mysql_host', 'sql_host', 'hostname', 'dbserver', 'db_server', 'host', 'server'),
    'user' => array('db_user', 'dbuser', 'mysql_user', 'sql_user', 'db_username', 'dbusername', 'username', 'user'),
    'pass' => array('db_password', 'dbpassword', 'mysql_password', 'sql_password', 'db_pass', 'dbpass', 'mysql_pass', 'password', 'passwd', 'pass'),
    'name' => array('db_name', 'dbname', 'mysql_database', 'mysql_db', 'sql_db', 'database', 'db'),
);

function grab($src, $alts) {
    $alt = '(?:' . implode('|', $alts) . ')';
    $patterns = array(
        '/\$' . $alt . '\s*=\s*[\'"]([^\'"]*)[\'"]/i',
        '/define\s*\(\s*[\'"]' . $alt . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/i',
        '/[\'"]' . $alt . '[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i',
    );
    foreach ($patterns as $re) {
        if (preg_match($re, $src, $m)) { return $m[count($m) - 1]; }
    }
    return null;
}

// also look at the connect call itself, for hard-coded literal arguments
function grab_inline($src) {
    if (preg_match('/mysql_(?:p)?connect\s*\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/i', $src, $m)) {
        return array('host' => $m[1], 'user' => $m[2], 'pass' => $m[3], 'name' => null);
    }
    if (preg_match('/(?:mysqli_connect|new\s+mysqli)\s*\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*(?:,\s*[\'"]([^\'"]*)[\'"])?/i', $src, $m)) {
        return array('host' => $m[1], 'user' => $m[2], 'pass' => $m[3],
                     'name' => isset($m[4]) && $m[4] !== '' ? $m[4] : null);
    }
    return null;
}

$creds = array();
$seen  = array();
foreach ($hits as $f => $src) {
    $c = array('file' => $f, 'host' => null, 'user' => null, 'pass' => null, 'name' => null);
    foreach ($map as $key => $alts) { $c[$key] = grab($src, $alts); }

    // old code usually picks the database in a separate select_db() call
    if ($c['name'] === null
        && preg_match('/mysql(?:i)?_select_db\s*\(\s*(?:\$[A-Za-z_][A-Za-z0-9_]*\s*,\s*)?[\'"]([^\'"]+)[\'"]/i', $src, $m)) {
        $c['name'] = $m[1];
    }

    $inline = grab_inline($src);
    if ($inline !== null) {
        foreach (array('host', 'user', 'pass', 'name') as $k) {
            if ($c[$k] === null && $inline[$k] !== null) { $c[$k] = $inline[$k]; }
        }
    }
    // the connecting file often includes a separate config file - scan those too
    if ($c['user'] === null) {
        if (preg_match_all('/(?:include|include_once|require|require_once)\s*\(?\s*[\'"]([^\'"]+\.(?:php|inc))[\'"]/i', $src, $mm)) {
            foreach ($mm[1] as $rel) {
                $p = (substr($rel, 0, 1) === '/') ? $rel : dirname($f) . '/' . $rel;
                $s2 = @file_get_contents($p);
                if ($s2 === false) { continue; }
                foreach ($map as $key => $alts) {
                    if ($c[$key] === null) { $c[$key] = grab($s2, $alts); }
                }
                if ($c['user'] !== null) { $c['file'] = $f . '  (settings from ' . $p . ')'; break; }
            }
        }
    }

    if ($c['user'] === null && $c['name'] === null) { continue; }
    $sig = $c['host'] . '|' . $c['user'] . '|' . $c['pass'] . '|' . $c['name'];
    if (isset($seen[$sig])) { continue; }
    $seen[$sig] = true;
    $creds[] = $c;
}

if (!$creds) {
    echo "No database credentials could be read automatically.\n";
    echo "Open one of the files listed above and read host / user / database name by hand.\n";
    exit;
}

/* ---- 3. try to connect ---------------------------------------------- */
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
