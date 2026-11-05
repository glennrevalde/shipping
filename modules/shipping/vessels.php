<?php
declare(strict_types=1);

$page = 'fleet.vessels';
$id = gint('id');
$a = (string)g('a');
$v = $id ? DB::row('SELECT * FROM shp_vessels WHERE id = :id', ['id' => $id]) : null;
$fuelItems = DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM items WHERE item_type = 'inventory' AND unit = 'L' AND is_active = 1 ORDER BY code");

handle_post(function () use ($page, $v) {
    if (p('save_plan') !== '' && $v) {
        ship_require($page, 'edit');
        $rows = [];
        foreach ((array)($_POST['ac_code'] ?? []) as $i => $code) {
            $rows[] = ['code' => $code, 'name' => $_POST['ac_name'][$i] ?? '', 'kind' => $_POST['ac_kind'][$i] ?? 'seat', 'deck' => $_POST['ac_deck'][$i] ?? '',
                'capacity' => $_POST['ac_capacity'][$i] ?? 0, 'seat_rows' => $_POST['ac_rows'][$i] ?? 0, 'seat_cols' => $_POST['ac_cols'][$i] ?? 0,
                'priority_seats' => $_POST['ac_priority'][$i] ?? 0, 'reserved_seating' => ($_POST['ac_reserved'][$i] ?? '0') === '1'];
        }
        Fleet::saveAccommodation((int)$v['id'], $rows);
        flash('success', 'Accommodation plan saved and seats generated.');
        redirect(url($page, ['id' => $v['id']]));
    }
    ship_require($page, $v ? 'edit' : 'create');
    $d = [];
    foreach (Fleet::FIELDS as $k) {
        if (array_key_exists($k, $_POST)) {
            $d[$k] = trim((string)$_POST[$k]);
        }
    }
    $newId = Fleet::save($d, $v ? (int)$v['id'] : null);
    flash('success', 'Vessel saved.' . ($v ? '' : ' Its accounting class and its tanks-and-stores site were created; now set its accommodation plan.'));
    redirect(url($page, ['id' => $newId]));
});

$canEdit = Access::can($page, 'edit');
if ($a === 'new' || $a === 'edit' || (is_post() && !p('save_plan'))) {
    $e = $a === 'edit' ? $v : null;
    layout_start($e ? 'Edit vessel ' . $e['name'] : 'New vessel');
    $f = fn(string $k, string $label, string $type = 'text', string $cls = 'col-md-3', bool $req = false) => input_field($k, $label, fv($k, $e), ['type' => $type, 'class' => $cls, 'required' => $req, 'step' => '0.01']);
    ?>
    <form method="post" class="card reveal"><?= csrf_field() ?><div class="card-body"><div class="row g-3">
      <?= $f('code', 'Vessel code', 'text', 'col-md-2', true) ?><?= $f('name', 'Vessel name', 'text', 'col-md-4', true) ?>
      <?= select_field('vessel_type', 'Type', Ship::VESSEL_TYPES, fv('vessel_type', $e, 'ropax'), ['class' => 'col-md-3']) ?>
      <?= select_field('status', 'Status', ['active' => 'In service', 'drydock' => 'In dry-dock', 'laid_up' => 'Laid up', 'retired' => 'Retired / sold'], fv('status', $e, 'active'), ['class' => 'col-md-3']) ?>
      <?= $f('official_no', 'Official number') ?><?= $f('imo_no', 'IMO number (if any)') ?><?= $f('call_sign', 'Call sign') ?><?= $f('classification', 'Classification / class society') ?>
      <?= $f('owner_operator', 'Owner / operator', 'text', 'col-md-4') ?><?= $f('year_built', 'Year built', 'number', 'col-md-2') ?>
      <?= select_field('home_port_id', 'Home port', Ship::portOptions(), fv('home_port_id', $e), ['class' => 'col-md-3', 'blank' => '']) ?>
      <?= $f('service_speed_kn', 'Service speed (knots)', 'number') ?>
      <div class="col-12"><h6 class="mb-0 mt-2">Dimensions and capacity</h6></div>
      <?= $f('gross_tonnage', 'Gross tonnage', 'number', 'col-md-2') ?><?= $f('net_tonnage', 'Net tonnage', 'number', 'col-md-2') ?><?= $f('deadweight_t', 'Deadweight (t)', 'number', 'col-md-2') ?>
      <?= $f('length_m', 'Length (m)', 'number', 'col-md-2') ?><?= $f('breadth_m', 'Breadth (m)', 'number', 'col-md-2') ?>
      <?= $f('pax_capacity', 'Approved passenger limit', 'number', 'col-md-2', true) ?><?= $f('crew_complement', 'Crew complement', 'number', 'col-md-2') ?>
      <?= $f('lane_meters', 'Vehicle lane meters', 'number', 'col-md-2') ?><?= $f('vehicle_lanes', 'Vehicle lanes', 'number', 'col-md-2') ?>
      <?= $f('max_vehicle_length_m', 'Max vehicle length (m)', 'number', 'col-md-2') ?><?= $f('max_vehicle_weight_t', 'Max vehicle weight (t)', 'number', 'col-md-2') ?>
      <?= $f('cargo_capacity_t', 'Cargo capacity (t)', 'number', 'col-md-2') ?><?= $f('cargo_capacity_cbm', 'Cargo capacity (cbm)', 'number', 'col-md-2') ?>
      <div class="col-12"><h6 class="mb-0 mt-2">Machinery and fuel</h6></div>
      <?= $f('fuel_type', 'Fuel type', 'text', 'col-md-3') ?>
      <?= select_field('fuel_item_id', 'Fuel item (stock, in liters)', $fuelItems, fv('fuel_item_id', $e), ['class' => 'col-md-3', 'blank' => '']) ?>
      <?= $f('fuel_tank_l', 'Fuel tank capacity (L)', 'number') ?><?= $f('fuel_rate_lph', 'Planned consumption (L per sailing hour)', 'number') ?>
      <?= $f('notes', 'Notes', 'text', 'col-md-12') ?>
    </div></div><div class="card-footer bg-transparent"><?= save_buttons('Save vessel', $e ? url($page, ['id' => $e['id']]) : url($page), false) ?></div></form>
    <?php
    layout_end();
    return;
}

if ($v) {
    Holds::syncCertificates((int)$v['id']);
    $plan = Fleet::accommodation((int)$v['id']);
    $holds = Holds::active((int)$v['id']);
    $actions = ($canEdit ? btn_link(url($page, ['a' => 'edit', 'id' => $v['id']]), 'Edit particulars', 'btn-outline-primary', 'pencil') : '')
        . ' ' . btn_link(url('fleet.certificates', ['vessel' => $v['id']]), 'Certificates', 'btn-outline-secondary', 'patch-check')
        . ' ' . btn_link(url('fleet.equipment', ['vessel' => $v['id']]), 'Equipment', 'btn-outline-secondary', 'gear-wide-connected');
    layout_start($v['name'] . ' (' . $v['code'] . ')', ['actions' => $actions]);
    $rob = Fuel::rob((int)$v['id']);
    echo '<div class="kpi-grid mb-3">'
        . kpi_tile('Status', Ship::VESSEL_TYPES[$v['vessel_type']] ?? $v['vessel_type'], 'water', Ship::label($v['status'], ['active' => 'In service', 'drydock' => 'In dry-dock', 'laid_up' => 'Laid up', 'retired' => 'Retired']))
        . kpi_tile('Passenger limit', number_format((int)$v['pax_capacity']), 'people', count($plan) . ' accommodation class(es)', '', null, (float)$v['pax_capacity'])
        . kpi_tile('Vehicle deck', qty($v['lane_meters']) . ' lane m', 'truck-front', (int)$v['vehicle_lanes'] . ' lanes, max ' . qty($v['max_vehicle_weight_t']) . ' t')
        . kpi_tile('Fuel on board', number_format($rob) . ' L', 'fuel-pump', 'Tanks ' . number_format((float)$v['fuel_tank_l']) . ' L', $v['fuel_tank_l'] > 0 && $rob < (float)$v['fuel_tank_l'] * .25 ? 'warn' : '', url('ops.fuel', ['vessel' => $v['id']]), $rob)
        . kpi_tile('Safety holds', (string)count($holds), 'shield-exclamation', $holds ? h($holds[0]['reason']) : 'None active', $holds ? 'bad' : 'good', url('ops.holds'))
        . '</div>';
    ?>
    <div class="row g-3">
      <div class="col-xl-5"><div class="card reveal h-100"><div class="card-header">Particulars</div><div class="card-body"><div class="info-grid">
        <?= info_pair('Official no.', $v['official_no'] ?: '-') ?><?= info_pair('IMO no.', $v['imo_no'] ?: '-') ?><?= info_pair('Call sign', $v['call_sign'] ?: '-') ?>
        <?= info_pair('Classification', $v['classification'] ?: '-') ?><?= info_pair('Owner / operator', $v['owner_operator'] ?: '-') ?><?= info_pair('Built', $v['year_built'] ?: '-') ?>
        <?= info_pair('Gross / net tonnage', qty($v['gross_tonnage']) . ' / ' . qty($v['net_tonnage'])) ?><?= info_pair('Deadweight', qty($v['deadweight_t']) . ' t') ?>
        <?= info_pair('Length x breadth', qty($v['length_m']) . ' x ' . qty($v['breadth_m']) . ' m') ?><?= info_pair('Speed', qty($v['service_speed_kn']) . ' kn') ?>
        <?= info_pair('Crew complement', (int)$v['crew_complement']) ?><?= info_pair('Cargo', qty($v['cargo_capacity_t']) . ' t / ' . qty($v['cargo_capacity_cbm']) . ' cbm') ?>
        <?= info_pair('Fuel', ($v['fuel_type'] ?: '-') . ', ' . qty($v['fuel_rate_lph']) . ' L/h planned') ?>
        <?= info_pair('Accounting class', Classes::name($v['class_id'])) ?><?= info_pair('Stores site', (string)DB::value('SELECT name FROM sites WHERE id = :s', ['s' => $v['site_id']])) ?>
      </div></div></div></div>
      <div class="col-xl-7"><div class="card reveal h-100"><div class="card-header">Approved accommodation plan</div><div class="card-body">
        <form method="post"><?= csrf_field() ?><fieldset <?= $canEdit ? '' : 'disabled' ?>>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Code</th><th>Name</th><th>Kind</th><th>Deck</th><th>Reserved seats</th><th>Rows</th><th>Per row</th><th>Capacity</th><th>Priority</th></tr></thead><tbody>
        <?php foreach (array_merge($plan, [['code' => '', 'name' => '', 'kind' => 'seat', 'deck' => '', 'reserved_seating' => 1, 'seat_rows' => '', 'seat_cols' => '', 'capacity' => '', 'priority_seats' => '']]) as $c): ?>
          <tr><td><input class="form-control form-control-sm" style="width:5rem" name="ac_code[]" value="<?= h($c['code']) ?>"></td><td><input class="form-control form-control-sm" name="ac_name[]" value="<?= h($c['name']) ?>"></td>
            <td><select class="form-select form-select-sm" name="ac_kind[]"><?= options(['seat' => 'Seats', 'bunk' => 'Bunks', 'cabin' => 'Cabins', 'open' => 'Open deck'], $c['kind']) ?></select></td>
            <td><input class="form-control form-control-sm" style="width:6rem" name="ac_deck[]" value="<?= h($c['deck'] ?? '') ?>"></td>
            <td><select class="form-select form-select-sm" name="ac_reserved[]"><?= options(['1' => 'Yes', '0' => 'Open seating'], (string)$c['reserved_seating']) ?></select></td>
            <td><input class="form-control form-control-sm" style="width:4.5rem" type="number" name="ac_rows[]" value="<?= h($c['seat_rows']) ?>"></td>
            <td><input class="form-control form-control-sm" style="width:4.5rem" type="number" name="ac_cols[]" value="<?= h($c['seat_cols']) ?>"></td>
            <td><input class="form-control form-control-sm" style="width:5rem" type="number" name="ac_capacity[]" value="<?= h($c['capacity']) ?>"></td>
            <td><input class="form-control form-control-sm" style="width:4.5rem" type="number" name="ac_priority[]" value="<?= h($c['priority_seats']) ?>"></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="form-text mb-2">Reserved seating: the capacity is rows x seats per row and each seat is numbered. The first priority seats of a class are kept for seniors and PWD first. Total: <?= (int)array_sum(array_column($plan, 'capacity')) ?> places.</div>
        <?php if ($canEdit): ?><button class="btn btn-primary btn-sm" name="save_plan" value="1">Save plan and generate seats</button><?php endif; ?>
        </fieldset></form>
      </div></div></div>
      <div class="col-12"><div class="card reveal"><div class="card-header">Recent and coming voyages</div><div class="card-body"><div class="voyage-list">
      <?php
      $vs = DB::all('SELECT v.*, po.code origin_code, pd.code dest_code, s.name vessel_name FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id
                     JOIN shp_ports pd ON pd.id = r.dest_port_id JOIN shp_vessels s ON s.id = v.vessel_id WHERE v.vessel_id = :v AND v.etd >= :f ORDER BY v.etd LIMIT 8', ['v' => $v['id'], 'f' => date('Y-m-d', strtotime(Ship::today() . ' -3 days'))]);
      foreach ($vs as $x) {
          echo voyage_card($x, Access::page('ops.voyages') ? url('ops.voyages', ['id' => $x['id']]) : null);
      }
      if (!$vs) {
          echo empty_note('No voyage scheduled around today.');
      }
      ?>
      </div></div></div></div>
    </div>
    <?php
    layout_end();
    return;
}

layout_start('Vessel Registry', ['actions' => Access::can($page, 'create') ? btn_link(url($page, ['a' => 'new']), 'New vessel', 'btn-primary', 'plus-lg') : '']);
$rows = DB::all("SELECT s.*, p.name AS home_port, (SELECT COUNT(*) FROM shp_holds h WHERE h.vessel_id = s.id AND h.status = 'active') AS holds,
                        (SELECT MIN(expires_on) FROM shp_certificates c WHERE c.vessel_id = s.id AND c.expires_on >= :d) AS next_expiry
                   FROM shp_vessels s LEFT JOIN shp_ports p ON p.id = s.home_port_id ORDER BY s.status = 'retired', s.name", ['d' => Ship::today()]);
?>
<div class="row g-3">
<?php foreach ($rows as $r): ?>
  <div class="col-md-6 col-xl-4"><a class="kpi-tile reveal h-100 <?= $r['holds'] ? 'tone-bad' : '' ?>" href="<?= h(url($page, ['id' => $r['id']])) ?>">
    <span class="k-ico"><i class="bi bi-<?= $r['holds'] ? 'shield-exclamation' : 'water' ?>"></i></span>
    <div class="k-label"><?= h($r['code']) ?>, <?= h(Ship::VESSEL_TYPES[$r['vessel_type']] ?? $r['vessel_type']) ?></div>
    <div class="k-value" style="font-size:1.2rem"><?= h($r['name']) ?></div>
    <div class="k-sub mt-1"><?= (int)$r['pax_capacity'] ?> passengers, <?= qty($r['lane_meters']) ?> lane m, <?= qty($r['gross_tonnage']) ?> GT</div>
    <div class="k-sub"><?= Ship::label($r['status'], ['active' => 'In service', 'drydock' => 'In dry-dock', 'laid_up' => 'Laid up', 'retired' => 'Retired']) ?>
      <?= $r['holds'] ? ' <span class="st st-bad">' . (int)$r['holds'] . ' hold(s)</span>' : '' ?></div>
    <div class="k-sub text-muted"><?= $r['next_expiry'] ? 'Next certificate expiry ' . h(fdate($r['next_expiry'])) : 'No certificate on file' ?></div>
  </a></div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="col-12"><?= empty_note('No vessel registered yet. Add the first one with New vessel.') ?></div><?php endif; ?>
</div>
<?php
layout_end();
