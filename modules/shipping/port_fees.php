<?php
declare(strict_types=1);

$page = 'cgo.port_fees';
$from = gdate('from', date('Y-m-d', strtotime(Ship::today() . ' -30 days')));
$to = gdate('to', Ship::today());
$feeId = gint('fee');
$fees = DB::pairs("SELECT id, CONCAT(name, ' (', code, ')') FROM shp_fees WHERE ownership = 'agent' ORDER BY sort_order, name");

handle_post(function () use ($page, $from, $to) {
    ship_require($page, 'create');
    $fee = (int)pint('fee_id');
    $ids = array_map('intval', (array)($_POST['voyages'] ?? []));
    $bank = (int)pint('bank_account_id');
    $date = pdate('remit_date', Ship::today());
    if (!$bank || !isset(Accts::banks()[$bank])) {
        fail('Choose the bank account the check is drawn on.');
    }
    $txn = PortFees::remit($fee, $ids, $bank, $date, (string)p('ref_no'));
    $t = Txn::load($txn);
    flash('success', 'Remitted ₱' . money($t['total']) . ' with ' . Txn::label('check') . ' ' . $t['txn_no'] . '; the payable is cleared for ' . count($ids) . ' voyage(s).');
    redirect(url($page, ['from' => $from, 'to' => $to, 'fee' => $fee]));
});

$rows = PortFees::byVoyage($from, $to, $feeId);
$sum = fn(string $k, ?int $fid = null) => array_sum(array_map(fn($r) => $fid === null || (int)$r['fee_id'] === $fid ? (float)$r[$k] : 0, $rows));
$canRemit = Access::can($page, 'create');
$byFee = [];
foreach ($rows as $r) {
    $byFee[(int)$r['fee_id']][] = $r;
}
$history = DB::all("SELECT p.*, t.txn_no, t.txn_date, t.total, t.ref_no, t.is_voided, f.name AS fee_name, f.code AS fee_code
                      FROM shp_postings p JOIN transactions t ON t.id = p.txn_id JOIN shp_fees f ON f.id = p.source_id
                     WHERE p.source_type = 'fee_remit' ORDER BY t.txn_date DESC, p.id DESC LIMIT 30");

layout_start('Port Fee Reconciliation & Remittance', ['actions' => Access::page('rpt.shp_port_fees') ? btn_link(url('rpt.shp_port_fees'), 'Port fees report', 'btn-outline-secondary', 'bar-chart') : '']);
echo '<div class="kpi-grid mb-3">'
    . kpi_tile('Collected', '₱' . money($sum('collected')), 'cash-stack', 'On tickets and vehicle passes', '', null, $sum('collected'))
    . kpi_tile('Refunded', '₱' . money($sum('refunded')), 'arrow-counterclockwise', 'Given back with refunded tickets', '', null, $sum('refunded'))
    . kpi_tile('Remitted', '₱' . money($sum('remitted')), 'bank', 'Paid over to the authorities', 'good', null, $sum('remitted'))
    . kpi_tile('Still to remit', '₱' . money($sum('outstanding')), 'hourglass-split', 'Held in the payable accounts', $sum('outstanding') > 0 ? 'warn' : 'good', null, $sum('outstanding'))
    . '</div>';
?>
<form class="toolbar mb-3" method="get"><input type="hidden" name="p" value="<?= h($page) ?>">
  <?= input_field('from', 'Voyages from', $from, ['type' => 'date', 'class' => '']) ?><?= input_field('to', 'to', $to, ['type' => 'date', 'class' => '']) ?>
  <?= select_field('fee', 'Charge', $fees, $feeId, ['class' => '', 'blank' => 'Every third-party charge']) ?>
  <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Show</button></div>
</form>
<p class="small text-muted">Terminal fees and passenger insurance are collected for the port authority and the insurer, so they sit in payable accounts and never in revenue. Each voyage below shows what was collected against the passengers who boarded; tick the voyages to remit and the check clears the payable.</p>

<?php foreach ($byFee as $fid => $list):
    $f = DB::row('SELECT f.*, v.name AS vendor_name FROM shp_fees f LEFT JOIN vendors v ON v.id = f.vendor_id WHERE f.id = :id', ['id' => $fid]);
    $out = $sum('outstanding', $fid);
    $remitHere = $canRemit && $out > 0; ?>
<form method="post" class="card reveal mb-3"><?= csrf_field() ?><input type="hidden" name="fee_id" value="<?= (int)$fid ?>">
  <div class="card-header d-flex flex-wrap justify-content-between gap-2"><span><?= h($f['name']) ?> <span class="text-muted fw-normal small">remitted to <?= h($f['vendor_name'] ?: 'no authority set') ?></span></span>
    <span class="small">Outstanding <b>₱<?= money($out) ?></b></span></div>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><?php if ($remitHere): ?><th style="width:34px"><input class="form-check-input" type="checkbox" data-all="<?= (int)$fid ?>" title="Tick every voyage with something to remit"></th><?php endif; ?>
      <th>Voyage</th><th>Departure</th><th>Status</th><th class="num">Boarded</th><th class="num">Charged and boarded</th><th class="num">Exempt</th><th class="num">Collected</th><th class="num">Refunded</th><th class="num">Remitted</th><th class="num">Outstanding</th></tr></thead><tbody>
    <?php foreach ($list as $r): ?>
      <tr><?php if ($remitHere): ?><td><?php if ((float)$r['outstanding'] > 0): ?><input class="form-check-input" type="checkbox" name="voyages[]" value="<?= (int)$r['voyage_id'] ?>" data-fee="<?= (int)$fid ?>" data-amt="<?= h((string)$r['outstanding']) ?>"><?php endif; ?></td><?php endif; ?>
        <td class="fw-semibold"><?= safe_link('ops.voyages', ['id' => $r['voyage_id']], $r['voyage_no']) ?></td><td class="small"><?= h(fdatetime($r['etd'])) ?></td><td><?= Ship::label($r['voyage_status']) ?></td>
        <td class="num"><?= (int)$r['pax_boarded'] ?></td><td class="num"><?= (int)$r['boarded_paid'] ?></td><td class="num"><?= (int)$r['exempt'] ?></td>
        <td class="num"><?= money($r['collected']) ?></td><td class="num"><?= money($r['refunded'], true) ?></td><td class="num"><?= money($r['remitted'], true) ?></td>
        <td class="num fw-semibold <?= (float)$r['outstanding'] > 0 ? '' : 'text-muted' ?>"><?= money($r['outstanding']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="grid-totals"><?php if ($remitHere): ?><td></td><?php endif; ?><td colspan="6">Total</td><td class="num"><?= money($sum('collected', $fid)) ?></td><td class="num"><?= money($sum('refunded', $fid)) ?></td><td class="num"><?= money($sum('remitted', $fid)) ?></td><td class="num"><?= money($out) ?></td></tr></tfoot>
  </table></div>
  <?php if ($remitHere): ?>
  <div class="card-footer bg-transparent"><div class="row g-2 align-items-end">
    <?= select_field('bank_account_id', 'Check drawn on', Accts::banks(), '', ['class' => 'col-md-3', 'blank' => '', 'required' => true]) ?>
    <?= input_field('remit_date', 'Date', Ship::today(), ['type' => 'date', 'class' => 'col-md-2']) ?>
    <?= input_field('ref_no', 'Authority statement / OR no.', '', ['class' => 'col-md-3', 'required' => true]) ?>
    <div class="col-md-4"><button class="btn btn-primary btn-sm w-100" data-remit-btn="<?= (int)$fid ?>" disabled><i class="bi bi-send-check"></i> Remit the voyages ticked</button></div>
  </div></div>
  <?php endif; ?>
</form>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="card reveal mb-3"><?= empty_note('No third-party charge collected on the voyages of these dates.', 'cash-stack') ?></div><?php endif; ?>

<div class="card reveal"><div class="card-header">Remittances made</div><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Date</th><th>Check</th><th>Charge</th><th>Reference</th><th>Details</th><th class="num">Amount</th></tr></thead><tbody>
  <?php foreach ($history as $hrow): ?><tr><td><?= h(fdate($hrow['txn_date'])) ?></td><td><?= txn_doc_link((int)$hrow['txn_id'], Txn::label('check') . ' ' . $hrow['txn_no']) ?><?= (int)$hrow['is_voided'] ? ' <span class="st st-bad">Void</span>' : '' ?></td>
    <td><?= h($hrow['fee_name']) ?></td><td><?= h((string)$hrow['ref_no']) ?></td><td class="small text-muted"><?= h((string)$hrow['memo']) ?></td><td class="num"><?= money($hrow['total']) ?></td></tr><?php endforeach; ?>
  <?php if (!$history): ?><tr><td colspan="6"><?= empty_note('Nothing remitted yet.', 'bank') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<script>
(function () {
  function refresh(fee) {
    var boxes = document.querySelectorAll('input[data-fee="' + fee + '"]'), sum = 0, n = 0;
    boxes.forEach(function (b) { if (b.checked) { sum += parseFloat(b.dataset.amt) || 0; n++; } });
    var btn = document.querySelector('[data-remit-btn="' + fee + '"]');
    if (btn) { btn.disabled = n === 0; btn.innerHTML = '<i class="bi bi-send-check"></i> ' + (n ? 'Remit ₱' + sum.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (' + n + ' voyage' + (n === 1 ? '' : 's') + ')' : 'Remit the voyages ticked'); }
  }
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.dataset.all) { document.querySelectorAll('input[data-fee="' + t.dataset.all + '"]').forEach(function (b) { b.checked = t.checked; }); refresh(t.dataset.all); }
    else if (t.dataset.fee) { refresh(t.dataset.fee); }
  });
})();
</script>
<?php
layout_end();
