<?php
declare(strict_types=1);

$page = 'fleet.workorders';
$id = gint('id');
$a = (string)g('a');
$w = $id ? Cmms::wo($id) : null;
if ($id && !$w) {
    flash('warning', 'That work order no longer exists.');
    redirect(url($page));
}
const WO_TYPES = ['corrective' => 'Corrective (repair)', 'preventive' => 'Preventive', 'inspection' => 'Inspection', 'survey' => 'Survey / statutory', 'project' => 'Project / improvement'];

if (!function_exists('wo_people')) {
    function wo_people(): array
    {
        return DB::pairs("SELECT DISTINCT u.id, u.full_name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                           WHERE u.status = 'active' AND r.name IN ('Engineer / Technician', 'Chief Engineer', 'Maintenance Planner', 'Fleet Manager', 'Captain / Master') ORDER BY u.full_name");
    }

    function wo_checklist(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R|\\\\n/', (string)$text)), fn($l) => $l !== ''));
    }

    function wo_cost(array $w): float
    {
        return (float)$w['cost_labor'] + (float)$w['cost_parts'] + (float)$w['cost_services'];
    }
}

handle_post(function () use ($page, $w) {
    $act = (string)p('act');
    if ($act === 'create') {
        ship_require($page, 'create');
        $approve = p('approve_now') === '1' && Access::can($page, 'approve');
        $nid = Cmms::createWo(['vessel_id' => pint('vessel_id'), 'equipment_id' => pint('equipment_id'), 'wo_type' => isset(WO_TYPES[p('wo_type')]) ? p('wo_type') : 'corrective',
            'title' => p('title'), 'description' => p('description') ?: null, 'priority' => p('priority'), 'safety_critical' => p('safety_critical') === '1',
            'planned_start' => pdate('planned_start'), 'planned_end' => pdate('planned_end'), 'assigned_to' => pint('assigned_to'), 'expense_mode' => p('expense_mode'),
            'drydock_id' => pint('drydock_id'), 'checklist' => trim((string)p('checklist')) ?: null, 'status' => $approve ? 'approved' : 'reported']);
        flash('success', 'Work order ' . DB::value('SELECT wo_no FROM shp_work_orders WHERE id = :id', ['id' => $nid]) . ' raised' . ($approve ? ' and approved.' : '; it waits for triage and approval.'));
        redirect(url($page, ['id' => $nid]));
    }
    if (!$w) {
        fail('Work order not found.');
    }
    $back = url($page, ['id' => $w['id']]);
    ship_require($page, 'edit');
    switch ($act) {
        case 'status':
            $to = (string)p('to');
            $ctx = ['note' => p('note'), 'until' => pdate('until'), 'findings' => p('findings'), 'root_cause' => p('root_cause'), 'repair_procedure' => p('repair_procedure'),
                'reading_before' => p('reading_before'), 'reading_after' => p('reading_after'), 'downtime_hours' => p('downtime_hours'), 'inspection_result' => p('inspection_result')];
            if (isset($_POST['ck_present'])) {
                $ctx['checklist_done'] = implode("\n", array_map('strval', (array)($_POST['ck'] ?? [])));
            }
            if ($to === 'in_progress' && $w['status'] === 'inspection' && trim((string)p('note')) === '') {
                fail('Write what failed at the inspection; the job goes back to the technician.');
            }
            Cmms::transition((int)$w['id'], $to, $ctx);
            $now = DB::value('SELECT status FROM shp_work_orders WHERE id = :id', ['id' => $w['id']]);
            flash('success', $now === $w['status'] ? 'Deferral of this safety-critical job sent for approval; the vessel hold stays meanwhile.' : $w['wo_no'] . ' is now ' . strtolower(strip_tags(Ship::label((string)$now))) . '.');
            break;
        case 'plan':
            if (in_array($w['status'], ['completed', 'closed', 'cancelled'], true)) {
                fail('The work is finished; its plan is no longer changed.');
            }
            $mode = p('expense_mode') === 'capex' ? 'capex' : 'opex';
            if ($mode !== $w['expense_mode'] && wo_cost($w) > 0) {
                fail('Costs are already booked as ' . ($w['expense_mode'] === 'capex' ? 'capital work' : 'repairs') . '; the treatment cannot change now. Ask the accountant to reclassify.');
            }
            $d = ['assigned_to' => pint('assigned_to'), 'planned_start' => pdate('planned_start'), 'planned_end' => pdate('planned_end'), 'priority' => in_array(p('priority'), ['low', 'normal', 'high', 'urgent'], true) ? p('priority') : $w['priority'],
                'expense_mode' => $mode, 'drydock_id' => pint('drydock_id'), 'updated_at' => Ship::now()];
            if ($d['planned_start'] && $d['planned_end'] && $d['planned_end'] < $d['planned_start']) {
                fail('The planned end comes after the planned start.');
            }
            DB::update('shp_work_orders', $d, 'id = :id', ['id' => $w['id']]);
            DB::insert('shp_wo_log', ['wo_id' => $w['id'], 'from_status' => $w['status'], 'to_status' => $w['status'], 'details' => 'Plan updated' . ($d['assigned_to'] ? ': assigned to ' . Ship::userName($d['assigned_to']) : ''), 'user_id' => Auth::id()]);
            Ship::audit('edit', 'fleet', 'shp_wo', (int)$w['id'], $w['wo_no'], ['changes' => Ship::changes($w, $d), 'page_key' => 'fleet.workorders']);
            if ($d['assigned_to'] && (int)$d['assigned_to'] !== (int)$w['assigned_to']) {
                Notify::user((int)$d['assigned_to'], 'wo:' . $w['id'] . ':assigned:' . $d['assigned_to'], 'wo_assigned', 'Work order ' . $w['wo_no'] . ' assigned to you', (string)$w['title'], 'shp_work_orders', (int)$w['id']);
            }
            flash('success', 'Plan saved.');
            break;
        case 'labor':
            $uid = pint('user_id');
            $name = $uid ? Ship::userName($uid) : trim((string)p('worker_name'));
            Cmms::addLabor((int)$w['id'], $name, $uid, pdate('work_date', Ship::today()), pnum('hours'), null, p('overtime') === '1', (string)p('notes'));
            flash('success', qty(pnum('hours')) . ' h of ' . $name . ' logged.');
            break;
        case 'part':
            [$item, $site] = array_map('intval', explode(':', (string)p('pick')) + [0, 0]);
            if (!$item || !$site) {
                fail('Choose the part and the store it is taken from.');
            }
            $pid = Cmms::issuePart((int)$w['id'], $item, $site, pnum('qty'), pdate('issue_date', Ship::today()));
            flash('success', 'Part issued to the job' . (Access::viewCosts() ? ' at ₱' . money(DB::value('SELECT amount FROM shp_wo_parts WHERE id = :id', ['id' => $pid])) : '') . '.');
            break;
        case 'service':
            $vendor = (int)pint('vendor_id');
            if (!$vendor) {
                fail('Choose the contractor.');
            }
            Cmms::addService((int)$w['id'], $vendor, (string)p('description'), pnum('amount'), (string)p('ref_no'), p('vatable') === '1', pdate('bill_date', Ship::today()));
            flash('success', 'Contractor\'s bill entered in Accounts Payable.');
            break;
        default:
            fail('Unknown action.');
    }
    redirect($back);
});

$canEdit = Access::can($page, 'edit');
$canCreate = Access::can($page, 'create');
$canApprove = Access::can($page, 'approve');
$costs = Access::viewCosts();
$drydocks = DB::pairs("SELECT id, CONCAT(dd_no, ' ', title) FROM shp_drydock WHERE status NOT IN ('completed', 'cancelled') ORDER BY planned_start");

// ------------------------------------------------------------------ new work order
if ($a === 'new' || (is_post() && p('act') === 'create')) {
    ship_require($page, 'create');
    $vSel = (string)fv('vessel_id', null, gint('vessel'));
    layout_start('New work order', ['actions' => btn_link(url($page), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" class="card reveal" style="max-width:1040px"><?= csrf_field() ?><input type="hidden" name="act" value="create"><div class="card-body"><div class="row g-3">
      <?= select_field('vessel_id', 'Vessel', Ship::vesselOptions(), $vSel, ['class' => 'col-md-4', 'blank' => '', 'required' => true, 'extra' => 'id="woVessel"']) ?>
      <div class="col-md-5"><label class="form-label">Equipment</label><select class="form-select form-select-sm" name="equipment_id" id="woEq"><option value=""></option>
        <?php foreach (DB::all('SELECT id, vessel_id, code, name FROM shp_equipment WHERE is_active = 1 ORDER BY code') as $q): ?>
          <option value="<?= (int)$q['id'] ?>" data-vessel="<?= (int)$q['vessel_id'] ?>" <?= (string)fv('equipment_id', null, gint('equipment')) === (string)$q['id'] ? 'selected' : '' ?>><?= h($q['code'] . ' ' . $q['name']) ?></option>
        <?php endforeach; ?></select></div>
      <?= select_field('wo_type', 'Type', WO_TYPES, p('wo_type', 'corrective'), ['class' => 'col-md-3']) ?>
      <?= input_field('title', 'Work to be done', p('title'), ['class' => 'col-md-9', 'required' => true]) ?>
      <?= select_field('priority', 'Priority', ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'], p('priority', 'normal'), ['class' => 'col-md-3']) ?>
      <div class="col-12"><label class="form-label">Description</label><textarea class="form-control form-control-sm" name="description" rows="3"><?= h((string)p('description')) ?></textarea></div>
      <?= select_field('assigned_to', 'Assigned to', wo_people(), p('assigned_to'), ['class' => 'col-md-4', 'blank' => '']) ?>
      <?= input_field('planned_start', 'Planned start', p('planned_start'), ['type' => 'date', 'class' => 'col-md-2']) ?><?= input_field('planned_end', 'Planned end', p('planned_end'), ['type' => 'date', 'class' => 'col-md-2']) ?>
      <?= select_field('expense_mode', 'Accounting', ['opex' => 'Repairs and maintenance (expense)', 'capex' => 'Capital work (to the asset)'], p('expense_mode', 'opex'), ['class' => 'col-md-4']) ?>
      <?php if ($drydocks): ?><?= select_field('drydock_id', 'Part of dry-docking', $drydocks, p('drydock_id', gint('drydock')), ['class' => 'col-md-6', 'blank' => '']) ?><?php endif; ?>
      <div class="col-12"><label class="form-label">Checklist (one step per line)</label><textarea class="form-control form-control-sm" name="checklist" rows="3"><?= h((string)p('checklist')) ?></textarea></div>
      <?= check_field('safety_critical', 'Safety-critical work (inspected by an engineer with the technical-release permission)', p('safety_critical') === '1', 'col-md-8') ?>
      <?php if ($canApprove): ?><?= check_field('approve_now', 'Approve it now', p('approve_now') === '1', 'col-md-4') ?><?php endif; ?>
    </div></div><div class="card-footer bg-transparent"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Raise work order</button></div></form>
    <script>
    (function () { var v = document.getElementById('woVessel'), e = document.getElementById('woEq');
      function f() { Array.prototype.forEach.call(e.options, function (o) { if (!o.value) return; var ok = !v.value || o.dataset.vessel === v.value; o.hidden = !ok; o.disabled = !ok; if (!ok && o.selected) e.value = ''; }); if (e.comboSync) e.comboSync(); }
      v.addEventListener('change', f); f(); })();
    </script>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ one work order
if ($w) {
    $labor = DB::all('SELECT * FROM shp_wo_labor WHERE wo_id = :w ORDER BY work_date, id', ['w' => $w['id']]);
    $parts = DB::all('SELECT p.*, i.code, i.name, i.unit, s.name AS site_name FROM shp_wo_parts p JOIN items i ON i.id = p.item_id JOIN sites s ON s.id = p.site_id WHERE p.wo_id = :w ORDER BY p.id', ['w' => $w['id']]);
    $services = DB::all('SELECT x.*, v.name AS vendor FROM shp_wo_services x JOIN vendors v ON v.id = x.vendor_id WHERE x.wo_id = :w ORDER BY x.id', ['w' => $w['id']]);
    $log = DB::all('SELECT l.*, u.full_name FROM shp_wo_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.wo_id = :w ORDER BY l.id DESC', ['w' => $w['id']]);
    $checklist = wo_checklist($w['checklist']);
    $done = wo_checklist($w['checklist_done']);
    $open = in_array($w['status'], ['approved', 'planned', 'waiting_parts', 'in_progress', 'inspection'], true);

    // ------------------------------------------------------------------ printable job card
    if (g('print') === '1') {
        ship_require($page, 'print');
        layout_start('Job card ' . $w['wo_no'], ['bare' => true, 'no_title' => true]);
        ?>
        <div class="print-bar no-print"><button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button><?= btn_link(url($page, ['id' => $w['id']]), 'Back', 'btn-outline-secondary', 'arrow-left') ?></div>
        <div class="doc-sheet">
          <div class="doc-head"><div class="co"><?= h(Settings::get('company_name')) ?><small><?= h($w['vessel_name']) ?><?= $w['eq_code'] ? ', ' . h($w['eq_code'] . ' ' . $w['eq_name']) : '' ?></small></div>
            <div class="doc-no"><span>Maintenance job card</span><b><?= h($w['wo_no']) ?></b><span><?= h(WO_TYPES[$w['wo_type']] ?? $w['wo_type']) ?>, <?= h(ucfirst($w['priority'])) ?> priority</span></div></div>
          <div class="doc-title">Work</div><div class="fw-semibold mb-1"><?= h($w['title']) ?></div><p class="small" style="white-space:pre-line"><?= h((string)$w['description']) ?></p>
          <div class="row g-3 small mb-2">
            <div class="col-sm-4"><div class="doc-title">Assigned to</div><?= h(Ship::userName($w['assigned_to'] ? (int)$w['assigned_to'] : null) ?: '-') ?></div>
            <div class="col-sm-4"><div class="doc-title">Planned</div><?= h(trim(fdate($w['planned_start']) . ' to ' . fdate($w['planned_end']), ' to') ?: '-') ?></div>
            <div class="col-sm-4"><div class="doc-title">Defect</div><?= h($w['defect_no'] ?: '-') ?><?= (int)$w['safety_critical'] ? ', safety-critical' : '' ?></div>
          </div>
          <?php if ($checklist): ?><div class="doc-title">Checklist</div><ul class="list-unstyled small mb-3"><?php foreach ($checklist as $c): ?><li class="mb-1"><span class="ckbox <?= in_array($c, $done, true) ? 'on' : '' ?>"></span><?= h($c) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <table class="table table-sm"><thead><tr><th>Date</th><th>Worker</th><th class="num">Hours</th><th>Work done</th></tr></thead><tbody>
          <?php foreach ($labor as $l): ?><tr><td><?= h(fdate($l['work_date'])) ?></td><td><?= h($l['worker_name']) ?></td><td class="num"><?= qty($l['hours']) ?></td><td><?= h((string)$l['notes']) ?></td></tr><?php endforeach; ?>
          <?php for ($i = count($labor); $i < 4; $i++): ?><tr><td>&nbsp;</td><td></td><td></td><td></td></tr><?php endfor; ?></tbody></table>
          <table class="table table-sm"><thead><tr><th>Part</th><th class="num">Qty</th><th>From</th></tr></thead><tbody>
          <?php foreach ($parts as $pt): ?><tr><td><?= h($pt['code'] . ' ' . $pt['name']) ?></td><td class="num"><?= qty($pt['qty']) ?> <?= h((string)$pt['unit']) ?></td><td><?= h($pt['site_name']) ?></td></tr><?php endforeach; ?>
          <?php for ($i = count($parts); $i < 3; $i++): ?><tr><td>&nbsp;</td><td></td><td></td></tr><?php endfor; ?></tbody></table>
          <div class="row g-3 small"><div class="col-sm-6"><div class="doc-title">Findings and root cause</div><?= h(trim($w['findings'] . ($w['root_cause'] ? '. Root cause: ' . $w['root_cause'] : ''), '. ')) ?: '<br><br>' ?></div>
            <div class="col-sm-6"><div class="doc-title">Readings before / after</div><?= h(trim($w['reading_before'] . ' / ' . $w['reading_after'], ' /')) ?: '<br><br>' ?></div></div>
          <div class="sign-row"><div>Done by (technician)</div><div>Inspected by (not the technician)</div><div>Accepted by</div></div>
        </div>
        <?php
        layout_end();
        return;
    }

    $actions = (Access::can($page, 'print') ? btn_link(url($page, ['id' => $w['id'], 'print' => 1]), 'Job card', 'btn-outline-secondary', 'printer') . ' ' : '')
        . ($open && Access::can('fleet.requisitions', 'create') ? btn_link(url('fleet.requisitions', ['a' => 'new', 'wo' => $w['id']]), 'Requisition parts', 'btn-outline-secondary', 'cart-plus') : '');
    layout_start('Work order ' . $w['wo_no'], ['actions' => $actions]);
    $next = Ship::WO_FLOW[$w['status']] ?? [];
    $flowNow = in_array($w['status'], ['deferred', 'cancelled'], true) ? 'triaged' : ($w['status'] === 'planned' || $w['status'] === 'waiting_parts' ? 'approved' : $w['status']);
    ?>
    <div class="card reveal mb-3"><div class="card-body d-flex flex-wrap justify-content-between gap-3">
      <div><div class="fs-5 fw-bold"><?= h($w['title']) ?></div>
        <div class="small text-muted"><?= h($w['vessel_name']) ?><?= $w['eq_code'] ? ', ' . safe_link('fleet.equipment', ['id' => $w['equipment_id']], $w['eq_code'] . ' ' . $w['eq_name']) : '' ?><?= $w['defect_no'] ? ', from defect ' . safe_link('fleet.defects', ['id' => $w['defect_id']], $w['defect_no']) : '' ?></div>
        <div class="mt-1 d-flex flex-wrap gap-3"><?= Ship::label($w['status']) ?><?= Ship::severityLabel($w['priority']) ?><?= (int)$w['safety_critical'] ? '<span class="st st-bad">Safety-critical</span>' : '' ?></div></div>
      <div class="text-lg-end small"><div class="text-muted"><?= h(WO_TYPES[$w['wo_type']] ?? $w['wo_type']) ?>, <?= $w['expense_mode'] === 'capex' ? 'capital work' : 'repairs expense' ?></div>
        <?php if ($costs): ?><div class="fs-4 fw-bold">₱<?= money(wo_cost($w)) ?></div><div class="text-muted">Labor <?= money($w['cost_labor']) ?>, parts <?= money($w['cost_parts']) ?>, services <?= money($w['cost_services']) ?></div><?php endif; ?></div>
    </div></div>
    <div class="mb-3"><?= flow_steps(['reported' => 'Reported', 'triaged' => 'Triaged', 'approved' => 'Approved', 'in_progress' => 'In progress', 'inspection' => 'Inspection', 'completed' => 'Completed', 'closed' => 'Closed'], $flowNow) ?></div>
    <div class="row g-3">
      <div class="col-xl-8">
        <div class="card reveal mb-3"><div class="card-header">The job</div><div class="card-body">
          <?php if ($w['description']): ?><p class="small" style="white-space:pre-line"><?= h((string)$w['description']) ?></p><?php endif; ?>
          <div class="info-grid">
            <?= info_pair('Assigned to', Ship::userName($w['assigned_to'] ? (int)$w['assigned_to'] : null) ?: 'Nobody yet') ?><?= info_pair('Planned', $w['planned_start'] ? fdate($w['planned_start']) . ($w['planned_end'] ? ' to ' . fdate($w['planned_end']) : '') : 'Not planned') ?>
            <?= info_pair('Approved by', $w['approved_by'] ? Ship::userName((int)$w['approved_by']) . ', ' . fdatetime($w['approved_at']) : '-') ?><?= info_pair('Done by', $w['executed_by'] ? Ship::userName((int)$w['executed_by']) : '-') ?>
            <?= info_pair('Work started', $w['actual_start'] ? fdatetime($w['actual_start']) : '-') ?><?= info_pair('Work ended', $w['actual_end'] ? fdatetime($w['actual_end']) : '-') ?>
            <?= $w['inspected_by'] ? info_pair('Inspection', ($w['inspection_result'] === 'pass' ? 'Passed' : 'Failed') . ', ' . Ship::userName((int)$w['inspected_by']) . ', ' . fdatetime($w['inspected_at'])) : '' ?>
            <?= $w['accepted_by'] ? info_pair('Accepted and closed by', Ship::userName((int)$w['accepted_by']) . ', ' . fdatetime($w['accepted_at'])) : '' ?>
            <?= $w['downtime_hours'] !== null ? info_pair('Downtime', qty($w['downtime_hours']) . ' h') : '' ?>
            <?= $w['findings'] ? info_pair('Findings', $w['findings']) : '' ?><?= $w['root_cause'] ? info_pair('Root cause', $w['root_cause']) : '' ?>
            <?= $w['repair_procedure'] ? info_pair('Repair done', $w['repair_procedure']) : '' ?><?= $w['reading_before'] || $w['reading_after'] ? info_pair('Readings before / after', trim($w['reading_before'] . ' / ' . $w['reading_after'], ' /')) : '' ?>
            <?= $w['deferral_reason'] ? info_pair('Deferral', $w['deferral_reason'] . ' (to ' . fdate($w['deferral_until']) . ')' . ($w['deferral_approved_by'] ? ', approved by ' . Ship::userName((int)$w['deferral_approved_by']) : ', waiting for approval')) : '' ?>
            <?= $w['inspection_notes'] ? info_pair('Inspection notes', $w['inspection_notes']) : '' ?>
          </div>
          <?php if ($checklist): ?><div class="mt-3"><div class="small fw-semibold mb-1">Checklist</div><ul class="check-list">
            <?php foreach ($checklist as $c): $ok = in_array($c, $done, true); ?><li><span class="ck <?= $ok ? 'ok' : 'soft' ?>"><i class="bi bi-<?= $ok ? 'check-lg' : 'dash' ?>"></i></span><div><?= h($c) ?></div></li><?php endforeach; ?></ul></div><?php endif; ?>
        </div></div>

        <div class="card reveal mb-3"><div class="card-header">Labor</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>Date</th><th>Worker</th><th class="num">Hours</th><th>Work done</th><?= $costs ? '<th class="num">Cost</th><th>Posted</th>' : '' ?></tr></thead><tbody>
          <?php foreach ($labor as $l): ?><tr><td class="small"><?= h(fdate($l['work_date'])) ?></td><td><?= h($l['worker_name']) ?><?= (int)$l['is_overtime'] ? ' <span class="small text-muted">overtime</span>' : '' ?></td><td class="num"><?= qty($l['hours']) ?></td><td class="small"><?= h((string)$l['notes']) ?></td>
            <?= $costs ? '<td class="num">' . money($l['amount']) . '</td><td class="small">' . txn_doc_link($l['txn_id'] ? (int)$l['txn_id'] : null) . '</td>' : '' ?></tr><?php endforeach; ?>
          <?php if (!$labor): ?><tr><td colspan="6"><?= empty_note('No hours logged yet.', 'person-gear') ?></td></tr><?php endif; ?></tbody></table></div>
          <?php if ($canEdit && $open): ?><form method="post" class="card-footer bg-transparent row g-2 mx-0 align-items-end"><?= csrf_field() ?><input type="hidden" name="act" value="labor">
            <?= select_field('user_id', 'Worker', wo_people(), Auth::id(), ['class' => 'col-md-3', 'blank' => 'Someone else (type the name)']) ?><?= input_field('worker_name', 'or name', '', ['class' => 'col-md-2']) ?>
            <?= input_field('work_date', 'Date', Ship::today(), ['type' => 'date', 'class' => 'col-md-2']) ?><?= input_field('hours', 'Hours', '', ['type' => 'number', 'step' => '0.25', 'class' => 'col-md-1', 'required' => true]) ?>
            <?= input_field('notes', 'Work done', '', ['class' => 'col-md-2']) ?><?= check_field('overtime', 'Overtime', false, 'col-md-1') ?>
            <div class="col-md-1"><button class="btn btn-outline-primary btn-sm w-100">Log</button></div></form><?php endif; ?>
        </div>

        <div class="card reveal mb-3"><div class="card-header">Spare parts used</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>Part</th><th class="num">Qty</th><th>Taken from</th><th>Issued</th><?= $costs ? '<th class="num">Cost</th><th>Posted</th>' : '' ?></tr></thead><tbody>
          <?php foreach ($parts as $pt): ?><tr><td><?= h($pt['code'] . ' ' . $pt['name']) ?></td><td class="num"><?= qty($pt['qty']) ?> <?= h((string)$pt['unit']) ?></td><td class="small"><?= h($pt['site_name']) ?></td><td class="small"><?= h(fdatetime($pt['issued_at'])) ?></td>
            <?= $costs ? '<td class="num">' . money($pt['amount']) . '</td><td class="small">' . txn_doc_link($pt['txn_id'] ? (int)$pt['txn_id'] : null) . '</td>' : '' ?></tr><?php endforeach; ?>
          <?php if (!$parts): ?><tr><td colspan="6"><?= empty_note('No part issued to this job.', 'box-seam') ?></td></tr><?php endif; ?></tbody></table></div>
          <?php if ($canEdit && $open):
              $stock = DB::all("SELECT st.item_id, st.site_id, st.qty_on_hand, i.code, i.name, i.unit, s.name AS site_name FROM item_site_stock st JOIN items i ON i.id = st.item_id JOIN sites s ON s.id = st.site_id
                                 WHERE st.qty_on_hand > 0 AND i.item_type = 'inventory' AND i.is_active = 1 AND i.code NOT LIKE 'FUEL%' AND i.code NOT LIKE 'CAN-%' AND (s.site_type <> 'vessel' OR s.id = :vs) ORDER BY i.code, s.name",
                  ['vs' => DB::value('SELECT site_id FROM shp_vessels WHERE id = :v', ['v' => $w['vessel_id']]) ?: 0]); ?>
            <form method="post" class="card-footer bg-transparent row g-2 mx-0 align-items-end"><?= csrf_field() ?><input type="hidden" name="act" value="part">
              <div class="col-md-6"><label class="form-label">Part and where it is</label><select class="form-select form-select-sm" name="pick" id="woPick" required><option value=""></option>
                <?php foreach ($stock as $s): ?><option value="<?= (int)$s['item_id'] . ':' . (int)$s['site_id'] ?>"><?= h($s['code'] . ' ' . $s['name'] . ', ' . $s['site_name'] . ' (' . qty($s['qty_on_hand']) . ' ' . $s['unit'] . ' on hand)') ?></option><?php endforeach; ?></select>
</div>
              <?= input_field('qty', 'Qty', '1', ['type' => 'number', 'step' => '0.01', 'class' => 'col-md-2', 'required' => true]) ?><?= input_field('issue_date', 'Date', Ship::today(), ['type' => 'date', 'class' => 'col-md-2']) ?>
              <div class="col-md-2"><button class="btn btn-outline-primary btn-sm w-100">Issue</button></div>
              <div class="col-12 form-text">Stock never goes below zero: only what is on hand at the vessel or the shore store can be issued.</div></form>
          <?php endif; ?>
        </div>

        <div class="card reveal mb-3"><div class="card-header">Contractors' services</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>Contractor</th><th>Service</th><th>Invoice no.</th><?= $costs ? '<th class="num">Amount</th><th class="num">VAT</th><th>Bill</th>' : '' ?></tr></thead><tbody>
          <?php foreach ($services as $sv): ?><tr><td><?= h($sv['vendor']) ?></td><td class="small"><?= h($sv['description']) ?></td><td class="small"><?= h($sv['ref_no']) ?></td>
            <?= $costs ? '<td class="num">' . money($sv['amount']) . '</td><td class="num">' . money($sv['tax_amount'], true) . '</td><td class="small">' . txn_doc_link($sv['txn_id'] ? (int)$sv['txn_id'] : null) . '</td>' : '' ?></tr><?php endforeach; ?>
          <?php if (!$services): ?><tr><td colspan="6"><?= empty_note('No outside contractor on this job.', 'building-gear') ?></td></tr><?php endif; ?></tbody></table></div>
          <?php if ($canEdit && $open && $costs): ?><form method="post" class="card-footer bg-transparent row g-2 mx-0 align-items-end"><?= csrf_field() ?><input type="hidden" name="act" value="service">
            <?= select_field('vendor_id', 'Contractor', DB::pairs('SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name'), '', ['class' => 'col-md-3', 'blank' => '', 'required' => true]) ?>
            <?= input_field('description', 'Service', '', ['class' => 'col-md-3', 'required' => true]) ?><?= input_field('ref_no', 'Their invoice no.', '', ['class' => 'col-md-2', 'required' => true]) ?>
            <?= input_field('amount', 'Amount before VAT', '', ['type' => 'number', 'class' => 'col-md-2', 'required' => true]) ?><?= check_field('vatable', '+ VAT', true, 'col-md-1') ?>
            <div class="col-md-1"><button class="btn btn-outline-primary btn-sm w-100">Add</button></div></form><?php endif; ?>
        </div>
        <?php if ($costs): ?><div class="card reveal"><div class="card-header">Accounting documents</div><?= ship_documents_table(ShipGL::documentsWhere('work_order_id', (int)$w['id'])) ?></div><?php endif; ?>
      </div>

      <div class="col-xl-4">
        <div class="card reveal mb-3"><div class="card-header">Next step</div><div class="card-body">
        <?php if (!$canEdit): ?><p class="small text-muted mb-0">Your role can see this work order but not move it.</p>
        <?php elseif (!$next): ?><p class="small text-muted mb-0">The work order is <?= strtolower(strip_tags(Ship::label($w['status']))) ?>.</p>
        <?php else: foreach ($next as $to):
            if ($to === 'approved' && !$canApprove) {
                continue;
            }
            $failBack = $to === 'in_progress' && $w['status'] === 'inspection';
            $btn = $failBack ? 'Inspection failed: back to the technician' : (['triaged' => 'Mark triaged', 'approved' => 'Approve the work', 'planned' => 'Mark planned', 'waiting_parts' => 'Waiting for parts', 'in_progress' => 'Start the work',
                'inspection' => 'Work done: send for inspection', 'completed' => 'Inspection passed', 'closed' => 'Accept and close', 'deferred' => 'Defer', 'cancelled' => 'Cancel the work order'][$to] ?? $to);
            $primary = in_array($to, ['approved', 'in_progress', 'inspection', 'completed', 'closed'], true) && !$failBack; ?>
          <details class="mb-2" <?= $primary ? 'open' : '' ?>><summary class="small fw-semibold"><?= h($btn) ?></summary>
            <form method="post" class="row g-2 mt-1 p-2 border rounded-3"><?= csrf_field() ?><input type="hidden" name="act" value="status"><input type="hidden" name="to" value="<?= h($to) ?>">
            <?php if ($to === 'inspection'): ?>
              <?php if ($checklist): ?><input type="hidden" name="ck_present" value="1"><div class="col-12"><?php foreach ($checklist as $i => $c): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="ck[]" value="<?= h($c) ?>" id="ck<?= $i ?>" <?= checked(in_array($c, $done, true)) ?>><label class="form-check-label small" for="ck<?= $i ?>"><?= h($c) ?></label></div><?php endforeach; ?></div><?php endif; ?>
              <?= input_field('findings', 'Findings', $w['findings'], ['class' => 'col-12']) ?><?= input_field('root_cause', 'Root cause', $w['root_cause'], ['class' => 'col-12']) ?>
              <?= input_field('repair_procedure', 'Repair done', $w['repair_procedure'], ['class' => 'col-12']) ?>
              <?= input_field('reading_before', 'Reading before', $w['reading_before'], ['class' => 'col-6']) ?><?= input_field('reading_after', 'Reading after', $w['reading_after'], ['class' => 'col-6']) ?>
            <?php elseif ($to === 'completed'): ?>
              <input type="hidden" name="inspection_result" value="pass">
              <div class="col-12 small text-muted">Inspected by you: nobody who logged hours on the job may pass it<?= (int)$w['safety_critical'] ? ', and safety-critical work needs the technical-release permission' : '' ?>.</div>
              <?= input_field('note', 'Inspection notes', '', ['class' => 'col-12']) ?>
            <?php elseif ($to === 'closed'): ?>
              <?= input_field('downtime_hours', 'Downtime (h, blank = work time)', '', ['type' => 'number', 'class' => 'col-6']) ?><?= input_field('note', 'Note', '', ['class' => 'col-6']) ?>
            <?php elseif ($to === 'deferred'): ?>
              <?= input_field('until', 'Deferred to', '', ['type' => 'date', 'class' => 'col-5', 'required' => true]) ?><?= input_field('note', 'Reason', '', ['class' => 'col-7', 'required' => true]) ?>
              <?php if ((int)$w['safety_critical']): ?><div class="col-12 small text-danger">Safety-critical: the deferral goes to another person for approval and the vessel hold stays.</div><?php endif; ?>
            <?php else: ?>
              <?= input_field('note', $failBack ? 'What failed' : 'Note', '', ['class' => 'col-12', 'required' => $failBack || $to === 'cancelled']) ?>
            <?php endif; ?>
              <div class="col-12"><button class="btn btn-sm <?= $primary ? 'btn-primary' : ($failBack || $to === 'cancelled' ? 'btn-outline-danger' : 'btn-outline-primary') ?>"><?= h($btn) ?></button></div>
            </form></details>
        <?php endforeach; endif; ?>
        </div></div>
        <?php if ($canEdit && !in_array($w['status'], ['completed', 'closed', 'cancelled'], true)): ?>
        <form method="post" class="card reveal mb-3"><?= csrf_field() ?><input type="hidden" name="act" value="plan"><div class="card-header">Plan</div><div class="card-body"><div class="row g-2">
          <?= select_field('assigned_to', 'Assigned to', wo_people(), $w['assigned_to'], ['class' => 'col-12', 'blank' => '']) ?>
          <?= input_field('planned_start', 'Start', $w['planned_start'], ['type' => 'date', 'class' => 'col-6']) ?><?= input_field('planned_end', 'End', $w['planned_end'], ['type' => 'date', 'class' => 'col-6']) ?>
          <?= select_field('priority', 'Priority', ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'], $w['priority'], ['class' => 'col-6']) ?>
          <?= select_field('expense_mode', 'Accounting', ['opex' => 'Repairs expense', 'capex' => 'Capital work'], $w['expense_mode'], ['class' => 'col-6']) ?>
          <?php if ($drydocks): ?><?= select_field('drydock_id', 'Dry-docking', $drydocks, $w['drydock_id'], ['class' => 'col-12', 'blank' => '']) ?><?php endif; ?>
        </div><button class="btn btn-outline-primary btn-sm mt-2">Save plan</button></div></form>
        <?php endif; ?>
        <?php $prs = DB::all('SELECT id, pr_no, status, est_total FROM shp_requisitions WHERE wo_id = :w ORDER BY id', ['w' => $w['id']]); if ($prs): ?>
        <div class="card reveal mb-3"><div class="card-header">Requisitions</div><ul class="list-group list-group-flush">
          <?php foreach ($prs as $pr): ?><li class="list-group-item d-flex justify-content-between"><span><?= safe_link('fleet.requisitions', ['id' => $pr['id']], $pr['pr_no']) ?></span><?= Ship::label($pr['status']) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <div class="card reveal"><div class="card-header">History</div><div class="card-body"><ul class="timeline">
          <?php foreach ($log as $l): ?><li class="<?= $l['to_status'] === 'cancelled' || str_contains((string)$l['details'], 'ailed') ? 't-bad' : ($l['to_status'] === 'deferred' ? 't-warn' : '') ?>">
            <div class="when"><?= h(fdatetime($l['created_at'])) ?>, <?= h($l['full_name'] ?? 'system') ?></div><div><?= $l['to_status'] && $l['to_status'] !== $l['from_status'] ? Ship::label($l['to_status']) . ' ' : '' ?><?= h((string)$l['details']) ?></div></li><?php endforeach; ?>
        </ul></div></div>
      </div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ board / list
$view = g('view') === 'list' ? 'list' : 'board';
$vessel = gint('vessel');
$type = (string)g('type');
$mine = g('mine') === '1';
$where = '1 = 1';
$par = [];
if ($vessel) {
    $where .= ' AND w.vessel_id = :v';
    $par['v'] = $vessel;
}
if (isset(WO_TYPES[$type])) {
    $where .= ' AND w.wo_type = :t';
    $par['t'] = $type;
}
if ($mine) {
    $where .= ' AND (w.assigned_to = :me OR w.executed_by = :me2)';
    $par['me'] = Auth::id();
    $par['me2'] = Auth::id();
}
$showClosed = $view === 'list' && g('closed') === '1';
$rows = DB::all("SELECT w.*, s.code AS vessel_code, e.code AS eq_code, e.name AS eq_name, u.full_name AS assignee
                   FROM shp_work_orders w JOIN shp_vessels s ON s.id = w.vessel_id LEFT JOIN shp_equipment e ON e.id = w.equipment_id LEFT JOIN users u ON u.id = w.assigned_to
                  WHERE $where" . ($showClosed ? '' : " AND w.status NOT IN ('closed', 'cancelled')") . "
                  ORDER BY FIELD(w.priority, 'urgent', 'high', 'normal', 'low'), w.safety_critical DESC, COALESCE(w.planned_end, '9999-12-31'), w.id DESC LIMIT 500", $par);
$cols = ['new' => ['To triage and approve', ['reported', 'triaged']], 'ready' => ['Approved and planned', ['approved', 'planned']], 'parts' => ['Waiting parts', ['waiting_parts']],
    'doing' => ['In progress', ['in_progress']], 'check' => ['For inspection', ['inspection']], 'done' => ['Completed, to close', ['completed']], 'deferred' => ['Deferred', ['deferred']]];
$count = fn(array $sts) => count(array_filter($rows, fn($r) => in_array($r['status'], $sts, true)));
$late = count(array_filter($rows, fn($r) => $r['planned_end'] && $r['planned_end'] < Ship::today() && !in_array($r['status'], ['completed', 'closed', 'cancelled', 'deferred'], true)));
$critical = count(array_filter($rows, fn($r) => (int)$r['safety_critical'] === 1 && !in_array($r['status'], ['completed', 'closed', 'cancelled'], true)));
$actions = ($canCreate ? btn_link(url($page, ['a' => 'new', 'vessel' => $vessel]), 'New work order', 'btn-primary', 'plus-lg') . ' ' : '')
    . (Access::page('rpt.shp_wo_status') ? btn_link(url('rpt.shp_wo_status'), 'Status report', 'btn-outline-secondary', 'bar-chart') : '');
layout_start('Work Orders', ['actions' => $actions]);
echo '<div class="kpi-grid mb-3">'
    . kpi_tile('Open work orders', (string)count(array_filter($rows, fn($r) => !in_array($r['status'], ['closed', 'cancelled'], true))), 'tools', 'Not closed or cancelled', '')
    . kpi_tile('Safety-critical open', (string)$critical, 'shield-exclamation', 'Holding or limiting a vessel', $critical ? 'bad' : 'good')
    . kpi_tile('Waiting parts', (string)$count(['waiting_parts']), 'box-seam', 'Ordered through requisitions', $count(['waiting_parts']) ? 'warn' : '')
    . kpi_tile('For inspection', (string)$count(['inspection']), 'clipboard2-check', 'Inspected by someone who did not do the work', $count(['inspection']) ? 'warn' : '')
    . kpi_tile('Past planned end', (string)$late, 'calendar-x', 'Still not completed', $late ? 'bad' : 'good')
    . '</div>';
$q = fn(array $extra) => url($page, array_filter(array_merge(['view' => $view, 'vessel' => $vessel, 'type' => $type, 'mine' => $mine ? '1' : null], $extra)));
?>
<form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>"><input type="hidden" name="view" value="<?= h($view) ?>">
  <?= select_field('vessel', 'Vessel', Ship::vesselOptions(false), $vessel, ['class' => '', 'blank' => 'Every vessel']) ?>
  <?= select_field('type', 'Type', WO_TYPES, $type, ['class' => '', 'blank' => 'Any type']) ?>
  <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="mine" value="1" id="woMine" <?= checked($mine) ?>><label class="form-check-label small" for="woMine">Assigned to me</label></div>
  <?php if ($view === 'list'): ?><div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="closed" value="1" id="woClosed" <?= checked($showClosed) ?>><label class="form-check-label small" for="woClosed">With closed ones</label></div><?php endif; ?>
  <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Show</button></div>
</form>
<?= tabs_line(['board' => ['Board', $q(['view' => 'board']), null], 'list' => ['List', $q(['view' => 'list']), null]], $view) ?>
<?php if ($view === 'board'): ?>
<div class="wo-board">
<?php foreach ($cols as $key => [$label, $sts]): $list = array_filter($rows, fn($r) => in_array($r['status'], $sts, true)); if ($key === 'deferred' && !$list) { continue; } ?>
  <div class="wo-col"><h6><?= h($label) ?><span><?= count($list) ?></span></h6>
  <?php foreach ($list as $r): $isLate = $r['planned_end'] && $r['planned_end'] < Ship::today(); ?>
    <a class="wo-card <?= (int)$r['safety_critical'] ? 'crit' : '' ?>" href="<?= h(url($page, ['id' => $r['id']])) ?>">
      <div class="no"><span><?= h($r['wo_no']) ?>, <?= h($r['vessel_code']) ?></span><?= Ship::severityLabel($r['priority']) ?></div>
      <div class="tt"><?= h($r['title']) ?></div>
      <div class="ft"><span><?= h($r['eq_code'] ?: ucfirst($r['wo_type'])) ?></span><span><?= h($r['assignee'] ?: 'Unassigned') ?></span><?= $r['planned_end'] ? '<span class="' . ($isLate ? 'text-danger' : '') . '">Due ' . h(date('M j', strtotime($r['planned_end']))) . '</span>' : '' ?></div>
    </a>
  <?php endforeach; ?>
  <?php if (!$list): ?><div class="small text-muted text-center py-3">Nothing here</div><?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>Work order</th><th>Vessel</th><th>Work</th><th>Type</th><th>Priority</th><th>Assigned</th><th>Planned end</th><th>Status</th><?= $costs ? '<th class="num">Cost</th>' : '' ?></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td class="fw-semibold text-nowrap"><a href="<?= h(url($page, ['id' => $r['id']])) ?>"><?= h($r['wo_no']) ?></a></td><td><?= h($r['vessel_code']) ?></td>
    <td><?= h($r['title']) ?><?= $r['eq_code'] ? '<div class="small text-muted">' . h($r['eq_code'] . ' ' . $r['eq_name']) . '</div>' : '' ?></td><td class="small"><?= h(ucfirst($r['wo_type'])) ?></td><td><?= Ship::severityLabel($r['priority']) ?></td>
    <td class="small"><?= h((string)$r['assignee']) ?></td><td class="small"><?= h(fdate($r['planned_end'])) ?></td><td><?= Ship::label($r['status']) ?></td><?= $costs ? '<td class="num">' . money(wo_cost($r), true) . '</td>' : '' ?></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9"><?= empty_note('No work order here.', 'tools') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php endif;
layout_end();
