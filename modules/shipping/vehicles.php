<?php
declare(strict_types=1);

$page = 'cgo.vehicles';
$vid = gint('voyage');
$v = $vid ? Ship::voyage($vid) : null;
if ($vid && !$v) {
    flash('warning', 'That voyage no longer exists.');
    redirect(url($page));
}

if (!function_exists('roro_vehicles')) {
    function roro_vehicles(int $voyageId): array
    {
        return DB::all("SELECT x.*, k.code AS kcode, k.name AS kname, k.lane_m, t.last_name, t.first_name, t.ticket_no, t.status AS driver_status, b.booking_ref
                          FROM shp_vehicles x JOIN shp_vehicle_classes k ON k.id = x.vehicle_class_id
                          LEFT JOIN shp_tickets t ON t.id = x.driver_ticket_id LEFT JOIN shp_bookings b ON b.id = x.booking_id
                         WHERE x.voyage_id = :v AND x.status NOT IN ('cancelled', 'refunded', 'transferred', 'expired')
                         ORDER BY x.lane_no IS NULL, x.lane_no, x.lane_position, x.id", ['v' => $voyageId]);
    }

    /** Lanes of the vessel and the meters each holds */
    function roro_lanes(array $v): array
    {
        $vessel = Ship::vessel((int)$v['vessel_id']);
        $n = max(0, (int)$vessel['vehicle_lanes']);
        $limit = Booking::laneLimit($v);
        return [$n, $n > 0 ? $limit / $n : 0.0];
    }

    function roro_on_deck(array $x): bool
    {
        return in_array($x['status'], ['held', 'issued', 'checked_in', 'loaded'], true);
    }
}

handle_post(function () use ($page, $v) {
    ship_require($page, 'edit');
    if (!$v) {
        fail('Choose the voyage.');
    }
    $act = (string)p('act');
    $back = url($page, ['voyage' => $v['id']]);
    if (in_array($v['status'], ['completed', 'cancelled'], true)) {
        fail('This voyage is ' . strtolower(strip_tags(Ship::label($v['status']))) . '; its loading list is closed.');
    }
    [$lanes, $laneLen] = roro_lanes($v);
    if ($act === 'lanes' || $act === 'auto') {
        if (in_array($v['status'], ['departed', 'arrived'], true)) {
            fail('Lanes are assigned before departure.');
        }
        if ($lanes < 1) {
            fail('The vessel has no vehicle lanes set in the Vessel Registry.');
        }
        $rows = roro_vehicles((int)$v['id']);
        $plan = [];
        if ($act === 'lanes') {
            foreach ($rows as $x) {
                $ln = $_POST['lane'][$x['id']] ?? null;
                if ($ln === null) {
                    continue;
                }
                $plan[(int)$x['id']] = ['lane_no' => $ln === '' ? null : (int)$ln, 'lane_position' => ($_POST['pos'][$x['id']] ?? '') === '' ? null : (int)$_POST['pos'][$x['id']],
                    'deck' => trim((string)($_POST['deck'][$x['id']] ?? '')) ?: null];
            }
        } else {
            // first fit: the longest vehicles first, each into the lane with the most room left; dangerous cargo goes to the last lane
            $used = array_fill(1, $lanes, 0.0);
            $pos = array_fill(1, $lanes, 0);
            foreach ($rows as $x) {
                if ($x['lane_no'] && roro_on_deck($x)) {
                    $used[(int)$x['lane_no']] = ($used[(int)$x['lane_no']] ?? 0) + (float)$x['lane_m'];
                    $pos[(int)$x['lane_no']] = max($pos[(int)$x['lane_no']] ?? 0, (int)$x['lane_position']);
                }
            }
            $todo = array_filter($rows, fn($x) => !$x['lane_no'] && roro_on_deck($x));
            usort($todo, fn($a, $b) => ((int)$b['hazardous'] <=> (int)$a['hazardous']) ?: ((float)$b['lane_m'] <=> (float)$a['lane_m']));
            foreach ($todo as $x) {
                $choices = $used;
                if ((int)$x['hazardous'] === 1) {
                    $choices = [$lanes => $used[$lanes]];
                }
                asort($choices);
                $best = (int)array_key_first($choices);
                if ($used[$best] + (float)$x['lane_m'] > $laneLen + 0.001) {
                    continue;
                }
                $used[$best] += (float)$x['lane_m'];
                $pos[$best]++;
                $plan[(int)$x['id']] = ['lane_no' => $best, 'lane_position' => $pos[$best], 'deck' => $x['deck'] ?: 'Main'];
            }
            if (!$plan) {
                fail('Every vehicle already has a lane, or none fits in the room left.');
            }
        }
        // the lane meters of each lane after the change
        $meters = [];
        $byId = array_column($rows, null, 'id');
        foreach ($rows as $x) {
            $ln = array_key_exists((int)$x['id'], $plan) ? $plan[(int)$x['id']]['lane_no'] : ($x['lane_no'] !== null ? (int)$x['lane_no'] : null);
            if ($ln !== null && roro_on_deck($x)) {
                if ($ln < 1 || $ln > $lanes) {
                    fail('Lane ' . $ln . ' does not exist: the vessel has lanes 1 to ' . $lanes . '.');
                }
                $meters[$ln] = ($meters[$ln] ?? 0) + (float)$x['lane_m'];
            }
        }
        foreach ($meters as $ln => $m) {
            if ($m > $laneLen + 0.001) {
                fail('Lane ' . $ln . ' would hold ' . qty($m) . ' m of vehicles; it is ' . qty($laneLen) . ' m long.');
            }
        }
        DB::tx(function () use ($plan, $byId, $v) {
            $n = 0;
            foreach ($plan as $xid => $d) {
                $x = $byId[$xid];
                if ((string)$x['lane_no'] === (string)$d['lane_no'] && (string)$x['lane_position'] === (string)$d['lane_position'] && (string)$x['deck'] === (string)$d['deck']) {
                    continue;
                }
                DB::update('shp_vehicles', $d, 'id = :id', ['id' => $xid]);
                $n++;
            }
            if ($n) {
                Ship::voyageEvent((int)$v['id'], 'note', 'Vehicle lanes assigned for ' . $n . ' vehicle(s) by ' . (Auth::user()['full_name'] ?? ''));
                Ship::audit('lanes', 'cgo', 'shp_voyage', (int)$v['id'], 'Lane plan of ' . $v['voyage_no'] . ' (' . $n . ' vehicles)', ['page_key' => 'cgo.vehicles']);
            }
        });
        flash('success', $act === 'auto' ? count($plan) . ' vehicle(s) placed in lanes.' : 'Lane plan saved.');
        redirect($back);
    }
    $x = DB::row('SELECT * FROM shp_vehicles WHERE id = :id AND voyage_id = :v', ['id' => pint('vehicle_id'), 'v' => $v['id']]);
    if (!$x) {
        fail('Vehicle not found on this voyage.');
    }
    switch ($act) {
        case 'checkin':
            if ($x['status'] !== 'issued') {
                fail('Only an issued (paid) vehicle pass is checked in.');
            }
            if (!in_array($v['status'], ['on_sale', 'boarding', 'ready'], true)) {
                fail('Check-in is open while the voyage is on sale or boarding.');
            }
            if ((int)$x['hazardous'] === 1 && p('haz_ok') !== '1') {
                fail('The vehicle declares dangerous cargo: confirm the declaration and the placards were checked.');
            }
            DB::update('shp_vehicles', ['status' => 'checked_in', 'restrictions_ack' => 1], 'id = :id', ['id' => $x['id']]);
            Ship::audit('checkin', 'cgo', 'shp_vehicle', (int)$x['id'], 'Vehicle ' . $x['plate_no'] . ' checked in for ' . $v['voyage_no'], ['page_key' => 'cgo.vehicles']);
            flash('success', $x['plate_no'] . ' checked in.');
            break;
        case 'load':
            if (!in_array($v['status'], ['boarding', 'ready'], true)) {
                fail('Vehicles are loaded once boarding of the voyage is open.');
            }
            // the same check as the gate scanner (paid pass, this voyage, the driver's ticket valid), logged as a scan
            $r = Boarding::scan((int)$v['id'], (string)$x['vehicle_no'], 'board', ['device_id' => 'loading-list']);
            if (empty($r['ok'])) {
                fail($r['message']);
            }
            flash('success', $x['plate_no'] . ': ' . $r['message']);
            break;
        case 'unload':
            if ($x['status'] !== 'loaded' || $v['status'] !== 'arrived') {
                fail('A vehicle is unloaded from a loaded deck after arrival.');
            }
            DB::update('shp_vehicles', ['status' => 'unloaded', 'unloaded_at' => Ship::now()], 'id = :id', ['id' => $x['id']]);
            flash('success', $x['plate_no'] . ' unloaded.');
            break;
        default:
            fail('Unknown action.');
    }
    redirect($back);
});

$canEdit = Access::can($page, 'edit');

// ------------------------------------------------------------------ choose a voyage
if (!$v) {
    $from = gdate('from', Ship::today());
    $to = gdate('to', date('Y-m-d', strtotime($from . ' +6 days')));
    $rows = DB::all("SELECT v.*, po.code origin_code, pd.code dest_code, s.name vessel_name, s.lane_meters, v.vehicle_quota_lm,
                            (SELECT COUNT(*) FROM shp_vehicles x WHERE x.voyage_id = v.id AND x.status IN ('held', 'issued', 'checked_in', 'loaded', 'unloaded')) n,
                            (SELECT COUNT(*) FROM shp_vehicles x WHERE x.voyage_id = v.id AND x.status IN ('loaded', 'unloaded')) loaded,
                            (SELECT COALESCE(SUM(k.lane_m), 0) FROM shp_vehicles x JOIN shp_vehicle_classes k ON k.id = x.vehicle_class_id WHERE x.voyage_id = v.id AND x.status IN ('held', 'issued', 'checked_in', 'loaded')) used
                       FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id
                       JOIN shp_vessels s ON s.id = v.vessel_id
                      WHERE s.lane_meters > 0 AND v.voyage_date BETWEEN :f AND :t AND v.status <> 'cancelled' ORDER BY v.etd", ['f' => $from, 't' => $to]);
    layout_start('RoRo Loading & Vehicle Passes');
    ?>
    <form class="toolbar mb-3" method="get"><input type="hidden" name="p" value="<?= h($page) ?>">
      <?= input_field('from', 'From', $from, ['type' => 'date', 'class' => '']) ?><?= input_field('to', 'To', $to, ['type' => 'date', 'class' => '']) ?>
      <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Show</button></div></form>
    <p class="small text-muted">Voyages of vessels with a vehicle deck. Open one for its loading list, the lane plan and the check-in of vehicles.</p>
    <div class="voyage-list">
    <?php foreach ($rows as $r):
        $limit = $r['vehicle_quota_lm'] !== null && (float)$r['vehicle_quota_lm'] > 0 ? min((float)$r['lane_meters'], (float)$r['vehicle_quota_lm']) : (float)$r['lane_meters'];
        $pct = $limit > 0 ? 100 * (float)$r['used'] / $limit : 0;
        echo voyage_card($r, url($page, ['voyage' => $r['id']]), '<div class="small"><b>' . (int)$r['n'] . '</b> vehicle(s), <b>' . (int)$r['loaded'] . '</b> loaded</div><div class="small text-muted">'
            . qty($r['used']) . ' of ' . qty($limit) . ' lane m</div><div style="min-width:150px">' . meter($pct, $pct > 95 ? 'bad' : ($pct > 80 ? 'warn' : '')) . '</div>');
    endforeach; ?>
    <?php if (!$rows): ?><?= empty_note('No RoRo voyage in these dates.', 'truck-front') ?><?php endif; ?>
    </div>
    <?php
    layout_end();
    return;
}

$rows = roro_vehicles((int)$v['id']);
[$lanes, $laneLen] = roro_lanes($v);
$deck = array_values(array_filter($rows, 'roro_on_deck'));
$usedTotal = array_sum(array_map(fn($x) => (float)$x['lane_m'], $deck));
$limit = Booking::laneLimit($v);
$byLane = [];
foreach ($deck as $x) {
    if ($x['lane_no']) {
        $byLane[(int)$x['lane_no']][] = $x;
    }
}
$unassigned = array_filter($deck, fn($x) => !$x['lane_no']);
$haz = array_filter($deck, fn($x) => (int)$x['hazardous'] === 1);
$cnt = fn(array $sts) => count(array_filter($rows, fn($x) => in_array($x['status'], $sts, true)));
$open = !in_array($v['status'], ['completed', 'cancelled', 'departed', 'arrived'], true);

// ------------------------------------------------------------------ printable loading list
if (g('print') === '1') {
    ship_require($page, 'print');
    layout_start('Vehicle loading list ' . $v['voyage_no'], ['bare' => true, 'no_title' => true]);
    ?>
    <div class="print-bar no-print"><button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button><?= btn_link(url($page, ['voyage' => $v['id']]), 'Back', 'btn-outline-secondary', 'arrow-left') ?></div>
    <div class="doc-sheet">
      <div class="doc-head"><div class="co"><?= h(Settings::get('company_name')) ?><small><?= h($v['vessel_name']) ?>, <?= h($v['origin_name']) ?> to <?= h($v['dest_name']) ?></small></div>
        <div class="doc-no"><span>Vehicle loading list</span><b><?= h($v['voyage_no']) ?></b><span>Departure <?= h(fdatetime($v['etd'])) ?></span></div></div>
      <p class="small mb-2"><?= count($deck) ?> vehicle(s), <?= qty($usedTotal) ?> of <?= qty($limit) ?> lane meters<?= $haz ? ', ' . count($haz) . ' with dangerous cargo declared' : '' ?>.</p>
      <table class="table table-sm"><thead><tr><th>Lane</th><th>Pos</th><th>Pass</th><th>Plate</th><th>Class</th><th>Make / model</th><th class="num">L m</th><th class="num">t</th><th>Driver</th><th>Cargo declared</th><th>Loaded</th></tr></thead><tbody>
      <?php foreach ($deck as $x): ?><tr><td><?= $x['lane_no'] ? (int)$x['lane_no'] : '-' ?></td><td><?= h((string)$x['lane_position']) ?></td><td><?= h((string)$x['vehicle_no']) ?></td><td class="fw-semibold"><?= h($x['plate_no']) ?></td>
        <td><?= h($x['kcode']) ?></td><td><?= h((string)$x['make_model']) ?></td><td class="num"><?= qty($x['length_m']) ?></td><td class="num"><?= qty($x['weight_t']) ?></td>
        <td><?= h($x['last_name'] ? Ship::maskName($x['last_name'], $x['first_name']) : (string)$x['operator_name']) ?></td><td><?= h((string)$x['declared_cargo']) ?><?= (int)$x['hazardous'] ? ' <b>DG: ' . h((string)$x['hazardous_desc']) . '</b>' : '' ?></td>
        <td><span class="ckbox <?= $x['status'] === 'loaded' ? 'on' : '' ?>"></span></td></tr><?php endforeach; ?>
      </tbody></table>
      <div class="sign-row"><div>Prepared by (cargo clerk)</div><div>Lashing checked (Chief Mate)</div><div>Approved for sailing (Master)</div></div>
    </div>
    <?php
    layout_end();
    return;
}

$actions = (Access::can($page, 'print') ? btn_link(url($page, ['voyage' => $v['id'], 'print' => 1]), 'Print loading list', 'btn-outline-secondary', 'printer') . ' ' : '')
    . (Access::page('ops.voyages') ? btn_link(url('ops.voyages', ['id' => $v['id'], 'tab' => 'loads']), 'Voyage', 'btn-outline-secondary', 'compass') . ' ' : '')
    . btn_link(url($page), 'Other voyages', 'btn-outline-secondary', 'list');
layout_start('Loading list ' . $v['voyage_no'], ['actions' => $actions]);
echo voyage_card($v, null, '<div class="small text-muted">' . h($v['vessel_name']) . '</div><div class="small">' . $lanes . ' lanes of ' . qty($laneLen) . ' m</div>');
echo '<div class="kpi-grid my-3">'
    . kpi_tile('Vehicles booked', (string)count($deck), 'truck-front', $cnt(['held']) . ' held unpaid', '', null, (float)count($deck))
    . kpi_tile('Checked in', (string)$cnt(['checked_in']), 'clipboard-check', 'Waiting to drive aboard', '', null, (float)$cnt(['checked_in']))
    . kpi_tile('Loaded', (string)$cnt(['loaded', 'unloaded']), 'box-arrow-in-down', $cnt(['unloaded']) . ' unloaded at arrival', 'good', null, (float)$cnt(['loaded', 'unloaded']))
    . kpi_tile('Lane meters', qty($usedTotal) . ' / ' . qty($limit), 'rulers', $limit > 0 ? round(100 * $usedTotal / $limit) . '% of the deck' : 'No vehicle deck', $usedTotal > $limit ? 'bad' : '')
    . kpi_tile('Dangerous cargo', (string)count($haz), 'exclamation-diamond', count($haz) ? 'Keep to the dangerous-goods lane' : 'None declared', count($haz) ? 'warn' : '')
    . '</div>';
?>
<div class="card reveal mb-3"><div class="card-header d-flex flex-wrap justify-content-between gap-2 align-items-center"><span>Deck plan</span>
  <?php if ($canEdit && $open && $lanes > 0 && $unassigned): ?><form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="act" value="auto"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-magic"></i> Place the <?= count($unassigned) ?> vehicle(s) without a lane</button></form><?php endif; ?></div>
  <div class="card-body">
  <?php if ($lanes < 1): ?><?= empty_note('The vessel has no vehicle lanes set in the Vessel Registry.', 'truck-front') ?>
  <?php else: ?>
    <div class="lane-deck">
    <?php for ($ln = 1; $ln <= $lanes; $ln++):
        $list = $byLane[$ln] ?? [];
        $m = array_sum(array_map(fn($x) => (float)$x['lane_m'], $list)); ?>
      <div class="lane-row"><div class="lname">Lane <?= $ln ?></div>
        <div class="lane-bar <?= $m > $laneLen + .001 ? 'over' : '' ?>">
        <?php foreach ($list as $i => $x): $w = $laneLen > 0 ? max(4, 100 * (float)$x['lane_m'] / $laneLen) : 0; ?>
          <span class="veh <?= $x['status'] === 'loaded' ? 'loaded' : '' ?> <?= (int)$x['hazardous'] ? 'haz' : '' ?>" style="width:calc(<?= round($w, 2) ?>% - 2px);animation-delay:<?= $i * 40 ?>ms" title="<?= h($x['plate_no'] . ', ' . $x['kname'] . ', ' . qty($x['lane_m']) . ' m') ?>"><?= h($x['plate_no']) ?></span>
        <?php endforeach; ?>
        </div><div class="lused"><?= qty($m) ?> / <?= qty($laneLen) ?> m</div></div>
    <?php endfor; ?>
    </div>
    <div class="lane-legend mt-2"><span><i style="background:#1d6fa5"></i>Booked or checked in</span><span><i style="background:#2f8a57"></i>Loaded</span><span><i style="background:#ef8a2b"></i>Dangerous cargo</span><?php if ($unassigned): ?><span class="text-warning-emphasis"><?= count($unassigned) ?> vehicle(s) without a lane yet</span><?php endif; ?></div>
  <?php endif; ?>
  </div></div>

<form method="post" class="card reveal"><?= csrf_field() ?><input type="hidden" name="act" value="lanes">
  <div class="card-header d-flex justify-content-between"><span>Vehicles (<?= count($rows) ?>)</span><span class="small text-muted">Lane meters by class: the space a vehicle takes with its gap</span></div>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 grid-input">
  <thead><tr><th>Pass</th><th>Plate</th><th>Class</th><th class="num">Length</th><th class="num">Weight</th><th>Driver</th><th>Cargo</th><th>Deck</th><th>Lane</th><th>Pos</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $x):
      $editable = $canEdit && $open && roro_on_deck($x) && $lanes > 0; ?>
    <tr><td class="small text-nowrap"><?= h($x['vehicle_no'] ?: '(unpaid)') ?><div class="text-muted"><?= h((string)$x['booking_ref']) ?></div></td>
      <td class="fw-semibold text-nowrap"><?= h($x['plate_no']) ?><div class="small text-muted fw-normal"><?= h((string)$x['make_model']) ?></div></td>
      <td class="small"><?= h($x['kname']) ?><div class="text-muted"><?= qty($x['lane_m']) ?> lane m</div></td><td class="num"><?= qty($x['length_m']) ?> m</td><td class="num"><?= qty($x['weight_t']) ?> t</td>
      <td class="small"><?= $x['last_name'] ? h(Ship::canPii() ? $x['last_name'] . ', ' . $x['first_name'] : Ship::maskName($x['last_name'], $x['first_name'])) . '<div class="text-muted text-nowrap">' . h((string)$x['ticket_no']) . '</div>' : h((string)$x['operator_name']) ?></td>
      <td class="small"><?= h((string)$x['declared_cargo']) ?><?= (int)$x['hazardous'] ? '<div><span class="st st-bad">Dangerous</span> ' . h((string)$x['hazardous_desc']) . '</div>' : '' ?></td>
      <?php if ($editable): ?>
        <td><input class="form-control form-control-sm" style="width:84px" name="deck[<?= (int)$x['id'] ?>]" value="<?= h((string)$x['deck']) ?>" placeholder="Main"></td>
        <td><select class="form-select form-select-sm" style="width:76px" name="lane[<?= (int)$x['id'] ?>]" data-no-combo><option value=""></option><?php for ($ln = 1; $ln <= $lanes; $ln++): ?><option <?= (int)$x['lane_no'] === $ln ? 'selected' : '' ?>><?= $ln ?></option><?php endfor; ?></select></td>
        <td><input class="form-control form-control-sm" style="width:62px" type="number" min="1" name="pos[<?= (int)$x['id'] ?>]" value="<?= h((string)$x['lane_position']) ?>"></td>
      <?php else: ?>
        <td><?= h((string)$x['deck']) ?></td><td><?= h((string)$x['lane_no']) ?></td><td><?= h((string)$x['lane_position']) ?></td>
      <?php endif; ?>
      <td><?= Ship::label($x['status']) ?></td>
      <td class="text-nowrap">
      <?php if ($canEdit && $x['status'] === 'issued' && in_array($v['status'], ['on_sale', 'boarding', 'ready'], true)): ?>
        <button class="btn btn-sm btn-outline-primary" form="vx<?= (int)$x['id'] ?>" name="act" value="checkin" <?= (int)$x['hazardous'] ? 'data-confirm="Dangerous cargo declared: were the declaration and the placards checked?"' : '' ?>>Check in</button>
      <?php endif; ?>
      <?php if ($canEdit && in_array($x['status'], ['issued', 'checked_in'], true) && in_array($v['status'], ['boarding', 'ready'], true)): ?>
        <button class="btn btn-sm btn-primary" form="vx<?= (int)$x['id'] ?>" name="act" value="load">Load</button>
      <?php endif; ?>
      <?php if ($canEdit && $x['status'] === 'loaded' && $v['status'] === 'arrived'): ?>
        <button class="btn btn-sm btn-outline-primary" form="vx<?= (int)$x['id'] ?>" name="act" value="unload">Unload</button>
      <?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="12"><?= empty_note('No vehicle booked on this voyage.', 'truck-front') ?></td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($canEdit && $open && $lanes > 0 && $deck): ?><div class="card-footer bg-transparent d-flex flex-wrap gap-2 align-items-center"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Save lane plan</button>
    <span class="small text-muted">A lane never takes more vehicles than its length; loading at the gate uses the same check as the boarding scanner.</span></div><?php endif; ?>
</form>
<?php foreach ($rows as $x): ?><form method="post" id="vx<?= (int)$x['id'] ?>" class="d-none"><?= csrf_field() ?><input type="hidden" name="vehicle_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="haz_ok" value="1"></form><?php endforeach; ?>
<?php
layout_end();
