<?php
declare(strict_types=1);

/**
 * Bookings & Tickets: find any booking (reference, ticket or pass number, passenger, phone, voyage) and work on
 * it: payment of a held booking (on the Booking Desk), reprint, refund request (approved by someone else in the
 * Approvals Queue) and moving a ticket to another voyage of the same route. Names and phone numbers are masked
 * for roles without the passenger-data permission.
 */

$page = 'tkt.bookings';
$id = gint('id');

if (!function_exists('shp_bks_status')) {
    function shp_bks_status(string $s): string
    {
        $map = ['held' => ['warn', 'Held, unpaid'], 'confirmed' => ['good', 'Confirmed'], 'completed' => ['good', 'Completed'], 'cancelled' => ['bad', 'Cancelled'], 'expired' => ['bad', 'Hold expired']];
        [$tone, $text] = $map[$s] ?? ['muted', ucfirst($s)];
        return '<span class="st st-' . $tone . '">' . h($text) . '</span>';
    }

    /** A contact's full name, masked word by word for roles without the passenger-data permission */
    function shp_bks_name(?string $name): string
    {
        $name = trim((string)$name);
        if ($name === '' || Ship::canPii()) {
            return $name;
        }
        return implode(' ', array_map(fn($w) => mb_substr($w, 0, 1) . str_repeat('*', max(2, mb_strlen($w) - 1)), preg_split('/\s+/', $name)));
    }

    function shp_bks_channel(string $c): string
    {
        return ['counter' => 'Counter', 'portal' => 'Online', 'agent' => 'Travel agent', 'corporate' => 'Corporate'][$c] ?? ucfirst($c);
    }

}

$canRefund = Access::can($page, 'edit') || Access::can('tkt.refunds', 'create');
$canEdit = Access::can($page, 'edit');

handle_post(function () use ($page, $canRefund, $canEdit) {
    $b = Booking::find((int)pint('booking_id'));
    if (!$b) {
        fail('Booking not found.');
    }
    $back = url($page, ['id' => $b['id']]);
    switch ((string)p('act')) {
        case 'refund':
            if (!$canRefund) {
                Access::denyPage($page, 'edit');
            }
            $type = in_array(p('refund_type'), ['passenger_cancel', 'voyage_cancelled', 'overcharge'], true) ? (string)p('refund_type') : 'passenger_cancel';
            $method = in_array(p('method'), ['cash', 'card', 'ewallet', 'maya', 'bank_transfer'], true) ? (string)p('method') : 'cash';
            $rid = Booking::requestRefund((int)$b['id'], array_map('intval', (array)($_POST['tickets'] ?? [])), array_map('intval', (array)($_POST['vehicles'] ?? [])),
                $type, (string)p('reason'), $method);
            $no = (string)DB::value('SELECT refund_no FROM shp_refunds WHERE id = :id', ['id' => $rid]);
            flash('success', 'Refund ' . $no . ' requested. It is posted only after another person approves it in the Approvals Queue.');
            break;
        case 'transfer':
            if (!$canEdit) {
                Access::denyPage($page, 'edit');
            }
            $tid = (int)pint('ticket_id');
            if (!DB::value('SELECT 1 FROM shp_tickets WHERE id = :t AND booking_id = :b', ['t' => $tid, 'b' => $b['id']])) {
                fail('Choose a ticket of this booking.');
            }
            $free = p('free') === '1';
            if ($free && !Access::special('shp_fare_override')) {
                fail('Only a role with the fare-override permission can move a ticket free of charge.');
            }
            $newId = Booking::transfer($tid, (int)pint('new_voyage_id'), ['accom_code' => p('accom_code') ?: null, 'channel' => p('channel', 'cash'),
                'provider_ref' => p('provider_ref'), 'free' => $free, 'reason' => p('reason')]);
            $no = (string)DB::value('SELECT ticket_no FROM shp_tickets WHERE id = :id', ['id' => $newId]);
            flash('success', 'Ticket moved. The new ticket is ' . $no . '; the old one can no longer board.');
            break;
        case 'release':
            if (!$canEdit && !Access::can('tkt.booking', 'create')) {
                Access::denyPage($page, 'edit');
            }
            if ($b['status'] !== 'held') {
                fail('Only a booking that is still held can be released.');
            }
            Booking::release((int)$b['id'], 'cancelled', 'Hold released by ' . (Auth::user()['full_name'] ?? '') . '.');
            Ship::audit('release', 'tkt', 'shp_booking', (int)$b['id'], 'Hold of ' . $b['booking_ref'] . ' released', ['page_key' => $page]);
            flash('info', 'The places held by ' . $b['booking_ref'] . ' are back on sale.');
            break;
        default:
            fail('Unknown action.');
    }
    redirect($back);
});

// ================================================================== one booking
if ($id) {
    $b = Booking::find($id);
    if (!$b) {
        layout_start('Booking not found');
        echo empty_note('That booking does not exist.');
        layout_end();
        return;
    }
    $tickets = Booking::tickets($id);
    $vehicles = Booking::vehicles($id);
    $vid = (int)($tickets[0]['voyage_id'] ?? ($vehicles[0]['voyage_id'] ?? 0));
    if ($b['status'] === 'held' && $vid) {
        Booking::expireHolds($vid);
        $b = Booking::find($id);
        $tickets = Booking::tickets($id);
        $vehicles = Booking::vehicles($id);
    }
    $v = $vid ? Ship::voyage($vid) : null;
    $payments = DB::all('SELECT p.*, s.shift_no, u.full_name FROM shp_payments p LEFT JOIN shp_cashier_shifts s ON s.id = p.shift_id LEFT JOIN users u ON u.id = p.received_by WHERE p.booking_id = :b ORDER BY p.id', ['b' => $id]);
    $refunds = DB::all('SELECT r.*, t.ticket_no, x.vehicle_no FROM shp_refunds r LEFT JOIN shp_tickets t ON t.id = r.ticket_id LEFT JOIN shp_vehicles x ON x.id = r.vehicle_id WHERE r.booking_id = :b ORDER BY r.id', ['b' => $id]);
    $tIds = array_map(fn($t) => (int)$t['id'], $tickets) ?: [0];
    $rIds = array_map(fn($r) => (int)$r['id'], $refunds) ?: [0];
    $docs = DB::all("SELECT p.*, t.txn_type, t.txn_no, t.txn_date, t.total, t.is_voided FROM shp_postings p LEFT JOIN transactions t ON t.id = p.txn_id
                      WHERE (p.source_type = 'booking' AND p.source_id = :b) OR (p.source_type = 'refund' AND p.source_id IN (" . implode(',', $rIds) . "))
                         OR (p.source_type = 'ticket' AND p.source_id IN (" . implode(',', $tIds) . ')) ORDER BY p.id', ['b' => $id]);
    $refundable = array_filter($tickets, fn($t) => in_array($t['status'], ['issued', 'checked_in', 'no_show'], true));
    $refundableVeh = array_filter($vehicles, fn($x) => in_array($x['status'], ['issued', 'checked_in', 'no_show'], true));
    $movable = array_filter($tickets, fn($t) => in_array($t['status'], ['issued', 'checked_in', 'no_show'], true));
    $inProgress = DB::column("SELECT ticket_id FROM shp_refunds WHERE booking_id = :b AND status IN ('requested', 'approved') AND ticket_id IS NOT NULL", ['b' => $id]);
    $paid = in_array($b['status'], ['confirmed', 'completed'], true);

    $actions = '';
    if ($paid || $b['status'] === 'held') {
        $actions .= btn_link(url('shp.ticket', ['booking' => $id]), $paid ? 'Print e-tickets' : 'Print reservation', 'btn-outline-primary', 'printer') . ' ';
    }
    if ($b['status'] === 'held' && Access::can('tkt.booking', 'create')) {
        $actions .= btn_link(url('tkt.booking', ['booking' => $id]), 'Take payment', 'btn-primary', 'cash-coin');
    }
    layout_start('Booking ' . $b['booking_ref'], ['actions' => $actions]);
    $leftSec = $b['status'] === 'held' && $b['hold_expires_at'] ? max(0, strtotime($b['hold_expires_at']) - strtotime(Ship::now())) : 0;
    ?>
    <div class="row g-3">
      <div class="col-xl-8">
        <?php if ($v): ?><?= voyage_card($v, Access::page('ops.voyages') ? url('ops.voyages', ['id' => $v['id']]) : null, '<div class="small text-muted">' . h($v['route_name']) . '</div>') ?><?php endif; ?>
        <?php if ($b['status'] === 'held'): ?>
          <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 mb-0">
            <div><i class="bi bi-hourglass-split"></i> Held, not paid. The hold ends <b><?= h(fdatetime($b['hold_expires_at'])) ?></b> (<?= $leftSec >= 60 ? floor($leftSec / 60) . ' min left' : 'less than a minute left' ?>); the places go back on sale after that.</div>
            <?php if (Access::can('tkt.booking', 'create')): ?><a class="btn btn-sm btn-primary" href="<?= h(url('tkt.booking', ['booking' => $id])) ?>"><i class="bi bi-cash-coin"></i> Take payment ₱<?= money($b['total_amount']) ?></a><?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="card reveal mt-3"><div class="card-header">Passengers</div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0 bk-table">
            <thead><tr><th>Ticket</th><th>Passenger</th><th>Category</th><th>Place</th><th>Voyage</th><th class="num">Total</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($tickets as $t): ?>
              <tr><td class="fw-semibold"><?= h($t['ticket_no'] ?: '-') ?><?= $t['transferred_from_id'] ? '<div class="sub">moved from another voyage</div>' : '' ?></td>
                <td><?= h(Ship::maskName($t['last_name'], $t['first_name'])) ?><div class="sub"><?= $t['birth_date'] && Ship::canPii() ? 'Born ' . h(fdate($t['birth_date'])) : '' ?><?= $t['gender'] ? ' ' . h($t['gender']) : '' ?><?= $t['special_assist'] ? ', ' . h($t['special_assist']) : '' ?></div></td>
                <td><?= h($t['cat_name']) ?><?= $t['id_last4'] ? '<div class="sub">ID ending ' . h($t['id_last4']) . '</div>' : '' ?></td>
                <td><?= h($t['accom_code']) ?> <?= h((string)$t['seat_no']) ?></td>
                <td><?= h($t['voyage_no']) ?><div class="sub"><?= h(date('M j g:i A', strtotime($t['etd']))) ?></div></td>
                <td class="num"><?= money($t['total']) ?></td><td><?= Ship::label($t['status']) ?></td>
                <td class="text-end"><?php if ($t['ticket_no'] && !in_array($t['status'], ['transferred', 'expired', 'cancelled'], true)): ?><a class="btn btn-sm btn-link px-1" title="Print this ticket" href="<?= h(url('shp.ticket', ['ticket' => $t['id']])) ?>"><i class="bi bi-printer"></i></a><?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$tickets): ?><tr><td colspan="8"><?= empty_note('No passenger on this booking.') ?></td></tr><?php endif; ?>
            </tbody></table></div></div>

        <?php if ($vehicles): ?>
        <div class="card reveal mt-3"><div class="card-header">Vehicles</div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0 bk-table">
            <thead><tr><th>Pass</th><th>Plate</th><th>Class</th><th class="num">Size</th><th>Lane</th><th class="num">Total</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($vehicles as $x): ?>
              <tr><td class="fw-semibold"><?= h($x['vehicle_no'] ?: '-') ?></td><td><?= h($x['plate_no']) ?><div class="sub"><?= h((string)$x['make_model']) ?></div></td><td><?= h($x['class_name']) ?></td>
                <td class="num"><?= qty($x['length_m']) ?> m, <?= qty($x['weight_t']) ?> t</td><td><?= $x['lane_no'] ? h(trim($x['deck'] . ' L' . $x['lane_no'] . '/' . $x['lane_position'])) : '' ?></td>
                <td class="num"><?= money($x['total']) ?></td><td><?= Ship::label($x['status']) ?></td>
                <td class="text-end"><?php if ($x['vehicle_no']): ?><a class="btn btn-sm btn-link px-1" title="Print this pass" href="<?= h(url('shp.ticket', ['vehicle' => $x['id']])) ?>"><i class="bi bi-printer"></i></a><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div></div>
        <?php endif; ?>

        <div class="card reveal mt-3"><div class="card-header">Payments</div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Payment</th><th>Received</th><th>How</th><th>Reference</th><th>Shift</th><th>Document</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($payments as $pm): ?>
              <tr><td class="text-nowrap"><?= h($pm['payment_no']) ?></td><td><?= h(fdatetime($pm['received_at'])) ?><div class="small text-muted"><?= h((string)$pm['full_name']) ?></div></td>
                <td><?= h(['cash' => 'Cash', 'check' => 'Check', 'card' => 'Card', 'ewallet' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer', 'gateway' => 'Online gateway', 'corporate' => 'Charged to account', 'agent' => 'Charged to agent'][$pm['channel']] ?? $pm['channel']) ?></td>
                <td><?= h((string)$pm['provider_ref']) ?></td><td class="text-nowrap"><?= h((string)$pm['shift_no']) ?></td><td class="text-nowrap"><?= txn_doc_link($pm['txn_id'] ? (int)$pm['txn_id'] : null) ?></td>
                <td class="num"><?= money($pm['amount']) ?></td><td><?= Ship::label($pm['status']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?><tr><td colspan="8"><?= empty_note('No payment yet.', 'cash') ?></td></tr><?php endif; ?>
            </tbody></table></div></div>

        <?php if ($refunds): ?>
        <div class="card reveal mt-3"><div class="card-header">Refunds</div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Refund</th><th>Of</th><th>Reason</th><th class="num">Kept</th><th class="num">Refund</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($refunds as $r): ?>
              <tr><td><?= Access::page('tkt.refunds') ? '<a href="' . h(url('tkt.refunds', ['id' => $r['id']])) . '">' . h($r['refund_no']) . '</a>' : h($r['refund_no']) ?><div class="small text-muted"><?= h(fdatetime($r['requested_at'])) ?></div></td>
                <td><?= h($r['ticket_no'] ?: $r['vehicle_no']) ?></td><td><?= h(['passenger_cancel' => 'Passenger cancelled', 'voyage_cancelled' => 'Voyage cancelled', 'overcharge' => 'Overcharge'][$r['refund_type']] ?? $r['refund_type']) ?><div class="small text-muted"><?= h($r['reason']) ?></div></td>
                <td class="num"><?= money($r['fee_retained'], true) ?></td><td class="num"><?= money($r['refund_amount']) ?></td><td><?= Ship::label($r['status']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div></div>
        <?php endif; ?>

        <div class="card reveal mt-3"><div class="card-header">Accounting documents</div><?= ship_documents_table($docs) ?></div>
      </div>

      <div class="col-xl-4">
        <div class="card reveal mb-3 bk-side"><div class="card-body">
          <div class="bk-head mb-2"><span class="bk-ref"><?= h($b['booking_ref']) ?></span><?= shp_bks_status($b['status']) ?></div>
          <div class="info-grid">
            <?= info_pair('Booked by', shp_bks_name($b['contact_name'])) ?>
            <?= info_pair('Mobile', Ship::maskPhone($b['contact_phone']) ?: '-') ?>
            <?= info_pair('Email', $b['contact_email'] ? (Ship::canPii() ? $b['contact_email'] : preg_replace('/^(.).*(@.*)$/', '$1***$2', (string)$b['contact_email'])) : '-') ?>
            <?= info_pair('Sold through', shp_bks_channel($b['channel'])) ?>
            <?= info_pair('Office', $b['office_name'] ?: '-') ?>
            <?= $b['agent_name'] ? info_pair('Agent', $b['agent_name']) : '' ?>
            <?= $b['channel'] === 'corporate' ? info_pair('Account', $b['customer_name']) : '' ?>
            <?= info_pair('Created', fdatetime($b['created_at'])) ?>
            <?= $b['confirmed_at'] ? info_pair('Paid', fdatetime($b['confirmed_at'])) : '' ?>
            <?= info_pair('Total', '₱' . money($b['total_amount'])) ?><?= info_pair('Paid', '₱' . money($b['paid_amount'])) ?>
            <?= (float)$b['refunded_amount'] > 0 ? info_pair('Refunded', '₱' . money($b['refunded_amount'])) : '' ?>
          </div>
          <?php if ($b['notes']): ?><p class="small text-muted mt-2 mb-0"><?= h((string)$b['notes']) ?></p><?php endif; ?>
          <?php if ($b['status'] === 'held' && ($canEdit || Access::can('tkt.booking', 'create'))): ?>
            <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="act" value="release"><input type="hidden" name="booking_id" value="<?= (int)$id ?>">
              <button class="btn btn-link btn-sm text-danger px-0" data-confirm="Release the places held by this booking?">Release the hold</button></form>
          <?php endif; ?>
        </div></div>

        <?php if ($paid && $canRefund && ($refundable || $refundableVeh)): ?>
        <form method="post" class="card reveal mb-3" id="refundForm"><?= csrf_field() ?><input type="hidden" name="act" value="refund"><input type="hidden" name="booking_id" value="<?= (int)$id ?>">
          <div class="card-header">Request a refund</div><div class="card-body">
          <?= select_field('refund_type', 'Why', ['passenger_cancel' => 'The passenger cancels', 'voyage_cancelled' => 'The voyage was cancelled (full refund)', 'overcharge' => 'Overcharged (full refund)'],
              p('refund_type', $v && $v['status'] === 'cancelled' ? 'voyage_cancelled' : 'passenger_cancel'), ['class' => 'mb-2', 'extra' => 'id="rfType"']) ?>
          <table class="table table-sm ticket-pick mb-2"><tbody>
          <?php foreach ($refundable as $t): $busy = in_array((int)$t['id'], array_map('intval', $inProgress), true);
              [$amt, $kept] = Booking::refundQuote($t, 'passenger_cancel'); [$full] = Booking::refundQuote($t, 'voyage_cancelled'); ?>
            <tr><td><input class="form-check-input" type="checkbox" name="tickets[]" value="<?= (int)$t['id'] ?>" <?= $busy ? 'disabled' : '' ?>></td>
              <td><?= h($t['ticket_no']) ?><div class="small text-muted"><?= h(Ship::maskName($t['last_name'], $t['first_name'])) ?><?= $busy ? ', refund in progress' : '' ?></div></td>
              <td class="num small"><span class="rf-amt" data-cancel="<?= h(money($amt)) ?>" data-full="<?= h(money($full)) ?>" data-kept="<?= h(money($kept)) ?>"><?= money($amt) ?></span>
                <div class="text-muted rf-kept"><?= $kept > 0 ? 'fee ' . money($kept) : '' ?></div></td></tr>
          <?php endforeach; ?>
          <?php foreach ($refundableVeh as $x):
              $vPaid = r2((float)$x['fare_net'] + (float)$x['vat_amount']);
              $vKept = r2($vPaid * (float)Ship::setting('cancel_fee_pct', '20') / 100); ?>
            <tr><td><input class="form-check-input" type="checkbox" name="vehicles[]" value="<?= (int)$x['id'] ?>"></td><td><?= h($x['vehicle_no']) ?><div class="small text-muted"><?= h($x['plate_no']) ?></div></td>
              <td class="num small"><span class="rf-amt" data-cancel="<?= h(money($vPaid - $vKept + (float)$x['fees_total'])) ?>" data-full="<?= h(money($x['total'])) ?>" data-kept="<?= h(money($vKept)) ?>"><?= money($vPaid - $vKept + (float)$x['fees_total']) ?></span>
                <div class="text-muted rf-kept"><?= $vKept > 0 ? 'fee ' . money($vKept) : '' ?></div></td></tr>
          <?php endforeach; ?>
          </tbody></table>
          <div class="row g-2">
            <?= select_field('method', 'Refund to', ['cash' => 'Cash at the counter', 'card' => 'Back to the card', 'ewallet' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer'], p('method', 'cash'), ['class' => 'col-12']) ?>
            <?= input_field('reason', 'Reason', p('reason'), ['class' => 'col-12', 'required' => true]) ?>
          </div>
          <div class="small text-muted mt-2">Cancelled <?= Ship::intSetting('cancel_late_hours', 24) ?> hours or more before departure: <?= h(Ship::setting('cancel_fee_pct', '20')) ?>% of the fare is kept; later: <?= h(Ship::setting('cancel_late_fee_pct', '50')) ?>%. Unused terminal fees and insurance are returned.</div>
          <button class="btn btn-outline-primary btn-sm mt-2 w-100">Request refund</button>
        </div></form>
        <?php endif; ?>

        <?php if ($paid && $canEdit && $movable && $v):
            $newVoyages = Ship::voyageOptions(['on_sale', 'boarding'], (int)$v['route_id']);
            unset($newVoyages[$v['id']]);
            $codes = DB::pairs("SELECT a.code, MIN(a.name) FROM shp_accom_classes a GROUP BY a.code ORDER BY MIN(a.sort_order)");
            ?>
        <form method="post" class="card reveal mb-3"><?= csrf_field() ?><input type="hidden" name="act" value="transfer"><input type="hidden" name="booking_id" value="<?= (int)$id ?>">
          <div class="card-header">Move a ticket to another voyage</div><div class="card-body"><div class="row g-2">
            <?= select_field('ticket_id', 'Ticket', array_combine(array_map(fn($t) => (int)$t['id'], $movable), array_map(fn($t) => $t['ticket_no'] . ' ' . Ship::maskName($t['last_name'], $t['first_name']), $movable)), p('ticket_id'), ['class' => 'col-12']) ?>
            <?= select_field('new_voyage_id', 'New voyage (same route)', $newVoyages, p('new_voyage_id'), ['class' => 'col-12', 'blank' => '', 'required' => true]) ?>
            <?= select_field('accom_code', 'Accommodation', $codes, p('accom_code'), ['class' => 'col-12', 'blank' => 'Same as now']) ?>
            <?= select_field('channel', 'Change fee paid by', ['cash' => 'Cash', 'card' => 'Card', 'ewallet' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer'], p('channel', 'cash'), ['class' => 'col-6']) ?>
            <?= input_field('provider_ref', 'Reference no.', p('provider_ref'), ['class' => 'col-6']) ?>
            <?= input_field('reason', 'Reason', p('reason'), ['class' => 'col-12']) ?>
            <?php if (Access::special('shp_fare_override')): ?><?= check_field('free', 'Free of charge (company decision)', p('free') === '1', 'col-12') ?><?php endif; ?>
          </div>
          <div class="small text-muted mt-2"><?= $v['status'] === 'cancelled' ? 'The voyage was cancelled, so the move is free.' : 'Change fee ₱' . money((float)Ship::setting('change_fee', '150')) . ' plus any higher fare; a lower fare is not refunded.' ?></div>
          <button class="btn btn-outline-primary btn-sm mt-2 w-100" <?= $newVoyages ? '' : 'disabled' ?>>Move ticket</button>
          <?php if (!$newVoyages): ?><div class="small text-muted mt-1">No other voyage of this route is on sale.</div><?php endif; ?>
        </div></form>
        <?php endif; ?>
      </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var t = document.getElementById('rfType');
      if (!t) return;
      function sync() {
        var full = t.value !== 'passenger_cancel';
        document.querySelectorAll('.rf-amt').forEach(function (s) {
          s.textContent = full ? s.getAttribute('data-full') : s.getAttribute('data-cancel');
          var k = s.parentNode.querySelector('.rf-kept'); var kept = s.getAttribute('data-kept');
          if (k) k.textContent = !full && kept !== '0.00' ? 'fee ' + kept : '';
        });
      }
      t.addEventListener('change', sync); sync();
    });
    </script>
    <?php
    layout_end();
    return;
}

// ================================================================== search
$q = trim((string)g('q'));
$voyage = gint('voyage');
$status = (string)g('status');
$channel = (string)g('channel');
$from = gdate('from');
$to = gdate('to');
if ($q === '' && !$voyage && !$from && !$to) {
    $from = date('Y-m-d', strtotime(Ship::today() . ' -6 days'));
    $to = Ship::today();
}
$where = ['1 = 1'];
$par = [];
if ($q !== '') {
    $where[] = '(b.booking_ref = :qe OR b.contact_name LIKE :q1 OR b.contact_phone LIKE :q2 OR b.contact_email LIKE :q3
                 OR b.id IN (SELECT booking_id FROM shp_tickets WHERE ticket_no = :qe2 OR last_name LIKE :q4 OR CONCAT(last_name, \', \', first_name) LIKE :q5 OR CONCAT(first_name, \' \', last_name) LIKE :q6)
                 OR b.id IN (SELECT booking_id FROM shp_vehicles WHERE vehicle_no = :qe3 OR plate_no LIKE :q7))';
    $like = '%' . $q . '%';
    $par += ['qe' => strtoupper($q), 'qe2' => strtoupper($q), 'qe3' => strtoupper($q), 'q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $q . '%', 'q5' => $q . '%', 'q6' => $q . '%', 'q7' => str_replace(' ', '', $like)];
}
if ($voyage) {
    $where[] = '(b.id IN (SELECT booking_id FROM shp_tickets WHERE voyage_id = :v) OR b.id IN (SELECT booking_id FROM shp_vehicles WHERE voyage_id = :v2))';
    $par += ['v' => $voyage, 'v2' => $voyage];
}
if (in_array($status, ['held', 'confirmed', 'completed', 'cancelled', 'expired'], true)) {
    $where[] = 'b.status = :st';
    $par['st'] = $status;
}
if (in_array($channel, ['counter', 'portal', 'agent', 'corporate'], true)) {
    $where[] = 'b.channel = :ch';
    $par['ch'] = $channel;
}
if ($from) {
    $where[] = 'b.created_at >= :f';
    $par['f'] = $from . ' 00:00:00';
}
if ($to) {
    $where[] = 'b.created_at <= :t';
    $par['t'] = $to . ' 23:59:59';
}
$rows = DB::all('SELECT b.*, tk.pax, tk.voyage_id, (SELECT COUNT(*) FROM shp_vehicles x WHERE x.booking_id = b.id) veh,
                        v.voyage_no, v.etd, po.code oc, pd.code dc
                   FROM shp_bookings b
                   LEFT JOIN (SELECT booking_id, MIN(voyage_id) voyage_id, SUM(status NOT IN (\'transferred\', \'expired\', \'cancelled\')) pax FROM shp_tickets GROUP BY booking_id) tk ON tk.booking_id = b.id
                   LEFT JOIN shp_voyages v ON v.id = tk.voyage_id LEFT JOIN shp_routes r ON r.id = v.route_id
                   LEFT JOIN shp_ports po ON po.id = r.origin_port_id LEFT JOIN shp_ports pd ON pd.id = r.dest_port_id
                  WHERE ' . implode(' AND ', $where) . ' ORDER BY b.id DESC LIMIT 100', $par);
$vTitle = $voyage ? Ship::voyage($voyage) : null;
layout_start('Bookings & Tickets', ['actions' => Access::can('tkt.booking', 'create') ? btn_link(url('tkt.booking'), 'New booking', 'btn-primary', 'plus-lg') : '']);
?>
<form class="card reveal mb-3" method="get"><input type="hidden" name="p" value="<?= h($page) ?>"><?php if ($voyage): ?><input type="hidden" name="voyage" value="<?= (int)$voyage ?>"><?php endif; ?>
  <div class="card-body"><div class="toolbar">
    <div class="flex-fill" style="min-width:220px"><label class="form-label">Find</label><input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Booking ref, ticket or pass no., name, mobile, plate" autofocus></div>
    <?= select_field('status', 'Status', ['held' => 'Held, unpaid', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled', 'expired' => 'Hold expired'], $status, ['class' => '', 'blank' => 'Any']) ?>
    <?= select_field('channel', 'Sold through', ['counter' => 'Counter', 'portal' => 'Online', 'agent' => 'Travel agent', 'corporate' => 'Corporate'], $channel, ['class' => '', 'blank' => 'Any']) ?>
    <?= input_field('from', 'Booked from', $from, ['type' => 'date', 'class' => '']) ?><?= input_field('to', 'to', $to, ['type' => 'date', 'class' => '']) ?>
    <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i> Find</button></div>
  </div>
  <?php if ($vTitle): ?><div class="small mt-2">Voyage <b><?= h(Ship::voyageTitle($vTitle)) ?></b> <a href="<?= h(url($page, ['q' => $q])) ?>">show all voyages</a></div><?php endif; ?>
</div></form>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 bk-table bk-list">
  <thead><tr><th>Booking</th><th>Booked by</th><th>Voyage</th><th class="num">Pax</th><th class="num">Vehicles</th><th>Through</th><th class="num">Total</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $link = url($page, ['id' => $r['id']]); ?>
    <tr onclick="location.href='<?= h($link) ?>'" style="cursor:pointer">
      <td class="c-ref"><a class="fw-semibold" href="<?= h($link) ?>"><?= h($r['booking_ref']) ?></a><div class="sub"><?= h(fdatetime($r['created_at'])) ?></div></td>
      <td class="c-who"><?= h(shp_bks_name($r['contact_name'])) ?><div class="sub"><?= h(Ship::maskPhone($r['contact_phone'])) ?></div></td>
      <td class="c-voy"><?= $r['voyage_no'] ? h($r['voyage_no']) . '<div class="sub">' . h($r['oc'] . ' to ' . $r['dc'] . ', ' . date('M j g:i A', strtotime($r['etd']))) . '</div>' : '-' ?></td>
      <td class="num c-hide"><?= (int)$r['pax'] ?></td><td class="num c-hide"><?= (int)$r['veh'] ?: '' ?></td><td class="c-hide"><?= h(shp_bks_channel($r['channel'])) ?></td>
      <td class="num c-total"><?= money($r['total_amount']) ?><div class="sub d-sm-none"><?= (int)$r['pax'] ?> pax<?= (int)$r['veh'] ? ', ' . (int)$r['veh'] . ' vehicle(s)' : '' ?></div></td>
      <td class="c-status"><?= shp_bks_status($r['status']) ?><?= $r['status'] === 'held' && $r['hold_expires_at'] ? '<div class="sub">until ' . h(date('M j g:i A', strtotime($r['hold_expires_at']))) . '</div>' : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8"><?= empty_note('No booking matches.', 'search') ?></td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if (count($rows) === 100): ?><div class="card-body small text-muted">The 100 most recent matches are shown; narrow the search to see others.</div><?php endif; ?>
</div>
<?php
layout_end();
