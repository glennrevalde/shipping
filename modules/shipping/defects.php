<?php
declare(strict_types=1);

$page = 'fleet.defects';
$id = gint('id');
$a = (string)g('a');
$df = $id ? DB::row('SELECT d.*, s.name AS vessel_name, s.code AS vessel_code, e.code AS eq_code, e.name AS eq_name, u.full_name AS reporter, t.full_name AS triager,
                            w.wo_no, w.status AS wo_status, h.status AS hold_status, h.hold_type
                       FROM shp_defects d JOIN shp_vessels s ON s.id = d.vessel_id LEFT JOIN shp_equipment e ON e.id = d.equipment_id
                       LEFT JOIN users u ON u.id = d.reported_by LEFT JOIN users t ON t.id = d.triaged_by LEFT JOIN shp_work_orders w ON w.id = d.work_order_id
                       LEFT JOIN shp_holds h ON h.id = d.hold_id WHERE d.id = :id', ['id' => $id]) : null;
if ($id && !$df) {
    flash('warning', 'That defect report no longer exists.');
    redirect(url($page));
}
const DEF_STATUS = ['reported' => 'Reported', 'triaged' => 'Triaged', 'work_order' => 'Work order raised', 'resolved' => 'Repaired', 'closed' => 'Closed'];
if (!function_exists('def_label')) {
    function def_label(string $s): string
    {
        $tone = ['reported' => 'warn', 'triaged' => 'info', 'work_order' => 'info', 'resolved' => 'good', 'closed' => 'good'][$s] ?? 'muted';
        return '<span class="st st-' . $tone . '">' . h(DEF_STATUS[$s] ?? $s) . '</span>';
    }
}

handle_post(function () use ($page, $df) {
    $act = (string)p('act');
    if ($act === 'report') {
        ship_require($page, 'create');
        $nid = Cmms::reportDefect(['vessel_id' => pint('vessel_id'), 'equipment_id' => pint('equipment_id'), 'title' => p('title'), 'description' => p('description') ?: null,
            'severity' => p('severity'), 'safety_critical' => p('safety_critical') === '1', 'operating_impact' => p('operating_impact') ?: null,
            'reported_at' => p('reported_at') ? date('Y-m-d H:i:s', strtotime((string)p('reported_at'))) : null]);
        $crit = p('safety_critical') === '1';
        flash($crit ? 'warning' : 'success', $crit ? 'Defect reported as safety-critical: the vessel is on a no-sail hold until the repair is inspected and released by two people.' : 'Defect reported; the engineers were told.');
        redirect(url($page, ['id' => $nid]));
    }
    if (!$df) {
        fail('Defect not found.');
    }
    if ($act === 'triage') {
        ship_require($page, 'edit');
        $sev = in_array(p('severity'), ['minor', 'major', 'critical'], true) ? (string)p('severity') : 'minor';
        $wo = Cmms::triage((int)$df['id'], $sev, p('safety_critical') === '1', p('raise_wo') === '1', pint('assigned_to'), (string)p('notes'));
        flash('success', $wo ? 'Triaged; work order ' . DB::value('SELECT wo_no FROM shp_work_orders WHERE id = :w', ['w' => $wo]) . ' raised.' : 'Triaged.');
        redirect($wo && Access::page('fleet.workorders') ? url('fleet.workorders', ['id' => $wo]) : url($page, ['id' => $df['id']]));
    }
    if ($act === 'close') {
        ship_require($page, 'edit');
        if (!in_array($df['status'], ['reported', 'triaged'], true) || $df['hold_status'] === 'active') {
            fail('Only a defect without a work order and without an active hold is closed here.');
        }
        if (trim((string)p('notes')) === '') {
            fail('Say why the defect is closed without repair (not a defect, duplicate, fixed on the spot).');
        }
        DB::update('shp_defects', ['status' => 'closed'], 'id = :id', ['id' => $df['id']]);
        Ship::audit('close', 'fleet', 'shp_defect', (int)$df['id'], 'Defect ' . $df['defect_no'] . ' closed without a work order', ['reason' => (string)p('notes'), 'page_key' => $page]);
        flash('success', 'Defect closed.');
        redirect(url($page, ['id' => $df['id']]));
    }
    fail('Unknown action.');
});

$canEdit = Access::can($page, 'edit');
$canCreate = Access::can($page, 'create');

// ------------------------------------------------------------------ report a defect
if ($a === 'new' || (is_post() && p('act') === 'report')) {
    ship_require($page, 'create');
    $vSel = (string)fv('vessel_id', null, gint('vessel'));
    layout_start('Report a defect', ['actions' => btn_link(url($page), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" class="card reveal" style="max-width:920px"><?= csrf_field() ?><input type="hidden" name="act" value="report"><div class="card-body"><div class="row g-3">
      <?= select_field('vessel_id', 'Vessel', Ship::vesselOptions(), $vSel, ['class' => 'col-md-5', 'blank' => '', 'required' => true, 'extra' => 'id="dfVessel"']) ?>
      <div class="col-md-7"><label class="form-label">Equipment (if known)</label><select class="form-select form-select-sm" name="equipment_id" id="dfEq"><option value=""></option>
        <?php foreach (DB::all('SELECT id, vessel_id, code, name FROM shp_equipment WHERE is_active = 1 ORDER BY code') as $q): ?>
          <option value="<?= (int)$q['id'] ?>" data-vessel="<?= (int)$q['vessel_id'] ?>" <?= (string)fv('equipment_id', null, gint('equipment')) === (string)$q['id'] ? 'selected' : '' ?>><?= h($q['code'] . ' ' . $q['name']) ?></option>
        <?php endforeach; ?></select></div>
      <?= input_field('title', 'What is wrong, in a few words', p('title'), ['class' => 'col-12', 'required' => true]) ?>
      <div class="col-12"><label class="form-label">What was seen (where, when, readings, what was done already)</label><textarea class="form-control form-control-sm" name="description" rows="4"><?= h((string)p('description')) ?></textarea></div>
      <?= select_field('severity', 'Severity', ['minor' => 'Minor: no effect on sailing', 'major' => 'Major: limits operation', 'critical' => 'Critical: affects safety'], p('severity', 'minor'), ['class' => 'col-md-5']) ?>
      <?= input_field('operating_impact', 'Effect on operation', p('operating_impact'), ['class' => 'col-md-7']) ?>
      <?= input_field('reported_at', 'Seen at', p('reported_at', date('Y-m-d\TH:i', strtotime(Ship::now()))), ['type' => 'datetime-local', 'class' => 'col-md-5']) ?>
      <div class="col-md-7 d-flex align-items-end"><div class="form-check p-2 ps-4 rounded-3 w-100" style="background:var(--hull-tint)"><input type="hidden" name="safety_critical" value="0">
        <input class="form-check-input" type="checkbox" id="dfCrit" name="safety_critical" value="1" <?= checked(p('safety_critical') === '1') ?>>
        <label class="form-check-label small fw-semibold" for="dfCrit">Safety-critical: the vessel must not sail until this is repaired (places a no-sail hold at once)</label></div></div>
    </div></div>
    <div class="card-footer bg-transparent"><button class="btn btn-danger btn-sm"><i class="bi bi-exclamation-triangle"></i> Report defect</button></div></form>
    <script>
    (function () { var v = document.getElementById('dfVessel'), e = document.getElementById('dfEq');
      function f() { Array.prototype.forEach.call(e.options, function (o) { if (!o.value) return; var ok = !v.value || o.dataset.vessel === v.value; o.hidden = !ok; o.disabled = !ok; if (!ok && o.selected) e.value = ''; }); if (e.comboSync) e.comboSync(); }
      v.addEventListener('change', f); f(); })();
    </script>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ one defect
if ($df) {
    $actions = $df['work_order_id'] ? (Access::page('fleet.workorders') ? btn_link(url('fleet.workorders', ['id' => $df['work_order_id']]), 'Work order ' . $df['wo_no'], 'btn-outline-primary', 'tools') : '') : '';
    $actions .= $df['hold_id'] && Access::page('ops.holds') ? ' ' . btn_link(url('ops.holds', ['vessel' => $df['vessel_id']]), 'Vessel holds', 'btn-outline-danger', 'sign-stop') : '';
    layout_start('Defect ' . $df['defect_no'], ['actions' => $actions]);
    $assignees = DB::pairs("SELECT DISTINCT u.id, u.full_name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                             WHERE u.status = 'active' AND r.name IN ('Engineer / Technician', 'Chief Engineer', 'Maintenance Planner') ORDER BY u.full_name");
    ?>
    <div class="card reveal mb-3"><div class="card-body d-flex flex-wrap justify-content-between gap-3">
      <div><div class="fs-5 fw-bold"><?= h($df['title']) ?></div><div class="small text-muted"><?= h($df['vessel_name']) ?><?= $df['eq_code'] ? ', ' . safe_link('fleet.equipment', ['id' => $df['equipment_id']], $df['eq_code'] . ' ' . $df['eq_name']) : '' ?></div>
        <div class="mt-1 d-flex flex-wrap gap-3"><?= Ship::severityLabel($df['severity']) ?><?= def_label((string)$df['status']) ?><?= (int)$df['safety_critical'] ? '<span class="st st-bad">Safety-critical</span>' : '' ?></div></div>
      <?php if ($df['hold_id']): ?><div class="text-lg-end"><div class="small text-muted">Vessel hold</div><div><?= Ship::label($df['hold_status'] === 'active' ? 'no_sail' : 'released', ['no_sail' => 'No-sail hold active', 'released' => 'Hold released']) ?></div></div><?php endif; ?>
    </div></div>
    <div class="mb-3"><?= flow_steps(['reported' => 'Reported', 'triaged' => 'Triaged', 'work_order' => 'Work order', 'resolved' => 'Repaired', 'closed' => 'Closed'], $df['status']) ?></div>
    <div class="row g-3">
      <div class="col-lg-7"><div class="card reveal"><div class="card-header">Report</div><div class="card-body">
        <p style="white-space:pre-line" class="small"><?= h((string)($df['description'] ?: 'No further description.')) ?></p>
        <div class="info-grid">
          <?= info_pair('Reported by', $df['reporter'] . ', ' . fdatetime($df['reported_at'])) ?><?= info_pair('Effect on operation', $df['operating_impact'] ?: '-') ?>
          <?= $df['triaged_by'] ? info_pair('Triaged by', $df['triager'] . ', ' . fdatetime($df['triaged_at'])) : '' ?>
          <?= $df['wo_no'] ? info_pair('Work order', safe_link('fleet.workorders', ['id' => $df['work_order_id']], $df['wo_no']) . ' ' . Ship::label($df['wo_status']), true) : '' ?>
        </div></div></div></div>
      <div class="col-lg-5">
      <?php if ($canEdit && in_array($df['status'], ['reported', 'triaged'], true)): ?>
        <form method="post" class="card reveal mb-3"><?= csrf_field() ?><input type="hidden" name="act" value="triage"><div class="card-header">Triage</div><div class="card-body"><div class="row g-2">
          <?= select_field('severity', 'Severity', ['minor' => 'Minor', 'major' => 'Major', 'critical' => 'Critical'], $df['severity'], ['class' => 'col-6']) ?>
          <?= select_field('assigned_to', 'Assign the work to', $assignees, '', ['class' => 'col-6', 'blank' => '']) ?>
          <?= check_field('safety_critical', 'Safety-critical (no-sail hold)', (int)$df['safety_critical'] === 1, 'col-12') ?>
          <?= check_field('raise_wo', 'Raise a corrective work order now', true, 'col-12') ?>
          <div class="col-12"><label class="form-label">Triage notes</label><textarea class="form-control form-control-sm" name="notes" rows="2"></textarea></div>
        </div><div class="form-text">A hold placed for a safety-critical defect is not lifted by triage; it is released after the repair passes inspection.</div></div>
        <div class="card-footer bg-transparent"><button class="btn btn-primary btn-sm"><i class="bi bi-clipboard2-check"></i> Save triage</button></div></form>
        <?php if ($df['hold_status'] !== 'active'): ?>
        <details class="card reveal"><summary class="card-header small">Close without repair</summary><form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="act" value="close">
          <textarea class="form-control form-control-sm mb-2" name="notes" rows="2" required placeholder="Not a defect, a duplicate of another report, fixed on the spot"></textarea>
          <button class="btn btn-sm btn-outline-secondary">Close the report</button></form></details>
        <?php endif; ?>
      <?php else: ?>
        <div class="card reveal"><div class="card-body small text-muted"><?= $df['status'] === 'closed' ? 'This report is closed.' : ($df['work_order_id'] ? 'The repair is followed on its work order.' : 'Your role can report defects; an engineer triages them.') ?></div></div>
      <?php endif; ?>
      </div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$tab = (string)g('tab', 'open');
$vessel = gint('vessel');
$groups = ['open' => ['Open', ['reported', 'triaged', 'work_order']], 'new' => ['Waiting for triage', ['reported']], 'done' => ['Repaired and closed', ['resolved', 'closed']], 'all' => ['All', []]];
if (!isset($groups[$tab])) {
    $tab = 'open';
}
$where = '1 = 1';
$par = [];
if ($vessel) {
    $where .= ' AND d.vessel_id = :v';
    $par['v'] = $vessel;
}
$counts = DB::pairs("SELECT d.status, COUNT(*) FROM shp_defects d WHERE $where GROUP BY d.status", $par);
$n = fn(array $s) => $s ? array_sum(array_map(fn($k) => (int)($counts[$k] ?? 0), $s)) : array_sum(array_map('intval', $counts));
$lw = $where;
if ($groups[$tab][1]) {
    [$in, $ip] = DB::in('ds', $groups[$tab][1]);
    $lw .= " AND d.status IN $in";
    $par += $ip;
}
$rows = DB::all("SELECT d.*, s.code AS vessel_code, e.code AS eq_code, e.name AS eq_name, u.full_name AS reporter, w.wo_no, w.status AS wo_status, h.status AS hold_status
                   FROM shp_defects d JOIN shp_vessels s ON s.id = d.vessel_id LEFT JOIN shp_equipment e ON e.id = d.equipment_id LEFT JOIN users u ON u.id = d.reported_by
                   LEFT JOIN shp_work_orders w ON w.id = d.work_order_id LEFT JOIN shp_holds h ON h.id = d.hold_id
                  WHERE $lw ORDER BY FIELD(d.status, 'reported', 'triaged', 'work_order', 'resolved', 'closed'), d.safety_critical DESC, d.reported_at DESC LIMIT 300", $par);
$holds = (int)DB::value("SELECT COUNT(*) FROM shp_holds WHERE source_type = 'defect' AND status = 'active'" . ($vessel ? ' AND vessel_id = ' . (int)$vessel : ''));
layout_start('Defect Reports', ['actions' => $canCreate ? btn_link(url($page, ['a' => 'new', 'vessel' => $vessel]), 'Report a defect', 'btn-danger', 'exclamation-triangle') : '']);
echo '<div class="kpi-grid mb-3">'
    . kpi_tile('Open defects', (string)$n($groups['open'][1]), 'exclamation-triangle', 'Reported, triaged or being repaired', '', null, (float)$n($groups['open'][1]))
    . kpi_tile('Waiting for triage', (string)$n(['reported']), 'hourglass-split', 'An engineer confirms severity and raises the work', $n(['reported']) ? 'warn' : 'good', url($page, ['tab' => 'new', 'vessel' => $vessel]), (float)$n(['reported']))
    . kpi_tile('Holding a vessel', (string)$holds, 'sign-stop', 'Safety-critical defects with a no-sail hold', $holds ? 'bad' : 'good', Access::page('ops.holds') ? url('ops.holds') : null, (float)$holds)
    . kpi_tile('Repaired', (string)$n(['resolved', 'closed']), 'check2-circle', 'Resolved or closed', 'good', url($page, ['tab' => 'done', 'vessel' => $vessel]), (float)$n(['resolved', 'closed']))
    . '</div>';
$tabs = [];
foreach ($groups as $k => [$l, $s]) {
    $tabs[$k] = [$l, url($page, array_filter(['tab' => $k, 'vessel' => $vessel])), $n($s)];
}
?>
<form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
  <?= select_field('vessel', 'Vessel', Ship::vesselOptions(false), $vessel, ['class' => '', 'blank' => 'Every vessel', 'extra' => 'data-autosubmit']) ?>
</form>
<?= tabs_line($tabs, $tab) ?>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>Report</th><th>Vessel</th><th>Defect</th><th>Equipment</th><th>Severity</th><th>Reported</th><th>Work order</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="fw-semibold text-nowrap"><a href="<?= h(url($page, ['id' => $r['id']])) ?>"><?= h($r['defect_no']) ?></a></td><td><?= h($r['vessel_code']) ?></td>
      <td><?= h($r['title']) ?><?= (int)$r['safety_critical'] ? '<div class="small ' . ($r['hold_status'] === 'active' ? 'text-danger' : 'text-muted') . '">Safety-critical' . ($r['hold_status'] === 'active' ? ', vessel held' : '') . '</div>' : '' ?></td>
      <td class="small"><?= h(trim($r['eq_code'] . ' ' . $r['eq_name'])) ?></td><td><?= Ship::severityLabel($r['severity']) ?></td>
      <td class="small"><?= h(fdatetime($r['reported_at'])) ?><div class="text-muted"><?= h((string)$r['reporter']) ?></div></td>
      <td class="small"><?= $r['wo_no'] ? safe_link('fleet.workorders', ['id' => $r['work_order_id']], $r['wo_no']) . ' ' . Ship::label($r['wo_status']) : '' ?></td><td><?= def_label((string)$r['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8"><?= empty_note('No defect report here.', 'shield-check') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php
layout_end();
