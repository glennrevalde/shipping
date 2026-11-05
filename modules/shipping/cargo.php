<?php
declare(strict_types=1);

$page = 'cgo.shipments';
$id = gint('id');
$a = (string)g('a');
$c = $id ? Cargo::find($id) : null;
if ($id && !$c) {
    flash('warning', 'That shipment no longer exists.');
    redirect(url($page));
}

const CGO_COMMODITY = ['general' => 'General cargo', 'perishable' => 'Perishable', 'dangerous' => 'Dangerous goods', 'livestock' => 'Livestock', 'fragile' => 'Fragile', 'vehicle_parts' => 'Vehicle parts'];
const CGO_CHANNELS = ['cash' => 'Cash', 'card' => 'Credit / debit card', 'ewallet' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer'];

if (!function_exists('cgo_open_voyages')) {
    /** Voyages still taking cargo, with their route (for the voyage list that follows the route chosen) */
    function cgo_open_voyages(?int $keep = null): array
    {
        return DB::all("SELECT v.id, v.route_id, CONCAT(v.voyage_no, ', ', DATE_FORMAT(v.etd, '%b %e %l:%i %p'), ', ', s.name) AS label
                          FROM shp_voyages v JOIN shp_vessels s ON s.id = v.vessel_id
                         WHERE (v.status IN ('published', 'on_sale', 'boarding', 'ready') AND v.etd >= :now) OR v.id = :keep ORDER BY v.etd LIMIT 300",
            ['now' => date('Y-m-d H:i:s', strtotime(Ship::now()) - 6 * 3600), 'keep' => $keep ?: 0]);
    }

    function cgo_commodities(): array
    {
        $out = [];
        foreach (DB::column('SELECT DISTINCT commodity_class FROM shp_cargo_tariffs ORDER BY commodity_class') as $k) {
            $out[$k] = CGO_COMMODITY[$k] ?? ucwords(str_replace('_', ' ', $k));
        }
        return $out ?: ['general' => 'General cargo'];
    }

    /** Package lines posted from the form */
    function cgo_posted_items(): array
    {
        $out = [];
        foreach ((array)($_POST['it_desc'] ?? []) as $i => $desc) {
            $out[] = ['description' => trim((string)$desc), 'packages' => (int)($_POST['it_pk'][$i] ?? 1), 'packing' => trim((string)($_POST['it_packing'][$i] ?? '')) ?: null,
                'weight_kg' => to_num($_POST['it_kg'][$i] ?? 0), 'length_cm' => to_num($_POST['it_l'][$i] ?? 0), 'width_cm' => to_num($_POST['it_w'][$i] ?? 0),
                'height_cm' => to_num($_POST['it_h'][$i] ?? 0), 'volume_cbm' => to_num($_POST['it_cbm'][$i] ?? 0), 'declared_value' => to_num($_POST['it_val'][$i] ?? 0),
                'marks' => trim((string)($_POST['it_marks'][$i] ?? '')) ?: null];
        }
        return $out;
    }

    function cgo_header(): array
    {
        $d = [];
        foreach (['route_id', 'voyage_id', 'shipper_customer_id', 'shipper_name', 'shipper_phone', 'consignee_name', 'consignee_phone', 'consignee_address',
                     'commodity_class', 'description', 'special_handling', 'payment_terms', 'discount_pct', 'status'] as $k) {
            $d[$k] = is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : ($_POST[$k] ?? null);
        }
        $max = Access::limit('max_discount_pct');
        if ((float)$d['discount_pct'] < 0 || ($max !== null && (float)$d['discount_pct'] > $max + 0.0001)) {
            fail($max ? 'Your discount limit on freight is ' . qty($max) . '%.' : 'You may not give a discount on freight.');
        }
        if (!empty($d['shipper_customer_id']) && trim((string)$d['shipper_name']) === '') {
            $d['shipper_name'] = (string)DB::value('SELECT name FROM customers WHERE id = :id', ['id' => (int)$d['shipper_customer_id']]);
        }
        return $d;
    }

    function cgo_quote_box(array $q, float $insurance = 0): string
    {
        $rows = [['Freight (' . ['kg' => 'by weight', 'cbm' => 'by volume', 'min' => 'minimum charge'][$q['basis']] . ')', money($q['freight_gross'])]];
        if ((float)$q['discount'] > 0) {
            $rows[] = ['Discount', '-' . money($q['discount'])];
        }
        if ((float)$q['handling'] > 0) {
            $rows[] = ['Handling', money($q['handling'])];
        }
        $rows[] = ['of which VAT (12%)', money($q['vat'])];
        if ((float)$q['insurance'] > 0) {
            $rows[] = ['Cargo insurance (for the insurer)', money($q['insurance'])];
        }
        $html = '<div class="quote-box"><table class="w-100">';
        foreach ($rows as [$l, $v]) {
            $html .= '<tr><td>' . h($l) . '</td><td>' . h($v) . '</td></tr>';
        }
        return $html . '<tr><td class="fw-bold pt-2">Total to pay</td><td class="fw-bold pt-2">₱' . money($q['total']) . '</td></tr></table></div>';
    }
}

handle_post(function () use ($page, $c) {
    $act = (string)p('act');
    if ($act === 'save' || $act === 'quote') {
        ship_require($page, $c ? 'edit' : 'create');
        $d = cgo_header();
        $items = cgo_posted_items();
        if ($act === 'quote') {
            // work out the price without saving
            $kg = 0.0;
            $cbm = 0.0;
            $val = 0.0;
            foreach ($items as $it) {
                $kg += $it['weight_kg'];
                $vol = $it['volume_cbm'] > 0 ? $it['volume_cbm'] : ($it['length_cm'] * $it['width_cm'] * $it['height_cm'] / 1000000 * max(1, $it['packages']));
                $cbm += $vol;
                $val += $it['declared_value'];
            }
            if (!(int)$d['route_id'] || $kg <= 0) {
                fail('Choose the route and enter the weight of the packages to work out the price.');
            }
            $date = $d['voyage_id'] ? substr((string)DB::value('SELECT etd FROM shp_voyages WHERE id = :id', ['id' => (int)$d['voyage_id']]), 0, 10) : Ship::today();
            $GLOBALS['CGO_QUOTE'] = Fares::quoteCargo((int)$d['route_id'], (string)$d['commodity_class'], $kg, $cbm, $val, $date, (float)$d['discount_pct']);
            $GLOBALS['CGO_QUOTE_TOTALS'] = [$kg, $cbm, $val];
            return;
        }
        $nid = Cargo::save($d, $items, $c ? (int)$c['id'] : null);
        flash('success', $c ? 'Shipment updated and priced again.' : 'Waybill ' . DB::value('SELECT waybill_no FROM shp_cargo WHERE id = :id', ['id' => $nid]) . ' saved.');
        redirect(url($page, ['id' => $nid]));
    }
    if (!$c) {
        fail('Shipment not found.');
    }
    $back = url($page, ['id' => $c['id']]);
    switch ($act) {
        case 'pay':
            ship_require($page, 'edit');
            $txn = Cargo::pay((int)$c['id'], (string)p('channel'), p('provider_ref') ?: null);
            flash('success', 'Freight paid; ' . Txn::label('sales_receipt') . ' ' . DB::value('SELECT txn_no FROM transactions WHERE id = :t', ['t' => $txn]) . ' recorded.');
            break;
        case 'status':
            ship_require($page, 'edit');
            $to = (string)p('to');
            if ($to === 'claimed') {
                DB::tx(function () use ($c) {
                    $case = Care::open(['category' => (string)$c['status'] === 'damaged' ? 'damaged_cargo' : 'cargo_claim', 'channel' => 'desk', 'priority' => 'high',
                        'subject' => 'Claim on waybill ' . $c['waybill_no'] . ': ' . mb_substr((string)$c['description'], 0, 100),
                        'description' => (string)p('note'), 'customer_name' => $c['shipper_name'], 'customer_phone' => $c['shipper_phone'],
                        'customer_email' => $c['shipper_customer_id'] ? DB::value('SELECT email FROM customers WHERE id = :id', ['id' => $c['shipper_customer_id']]) : null,
                        'voyage_id' => $c['voyage_id'], 'cargo_id' => $c['id']]);
                    Cargo::transition((int)$c['id'], 'claimed', ['case_id' => $case, 'note' => trim('Claim opened as case ' . DB::value('SELECT case_no FROM shp_cases WHERE id = :id', ['id' => $case]) . '. ' . p('note'))]);
                });
                flash('success', 'Claim opened as a support case; Customer Service follows it up.');
                break;
            }
            Cargo::transition((int)$c['id'], $to, ['note' => p('note'), 'received_qty' => p('received_qty'), 'delivered_to' => p('delivered_to'),
                'pod_notes' => p('pod_notes') ?: null, 'damage' => p('damage') === '1', 'new_voyage_id' => pint('new_voyage_id')]);
            flash('success', 'Waybill ' . $c['waybill_no'] . ' is now ' . strtolower(strip_tags(Ship::label($to))) . '.');
            break;
        case 'invoice':
            ship_require($page, 'edit');
            Cargo::invoice((int)$c['id']);
            flash('success', 'Freight invoiced to ' . ($c['shipper_account'] ?: $c['shipper_name']) . '.');
            break;
        default:
            fail('Unknown action.');
    }
    redirect($back);
});

$canCreate = Access::can($page, 'create');
$canEdit = Access::can($page, 'edit');
$routes = Ship::routeOptions();
$accounts = DB::pairs("SELECT id, name FROM customers WHERE is_active = 1 AND name <> 'Walk-in Passenger' ORDER BY name");

// ------------------------------------------------------------------ new / edit form
if ($a === 'new' || $a === 'edit' || (is_post() && in_array(p('act'), ['save', 'quote'], true))) {
    $e = $a === 'edit' ? $c : null;
    if ($e && (!in_array($e['status'], ['quotation', 'booked'], true) || $e['paid_txn_id'] || $e['invoice_txn_id'])) {
        flash('warning', 'A shipment can be changed only while it is an unpaid quotation or booking.');
        redirect(url($page, ['id' => $e['id']]));
    }
    ship_require($page, $e ? 'edit' : 'create');
    $items = is_post() ? cgo_posted_items() : ($e ? DB::all('SELECT * FROM shp_cargo_items WHERE cargo_id = :c ORDER BY line_no', ['c' => $e['id']]) : []);
    while (count($items) < 3) {
        $items[] = ['description' => '', 'packages' => 1, 'packing' => '', 'weight_kg' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => '', 'volume_cbm' => '', 'declared_value' => '', 'marks' => ''];
    }
    $voyages = cgo_open_voyages($e ? (int)$e['voyage_id'] : null);
    $routeSel = fv('route_id', $e, gint('route'));
    $voySel = (string)fv('voyage_id', $e, gint('voyage'));
    $maxDisc = Access::limit('max_discount_pct');
    layout_start($e ? 'Edit waybill ' . $e['waybill_no'] : 'New cargo shipment', ['actions' => btn_link($e ? url($page, ['id' => $e['id']]) : url($page), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    $blank = fn($v) => ($v === '' || $v === null || (float)$v == 0.0) ? '' : $v;
    ?>
    <form method="post" class="row g-3" id="cgoForm"><?= csrf_field() ?>
      <div class="col-xl-8">
        <div class="card reveal mb-3"><div class="card-header">Route and parties</div><div class="card-body"><div class="row g-2">
          <?= select_field('route_id', 'Route', $routes, $routeSel, ['class' => 'col-md-6', 'blank' => '', 'required' => true, 'extra' => 'id="cgoRoute"']) ?>
          <div class="col-md-6"><label class="form-label">Voyage (blank while it is only a quotation)</label>
            <select class="form-select form-select-sm" name="voyage_id" id="cgoVoyage"><option value=""></option>
            <?php foreach ($voyages as $vo): ?><option value="<?= (int)$vo['id'] ?>" data-route="<?= (int)$vo['route_id'] ?>" <?= (string)$vo['id'] === $voySel ? 'selected' : '' ?>><?= h($vo['label']) ?></option><?php endforeach; ?>
            </select></div>
          <?= select_field('shipper_customer_id', 'Shipper account (for credit terms)', $accounts, fv('shipper_customer_id', $e), ['class' => 'col-md-6', 'blank' => 'Walk-in shipper']) ?>
          <?= input_field('shipper_name', 'Shipper name', fv('shipper_name', $e), ['class' => 'col-md-6']) ?>
          <?= input_field('shipper_phone', 'Shipper mobile', fv('shipper_phone', $e), ['class' => 'col-md-4']) ?>
          <?= input_field('consignee_name', 'Consignee', fv('consignee_name', $e), ['class' => 'col-md-4', 'required' => true]) ?>
          <?= input_field('consignee_phone', 'Consignee mobile', fv('consignee_phone', $e), ['class' => 'col-md-4']) ?>
          <?= input_field('consignee_address', 'Consignee address', fv('consignee_address', $e), ['class' => 'col-12']) ?>
        </div></div></div>
        <div class="card reveal mb-3"><div class="card-header">Goods</div><div class="card-body"><div class="row g-2 mb-2">
          <?= select_field('commodity_class', 'Commodity class', cgo_commodities(), fv('commodity_class', $e, 'general'), ['class' => 'col-md-4']) ?>
          <?= input_field('description', 'Description of the goods', fv('description', $e), ['class' => 'col-md-8', 'required' => true]) ?>
          <?= input_field('special_handling', 'Special handling (keep upright, chilled, away from heat)', fv('special_handling', $e), ['class' => 'col-12']) ?>
        </div>
        <div class="table-responsive"><table class="table table-sm grid-input mb-1" id="cgoLines"><thead><tr><th>Package / contents</th><th>Pcs</th><th>Packing</th><th>Weight kg</th><th>L cm</th><th>W cm</th><th>H cm</th><th>or cbm</th><th>Declared ₱</th><th>Marks</th><th></th></tr></thead><tbody>
          <?php foreach ($items as $it): ?>
          <tr><td><input class="form-control form-control-sm w-desc" name="it_desc[]" value="<?= h($it['description']) ?>"></td>
            <td><input class="form-control form-control-sm" style="width:64px" type="number" min="1" name="it_pk[]" value="<?= h((string)($it['packages'] ?: 1)) ?>"></td>
            <td><input class="form-control form-control-sm" name="it_packing[]" value="<?= h((string)$it['packing']) ?>" placeholder="box"></td>
            <td><input class="form-control form-control-sm" type="number" step="0.01" name="it_kg[]" value="<?= h((string)$blank($it['weight_kg'])) ?>"></td>
            <td><input class="form-control form-control-sm" type="number" step="0.1" name="it_l[]" value="<?= h((string)$blank($it['length_cm'])) ?>"></td>
            <td><input class="form-control form-control-sm" type="number" step="0.1" name="it_w[]" value="<?= h((string)$blank($it['width_cm'])) ?>"></td>
            <td><input class="form-control form-control-sm" type="number" step="0.1" name="it_h[]" value="<?= h((string)$blank($it['height_cm'])) ?>"></td>
            <td><input class="form-control form-control-sm" type="number" step="0.001" name="it_cbm[]" value="<?= h((string)$blank($it['volume_cbm'])) ?>"></td>
            <td><input class="form-control form-control-sm" type="number" step="0.01" name="it_val[]" value="<?= h((string)$blank($it['declared_value'])) ?>"></td>
            <td><input class="form-control form-control-sm" name="it_marks[]" value="<?= h((string)$it['marks']) ?>"></td>
            <td><button type="button" class="rm" title="Remove line" data-rm><i class="bi bi-x-lg"></i></button></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <button type="button" class="btn btn-sm btn-link px-0" id="cgoAdd"><i class="bi bi-plus-lg"></i> Add a package line</button>
        <div class="form-text">Volume is worked out from the dimensions when cbm is left blank. Freight is charged on the weight or the volume, whichever is higher, never below the minimum charge of the tariff.</div>
        </div></div>
      </div>
      <div class="col-xl-4">
        <div class="card reveal mb-3"><div class="card-header">Terms and price</div><div class="card-body"><div class="row g-2">
          <?= select_field('payment_terms', 'Payment terms', ['prepaid' => 'Prepaid at the counter', 'credit' => 'Charge to the shipper\'s account (invoice after delivery)'], fv('payment_terms', $e, 'prepaid'), ['class' => 'col-12']) ?>
          <?php if ($maxDisc === null || $maxDisc > 0): ?><?= input_field('discount_pct', 'Freight discount %' . ($maxDisc !== null ? ' (up to ' . qty($maxDisc) . ')' : ''), p('discount_pct', '0'), ['type' => 'number', 'class' => 'col-6']) ?><?php else: ?><input type="hidden" name="discount_pct" value="0"><?php endif; ?>
          <?php if (!$e): ?><?= select_field('status', 'Save as', ['booked' => 'Booking', 'quotation' => 'Quotation only'], p('status', 'booked'), ['class' => 'col-6']) ?><?php endif; ?>
        </div>
        <div class="mt-3">
        <?php if (!empty($GLOBALS['CGO_QUOTE'])): [$kg, $cbm, $val] = $GLOBALS['CGO_QUOTE_TOTALS']; ?>
          <div class="small text-muted mb-1"><?= qty($kg) ?> kg, <?= qty(round($cbm, 3)) ?> cbm<?= $val > 0 ? ', declared ₱' . money($val) : '' ?></div>
          <?= cgo_quote_box($GLOBALS['CGO_QUOTE']) ?>
        <?php elseif ($e): ?>
          <?= cgo_quote_box(['basis' => $e['chargeable_basis'], 'freight_gross' => $e['freight_gross'], 'discount' => $e['discount_amount'], 'handling' => $e['handling_fee'], 'vat' => $e['vat_amount'], 'insurance' => $e['insurance_fee'], 'total' => $e['total_amount']]) ?>
          <div class="form-text">Saving prices the shipment again from today's tariff.</div>
        <?php else: ?>
          <p class="small text-muted mb-0">Enter the packages, then work out the price before saving. The tariff of the route and commodity is used.</p>
        <?php endif; ?>
        </div></div>
        <div class="card-footer bg-transparent d-flex flex-wrap gap-2">
          <button class="btn btn-outline-primary btn-sm" name="act" value="quote" formnovalidate><i class="bi bi-calculator"></i> Work out the price</button>
          <button class="btn btn-primary btn-sm" name="act" value="save"><i class="bi bi-check2"></i> <?= $e ? 'Save changes' : 'Save waybill' ?></button>
        </div></div>
      </div>
    </form>
    <script>
    (function () {
      var r = document.getElementById('cgoRoute'), v = document.getElementById('cgoVoyage');
      function filter() { Array.prototype.forEach.call(v.options, function (o) { if (!o.value) return; var ok = !r.value || o.dataset.route === r.value; o.hidden = !ok; o.disabled = !ok; if (!ok && o.selected) v.value = ''; }); if (v.comboSync) v.comboSync(); }
      r.addEventListener('change', filter); filter();
      var body = document.querySelector('#cgoLines tbody');
      document.getElementById('cgoAdd').addEventListener('click', function () {
        var tr = body.rows[body.rows.length - 1].cloneNode(true);
        tr.querySelectorAll('input').forEach(function (i) { i.value = i.name === 'it_pk[]' ? '1' : ''; });
        body.appendChild(tr); tr.querySelector('input').focus();
      });
      body.addEventListener('click', function (ev) { var b = ev.target.closest('[data-rm]'); if (b && body.rows.length > 1) b.closest('tr').remove(); });
    })();
    </script>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ printable waybill
if ($c && g('print') === '1') {
    ship_require($page, 'print');
    $items = DB::all('SELECT * FROM shp_cargo_items WHERE cargo_id = :c ORDER BY line_no', ['c' => $c['id']]);
    layout_start('Waybill ' . $c['waybill_no'], ['bare' => true, 'no_title' => true]);
    ?>
    <div class="print-bar no-print"><button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button><?= btn_link(url($page, ['id' => $c['id']]), 'Back to the shipment', 'btn-outline-secondary', 'arrow-left') ?></div>
    <div class="doc-sheet">
      <div class="doc-head"><div class="co"><?= h(Settings::get('company_name')) ?><small><?= h(Settings::get('company_address')) ?></small><small>TIN <?= h(Settings::get('company_tin')) ?></small></div>
        <div class="doc-no"><span>Cargo waybill</span><b><?= h($c['waybill_no']) ?></b><span><?= h(fdatetime($c['created_at'])) ?></span></div></div>
      <div class="row g-3 mb-2">
        <div class="col-sm-6"><div class="doc-title">Shipper</div><div class="fw-semibold"><?= h($c['shipper_name']) ?></div><div class="small"><?= h((string)$c['shipper_phone']) ?></div></div>
        <div class="col-sm-6"><div class="doc-title">Consignee</div><div class="fw-semibold"><?= h($c['consignee_name']) ?></div><div class="small"><?= h(trim($c['consignee_phone'] . ' ' . $c['consignee_address'])) ?></div></div>
        <div class="col-sm-6"><div class="doc-title">Route</div><div><?= h($c['origin_name']) ?> to <?= h($c['dest_name']) ?></div></div>
        <div class="col-sm-6"><div class="doc-title">Voyage</div><div><?= $c['voyage_no'] ? h($c['voyage_no'] . ', ' . fdatetime($c['etd'])) : 'To be assigned' ?></div></div>
      </div>
      <table class="table table-sm mt-2"><thead><tr><th>#</th><th>Description</th><th>Packing</th><th class="num">Pcs</th><th class="num">Weight kg</th><th class="num">cbm</th><th class="num">Declared</th><th>Marks</th></tr></thead><tbody>
      <?php foreach ($items as $it): ?><tr><td><?= (int)$it['line_no'] ?></td><td><?= h($it['description']) ?></td><td><?= h((string)$it['packing']) ?></td><td class="num"><?= (int)$it['packages'] ?></td><td class="num"><?= qty($it['weight_kg']) ?></td><td class="num"><?= qty($it['volume_cbm']) ?></td><td class="num"><?= money($it['declared_value'], true) ?></td><td><?= h((string)$it['marks']) ?></td></tr><?php endforeach; ?>
      <tr class="fw-semibold"><td></td><td colspan="2">Total (<?= h(CGO_COMMODITY[$c['commodity_class']] ?? $c['commodity_class']) ?>)</td><td class="num"><?= (int)$c['packages'] ?></td><td class="num"><?= qty($c['weight_kg']) ?></td><td class="num"><?= qty($c['volume_cbm']) ?></td><td class="num"><?= money($c['declared_value'], true) ?></td><td></td></tr>
      </tbody></table>
      <div class="row g-3">
        <div class="col-sm-6"><div class="doc-title">Special handling</div><div class="small"><?= h($c['special_handling'] ?: 'None') ?></div>
          <div class="doc-title mt-2">Terms</div><div class="small"><?= $c['payment_terms'] === 'credit' ? 'Charged to the shipper\'s account' : 'Prepaid' ?><?= $c['paid_txn_id'] ? ' (paid)' : '' ?></div></div>
        <div class="col-sm-6"><?= cgo_quote_box(['basis' => $c['chargeable_basis'], 'freight_gross' => $c['freight_gross'], 'discount' => $c['discount_amount'], 'handling' => $c['handling_fee'], 'vat' => $c['vat_amount'], 'insurance' => $c['insurance_fee'], 'total' => $c['total_amount']]) ?></div>
      </div>
      <div class="sign-row"><div>Shipper's signature</div><div>Received for shipment by</div><div>Received in good order by the consignee</div></div>
      <p class="fine mt-3 mb-0">The carrier's liability for loss or damage is limited to the declared value. Claims are filed within the period the law allows, with this waybill and a photo of the goods as received. Dangerous goods are accepted only when declared.</p>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ one shipment
if ($c) {
    $items = DB::all('SELECT * FROM shp_cargo_items WHERE cargo_id = :c ORDER BY line_no', ['c' => $c['id']]);
    $next = Ship::CARGO_FLOW[$c['status']] ?? [];
    $actions = ($canEdit && in_array($c['status'], ['quotation', 'booked'], true) && !$c['paid_txn_id'] && !$c['invoice_txn_id'] ? btn_link(url($page, ['a' => 'edit', 'id' => $c['id']]), 'Edit', 'btn-outline-primary', 'pencil') . ' ' : '')
        . (Access::can($page, 'print') ? btn_link(url($page, ['id' => $c['id'], 'print' => 1]), 'Print waybill', 'btn-outline-secondary', 'printer') : '');
    layout_start('Waybill ' . $c['waybill_no'], ['actions' => $actions]);
    $flow = ['quotation' => 'Quotation', 'booked' => 'Booked', 'accepted' => 'Accepted', 'loaded' => 'Loaded', 'in_transit' => 'In transit', 'landed' => 'Landed', 'delivered' => 'Delivered'];
    $flowNow = isset($flow[$c['status']]) ? $c['status'] : (in_array($c['status'], ['short_landed', 'damaged', 'claimed'], true) ? 'landed' : ($c['status'] === 'closed' ? 'delivered' : 'booked'));
    $paidState = $c['payment_terms'] === 'credit' ? ($c['invoice_txn_id'] ? 'Invoiced' : 'Bill after delivery') : ($c['paid_txn_id'] ? 'Paid' : 'Not paid yet');
    ?>
    <div class="card reveal mb-3"><div class="card-body d-flex flex-wrap justify-content-between gap-3 align-items-start">
      <div><div class="fs-5 fw-bold"><?= h($c['shipper_name']) ?> <i class="bi bi-arrow-right text-muted"></i> <?= h($c['consignee_name']) ?></div>
        <div class="small text-muted"><?= h($c['origin_name']) ?> to <?= h($c['dest_name']) ?><?= $c['voyage_no'] ? ', voyage ' . safe_link('ops.voyages', ['id' => $c['voyage_id']], $c['voyage_no']) . ' (' . h(fdatetime($c['etd'])) . ')' : ', no voyage assigned yet' ?></div>
        <div class="mt-1"><?= Ship::label($c['status']) ?></div></div>
      <div class="text-lg-end"><div class="small text-muted">Total charges</div><div class="fs-4 fw-bold">₱<?= money($c['total_amount']) ?></div><div class="small"><?= Ship::label($paidState === 'Paid' || $paidState === 'Invoiced' ? 'paid' : 'pending', ['paid' => $paidState, 'pending' => $paidState]) ?></div></div>
    </div></div>
    <div class="mb-3"><?= flow_steps($flow, $flowNow) ?></div>
    <div class="row g-3">
      <div class="col-xl-8">
        <div class="card reveal mb-3"><div class="card-header">Shipment</div><div class="card-body">
          <div class="info-grid mb-3">
            <?= info_pair('Goods', $c['description']) ?><?= info_pair('Commodity class', CGO_COMMODITY[$c['commodity_class']] ?? $c['commodity_class']) ?>
            <?= info_pair('Packages', (int)$c['packages'] . ($c['received_qty'] !== null ? ' (' . (int)$c['received_qty'] . ' handed over)' : '')) ?>
            <?= info_pair('Weight', qty($c['weight_kg']) . ' kg') ?><?= info_pair('Volume', qty($c['volume_cbm']) . ' cbm') ?>
            <?= info_pair('Charged by', ['kg' => 'Weight', 'cbm' => 'Volume', 'min' => 'Minimum charge'][$c['chargeable_basis']] ?? $c['chargeable_basis']) ?>
            <?= info_pair('Declared value', '₱' . money($c['declared_value'])) ?><?= info_pair('Terms', $c['payment_terms'] === 'credit' ? 'Credit (' . ($c['shipper_account'] ?: '') . ')' : 'Prepaid') ?>
            <?= info_pair('Shipper mobile', $c['shipper_phone'] ?: '-') ?><?= info_pair('Consignee mobile', $c['consignee_phone'] ?: '-') ?>
            <?= $c['consignee_address'] ? info_pair('Consignee address', $c['consignee_address']) : '' ?>
            <?= $c['special_handling'] ? info_pair('Special handling', $c['special_handling']) : '' ?>
            <?= $c['delivered_to'] ? info_pair('Delivered to', $c['delivered_to'] . ', ' . fdatetime($c['delivered_at'])) : '' ?>
          </div>
          <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>Package</th><th>Packing</th><th class="num">Pcs</th><th class="num">Weight kg</th><th class="num">Dimensions cm</th><th class="num">cbm</th><th class="num">Declared</th></tr></thead><tbody>
          <?php foreach ($items as $it): ?><tr><td><?= (int)$it['line_no'] ?></td><td><?= h($it['description']) ?><?= $it['marks'] ? '<div class="small text-muted">Marks: ' . h($it['marks']) . '</div>' : '' ?></td><td><?= h((string)$it['packing']) ?></td>
            <td class="num"><?= (int)$it['packages'] ?></td><td class="num"><?= qty($it['weight_kg']) ?></td><td class="num"><?= (float)$it['length_cm'] > 0 ? qty($it['length_cm']) . ' x ' . qty($it['width_cm']) . ' x ' . qty($it['height_cm']) : '' ?></td><td class="num"><?= qty($it['volume_cbm']) ?></td><td class="num"><?= money($it['declared_value'], true) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        </div></div>
        <div class="card reveal mb-3"><div class="card-header">Accounting documents</div><?= ship_documents_table(ShipGL::documents('cargo', (int)$c['id'])) ?></div>
      </div>
      <div class="col-xl-4">
        <div class="card reveal mb-3"><div class="card-header">Charges</div><div class="card-body">
          <?= cgo_quote_box(['basis' => $c['chargeable_basis'], 'freight_gross' => $c['freight_gross'], 'discount' => $c['discount_amount'], 'handling' => $c['handling_fee'], 'vat' => $c['vat_amount'], 'insurance' => $c['insurance_fee'], 'total' => $c['total_amount']]) ?>
          <?php if ($canEdit && $c['payment_terms'] === 'prepaid' && !$c['paid_txn_id'] && in_array($c['status'], ['quotation', 'booked', 'accepted'], true)): ?>
            <form method="post" class="row g-2 mt-2"><?= csrf_field() ?><input type="hidden" name="act" value="pay">
              <?= select_field('channel', 'Paid by', CGO_CHANNELS, 'cash', ['class' => 'col-6']) ?><?= input_field('provider_ref', 'Reference (non-cash)', '', ['class' => 'col-6']) ?>
              <div class="col-12"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-cash-coin"></i> Receive ₱<?= money($c['total_amount']) ?></button>
              <div class="form-text">Cash goes into your open cashier shift. The freight stays deferred until the voyage is completed.</div></div></form>
          <?php elseif ($canEdit && $c['payment_terms'] === 'credit' && !$c['invoice_txn_id'] && in_array($c['status'], ['delivered', 'closed', 'claimed'], true)): ?>
            <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="act" value="invoice"><button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-receipt"></i> Invoice the freight now</button></form>
          <?php endif; ?>
        </div></div>
        <div class="card reveal mb-3"><div class="card-header">Next step</div><div class="card-body">
        <?php if (!$canEdit): ?><p class="small text-muted mb-0">Your role can see the shipment but not move it.</p>
        <?php elseif (!$next): ?><p class="small text-muted mb-0">Nothing more to do: the shipment is <?= strtolower(strip_tags(Ship::label($c['status']))) ?>.</p>
        <?php else: foreach ($next as $to):
            // in transit and landing follow the voyage (set by its departure and arrival); offered by hand only once the voyage has moved
            if (($to === 'in_transit' && !in_array($c['voyage_status'], ['departed', 'arrived', 'completed'], true))
                || (in_array($to, ['landed', 'short_landed'], true) && $c['status'] === 'in_transit' && !in_array($c['voyage_status'], ['arrived', 'completed'], true))) {
                continue;
            }
            $btn = ['booked' => 'Confirm the booking', 'accepted' => 'Accept at the port', 'loaded' => 'Record loading', 'in_transit' => 'Mark in transit', 'landed' => 'Record landing',
                'delivered' => 'Deliver to the consignee', 'short_landed' => 'Report short-landed', 'damaged' => 'Report damage', 'claimed' => 'File a claim', 'closed' => 'Close the file',
                'cancelled' => 'Cancel the shipment', 'rolled_over' => 'Roll over to a later voyage'][$to] ?? $to;
            $tone = in_array($to, ['short_landed', 'damaged', 'cancelled', 'claimed'], true) ? 'btn-outline-danger' : (in_array($to, ['delivered', 'accepted', 'loaded', 'landed'], true) ? 'btn-primary' : 'btn-outline-primary'); ?>
          <details class="mb-2" <?= in_array($to, ['delivered', 'accepted', 'loaded', 'landed', 'booked'], true) && count($next) <= 3 ? 'open' : '' ?>><summary class="small fw-semibold"><?= h($btn) ?></summary>
            <form method="post" class="row g-2 mt-1 p-2 border rounded-3"><?= csrf_field() ?><input type="hidden" name="act" value="status"><input type="hidden" name="to" value="<?= h($to) ?>">
            <?php if ($to === 'delivered'): ?>
              <?= input_field('received_qty', 'Packages handed over', (string)($c['received_qty'] ?? $c['packages']), ['type' => 'number', 'step' => '1', 'class' => 'col-5', 'required' => true]) ?>
              <?= input_field('delivered_to', 'Received by (name, ID)', '', ['class' => 'col-7', 'required' => true]) ?>
              <?= input_field('pod_notes', 'Proof of delivery notes', '', ['class' => 'col-12']) ?>
              <?= check_field('damage', 'Damage seen at hand-over', false, 'col-12') ?>
            <?php elseif ($to === 'rolled_over'): ?>
              <?= select_field('new_voyage_id', 'Later voyage of the same route', Ship::voyageOptions(['published', 'on_sale', 'boarding'], (int)$c['route_id']), '', ['class' => 'col-12', 'blank' => '', 'required' => true]) ?>
              <?= input_field('note', 'Why (space, late acceptance)', '', ['class' => 'col-12']) ?>
            <?php elseif (in_array($to, ['short_landed', 'damaged'], true)): ?>
              <?= input_field('received_qty', 'Packages landed', (string)$c['packages'], ['type' => 'number', 'step' => '1', 'class' => 'col-5']) ?>
              <?= input_field('note', 'What is missing or damaged', '', ['class' => 'col-7', 'required' => true]) ?>
            <?php elseif ($to === 'claimed'): ?>
              <div class="col-12"><label class="form-label">Claim details (opens a support case)</label><textarea class="form-control form-control-sm" name="note" rows="2" required></textarea></div>
            <?php else: ?>
              <?= input_field('note', 'Note (optional)', '', ['class' => 'col-12']) ?>
            <?php endif; ?>
              <div class="col-12"><button class="btn btn-sm <?= $tone ?>" <?= $to === 'cancelled' ? 'data-confirm="Cancel this shipment?"' : '' ?>><?= h($btn) ?></button></div>
            </form></details>
        <?php endforeach; endif; ?>
        <?php if ($c['status'] === 'booked' && !$c['voyage_id'] && $canEdit): ?><p class="small text-muted mb-0 mt-2">Assign a voyage with Edit before the cargo is accepted at the port.</p><?php endif; ?>
        </div></div>
        <div class="card reveal"><div class="card-header">History</div><div class="card-body"><ul class="timeline">
          <?php foreach (array_reverse(Cargo::events((int)$c['id'])) as $ev): ?><li class="<?= in_array($ev['to_status'], ['short_landed', 'damaged', 'claimed', 'cancelled'], true) ? 't-bad' : ($ev['to_status'] === 'rolled_over' ? 't-warn' : '') ?>">
            <div class="when"><?= h(fdatetime($ev['created_at'])) ?>, <?= h($ev['full_name'] ?? 'system') ?></div><div><?= $ev['to_status'] ? Ship::label($ev['to_status']) . ' ' : '' ?><?= h((string)$ev['details']) ?></div></li><?php endforeach; ?>
        </ul></div></div>
      </div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the list
$tab = (string)g('tab', 'open');
$groups = ['open' => ['Open', ['quotation', 'booked', 'accepted', 'rolled_over']], 'aboard' => ['On board', ['loaded', 'in_transit', 'landed']],
    'exceptions' => ['Exceptions', ['short_landed', 'damaged', 'claimed']], 'done' => ['Delivered', ['delivered', 'closed']], 'cancelled' => ['Cancelled', ['cancelled']], 'all' => ['All', []]];
if (!isset($groups[$tab])) {
    $tab = 'open';
}
$q = trim((string)g('q'));
$route = gint('route');
$voyage = gint('voyage');
$from = gdate('from');
$to = gdate('to');
$where = '1 = 1';
$par = [];
if ($q !== '') {
    $where .= ' AND (c.waybill_no LIKE :q OR c.shipper_name LIKE :q OR c.consignee_name LIKE :q OR c.description LIKE :q)';
    $par['q'] = '%' . $q . '%';
}
if ($route) {
    $where .= ' AND c.route_id = :r';
    $par['r'] = $route;
}
if ($voyage) {
    $where .= ' AND c.voyage_id = :v';
    $par['v'] = $voyage;
}
if ($from) {
    $where .= ' AND DATE(c.created_at) >= :f';
    $par['f'] = $from;
}
if ($to) {
    $where .= ' AND DATE(c.created_at) <= :t';
    $par['t'] = $to;
}
$counts = DB::pairs("SELECT c.status, COUNT(*) FROM shp_cargo c WHERE $where GROUP BY c.status", $par);
$n = fn(array $sts) => $sts ? array_sum(array_map(fn($s) => (int)($counts[$s] ?? 0), $sts)) : array_sum(array_map('intval', $counts));
$listWhere = $where;
if ($groups[$tab][1]) {
    [$in, $ip] = DB::in('cs', $groups[$tab][1]);
    $listWhere .= " AND c.status IN $in";
    $par += $ip;
}
$rows = DB::all("SELECT c.*, v.voyage_no, v.etd, r.code AS route_code FROM shp_cargo c JOIN shp_routes r ON r.id = c.route_id LEFT JOIN shp_voyages v ON v.id = c.voyage_id
                  WHERE $listWhere ORDER BY COALESCE(v.etd, c.created_at) DESC, c.id DESC LIMIT 400", $par);
$week = DB::row("SELECT COUNT(*) n, COALESCE(SUM(c.weight_kg), 0) kg FROM shp_cargo c JOIN shp_voyages v ON v.id = c.voyage_id
                  WHERE c.status IN ('booked', 'accepted', 'rolled_over') AND v.etd BETWEEN :a AND :b", ['a' => Ship::now(), 'b' => date('Y-m-d 23:59:59', strtotime(Ship::today() . ' +7 days'))]);
$unpaid = DB::row("SELECT COUNT(*) n, COALESCE(SUM(total_amount), 0) amt FROM shp_cargo WHERE payment_terms = 'prepaid' AND paid_txn_id IS NULL AND status IN ('quotation', 'booked', 'accepted')");
$layoutActions = ($canCreate ? btn_link(url($page, ['a' => 'new']), 'New shipment', 'btn-primary', 'plus-lg') . ' ' : '')
    . (Access::page('rpt.shp_cargo_volume') ? btn_link(url('rpt.shp_cargo_volume'), 'Tonnage report', 'btn-outline-secondary', 'bar-chart') : '');
layout_start('Cargo Shipments & Waybills', ['actions' => $layoutActions]);
echo '<div class="kpi-grid mb-3">'
    . kpi_tile('Booked, next 7 days', (string)(int)$week['n'], 'calendar-week', number_format((float)$week['kg'] / 1000, 1) . ' t waiting to load', '', null, (float)$week['n'])
    . kpi_tile('On board', (string)$n($groups['aboard'][1]), 'water', 'Loaded, in transit or landed', '', url($page, ['tab' => 'aboard']), (float)$n($groups['aboard'][1]))
    . kpi_tile('Exceptions open', (string)$n($groups['exceptions'][1]), 'exclamation-triangle', 'Short-landed, damaged or claimed', $n($groups['exceptions'][1]) ? 'bad' : 'good', url($page, ['tab' => 'exceptions']), (float)$n($groups['exceptions'][1]))
    . kpi_tile('Prepaid, not paid yet', (string)(int)$unpaid['n'], 'cash-coin', '₱' . money($unpaid['amt']) . ' to collect', (int)$unpaid['n'] ? 'warn' : '', null, (float)$unpaid['n'])
    . '</div>';
$tabs = [];
foreach ($groups as $k => [$label, $sts]) {
    $tabs[$k] = [$label, url($page, array_filter(['tab' => $k, 'q' => $q, 'route' => $route, 'voyage' => $voyage, 'from' => $from, 'to' => $to])), $n($sts)];
}
?>
<form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
  <?= input_field('q', 'Waybill, shipper, consignee, goods', $q, ['class' => '']) ?>
  <?= select_field('route', 'Route', Ship::routeOptions(false), $route, ['class' => '', 'blank' => 'All routes']) ?>
  <?= input_field('from', 'Entered from', $from, ['type' => 'date', 'class' => '']) ?><?= input_field('to', 'to', $to, ['type' => 'date', 'class' => '']) ?>
  <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Show</button></div>
</form>
<?= tabs_line($tabs, $tab) ?>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>Waybill</th><th>Voyage</th><th>Shipper</th><th>Consignee</th><th>Goods</th><th class="num">Pcs</th><th class="num">Weight</th><th>Terms</th><th class="num">Total</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="fw-semibold"><a href="<?= h(url($page, ['id' => $r['id']])) ?>"><?= h($r['waybill_no']) ?></a></td>
      <td class="small"><?= $r['voyage_no'] ? '<span class="text-nowrap">' . h($r['voyage_no']) . '</span><div class="text-muted">' . h(date('M j g:i A', strtotime($r['etd']))) . '</div>' : '<span class="text-muted">' . h($r['route_code']) . ', not assigned</span>' ?></td>
      <td><?= h($r['shipper_name']) ?></td><td><?= h($r['consignee_name']) ?></td><td class="small"><?= h(mb_strimwidth((string)$r['description'], 0, 48, '...')) ?></td>
      <td class="num"><?= (int)$r['packages'] ?></td><td class="num"><?= qty($r['weight_kg']) ?> kg</td>
      <td class="small"><?= $r['payment_terms'] === 'credit' ? 'Credit' : ($r['paid_txn_id'] ? 'Prepaid, paid' : '<span class="text-danger">Prepaid, unpaid</span>') ?></td>
      <td class="num"><?= money($r['total_amount']) ?></td><td><?= Ship::label($r['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="10"><?= empty_note('No shipment here.', 'box-seam') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php
layout_end();
