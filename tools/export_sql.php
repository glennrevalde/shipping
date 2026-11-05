<?php
declare(strict_types=1);

/**
 * Writes a single importable .sql file (tables + triggers + all data).
 *
 *   php tools/export_sql.php                         -> database/gsmart_shipping_sample.sql
 *   php tools/export_sql.php --out=backup.sql        -> any file
 *   php tools/export_sql.php --title="..."           -> the title in the file's header
 *   php tools/export_sql.php --no-create-db          -> leave out CREATE DATABASE / USE (one company only)
 *
 * With multi-company switched on, the file holds every database of the installation: Company 1 (the database
 * of config/config.php), every company the Super Admin added, and the platform (master) database, each with
 * its own CREATE DATABASE / USE. Importing it gives back the whole platform as it was.
 *
 * Import with phpMyAdmin (Import tab, no database selected) or:  mysql -u root -p < gsmart_shipping_sample.sql
 * Importing replaces every table of the databases it names.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

$opts = getopt('', ['out::', 'no-create-db', 'title::']);
$out = $opts['out'] ?? (APP_ROOT . '/database/gsmart_shipping_sample.sql');
$primary = $GLOBALS['CONFIG']['db']['name'];
$server = server_pdo();
$multi = Master::enabled();
$createDb = !isset($opts['no-create-db']) || $multi;

$schema = file_get_contents(APP_ROOT . '/database/schema.sql');
// the combined file controls foreign key checks itself
$schema = preg_replace('/^SET FOREIGN_KEY_CHECKS\s*=\s*[01];\s*$/mi', '', $schema);
$schema = preg_replace('/^SET NAMES utf8mb4;\s*$/mi', '', $schema);
$triggers = trim(file_get_contents(APP_ROOT . '/database/triggers.sql'));
$tables = schema_tables(APP_ROOT . '/database/schema.sql');

// the company databases: Company 1 first, then the others in the order they were added
$companies = [['db' => $primary, 'name' => Settings::get('company_name')]];
if ($multi) {
    $companies = [];
    foreach (Master::sql()->all("SELECT c.name, d.db_name FROM companies c JOIN company_databases d ON d.company_id = c.id
                                  WHERE c.status IN ('active', 'inactive') ORDER BY d.is_primary DESC, c.id") as $c) {
        $companies[] = ['db' => (string)$c['db_name'], 'name' => (string)$c['name']];
    }
}

$parts = [];
$summary = [];
$totalRows = 0;
foreach ($companies as $i => $c) {
    $pdo = new PDO(dsn_for($c['db']), $GLOBALS['CONFIG']['db']['user'], $GLOBALS['CONFIG']['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET NAMES utf8mb4');
    $counts = [];
    foreach ($tables as $t) {
        $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    }
    $rows = array_sum($counts);
    $totalRows += $rows;
    $summary[] = '--    ' . $c['db'] . ': ' . $c['name'] . ' (' . number_format($rows) . ' rows, ' . number_format($counts['transactions'] ?? 0) . ' transactions)';
    $n = $i + 1;
    $parts[] = "-- =====================================================================\n"
        . "--  COMPANY DATABASE $n: {$c['db']}  ({$c['name']})\n"
        . "-- =====================================================================\n"
        . ($createDb ? "CREATE DATABASE IF NOT EXISTS `{$c['db']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nUSE `{$c['db']}`;\n\n" : '')
        . "-- tables\n" . trim($schema) . "\n\n"
        . "-- data\n" . export_data($pdo, $tables) . "\n"
        . "-- audit trail protection (created after the data is loaded)\n" . $triggers . "\n\n";
}

if ($multi) {
    $masterDb = Master::dbName();
    $m = new PDO(dsn_for($masterDb), $GLOBALS['CONFIG']['db']['user'], $GLOBALS['CONFIG']['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $m->exec('SET NAMES utf8mb4');
    $mschema = file_get_contents(APP_ROOT . '/database/master_schema.sql');
    $mschema = preg_replace('/^SET NAMES utf8mb4;\s*$/mi', '', $mschema);
    $mtables = schema_tables(APP_ROOT . '/database/master_schema.sql');
    $mrows = 0;
    foreach ($mtables as $t) {
        $mrows += (int)$m->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    }
    $totalRows += $mrows;
    $summary[] = '--    ' . $masterDb . ': the platform (companies, Company Codes, usernames, Super Admins; ' . number_format($mrows) . ' rows)';
    $drops = implode("\n", array_map(fn($t) => "DROP TABLE IF EXISTS `$t`;", array_reverse($mtables)));
    $parts[] = "-- =====================================================================\n"
        . "--  PLATFORM (MASTER) DATABASE: $masterDb\n"
        . "--  Multi-company is switched on by storage/platform/multi_company.json in the program folder.\n"
        . "-- =====================================================================\n"
        . "CREATE DATABASE IF NOT EXISTS `$masterDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nUSE `$masterDb`;\n\n"
        . $drops . "\n\n" . trim($mschema) . "\n\n"
        . "-- data\n" . export_data($m, $mtables) . "\n"
        . "-- the platform audit log is permanent\n"
        . "DROP TRIGGER IF EXISTS trg_platform_audit_no_update;\nDROP TRIGGER IF EXISTS trg_platform_audit_no_delete;\n\nDELIMITER \$\$\n"
        . "CREATE TRIGGER trg_platform_audit_no_update BEFORE UPDATE ON platform_audit\nFOR EACH ROW\nBEGIN\n  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform audit entries are read-only.';\nEND\$\$\n\n"
        . "CREATE TRIGGER trg_platform_audit_no_delete BEFORE DELETE ON platform_audit\nFOR EACH ROW\nBEGIN\n  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform audit entries are permanent.';\nEND\$\$\nDELIMITER ;\n\n";
}

$title = $opts['title'] ?? 'G-SMaRT Shipping System - complete database';
$file = basename($out);
$head = "-- =====================================================================\n"
    . "--  $title\n"
    . '--  Generated ' . date('Y-m-d H:i:s P') . "\n"
    . '--  ' . count($parts) . ' database' . (count($parts) === 1 ? '' : 's') . ', ' . number_format($totalRows) . " rows:\n"
    . implode("\n", $summary) . "\n"
    . "--\n"
    . "--  Import:  phpMyAdmin > Import (no database selected) > choose this file > Go\n"
    . "--      or:  mysql -u root -p < $file\n"
    . "--  Works on MySQL 5.5.3 or newer (5.5, 5.6, 5.7, 8.x) and MariaDB 5.5 or newer.\n"
    . "--  Import as root (or a user with CREATE, INSERT, REFERENCES and TRIGGER privileges).\n"
    . "--  WARNING: importing drops and recreates every table in the databases below.\n"
    . "--\n"
    . "--  Log-ins: see USER_ACCOUNTS.md in the program folder. Company administrator admin / Admin@12345"
    . ($multi ? ",\n--  Super Admin superadmin / Super@12345" : '') . " (a new password is asked at first log-in).\n"
    . "-- =====================================================================\n\n"
    . "SET NAMES utf8mb4;\n"
    . "SET FOREIGN_KEY_CHECKS = 0;\n"
    . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
    . "SET time_zone = '" . date('P') . "';\n\n";

$sql = $head . implode("\n", $parts) . "SET FOREIGN_KEY_CHECKS = 1;\n";
file_put_contents($out, $sql);
out(sprintf('Wrote %s (%s KB, %d database(s), %s rows).', $out, number_format(strlen($sql) / 1024), count($parts), number_format($totalRows)));

function dsn_for(string $db): string
{
    $c = $GLOBALS['CONFIG']['db'];
    return 'mysql:host=' . $c['host'] . ';port=' . ($c['port'] ?? 3306) . ';dbname=' . $db . ';charset=utf8mb4';
}
