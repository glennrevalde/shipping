<?php
declare(strict_types=1);

$page = 'fleet.pm';
$id = gint('id');
$a = (string)g('a');
$plan = $id ? DB::row('SELECT p.*, s.name AS vessel_name, s.code AS vessel_code, e.code AS eq_code, e.name AS eq_name, e.running_hours
                         FROM shp_pm_plans p JOIN shp_vessels s ON s.id = p.vessel_id LEFT JOIN shp_equipment e ON e.id = p.equipment_id WHERE p.id = :id', ['id' => $id]) : null;
if ($id && !$plan) {
    flash('warning', 'That plan no longer exists.');
    redirect(url($page));
}
$vessel = gint('vessel');
const PM_BASIS = ['calendar' => 'Calendar (every so many days)', 'hours' => 'Running hours', 'survey' => 'Survey / statutory'];

if (!function_exists('pm_next_text')) {
    function pm_next_text(array $p): string
    {
        $bits = [];
        if ($p['next_due_on']) {
            $bits[] = fdate($p['next_due_on']);
        }
        if ($p['next_due_hours'] !== null) {
            $bits[] = 'at ' . number_format((float)$p['next_due_hours'], 1) . ' h';
        }
        return $bits ? implode(' or ', $bits) : 'Not set';
    }

    function pm_interval_text(array $p): string
    {
        $bits = [];
        if ($p['interval_days']) {
            $bits[] = 'every ' . (int)$p['interval_days'] . ' days';
        }
        if ($p['interval_hours']) {
            $bits[] = 'every ' . number_format((float)$p['interval_hours'], 0) . ' h';
        }
        return ucfirst($bits ? implode(' or ', $bits) : 'once');
    }
}

handle_post(function () use ($page, $plan, $vessel) {
    $act = (string)p('act');
    if ($act === 'raise') {
        ship_require($page, 'create');
        $made = Cmms::raiseDue($vessel ?: null);
        flash($made ? 'success' : 'info', $made ? count($made) . ' work order(s) raised for the plans that are due.' : 'Every plan that is due already has an open work order.');
        redirect(url($page, array_filter(['vessel' => $vessel])));
    }
    if ($act === 'delete') {
        ship_require($page, 'delete');
        if (DB::value('SELECT 1 FROM shp_work_orders WHERE pm_plan_id = :p', ['p' => $plan['id']])) {
            fail('Work was already done under this plan; switch it off instead of deleting it.');
        }
        DB::run('DELETE FROM shp_pm_plans WHERE id = :id', ['id' => $plan['id']]);
        Ship::audit('delete', 'fleet', 'shp_pm_plan', (int)$plan['id'], $plan['name'], ['before' => $plan, 'page_key' => $page]);
        flash('success', 'Plan deleted.');
        redirect(url($page, ['tab' => 'plans']));
    }
    ship_require($page, $plan ? 'edit' : 'create');
    $basis = isset(PM_BASIS[p('basis')]) ? (string)p('basis') : 'calendar';
    $d = [
        'vessel_id' => (int)pint('vessel_id'), 'equipment_id' => pint('equipment_id'), 'name' => mb_substr(trim((string)p('name')), 0, 120), 'basis' => $basis,
        'interval_days' => pint('interval_days') ?: null, 'interval_hours' => p('interval_hours') !== '' ? pnum('interval_hours') : null, 'lead_days' => max(0, (int)p('lead_days', '7')),
        'last_done_on' => pdate('last_done_on'), 'last_done_hours' => p('last_done_hours') !== '' ? pnum('last_done_hours') : null,
        'next_due_on' => pdate('next_due_on'), 'next_due_hours' => p('next_due_hours') !== '' ? pnum('next_due_hours') : null,
        'checklist' => trim((string)p('checklist')) ?: null, 'est_hours' => pnum('est_hours'), 'is_regulatory' => p('is_regulatory') === '1' ? 1 : 0, 'is_active' => p('is_active', '1') === '1' ? 1 : 0,
    ];
    if (!DB::value('SELECT 1 FROM shp_vessels WHERE id = :id', ['id' => $d['vessel_id']])) {
        fail('Choose the vessel.');
    }
    if ($d['name'] === '') {
        fail('Name the maintenance task.');
    }
    if ($d['equipment_id'] && (int)DB::value('SELECT vessel_id FROM shp_equipment WHERE id = :id', ['id' => $d['equipment_id']]) !== $d['vessel_id']) {
        fail('The equipment chosen is on another vessel.');
    }
    if (!$d['interval_days'] && !$d['interval_hours']) {
        fail('Give the interval: a number of days, of running hours, or both (whichever comes first).');
    }
    if ($d['interval_hours'] && !$d['equipment_id']) {
        fail('A plan on running hours needs the equipment whose meter it follows.');
    }
    // next due worked out from the last time it was done when it is left blank
    if (!$d['next_due_on'] && $d['interval_days']) {
        $d['next_due_on'] = date('Y-m-d', strtotime(($d['last_done_on'] ?: Ship::today()) . ' +' . $d['interval_days'] . ' days'));
    }
    if ($d['next_due_hours'] === null && $d['interval_hours']) {
        $base = $d['last_done_hours'] ?? (float)DB::value('SELECT running_hours FROM shp_equipment WHERE id = :id', ['id' => $d['equipment_id']]);
        $d['next_due_hours'] = $base + $d['interval_hours'];
    }
    if ($plan) {
        DB::update('shp_pm_plans', $d, 'id = :id', ['id' => $plan['id']]);
        Ship::audit('edit', 'fleet', 'shp_pm_plan', (int)$plan['id'], $d['name'], ['changes' => Ship::changes($plan, $d), 'page_key' => $page]);
        $nid = (int)$plan['id'];
    } else {
        $nid = DB::insert('shp_pm_plans', $d);
        Ship::audit('create', 'fleet', 'shp_pm_plan', $nid, $d['name'], ['page_key' => $page]);
    }
    flash('success', 'Maintenance plan saved; next due ' . pm_next_text($d) . '.');
    redirect(url($page, ['id' => $nid]));
});

$canEdit = Access::can($page, 'edit');
$canCreate = Access::can($page, 'create');

// ------------------------------------------------------------------ form
if ($a === 'new' || $a === 'edit' || (is_post() && !in_array(p('act'), ['raise', 'delete'], true))) {
    $e = $a === 'edit' || ($plan && is_post()) ? $plan : null;
    ship_require($page, $e ? 'edit' : 'create');
    $vSel = (int)fv('vessel_id', $e, $vessel ?: (int)array_key_first(Ship::vesselOptions()));
    layout_start($e ? 'Edit plan' : 'New maintenance plan', ['actions' => btn_link($e ? url($page, ['id' => $e['id']]) : url($page), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" class="card reveal" style="max-width:1000px"><?= csrf_field() ?><input type="hidden" name="act" value="save"><div class="card-body"><div class="row g-3">
      <?= select_field('vessel_id', 'Vessel', Ship::vesselOptions(), $vSel, ['class' => 'col-md-4', 'required' => true, 'extra' => 'id="pmVessel"']) ?>
      <div class="col-md-8"><label class="form-label">Equipment</label><select class="form-select form-select-sm" name="equipment_id" id="pmEq"><option value=""></option>
        <?php foreach (DB::all('SELECT id, vessel_id, code, name FROM shp_equipment WHERE is_active = 1 ORDER BY code') as $q): ?>
          <option value="<?= (int)$q['id'] ?>" data-vessel="<?= (int)$q['vessel_id'] ?>" <?= (string)fv('equipment_id', $e, gint('equipment')) === (string)$q['id'] ? 'selected' : '' ?>><?= h($q['code'] . ' ' . $q['name']) ?></option>
        <?php endforeach; ?></select></div>
      <?= input_field('name', 'Task', fv('name', $e), ['class' => 'col-md-8', 'required' => true]) ?>
      <?= select_field('basis', 'Basis', PM_BASIS, fv('basis', $e, 'calendar'), ['class' => 'col-md-4']) ?>
      <?= input_field('interval_days', 'Every (days)', fv('interval_days', $e), ['type' => 'number', 'step' => '1', 'class' => 'col-md-3']) ?>
      <?= input_field('interval_hours', 'Every (running hours)', fv('interval_hours', $e), ['type' => 'number', 'step' => '0.1', 'class' => 'col-md-3']) ?>
      <?= input_field('lead_days', 'Show as due this many days early', fv('lead_days', $e, 7), ['type' => 'number', 'step' => '1', 'class' => 'col-md-3']) ?>
      <?= input_field('est_hours', 'Estimated man-hours', fv('est_hours', $e), ['type' => 'number', 'class' => 'col-md-3']) ?>
      <?= input_field('last_done_on', 'Last done on', fv('last_done_on', $e), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= input_field('last_done_hours', 'Last done at (h)', fv('last_done_hours', $e), ['type' => 'number', 'step' => '0.1', 'class' => 'col-md-3']) ?>
      <?= input_field('next_due_on', 'Next due on (blank: worked out)', fv('next_due_on', $e), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= input_field('next_due_hours', 'Next due at (h, blank: worked out)', fv('next_due_hours', $e), ['type' => 'number', 'step' => '0.1', 'class' => 'col-md-3']) ?>
      <div class="col-12"><label class="form-label">Checklist (one step per line)</label><textarea class="form-control form-control-sm" name="checklist" rows="5"><?= h((string)fv('checklist', $e)) ?></textarea></div>
      <?= check_field('is_regulatory', 'Statutory or class requirement (raised as a survey work order)', (int)fv('is_regulatory', $e, 0) === 1, 'col-md-8') ?>
      <?= select_field('is_active', 'Active', ['1' => 'Yes', '0' => 'No, switched off'], (string)fv('is_active', $e, '1'), ['class' => 'col-md-4']) ?>
    </div><div class="form-text mt-2">With both a day and an hour interval the task falls due at whichever comes first. Closing its work order moves the next due date and hours on from the day and meter of completion.</div></div>
    <div class="card-footer bg-transparent"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Save plan</button></div></form>
    <script>
    (function () { var v = document.getElementById('pmVessel'), e = document.getElementById('pmEq');
      function f() { Array.prototype.forEach.call(e.options, function (o) { if (!o.value) return; var ok = o.dataset.vessel === v.value; o.hidden = !ok; o.disabled = !ok; if (!ok && o.selected) e.value = ''; }); if (e.comboSync) e.comboSync(); }
      v.addEventListener('change', f); f(); })();
    </script>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ one plan
if ($plan) {
    $wos = DB::all('SELECT * FROM shp_work_orders WHERE pm_plan_id = :p ORDER BY id DESC LIMIT 20', ['p' => $plan['id']]);
    $actions = ($canEdit ? btn_link(url($page, ['a' => 'edit', 'id' => $plan['id']]), 'Edit', 'btn-outline-primary', 'pencil') . ' ' : '');
    layout_start($plan['name'], ['actions' => $actions]);
    ?>
    <div class="row g-3">
      <div class="col-lg-7"><div class="card reveal mb-3"><div class="card-body"><div class="info-grid">
        <?= info_pair('Vessel', $plan['vessel_name']) ?><?= info_pair('Equipment', $plan['eq_code'] ? $plan['eq_code'] . ' ' . $plan['eq_name'] : 'The vessel as a whole') ?>
        <?= info_pair('Basis', PM_BASIS[$plan['basis']] ?? $plan['basis']) ?><?= info_pair('Interval', pm_interval_text($plan)) ?>
        <?= info_pair('Last done', trim(($plan['last_done_on'] ? fdate($plan['last_done_on']) : '') . ($plan['last_done_hours'] !== null ? ' at ' . number_format((float)$plan['last_done_hours'], 1) . ' h' : '')) ?: 'Not yet') ?>
        <?= info_pair('Next due', pm_next_text($plan)) ?><?= $plan['running_hours'] !== null && $plan['interval_hours'] ? info_pair('Meter now', number_format((float)$plan['running_hours'], 1) . ' h') : '' ?>
        <?= info_pair('Estimated work', qty($plan['est_hours']) . ' man-hours') ?><?= info_pair('Statutory', (int)$plan['is_regulatory'] ? 'Yes' : 'No') ?><?= info_pair('Active', (int)$plan['is_active'] ? 'Yes' : 'Switched off') ?>
      </div></div></div>
      <?php if ($plan['checklist']): ?><div class="card reveal"><div class="card-header">Checklist</div><div class="card-body"><ol class="small mb-0"><?php foreach (preg_split('/\R/', (string)$plan['checklist']) as $line): if (trim($line) === '') { continue; } ?><li><?= h(trim($line)) ?></li><?php endforeach; ?></ol></div></div><?php endif; ?>
      </div>
      <div class="col-lg-5"><div class="card reveal"><div class="card-header">Work done under this plan</div><ul class="list-group list-group-flush">
        <?php foreach ($wos as $w): ?><li class="list-group-item d-flex justify-content-between gap-2"><span><?= safe_link('fleet.workorders', ['id' => $w['id']], $w['wo_no']) ?><div class="small text-muted"><?= h(fdate($w['created_at'])) ?><?= $w['accepted_at'] ? ', closed ' . h(fdate($w['accepted_at'])) : '' ?></div></span><?= Ship::label($w['status']) ?></li><?php endforeach; ?>
        <?php if (!$wos): ?><li class="list-group-item"><?= empty_note('Not raised yet.', 'calendar-check') ?></li><?php endif; ?>
      </ul>
      <?php if (Access::can($page, 'delete') && !$wos): ?><div class="card-footer bg-transparent"><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><button class="btn btn-sm btn-link text-danger px-0" data-confirm="Delete this plan?"><i class="bi bi-trash"></i> Delete the plan</button></form></div><?php endif; ?>
      </div></div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ due list and all plans
$tab = (string)g('tab', 'due');
$due = Cmms::pmDue($vessel ?: null);
$overdue = array_filter($due, fn($p) => $p['overdue']);
$where = '1 = 1';
$par = [];
if ($vessel) {
    $where .= ' AND p.vessel_id = :v';
    $par['v'] = $vessel;
}
$plans = DB::all("SELECT p.*, s.code AS vessel_code, e.code AS eq_code, e.name AS eq_name, e.running_hours, w.wo_no, w.status AS wo_status
                    FROM shp_pm_plans p JOIN shp_vessels s ON s.id = p.vessel_id LEFT JOIN shp_equipment e ON e.id = p.equipment_id
                    LEFT JOIN shp_work_orders w ON w.id = p.open_wo_id WHERE $where ORDER BY p.is_active DESC, s.code, p.next_due_on IS NULL, p.next_due_on, p.name", $par);
$openWo = (int)DB::value("SELECT COUNT(*) FROM shp_work_orders WHERE pm_plan_id IS NOT NULL AND status NOT IN ('closed', 'cancelled', 'completed')" . ($vessel ? ' AND vessel_id = ' . (int)$vessel : ''));
$noWo = array_filter($due, fn($p) => !$p['open_wo_id'] || !DB::value("SELECT 1 FROM shp_work_orders WHERE id = :id AND status NOT IN ('closed', 'cancelled')", ['id' => $p['open_wo_id']]));
$actions = ($canCreate ? btn_link(url($page, ['a' => 'new', 'vessel' => $vessel]), 'New plan', 'btn-primary', 'plus-lg') . ' ' : '')
    . (Access::page('rpt.shp_pm_due') ? btn_link(url('rpt.shp_pm_due'), 'Due report', 'btn-outline-secondary', 'bar-chart') : '');
layout_start('Planned Maintenance', ['actions' => $actions]);
echo '<div class="kpi-grid mb-3">'
    . kpi_tile('Active plans', (string)count(array_filter($plans, fn($p) => (int)$p['is_active'] === 1)), 'calendar-check', 'Calendar, running hours and surveys', '')
    . kpi_tile('Due now', (string)count($due), 'alarm', count($noWo) . ' without a work order yet', count($noWo) ? 'warn' : '', url($page, ['tab' => 'due', 'vessel' => $vessel]), (float)count($due))
    . kpi_tile('Overdue', (string)count($overdue), 'exclamation-octagon', 'Past the date or the hours', count($overdue) ? 'bad' : 'good', null, (float)count($overdue))
    . kpi_tile('Planned work open', (string)$openWo, 'tools', 'Preventive work orders not closed', '', Access::page('fleet.workorders') ? url('fleet.workorders') : null, (float)$openWo)
    . '</div>';
?>
<form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
  <?= select_field('vessel', 'Vessel', Ship::vesselOptions(false), $vessel, ['class' => '', 'blank' => 'Every vessel', 'extra' => 'data-autosubmit']) ?>
</form>
<?= tabs_line(['due' => ['Due and overdue', url($page, array_filter(['tab' => 'due', 'vessel' => $vessel])), count($due)], 'plans' => ['All plans', url($page, array_filter(['tab' => 'plans', 'vessel' => $vessel])), count($plans)]], $tab) ?>
<?php if ($tab === 'due'): ?>
<div class="card reveal"><div class="card-header d-flex flex-wrap justify-content-between gap-2 align-items-center"><span>Falling due within their lead time</span>
  <?php if ($canCreate && $noWo): ?><form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="act" value="raise"><button class="btn btn-sm btn-primary"><i class="bi bi-lightning-charge"></i> Raise work orders for the <?= count($noWo) ?> without one</button></form><?php endif; ?></div>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>Task</th><th>Vessel</th><th>Equipment</th><th>Due</th><th class="num">Meter now</th><th>State</th><th>Work order</th></tr></thead><tbody>
  <?php foreach ($due as $p): $wo = $p['open_wo_id'] ? DB::row('SELECT id, wo_no, status FROM shp_work_orders WHERE id = :id', ['id' => $p['open_wo_id']]) : null; ?>
    <tr><td class="fw-semibold"><a href="<?= h(url($page, ['id' => $p['id']])) ?>"><?= h($p['name']) ?></a><?= (int)$p['is_regulatory'] ? '<div class="small text-muted">Statutory</div>' : '' ?></td>
      <td><?= h($p['vessel_code']) ?></td><td class="small"><?= h(trim($p['eq_code'] . ' ' . $p['eq_name'])) ?></td><td class="small text-nowrap"><?= h(pm_next_text($p)) ?></td>
      <td class="num small"><?= $p['next_due_hours'] !== null ? number_format((float)$p['running_hours'], 1) . ' h' : '' ?></td>
      <td><?= $p['overdue'] ? '<span class="st st-bad">Overdue</span>' : '<span class="st st-warn">Due</span>' ?></td>
      <td><?= $wo ? safe_link('fleet.workorders', ['id' => $wo['id']], $wo['wo_no']) . ' ' . Ship::label($wo['status']) : '<span class="text-muted small">None yet</span>' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$due): ?><tr><td colspan="7"><?= empty_note('Nothing is due. The fleet is up to date with its planned maintenance.', 'check2-circle') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php else: ?>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>Task</th><th>Vessel</th><th>Equipment</th><th>Interval</th><th>Last done</th><th>Next due</th><th>Open work</th><th></th></tr></thead><tbody>
  <?php foreach ($plans as $p): ?>
    <tr class="<?= (int)$p['is_active'] ? '' : 'text-muted' ?>"><td class="fw-semibold"><a href="<?= h(url($page, ['id' => $p['id']])) ?>"><?= h($p['name']) ?></a></td><td><?= h($p['vessel_code']) ?></td>
      <td class="small"><?= h(trim($p['eq_code'] . ' ' . $p['eq_name'])) ?></td><td class="small"><?= h(pm_interval_text($p)) ?></td>
      <td class="small"><?= h(trim(($p['last_done_on'] ? fdate($p['last_done_on']) : '') . ($p['last_done_hours'] !== null ? ' / ' . number_format((float)$p['last_done_hours'], 0) . ' h' : ''))) ?></td>
      <td class="small text-nowrap"><?= h(pm_next_text($p)) ?></td><td><?= $p['wo_no'] ? h($p['wo_no']) . ' ' . Ship::label($p['wo_status']) : '' ?></td>
      <td><?= (int)$p['is_active'] ? '' : '<span class="st st-muted">Off</span>' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$plans): ?><tr><td colspan="8"><?= empty_note('No maintenance plan yet.', 'calendar-check') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php endif;
layout_end();
