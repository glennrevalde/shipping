<?php
declare(strict_types=1);

$page = 'fleet.requisitions';
$id = gint('id');
$a = (string)g('a');
$pr = $id ? DB::row('SELECT r.*, s.name AS vessel_name, s.code AS vessel_code, w.wo_no, st.name AS site_name, u.full_name AS requester, ap.full_name AS approver, t.txn_no AS po_no
                       FROM shp_requisitions r LEFT JOIN shp_vessels s ON s.id = r.vessel_id LEFT JOIN shp_work_orders w ON w.id = r.wo_id LEFT JOIN sites st ON st.id = r.site_id
                       LEFT JOIN users u ON u.id = r.requested_by LEFT JOIN users ap ON ap.id = r.approved_by LEFT JOIN transactions t ON t.id = r.po_txn_id WHERE r.id = :id', ['id' => $id]) : null;
if ($id && !$pr) {
    flash('warning', 'That requisition no longer exists.');
    redirect(url($page));
}
const PR_STATUS = ['draft' => 'Draft', 'submitted' => 'Waiting for approval', 'approved' => 'Approved, to canvass', 'canvass' => 'Canvassing', 'ordered' => 'Ordered', 'rejected' => 'Rejected', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];

if (!function_exists('pr_label')) {
    function pr_label(string $s): string
    {
        $tone = ['draft' => 'muted', 'submitted' => 'warn', 'approved' => 'info', 'canvass' => 'info', 'ordered' => 'good', 'rejected' => 'bad', 'closed' => 'good', 'cancelled' => 'muted'][$s] ?? 'muted';
        return '<span class="st st-' . $tone . '">' . h(PR_STATUS[$s] ?? $s) . '</span>';
    }

    function pr_posted_lines(): array
    {
        $out = [];
        foreach ((array)($_POST['ln_desc'] ?? []) as $i => $desc) {
            $out[] = ['item_id' => (int)($_POST['ln_item'][$i] ?? 0) ?: null, 'description' => trim((string)$desc), 'part_no' => trim((string)($_POST['ln_part'][$i] ?? '')) ?: null,
                'qty' => to_num($_POST['ln_qty'][$i] ?? 0), 'unit' => trim((string)($_POST['ln_unit'][$i] ?? '')), 'est_unit_cost' => to_num($_POST['ln_cost'][$i] ?? 0)];
        }
        return $out;
    }
}

handle_post(function () use ($page, $pr) {
    $act = (string)p('act');
    if ($act === 'save' || $act === 'save_submit') {
        ship_require($page, $pr ? 'edit' : 'create');
        $nid = Procure::save(['department' => p('department') ?: null, 'vessel_id' => pint('vessel_id'), 'wo_id' => pint('wo_id'), 'site_id' => pint('site_id'), 'needed_by' => p('needed_by'),
            'priority' => p('priority'), 'justification' => mb_substr(trim((string)p('justification')), 0, 500) ?: null], pr_posted_lines(), $pr ? (int)$pr['id'] : null);
        if ($act === 'save_submit') {
            Procure::submit($nid);
            $st = (string)DB::value('SELECT status FROM shp_requisitions WHERE id = :id', ['id' => $nid]);
            flash('success', $st === 'approved' ? 'Emergency purchase approved at once for ordering; another person reviews it after the fact.' : 'Requisition submitted for approval.');
        } else {
            flash('success', 'Requisition saved as a draft.');
        }
        redirect(url($page, ['id' => $nid]));
    }
    if (!$pr) {
        fail('Requisition not found.');
    }
    $back = url($page, ['id' => $pr['id']]);
    switch ($act) {
        case 'submit':
            if ((int)$pr['requested_by'] !== (int)Auth::id()) {
                ship_require($page, 'edit');
            }
            Procure::submit((int)$pr['id']);
            flash('success', 'Requisition submitted for approval.');
            break;
        case 'decide':
            $ap = DB::row("SELECT * FROM shp_approvals WHERE ref_table = 'shp_requisitions' AND ref_id = :r AND status = 'pending' ORDER BY id DESC LIMIT 1", ['r' => $pr['id']]);
            if (!$ap) {
                fail('There is nothing waiting for a decision on this requisition.');
            }
            ShipApproval::decide((int)$ap['id'], p('decision') === 'approve', (string)p('notes'));
            flash('success', p('decision') === 'approve' ? 'Approved.' : 'Rejected; the requester was told.');
            break;
        case 'quote':
            ship_require($page, 'edit');
            $vendor = (int)pint('vendor_id');
            if (!$vendor) {
                fail('Choose the supplier.');
            }
            $prices = [];
            foreach ((array)($_POST['price'] ?? []) as $lid => $v) {
                $prices[(int)$lid] = to_num($v);
            }
            Procure::addQuote((int)$pr['id'], $vendor, $prices, p('quote_ref') ?: null, pdate('quote_date'), pint('delivery_days'), p('terms_note') ?: null);
            flash('success', 'Quotation added to the canvass.');
            break;
        case 'order':
            ship_require($page, 'edit');
            $po = Procure::order((int)$pr['id'], (int)pint('quote_id'), (string)p('reason'));
            flash('success', 'Purchase order ' . DB::value('SELECT txn_no FROM transactions WHERE id = :t', ['t' => $po]) . ' made; receive the items against it when they arrive.');
            break;
        case 'cancel':
            ship_require($page, 'edit');
            if (!in_array($pr['status'], ['draft', 'rejected', 'approved', 'canvass'], true)) {
                fail('A requisition that is ordered or waiting for approval is not cancelled here.');
            }
            DB::update('shp_requisitions', ['status' => 'cancelled', 'updated_at' => Ship::now()], 'id = :id', ['id' => $pr['id']]);
            Ship::audit('cancel', 'fleet', 'shp_pr', (int)$pr['id'], $pr['pr_no'], ['reason' => (string)p('reason'), 'page_key' => 'fleet.requisitions']);
            flash('success', 'Requisition cancelled.');
            break;
        default:
            fail('Unknown action.');
    }
    redirect($back);
});

$canEdit = Access::can($page, 'edit');
$canCreate = Access::can($page, 'create');
$costs = Access::viewCosts() || $canEdit;

// ------------------------------------------------------------------ form
if ($a === 'new' || $a === 'edit' || (is_post() && in_array(p('act'), ['save', 'save_submit'], true))) {
    $e = ($a === 'edit' || ($pr && is_post())) ? $pr : null;
    if ($e && !in_array($e['status'], ['draft', 'rejected'], true)) {
        flash('warning', 'A requisition is changed only while it is a draft or after it was rejected.');
        redirect(url($page, ['id' => $e['id']]));
    }
    ship_require($page, $e ? ((int)$e['requested_by'] === (int)Auth::id() ? 'create' : 'edit') : 'create');
    $wo = gint('wo') ? DB::row('SELECT id, wo_no, vessel_id, title FROM shp_work_orders WHERE id = :id', ['id' => gint('wo')]) : null;
    $lines = is_post() ? pr_posted_lines() : ($e ? DB::all('SELECT * FROM shp_requisition_lines WHERE pr_id = :p ORDER BY line_no', ['p' => $e['id']]) : []);
    while (count($lines) < 3) {
        $lines[] = ['item_id' => null, 'description' => '', 'part_no' => '', 'qty' => '', 'unit' => '', 'est_unit_cost' => ''];
    }
    $items = DB::all("SELECT id, code, name, unit, cost FROM items WHERE is_active = 1 AND item_type IN ('inventory', 'service') AND code NOT LIKE 'SHP-%' AND code NOT LIKE 'CAN-%' ORDER BY code");
    $woOpts = DB::pairs("SELECT w.id, CONCAT(w.wo_no, ' ', s.code, ' ', w.title) FROM shp_work_orders w JOIN shp_vessels s ON s.id = w.vessel_id WHERE w.status NOT IN ('closed', 'cancelled', 'completed') ORDER BY w.id DESC");
    layout_start($e ? 'Edit ' . $e['pr_no'] : 'New purchase requisition', ['actions' => btn_link($e ? url($page, ['id' => $e['id']]) : url($page), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" class="row g-3"><?= csrf_field() ?>
      <div class="col-xl-4"><div class="card reveal"><div class="card-header">Request</div><div class="card-body"><div class="row g-2">
        <?= select_field('vessel_id', 'For vessel', Ship::vesselOptions(), fv('vessel_id', $e, $wo['vessel_id'] ?? ''), ['class' => 'col-12', 'blank' => 'Shore / office']) ?>
        <?= select_field('wo_id', 'For work order', $woOpts, fv('wo_id', $e, $wo['id'] ?? ''), ['class' => 'col-12', 'blank' => '']) ?>
        <?= select_field('department', 'Department', ['Engine' => 'Engine', 'Deck' => 'Deck', 'Hotel' => 'Hotel / catering', 'Safety' => 'Safety', 'Office' => 'Office'], fv('department', $e, 'Engine'), ['class' => 'col-6']) ?>
        <?= input_field('needed_by', 'Needed by', fv('needed_by', $e), ['type' => 'date', 'class' => 'col-6']) ?>
        <?= select_field('site_id', 'Deliver to', DB::pairs('SELECT id, name FROM sites WHERE is_active = 1 ORDER BY name'), fv('site_id', $e, 1), ['class' => 'col-12']) ?>
        <?= select_field('priority', 'Priority', ['normal' => 'Normal', 'urgent' => 'Urgent', 'emergency' => 'Emergency (vessel cannot wait for canvass)'], fv('priority', $e, 'normal'), ['class' => 'col-12']) ?>
        <div class="col-12"><label class="form-label">Why it is needed</label><textarea class="form-control form-control-sm" name="justification" rows="3" maxlength="500"><?= h((string)fv('justification', $e, $wo ? 'For ' . $wo['wo_no'] . ': ' . $wo['title'] : '')) ?></textarea></div>
      </div><div class="form-text">An emergency purchase is ordered without waiting for approval only by someone with the emergency-purchase permission; it is still reviewed afterwards.</div></div></div></div>
      <div class="col-xl-8"><div class="card reveal"><div class="card-header">What to buy</div><div class="card-body">
        <div class="table-responsive"><table class="table table-sm grid-input mb-1" id="prLines"><thead><tr><th>Stock item (if any)</th><th>Description</th><th>Part no.</th><th>Qty</th><th>Unit</th><th>Est. unit cost</th><th></th></tr></thead><tbody>
        <?php foreach ($lines as $l): ?>
          <tr><td><select class="form-select form-select-sm" name="ln_item[]" style="min-width:170px" data-no-combo><option value=""></option><?php foreach ($items as $it): ?><option value="<?= (int)$it['id'] ?>" data-unit="<?= h((string)$it['unit']) ?>" data-cost="<?= h((string)$it['cost']) ?>" data-name="<?= h($it['name']) ?>" <?= (int)$l['item_id'] === (int)$it['id'] ? 'selected' : '' ?>><?= h($it['code']) ?></option><?php endforeach; ?></select></td>
            <td><input class="form-control form-control-sm w-desc" name="ln_desc[]" value="<?= h((string)$l['description']) ?>"></td>
            <td><input class="form-control form-control-sm" name="ln_part[]" value="<?= h((string)$l['part_no']) ?>"></td>
            <td><input class="form-control form-control-sm" style="width:80px" type="number" step="0.01" name="ln_qty[]" value="<?= h((string)((float)$l['qty'] ?: '')) ?>"></td>
            <td><input class="form-control form-control-sm" style="width:70px" name="ln_unit[]" value="<?= h((string)$l['unit']) ?>"></td>
            <td><input class="form-control form-control-sm" style="width:110px" type="number" step="0.01" name="ln_cost[]" value="<?= h((string)((float)$l['est_unit_cost'] ?: '')) ?>"></td>
            <td><button type="button" class="rm" data-rm title="Remove line"><i class="bi bi-x-lg"></i></button></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <button type="button" class="btn btn-sm btn-link px-0" id="prAdd"><i class="bi bi-plus-lg"></i> Add a line</button>
        <div class="d-flex justify-content-end small">Estimated total <b class="ms-2" id="prTotal">0.00</b></div>
      </div><div class="card-footer bg-transparent d-flex flex-wrap gap-2">
        <button class="btn btn-outline-primary btn-sm" name="act" value="save"><i class="bi bi-save"></i> Save draft</button>
        <button class="btn btn-primary btn-sm" name="act" value="save_submit"><i class="bi bi-send"></i> Save and submit for approval</button>
      </div></div></div>
    </form>
    <script>
    (function () {
      var body = document.querySelector('#prLines tbody'), tot = document.getElementById('prTotal');
      function sum() { var t = 0; body.querySelectorAll('tr').forEach(function (tr) { t += (parseFloat(tr.querySelector('[name="ln_qty[]"]').value) || 0) * (parseFloat(tr.querySelector('[name="ln_cost[]"]').value) || 0); });
        tot.textContent = t.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
      body.addEventListener('input', sum);
      body.addEventListener('change', function (e) {
        if (e.target.name !== 'ln_item[]') return; var o = e.target.selectedOptions[0], tr = e.target.closest('tr'); if (!o || !o.value) return;
        var d = tr.querySelector('[name="ln_desc[]"]'), u = tr.querySelector('[name="ln_unit[]"]'), c = tr.querySelector('[name="ln_cost[]"]');
        if (!d.value) d.value = o.dataset.name; if (!u.value) u.value = o.dataset.unit; if (!c.value) c.value = o.dataset.cost; sum();
      });
      document.getElementById('prAdd').addEventListener('click', function () { var tr = body.rows[body.rows.length - 1].cloneNode(true); tr.querySelectorAll('input').forEach(function (i) { i.value = ''; }); tr.querySelector('select').value = ''; body.appendChild(tr); });
      body.addEventListener('click', function (e) { var b = e.target.closest('[data-rm]'); if (b && body.rows.length > 1) { b.closest('tr').remove(); sum(); } });
      sum();
    })();
    </script>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ one requisition
if ($pr) {
    $lines = DB::all('SELECT l.*, i.code AS item_code FROM shp_requisition_lines l LEFT JOIN items i ON i.id = l.item_id WHERE l.pr_id = :p ORDER BY l.line_no', ['p' => $pr['id']]);
    $quotes = DB::all('SELECT q.*, v.name AS vendor FROM shp_quotes q JOIN vendors v ON v.id = q.vendor_id WHERE q.pr_id = :p ORDER BY q.total, q.id', ['p' => $pr['id']]);
    $ql = [];
    foreach (DB::all('SELECT ql.* FROM shp_quote_lines ql JOIN shp_quotes q ON q.id = ql.quote_id WHERE q.pr_id = :p', ['p' => $pr['id']]) as $x) {
        $ql[(int)$x['quote_id']][(int)$x['pr_line_id']] = $x;
    }
    $approvals = ShipApproval::forRecord('shp_requisitions', (int)$pr['id']);
    $pending = array_values(array_filter($approvals, fn($x) => $x['status'] === 'pending'));
    [$mayDecide, $why] = $pending ? ShipApproval::canDecide($pending[count($pending) - 1]) : [false, ''];
    $lowest = $quotes ? min(array_map(fn($q) => (float)$q['total'], $quotes)) : null;
    $mine = (int)$pr['requested_by'] === (int)Auth::id();
    $actions = (in_array($pr['status'], ['draft', 'rejected'], true) && ($canEdit || $mine) ? btn_link(url($page, ['a' => 'edit', 'id' => $pr['id']]), 'Edit', 'btn-outline-primary', 'pencil') . ' ' : '');
    if ($pr['po_txn_id']) {
        $actions .= $pr['status'] === 'ordered' && Access::page('vend.receive_items') ? btn_link(url('vend.receive_items', ['po' => $pr['po_txn_id']]), 'Receive the items', 'btn-primary', 'box-arrow-in-down') . ' ' : '';
        $actions .= Access::page('vend.bill_received') ? btn_link(url('vend.bill_received'), 'Enter the supplier bill', 'btn-outline-secondary', 'receipt') : '';
    }
    layout_start('Requisition ' . $pr['pr_no'], ['actions' => $actions]);
    $flowNow = $pr['status'] === 'rejected' ? 'submitted' : ($pr['status'] === 'approved' ? 'canvass' : $pr['status']);
    ?>
    <div class="card reveal mb-3"><div class="card-body d-flex flex-wrap justify-content-between gap-3">
      <div><div class="fs-5 fw-bold"><?= h($pr['justification'] ?: 'Purchase requisition') ?></div>
        <div class="small text-muted"><?= h($pr['vessel_name'] ?: 'Shore / office') ?><?= $pr['department'] ? ', ' . h($pr['department']) : '' ?><?= $pr['wo_no'] ? ', for ' . safe_link('fleet.workorders', ['id' => $pr['wo_id']], $pr['wo_no']) : '' ?></div>
        <div class="mt-1 d-flex flex-wrap gap-3"><?= pr_label($pr['status']) ?><?= Ship::severityLabel($pr['priority'] === 'emergency' ? 'urgent' : ($pr['priority'] === 'urgent' ? 'high' : 'normal')) ?></div></div>
      <div class="text-lg-end"><div class="small text-muted">Estimated</div><div class="fs-4 fw-bold">₱<?= money($pr['est_total']) ?></div><?php if ($pr['po_txn_id']): ?><div class="small">Ordered on <?= txn_doc_link((int)$pr['po_txn_id'], $pr['po_no']) ?></div><?php endif; ?></div>
    </div></div>
    <div class="mb-3"><?= flow_steps(['draft' => 'Draft', 'submitted' => 'Approval', 'canvass' => 'Canvass', 'ordered' => 'Ordered', 'closed' => 'Received'], $flowNow) ?></div>
    <div class="row g-3">
      <div class="col-xl-8">
        <div class="card reveal mb-3"><div class="card-header">Lines</div><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>#</th><th>Item</th><th>Part no.</th><th class="num">Qty</th><th class="num">Est. unit</th><th class="num">Est. amount</th></tr></thead><tbody>
          <?php foreach ($lines as $l): ?><tr><td><?= (int)$l['line_no'] ?></td><td><?= h($l['description']) ?><?= $l['item_code'] ? ' <span class="small text-muted">' . h($l['item_code']) . '</span>' : '' ?></td><td class="small"><?= h((string)$l['part_no']) ?></td>
            <td class="num"><?= qty($l['qty']) ?> <?= h((string)$l['unit']) ?></td><td class="num"><?= money($l['est_unit_cost']) ?></td><td class="num"><?= money((float)$l['qty'] * (float)$l['est_unit_cost']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
          <div class="card-body small text-muted"><?= h((string)$pr['budget_note']) ?></div></div>

        <?php if (in_array($pr['status'], ['approved', 'canvass', 'ordered', 'closed'], true)): ?>
        <div class="card reveal mb-3"><div class="card-header d-flex justify-content-between"><span>Canvass of quotations</span><span class="small text-muted"><?= count($quotes) ?> supplier(s)<?= $pr['priority'] !== 'emergency' ? ', at least two expected' : '' ?></span></div>
        <?php if ($quotes): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="order">
          <div class="table-responsive"><table class="table table-sm align-middle mb-0 quote-compare">
            <thead><tr><th>Item</th><?php foreach ($quotes as $q): $best = abs((float)$q['total'] - $lowest) < 0.005; ?><th class="num <?= $best ? 'best' : '' ?> <?= (int)$q['is_selected'] ? 'chosen' : '' ?>"><?= h($q['vendor']) ?><div class="small fw-normal text-muted"><?= h(trim(($q['quote_ref'] ?: '') . ($q['delivery_days'] ? ', ' . (int)$q['delivery_days'] . ' days' : '') . ($q['terms_note'] ? ', ' . $q['terms_note'] : ''), ', ')) ?></div></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($lines as $l): ?><tr><td class="small"><?= h($l['description']) ?> <span class="text-muted">x <?= qty($l['qty']) ?></span></td>
              <?php
              $prices = array_filter(array_map(fn($q) => isset($ql[(int)$q['id']][(int)$l['id']]) ? (float)$ql[(int)$q['id']][(int)$l['id']]['unit_price'] : null, $quotes), fn($v) => $v !== null);
              $lineLow = $prices ? min($prices) : null;
              foreach ($quotes as $q): $x = $ql[(int)$q['id']][(int)$l['id']] ?? null; ?>
                <td class="num <?= $x && abs((float)$x['unit_price'] - $lineLow) < 0.005 ? 'best' : '' ?>"><?= $x ? money($x['unit_price']) . '<div class="small text-muted">' . money($x['amount']) . '</div>' : '-' ?></td>
              <?php endforeach; ?></tr><?php endforeach; ?></tbody>
            <tfoot><tr><td>Total before VAT</td><?php foreach ($quotes as $q): ?><td class="num <?= abs((float)$q['total'] - $lowest) < 0.005 ? 'best' : '' ?>">₱<?= money($q['total']) ?></td><?php endforeach; ?></tr>
            <?php if ($canEdit && in_array($pr['status'], ['approved', 'canvass'], true)): ?><tr><td class="small fw-normal">Order from</td><?php foreach ($quotes as $q): ?><td class="num"><input class="form-check-input" type="radio" name="quote_id" value="<?= (int)$q['id'] ?>" <?= abs((float)$q['total'] - $lowest) < 0.005 ? 'checked' : '' ?> aria-label="Order from <?= h($q['vendor']) ?>"></td><?php endforeach; ?></tr><?php endif; ?>
            </tfoot></table></div>
          <?php if ($canEdit && in_array($pr['status'], ['approved', 'canvass'], true)): ?>
            <div class="card-body border-top row g-2 align-items-end"><?= input_field('reason', 'Reason (needed when it is not the lowest, or for a single quotation)', '', ['class' => 'col-md-8']) ?>
              <div class="col-md-4"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-cart-check"></i> Make the purchase order</button></div></div>
          <?php endif; ?>
          </form>
        <?php else: ?><div class="card-body"><?= empty_note('No quotation yet. Ask at least two suppliers and enter their prices below.', 'chat-square-quote') ?></div><?php endif; ?>
        <?php if ($canEdit && in_array($pr['status'], ['approved', 'canvass'], true)): ?>
          <details class="border-top" <?= count($quotes) < 2 ? 'open' : '' ?>><summary class="card-body py-2 small fw-semibold">Enter a supplier's quotation</summary>
          <form method="post" class="card-body pt-0"><?= csrf_field() ?><input type="hidden" name="act" value="quote"><div class="row g-2">
            <?= select_field('vendor_id', 'Supplier', DB::pairs('SELECT id, name FROM vendors WHERE is_active = 1 AND id NOT IN (SELECT vendor_id FROM shp_quotes WHERE pr_id = ' . (int)$pr['id'] . ') ORDER BY name'), '', ['class' => 'col-md-4', 'blank' => '', 'required' => true]) ?>
            <?= input_field('quote_ref', 'Their quotation no.', '', ['class' => 'col-md-3']) ?><?= input_field('quote_date', 'Date', Ship::today(), ['type' => 'date', 'class' => 'col-md-2']) ?>
            <?= input_field('delivery_days', 'Delivery (days)', '', ['type' => 'number', 'step' => '1', 'class' => 'col-md-1']) ?><?= input_field('terms_note', 'Terms', '', ['class' => 'col-md-2']) ?>
          </div><table class="table table-sm mt-2 mb-2"><tbody>
            <?php foreach ($lines as $l): ?><tr><td class="small"><?= h($l['description']) ?> <span class="text-muted">x <?= qty($l['qty']) ?> <?= h((string)$l['unit']) ?></span></td><td style="width:180px"><input class="form-control form-control-sm" type="number" step="0.01" name="price[<?= (int)$l['id'] ?>]" placeholder="Unit price" required></td></tr><?php endforeach; ?>
          </tbody></table><button class="btn btn-outline-primary btn-sm">Add quotation</button></form></details>
        <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="col-xl-4">
        <div class="card reveal mb-3"><div class="card-body"><div class="info-grid">
          <?= info_pair('Requested by', $pr['requester'] . ', ' . fdatetime($pr['created_at'])) ?><?= info_pair('Needed by', $pr['needed_by'] ? fdate($pr['needed_by']) : '-') ?>
          <?= info_pair('Deliver to', $pr['site_name'] ?: '-') ?><?= info_pair('Approved by', $pr['approver'] ? $pr['approver'] . ', ' . fdatetime($pr['approved_at']) : '-') ?>
          <?= $pr['priority'] === 'emergency' ? info_pair('After-the-fact review', $pr['emergency_review_by'] ? Ship::userName((int)$pr['emergency_review_by']) . ', ' . fdatetime($pr['emergency_review_at']) : 'Pending') : '' ?>
        </div></div></div>
        <?php if (in_array($pr['status'], ['draft', 'rejected'], true) && ($mine || $canEdit)): ?>
          <form method="post" class="card reveal mb-3"><?= csrf_field() ?><input type="hidden" name="act" value="submit"><div class="card-body d-flex gap-2 align-items-center">
            <span class="small flex-fill"><?= $pr['status'] === 'rejected' ? 'Rejected. Change it and submit again, or cancel it.' : 'Still a draft.' ?></span><button class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Submit</button></div></form>
        <?php endif; ?>
        <?php if ($pending): ?>
          <div class="card reveal mb-3"><div class="card-header">Approval</div><div class="card-body">
            <p class="small mb-2"><?= h($pending[count($pending) - 1]['summary']) ?></p>
            <?php if ($mayDecide): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="decide"><textarea class="form-control form-control-sm mb-2" name="notes" rows="2" placeholder="Notes (needed to reject)"></textarea>
                <div class="d-flex gap-2"><button class="btn btn-primary btn-sm" name="decision" value="approve">Approve</button><button class="btn btn-outline-danger btn-sm" name="decision" value="reject">Reject</button></div></form>
            <?php else: ?><p class="small text-muted mb-0"><?= h($why ?: 'Waiting for someone with approval rights.') ?></p><?php endif; ?>
          </div></div>
        <?php endif; ?>
        <?php if ($approvals): ?><div class="card reveal mb-3"><div class="card-header">Decisions</div><div class="card-body"><ul class="timeline">
          <?php foreach (array_reverse($approvals) as $ap): ?><li class="<?= $ap['status'] === 'rejected' ? 't-bad' : ($ap['status'] === 'pending' ? 't-warn' : '') ?>"><div class="when"><?= h(fdatetime($ap['decided_at'] ?: $ap['requested_at'])) ?></div>
            <div><?= Ship::label($ap['status']) ?> <?= $ap['decided_by'] ? h(Ship::userName((int)$ap['decided_by'])) : '' ?><?= $ap['decision_notes'] ? ': ' . h($ap['decision_notes']) : '' ?></div></li><?php endforeach; ?>
        </ul></div></div><?php endif; ?>
        <?php if ($canEdit && in_array($pr['status'], ['draft', 'rejected', 'approved', 'canvass'], true)): ?>
          <details class="card reveal"><summary class="card-header small">Cancel the requisition</summary><form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="act" value="cancel">
            <input class="form-control form-control-sm mb-2" name="reason" placeholder="Why" required><button class="btn btn-sm btn-outline-danger" data-confirm="Cancel this requisition?">Cancel it</button></form></details>
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
$groups = ['open' => ['In progress', ['draft', 'submitted', 'approved', 'canvass', 'rejected']], 'approval' => ['Waiting for approval', ['submitted']], 'canvass' => ['To canvass', ['approved', 'canvass']],
    'ordered' => ['Ordered', ['ordered', 'closed']], 'all' => ['All', []]];
if (!isset($groups[$tab])) {
    $tab = 'open';
}
$where = '1 = 1';
$par = [];
if ($vessel) {
    $where .= ' AND r.vessel_id = :v';
    $par['v'] = $vessel;
}
$counts = DB::pairs("SELECT r.status, COUNT(*) FROM shp_requisitions r WHERE $where GROUP BY r.status", $par);
$n = fn(array $s) => $s ? array_sum(array_map(fn($k) => (int)($counts[$k] ?? 0), $s)) : array_sum(array_map('intval', $counts));
$lw = $where;
if ($groups[$tab][1]) {
    [$in, $ip] = DB::in('rs', $groups[$tab][1]);
    $lw .= " AND r.status IN $in";
    $par += $ip;
}
$rows = DB::all("SELECT r.*, s.code AS vessel_code, w.wo_no, u.full_name AS requester, t.txn_no AS po_no, (SELECT COUNT(*) FROM shp_quotes q WHERE q.pr_id = r.id) AS quotes
                   FROM shp_requisitions r LEFT JOIN shp_vessels s ON s.id = r.vessel_id LEFT JOIN shp_work_orders w ON w.id = r.wo_id LEFT JOIN users u ON u.id = r.requested_by
                   LEFT JOIN transactions t ON t.id = r.po_txn_id WHERE $lw ORDER BY FIELD(r.priority, 'emergency', 'urgent', 'normal'), r.id DESC LIMIT 300", $par);
$ordered = (float)DB::value("SELECT COALESCE(SUM(q.total), 0) FROM shp_requisitions r JOIN shp_quotes q ON q.id = r.selected_quote_id WHERE r.status IN ('ordered', 'closed') AND YEAR(r.updated_at) = :y", ['y' => substr(Ship::today(), 0, 4)]);
layout_start('Purchase Requisitions & Canvass', ['actions' => $canCreate ? btn_link(url($page, ['a' => 'new']), 'New requisition', 'btn-primary', 'plus-lg') : '']);
echo '<div class="kpi-grid mb-3">'
    . kpi_tile('Waiting for approval', (string)$n(['submitted']), 'hourglass-split', 'Approved by someone other than the requester', $n(['submitted']) ? 'warn' : '', url($page, ['tab' => 'approval']), (float)$n(['submitted']))
    . kpi_tile('To canvass', (string)$n(['approved', 'canvass']), 'chat-square-quote', 'Two quotations or a reason', '', url($page, ['tab' => 'canvass']), (float)$n(['approved', 'canvass']))
    . kpi_tile('Ordered', (string)$n(['ordered', 'closed']), 'cart-check', 'Purchase orders made', 'good', url($page, ['tab' => 'ordered']), (float)$n(['ordered', 'closed']))
    . ($costs ? kpi_tile('Ordered this year', '₱' . money($ordered), 'cash-stack', 'Before VAT, from the chosen quotations', '', null, $ordered) : '')
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
  <thead><tr><th>Requisition</th><th>For</th><th>Why</th><th>Requested by</th><th>Needed by</th><th class="num">Estimate</th><th class="num">Quotes</th><th>Order</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="fw-semibold text-nowrap"><a href="<?= h(url($page, ['id' => $r['id']])) ?>"><?= h($r['pr_no']) ?></a><?= $r['priority'] !== 'normal' ? '<div>' . Ship::severityLabel($r['priority'] === 'emergency' ? 'urgent' : 'high') . '</div>' : '' ?></td>
      <td class="small"><?= h($r['vessel_code'] ?: 'Shore') ?><?= $r['wo_no'] ? '<div class="text-muted">' . h($r['wo_no']) . '</div>' : '' ?></td><td class="small"><?= h(mb_strimwidth((string)$r['justification'], 0, 70, '...')) ?></td>
      <td class="small"><?= h((string)$r['requester']) ?></td><td class="small"><?= h(fdate($r['needed_by'])) ?></td><td class="num"><?= money($r['est_total']) ?></td><td class="num"><?= (int)$r['quotes'] ?></td>
      <td class="small"><?= $r['po_txn_id'] ? txn_doc_link((int)$r['po_txn_id'], $r['po_no']) : '' ?></td><td><?= pr_label($r['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9"><?= empty_note('No requisition here.', 'cart') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php
layout_end();
