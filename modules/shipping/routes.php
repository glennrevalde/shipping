<?php
declare(strict_types=1);

$page = 'shp.routes';
$id = gint('id');
$edit = $id ? DB::row('SELECT * FROM shp_routes WHERE id = :id', ['id' => $id]) : null;
$ports = Ship::portOptions();

handle_post(function () use ($page, $edit) {
    if (p('toggle') !== '' && $edit) {
        ship_require($page, 'delete');
        DB::update('shp_routes', ['is_active' => (int)!$edit['is_active']], 'id = :id', ['id' => $edit['id']]);
        flash('success', 'Route ' . ($edit['is_active'] ? 'switched off' : 'switched on') . '.');
        redirect(url($page));
    }
    ship_require($page, $edit ? 'edit' : 'create');
    $row = [
        'code' => strtoupper(trim((string)p('code'))), 'name' => trim((string)p('name')), 'origin_port_id' => pint('origin_port_id'), 'dest_port_id' => pint('dest_port_id'),
        'distance_nm' => pnum('distance_nm'), 'transit_minutes' => (int)p('transit_minutes'), 'service_type' => (string)p('service_type', 'ropax'), 'sailing_note' => trim((string)p('sailing_note')) ?: null,
    ];
    if ($row['code'] === '' || !$row['origin_port_id'] || !$row['dest_port_id']) {
        fail('Enter the route code and choose both ports.');
    }
    if ($row['origin_port_id'] === $row['dest_port_id']) {
        fail('The origin and the destination are the same port.');
    }
    if ($row['transit_minutes'] <= 0) {
        fail('Enter the transit time in minutes.');
    }
    if ($row['name'] === '') {
        $row['name'] = DB::value('SELECT name FROM shp_ports WHERE id = :a', ['a' => $row['origin_port_id']]) . ' to ' . DB::value('SELECT name FROM shp_ports WHERE id = :b', ['b' => $row['dest_port_id']]);
    }
    if (DB::value('SELECT id FROM shp_routes WHERE code = :c' . ($edit ? ' AND id <> :id' : ''), $edit ? ['c' => $row['code'], 'id' => $edit['id']] : ['c' => $row['code']])) {
        fail('Another route already has code ' . $row['code'] . '.');
    }
    DB::tx(function () use ($row, $edit, $page) {
        if ($edit) {
            DB::update('shp_routes', $row, 'id = :id', ['id' => $edit['id']]);
            $rid = (int)$edit['id'];
        } else {
            $rid = DB::insert('shp_routes', $row);
        }
        DB::run('DELETE FROM shp_route_stops WHERE route_id = :r', ['r' => $rid]);
        $seq = 0;
        foreach ((array)($_POST['stop_port'] ?? []) as $i => $port) {
            if ((int)$port > 0) {
                DB::insert('shp_route_stops', ['route_id' => $rid, 'seq' => ++$seq, 'port_id' => (int)$port,
                    'arrive_offset' => (int)($_POST['stop_arr'][$i] ?? 0), 'depart_offset' => (int)($_POST['stop_dep'][$i] ?? 0)]);
            }
        }
        Ship::audit($edit ? 'edit' : 'create', 'shp', 'shp_route', $rid, 'Route ' . $row['code'], ['page_key' => $page, 'changes' => $edit ? Ship::changes($edit, $row) : null]);
    });
    flash('success', 'Route ' . $row['code'] . ' saved.');
    redirect(url($page));
});

$show = g('a') === 'new' || $edit || is_post();
layout_start('Routes', ['actions' => !$show && Access::can($page, 'create') ? btn_link(url($page, ['a' => 'new']), 'New route', 'btn-primary', 'plus-lg') : '']);
if ($show):
    $stops = $edit ? DB::all('SELECT * FROM shp_route_stops WHERE route_id = :r ORDER BY seq', ['r' => $edit['id']]) : [];
    $stops = array_pad($stops, max(2, count($stops) + 1), ['port_id' => '', 'arrive_offset' => '', 'depart_offset' => '']);
?>
<div class="card mb-3 reveal"><div class="card-header"><?= $edit ? 'Edit route ' . h($edit['code']) : 'New route' ?></div><div class="card-body">
<form method="post"><?= csrf_field() ?>
  <div class="row g-3">
    <?= input_field('code', 'Route code', fv('code', $edit), ['class' => 'col-md-2', 'required' => true]) ?>
    <?= select_field('origin_port_id', 'From', $ports, fv('origin_port_id', $edit), ['class' => 'col-md-4', 'required' => true, 'blank' => '']) ?>
    <?= select_field('dest_port_id', 'To', $ports, fv('dest_port_id', $edit), ['class' => 'col-md-4', 'required' => true, 'blank' => '']) ?>
    <?= select_field('service_type', 'Service', Ship::VESSEL_TYPES, fv('service_type', $edit, 'ropax'), ['class' => 'col-md-2']) ?>
    <?= input_field('name', 'Name (blank = From to To)', fv('name', $edit), ['class' => 'col-md-4']) ?>
    <?= input_field('distance_nm', 'Distance (nautical miles)', fv('distance_nm', $edit), ['type' => 'number', 'class' => 'col-md-2']) ?>
    <?= input_field('transit_minutes', 'Transit time (minutes)', fv('transit_minutes', $edit), ['type' => 'number', 'class' => 'col-md-2', 'step' => '1', 'required' => true]) ?>
    <?= input_field('sailing_note', 'Frequency, restrictions, planned closures', fv('sailing_note', $edit), ['class' => 'col-md-4']) ?>
  </div>
  <h6 class="mt-4 mb-2">Intermediate port calls (legs)</h6>
  <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Port</th><th>Arrives (minutes after departure)</th><th>Leaves (minutes after departure)</th></tr></thead><tbody>
  <?php foreach ($stops as $s): ?>
    <tr><td><select class="form-select form-select-sm" name="stop_port[]"><?= options($ports, $s['port_id'], '(none)') ?></select></td>
      <td><input class="form-control form-control-sm" type="number" name="stop_arr[]" value="<?= h($s['arrive_offset']) ?>"></td>
      <td><input class="form-control form-control-sm" type="number" name="stop_dep[]" value="<?= h($s['depart_offset']) ?>"></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?= save_buttons('Save route', url($page), false) ?>
</form></div></div>
<?php endif; ?>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle">
<thead><tr><th>Code</th><th>Route</th><th>Service</th><th class="num">Distance (nm)</th><th class="num">Transit</th><th>Port calls</th><th>Fares in force</th><th></th></tr></thead><tbody>
<?php foreach (DB::all('SELECT r.*, po.code oc, pd.code dc FROM shp_routes r JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id ORDER BY r.is_active DESC, r.code') as $r):
    $calls = DB::column('SELECT p.code FROM shp_route_stops s JOIN shp_ports p ON p.id = s.port_id WHERE s.route_id = :r ORDER BY s.seq', ['r' => $r['id']]);
    $fares = (int)DB::value('SELECT COUNT(DISTINCT accom_code) FROM shp_fares WHERE route_id = :r AND effective_from <= :d AND (effective_to IS NULL OR effective_to >= :d2)', ['r' => $r['id'], 'd' => Ship::today(), 'd2' => Ship::today()]); ?>
  <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>"><td class="fw-semibold"><?= h($r['code']) ?></td><td><?= h($r['oc'] . ' to ' . $r['dc']) ?> <span class="text-muted small"><?= h($r['name']) ?></span></td>
    <td><?= h(Ship::VESSEL_TYPES[$r['service_type']] ?? $r['service_type']) ?></td><td class="num"><?= qty($r['distance_nm']) ?></td>
    <td class="num"><?= intdiv((int)$r['transit_minutes'], 60) ?>h <?= (int)$r['transit_minutes'] % 60 ?>m</td><td><?= h(implode(', ', $calls)) ?></td>
    <td><?= $fares ? $fares . ' class(es)' : '<span class="st st-warn">None</span>' ?></td>
    <td class="text-end text-nowrap"><?php if (Access::can($page, 'edit')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($page, ['id' => $r['id']])) ?>"><i class="bi bi-pencil"></i></a><?php endif; ?>
      <?php if (Access::can($page, 'delete')): ?><form class="d-inline" method="post" action="<?= h(url($page, ['id' => $r['id']])) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" name="toggle" value="1"><i class="bi bi-toggle-<?= $r['is_active'] ? 'on' : 'off' ?>"></i></button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php
layout_end();
