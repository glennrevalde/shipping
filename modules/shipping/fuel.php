<?php
declare(strict_types=1);

$page = 'ops.fuel';
$vesselF = gint('vessel');
$voyageQ = gint('voyage');
$canCreate = Access::can($page, 'create');
$canIssue = $canCreate || Access::can($page, 'edit');

handle_post(function () use ($page, $canCreate, $canIssue) {
    $act = (string)p('act');
    if ($act === 'bunker') {
        if (!$canCreate) {
            Access::denyPage($page, 'record bunkering on');
        }
        $vendor = (int)pint('vendor_id');
        if (!$vendor) {
            fail('Choose the fuel supplier.');
        }
        $date = pdate('date') ?? Ship::today();
        Fuel::bunker((int)pint('vessel_id'), pnum('liters'), pnum('unit_cost'), $vendor, (string)p('ref_no'), $date, (string)p('notes'));
        flash('success', 'Bunkering received into the vessel\'s tanks and billed to the supplier.');
        redirect(url($page, ['vessel' => pint('vessel_id')]));
    }
    if ($act === 'issue') {
        if (!$canIssue) {
            Access::denyPage($page, 'record fuel used on');
        }
        $r = Fuel::issue((int)pint('voyage_id'), pnum('rob_departure'), pnum('rob_arrival'), (string)p('notes'));
        flash('success', 'Fuel used recorded: ' . qty($r['liters']) . ' L costing ₱' . money($r['cost']) . ($r['planned'] !== null ? ', against ' . qty($r['planned']) . ' L planned' : '') . '.');
        redirect(url($page, ['voyage' => pint('voyage_id')]));
    }
    if ($act === 'sounding') {
        if (!$canCreate) {
            Access::denyPage($page, 'record soundings on');
        }
        if (trim((string)p('measured')) === '') {
            fail('Enter the liters measured in the tanks.');
        }
        $at = p('log_at') ? date('Y-m-d H:i:s', strtotime((string)p('log_at'))) : Ship::now();
        $lid = DB::tx(fn() => Fuel::sounding((int)pint('vessel_id'), pnum('measured'), $at, (string)p('notes')));
        $st = (string)DB::value('SELECT status FROM shp_fuel_logs WHERE id = :id', ['id' => $lid]);
        flash($st === 'pending' ? 'info' : 'success', $st === 'pending' ? 'The sounding differs from the books. The adjustment waits for another person\'s approval before the stock moves.' : 'Sounding recorded; it agrees with the books.');
        redirect(url($page, ['vessel' => pint('vessel_id')]));
    }
    fail('Unknown action.');
});

$vessels = DB::all("SELECT s.*, i.name fuel_name, i.avg_cost FROM shp_vessels s LEFT JOIN items i ON i.id = s.fuel_item_id WHERE s.status <> 'retired' ORDER BY s.name");
$pending = DB::all("SELECT l.*, s.name vessel_name, (SELECT a.id FROM shp_approvals a WHERE a.ref_table = 'shp_fuel_logs' AND a.ref_id = l.id AND a.status = 'pending' LIMIT 1) approval_id
                      FROM shp_fuel_logs l JOIN shp_vessels s ON s.id = l.vessel_id WHERE l.status = 'pending' ORDER BY l.log_at");
$toIssue = DB::pairs("SELECT v.id, CONCAT(v.voyage_no, ' ', po.code, '-', pd.code, ', ', s.name, ', ', DATE_FORMAT(v.etd, '%b %e')) FROM shp_voyages v
                        JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id JOIN shp_vessels s ON s.id = v.vessel_id
                       WHERE v.status IN ('departed', 'arrived', 'completed') AND v.etd >= :f AND s.fuel_item_id IS NOT NULL
                         AND NOT EXISTS (SELECT 1 FROM shp_fuel_logs l WHERE l.voyage_id = v.id AND l.log_type = 'issue' AND l.status = 'posted') ORDER BY v.etd",
    ['f' => date('Y-m-d', strtotime(Ship::today() . ' -45 days'))]);
$issueV = $voyageQ ? Ship::voyage($voyageQ) : null;
$posted = $issueV ? DB::row("SELECT * FROM shp_fuel_logs WHERE voyage_id = :v AND log_type = 'issue' AND status = 'posted'", ['v' => $voyageQ]) : null;
$from = gdate('from', date('Y-m-d', strtotime(Ship::today() . ' -30 days')));
$to = gdate('to', Ship::today());
$where = 'DATE(l.log_at) BETWEEN :f AND :t';
$par = ['f' => $from, 't' => $to];
if ($vesselF) {
    $where .= ' AND l.vessel_id = :vs';
    $par['vs'] = $vesselF;
}
if ($voyageQ) {
    $where = 'l.voyage_id = :vq';
    $par = ['vq' => $voyageQ];
}
$log = DB::all("SELECT l.*, s.name vessel_name, v.voyage_no, vd.name vendor_name FROM shp_fuel_logs l JOIN shp_vessels s ON s.id = l.vessel_id
                  LEFT JOIN shp_voyages v ON v.id = l.voyage_id LEFT JOIN vendors vd ON vd.id = l.vendor_id WHERE $where ORDER BY l.log_at DESC, l.id DESC LIMIT 400", $par);
$typeNames = ['bunkering' => 'Bunkering', 'issue' => 'Used on voyage', 'sounding' => 'Sounding', 'adjustment' => 'Sounding adjustment'];
$fuelVendors = DB::pairs("SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name LIKE '%fuel%' DESC, name");
layout_start('Fuel & Bunkering');
?>
<div class="row g-3 mb-3">
<?php foreach ($vessels as $s):
    $rob = Fuel::rob((int)$s['id']);
    $cap = (float)$s['fuel_tank_l'];
    $pct = $cap > 0 ? max(0, min(100, 100 * $rob / $cap)) : 0;
    $tone = $pct < 25 ? 'bad' : ($pct < 40 ? 'warn' : '');
    $hours = (float)$s['fuel_rate_lph'] > 0 ? $rob / (float)$s['fuel_rate_lph'] : null; ?>
  <div class="col-md-6 col-xl-4"><a class="card reveal h-100 fuel-card text-reset text-decoration-none <?= $vesselF === (int)$s['id'] ? 'border-primary' : '' ?>" href="<?= h(url($page, ['vessel' => $s['id']])) ?>"><div class="card-body d-flex gap-3 align-items-center">
    <div class="tank <?= $tone ?>" style="--lvl:<?= round($pct, 1) ?>%" role="img" aria-label="Tanks <?= round($pct) ?>% full"><span class="liquid"><i></i></span><b><?= round($pct) ?>%</b></div>
    <div class="flex-fill"><div class="fw-bold"><?= h($s['name']) ?></div><div class="small text-muted"><?= h((string)($s['fuel_name'] ?: 'No fuel item set')) ?></div>
      <div class="money-big mt-1"><span data-count="<?= h((string)round($rob)) ?>"><?= number_format($rob) ?></span> L</div>
      <div class="small">on board of <?= number_format($cap) ?> L<?= $hours !== null ? ', about ' . number_format($hours, 0) . ' h of steaming' : '' ?></div>
      <?php if ($tone === 'bad'): ?><div class="small st st-bad mt-1">Bunker before the next long passage</div><?php endif; ?>
    </div></div></a></div>
<?php endforeach; ?>
</div>

<?php if ($pending): ?>
<div class="card reveal mb-3 border-warning"><div class="card-header">Sounding adjustments waiting for approval</div><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Vessel</th><th>Sounded</th><th class="num">Books</th><th class="num">Measured</th><th class="num">Difference</th><th>Notes</th><th></th></tr></thead><tbody>
  <?php foreach ($pending as $l): ?><tr><td><?= h($l['vessel_name']) ?></td><td><?= h(fdatetime($l['log_at'])) ?></td><td class="num"><?= qty($l['rob_book']) ?> L</td><td class="num"><?= qty($l['rob_sounding']) ?> L</td>
    <td class="num <?= (float)$l['variance_l'] < 0 ? 'text-danger' : '' ?>"><?= ((float)$l['variance_l'] > 0 ? '+' : '') . qty($l['variance_l']) ?> L</td><td class="small"><?= h((string)$l['notes']) ?></td>
    <td><?= $l['approval_id'] && Access::page('ops.approvals') ? '<a class="small" href="' . h(url('ops.approvals', ['id' => $l['approval_id'], 'tab' => 'all'])) . '">Decide in the queue</a>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <?php if ($canIssue): ?>
  <div class="col-lg-4"><form method="post" class="card reveal h-100" id="issue"><?= csrf_field() ?><input type="hidden" name="act" value="issue"><div class="card-header">Fuel used on a voyage</div><div class="card-body">
    <?php if ($posted): ?>
      <p class="small mb-2">Recorded for <?= h($issueV['voyage_no']) ?>: <b><?= qty(-(float)$posted['liters']) ?> L</b>, ₱<?= money($posted['amount']) ?> <?= txn_doc_link($posted['txn_id'] ? (int)$posted['txn_id'] : null) ?>.</p>
    <?php endif; ?>
    <div class="row g-2">
      <?= select_field('voyage_id', 'Voyage (departed, fuel not yet recorded)', $toIssue, $voyageQ, ['class' => 'col-12', 'blank' => $toIssue ? '' : 'Every sailed voyage has its fuel recorded', 'required' => true]) ?>
      <?= input_field('rob_departure', 'ROB at departure (L)', $issueV && !$posted ? (string)($issueV['fuel_rob_departure'] ?? '') : p('rob_departure'), ['type' => 'number', 'class' => 'col-6', 'required' => true]) ?>
      <?= input_field('rob_arrival', 'ROB at arrival (L)', $issueV && !$posted ? (string)($issueV['fuel_rob_arrival'] ?? '') : p('rob_arrival'), ['type' => 'number', 'class' => 'col-6', 'required' => true]) ?>
      <?= input_field('notes', 'Notes', p('notes'), ['class' => 'col-12']) ?>
    </div>
    <p class="small text-muted mt-2 mb-2">The liters burnt leave the vessel's tank stock at average cost and are charged to Voyage Fuel Expense for this voyage. A difference from the planned burn above <?= h(Ship::setting('fuel_variance_pct', '2')) ?>% is flagged.</p>
    <button class="btn btn-primary btn-sm" <?= $toIssue ? '' : 'disabled' ?>>Record fuel used</button>
  </div></form></div>
  <?php endif; ?>
  <?php if ($canCreate): ?>
  <div class="col-lg-4"><form method="post" class="card reveal h-100"><?= csrf_field() ?><input type="hidden" name="act" value="bunker"><div class="card-header">Bunkering received</div><div class="card-body">
    <div class="row g-2">
      <?= select_field('vessel_id', 'Vessel', Ship::vesselOptions(), $vesselF ?: p('vessel_id'), ['class' => 'col-12', 'blank' => '', 'required' => true]) ?>
      <?= select_field('vendor_id', 'Supplier', $fuelVendors, p('vendor_id'), ['class' => 'col-12', 'blank' => '', 'required' => true]) ?>
      <?= input_field('liters', 'Liters delivered', p('liters'), ['type' => 'number', 'class' => 'col-6', 'required' => true]) ?>
      <?= input_field('unit_cost', 'Price per liter (before VAT)', p('unit_cost'), ['type' => 'number', 'class' => 'col-6', 'required' => true, 'step' => '0.0001']) ?>
      <?= input_field('ref_no', 'Delivery receipt / invoice no.', p('ref_no'), ['class' => 'col-6', 'required' => true]) ?>
      <?= input_field('date', 'Date', p('date', Ship::today()), ['type' => 'date', 'class' => 'col-6']) ?>
    </div>
    <p class="small text-muted mt-2 mb-2">Posted as a bill to the supplier with 12% input VAT; the liters go into the vessel's tank stock.</p>
    <button class="btn btn-primary btn-sm">Receive bunkers</button>
  </div></form></div>
  <div class="col-lg-4"><form method="post" class="card reveal h-100"><?= csrf_field() ?><input type="hidden" name="act" value="sounding"><div class="card-header">Tank sounding</div><div class="card-body">
    <div class="row g-2">
      <?= select_field('vessel_id', 'Vessel', Ship::vesselOptions(), $vesselF ?: p('vessel_id'), ['class' => 'col-12', 'blank' => '', 'required' => true]) ?>
      <?= input_field('measured', 'Liters measured', p('measured'), ['type' => 'number', 'class' => 'col-6', 'required' => true]) ?>
      <?= input_field('log_at', 'Sounded at', p('log_at', date('Y-m-d\TH:i')), ['type' => 'datetime-local', 'class' => 'col-6']) ?>
      <?= input_field('notes', 'Tank and method', p('notes'), ['class' => 'col-12', 'extra' => 'placeholder="All tanks, sounding tape, trim corrected"']) ?>
    </div>
    <p class="small text-muted mt-2 mb-2">A difference of half a liter or more becomes an adjustment that another person approves; the tank stock never goes below zero.</p>
    <button class="btn btn-outline-primary btn-sm">Record sounding</button>
  </div></form></div>
  <?php endif; ?>
</div>

<form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>">
  <?= select_field('vessel', 'Vessel', Ship::vesselOptions(false), $vesselF, ['class' => '', 'blank' => 'All vessels']) ?>
  <?= input_field('from', 'From', $from, ['type' => 'date', 'class' => '']) ?><?= input_field('to', 'To', $to, ['type' => 'date', 'class' => '']) ?>
  <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Show</button></div>
  <?php if ($voyageQ): ?><div class="small text-muted align-self-center">Showing voyage <?= h((string)($issueV['voyage_no'] ?? '')) ?> only. <a href="<?= h(url($page)) ?>">Show all</a></div><?php endif; ?>
</form>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>When</th><th>Vessel</th><th>Entry</th><th>Voyage / supplier</th><th class="num">Liters</th><th class="num">ROB (books)</th><th class="num">Sounding</th><th class="num">Variance</th><th class="num">Per liter</th><th class="num">Amount</th><th>Document</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($log as $l): $lit = (float)$l['liters']; ?>
    <tr><td class="text-nowrap small"><?= h(fdatetime($l['log_at'])) ?></td><td class="text-nowrap"><?= h($l['vessel_name']) ?></td><td><?= h($typeNames[$l['log_type']] ?? $l['log_type']) ?></td>
      <td class="small"><?= $l['voyage_no'] ? safe_link('ops.voyages', ['id' => $l['voyage_id'], 'tab' => 'costs'], $l['voyage_no']) : h(trim(($l['vendor_name'] ?? '') . ' ' . ($l['ref_no'] ?? ''))) ?></td>
      <td class="num <?= $lit < 0 ? 'text-danger' : '' ?>"><?= ($lit > 0 ? '+' : '') . qty($lit) ?></td><td class="num"><?= $l['rob_book'] !== null ? qty($l['rob_book']) : '' ?></td>
      <td class="num"><?= $l['rob_sounding'] !== null ? qty($l['rob_sounding']) : '' ?></td><td class="num"><?= $l['variance_l'] !== null ? ((float)$l['variance_l'] > 0 ? '+' : '') . qty($l['variance_l']) : '' ?></td>
      <td class="num"><?= (float)$l['unit_cost'] ? number_format((float)$l['unit_cost'], 2) : '' ?></td><td class="num"><?= money($l['amount'], true) ?></td>
      <td><?= txn_doc_link($l['txn_id'] ? (int)$l['txn_id'] : null) ?></td><td><?= $l['status'] === 'posted' ? '<span class="st st-good">Posted</span>' : Ship::label($l['status'], ['pending' => 'For approval', 'rejected' => 'Rejected']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$log): ?><tr><td colspan="12"><?= empty_note('No fuel entry in these dates.', 'fuel-pump') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php
layout_end();
