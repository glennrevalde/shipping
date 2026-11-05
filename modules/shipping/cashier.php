<?php
declare(strict_types=1);

$page = 'tkt.cashier';
$id = gint('id');
$isSup = Access::can($page, 'approve');
$me = (int)Auth::id();

if (!function_exists('cashier_channel_name')) {
    function cashier_channel_name(string $c): string
    {
        return ['cash' => 'Cash', 'card' => 'Credit / debit card', 'ewallet' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer',
            'gateway' => 'Online gateway', 'corporate' => 'Corporate account', 'agent' => 'Travel agent account'][$c] ?? ucfirst(str_replace('_', ' ', $c));
    }
}

handle_post(function () use ($page, $isSup, $me) {
    $act = (string)p('act');
    if ($act === 'open') {
        ship_require($page, 'create');
        $office = pint('office_id');
        if (!$office || !isset(Ship::userOffices()[$office])) {
            fail('Choose one of your ticket offices.');
        }
        $sid = DB::tx(fn() => Cashier::open($office, pnum('opening_float')));
        flash('success', 'Shift opened. Count the float into the drawer before the first sale.');
        redirect(url($page, ['id' => $sid]));
    }
    $sid = pint('shift_id');
    $s = $sid ? DB::row('SELECT * FROM shp_cashier_shifts WHERE id = :id', ['id' => $sid]) : null;
    if (!$s) {
        fail('Shift not found.');
    }
    if ($act === 'close') {
        if ((int)$s['cashier_id'] !== $me && !$isSup) {
            Access::denyPage($page, 'close other cashiers\' shifts on');
        }
        if (trim((string)p('counted')) === '') {
            fail('Count the cash in the drawer and enter the total.');
        }
        Cashier::close((int)$s['id'], pnum('counted'), (string)p('notes'));
        flash('success', 'Shift ' . $s['shift_no'] . ' closed. A supervisor reviews it next.');
    } elseif ($act === 'review') {
        Cashier::review((int)$s['id'], (string)p('notes'));
        flash('success', 'Shift ' . $s['shift_no'] . ' marked as reviewed.');
    } else {
        fail('Unknown action.');
    }
    redirect(url($page, ['id' => $s['id']]));
});

// ------------------------------------------------------------------ one shift (and its printable report)
if ($id) {
    $s = DB::row('SELECT s.*, o.name office_name, o.code office_code, u.full_name cashier_name FROM shp_cashier_shifts s JOIN shp_offices o ON o.id = s.office_id
                   JOIN users u ON u.id = s.cashier_id WHERE s.id = :id', ['id' => $id]);
    if (!$s) {
        flash('warning', 'That shift was not found.');
        redirect(url($page));
    }
    if ((int)$s['cashier_id'] !== $me && !$isSup) {
        Access::denyPage($page, 'see other cashiers\' shifts on');
    }
    $tot = Cashier::totals($id);
    $pays = DB::all('SELECT p.*, b.booking_ref, g.waybill_no FROM shp_payments p LEFT JOIN shp_bookings b ON b.id = p.booking_id LEFT JOIN shp_cargo g ON g.id = p.cargo_id
                      WHERE p.shift_id = :s ORDER BY p.received_at, p.id', ['s' => $id]);
    $refs = DB::all("SELECT r.*, b.booking_ref FROM shp_refunds r JOIN shp_bookings b ON b.id = r.booking_id WHERE r.shift_id = :s AND r.status = 'paid' ORDER BY r.paid_at", ['s' => $id]);
    $open = $s['status'] === 'open';
    $actions = '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print shift report</button> '
        . btn_link(url($page), 'All shifts', 'btn-outline-secondary', 'list-ul');
    layout_start('Shift ' . $s['shift_no'], ['actions' => $actions]);
    $expected = $open ? $tot['expected'] : (float)$s['expected_cash'];
    $os = (float)$s['over_short'];
    ?>
    <div class="card reveal mb-3"><div class="card-body">
      <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
        <div><div class="fw-bold fs-5"><?= h($s['office_name']) ?></div><div class="small text-muted"><?= h($s['cashier_name']) ?>, opened <?= h(fdatetime($s['opened_at'])) ?><?= $s['closed_at'] ? ', closed ' . h(fdatetime($s['closed_at'])) : '' ?></div></div>
        <div class="text-end"><?= Ship::label($s['status'], ['open' => 'Open', 'closed' => 'Closed, waiting for review', 'reviewed' => 'Reviewed']) ?>
          <?php if ($s['reviewed_by']): ?><div class="small text-muted">by <?= h(Ship::userName((int)$s['reviewed_by'])) ?>, <?= h(fdatetime($s['reviewed_at'])) ?></div><?php endif; ?></div>
      </div>
    </div></div>
    <div class="kpi-grid mb-3">
      <?= kpi_tile('Opening float', '₱' . money($s['opening_float']), 'safe', 'Counted into the drawer at the start') ?>
      <?= kpi_tile('Cash collected', '₱' . money($tot['cash']), 'cash-stack', $tot['count'] . ' payment(s) on the shift', 'good', null, $tot['cash']) ?>
      <?= kpi_tile('Card, e-wallet, bank', '₱' . money($tot['non_cash']), 'credit-card', 'Not in the drawer', '', null, $tot['non_cash']) ?>
      <?= kpi_tile('Cash refunds paid', '₱' . money($tot['refunds_cash']), 'arrow-counterclockwise', count($refs) . ' refund(s)', $tot['refunds_cash'] > 0 ? 'warn' : '') ?>
      <?= kpi_tile('Expected in the drawer', '₱' . money($expected), 'calculator', 'Float plus cash less cash refunds') ?>
      <?php if (!$open): ?><?= kpi_tile($os > 0 ? 'Cash over' : ($os < 0 ? 'Cash short' : 'Drawer balanced'), '₱' . money(abs($os)), $os == 0.0 ? 'check2-circle' : 'exclamation-triangle', 'Counted ₱' . money($s['counted_cash']), $os == 0.0 ? 'good' : 'bad') ?><?php endif; ?>
    </div>
    <div class="row g-3">
      <div class="col-lg-<?= $open || ($s['status'] === 'closed' && $isSup) ? '7' : '12' ?>">
        <div class="card reveal mb-3"><div class="card-header">Collections by payment channel</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>Channel</th><th class="num">Amount</th></tr></thead><tbody>
          <?php foreach ($tot['by_channel'] as $ch => $amt): ?><tr><td><?= h(cashier_channel_name((string)$ch)) ?></td><td class="num"><?= money($amt) ?></td></tr><?php endforeach; ?>
          <?php if (!$tot['by_channel']): ?><tr><td colspan="2"><?= empty_note('Nothing collected on this shift yet.', 'cash') ?></td></tr><?php endif; ?>
          <tr class="grid-totals"><td class="fw-semibold">Total collected</td><td class="num fw-semibold"><?= money(array_sum($tot['by_channel'])) ?></td></tr></tbody></table></div></div>
        <div class="card reveal mb-3"><div class="card-header">Payments received (<?= count($pays) ?>)</div><div class="table-responsive" style="max-height:460px;overflow:auto"><table class="table table-sm table-hover mb-0">
          <thead><tr><th>Time</th><th>Payment</th><th>For</th><th>Channel</th><th>Reference</th><th>Receipt</th><th class="num">Amount</th></tr></thead><tbody>
          <?php foreach ($pays as $p): ?><tr>
            <td class="text-nowrap"><?= h(date('g:i A', strtotime($p['received_at']))) ?></td><td><?= h($p['payment_no']) ?></td>
            <td><?= $p['booking_ref'] ? safe_link('tkt.bookings', ['id' => $p['booking_id']], $p['booking_ref']) : h((string)$p['waybill_no']) ?></td>
            <td><?= h(cashier_channel_name($p['channel'])) ?></td><td class="small"><?= h((string)$p['provider_ref']) ?></td><td><?= txn_doc_link($p['txn_id'] ? (int)$p['txn_id'] : null) ?></td>
            <td class="num"><?= money($p['amount']) ?><?= !in_array($p['status'], ['captured', 'settled'], true) ? ' ' . Ship::label($p['status']) : '' ?></td></tr><?php endforeach; ?>
          <?php if (!$pays): ?><tr><td colspan="7"><?= empty_note('No payment yet.', 'receipt') ?></td></tr><?php endif; ?>
          </tbody></table></div></div>
        <?php if ($refs): ?>
        <div class="card reveal mb-3"><div class="card-header">Cash refunds paid from the drawer</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>Refund</th><th>Booking</th><th>Paid</th><th>Document</th><th class="num">Amount</th></tr></thead><tbody>
          <?php foreach ($refs as $r): ?><tr><td><?= h($r['refund_no']) ?></td><td><?= safe_link('tkt.bookings', ['id' => $r['booking_id']], $r['booking_ref']) ?></td><td><?= h(fdatetime($r['paid_at'])) ?></td><td><?= txn_doc_link($r['paid_txn_id'] ? (int)$r['paid_txn_id'] : null) ?></td><td class="num"><?= money($r['refund_amount']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div></div>
        <?php endif; ?>
        <?php if ($s['over_short_txn_id']): ?><p class="small text-muted">The difference was posted to Cash Over and Short: <?= txn_doc_link((int)$s['over_short_txn_id']) ?>.</p><?php endif; ?>
        <?php if ($s['notes']): ?><p class="small"><b>Notes:</b> <?= h($s['notes']) ?></p><?php endif; ?>
      </div>
      <?php if ($open && ((int)$s['cashier_id'] === $me || $isSup)): ?>
      <div class="col-lg-5 no-print">
        <form method="post" class="card reveal" id="closeShift"><?= csrf_field() ?><input type="hidden" name="act" value="close"><input type="hidden" name="shift_id" value="<?= (int)$s['id'] ?>">
          <div class="card-header">Close the shift</div><div class="card-body">
            <p class="small text-muted">Count every bill and coin in the drawer, including the float. Non-cash payments are not counted here; they are matched with the card terminal and e-wallet reports.</p>
            <label class="form-label">Cash counted in the drawer</label>
            <input class="form-control form-control-lg text-end" type="number" step="0.01" min="0" name="counted" id="counted" required data-expected="<?= h((string)$expected) ?>">
            <div class="d-flex justify-content-between small mt-2"><span>Expected</span><b>₱<?= money($expected) ?></b></div>
            <div class="d-flex justify-content-between small"><span>Difference</span><b id="diffOut">-</b></div>
            <label class="form-label mt-3">Notes for the supervisor</label><textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="Explain any difference"></textarea>
            <button class="btn btn-primary mt-3 w-100" data-confirm="Close the shift with this count? It cannot be reopened."><i class="bi bi-lock"></i> Close shift</button>
          </div></form>
      </div>
      <script>
      (function () {
        var c = document.getElementById('counted'), out = document.getElementById('diffOut');
        if (!c) { return; }
        c.addEventListener('input', function () {
          if (c.value === '') { out.textContent = '-'; out.className = ''; return; }
          var d = Math.round((parseFloat(c.value) - parseFloat(c.dataset.expected)) * 100) / 100;
          out.textContent = d === 0 ? 'Balanced' : (d > 0 ? 'Over ' : 'Short ') + '₱' + Math.abs(d).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
          out.className = d === 0 ? 'st st-good' : 'st st-bad';
        });
      })();
      </script>
      <?php elseif ($s['status'] === 'closed' && $isSup): ?>
      <div class="col-lg-5 no-print">
        <form method="post" class="card reveal"><?= csrf_field() ?><input type="hidden" name="act" value="review"><input type="hidden" name="shift_id" value="<?= (int)$s['id'] ?>">
          <div class="card-header">Supervisor review</div><div class="card-body">
          <?php if ((int)$s['cashier_id'] === $me): ?><p class="small text-muted mb-0">This is your own shift. Another supervisor reviews it.</p>
          <?php else: ?>
            <p class="small text-muted">Check the count sheet against the drawer, the card terminal settlement and the e-wallet report, then sign off.</p>
            <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="Review notes"></textarea>
            <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-check2-square"></i> Mark as reviewed</button>
          <?php endif; ?>
          </div></form>
      </div>
      <?php endif; ?>
    </div>
    <div class="d-none d-print-block mt-4 small"><div class="row"><div class="col-6">Cashier: ______________________<br><?= h($s['cashier_name']) ?></div><div class="col-6">Reviewed by: ______________________</div></div></div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ my shift and the shift list
$mine = Cashier::current($me);
$from = gdate('from', date('Y-m-d', strtotime(Ship::today() . ' -13 days')));
$to = gdate('to', Ship::today());
$office = gint('office');
$status = (string)g('status');
$where = 'DATE(s.opened_at) BETWEEN :f AND :t';
$par = ['f' => $from, 't' => $to];
if (!$isSup) {
    $where .= ' AND s.cashier_id = :me';
    $par['me'] = $me;
}
if ($office) {
    $where .= ' AND s.office_id = :o';
    $par['o'] = $office;
}
if (in_array($status, ['open', 'closed', 'reviewed'], true)) {
    $where .= ' AND s.status = :st';
    $par['st'] = $status;
}
$rows = DB::all("SELECT s.*, o.name office_name, u.full_name cashier_name,
                        (SELECT COALESCE(SUM(p.amount), 0) FROM shp_payments p WHERE p.shift_id = s.id AND p.channel = 'cash' AND p.status IN ('captured', 'settled')) cash_in,
                        (SELECT COALESCE(SUM(p.amount), 0) FROM shp_payments p WHERE p.shift_id = s.id AND p.channel <> 'cash' AND p.status IN ('captured', 'settled')) other_in
                   FROM shp_cashier_shifts s JOIN shp_offices o ON o.id = s.office_id JOIN users u ON u.id = s.cashier_id
                  WHERE $where ORDER BY s.opened_at DESC LIMIT 300", $par);
$waiting = $isSup ? (int)DB::value("SELECT COUNT(*) FROM shp_cashier_shifts WHERE status = 'closed' AND cashier_id <> :me", ['me' => $me]) : 0;
layout_start('Cashier Shifts', ['actions' => $mine ? btn_link(url($page, ['id' => $mine['id']]), 'My open shift', 'btn-primary', 'cash-coin') : '']);
?>
<div class="row g-3 mb-3">
  <div class="col-lg-<?= $isSup ? '7' : '12' ?>">
  <?php if ($mine):
      $t = Cashier::totals((int)$mine['id']); ?>
    <a class="card reveal text-decoration-none text-reset d-block" href="<?= h(url($page, ['id' => $mine['id']])) ?>"><div class="card-body">
      <div class="d-flex flex-wrap justify-content-between gap-2">
        <div><div class="small text-muted">Your shift is open</div><div class="fw-bold fs-5"><?= h($mine['shift_no']) ?>, <?= h($mine['office_name']) ?></div><div class="small text-muted">Since <?= h(fdatetime($mine['opened_at'])) ?></div></div>
        <div class="text-end"><div class="small text-muted">Expected in the drawer</div><div class="fw-bold fs-4">₱<?= money($t['expected']) ?></div><div class="small"><?= (int)$t['count'] ?> payment(s), ₱<?= money($t['non_cash']) ?> non-cash</div></div>
      </div><div class="small mt-2 text-primary">Open the shift to see the payments or to close it <i class="bi bi-arrow-right"></i></div></div></a>
  <?php elseif (Access::can($page, 'create')): ?>
    <form method="post" class="card reveal"><?= csrf_field() ?><input type="hidden" name="act" value="open"><div class="card-header">Open a cashier shift</div><div class="card-body">
      <p class="small text-muted">Cash sales and cash refunds are counted per shift. Count the change fund you received and open the shift before the first sale.</p>
      <div class="row g-2">
        <?= select_field('office_id', 'Ticket office', Ship::userOffices(), array_key_first(Ship::userOffices()), ['class' => 'col-md-6', 'required' => true]) ?>
        <?= input_field('opening_float', 'Opening float (change fund)', '2000.00', ['type' => 'number', 'class' => 'col-md-6', 'required' => true]) ?>
      </div><button class="btn btn-primary btn-sm mt-3"><i class="bi bi-unlock"></i> Open shift</button></div></form>
  <?php else: ?>
    <div class="card reveal"><div class="card-body small text-muted">Your role reviews shifts; it does not sell at a counter.</div></div>
  <?php endif; ?>
  </div>
  <?php if ($isSup): ?>
  <div class="col-lg-5"><div class="kpi-grid">
    <?= kpi_tile('Shifts open now', (string)DB::value("SELECT COUNT(*) FROM shp_cashier_shifts WHERE status = 'open'"), 'door-open', 'Across all offices', '', url($page, ['status' => 'open', 'from' => date('Y-m-d', strtotime(Ship::today() . ' -60 days'))])) ?>
    <?= kpi_tile('Waiting for review', (string)$waiting, 'clipboard-check', 'Closed by another cashier', $waiting ? 'warn' : 'good', url($page, ['status' => 'closed', 'from' => date('Y-m-d', strtotime(Ship::today() . ' -60 days'))])) ?>
  </div></div>
  <?php endif; ?>
</div>
<form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>">
  <?= input_field('from', 'Opened from', $from, ['type' => 'date', 'class' => '']) ?><?= input_field('to', 'To', $to, ['type' => 'date', 'class' => '']) ?>
  <?= select_field('office', 'Office', Ship::officeOptions(), $office, ['class' => '', 'blank' => 'All offices']) ?>
  <?= select_field('status', 'Status', ['open' => 'Open', 'closed' => 'Closed, not reviewed', 'reviewed' => 'Reviewed'], $status, ['class' => '', 'blank' => 'Any status']) ?>
  <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Show</button></div>
</form>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Shift</th><th>Office</th><th>Cashier</th><th>Opened</th><th>Closed</th><th class="num">Float</th><th class="num">Cash in</th><th class="num">Non-cash</th><th class="num">Counted</th><th class="num">Over / short</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $os = (float)$r['over_short']; ?>
    <tr><td><a href="<?= h(url($page, ['id' => $r['id']])) ?>"><?= h($r['shift_no']) ?></a></td><td><?= h($r['office_name']) ?></td><td><?= h($r['cashier_name']) ?></td>
      <td class="text-nowrap"><?= h(fdatetime($r['opened_at'])) ?></td><td class="text-nowrap"><?= $r['closed_at'] ? h(fdatetime($r['closed_at'])) : '' ?></td>
      <td class="num"><?= money($r['opening_float']) ?></td><td class="num"><?= money($r['cash_in']) ?></td><td class="num"><?= money($r['other_in']) ?></td>
      <td class="num"><?= $r['status'] === 'open' ? '' : money($r['counted_cash']) ?></td>
      <td class="num <?= $os < 0 ? 'text-danger' : ($os > 0 ? 'text-warning' : '') ?>"><?= $r['status'] === 'open' ? '' : ($os == 0.0 ? 'Balanced' : money($os)) ?></td>
      <td><?= Ship::label($r['status'], ['open' => 'Open', 'closed' => 'For review', 'reviewed' => 'Reviewed']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="11"><?= empty_note('No shift in these dates.', 'cash-coin') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php
layout_end();
