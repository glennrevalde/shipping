<?php
declare(strict_types=1);

$page = 'fleet.equipment';
$id = gint('id');
$a = (string)g('a');
$eq = $id ? DB::row('SELECT e.*, s.name AS vessel_name, s.code AS vessel_code, p.name AS parent_name, vd.name AS vendor_name
                       FROM shp_equipment e JOIN shp_vessels s ON s.id = e.vessel_id LEFT JOIN shp_equipment p ON p.id = e.parent_id
                       LEFT JOIN vendors vd ON vd.id = e.vendor_id WHERE e.id = :id', ['id' => $id]) : null;
if ($id && !$eq) {
    flash('warning', 'That equipment no longer exists.');
    redirect(url($page));
}
$vessels = Ship::vesselOptions();
$vessel = $eq ? (int)$eq['vessel_id'] : (gint('vessel') ?: (int)array_key_first($vessels));

const EQ_LEVELS = ['system' => 'System', 'equipment' => 'Equipment', 'component' => 'Component'];
const EQ_CRIT = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

if (!function_exists('eq_metered')) {
    /** Equipment whose running hours are read (a meter reading exists, or a plan runs on hours) */
    function eq_metered(int $vesselId): array
    {
        return DB::all("SELECT e.* FROM shp_equipment e WHERE e.vessel_id = :v AND e.is_active = 1
                          AND (e.running_hours > 0 OR EXISTS (SELECT 1 FROM shp_pm_plans p WHERE p.equipment_id = e.id AND p.basis = 'hours' AND p.is_active = 1))
                        ORDER BY e.code", ['v' => $vesselId]);
    }

    function eq_tree(array $nodes, ?int $parent, array $stats, int $depth = 0): string
    {
        $kids = array_filter($nodes, fn($n) => (int)$n['parent_id'] === (int)$parent && ($parent !== null || !$n['parent_id']));
        if (!$kids || $depth > 6) {
            return '';
        }
        $icons = ['system' => 'diagram-3', 'equipment' => 'gear-wide-connected', 'component' => 'nut'];
        $html = '<ul>';
        foreach ($kids as $n) {
            $s = $stats[(int)$n['id']] ?? ['wo' => 0, 'pm' => 0];
            $meta = trim(implode(', ', array_filter([trim(($n['maker'] ?? '') . ' ' . ($n['model'] ?? '')), $s['pm'] ? $s['pm'] . ' plan(s)' : '', $s['wo'] ? $s['wo'] . ' open work order(s)' : ''])));
            $html .= '<li><a class="eq-node lvl-' . h($n['level']) . ' text-decoration-none' . ((int)$n['is_active'] ? '' : ' opacity-50') . '" href="' . h(url('fleet.equipment', ['id' => $n['id']])) . '">'
                . '<span class="ico"><i class="bi bi-' . ($icons[$n['level']] ?? 'gear') . '"></i></span>'
                . '<span><span class="nm">' . h($n['name']) . '<small>' . h($n['code']) . '</small></span><span class="d-block meta">' . Ship::severityLabel((string)$n['criticality'])
                . ($meta !== '' ? ' ' . h($meta) : '') . '</span></span>'
                . '<span class="hrs">' . ((float)$n['running_hours'] > 0 ? '<b>' . number_format((float)$n['running_hours'], 1) . ' h</b>' . ($n['hours_read_at'] ? h(date('M j', strtotime($n['hours_read_at']))) : '') : '') . '</span></a>'
                . eq_tree($nodes, (int)$n['id'], $stats, $depth + 1) . '</li>';
        }
        return $html . '</ul>';
    }
}

handle_post(function () use ($page, $eq, $vessel) {
    $act = (string)p('act');
    if ($act === 'readings') {
        ship_require($page, 'edit');
        $n = 0;
        DB::tx(function () use (&$n) {
            $at = p('read_at') ? date('Y-m-d H:i:s', strtotime((string)p('read_at'))) : Ship::now();
            foreach ((array)($_POST['hours'] ?? []) as $eid => $hrs) {
                if (trim((string)$hrs) === '') {
                    continue;
                }
                Cmms::reading((int)$eid, to_num($hrs), $at);
                $n++;
            }
        });
        if (!$n) {
            fail('Enter at least one reading.');
        }
        flash('success', $n . ' running-hour reading(s) recorded. Plans that fall due by hours now show on Planned Maintenance.');
        redirect(url($page, ['vessel' => $vessel]));
    }
    if ($act === 'reading') {
        ship_require($page, 'edit');
        Cmms::reading((int)$eq['id'], pnum('hours'), p('read_at') ? date('Y-m-d H:i:s', strtotime((string)p('read_at'))) : null);
        flash('success', 'Reading recorded.');
        redirect(url($page, ['id' => $eq['id']]));
    }
    ship_require($page, $eq ? 'edit' : 'create');
    $d = [
        'vessel_id' => (int)pint('vessel_id'), 'parent_id' => pint('parent_id'), 'code' => strtoupper(mb_substr(trim((string)p('code')), 0, 30)), 'name' => mb_substr(trim((string)p('name')), 0, 120),
        'level' => isset(EQ_LEVELS[p('level')]) ? (string)p('level') : 'equipment', 'maker' => p('maker') ?: null, 'model' => p('model') ?: null, 'serial_no' => p('serial_no') ?: null,
        'installed_on' => pdate('installed_on'), 'criticality' => isset(EQ_CRIT[p('criticality')]) ? (string)p('criticality') : 'medium', 'warranty_until' => pdate('warranty_until'),
        'vendor_id' => pint('vendor_id'), 'manual_ref' => p('manual_ref') ?: null, 'notes' => p('notes') ?: null, 'is_active' => p('is_active', '1') === '1' ? 1 : 0,
    ];
    if (!DB::value('SELECT 1 FROM shp_vessels WHERE id = :id', ['id' => $d['vessel_id']])) {
        fail('Choose the vessel.');
    }
    if ($d['code'] === '' || $d['name'] === '') {
        fail('Enter the code and the name.');
    }
    if (DB::value('SELECT 1 FROM shp_equipment WHERE vessel_id = :v AND code = :c AND id <> :id', ['v' => $d['vessel_id'], 'c' => $d['code'], 'id' => $eq['id'] ?? 0])) {
        fail('Code ' . $d['code'] . ' is already used on this vessel.');
    }
    if ($d['parent_id']) {
        $par = DB::row('SELECT vessel_id FROM shp_equipment WHERE id = :id', ['id' => $d['parent_id']]);
        if (!$par || (int)$par['vessel_id'] !== $d['vessel_id']) {
            fail('The parent belongs to another vessel.');
        }
        // no loops: the parent may not sit under this item
        for ($p = $d['parent_id'], $i = 0; $p && $i < 20; $i++) {
            if ($eq && (int)$p === (int)$eq['id']) {
                fail('An item cannot sit under itself or one of its own parts.');
            }
            $p = DB::value('SELECT parent_id FROM shp_equipment WHERE id = :id', ['id' => $p]);
        }
    }
    if ($eq) {
        DB::update('shp_equipment', $d, 'id = :id', ['id' => $eq['id']]);
        Ship::audit('edit', 'fleet', 'shp_equipment', (int)$eq['id'], $d['code'] . ' ' . $d['name'], ['changes' => Ship::changes($eq, $d), 'page_key' => $page]);
        $nid = (int)$eq['id'];
    } else {
        $d['running_hours'] = pnum('running_hours');
        $d['hours_read_at'] = $d['running_hours'] > 0 ? Ship::now() : null;
        $nid = DB::insert('shp_equipment', $d);
        Ship::audit('create', 'fleet', 'shp_equipment', $nid, $d['code'] . ' ' . $d['name'], ['page_key' => $page]);
    }
    flash('success', 'Equipment saved.');
    redirect(url($page, ['id' => $nid]));
});

$canEdit = Access::can($page, 'edit');
$canCreate = Access::can($page, 'create');

// ------------------------------------------------------------------ form
if ($a === 'new' || $a === 'edit' || (is_post() && !in_array(p('act'), ['readings', 'reading'], true))) {
    $e = $a === 'edit' || ($eq && is_post()) ? $eq : null;
    ship_require($page, $e ? 'edit' : 'create');
    $vSel = (int)fv('vessel_id', $e, $vessel);
    $parents = DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM shp_equipment WHERE vessel_id = :v AND level <> 'component' AND id <> :me ORDER BY code", ['v' => $vSel, 'me' => $e['id'] ?? 0]);
    layout_start($e ? 'Edit ' . $e['name'] : 'New equipment', ['actions' => btn_link($e ? url($page, ['id' => $e['id']]) : url($page, ['vessel' => $vessel]), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" class="card reveal" style="max-width:980px"><?= csrf_field() ?><input type="hidden" name="act" value="save"><div class="card-body"><div class="row g-3">
      <?= select_field('vessel_id', 'Vessel', $vessels, $vSel, ['class' => 'col-md-4', 'required' => true]) ?>
      <?= select_field('parent_id', 'Part of', $parents, fv('parent_id', $e, gint('parent')), ['class' => 'col-md-5', 'blank' => '(top level)']) ?>
      <?= select_field('level', 'Level', EQ_LEVELS, fv('level', $e, gint('parent') ? 'equipment' : 'system'), ['class' => 'col-md-3']) ?>
      <?= input_field('code', 'Code', fv('code', $e), ['class' => 'col-md-3', 'required' => true]) ?><?= input_field('name', 'Name', fv('name', $e), ['class' => 'col-md-6', 'required' => true]) ?>
      <?= select_field('criticality', 'Criticality', EQ_CRIT, fv('criticality', $e, 'medium'), ['class' => 'col-md-3']) ?>
      <?= input_field('maker', 'Maker', fv('maker', $e), ['class' => 'col-md-3']) ?><?= input_field('model', 'Model', fv('model', $e), ['class' => 'col-md-3']) ?>
      <?= input_field('serial_no', 'Serial number', fv('serial_no', $e), ['class' => 'col-md-3']) ?><?= input_field('installed_on', 'Installed on', fv('installed_on', $e), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?php if (!$e): ?><?= input_field('running_hours', 'Running hours now (if it has a meter)', p('running_hours'), ['type' => 'number', 'step' => '0.1', 'class' => 'col-md-3']) ?><?php endif; ?>
      <?= input_field('warranty_until', 'Warranty until', fv('warranty_until', $e), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= select_field('vendor_id', 'Supplier / service agent', DB::pairs('SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name'), fv('vendor_id', $e), ['class' => 'col-md-3', 'blank' => '']) ?>
      <?= input_field('manual_ref', "Maker's manual / drawing reference", fv('manual_ref', $e), ['class' => 'col-md-3']) ?>
      <?= input_field('notes', 'Notes', fv('notes', $e), ['class' => 'col-md-9']) ?>
      <?= select_field('is_active', 'In use', ['1' => 'Yes', '0' => 'No, removed'], (string)fv('is_active', $e, '1'), ['class' => 'col-md-3']) ?>
    </div><div class="form-text mt-2">Running hours of existing equipment are changed only by meter readings, which never go down.</div></div>
    <div class="card-footer bg-transparent"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Save equipment</button></div></form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ one item
if ($eq) {
    $readings = DB::all('SELECT m.*, u.full_name FROM shp_meter_readings m LEFT JOIN users u ON u.id = m.user_id WHERE m.equipment_id = :e ORDER BY m.read_at DESC, m.id DESC LIMIT 15', ['e' => $eq['id']]);
    $plans = DB::all('SELECT * FROM shp_pm_plans WHERE equipment_id = :e ORDER BY is_active DESC, name', ['e' => $eq['id']]);
    $wos = DB::all('SELECT * FROM shp_work_orders WHERE equipment_id = :e ORDER BY id DESC LIMIT 20', ['e' => $eq['id']]);
    $defects = DB::all('SELECT * FROM shp_defects WHERE equipment_id = :e ORDER BY id DESC LIMIT 10', ['e' => $eq['id']]);
    $kids = DB::all('SELECT * FROM shp_equipment WHERE parent_id = :e ORDER BY code', ['e' => $eq['id']]);
    $cost = DB::row("SELECT COALESCE(SUM(cost_labor), 0) l, COALESCE(SUM(cost_parts), 0) p, COALESCE(SUM(cost_services), 0) s, COUNT(*) n,
                            SUM(CASE WHEN wo_type = 'corrective' THEN 1 ELSE 0 END) fails, COALESCE(SUM(downtime_hours), 0) dt
                       FROM shp_work_orders WHERE equipment_id = :e AND status IN ('completed', 'closed')", ['e' => $eq['id']]);
    $actions = ($canEdit ? btn_link(url($page, ['a' => 'edit', 'id' => $eq['id']]), 'Edit', 'btn-outline-primary', 'pencil') . ' ' : '')
        . ($canCreate && $eq['level'] !== 'component' ? btn_link(url($page, ['a' => 'new', 'vessel' => $eq['vessel_id'], 'parent' => $eq['id']]), 'Add a part', 'btn-outline-secondary', 'plus-lg') . ' ' : '')
        . (Access::can('fleet.defects', 'create') ? btn_link(url('fleet.defects', ['a' => 'new', 'vessel' => $eq['vessel_id'], 'equipment' => $eq['id']]), 'Report a defect', 'btn-outline-danger', 'exclamation-triangle') : '');
    layout_start($eq['name'] . ' (' . $eq['code'] . ')', ['actions' => $actions]);
    $costs = Access::viewCosts();
    echo '<div class="kpi-grid mb-3">'
        . kpi_tile('Running hours', (float)$eq['running_hours'] > 0 ? number_format((float)$eq['running_hours'], 1) : 'No meter', 'speedometer2', $eq['hours_read_at'] ? 'Read ' . fdatetime($eq['hours_read_at']) : '', '')
        . kpi_tile('Work done', (string)(int)$cost['n'], 'tools', (int)$cost['fails'] . ' repair(s) of failures', (int)$cost['fails'] > 2 ? 'warn' : '', null, (float)$cost['n'])
        . kpi_tile('Downtime', qty($cost['dt']) . ' h', 'clock-history', 'From closed work orders', '')
        . ($costs ? kpi_tile('Maintenance cost', '₱' . money((float)$cost['l'] + (float)$cost['p'] + (float)$cost['s']), 'cash-coin', 'Labor ₱' . money($cost['l']) . ', parts ₱' . money($cost['p']) . ', services ₱' . money($cost['s']), '') : '')
        . '</div>';
    ?>
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card reveal mb-3"><div class="card-header">Particulars</div><div class="card-body"><div class="info-grid">
          <?= info_pair('Vessel', $eq['vessel_name']) ?><?= info_pair('Part of', $eq['parent_name'] ?: 'Top level') ?><?= info_pair('Level', EQ_LEVELS[$eq['level']] ?? $eq['level']) ?>
          <?= info_pair('Criticality', Ship::severityLabel((string)$eq['criticality']), true) ?>
          <?= info_pair('Maker and model', trim($eq['maker'] . ' ' . $eq['model']) ?: '-') ?><?= info_pair('Serial number', $eq['serial_no'] ?: '-') ?>
          <?= info_pair('Installed', $eq['installed_on'] ? fdate($eq['installed_on']) : '-') ?><?= info_pair('Warranty until', $eq['warranty_until'] ? fdate($eq['warranty_until']) : '-') ?>
          <?= info_pair('Service agent', $eq['vendor_name'] ?: '-') ?><?= info_pair('Manual', $eq['manual_ref'] ?: '-') ?>
          <?= $eq['notes'] ? info_pair('Notes', $eq['notes']) : '' ?><?= (int)$eq['is_active'] ? '' : info_pair('In use', 'Removed') ?>
        </div></div></div>
        <div class="card reveal mb-3"><div class="card-header">Work orders</div><div class="table-responsive"><table class="table table-sm table-hover mb-0">
          <thead><tr><th>Work order</th><th>Work</th><th>Type</th><th>Raised</th><th>Status</th><?= $costs ? '<th class="num">Cost</th>' : '' ?></tr></thead><tbody>
          <?php foreach ($wos as $w): ?><tr><td><?= safe_link('fleet.workorders', ['id' => $w['id']], $w['wo_no']) ?></td><td><?= h($w['title']) ?></td><td class="small"><?= h(ucfirst($w['wo_type'])) ?></td><td class="small"><?= h(fdate($w['created_at'])) ?></td><td><?= Ship::label($w['status']) ?></td>
            <?= $costs ? '<td class="num">' . money((float)$w['cost_labor'] + (float)$w['cost_parts'] + (float)$w['cost_services'], true) . '</td>' : '' ?></tr><?php endforeach; ?>
          <?php if (!$wos): ?><tr><td colspan="6"><?= empty_note('No work order on this item yet.', 'tools') ?></td></tr><?php endif; ?>
          </tbody></table></div></div>
        <?php if ($defects): ?><div class="card reveal mb-3"><div class="card-header">Defects reported</div><ul class="list-group list-group-flush">
          <?php foreach ($defects as $df): ?><li class="list-group-item d-flex justify-content-between gap-2"><span><?= safe_link('fleet.defects', ['id' => $df['id']], $df['defect_no']) ?> <?= h($df['title']) ?></span><span class="d-flex gap-3"><?= Ship::severityLabel($df['severity']) ?><?= Ship::label($df['status'], ['work_order' => 'Work order raised', 'resolved' => 'Repaired']) ?></span></li><?php endforeach; ?></ul></div><?php endif; ?>
        <?php if ($kids): ?><div class="card reveal"><div class="card-header">Parts</div><div class="card-body eq-tree"><?= eq_tree(array_merge($kids, DB::all('SELECT * FROM shp_equipment WHERE parent_id IN (' . implode(',', array_map('intval', array_column($kids, 'id'))) . ')')), (int)$eq['id'], []) ?></div></div><?php endif; ?>
      </div>
      <div class="col-lg-5">
        <div class="card reveal mb-3"><div class="card-header">Running hours</div><div class="card-body">
          <?php if ($canEdit): ?><form method="post" class="row g-2 mb-3"><?= csrf_field() ?><input type="hidden" name="act" value="reading">
            <?= input_field('hours', 'Meter now (h)', '', ['type' => 'number', 'step' => '0.1', 'class' => 'col-5', 'required' => true]) ?>
            <?= input_field('read_at', 'Read at', date('Y-m-d\TH:i', strtotime(Ship::now())), ['type' => 'datetime-local', 'class' => 'col-7']) ?>
            <div class="col-12"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-speedometer2"></i> Record reading</button></div></form><?php endif; ?>
          <table class="table table-sm mb-0"><thead><tr><th>Read</th><th class="num">Hours</th><th class="num">Since last</th><th>By</th></tr></thead><tbody>
          <?php foreach ($readings as $i => $r): $prev = $readings[$i + 1] ?? null; ?><tr><td class="small"><?= h(fdatetime($r['read_at'])) ?></td><td class="num"><?= number_format((float)$r['reading'], 1) ?></td>
            <td class="num small"><?= $prev ? '+' . number_format((float)$r['reading'] - (float)$prev['reading'], 1) : '' ?></td><td class="small"><?= h((string)$r['full_name']) ?></td></tr><?php endforeach; ?>
          <?php if (!$readings): ?><tr><td colspan="4"><?= empty_note('No reading yet.', 'speedometer2') ?></td></tr><?php endif; ?>
          </tbody></table></div></div>
        <div class="card reveal"><div class="card-header d-flex justify-content-between"><span>Maintenance plans</span><?= Access::can('fleet.pm', 'create') ? '<a class="small" href="' . h(url('fleet.pm', ['a' => 'new', 'vessel' => $eq['vessel_id'], 'equipment' => $eq['id']])) . '">Add a plan</a>' : '' ?></div>
          <ul class="list-group list-group-flush">
          <?php foreach ($plans as $pl): ?><li class="list-group-item"><div class="d-flex justify-content-between gap-2"><span class="fw-semibold small"><?= safe_link('fleet.pm', ['id' => $pl['id']], $pl['name']) ?></span><?= (int)$pl['is_active'] ? '' : '<span class="st st-muted">Off</span>' ?></div>
            <div class="small text-muted"><?= $pl['interval_days'] ? 'Every ' . (int)$pl['interval_days'] . ' days' : '' ?><?= $pl['interval_days'] && $pl['interval_hours'] ? ' or ' : '' ?><?= $pl['interval_hours'] ? 'every ' . qty($pl['interval_hours']) . ' h' : '' ?>,
              next <?= $pl['next_due_on'] ? h(fdate($pl['next_due_on'])) : '' ?><?= $pl['next_due_on'] && $pl['next_due_hours'] !== null ? ' / ' : '' ?><?= $pl['next_due_hours'] !== null ? number_format((float)$pl['next_due_hours'], 1) . ' h' : '' ?></div></li><?php endforeach; ?>
          <?php if (!$plans): ?><li class="list-group-item"><?= empty_note('No plan for this item.', 'calendar-check') ?></li><?php endif; ?>
          </ul></div>
      </div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the hierarchy of one vessel
$nodes = DB::all('SELECT * FROM shp_equipment WHERE vessel_id = :v ORDER BY is_active DESC, code', ['v' => $vessel]);
$stats = [];
foreach (DB::all("SELECT equipment_id, COUNT(*) n FROM shp_work_orders WHERE vessel_id = :v AND status NOT IN ('closed', 'cancelled', 'completed') AND equipment_id IS NOT NULL GROUP BY equipment_id", ['v' => $vessel]) as $r) {
    $stats[(int)$r['equipment_id']]['wo'] = (int)$r['n'];
}
foreach (DB::all('SELECT equipment_id, COUNT(*) n FROM shp_pm_plans WHERE vessel_id = :v AND is_active = 1 AND equipment_id IS NOT NULL GROUP BY equipment_id', ['v' => $vessel]) as $r) {
    $stats[(int)$r['equipment_id']]['pm'] = (int)$r['n'];
}
foreach ($stats as &$s) {
    $s += ['wo' => 0, 'pm' => 0];
}
unset($s);
$metered = eq_metered($vessel);
$tabs = [];
foreach ($vessels as $vid => $vn) {
    $tabs[(string)$vid] = [$vn, url($page, ['vessel' => $vid]), (int)DB::value('SELECT COUNT(*) FROM shp_equipment WHERE vessel_id = :v AND is_active = 1', ['v' => $vid])];
}
layout_start('Equipment Hierarchy', ['actions' => $canCreate ? btn_link(url($page, ['a' => 'new', 'vessel' => $vessel]), 'New equipment', 'btn-primary', 'plus-lg') : '']);
echo tabs_line($tabs, (string)$vessel);
?>
<div class="row g-3">
  <div class="col-xl-7"><div class="card reveal"><div class="card-header d-flex justify-content-between"><span>Systems, equipment and components</span><span class="small text-muted"><?= count($nodes) ?> item(s)</span></div>
    <div class="card-body eq-tree"><?= $nodes ? eq_tree($nodes, null, $stats) : empty_note('No equipment registered for this vessel yet.', 'gear-wide-connected') ?></div></div></div>
  <div class="col-xl-5"><form method="post" class="card reveal"><?= csrf_field() ?><input type="hidden" name="act" value="readings">
    <div class="card-header">Record running hours</div><div class="card-body">
    <?php if (!$metered): ?><?= empty_note('No equipment with a running-hour meter on this vessel.', 'speedometer2') ?>
    <?php else: ?>
      <p class="small text-muted">Type the meter of each machine read today; leave the others blank. A reading lower than the last one is refused.</p>
      <fieldset <?= $canEdit ? '' : 'disabled' ?>>
      <table class="table table-sm align-middle mb-2"><thead><tr><th>Machine</th><th class="num">Last</th><th style="width:130px">Now</th></tr></thead><tbody>
      <?php foreach ($metered as $m): ?><tr><td class="small"><span class="fw-semibold"><?= h($m['code']) ?></span> <?= h($m['name']) ?></td><td class="num small"><?= number_format((float)$m['running_hours'], 1) ?><div class="text-muted"><?= $m['hours_read_at'] ? h(date('M j', strtotime($m['hours_read_at']))) : '' ?></div></td>
        <td><input class="form-control form-control-sm" type="number" step="0.1" min="<?= h((string)$m['running_hours']) ?>" name="hours[<?= (int)$m['id'] ?>]"></td></tr><?php endforeach; ?>
      </tbody></table>
      <div class="row g-2 align-items-end"><?= input_field('read_at', 'Read at', date('Y-m-d\TH:i', strtotime(Ship::now())), ['type' => 'datetime-local', 'class' => 'col-7']) ?>
        <div class="col-5"><?php if ($canEdit): ?><button class="btn btn-primary btn-sm w-100"><i class="bi bi-check2"></i> Save readings</button><?php endif; ?></div></div>
      </fieldset>
    <?php endif; ?>
    </div></form></div>
</div>
<?php
layout_end();
