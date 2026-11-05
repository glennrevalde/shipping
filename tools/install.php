<?php
declare(strict_types=1);

/**
 * Command-line installer.
 *
 *   php tools/install.php            create the database (if missing), tables, triggers and master data
 *   php tools/install.php --demo     ... plus sample transactions
 *   php tools/install.php --drop     drop and recreate the database first (DESTROYS DATA; the platform
 *                                    database and the multi-company switch file are removed too)
 *   php tools/install.php --platform ... and switch multi-company on: the database becomes Company 1 of the
 *                                    platform, with the Super Admin superadmin / Super@12345 (new password at
 *                                    first log-in) who adds the other shipping companies
 *
 * Database settings come from config/config.php (or DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS).
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/seed.php';

$opts = getopt('', ['demo', 'drop', 'platform']);
$name = $GLOBALS['CONFIG']['db']['name'];

$server = server_pdo();
if (isset($opts['drop'])) {
    out("Dropping database `$name` ...");
    $server->exec("DROP DATABASE IF EXISTS `$name`");
    // the platform belongs to the database it was set up on: it goes too, with every company it created
    $masterDb = Master::dbName();
    $exists = (int)$server->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $server->quote($masterDb))->fetchColumn();
    if ($exists) {
        foreach ($server->query("SELECT db_name FROM `$masterDb`.company_databases WHERE is_primary = 0")->fetchAll(PDO::FETCH_COLUMN) as $db) {
            if (DB::validName((string)$db)) {
                out("Dropping company database `$db` ...");
                $server->exec("DROP DATABASE IF EXISTS `$db`");
            }
        }
        out("Dropping platform database `$masterDb` ...");
        $server->exec("DROP DATABASE IF EXISTS `$masterDb`");
    }
    // the switch file and the other companies' folders go only when they belong to this database
    if (Master::flag() !== null) {
        @unlink(APP_ROOT . '/storage/platform/multi_company.json');
        foreach (glob(APP_ROOT . '/storage/companies/c*') ?: [] as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    }
    Master::forgetFlag();
}
$server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$pdo = DB::pdo();
$existing = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
if ($existing && !isset($opts['drop'])) {
    out('The database already has tables. Use --drop to reinstall (this deletes all data).');
    exit(1);
}

out('Creating tables ...');
run_sql_file($pdo, APP_ROOT . '/database/schema.sql');
out('Creating audit trail protection triggers ...');
run_sql_file($pdo, APP_ROOT . '/database/triggers.sql');

out('Loading master data ...');
DB::tx(function () {
    seed_master_data();
});

if (!isset($opts['demo'])) {
    // a database for real work: every account chooses its own password at the first log-in
    DB::run('UPDATE users SET must_change_password = 1');
}
if (isset($opts['demo'])) {
    require __DIR__ . '/demo_data.php';
    out('Recording sample transactions ...');
    load_demo_data();
}

if (isset($opts['platform'])) {
    out('Switching multi-company on (platform database, Super Admin, Company 1) ...');
    $r = setup_platform(isset($opts['demo']));
    out('  Company 1 code ' . $r['code'] . ', platform database ' . $r['master_db']);
}

out('Done. Log in as admin / Admin@12345 (you will be asked to choose a new password).');
if (isset($opts['platform'])) {
    out('The Super Admin logs in on the same page as superadmin / Super@12345 (a new password is asked at first log-in).');
}
