<?php
declare(strict_types=1);

$page = 'fleet.drydock';
$id = gint('id');
$a = (string)g('a');
$dd = $id ? DB::row('SELECT d.*, s.name AS vessel_name, s.code AS vessel_code, s.status AS vessel_status, v.name AS yard_name
                       FROM shp_drydock d JOIN shp_vessels s ON s.id = d.vessel_id LEFT JOIN vendors v ON v.id = d.yard_vendor_id WHERE d.id = :id', ['id' => $id]) : null;
if ($id && !$dd) {
    flash('warning', 'That dry-docking project no longer exists.');
    redirect(url($page));
}
const DD_STATUS = ['planned' => 'Planned', 'docked' => 'In dry-dock', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
const DD_ASSESS = ['capex' => 'Capital (to the vessel)', 'opex' => 'Repairs (expense)', 'mixed' => 'Mixed: decided per item'];

if (!function_exists('dd_label')) {
    function dd_label(string $s): string
    {
        $tone = ['planned' => 'warn', 'docked' => 'info', 'completed' => 'good', 'cancelled' => 'muted', 'pending' => 'warn', 'done' => 'info', 'billed' => 'good'][$s] ?? 'muted';
        $names = DD_STATUS + ['pending' => 'To do', 'done' => 'Done, to bill', 'billed' => 'Billed'];
        return '<span class="st st-' . $tone . '">' . h($names[$s] ?? $s) . '</span>';
    }

    /** Cost so far: milestone bills of the yard and the work orders of the project, split capital / expense */
    function dd_actuals(array $dd): array
    {
        $capex = 0.0;
        $opex = 0.0;
        $wip = Accts::get('shp_capex_wip');
        foreach (DB::all("SELECT m.bill_txn_id FROM shp_drydock_milestones m WHERE m.drydock_id = :d AND m.bill_txn_id IS NOT NULL", ['d' => $dd['id']]) as $m) {
            foreach (DB::all('SELECT j.account_id, SUM(j.debit - j.credit) amt FROM journal_lines j JOIN transactions t ON t.id = j.txn_id AND t.is_voided = 0
                               JOIN accounts a ON a.id = j.account_id WHERE j.txn_id = :t AND (a.category = \'Expense\' OR a.id = :wip) GROUP BY j.account_id', ['t' => $m['bill_txn_id'], 'wip' => $wip]) as $l) {
                (int)$l['account_id'] === $wip ? $capex += (float)$l['amt'] : $opex += (float)$l['amt'];
            }
        }
        foreach (DB::all("SELECT expense_mode, SUM(cost_labor + cost_parts + cost_services) c FROM shp_work_orders WHERE drydock_id = :d AND status <> 'cancelled' GROUP BY expense_mode", ['d' => $dd['id']]) as $r) {
            $r['expense_mode'] === 'capex' ? $capex += (float)$r['c'] : $opex += (float)$r['c'];
        }
        return ['capex' => r2($capex), 'opex' => r2($opex), 'total' => r2($capex + $opex)];
    }
}

handle_post(function () use ($page, $dd) {
    $act = (string)p('act');
    if ($act === 'save') {
        ship_require($page, $dd ? 'edit' : 'create');
        $d = ['vessel_id' => (int)pint('vessel_id'), 'title' => mb_substr(trim((string)p('title')), 0, 160), 'yard_vendor_id' => pint('yard_vendor_id'), 'survey_type' => p('survey_type') ?: null,
            'planned_start' => pdate('planned_start'), 'planned_end' => pdate('planned_end'), 'budget' => pnum('budget'), 'quoted_amount' => pnum('quoted_amount'),
            'capex_assessment' => isset(DD_ASSESS[p('capex_assessment')]) ? (string)p('capex_assessment') : 'mixed', 'notes' => mb_substr(trim((string)p('notes')), 0, 500) ?: null];
        if (!DB::value('SELECT 1 FROM shp_vessels WHERE id = :id', ['id' => $d['vessel_id']])) {
            fail('Choose the vessel.');
        }
        if ($d['title'] === '' || !$d['planned_start'] || !$d['planned_end'] || $d['planned_end'] < $d['planned_start']) {
            fail('Enter the title and the planned docking and undocking dates.');
        }
        if ($dd) {
            DB::update('shp_drydock', $d, 'id = :id', ['id' => $dd['id']]);
            Ship::audit('edit', 'fleet', 'shp_drydock', (int)$dd['id'], $dd['dd_no'], ['changes' => Ship::changes($dd, $d), 'page_key' => $page]);
            $nid = (int)$dd['id'];
        } else {
            $d += ['dd_no' => Ship::no('shp_drydock', 'DD-', 4), 'status' => 'planned', 'created_by' => Auth::id()];
            $nid = DB::insert('shp_drydock', $d);
            Ship::audit('create', 'fleet', 'shp_drydock', $nid, $d['dd_no'], ['amount' => $d['budget'], 'page_key' => $page]);
        }
        flash('success', 'Dry-docking project saved.');
        redirect(url($page, ['id' => $nid]));
    }
    if (!$dd) {
        fail('Project not found.');
    }
    $back = url($page, ['id' => $dd['id']]);
    switch ($act) {
        case 'status':
            ship_require($page, 'edit');
            $to = (string)p('to');
            $flow = ['planned' => ['docked', 'cancelled'], 'docked' => ['completed']];
            if (!in_array($to, $flow[$dd['status']] ?? [], true)) {
                fail('The project cannot go from ' . strtolower(DD_STATUS[$dd['status']]) . ' to ' . strtolower(DD_STATUS[$to] ?? $to) . '.');
            }
            DB::tx(function () use ($dd, $to) {
                $upd = ['status' => $to];
                if ($to === 'docked') {
                    $busy = DB::value("SELECT voyage_no FROM shp_voyages WHERE vessel_id = :v AND status IN ('published', 'on_sale', 'boarding', 'ready', 'departed') AND etd <= :e AND eta >= :s LIMIT 1",
                        ['v' => $dd['vessel_id'], 's' => (pdate('on', Ship::today())) . ' 00:00:00', 'e' => ($dd['planned_end'] ?: Ship::today()) . ' 23:59:59']);
                    if ($busy) {
                        fail('Voyage ' . $busy . ' of this vessel falls inside the dry-docking dates; cancel it or move it to another vessel first.');
                    }
                    $upd['actual_start'] = pdate('on', Ship::today());
                    DB::update('shp_vessels', ['status' => 'drydock'], 'id = :id', ['id' => $dd['vessel_id']]);
                }
                if ($to === 'completed') {
                    $upd['actual_end'] = pdate('on', Ship::today());
                    $upd['progress_pct'] = 100;
                    if (DB::value("SELECT 1 FROM shp_work_orders WHERE drydock_id = :d AND status NOT IN ('completed', 'closed', 'cancelled', 'deferred')", ['d' => $dd['id']])) {
                        fail('Some work orders of the dry-docking are still open; complete or defer them first.');
                    }
                    DB::update('shp_vessels', ['status' => 'active'], 'id = :id AND status = :s', ['id' => $dd['vessel_id'], 's' => 'drydock']);
                }
                DB::update('shp_drydock', $upd, 'id = :id', ['id' => $dd['id']]);
                Ship::audit('status', 'fleet', 'shp_drydock', (int)$dd['id'], $dd['dd_no'] . ': ' . $dd['status'] . ' to ' . $to, ['reason' => (string)p('note'), 'page_key' => 'fleet.drydock']);
            });
            flash('success', $to === 'docked' ? 'Vessel is in dry-dock; it shows as such in the Vessel Registry.' : ($to === 'completed' ? 'Dry-docking completed; the vessel is back in service.' : 'Project cancelled.'));
            break;
        case 'progress':
            ship_require($page, 'edit');
            $pct = max(0, min(100, pnum('progress_pct')));
            DB::update('shp_drydock', ['progress_pct' => $pct], 'id = :id', ['id' => $dd['id']]);
            flash('success', 'Progress set to ' . qty($pct) . '%.');
            break;
        case 'milestone':
            ship_require($page, 'edit');
            if (trim((string)p('name')) === '') {
                fail('Name the milestone.');
            }
            DB::insert('shp_drydock_milestones', ['drydock_id' => $dd['id'], 'name' => mb_substr(trim((string)p('name')), 0, 120), 'due_date' => pdate('due_date'), 'amount' => pnum('amount')]);
            flash('success', 'Milestone added.');
            break;
        case 'done':
            ship_require($page, 'edit');
            DB::update('shp_drydock_milestones', ['status' => 'done', 'done_at' => Ship::now()], 'id = :id AND drydock_id = :d AND status = :s', ['id' => pint('milestone_id'), 'd' => $dd['id'], 's' => 'pending']);
            flash('success', 'Milestone marked done; it can now be billed by the yard.');
            break;
        case 'bill':
            ship_require($page, 'approve');
            $m = DB::row('SELECT * FROM shp_drydock_milestones WHERE id = :id AND drydock_id = :d', ['id' => pint('milestone_id'), 'd' => $dd['id']]);
            if (!$m || $m['status'] !== 'done') {
                fail('Only a milestone that is done is billed.');
            }
            if (!$dd['yard_vendor_id']) {
                fail('Set the shipyard of the project first.');
            }
            $amount = pnum('amount') ?: (float)$m['amount'];
            $mode = $dd['capex_assessment'] === 'mixed' ? (p('mode') === 'capex' ? 'capex' : 'opex') : $dd['capex_assessment'];
            if ($amount <= 0 || trim((string)p('ref_no')) === '') {
                fail('Enter the amount (before VAT) and the yard\'s invoice number.');
            }
            DB::tx(function () use ($dd, $m, $amount, $mode) {
                $vat = p('vatable') === '1' ? r2($amount * ShipGL::vatRate() / 100) : 0.0;
                $memo = $dd['dd_no'] . ' ' . $m['name'] . ' (' . ($mode === 'capex' ? 'capital' : 'repairs') . ')';
                $acct = $mode === 'capex' ? Accts::get('shp_capex_wip') : Accts::get('shp_repairs_expense');
                $txn = ShipGL::once('drydock:' . $dd['id'] . ':ms:' . $m['id'], ['source_type' => 'drydock', 'source_id' => (int)$dd['id'], 'vessel_id' => (int)$dd['vessel_id'], 'memo' => $memo],
                    fn() => ShipGL::bill((int)$dd['yard_vendor_id'], pdate('bill_date', Ship::today()), (string)p('ref_no'), [['account_id' => $acct, 'amount' => r2($amount),
                        'tax_code_id' => ShipGL::taxCode($vat > 0 ? 'VAT12' : 'NT'), 'tax_amount' => $vat, 'description' => $memo]], $memo, ShipGL::vesselClass((int)$dd['vessel_id'])));
                DB::update('shp_drydock_milestones', ['status' => 'billed', 'bill_txn_id' => $txn], 'id = :id', ['id' => $m['id']]);
                Ship::audit('bill', 'fleet', 'shp_drydock', (int)$dd['id'], $memo, ['amount' => $amount, 'page_key' => 'fleet.drydock']);
            });
            flash('success', 'The yard\'s bill was entered in Accounts Payable.');
            break;
        default:
            fail('Unknown action.');
    }
    redirect($back);
});

$canEdit = Access::can($page, 'edit');
$canCreate = Access::can($page, 'create');
$costs = Access::viewCosts();

// ------------------------------------------------------------------ form
if ($a === 'new' || $a === 'edit' || (is_post() && p('act') === 'save')) {
    $e = $a === 'edit' || ($dd && is_post()) ? $dd : null;
    ship_require($page, $e ? 'edit' : 'create');
    layout_start($e ? 'Edit ' . $e['dd_no'] : 'New dry-docking project', ['actions' => btn_link($e ? url($page, ['id' => $e['id']]) : url($page), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" class="card reveal" style="max-width:980px"><?= csrf_field() ?><input type="hidden" name="act" value="save"><div class="card-body"><div class="row g-3">
      <?= select_field('vessel_id', 'Vessel', Ship::vesselOptions(), fv('vessel_id', $e, gint('vessel')), ['class' => 'col-md-4', 'blank' => '', 'required' => true]) ?>
      <?= input_field('title', 'Project', fv('title', $e), ['class' => 'col-md-8', 'required' => true]) ?>
      <?= select_field('yard_vendor_id', 'Shipyard', DB::pairs('SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name'), fv('yard_vendor_id', $e), ['class' => 'col-md-5', 'blank' => '']) ?>
      <?= input_field('survey_type', 'Survey (special, intermediate, annual)', fv('survey_type', $e), ['class' => 'col-md-7']) ?>
      <?= input_field('planned_start', 'Docking (planned)', fv('planned_start', $e), ['type' => 'date', 'class' => 'col-md-3', 'required' => true]) ?>
      <?= input_field('planned_end', 'Undocking (planned)', fv('planned_end', $e), ['type' => 'date', 'class' => 'col-md-3', 'required' => true]) ?>
      <?= input_field('budget', 'Budget', fv('budget', $e), ['type' => 'number', 'class' => 'col-md-3']) ?><?= input_field('quoted_amount', 'Yard quotation', fv('quoted_amount', $e), ['type' => 'number', 'class' => 'col-md-3']) ?>
      <?= select_field('capex_assessment', 'Accounting treatment', DD_ASSESS, fv('capex_assessment', $e, 'mixed'), ['class' => 'col-md-6']) ?>
      <div class="col-12"><label class="form-label">Scope and notes</label><textarea class="form-control form-control-sm" name="notes" rows="3" maxlength="500"><?= h((string)fv('notes', $e)) ?></textarea></div>
    </div><div class="form-text mt-2">Under the accounting policy, major overhauls and renewals that extend the vessel's life go to the vessel (capital, through Capital Work in Progress) and routine repairs to expense. With "mixed" each yard bill and work order says which.</div></div>
    <div class="card-footer bg-transparent"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Save project</button></div></form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ one project
if ($dd) {
    $ms = DB::all('SELECT m.*, t.txn_no FROM shp_drydock_milestones m LEFT JOIN transactions t ON t.id = m.bill_txn_id WHERE m.drydock_id = :d ORDER BY m.due_date, m.id', ['d' => $dd['id']]);
    $wos = DB::all('SELECT * FROM shp_work_orders WHERE drydock_id = :d ORDER BY id', ['d' => $dd['id']]);
    $act = dd_actuals($dd);
    $days = $dd['planned_start'] && $dd['planned_end'] ? (int)round((strtotime($dd['planned_end']) - strtotime($dd['planned_start'])) / 86400) + 1 : 0;
    $actions = ($canEdit && !in_array($dd['status'], ['completed', 'cancelled'], true) ? btn_link(url($page, ['a' => 'edit', 'id' => $dd['id']]), 'Edit', 'btn-outline-primary', 'pencil') . ' ' : '')
        . (Access::can('fleet.workorders', 'create') && $dd['status'] !== 'completed' ? btn_link(url('fleet.workorders', ['a' => 'new', 'vessel' => $dd['vessel_id'], 'drydock' => $dd['id']]), 'Add a work order', 'btn-outline-secondary', 'tools') : '');
    layout_start($dd['dd_no'] . ' ' . $dd['title'], ['actions' => $actions]);
    $pct = (float)$dd['progress_pct'];
    echo '<div class="kpi-grid mb-3">'
        . kpi_tile('Progress', qty($pct) . '%', 'bar-chart-steps', DD_STATUS[$dd['status']] . ($dd['actual_start'] ? ' since ' . fdate($dd['actual_start']) : ', ' . $days . ' days planned'), $dd['status'] === 'completed' ? 'good' : '')
        . ($costs ? kpi_tile('Budget', '₱' . money($dd['budget']), 'wallet2', 'Yard quotation ₱' . money($dd['quoted_amount']), '')
            . kpi_tile('Spent so far', '₱' . money($act['total']), 'receipt', $dd['budget'] > 0 ? round(100 * $act['total'] / (float)$dd['budget'], 1) . '% of the budget' : '', $act['total'] > (float)$dd['budget'] && (float)$dd['budget'] > 0 ? 'bad' : '', null, $act['total'])
            . kpi_tile('To the vessel (capital)', '₱' . money($act['capex']), 'diagram-2', 'Repairs expensed ₱' . money($act['opex']), '') : '')
        . '</div>';
    ?>
    <div class="row g-3">
      <div class="col-xl-8">
        <div class="card reveal mb-3"><div class="card-header d-flex justify-content-between"><span>Project</span><?= dd_label($dd['status']) ?></div><div class="card-body">
          <?= meter($pct, $dd['status'] === 'completed' ? '' : '') ?>
          <div class="info-grid mt-3">
            <?= info_pair('Vessel', $dd['vessel_name']) ?><?= info_pair('Shipyard', $dd['yard_name'] ?: '-') ?><?= info_pair('Survey', $dd['survey_type'] ?: '-') ?>
            <?= info_pair('Planned', fdate($dd['planned_start']) . ' to ' . fdate($dd['planned_end'])) ?><?= info_pair('Actual', $dd['actual_start'] ? fdate($dd['actual_start']) . ' to ' . ($dd['actual_end'] ? fdate($dd['actual_end']) : 'now') : 'Not docked yet') ?>
            <?= info_pair('Accounting', DD_ASSESS[$dd['capex_assessment']] ?? $dd['capex_assessment']) ?>
          </div>
          <?php if ($dd['notes']): ?><p class="small mt-3 mb-0" style="white-space:pre-line"><?= h((string)$dd['notes']) ?></p><?php endif; ?>
        </div></div>
        <div class="card reveal mb-3"><div class="card-header">Yard milestones</div><div class="card-body">
          <ul class="mile-list">
          <?php foreach ($ms as $i => $m): $late = $m['status'] === 'pending' && $m['due_date'] && $m['due_date'] < Ship::today(); ?>
            <li class="<?= $m['status'] !== 'pending' ? 'done' : ($late ? 'late' : '') ?>"><span class="dot"><?= $m['status'] !== 'pending' ? '<i class="bi bi-check-lg"></i>' : $i + 1 ?></span>
              <div><div class="mn"><?= h($m['name']) ?></div><div class="md"><?= $m['due_date'] ? 'Due ' . h(fdate($m['due_date'])) : '' ?><?= $costs ? ', ₱' . money($m['amount']) : '' ?><?= $m['bill_txn_id'] ? ', ' . txn_doc_link((int)$m['bill_txn_id'], 'Bill ' . $m['txn_no']) : '' ?></div>
              <?php if ($m['status'] === 'done' && Access::can($page, 'approve') && $costs): ?>
                <form method="post" class="row g-2 mt-1 align-items-end"><?= csrf_field() ?><input type="hidden" name="act" value="bill"><input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>">
                  <?= input_field('ref_no', 'Yard invoice no.', '', ['class' => 'col-md-3', 'required' => true]) ?><?= input_field('amount', 'Amount before VAT', (string)$m['amount'], ['type' => 'number', 'class' => 'col-md-3']) ?>
                  <?php if ($dd['capex_assessment'] === 'mixed'): ?><?= select_field('mode', 'Treatment', ['opex' => 'Repairs', 'capex' => 'Capital'], 'opex', ['class' => 'col-md-2']) ?><?php endif; ?>
                  <?= check_field('vatable', '+ VAT', true, 'col-md-2') ?><div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Bill</button></div></form>
              <?php endif; ?></div>
              <div class="text-end"><?= dd_label($m['status']) ?>
                <?php if ($m['status'] === 'pending' && $canEdit && $dd['status'] === 'docked'): ?><form method="post" class="mt-1"><?= csrf_field() ?><input type="hidden" name="act" value="done"><input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-outline-primary">Mark done</button></form><?php endif; ?></div></li>
          <?php endforeach; ?>
          <?php if (!$ms): ?><li class="d-block"><?= empty_note('No milestone yet.', 'flag') ?></li><?php endif; ?>
          </ul>
          <?php if ($canEdit && !in_array($dd['status'], ['completed', 'cancelled'], true)): ?>
            <form method="post" class="row g-2 mt-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="act" value="milestone">
              <?= input_field('name', 'Milestone', '', ['class' => 'col-md-5', 'required' => true]) ?><?= input_field('due_date', 'Due', '', ['type' => 'date', 'class' => 'col-md-3']) ?>
              <?= input_field('amount', 'Amount', '', ['type' => 'number', 'class' => 'col-md-2']) ?><div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Add</button></div></form>
          <?php endif; ?>
        </div></div>
        <div class="card reveal"><div class="card-header">Work orders of the dry-docking</div><div class="table-responsive"><table class="table table-sm table-hover mb-0">
          <thead><tr><th>Work order</th><th>Work</th><th>Treatment</th><th>Status</th><?= $costs ? '<th class="num">Cost</th>' : '' ?></tr></thead><tbody>
          <?php foreach ($wos as $w): ?><tr><td><?= safe_link('fleet.workorders', ['id' => $w['id']], $w['wo_no']) ?></td><td><?= h($w['title']) ?></td><td class="small"><?= $w['expense_mode'] === 'capex' ? 'Capital' : 'Repairs' ?></td><td><?= Ship::label($w['status']) ?></td>
            <?= $costs ? '<td class="num">' . money((float)$w['cost_labor'] + (float)$w['cost_parts'] + (float)$w['cost_services'], true) . '</td>' : '' ?></tr><?php endforeach; ?>
          <?php if (!$wos): ?><tr><td colspan="5"><?= empty_note('No work order linked yet. Add the items of the dry-docking specification as work orders.', 'tools') ?></td></tr><?php endif; ?>
          </tbody></table></div></div>
      </div>
      <div class="col-xl-4">
        <?php if ($canEdit && in_array($dd['status'], ['planned', 'docked'], true)): ?>
        <div class="card reveal mb-3"><div class="card-header">Status</div><div class="card-body">
          <?php if ($dd['status'] === 'docked'): ?>
            <form method="post" class="row g-2 mb-3 align-items-end"><?= csrf_field() ?><input type="hidden" name="act" value="progress">
              <?= input_field('progress_pct', 'Progress %', (string)$dd['progress_pct'], ['type' => 'number', 'step' => '1', 'class' => 'col-7']) ?><div class="col-5"><button class="btn btn-sm btn-outline-primary w-100">Update</button></div></form>
          <?php endif; ?>
          <?php foreach (['planned' => ['docked' => 'Vessel docked', 'cancelled' => 'Cancel the project'], 'docked' => ['completed' => 'Undocked and completed']][$dd['status']] as $to => $label): ?>
            <form method="post" class="row g-2 mb-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="act" value="status"><input type="hidden" name="to" value="<?= h($to) ?>">
              <?php if ($to !== 'cancelled'): ?><?= input_field('on', 'On', Ship::today(), ['type' => 'date', 'class' => 'col-7']) ?><?php else: ?><?= input_field('note', 'Why', '', ['class' => 'col-7', 'required' => true]) ?><?php endif; ?>
              <div class="col-5"><button class="btn btn-sm <?= $to === 'cancelled' ? 'btn-outline-danger' : 'btn-primary' ?> w-100" <?= $to === 'cancelled' ? 'data-confirm="Cancel the dry-docking project?"' : '' ?>><?= h($label) ?></button></div></form>
          <?php endforeach; ?>
          <p class="small text-muted mb-0">Docking marks the vessel as in dry-dock and is refused while a voyage of the vessel falls in the dry-docking dates.</p>
        </div></div>
        <?php endif; ?>
        <?php if ($costs): ?><div class="card reveal"><div class="card-header">Accounting documents</div><?= ship_documents_table(ShipGL::documents('drydock', (int)$dd['id'])) ?></div><?php endif; ?>
      </div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$rows = DB::all('SELECT d.*, s.code AS vessel_code, s.name AS vessel_name, v.name AS yard_name FROM shp_drydock d JOIN shp_vessels s ON s.id = d.vessel_id LEFT JOIN vendors v ON v.id = d.yard_vendor_id
                  ORDER BY FIELD(d.status, \'docked\', \'planned\', \'completed\', \'cancelled\'), d.planned_start');
layout_start('Dry-docking Projects', ['actions' => $canCreate ? btn_link(url($page, ['a' => 'new']), 'New project', 'btn-primary', 'plus-lg') : '']);
?>
<div class="row g-3">
<?php foreach ($rows as $r): $act = dd_actuals($r); $pct = (float)$r['progress_pct']; ?>
  <div class="col-md-6 col-xl-4"><a class="card reveal h-100 text-decoration-none text-reset" href="<?= h(url($page, ['id' => $r['id']])) ?>"><div class="card-body">
    <div class="d-flex justify-content-between gap-2"><span class="small text-muted"><?= h($r['dd_no']) ?>, <?= h($r['vessel_code']) ?></span><?= dd_label($r['status']) ?></div>
    <div class="fw-bold mt-1"><?= h($r['title']) ?></div>
    <div class="small text-muted mb-2"><?= h($r['vessel_name']) ?><?= $r['yard_name'] ? ' at ' . h($r['yard_name']) : '' ?></div>
    <div class="d-flex justify-content-between small"><span><?= h(fdate($r['planned_start'])) ?> to <?= h(fdate($r['planned_end'])) ?></span><span><?= qty($pct) ?>%</span></div>
    <?= meter($pct) ?>
    <?php if ($costs): ?><div class="d-flex justify-content-between small mt-2"><span>Spent ₱<?= money($act['total']) ?></span><span class="text-muted">of ₱<?= money($r['budget']) ?></span></div><?php endif; ?>
  </div></a></div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="col-12"><div class="card"><?= empty_note('No dry-docking project yet.', 'tools') ?></div></div><?php endif; ?>
</div>
<?php
layout_end();
