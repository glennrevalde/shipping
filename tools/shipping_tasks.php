<?php
declare(strict_types=1);

/**
 * The shipping housekeeping that has to happen even when nobody opens the pages. Run it every 15 minutes:
 *
 *   Linux cron:              *\/15 * * * *  php /path/to/gsmart-shipping/tools/shipping_tasks.php
 *   Windows Task Scheduler:  C:\xampp\php\php.exe C:\xampp\htdocs\gsmart-shipping\tools\shipping_tasks.php
 *
 * It releases the seats of reservations whose hold ran out, puts a no-sail hold on a vessel whose critical
 * certificate expired (and tells the fleet), escalates support cases past their service time, and tries again
 * the e-mail and SMS notices that failed. The pages do the same when they are opened, so nothing depends on
 * this script; it only keeps things on time. With several companies the same line serves every active
 * company, each in a process of its own (or one company with --company=N).
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

$opts = getopt('', ['company:']);
if (Master::enabled() && !isset($opts['company'])) {
    $ids = Master::sql()->column("SELECT id FROM companies WHERE status = 'active' ORDER BY id");
    if (!function_exists('passthru')) {
        out('passthru() is switched off: run this script once per company with --company=N (' . implode(', ', $ids) . ').');
        exit(1);
    }
    foreach ($ids as $cid) {
        out('--- company ' . $cid);
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --company=' . (int)$cid);
    }
    exit(0);
}
$tenant = Tenant::bootCli(isset($opts['company']) ? (int)$opts['company'] : null);
if ($tenant && $tenant['status'] !== 'active') {
    out('Company ' . $tenant['id'] . ' is not active: nothing to do.');
    exit(0);
}
if (!DB::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'shp_voyages'")) {
    out('This database has no shipping tables.');
    exit(0);
}
// the work is recorded under the administrator (the audit trail shows it came from this script)
$admin = (int)DB::value("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                          WHERE r.is_admin = 1 AND u.status = 'active' AND u.is_platform = 0 ORDER BY u.id LIMIT 1");
if ($admin) {
    Auth::actAs($admin);
}

$released = Booking::expireHolds();
$holdsBefore = (int)DB::value("SELECT COUNT(*) FROM shp_holds WHERE status = 'active'");
Holds::syncCertificates();
$newHolds = (int)DB::value("SELECT COUNT(*) FROM shp_holds WHERE status = 'active'") - $holdsBefore;
$escalated = Care::escalateOverdue();
$failed = DB::column("SELECT id FROM shp_notifications WHERE status = 'failed' AND attempts < 3 ORDER BY id LIMIT 200");
$retried = $failed ? Notify::retry(array_map('intval', $failed)) : 0;

out(sprintf('%s: %d expired reservation(s) released, %d new certificate hold(s), %d case(s) escalated, %d failed notice(s) tried again.',
    Settings::get('company_name'), $released, max(0, $newHolds), $escalated, $retried));
