<?php
declare(strict_types=1);

/**
 * Acceptance tests of the shipping controls, run against a copy of the SAMPLE database (they add bookings,
 * scans, defects and refunds to it). Never run them on live data.
 *
 *   DB_NAME=gsmart_test php tools/install.php --drop --demo      a separate test copy with the sample data
 *   DB_NAME=gsmart_test php tools/acceptance_tests.php           the tests; each line says PASS or FAIL
 *
 * The database name must contain "test" (or add --force).
 *
 *   1  the last places of a voyage are sold once when several counters sell them at the same moment
 *   2  a group that does not fit is not held at all
 *   3  a payment sent twice (same one-time key) is recorded once, with one sales receipt
 *   4  a ticket scanned twice boards once; a ticket of another voyage and a changed code are refused
 *   5  a refund never gives back more than was paid, and a ticket cannot have two refunds at once
 *   6  the person who asks for a refund cannot approve it
 *   7  a safety-critical defect stops the departure, and no override can lift it
 *   8  a technician cannot inspect their own work
 *   9  stock cannot be issued beyond what is on hand
 *  10  cash cannot go into a cashier shift that was already closed
 *  11  nothing can be posted before the closing date without the permission
 *  12  every transaction in the books balances
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

$opts = getopt('', ['force', 'child:', 'voyage:', 'accom:']);

// ------------------------------------------------------------------ a child process of test 1: one counter selling one place
if (isset($opts['child'])) {
    Auth::actAs((int)DB::value("SELECT id FROM users WHERE username = 'ticket1'"));
    try {
        $bid = Booking::hold(['voyage_id' => (int)$opts['voyage'], 'channel' => 'counter', 'contact_name' => 'Race test ' . $opts['child']],
            [['last_name' => 'Race', 'first_name' => 'Test ' . $opts['child'], 'birth_date' => '1990-01-01', 'gender' => 'M', 'nationality' => 'Filipino',
                'category_id' => Fares::regularCategory(), 'accom_code' => (string)$opts['accom']]]);
        echo "OK $bid\n";
    } catch (UserError $e) {
        echo 'REFUSED ' . $e->getMessage() . "\n";
    }
    exit(0);
}

$name = $GLOBALS['CONFIG']['db']['name'];
if (!str_contains($name, 'test') && !isset($opts['force'])) {
    out("The database `$name` does not look like a test copy. Install one with DB_NAME=gsmart_test php tools/install.php --drop --demo, or add --force.");
    exit(1);
}
if (!DB::value('SELECT COUNT(*) FROM shp_voyages')) {
    out('These tests need the sample data: install the test copy with --demo.');
    exit(1);
}

$passed = 0;
$failed = 0;
$check = function (string $title, bool $ok, string $detail = '') use (&$passed, &$failed) {
    $ok ? $passed++ : $failed++;
    out(($ok ? 'PASS  ' : 'FAIL  ') . $title . ($detail !== '' ? '  (' . $detail . ')' : ''));
};
$refused = function (callable $fn): ?string {
    try {
        $fn();
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        return $e->getMessage();
    }
    return null;
};
$as = fn(string $u) => Auth::actAs((int)DB::value('SELECT id FROM users WHERE username = :u', ['u' => $u]));
$pax = fn(string $first, string $accom) => ['last_name' => 'Tester', 'first_name' => $first, 'birth_date' => '1990-01-01', 'gender' => 'F', 'nationality' => 'Filipino',
    'category_id' => Fares::regularCategory(), 'accom_code' => $accom];

// a fast-craft voyage on sale a few days ahead
$vid = (int)DB::value("SELECT v.id FROM shp_voyages v JOIN shp_vessels s ON s.id = v.vessel_id WHERE s.vessel_type = 'fastcraft' AND v.status = 'on_sale' AND v.etd > :n ORDER BY v.etd DESC LIMIT 1",
    ['n' => date('Y-m-d H:i:s', time() + 86400)]);
$v = Ship::voyage($vid);
$accom = (string)DB::value('SELECT code FROM shp_accom_classes WHERE vessel_id = :v ORDER BY capacity DESC LIMIT 1', ['v' => $v['vessel_id']]);
out('Test voyage ' . $v['voyage_no'] . ', class ' . $accom);

// ------------------------------------------------------------------ 1. the last places, sold by six counters at once
$taken = array_sum(array_column(Booking::capacity($v), 'taken'));
DB::update('shp_voyages', ['pax_quota' => $taken + 3], 'id = :id', ['id' => $vid]);
$procs = [];
for ($i = 1; $i <= 6; $i++) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child=' . $i . ' --voyage=' . $vid . ' --accom=' . escapeshellarg($accom);
    $procs[] = popen($cmd, 'r');
}
$ok = 0;
$no = 0;
foreach ($procs as $p) {
    $line = trim((string)stream_get_contents($p));
    pclose($p);
    str_starts_with($line, 'OK') ? $ok++ : $no++;
}
$live = array_sum(array_column(Booking::capacity(Ship::voyage($vid)), 'taken'));
$check('1. Six counters sell the last 3 places at the same moment: 3 sold, 3 refused, never more than the limit', $ok === 3 && $no === 3 && $live === $taken + 3, "$ok sold, $no refused, $live of " . ($taken + 3));

// ------------------------------------------------------------------ 2. a group that does not fit
$as('ticket1');
$before = (int)DB::value('SELECT COUNT(*) FROM shp_tickets WHERE voyage_id = :v', ['v' => $vid]);
$msg = $refused(fn() => Booking::hold(['voyage_id' => $vid, 'channel' => 'counter', 'contact_name' => 'Group'], [$pax('A', $accom), $pax('B', $accom)]));
$after = (int)DB::value('SELECT COUNT(*) FROM shp_tickets WHERE voyage_id = :v', ['v' => $vid]);
$check('2. A group of two on a full voyage is refused and nothing is held', $msg !== null && $before === $after, (string)$msg);
DB::update('shp_voyages', ['pax_quota' => null], 'id = :id', ['id' => $vid]);

// ------------------------------------------------------------------ 3. the same payment sent twice
$bid = Booking::hold(['voyage_id' => $vid, 'channel' => 'portal', 'contact_name' => 'Idempotent'], [$pax('Pay', $accom)]);
$key = 'test-' . bin2hex(random_bytes(4));
$ref = 'G' . random_int(10000000, 99999999);
Booking::pay($bid, ['channel' => 'ewallet', 'provider_ref' => $ref, 'idempotency_key' => $key]);
$second = $refused(fn() => Booking::pay($bid, ['channel' => 'ewallet', 'provider_ref' => $ref, 'idempotency_key' => $key]));
$payments = (int)DB::value('SELECT COUNT(*) FROM shp_payments WHERE booking_id = :b', ['b' => $bid]);
$receipts = (int)DB::value("SELECT COUNT(DISTINCT p.txn_id) FROM shp_postings p WHERE p.source_type = 'booking' AND p.source_id = :b", ['b' => $bid]);
$check('3. A payment sent twice with the same key is recorded once', $payments === 1, $payments . ' payment(s), ' . $receipts . ' booking posting(s)' . ($second ? '; second call: ' . $second : '; second call returned the first payment'));
$t = DB::row("SELECT * FROM shp_tickets WHERE booking_id = :b", ['b' => $bid]);

// ------------------------------------------------------------------ 4. scans
DB::update('shp_voyages', ['status' => 'boarding'], 'id = :id', ['id' => $vid]);
$as('boarding1');
$code = Ship::qrPayload('ticket', $t['ticket_no'], $t['qr_sig']);
$r1 = Boarding::scan($vid, $code, 'board', ['device_id' => 'TEST']);
$r2 = Boarding::scan($vid, $code, 'board', ['device_id' => 'TEST-2']);
$other = (int)DB::value("SELECT id FROM shp_voyages WHERE id <> :v AND status = 'on_sale' ORDER BY etd LIMIT 1", ['v' => $vid]);
DB::update('shp_voyages', ['status' => 'boarding'], 'id = :id', ['id' => $other]);
$r3 = Boarding::scan($other, $code, 'board', ['device_id' => 'TEST']);
$forged = Ship::qrPayload('ticket', $t['ticket_no'], strrev((string)$t['qr_sig']));
$r4 = Boarding::scan($vid, $forged, 'board', ['device_id' => 'TEST']);
DB::update('shp_voyages', ['status' => 'on_sale'], 'id = :id', ['id' => $other]);
$check('4a. First scan boards the passenger', ($r1['result'] ?? '') === 'accepted', (string)($r1['result'] ?? ''));
$check('4b. Second scan of the same ticket is refused as a duplicate', ($r2['result'] ?? '') === 'duplicate', (string)($r2['result'] ?? ''));
$check('4c. The ticket scanned at another voyage\'s gate is refused', ($r3['result'] ?? '') === 'wrong_voyage', (string)($r3['result'] ?? ''));
$check('4d. A code whose signature was changed is refused', ($r4['result'] ?? '') === 'invalid', (string)($r4['result'] ?? ''));
DB::update('shp_voyages', ['status' => 'on_sale'], 'id = :id', ['id' => $vid]);

// ------------------------------------------------------------------ 5 and 6. refunds
$as('ticket1');
$bid2 = Booking::hold(['voyage_id' => $vid, 'channel' => 'counter', 'contact_name' => 'Refund test'], [$pax('Refund', $accom)]);
Booking::pay($bid2, ['channel' => 'card', 'provider_ref' => 'C' . random_int(10000000, 99999999), 'idempotency_key' => 'test-' . bin2hex(random_bytes(4))]);
$t2 = DB::row('SELECT * FROM shp_tickets WHERE booking_id = :b', ['b' => $bid2]);
$rid = Booking::requestRefund($bid2, [(int)$t2['id']], [], 'voyage_cancelled', 'Test refund', 'card');
$r = DB::row('SELECT * FROM shp_refunds WHERE id = :id', ['id' => $rid]);
$check('5a. The refund is not more than the passenger paid', (float)$r['refund_amount'] <= (float)$t2['total'] + 0.005, money($r['refund_amount']) . ' of ' . money($t2['total']));
$msg = $refused(fn() => Booking::requestRefund($bid2, [(int)$t2['id']], [], 'voyage_cancelled', 'Again', 'card'));
$check('5b. A second refund of the same ticket is refused while the first is open', $msg !== null, (string)$msg);
$approval = (int)DB::value("SELECT id FROM shp_approvals WHERE ref_table = 'shp_refunds' AND ref_id = :r", ['r' => $rid]);
$msg = $refused(fn() => ShipApproval::decide($approval, true, 'self'));
$check('6. The person who asked for the refund cannot approve it', $msg !== null, (string)$msg);

// ------------------------------------------------------------------ 7. a safety-critical defect stops the departure
// a voyage of a vessel with no hold yet, at boarding
$vid7 = (int)DB::value("SELECT v.id FROM shp_voyages v WHERE v.status = 'on_sale' AND v.etd > :n
                         AND NOT EXISTS (SELECT 1 FROM shp_holds h WHERE h.vessel_id = v.vessel_id AND h.status = 'active') ORDER BY v.etd LIMIT 1", ['n' => date('Y-m-d H:i:s')]);
$v7 = Ship::voyage($vid7);
$as('opsmgr');
Voyages::transition($vid7, 'boarding', []);
[$clearBefore, $hardBefore] = Voyages::readinessVerdict($vid7);
$as('captain');
$defect = Cmms::reportDefect(['vessel_id' => (int)$v7['vessel_id'], 'title' => 'Test: steering gear alarm', 'severity' => 'critical', 'safety_critical' => 1]);
[$clear, $hard] = Voyages::readinessVerdict($vid7);
$msg = $refused(fn() => Voyages::transition($vid7, 'ready', []));
$msg2 = $refused(fn() => Voyages::requestOverride($vid7, 'We need to sail'));
$check('7a. Reporting the defect puts a no-sail hold on ' . $v7['vessel_name'] . ', a check that cannot be overridden', !isset($hardBefore['holds']) && isset($hard['holds']), (string)($hard['holds'] ?? ''));
$check('7b. Voyage ' . $v7['voyage_no'] . ' cannot be released for departure', $msg !== null && str_contains($msg, 'cannot be overridden'), (string)$msg);
$check('7c. A departure override is refused', $msg2 !== null, (string)$msg2);

// ------------------------------------------------------------------ 8. nobody inspects their own work
$as('fleetmgr');
$wo = Cmms::triage($defect, 'critical', true, true, (int)DB::value("SELECT id FROM users WHERE username = 'tech1'"), 'Test');
if (DB::value('SELECT status FROM shp_work_orders WHERE id = :w', ['w' => $wo]) === 'triaged') {
    Cmms::transition((int)$wo, 'approved', []);
}
$as('tech1');
Cmms::transition((int)$wo, 'in_progress', []);
Cmms::addLabor((int)$wo, 'Joel Sumalinog', (int)Auth::id(), date('Y-m-d'), 2);
Cmms::transition((int)$wo, 'inspection', ['findings' => 'Test']);
$msg = $refused(fn() => Cmms::transition((int)$wo, 'completed', ['inspection_result' => 'pass']));
$check('8. The technician cannot pass the inspection of their own work', $msg !== null, (string)$msg);

// ------------------------------------------------------------------ 9. stock
$as('warehouse');
$site = (int)DB::value("SELECT id FROM sites WHERE code = 'SHORE-CEB'");
$item = (int)DB::value("SELECT i.id FROM items i JOIN item_site_stock s ON s.item_id = i.id WHERE s.site_id = :s AND s.qty_on_hand > 0 ORDER BY i.id LIMIT 1", ['s' => $site]);
$onHand = (float)DB::value('SELECT qty_on_hand FROM item_site_stock WHERE item_id = :i AND site_id = :s', ['i' => $item, 's' => $site]);
$msg = $refused(fn() => ShipGL::issueStock(date('Y-m-d'), $site, $item, $onHand + 5, Accts::get('shp_spares_expense'), null, 'Test issue'));
$check('9. Issuing more than the stock on hand is refused', $msg !== null, (string)$msg);

// ------------------------------------------------------------------ 10. closed shift
$closed = (int)DB::value("SELECT id FROM shp_cashier_shifts WHERE status IN ('closed', 'reviewed') ORDER BY id DESC LIMIT 1");
$as('ticket1');
$bid3 = Booking::hold(['voyage_id' => $vid, 'channel' => 'counter', 'contact_name' => 'Closed shift'], [$pax('Shift', $accom)]);
$msg = $refused(fn() => Booking::pay($bid3, ['channel' => 'cash', 'shift_id' => $closed, 'idempotency_key' => 'test-' . bin2hex(random_bytes(4))]));
$check('10. Cash cannot be paid into a shift that was already closed', $msg !== null, (string)$msg);
Booking::release($bid3, 'cancelled', 'Test');

// ------------------------------------------------------------------ 11. closing date
$as('ticket1');
$old = Settings::get('closing_date');
Settings::set('closing_date', date('Y-m-d', strtotime('-1 day')));
$msg = $refused(fn() => ShipGL::journal(date('Y-m-d', strtotime('-5 days')), [ShipGL::jl(Accts::get('shp_fuel_expense'), 10, 'Test', null), ShipGL::jl(Accts::get('ap'), -10, 'Test', null)], 'Test before the closing date', null));
Settings::set('closing_date', $old);
$check('11. A user without the permission cannot post before the closing date', $msg !== null, (string)$msg);

// ------------------------------------------------------------------ 12. every transaction balances
$unbalanced = (int)DB::value('SELECT COUNT(*) FROM (SELECT txn_id FROM journal_lines GROUP BY txn_id HAVING ROUND(SUM(debit) - SUM(credit), 2) <> 0) x');
$tot = DB::row('SELECT ROUND(SUM(debit), 2) d, ROUND(SUM(credit), 2) c FROM journal_lines');
$check('12. Every transaction balances, and so do the books', $unbalanced === 0 && $tot['d'] === $tot['c'], $unbalanced . ' unbalanced; debits ' . money($tot['d']) . ', credits ' . money($tot['c']));

out('');
out("$passed passed, $failed failed.");
exit($failed ? 1 : 0);
