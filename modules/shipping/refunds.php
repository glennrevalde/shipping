<?php
declare(strict_types=1);

$page = 'tkt.refunds';
$tab = (string)g('tab', 'requested');
$me = (int)Auth::id();
$canAsk = Access::can($page, 'create');
$canPay = Access::can($page, 'create') || Access::can($page, 'edit');

if (!function_exists('refunds_type_name')) {
    function refunds_type_name(string $t): string
    {
        return ['passenger_cancel' => 'Passenger cancellation', 'voyage_cancelled' => 'Voyage cancelled', 'overcharge' => 'Overcharge / wrong fare'][$t] ?? ucfirst(str_replace('_', ' ', $t));
    }

    function refunds_methods(): array
    {
        return ['cash' => 'Cash at the counter', 'card' => 'Back to the card', 'ewallet' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer'];
    }
}

handle_post(function () use ($page, $canAsk, $canPay, $me) {
    $act = (string)p('act');
    if ($act === 'pay') {
        if (!$canPay) {
            Access::denyPage($page, 'pay out refunds on');
        }
        $rid = (int)pint('refund_id');
        $method = (string)p('method');
        if (!isset(refunds_methods()[$method])) {
            fail('Choose how the refund is paid out.');
        }
        $shift = $method === 'cash' ? Cashier::requireOpen($me) : null;
        Booking::payRefund($rid, $method, $shift);
        flash('success', 'Refund paid out and posted. Give the passenger the refund slip to sign.');
        redirect(url($page, ['tab' => 'paid']));
    }
    if ($act === 'affected_refund' || $act === 'affected_move') {
        if (!$canAsk) {
            Access::denyPage($page, 'act for passengers of cancelled voyages on');
        }
        $voyageId = (int)pint('voyage_id');
        $v = Ship::voyage($voyageId);
        if (!$v || $v['status'] !== 'cancelled') {
            fail('Choose a cancelled voyage.');
        }
        $tids = array_map('intval', (array)($_POST['tickets'] ?? []));
        $xids = $act === 'affected_refund' ? array_map('intval', (array)($_POST['vehicles'] ?? [])) : [];
        if (!$tids && !$xids) {
            fail('Tick the passengers' . ($act === 'affected_refund' ? ' or vehicles' : '') . ' first.');
        }
        $done = 0;
        $errors = [];
        if ($act === 'affected_refund') {
            $method = (string)p('method', 'cash');
            if (!isset(refunds_methods()[$method])) {
                $method = 'cash';
            }
            // one refund request per booking, so each booking's tickets are approved together
            $byBooking = [];
            foreach ($tids as $t) {
                $b = (int)DB::value('SELECT booking_id FROM shp_tickets WHERE id = :t AND voyage_id = :v', ['t' => $t, 'v' => $voyageId]);
                if ($b) {
                    $byBooking[$b]['t'][] = $t;
                }
            }
            foreach ($xids as $x) {
                $b = (int)DB::value('SELECT booking_id FROM shp_vehicles WHERE id = :x AND voyage_id = :v', ['x' => $x, 'v' => $voyageId]);
                if ($b) {
                    $byBooking[$b]['x'][] = $x;
                }
            }
            foreach ($byBooking as $bid => $sel) {
                try {
                    Booking::requestRefund($bid, $sel['t'] ?? [], $sel['x'] ?? [], 'voyage_cancelled', 'Voyage ' . $v['voyage_no'] . ' cancelled: ' . $v['cancel_reason'], $method);
                    $done += count($sel['t'] ?? []) + count($sel['x'] ?? []);
                } catch (UserError $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $msg = $done . ' full refund(s) requested; they wait in the Approvals Queue.';
        } else {
            $to = (int)pint('new_voyage_id');
            if (!$to) {
                fail('Choose the voyage to move the passengers to.');
            }
            foreach ($tids as $t) {
                try {
                    Booking::transfer($t, $to, ['free' => true, 'reason' => 'Voyage ' . $v['voyage_no'] . ' cancelled']);
                    $done++;
                } catch (UserError $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $msg = $done . ' passenger(s) moved free of charge; new e-tickets were issued and the passengers told.';
        }
        if ($errors) {
            flash($done ? 'warning' : 'danger', ($done ? $msg . ' ' : '') . 'Not done: ' . implode(' ', array_unique($errors)));
        } else {
            flash('success', $msg);
        }
        redirect(url($page, ['tab' => 'affected', 'voyage' => $voyageId]));
    }
    fail('Unknown action.');
});

$counts = DB::pairs("SELECT status, COUNT(*) FROM shp_refunds GROUP BY status");
$cancelled = DB::all("SELECT v.id, v.voyage_no, v.etd, v.cancel_reason, po.code o, pd.code d,
                             (SELECT COUNT(*) FROM shp_tickets t WHERE t.voyage_id = v.id AND t.status IN ('issued', 'checked_in', 'no_show')) waiting
                        FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id
                       WHERE v.status = 'cancelled' AND v.etd >= :f ORDER BY v.etd DESC", ['f' => date('Y-m-d', strtotime(Ship::today() . ' -120 days'))]);
$affectedWaiting = array_sum(array_column($cancelled, 'waiting'));
$tabs = [
    'requested' => ['Waiting for approval', url($page, ['tab' => 'requested']), (int)($counts['requested'] ?? 0)],
    'approved'  => ['Approved, to pay out', url($page, ['tab' => 'approved']), (int)($counts['approved'] ?? 0)],
    'paid'      => ['Paid', url($page, ['tab' => 'paid']), null],
    'rejected'  => ['Rejected', url($page, ['tab' => 'rejected']), null],
    'affected'  => ['Cancelled voyages', url($page, ['tab' => 'affected']), $affectedWaiting ?: null],
];
if (!isset($tabs[$tab])) {
    $tab = 'requested';
}
$actions = Access::page('ops.approvals') ? btn_link(url('ops.approvals'), 'Approvals Queue', 'btn-outline-secondary', 'patch-check') : '';
layout_start('Refunds & Cancellations', ['actions' => $actions]);
echo tabs_line($tabs, $tab);

if ($tab === 'affected') {
    $voyageId = gint('voyage') ?: (int)($cancelled[0]['id'] ?? 0);
    $v = $voyageId ? Ship::voyage($voyageId) : null;
    ?>
    <div class="row g-3">
      <div class="col-lg-4"><div class="card reveal"><div class="card-header">Cancelled voyages</div><div class="list-group list-group-flush">
        <?php foreach ($cancelled as $c): ?>
          <a class="list-group-item list-group-item-action refund-voy <?= (int)$c['id'] === $voyageId ? 'on' : '' ?>" href="<?= h(url($page, ['tab' => 'affected', 'voyage' => $c['id']])) ?>">
            <div class="d-flex justify-content-between"><b><?= h($c['voyage_no']) ?></b><span class="small"><?= (int)$c['waiting'] ?> to settle</span></div>
            <div class="small"><?= h($c['o'] . ' to ' . $c['d']) ?>, <?= h(fdatetime($c['etd'])) ?></div><div class="small opacity-75"><?= h((string)$c['cancel_reason']) ?></div></a>
        <?php endforeach; ?>
        <?php if (!$cancelled): ?><div class="list-group-item"><?= empty_note('No voyage was cancelled in the last four months.', 'calendar-x') ?></div><?php endif; ?>
      </div></div></div>
      <div class="col-lg-8">
      <?php if ($v && $v['status'] === 'cancelled'):
          $tix = DB::all("SELECT t.*, b.booking_ref, b.contact_name, b.contact_phone, c.name cat_name,
                                 (SELECT r.status FROM shp_refunds r WHERE r.ticket_id = t.id ORDER BY r.id DESC LIMIT 1) refund_status
                            FROM shp_tickets t JOIN shp_bookings b ON b.id = t.booking_id JOIN shp_fare_categories c ON c.id = t.category_id
                           WHERE t.voyage_id = :v AND t.status IN ('issued', 'checked_in', 'no_show', 'refunded', 'transferred') ORDER BY b.booking_ref, t.pax_seq", ['v' => $v['id']]);
          $veh = DB::all("SELECT x.*, b.booking_ref, k.name kname, (SELECT r.status FROM shp_refunds r WHERE r.vehicle_id = x.id ORDER BY r.id DESC LIMIT 1) refund_status
                            FROM shp_vehicles x JOIN shp_bookings b ON b.id = x.booking_id JOIN shp_vehicle_classes k ON k.id = x.vehicle_class_id
                           WHERE x.voyage_id = :v AND x.status IN ('issued', 'checked_in', 'no_show', 'refunded') ORDER BY b.booking_ref", ['v' => $v['id']]);
          $alts = DB::pairs("SELECT v.id, CONCAT(v.voyage_no, ', ', DATE_FORMAT(v.etd, '%b %e %l:%i %p'), ', ', s.name) FROM shp_voyages v JOIN shp_vessels s ON s.id = v.vessel_id
                              WHERE v.route_id = :r AND v.status IN ('on_sale', 'boarding') AND v.etd > :n ORDER BY v.etd LIMIT 30", ['r' => $v['route_id'], 'n' => Ship::now()]);
          $open = array_filter($tix, fn($t) => in_array($t['status'], ['issued', 'checked_in', 'no_show'], true) && !in_array($t['refund_status'], ['requested', 'approved'], true));
          $pii = Ship::canPii(); ?>
        <form method="post" class="card reveal" id="affectedForm"><?= csrf_field() ?><input type="hidden" name="voyage_id" value="<?= (int)$v['id'] ?>">
          <div class="card-header d-flex flex-wrap justify-content-between gap-2"><span>Passengers of <?= h($v['voyage_no']) ?></span><span class="small text-muted"><?= h($v['cancel_reason'] . ' (' . $v['cancel_authority'] . ')') ?></span></div>
          <div class="card-body pb-0"><p class="small text-muted mb-2">Every paid passenger of a cancelled voyage may choose a full refund (fees included, nothing retained) or a free move to another sailing of the same route. Tick the passengers who chose the same thing and use one of the two buttons below.</p></div>
          <div class="table-responsive"><table class="table table-sm table-hover mb-0">
            <thead><tr><th style="width:32px"><input class="form-check-input" type="checkbox" data-check-all="tickets[]" aria-label="Tick all"></th><th>Ticket</th><th>Passenger</th><th>Booking</th><th>Category</th><th class="num">Paid</th><th>Now</th></tr></thead><tbody>
            <?php foreach ($tix as $t):
                $settle = in_array($t['status'], ['issued', 'checked_in', 'no_show'], true) && !in_array($t['refund_status'], ['requested', 'approved'], true); ?>
              <tr><td><?php if ($settle): ?><input class="form-check-input" type="checkbox" name="tickets[]" value="<?= (int)$t['id'] ?>" aria-label="Tick <?= h($t['ticket_no']) ?>"><?php endif; ?></td>
                <td><?= h($t['ticket_no']) ?></td><td><?= h($pii ? $t['last_name'] . ', ' . $t['first_name'] : Ship::maskName($t['last_name'], $t['first_name'])) ?></td>
                <td><?= safe_link('tkt.bookings', ['id' => $t['booking_id']], $t['booking_ref']) ?><div class="small text-muted"><?= h($pii ? (string)$t['contact_phone'] : Ship::maskPhone($t['contact_phone'])) ?></div></td>
                <td><?= h($t['cat_name']) ?></td><td class="num"><?= money($t['total']) ?></td>
                <td><?= $t['refund_status'] && in_array($t['refund_status'], ['requested', 'approved', 'paid'], true) ? Ship::label($t['refund_status'], ['requested' => 'Refund waiting', 'approved' => 'Refund approved', 'paid' => 'Refunded']) : Ship::label($t['status']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$tix): ?><tr><td colspan="7"><?= empty_note('No paid passenger on this voyage.') ?></td></tr><?php endif; ?>
            </tbody></table></div>
          <?php if ($veh): ?>
          <div class="table-responsive border-top"><table class="table table-sm mb-0"><thead><tr><th style="width:32px"></th><th>Vehicle pass</th><th>Plate</th><th>Class</th><th>Booking</th><th class="num">Paid</th><th>Now</th></tr></thead><tbody>
            <?php foreach ($veh as $x): $settle = in_array($x['status'], ['issued', 'checked_in', 'no_show'], true) && !in_array($x['refund_status'], ['requested', 'approved'], true); ?>
              <tr><td><?php if ($settle): ?><input class="form-check-input" type="checkbox" name="vehicles[]" value="<?= (int)$x['id'] ?>" aria-label="Tick <?= h((string)$x['vehicle_no']) ?>"><?php endif; ?></td>
                <td><?= h((string)$x['vehicle_no']) ?></td><td><?= h($x['plate_no']) ?></td><td><?= h($x['kname']) ?></td><td><?= h($x['booking_ref']) ?></td><td class="num"><?= money($x['total']) ?></td>
                <td><?= $x['refund_status'] ? Ship::label($x['refund_status'], ['requested' => 'Refund waiting', 'approved' => 'Refund approved', 'paid' => 'Refunded']) : Ship::label($x['status']) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table><div class="small text-muted px-3 pb-2">Vehicle passes are refunded in full; to sail on another voyage the vehicle is booked again.</div></div>
          <?php endif; ?>
          <?php if ($canAsk && ($open || $veh)): ?>
          <div class="card-footer bg-transparent"><div class="row g-2 align-items-end">
            <div class="col-md-6"><div class="p-2 border rounded-3 h-100"><div class="fw-semibold small mb-1">Full refund</div><div class="d-flex gap-2">
              <select class="form-select form-select-sm" name="method"><?= options(refunds_methods(), 'cash') ?></select>
              <button class="btn btn-sm btn-outline-danger text-nowrap" name="act" value="affected_refund" data-confirm="Request a full refund for the ticked passengers and vehicles?">Request refunds</button></div></div></div>
            <div class="col-md-6"><div class="p-2 border rounded-3 h-100"><div class="fw-semibold small mb-1">Move free of charge</div><div class="d-flex gap-2">
              <select class="form-select form-select-sm" name="new_voyage_id"><?= options($alts, null, $alts ? 'Choose the voyage' : 'No voyage of this route on sale') ?></select>
              <button class="btn btn-sm btn-primary text-nowrap" name="act" value="affected_move" <?= $alts ? '' : 'disabled' ?>>Move</button></div></div></div>
          </div></div>
          <?php endif; ?>
        </form>
        <script>
        document.querySelectorAll('[data-check-all]').forEach(function (all) {
          all.addEventListener('change', function () { document.querySelectorAll('input[name="' + all.dataset.checkAll + '"]').forEach(function (c) { c.checked = all.checked; }); });
        });
        </script>
      <?php elseif ($cancelled): ?>
        <?= empty_note('Choose a cancelled voyage on the left.', 'arrow-left') ?>
      <?php endif; ?>
      </div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the refund lists
$q = trim((string)g('q'));
$where = 'r.status = :st';
$par = ['st' => $tab];
if ($q !== '') {
    $where .= ' AND (r.refund_no LIKE :q OR b.booking_ref LIKE :q2 OR t.ticket_no LIKE :q3 OR t.last_name LIKE :q4)';
    $par += ['q' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%", 'q4' => "%$q%"];
}
$order = ['requested' => 'r.requested_at', 'approved' => 'r.approved_at', 'paid' => 'r.paid_at DESC', 'rejected' => 'r.approved_at DESC'][$tab];
$rows = DB::all("SELECT r.*, b.booking_ref, b.contact_name, t.ticket_no, t.last_name, t.first_name, x.vehicle_no, x.plate_no, v.voyage_no, v.id voyage_id,
                        (SELECT a.id FROM shp_approvals a WHERE a.ref_table = 'shp_refunds' AND a.ref_id = r.id ORDER BY a.id DESC LIMIT 1) approval_id,
                        (SELECT a.decision_notes FROM shp_approvals a WHERE a.ref_table = 'shp_refunds' AND a.ref_id = r.id ORDER BY a.id DESC LIMIT 1) decision_notes
                   FROM shp_refunds r JOIN shp_bookings b ON b.id = r.booking_id LEFT JOIN shp_tickets t ON t.id = r.ticket_id LEFT JOIN shp_vehicles x ON x.id = r.vehicle_id
                   LEFT JOIN shp_voyages v ON v.id = COALESCE(t.voyage_id, x.voyage_id)
                  WHERE $where ORDER BY $order LIMIT 300", $par);
$shift = Cashier::current($me);
?>
<form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
  <?= input_field('q', 'Find', $q, ['class' => '', 'extra' => 'placeholder="Refund, booking, ticket or surname"']) ?>
  <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i> Find</button></div>
</form>
<?php if ($tab === 'requested'): ?>
  <p class="small text-muted">A refund is requested from the booking (Bookings & Tickets) and decided in the Approvals Queue by someone other than the person who asked. Nothing is posted to the books until it is approved.</p>
<?php elseif ($tab === 'approved'): ?>
  <p class="small text-muted">Approved refunds are already a credit to the customer in the books. Paying one out moves the money: cash comes from your shift drawer<?= $shift ? ' (' . h($shift['shift_no']) . ' is open)' : ', so open your shift first' ?>; other methods go back through the channel the passenger used.</p>
<?php endif; ?>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>Refund</th><th>Ticket / pass</th><th>Booking</th><th>Voyage</th><th>Reason</th><th class="num">Paid</th><th class="num">Kept</th><th class="num">Refund</th>
    <th><?= ['requested' => 'Requested', 'approved' => 'Approved', 'paid' => 'Paid out', 'rejected' => 'Rejected'][$tab] ?></th><th><?= $tab === 'approved' ? 'Pay out' : '' ?></th></tr></thead><tbody>
  <?php foreach ($rows as $r):
      $who = ['requested' => [$r['requested_by'], $r['requested_at']], 'approved' => [$r['approved_by'], $r['approved_at']], 'paid' => [$r['paid_by'], $r['paid_at']], 'rejected' => [$r['approved_by'], $r['approved_at']]][$tab]; ?>
    <tr><td class="fw-semibold"><?= h($r['refund_no']) ?></td>
      <td><?= $r['ticket_no'] ? h($r['ticket_no']) . '<div class="small text-muted">' . h(Ship::canPii() ? $r['last_name'] . ', ' . $r['first_name'] : Ship::maskName((string)$r['last_name'], (string)$r['first_name'])) . '</div>' : h((string)$r['vehicle_no']) . '<div class="small text-muted">' . h((string)$r['plate_no']) . '</div>' ?></td>
      <td><?= safe_link('tkt.bookings', ['id' => $r['booking_id']], $r['booking_ref']) ?></td>
      <td><?= $r['voyage_no'] ? safe_link('ops.voyages', ['id' => $r['voyage_id']], $r['voyage_no']) : '' ?></td>
      <td class="small"><?= h(refunds_type_name($r['refund_type'])) ?><div class="text-muted"><?= h((string)$r['reason']) ?></div><?= $tab === 'rejected' && $r['decision_notes'] ? '<div class="text-danger">' . h($r['decision_notes']) . '</div>' : '' ?></td>
      <td class="num"><?= money($r['original_amount']) ?></td><td class="num"><?= money($r['fee_retained'], true) ?></td><td class="num fw-semibold"><?= money($r['refund_amount']) ?></td>
      <td class="small text-nowrap"><?= h(Ship::userName((int)$who[0])) ?><div class="text-muted"><?= $who[1] ? h(fdatetime($who[1])) : '' ?></div>
        <?php if ($tab === 'paid'): ?><div><?= h(refunds_methods()[$r['method']] ?? $r['method']) ?> <?= txn_doc_link($r['paid_txn_id'] ? (int)$r['paid_txn_id'] : null) ?></div><?php endif; ?></td>
      <td class="text-nowrap">
      <?php if ($tab === 'requested'): ?>
        <?= $r['approval_id'] && Access::page('ops.approvals') ? '<a class="small" href="' . h(url('ops.approvals', ['id' => $r['approval_id']])) . '">Open in the queue</a>' : '' ?>
      <?php elseif ($tab === 'approved' && $canPay): ?>
        <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="act" value="pay"><input type="hidden" name="refund_id" value="<?= (int)$r['id'] ?>">
          <select class="form-select form-select-sm" name="method" style="min-width:130px" aria-label="Paid out by"><?= options(refunds_methods(), $r['method']) ?></select>
          <button class="btn btn-sm btn-primary" data-confirm="Pay out <?= h(money($r['refund_amount'])) ?> to the passenger now?">Pay</button></form>
      <?php elseif ($tab === 'approved'): ?><span class="small text-muted">Paid out at a ticket counter</span>
      <?php elseif ($tab === 'paid' || $tab === 'approved'): ?><?= txn_doc_link($r['credit_txn_id'] ? (int)$r['credit_txn_id'] : null) ?>
      <?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="10"><?= empty_note(['requested' => 'No refund is waiting for approval.', 'approved' => 'Nothing to pay out.', 'paid' => 'No refund paid yet.', 'rejected' => 'No rejected refund.'][$tab], 'arrow-counterclockwise') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php
layout_end();
