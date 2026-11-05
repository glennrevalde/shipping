<?php
declare(strict_types=1);

/**
 * E-tickets and vehicle passes, ready to print or show on a phone (open route shp.ticket).
 *   ?booking=ID   every ticket and pass of a booking that can still travel (and unpaid ones, marked as not valid)
 *   ?ticket=ID    one passenger ticket, whatever its status (a void one is marked)
 *   ?vehicle=ID   one vehicle pass
 * The QR code carries the ticket number and its signature (GST1 / GSV1), checked by the Boarding Scanner.
 */

if (!Access::page('tkt.bookings') && !Access::page('tkt.booking')) {
    Access::denyPage('tkt.bookings');
}

if (!function_exists('shp_tp_fact')) {
    function shp_tp_fact(string $label, string $value): string
    {
        return '<div><dt>' . h($label) . '</dt><dd>' . ($value !== '' ? h($value) : '-') . '</dd></div>';
    }

}

$bookingId = gint('booking');
$ticketId = gint('ticket');
$vehicleId = gint('vehicle');

$tickets = [];
$vehicles = [];
if ($ticketId) {
    $tickets = DB::all('SELECT t.*, c.name AS cat_name, c.vat_exempt, s.seat_no FROM shp_tickets t JOIN shp_fare_categories c ON c.id = t.category_id LEFT JOIN shp_seats s ON s.id = t.seat_id WHERE t.id = :id', ['id' => $ticketId]);
    $bookingId = $tickets ? (int)$tickets[0]['booking_id'] : null;
} elseif ($vehicleId) {
    $vehicles = DB::all('SELECT x.*, k.name AS class_name FROM shp_vehicles x JOIN shp_vehicle_classes k ON k.id = x.vehicle_class_id WHERE x.id = :id', ['id' => $vehicleId]);
    $bookingId = $vehicles ? (int)$vehicles[0]['booking_id'] : null;
} elseif ($bookingId) {
    $tickets = DB::all("SELECT t.*, c.name AS cat_name, c.vat_exempt, s.seat_no FROM shp_tickets t JOIN shp_fare_categories c ON c.id = t.category_id LEFT JOIN shp_seats s ON s.id = t.seat_id
                         WHERE t.booking_id = :b AND t.status IN ('held', 'issued', 'checked_in', 'boarded', 'no_show') ORDER BY t.voyage_id, t.pax_seq", ['b' => $bookingId]);
    $vehicles = DB::all("SELECT x.*, k.name AS class_name FROM shp_vehicles x JOIN shp_vehicle_classes k ON k.id = x.vehicle_class_id
                          WHERE x.booking_id = :b AND x.status IN ('held', 'issued', 'checked_in', 'loaded', 'unloaded', 'no_show') ORDER BY x.id", ['b' => $bookingId]);
}
$b = $bookingId ? Booking::find($bookingId) : null;

layout_start($b ? 'E-tickets ' . $b['booking_ref'] : 'E-tickets', ['no_title' => true, 'module' => 'tkt', 'js' => ['vendor/qr/qrcode.js']]);
if (!$b || (!$tickets && !$vehicles)) {
    echo '<div class="page-bar"><h1>E-tickets</h1></div>' . empty_note('No ticket to print for this booking.', 'ticket-perforated');
    layout_end();
    return;
}

$company = Settings::get('company_name');
$address = Settings::get('company_address');
$terms = Ship::setting('ticket_terms');
$receipt = DB::row("SELECT t.txn_type, t.txn_no FROM shp_postings p JOIN transactions t ON t.id = p.txn_id WHERE p.source_type = 'booking' AND p.source_id = :b ORDER BY p.id LIMIT 1", ['b' => $bookingId]);
$isInvoice = Ship::setting('ticket_is_invoice', '0') === '1';
$voyages = [];
$voy = function (int $id) use (&$voyages) {
    return $voyages[$id] ??= Ship::voyage($id);
};
$valid = ['issued', 'checked_in', 'boarded'];
$back = Access::page('tkt.bookings') ? url('tkt.bookings', ['id' => $bookingId]) : url('tkt.booking', ['booking' => $bookingId]);
$count = count($tickets) + count($vehicles);
?>
<div class="page-bar no-print">
  <h1>E-tickets of booking <?= h($b['booking_ref']) ?></h1>
  <div class="page-actions"><a class="btn btn-sm btn-outline-secondary" href="<?= h($back) ?>"><i class="bi bi-arrow-left"></i> Back</a>
    <button class="btn btn-sm btn-primary" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print <?= $count === 1 ? '' : 'all ' . $count ?></button></div>
</div>
<div class="eticket-sheet">
<?php foreach ($tickets as $t):
    $v = $voy((int)$t['voyage_id']);
    $ok = in_array($t['status'], $valid, true) && $t['ticket_no'];
    $void = !in_array($t['status'], array_merge($valid, ['held']), true);
    $fees = DB::all('SELECT f.amount, s.name FROM shp_ticket_fees f JOIN shp_fees s ON s.id = f.fee_id WHERE f.ticket_id = :t', ['t' => $t['id']]);
    $boardAt = date('g:i A', strtotime($v['etd']) - 60 * (int)$v['boarding_open_min']);
    $farePaidNet = (float)$t['fare_net'];
    ?>
  <article class="eticket reveal <?= $void ? 'is-void' : '' ?>">
    <div class="main">
      <div class="head">
        <div class="brand-line"><span class="brand-mark"><?= ship_brand_svg() ?></span><div><?= h($company) ?><div class="small text-muted fw-normal"><?= h($address) ?></div></div></div>
        <div class="text-end"><div class="small text-muted">Passenger e-ticket</div><div class="tno"><?= h($t['ticket_no'] ?: 'Not yet issued') ?></div></div>
      </div>
      <div class="ports">
        <div><b><?= h($v['origin_code']) ?></b><span><?= h($v['origin_name']) ?></span></div>
        <div class="sea-line"></div>
        <div class="text-end"><b><?= h($v['dest_code']) ?></b><span><?= h($v['dest_name']) ?></span></div>
      </div>
      <dl class="facts">
        <?= shp_tp_fact('Passenger', trim($t['last_name'] . ', ' . $t['first_name'] . ' ' . (string)$t['middle_name'])) ?>
        <?= shp_tp_fact('Departure', date('D, M j, Y g:i A', strtotime($v['etd']))) ?>
        <?= shp_tp_fact('Arrival (estimated)', date('M j g:i A', strtotime($v['eta']))) ?>
        <?= shp_tp_fact('Voyage', $v['voyage_no']) ?>
        <?= shp_tp_fact('Vessel', $v['vessel_name']) ?>
        <?= shp_tp_fact('Accommodation', $t['accom_code'] . ($t['seat_no'] ? ', seat ' . $t['seat_no'] : '')) ?>
        <?= shp_tp_fact('Category', $t['cat_name'] . ($t['id_last4'] ? ', ID ending ' . $t['id_last4'] : '')) ?>
        <?= shp_tp_fact('Booking', $b['booking_ref']) ?>
        <?= shp_tp_fact('Issued', $t['issued_at'] ? fdatetime($t['issued_at']) : '') ?>
      </dl>
      <table class="money">
        <tr><td>Published fare</td><td><?= money($t['fare_gross']) ?></td></tr>
        <?php if ((float)$t['discount_amount'] + (float)$t['promo_amount'] > 0): ?><tr><td><?= (int)$t['vat_exempt'] === 1 ? 'Statutory discount (fare net of VAT, VAT-exempt)' : 'Discount' ?></td><td>(<?= money((float)$t['discount_amount'] + (float)$t['promo_amount']) ?>)</td></tr><?php endif; ?>
        <tr><td>Fare<?= (int)$t['vat_exempt'] === 1 ? ' (VAT-exempt)' : ' net of VAT' ?></td><td><?= money($farePaidNet) ?></td></tr>
        <?php if ((float)$t['surcharge_net'] > 0): ?><tr><td>Fuel surcharge</td><td><?= money($t['surcharge_net']) ?></td></tr><?php endif; ?>
        <?php if ((float)$t['vat_amount'] > 0): ?><tr><td>VAT 12%</td><td><?= money($t['vat_amount']) ?></td></tr><?php endif; ?>
        <?php foreach ($fees as $f): ?><tr><td><?= h($f['name']) ?></td><td><?= money($f['amount']) ?></td></tr><?php endforeach; ?>
        <tr class="tot"><td>Total paid</td><td>₱<?= money($t['total']) ?></td></tr>
      </table>
      <div class="terms"><?= h($terms) ?><?php if ($receipt && !$isInvoice): ?><?= "\n" ?>This e-ticket is not an official receipt. Payment recorded on <?= h(Txn::label((string)$receipt['txn_type']) . ' ' . $receipt['txn_no']) ?>.<?php endif; ?></div>
    </div>
    <div class="stub">
      <?php if ($ok): ?>
        <div class="qr" data-qr="<?= h(Ship::qrPayload('pax', (string)$t['ticket_no'], (string)$t['qr_sig'])) ?>"></div>
        <div class="tno"><?= h($t['ticket_no']) ?></div>
        <div class="small">Boarding opens <b><?= h($boardAt) ?></b></div>
        <div><?= Ship::label($t['status']) ?></div>
        <div class="sig">Show this code and your ID at the gate</div>
      <?php elseif ($t['status'] === 'held'): ?>
        <div class="void-mark">NOT VALID</div><div class="small">Waiting for payment. The ticket number and boarding code are given once paid.</div>
      <?php else: ?>
        <div class="void-mark">VOID</div><div><?= Ship::label($t['status']) ?></div><div class="small">This ticket cannot board.</div>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>

<?php foreach ($vehicles as $x):
    $v = $voy((int)$x['voyage_id']);
    $ok = in_array($x['status'], ['issued', 'checked_in', 'loaded'], true) && $x['vehicle_no'];
    $driver = $x['driver_ticket_id'] ? DB::row('SELECT last_name, first_name, ticket_no FROM shp_tickets WHERE id = :t', ['t' => $x['driver_ticket_id']]) : null;
    $fees = DB::all('SELECT f.amount, s.name FROM shp_ticket_fees f JOIN shp_fees s ON s.id = f.fee_id WHERE f.vehicle_id = :x', ['x' => $x['id']]);
    ?>
  <article class="eticket vehicle-pass reveal <?= !$ok && $x['status'] !== 'held' && $x['status'] !== 'unloaded' ? 'is-void' : '' ?>">
    <div class="main">
      <div class="head">
        <div class="brand-line"><span class="brand-mark"><?= ship_brand_svg() ?></span><div><?= h($company) ?><div class="small text-muted fw-normal"><?= h($address) ?></div></div></div>
        <div class="text-end"><div class="small text-muted">Vehicle pass</div><div class="tno"><?= h($x['vehicle_no'] ?: 'Not yet issued') ?></div></div>
      </div>
      <div class="ports">
        <div><b><?= h($v['origin_code']) ?></b><span><?= h($v['origin_name']) ?></span></div>
        <div class="sea-line"></div>
        <div class="text-end"><b><?= h($v['dest_code']) ?></b><span><?= h($v['dest_name']) ?></span></div>
      </div>
      <dl class="facts">
        <?= shp_tp_fact('Plate no.', $x['plate_no']) ?>
        <?= shp_tp_fact('Vehicle class', $x['class_name']) ?>
        <?= shp_tp_fact('Make / model', (string)$x['make_model']) ?>
        <?= shp_tp_fact('Length / weight', qty($x['length_m']) . ' m, ' . qty($x['weight_t']) . ' t') ?>
        <?= shp_tp_fact('Departure', date('D, M j, Y g:i A', strtotime($v['etd']))) ?>
        <?= shp_tp_fact('Voyage', $v['voyage_no'] . ', ' . $v['vessel_name']) ?>
        <?= shp_tp_fact('Driver', $driver ? $driver['last_name'] . ', ' . $driver['first_name'] . ' (' . ($driver['ticket_no'] ?: 'ticket unpaid') . ')' : 'Holds a separate ticket') ?>
        <?= shp_tp_fact('Lane', $x['lane_no'] ? trim(($x['deck'] ?? '') . ' lane ' . $x['lane_no'] . '/' . $x['lane_position']) : 'Assigned at loading') ?>
        <?= shp_tp_fact('Declared cargo', (string)$x['declared_cargo'] . ((int)$x['hazardous'] ? ' (hazardous: ' . $x['hazardous_desc'] . ')' : '')) ?>
      </dl>
      <table class="money">
        <tr><td>Vehicle freight net of VAT</td><td><?= money($x['fare_net']) ?></td></tr>
        <tr><td>VAT 12%</td><td><?= money($x['vat_amount']) ?></td></tr>
        <?php foreach ($fees as $f): ?><tr><td><?= h($f['name']) ?></td><td><?= money($f['amount']) ?></td></tr><?php endforeach; ?>
        <tr class="tot"><td>Total paid</td><td>₱<?= money($x['total']) ?></td></tr>
      </table>
      <div class="terms">Present this pass at the vehicle check-in point at least one hour before departure. The driver and every passenger in the vehicle need their own ticket. Undeclared hazardous goods are refused.</div>
    </div>
    <div class="stub">
      <?php if ($ok): ?>
        <div class="qr" data-qr="<?= h(Ship::qrPayload('vehicle', (string)$x['vehicle_no'], (string)$x['qr_sig'])) ?>"></div>
        <div class="tno"><?= h($x['vehicle_no']) ?></div>
        <div><?= Ship::label($x['status']) ?></div>
        <div class="sig">Scanned at the ramp when loading</div>
      <?php elseif ($x['status'] === 'held'): ?>
        <div class="void-mark">NOT VALID</div><div class="small">Waiting for payment.</div>
      <?php else: ?>
        <div class="void-mark"><?= $x['status'] === 'unloaded' ? 'USED' : 'VOID' ?></div><div><?= Ship::label($x['status']) ?></div>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php
layout_end();
