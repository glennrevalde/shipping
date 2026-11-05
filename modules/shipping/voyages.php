<?php
declare(strict_types=1);

$page = 'ops.voyages';
$id = gint('id');
$tab = (string)g('tab', 'overview');
$v = $id ? Ship::voyage($id) : null;

handle_post(function () use ($page, $v) {
    $act = (string)p('act');
    if ($act === 'create') {
        ship_require($page, 'create');
        $nid = Voyages::create(['route_id' => pint('route_id'), 'vessel_id' => pint('vessel_id'), 'etd' => p('etd'), 'eta' => p('eta'), 'status' => p('status', 'published'),
            'berth' => p('berth') ?: null, 'pax_quota' => p('pax_quota'), 'boarding_open_min' => p('boarding_open_min', '60')]);
        flash('success', 'Voyage scheduled.');
        redirect(url($page, ['id' => $nid]));
    }
    if (!$v) {
        fail('Voyage not found.');
    }
    $back = url($page, ['id' => $v['id'], 'tab' => p('tab', 'overview')]);
    switch ($act) {
        case 'status':
            ship_require($page, 'edit');
            $to = (string)p('to');
            Voyages::transition((int)$v['id'], $to, ['version' => pint('version'), 'note' => p('note'), 'reason' => p('reason'), 'authority' => p('authority'),
                'delay_cause' => p('delay_cause'), 'fuel_rob_departure' => p('fuel_rob_departure'), 'fuel_rob_arrival' => p('fuel_rob_arrival')]);
            flash('success', 'Voyage ' . $v['voyage_no'] . ' is now ' . strtolower(strip_tags(Ship::label($to))) . '.');
            break;
        case 'reschedule':
            ship_require($page, 'edit');
            Voyages::reschedule((int)$v['id'], (string)p('new_etd'), (string)p('cause'));
            flash('success', 'Departure time changed; the passengers booked were sent a notice.');
            break;
        case 'readiness':
            ship_require($page, 'edit');
            DB::tx(function () use ($v) {
                foreach (DB::all('SELECT * FROM shp_voyage_checklist WHERE voyage_id = :v', ['v' => $v['id']]) as $c) {
                    $done = in_array($c['item_key'], (array)($_POST['done'] ?? []), true) ? 1 : 0;
                    if ($done !== (int)$c['is_done']) {
                        DB::update('shp_voyage_checklist', ['is_done' => $done, 'done_by' => $done ? Auth::id() : null, 'done_at' => $done ? Ship::now() : null], 'id = :id', ['id' => $c['id']]);
                    }
                }
                DB::update('shp_voyages', ['weather_ack' => p('weather_ack') === '1' ? 1 : 0, 'weather_note' => p('weather_note') ?: null, 'clearance_ref' => p('clearance_ref') ?: null,
                    'berth' => p('berth') ?: null], 'id = :id', ['id' => $v['id']]);
                Ship::voyageEvent((int)$v['id'], 'note', 'Readiness checklist updated by ' . (Auth::user()['full_name'] ?? ''));
            });
            flash('success', 'Readiness saved.');
            break;
        case 'crew':
            ship_require($page, 'edit');
            DB::tx(function () use ($v) {
                $on = array_map('intval', (array)($_POST['crew'] ?? []));
                DB::run('DELETE FROM shp_voyage_crew WHERE voyage_id = :v', ['v' => $v['id']]);
                foreach ($on as $cid) {
                    DB::insert('shp_voyage_crew', ['voyage_id' => $v['id'], 'crew_id' => $cid, 'position' => DB::value('SELECT rank_title FROM shp_crew WHERE id = :c', ['c' => $cid])]);
                }
                Ship::voyageEvent((int)$v['id'], 'note', count($on) . ' crew signed on');
            });
            flash('success', 'Crew list saved.');
            break;
        case 'override':
            ship_require($page, 'edit');
            Voyages::requestOverride((int)$v['id'], (string)p('evidence'));
            flash('info', 'Departure override requested. Another person with the override permission decides it in the Approvals Queue.');
            break;
        case 'report':
            ship_require($page, 'edit');
            Voyages::signReport((int)$v['id'], (string)p('report_text'), ['distance_nm' => p('distance_nm'), 'weather_note' => p('weather_note')]);
            flash('success', 'Voyage report signed.');
            break;
        case 'event':
            ship_require($page, 'edit');
            if (trim((string)p('details')) === '') {
                fail('Write what happened.');
            }
            Ship::voyageEvent((int)$v['id'], (string)p('event_type', 'note'), (string)p('details'), null, null, p('event_at') ? date('Y-m-d H:i:s', strtotime((string)p('event_at'))) : null);
            flash('success', 'Logged.');
            break;
        case 'cost':
            ship_require($page, 'edit');
            $amount = pnum('amount');
            $acctId = pint('account_id');
            $vendor = pint('vendor_id');
            $mode = p('pay_mode') === 'check' ? 'check' : 'bill';
            if ($amount <= 0 || !$acctId || trim((string)p('description')) === '') {
                fail('Enter the description, the account and the amount of the cost.');
            }
            if ($mode === 'bill' && !$vendor) {
                fail('A cost that is billed needs the supplier.');
            }
            $vat = p('vatable') === '1' ? r2($amount * ShipGL::vatRate() / 100) : 0.0;
            DB::tx(function () use ($v, $amount, $acctId, $vendor, $mode, $vat) {
                $cid = DB::insert('shp_voyage_costs', ['voyage_id' => $v['id'], 'cost_type' => p('cost_type', 'other'), 'description' => mb_substr((string)p('description'), 0, 160),
                    'vendor_id' => $vendor, 'account_id' => $acctId, 'amount' => $amount, 'tax_code_id' => ShipGL::taxCode($vat > 0 ? 'VAT12' : 'NT'), 'tax_amount' => $vat,
                    'pay_mode' => $mode, 'bank_account_id' => $mode === 'check' ? pint('bank_account_id') : null, 'ref_no' => p('ref_no') ?: null, 'created_by' => Auth::id()]);
                $class = ShipGL::vesselClass((int)$v['vessel_id']);
                $line = [['account_id' => $acctId, 'amount' => $amount, 'tax_code_id' => ShipGL::taxCode($vat > 0 ? 'VAT12' : 'NT'), 'tax_amount' => $vat, 'description' => p('description') . ', ' . $v['voyage_no']]];
                $memo = p('description') . ', ' . $v['voyage_no'];
                $txn = ShipGL::once('cost:' . $cid, ['source_type' => 'cost', 'source_id' => $cid, 'voyage_id' => $v['id'], 'vessel_id' => $v['vessel_id'], 'memo' => $memo],
                    fn() => $mode === 'bill' ? ShipGL::bill($vendor, Ship::today(), p('ref_no') ?: null, $line, $memo, $class)
                        : ShipGL::check((int)pint('bank_account_id'), $vendor ?: null, $vendor ? null : (string)p('payee'), Ship::today(), p('ref_no') ?: null, $line, $memo, $class));
                DB::update('shp_voyage_costs', ['txn_id' => $txn], 'id = :id', ['id' => $cid]);
            });
            flash('success', 'Cost recorded in the books for this voyage.');
            break;
        default:
            fail('Unknown action.');
    }
    redirect($back);
});

// ------------------------------------------------------------------ one voyage
if ($v) {
    Booking::expireHolds((int)$v['id']);
    $c = Boarding::counts((int)$v['id']);
    $cap = Booking::capacity($v);
    $limit = Booking::paxLimit($v);
    $canEdit = Access::can($page, 'edit');
    $next = Ship::VOYAGE_FLOW[$v['status']] ?? [];
    $actions = '';
    if (Access::page('tkt.booking') && in_array($v['status'], ['on_sale', 'boarding'], true)) {
        $actions .= btn_link(url('tkt.booking', ['voyage' => $v['id']]), 'Book', 'btn-primary', 'ticket-perforated') . ' ';
    }
    if (Access::page('tkt.boarding') && in_array($v['status'], ['boarding', 'ready'], true)) {
        $actions .= btn_link(url('tkt.boarding', ['voyage' => $v['id']]), 'Boarding scanner', 'btn-outline-primary', 'qr-code-scan') . ' ';
    }
    if (Access::page('tkt.manifest')) {
        $actions .= btn_link(url('tkt.manifest', ['voyage' => $v['id']]), 'Manifest', 'btn-outline-secondary', 'card-checklist');
    }
    layout_start('Voyage ' . $v['voyage_no'], ['actions' => $actions]);
    $tabs = ['overview' => ['Overview', url($page, ['id' => $v['id']]), null], 'readiness' => ['Departure readiness', url($page, ['id' => $v['id'], 'tab' => 'readiness']), null],
        'loads' => ['Vehicles and cargo', url($page, ['id' => $v['id'], 'tab' => 'loads']), $c['vehicles_booked'] + (int)DB::value("SELECT COUNT(*) FROM shp_cargo WHERE voyage_id = :v AND status <> 'cancelled'", ['v' => $v['id']])],
        'costs' => ['Fuel, costs and P&L', url($page, ['id' => $v['id'], 'tab' => 'costs']), null], 'log' => ['Log and report', url($page, ['id' => $v['id'], 'tab' => 'log']), null]];
    echo voyage_card($v + ['vessel_name' => $v['vessel_name']], null, '<div class="small text-muted">' . h($v['route_name']) . '</div><div class="small">Version ' . (int)$v['version'] . '</div>');
    echo '<div class="mt-2 mb-3">' . flow_steps(['published' => 'Published', 'on_sale' => 'On sale', 'boarding' => 'Boarding', 'ready' => 'Ready', 'departed' => 'Departed', 'arrived' => 'Arrived', 'completed' => 'Completed'], $v['status'] === 'draft' ? 'published' : $v['status']) . '</div>';
    echo tabs_line($tabs, $tab);

    if ($tab === 'overview') {
        echo '<div class="kpi-grid mb-3">'
            . kpi_tile('Paid passengers', (string)$c['paid'], 'people', 'Limit ' . $limit . ' (' . (int)$c['held'] . ' held unpaid)', '', null, (float)$c['paid'])
            . kpi_tile('Checked in', (string)$c['checked_in'], 'person-check', 'Scanned at check-in or boarding', '', null, (float)$c['checked_in'])
            . kpi_tile('Boarded', (string)$c['boarded'], 'box-arrow-in-right', 'From validated scans only', $c['boarded'] > $limit ? 'bad' : 'good', Access::page('tkt.boarding') ? url('tkt.boarding', ['voyage' => $v['id']]) : null, (float)$c['boarded'])
            . kpi_tile('No-shows', (string)$c['no_show'], 'person-dash', $c['cancelled'] . ' cancelled or moved', $c['no_show'] ? 'warn' : '', null, (float)$c['no_show'])
            . kpi_tile('Vehicles', (string)$c['vehicles_booked'], 'truck-front', $c['vehicles_loaded'] . ' loaded, ' . qty(Booking::lanesUsed((int)$v['id'])) . ' of ' . qty(Booking::laneLimit($v)) . ' lane m', '', url($page, ['id' => $v['id'], 'tab' => 'loads']))
            . kpi_tile('Rejected scans', (string)$c['rejected_scans'], 'shield-x', $c['duplicate_scans'] . ' duplicate', $c['rejected_scans'] ? 'warn' : '', Access::page('tkt.boarding') ? url('tkt.boarding', ['voyage' => $v['id'], 'log' => 1]) : null)
            . '</div>';
        ?>
        <div class="row g-3">
          <div class="col-lg-6"><div class="card reveal h-100"><div class="card-header">Seats and places by class</div><div class="card-body">
            <?php foreach ($cap as $cl): $pct = $cl['capacity'] ? 100 * $cl['taken'] / $cl['capacity'] : 0; ?>
              <div class="mb-2"><div class="d-flex justify-content-between small"><span class="fw-semibold"><?= h($cl['name']) ?> (<?= h($cl['code']) ?>)</span><span><?= (int)$cl['taken'] ?> of <?= (int)$cl['capacity'] ?>, <?= (int)$cl['left'] ?> left</span></div><?= meter($pct, $pct > 95 ? 'bad' : ($pct > 80 ? 'warn' : '')) ?></div>
            <?php endforeach; ?>
            <div class="info-grid mt-3">
              <?= info_pair('Departure (ETD)', fdatetime($v['etd'])) ?><?= info_pair('Arrival (ETA)', fdatetime($v['eta'])) ?>
              <?= info_pair('Actual departure', $v['atd'] ? fdatetime($v['atd']) : '-') ?><?= info_pair('Actual arrival', $v['ata'] ? fdatetime($v['ata']) : '-') ?>
              <?= info_pair('Delay', (int)$v['delay_minutes'] ? (int)$v['delay_minutes'] . ' min' . ($v['delay_cause'] ? ', ' . $v['delay_cause'] : '') : 'On time') ?>
              <?= info_pair('Sales close', fdatetime($v['booking_close'])) ?><?= info_pair('Boarding opens', (int)$v['boarding_open_min'] . ' min before') ?><?= info_pair('Berth', $v['berth'] ?: '-') ?>
              <?= $v['status'] === 'cancelled' ? info_pair('Cancelled', $v['cancel_reason'] . ' (' . $v['cancel_authority'] . ')') : '' ?>
            </div>
          </div></div></div>
          <div class="col-lg-6"><div class="card reveal h-100"><div class="card-header">Change the status</div><div class="card-body">
          <?php if (!$canEdit): ?><p class="small text-muted mb-0">Your role can see the voyage but not change it.</p>
          <?php elseif (!$next): ?><p class="small text-muted mb-0">This voyage is <?= strtolower(strip_tags(Ship::label($v['status']))) ?>; nothing more to do here.</p>
          <?php else: ?>
            <?php foreach ($next as $to): if ($to === 'cancelled') { continue; } ?>
              <form method="post" class="mb-2 p-2 border rounded-3"><?= csrf_field() ?><input type="hidden" name="act" value="status"><input type="hidden" name="to" value="<?= h($to) ?>"><input type="hidden" name="version" value="<?= (int)$v['version'] ?>">
                <div class="d-flex flex-wrap gap-2 align-items-end">
                <?php if ($to === 'departed'): ?>
                  <div><label class="form-label">Fuel ROB at departure (L)</label><input class="form-control form-control-sm" type="number" step="0.01" name="fuel_rob_departure" value="<?= h((string)round(Fuel::rob((int)$v['vessel_id']))) ?>"></div>
                  <div class="flex-fill"><label class="form-label">Delay cause (if late)</label><input class="form-control form-control-sm" name="delay_cause"></div>
                <?php elseif ($to === 'arrived'): ?>
                  <div><label class="form-label">Fuel ROB at arrival (L)</label><input class="form-control form-control-sm" type="number" step="0.01" name="fuel_rob_arrival"></div>
                <?php else: ?>
                  <div class="flex-fill"><label class="form-label">Note (optional)</label><input class="form-control form-control-sm" name="note"></div>
                <?php endif; ?>
                <button class="btn btn-sm <?= in_array($to, ['ready', 'departed', 'completed'], true) ? 'btn-primary' : 'btn-outline-primary' ?>"><?= h(['published' => 'Publish', 'on_sale' => 'Put on sale', 'boarding' => 'Open boarding', 'ready' => 'Release for departure', 'departed' => 'Record departure', 'arrived' => 'Record arrival', 'completed' => 'Complete the voyage', 'draft' => 'Back to draft'][$to] ?? $to) ?></button>
                </div></form>
            <?php endforeach; ?>
            <?php if (in_array('cancelled', $next, true)): ?>
              <details class="mt-2"><summary class="small text-danger">Cancel or reschedule this voyage</summary>
                <form method="post" class="mt-2 row g-2"><?= csrf_field() ?><input type="hidden" name="act" value="reschedule">
                  <?= input_field('new_etd', 'New departure', date('Y-m-d\TH:i', strtotime($v['etd'])), ['type' => 'datetime-local', 'class' => 'col-md-5']) ?>
                  <?= input_field('cause', 'Cause (told to the passengers)', '', ['class' => 'col-md-5']) ?><div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-outline-warning w-100">Reschedule</button></div></form>
                <form method="post" class="mt-2 row g-2"><?= csrf_field() ?><input type="hidden" name="act" value="status"><input type="hidden" name="to" value="cancelled"><input type="hidden" name="version" value="<?= (int)$v['version'] ?>">
                  <?= input_field('reason', 'Reason', '', ['class' => 'col-md-5', 'required' => true]) ?><?= input_field('authority', 'Authority (PCG advisory no., management)', '', ['class' => 'col-md-5', 'required' => true]) ?>
                  <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-danger w-100" data-confirm="Cancel this voyage? Sales stop and every passenger is told.">Cancel voyage</button></div></form>
              </details>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($v['status'] === 'cancelled' && Access::page('tkt.refunds')): ?>
            <a class="btn btn-sm btn-outline-primary mt-2" href="<?= h(url('tkt.refunds', ['tab' => 'affected', 'voyage' => $v['id']])) ?>"><i class="bi bi-arrow-repeat"></i> Refund or move the affected passengers</a>
          <?php endif; ?>
          </div></div></div>
        </div>
        <?php
    } elseif ($tab === 'readiness') {
        $checks = Voyages::readiness((int)$v['id']);
        $list = DB::all('SELECT * FROM shp_voyage_checklist WHERE voyage_id = :v ORDER BY id', ['v' => $v['id']]);
        $crew = DB::all("SELECT c.*, vc.id AS on_id FROM shp_crew c LEFT JOIN shp_voyage_crew vc ON vc.crew_id = c.id AND vc.voyage_id = :v WHERE c.vessel_id = :vs AND c.status = 'active' ORDER BY FIELD(c.department, 'deck', 'engine', 'hotel'), c.id", ['v' => $v['id'], 'vs' => $v['vessel_id']]);
        ?>
        <div class="row g-3">
          <div class="col-lg-6"><div class="card reveal"><div class="card-header">Departure checks (worked out from the records)</div><div class="card-body"><ul class="check-list">
          <?php foreach ($checks as $k => [$label, $ok, $detail, $overridable]): ?>
            <li><span class="ck <?= $ok ? 'ok' : ($overridable ? 'soft' : 'no') ?>"><i class="bi bi-<?= $ok ? 'check-lg' : 'x-lg' ?>"></i></span><div><?= h($label) ?><div class="detail"><?= h($detail) ?><?= !$ok && !$overridable ? ' (cannot be overridden)' : '' ?></div></div></li>
          <?php endforeach; ?>
          </ul>
          <?php if ($v['override_by']): ?><div class="alert alert-warning small mt-2 mb-0">Departure override approved by <?= h(Ship::userName((int)$v['override_by'])) ?>: <?= h((string)$v['override_reason']) ?></div>
          <?php elseif ($canEdit && in_array($v['status'], ['boarding', 'on_sale'], true)): ?>
            <details class="mt-2"><summary class="small">Request a departure override</summary><form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="act" value="override"><input type="hidden" name="tab" value="readiness">
              <label class="form-label">Lawful basis and evidence</label><textarea class="form-control form-control-sm" name="evidence" rows="2" required></textarea>
              <div class="form-text">Only the checks marked in orange may be overridden, and only by another person with the override permission. A no-sail hold or a boarded count above the limit never can.</div>
              <button class="btn btn-sm btn-outline-warning mt-2">Request override</button></form></details>
          <?php endif; ?>
          </div></div></div>
          <div class="col-lg-6">
            <form method="post" class="card reveal mb-3"><?= csrf_field() ?><input type="hidden" name="act" value="readiness"><input type="hidden" name="tab" value="readiness">
              <div class="card-header">Checklist, weather and clearance</div><div class="card-body"><fieldset <?= $canEdit ? '' : 'disabled' ?>>
              <?php foreach ($list as $it): ?>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="done[]" value="<?= h($it['item_key']) ?>" id="ck_<?= h($it['item_key']) ?>" <?= checked($it['is_done']) ?>>
                  <label class="form-check-label small" for="ck_<?= h($it['item_key']) ?>"><?= h($it['label']) ?><?= $it['done_at'] ? ' <span class="text-muted">(' . h(Ship::userName((int)$it['done_by']) . ', ' . fdatetime($it['done_at'])) . ')</span>' : '' ?></label></div>
              <?php endforeach; ?>
              <div class="row g-2 mt-2">
                <?= check_field('weather_ack', 'Weather advisory acknowledged', (int)$v['weather_ack'] === 1, 'col-12') ?>
                <?= input_field('weather_note', 'Advisory / sea condition', $v['weather_note'], ['class' => 'col-md-7']) ?>
                <?= input_field('clearance_ref', 'PCG / port clearance reference', $v['clearance_ref'], ['class' => 'col-md-5']) ?>
                <?= input_field('berth', 'Berth', $v['berth'], ['class' => 'col-md-4']) ?>
              </div>
              <?php if ($canEdit): ?><button class="btn btn-primary btn-sm mt-3">Save readiness</button><?php endif; ?>
              </fieldset></div></form>
            <form method="post" class="card reveal"><?= csrf_field() ?><input type="hidden" name="act" value="crew"><input type="hidden" name="tab" value="readiness">
              <div class="card-header d-flex justify-content-between"><span>Crew signed on</span><span class="small text-muted"><?= count(array_filter($crew, fn($x) => $x['on_id'])) ?> of complement <?= (int)DB::value('SELECT crew_complement FROM shp_vessels WHERE id = :v', ['v' => $v['vessel_id']]) ?></span></div>
              <div class="card-body" style="max-height:360px;overflow:auto"><fieldset <?= $canEdit ? '' : 'disabled' ?>>
              <?php foreach ($crew as $cr): ?>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="crew[]" value="<?= (int)$cr['id'] ?>" id="cr<?= (int)$cr['id'] ?>" <?= checked($cr['on_id']) ?>>
                  <label class="form-check-label small" for="cr<?= (int)$cr['id'] ?>"><?= h($cr['rank_title']) ?>: <?= h($cr['last_name'] . ', ' . $cr['first_name']) ?></label></div>
              <?php endforeach; ?>
              </fieldset></div>
              <?php if ($canEdit): ?><div class="card-footer bg-transparent"><button class="btn btn-outline-primary btn-sm">Save crew list</button> <button type="button" class="btn btn-link btn-sm" onclick="this.closest('form').querySelectorAll('input[type=checkbox]').forEach(function(c){c.checked=true})">Tick all</button></div><?php endif; ?>
            </form>
          </div>
        </div>
        <?php
    } elseif ($tab === 'loads') {
        $veh = DB::all('SELECT x.*, k.name kname, k.lane_m, t.last_name, t.first_name FROM shp_vehicles x JOIN shp_vehicle_classes k ON k.id = x.vehicle_class_id LEFT JOIN shp_tickets t ON t.id = x.driver_ticket_id WHERE x.voyage_id = :v ORDER BY x.lane_no, x.lane_position, x.id', ['v' => $v['id']]);
        $cargo = DB::all("SELECT * FROM shp_cargo WHERE voyage_id = :v AND status <> 'cancelled' ORDER BY id", ['v' => $v['id']]);
        ?>
        <div class="card reveal mb-3"><div class="card-header d-flex justify-content-between"><span>Vehicles (<?= count($veh) ?>)</span><?php if (Access::page('cgo.vehicles')): ?><a class="small" href="<?= h(url('cgo.vehicles', ['voyage' => $v['id']])) ?>">Loading list and lanes</a><?php endif; ?></div>
        <div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Pass</th><th>Plate</th><th>Class</th><th class="num">Length</th><th class="num">Weight</th><th>Driver</th><th>Lane</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($veh as $x): ?><tr><td><?= h($x['vehicle_no'] ?: '(unpaid)') ?></td><td class="fw-semibold"><?= h($x['plate_no']) ?></td><td><?= h($x['kname']) ?></td><td class="num"><?= qty($x['length_m']) ?> m</td><td class="num"><?= qty($x['weight_t']) ?> t</td>
          <td><?= h($x['last_name'] ? $x['last_name'] . ', ' . $x['first_name'] : '') ?></td><td><?= h(trim(($x['deck'] ?? '') . ' ' . ($x['lane_no'] ? 'L' . $x['lane_no'] . '/' . $x['lane_position'] : ''))) ?></td><td><?= Ship::label($x['status']) ?></td></tr><?php endforeach; ?>
        <?php if (!$veh): ?><tr><td colspan="8"><?= empty_note('No vehicle booked.') ?></td></tr><?php endif; ?>
        </tbody></table></div></div>
        <div class="card reveal"><div class="card-header">Cargo (<?= count($cargo) ?>), <?= qty(array_sum(array_column($cargo, 'weight_kg')) / 1000) ?> t of <?= qty($v['cargo_capacity_t']) ?> t</div>
        <div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Waybill</th><th>Shipper</th><th>Consignee</th><th>Goods</th><th class="num">Packages</th><th class="num">Weight</th><th>Terms</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($cargo as $g): ?><tr><td><?= Access::page('cgo.shipments') ? '<a href="' . h(url('cgo.shipments', ['id' => $g['id']])) . '">' . h($g['waybill_no']) . '</a>' : h($g['waybill_no']) ?></td><td><?= h($g['shipper_name']) ?></td><td><?= h($g['consignee_name']) ?></td>
          <td><?= h($g['description']) ?></td><td class="num"><?= (int)$g['packages'] ?></td><td class="num"><?= qty($g['weight_kg']) ?> kg</td><td><?= h(ucfirst($g['payment_terms'])) ?></td><td><?= Ship::label($g['status']) ?></td></tr><?php endforeach; ?>
        <?php if (!$cargo): ?><tr><td colspan="8"><?= empty_note('No cargo on this voyage.') ?></td></tr><?php endif; ?>
        </tbody></table></div></div>
        <?php
    } elseif ($tab === 'costs') {
        $pnl = Voyages::pnl((int)$v['id']);
        $fuel = DB::row("SELECT * FROM shp_fuel_logs WHERE voyage_id = :v AND log_type = 'issue' ORDER BY id DESC LIMIT 1", ['v' => $v['id']]);
        $costs = DB::all('SELECT c.*, vd.name vendor FROM shp_voyage_costs c LEFT JOIN vendors vd ON vd.id = c.vendor_id WHERE c.voyage_id = :v ORDER BY c.id', ['v' => $v['id']]);
        echo '<div class="kpi-grid mb-3">'
            . kpi_tile('Revenue recognized', '₱' . money($pnl['revenue']), 'graph-up-arrow', $pnl['still_deferred'] > 0 ? '₱' . money($pnl['still_deferred']) . ' still deferred' : 'All earned', 'good', null, $pnl['revenue'])
            . kpi_tile('Direct voyage costs', '₱' . money($pnl['direct_cost']), 'receipt', 'Fuel, port, pilotage, crew, repairs', '', null, $pnl['direct_cost'])
            . kpi_tile('Gross contribution', '₱' . money($pnl['contribution']), 'calculator', $pnl['revenue'] > 0 ? round(100 * $pnl['contribution'] / $pnl['revenue'], 1) . '% of revenue' : '', $pnl['contribution'] < 0 ? 'bad' : 'good', Access::page('rpt.shp_voyage_pnl') ? url('rpt.shp_voyage_pnl', ['voyage' => $v['id'], 'dates' => 'all']) : null, $pnl['contribution'])
            . kpi_tile('Fuel used', $fuel ? number_format(-(float)$fuel['liters']) . ' L' : 'Not recorded', 'fuel-pump', $fuel ? '₱' . money($fuel['amount']) . ($fuel['variance_l'] !== null ? ', variance ' . qty($fuel['variance_l']) . ' L' : '') : 'Record it on Fuel & Bunkering', $fuel ? '' : 'warn', Access::page('ops.fuel') ? url('ops.fuel', ['voyage' => $v['id']]) : null)
            . '</div>';
        ?>
        <div class="row g-3">
          <div class="col-lg-6"><div class="card reveal h-100"><div class="card-header">Profit and loss from the ledger</div><div class="table-responsive"><table class="table table-sm mb-0">
            <tbody><?php foreach ($pnl['income'] as $r): ?><tr><td><?= h($r['code'] . ' ' . $r['name']) ?></td><td class="num"><?= money($r['net']) ?></td></tr><?php endforeach; ?>
            <tr class="grid-totals"><td class="fw-semibold">Revenue</td><td class="num fw-semibold"><?= money($pnl['revenue']) ?></td></tr>
            <?php foreach ($pnl['expense'] as $r): ?><tr><td><?= h($r['code'] . ' ' . $r['name']) ?></td><td class="num"><?= money(-(float)$r['net']) ?></td></tr><?php endforeach; ?>
            <tr class="grid-totals"><td class="fw-semibold">Gross contribution</td><td class="num fw-semibold"><?= money($pnl['contribution']) ?></td></tr></tbody></table></div>
            <div class="card-body small text-muted">Figures come from the journal lines of the documents linked to this voyage. The full-cost margin (overheads allocated by sailing hours) is on the Voyage Profit & Loss report.</div></div></div>
          <div class="col-lg-6"><div class="card reveal mb-3"><div class="card-header">Voyage costs</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Cost</th><th>Supplier</th><th>Document</th><th class="num">Amount</th></tr></thead><tbody>
            <?php foreach ($costs as $k): ?><tr><td><?= h($k['description']) ?></td><td><?= h($k['vendor'] ?? '') ?></td><td><?= txn_doc_link($k['txn_id'] ? (int)$k['txn_id'] : null) ?></td><td class="num"><?= money((float)$k['amount'] + (float)$k['tax_amount']) ?></td></tr><?php endforeach; ?>
            <?php if (!$costs): ?><tr><td colspan="4"><?= empty_note('No cost recorded.') ?></td></tr><?php endif; ?></tbody></table></div></div>
          <?php if ($canEdit): ?>
            <form method="post" class="card reveal"><?= csrf_field() ?><input type="hidden" name="act" value="cost"><input type="hidden" name="tab" value="costs"><div class="card-header">Add a voyage cost (posted to the books with the vessel's class)</div><div class="card-body"><div class="row g-2">
              <?= select_field('cost_type', 'Kind', ['port_charges' => 'Port charges', 'pilotage' => 'Pilotage', 'towing' => 'Tug / towing', 'crew_allowance' => 'Crew allowance', 'stores' => 'Ship stores', 'water' => 'Fresh water', 'commission' => 'Agent commission', 'other' => 'Other'], 'port_charges', ['class' => 'col-md-4']) ?>
              <?= input_field('description', 'Description', '', ['class' => 'col-md-8', 'required' => true]) ?>
              <?= select_field('account_id', 'Expense account', DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM accounts WHERE is_active = 1 AND category = 'Expense' ORDER BY code"), Accts::get('shp_port_charges'), ['class' => 'col-md-6']) ?>
              <?= input_field('amount', 'Amount (before VAT)', '', ['type' => 'number', 'class' => 'col-md-3', 'required' => true]) ?><?= check_field('vatable', 'Plus 12% VAT', false, 'col-md-3') ?>
              <?= select_field('pay_mode', 'Recorded as', ['bill' => 'Bill to the supplier (A/P)', 'check' => 'Check / cash paid now'], 'bill', ['class' => 'col-md-4']) ?>
              <?= select_field('vendor_id', 'Supplier', DB::pairs('SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name'), '', ['class' => 'col-md-4', 'blank' => '']) ?>
              <?= select_field('bank_account_id', 'Paid from (check)', Accts::banks(), '', ['class' => 'col-md-4', 'blank' => '']) ?>
              <?= input_field('ref_no', 'Supplier invoice / OR no.', '', ['class' => 'col-md-4']) ?><?= input_field('payee', 'Payee (check without a supplier)', '', ['class' => 'col-md-8']) ?>
            </div><button class="btn btn-primary btn-sm mt-3">Record cost</button></div></form>
          <?php endif; ?>
          </div>
          <div class="col-12"><div class="card reveal"><div class="card-header">Every accounting document of this voyage</div><?= ship_documents_table(ShipGL::documentsWhere('voyage_id', (int)$v['id'])) ?></div></div>
        </div>
        <?php
    } else {
        $events = DB::all('SELECT e.*, u.full_name FROM shp_voyage_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.voyage_id = :v ORDER BY e.id DESC', ['v' => $v['id']]);
        ?>
        <div class="row g-3">
          <div class="col-lg-6"><div class="card reveal"><div class="card-header">Voyage log</div><div class="card-body"><ul class="timeline">
            <?php foreach ($events as $e): ?><li class="<?= in_array($e['event_type'], ['hold', 'incident', 'override'], true) ? 't-bad' : ($e['event_type'] === 'delay' ? 't-warn' : '') ?>">
              <div class="when"><?= h(fdatetime($e['event_at'] ?: $e['created_at'])) ?>, <?= h($e['full_name'] ?? 'system') ?></div>
              <div><?= $e['to_status'] ? Ship::label($e['to_status']) . ' ' : '' ?><?= h((string)$e['details']) ?></div></li><?php endforeach; ?>
          </ul>
          <?php if ($canEdit): ?>
            <form method="post" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="act" value="event"><input type="hidden" name="tab" value="log">
              <?= select_field('event_type', 'Kind', ['note' => 'Note', 'port_call' => 'Port call', 'incident' => 'Incident', 'delay' => 'Delay', 'notice' => 'Notice'], 'note', ['class' => 'col-md-3']) ?>
              <?= input_field('event_at', 'When', date('Y-m-d\TH:i'), ['type' => 'datetime-local', 'class' => 'col-md-4']) ?><?= input_field('details', 'What happened', '', ['class' => 'col-md-5']) ?>
              <div class="col-12"><button class="btn btn-sm btn-outline-primary">Add to the log</button></div></form>
          <?php endif; ?>
          </div></div></div>
          <div class="col-lg-6"><div class="card reveal"><div class="card-header">Voyage report</div><div class="card-body">
          <?php if ($v['report_signed_at']): ?>
            <p class="small" style="white-space:pre-line"><?= h((string)$v['report_text']) ?></p>
            <p class="small text-muted mb-0">Signed by <?= h(Ship::userName((int)$v['report_signed_by'])) ?>, <?= h(fdatetime($v['report_signed_at'])) ?>. Sailing <?= qty($v['sailing_hours']) ?> h, <?= qty($v['distance_nm']) ?> nm.</p>
          <?php elseif ($canEdit && $v['status'] === 'arrived'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="report"><input type="hidden" name="tab" value="log">
              <textarea class="form-control form-control-sm mb-2" name="report_text" rows="6" required placeholder="Departure, passage, weather, incidents, port calls, discharge"></textarea>
              <div class="row g-2"><?= input_field('distance_nm', 'Distance sailed (nm)', $v['route_nm'], ['type' => 'number', 'class' => 'col-md-6']) ?><?= input_field('weather_note', 'Weather on passage', $v['weather_note'], ['class' => 'col-md-6']) ?></div>
              <button class="btn btn-primary btn-sm mt-2">Sign the voyage report</button></form>
          <?php else: ?><p class="small text-muted mb-0">The Master signs the report after arrival.</p><?php endif; ?>
          </div></div></div>
        </div>
        <?php
    }
    layout_end();
    return;
}

// ------------------------------------------------------------------ the voyage board
$from = gdate('from', g('date') ?: Ship::today());
$to = gdate('to', g('date') ?: date('Y-m-d', strtotime(Ship::today() . ' +6 days')));
$route = gint('route');
$vessel = gint('vessel');
$status = (string)g('status');
$where = 'v.voyage_date BETWEEN :f AND :t';
$par = ['f' => $from, 't' => $to];
if ($route) {
    $where .= ' AND v.route_id = :r';
    $par['r'] = $route;
}
if ($vessel) {
    $where .= ' AND v.vessel_id = :vs';
    $par['vs'] = $vessel;
}
if ($status !== '' && isset(Ship::VOYAGE_STATUS[$status])) {
    $where .= ' AND v.status = :st';
    $par['st'] = $status;
}
$rows = DB::all("SELECT v.*, po.code origin_code, pd.code dest_code, s.name vessel_name, COALESCE(v.pax_quota, s.pax_capacity) cap,
                        (SELECT COUNT(*) FROM shp_tickets t WHERE t.voyage_id = v.id AND t.status IN ('issued', 'checked_in', 'boarded', 'no_show')) paid,
                        (SELECT COUNT(*) FROM shp_tickets t WHERE t.voyage_id = v.id AND t.status = 'boarded') boarded,
                        (SELECT COUNT(*) FROM shp_holds h WHERE h.vessel_id = v.vessel_id AND h.status = 'active') holds
                   FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id
                   JOIN shp_vessels s ON s.id = v.vessel_id WHERE $where ORDER BY v.etd", $par);
$actions = (Access::can($page, 'create') ? '<button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#newVoyage"><i class="bi bi-plus-lg"></i> New voyage</button> ' : '')
    . (Access::page('ops.schedule') ? btn_link(url('ops.schedule'), 'Schedule planner', 'btn-outline-secondary', 'calendar3') : '');
layout_start('Voyages & Dispatch', ['actions' => $actions]);
if (Access::can($page, 'create')): ?>
<div class="collapse <?= is_post() ? 'show' : '' ?>" id="newVoyage"><form method="post" class="card mb-3"><?= csrf_field() ?><input type="hidden" name="act" value="create"><div class="card-body"><div class="row g-2">
  <?= select_field('route_id', 'Route', Ship::routeOptions(), p('route_id'), ['class' => 'col-md-3', 'blank' => '', 'required' => true]) ?>
  <?= select_field('vessel_id', 'Vessel', Ship::vesselOptions(), p('vessel_id'), ['class' => 'col-md-3', 'blank' => '', 'required' => true]) ?>
  <?= input_field('etd', 'Departure', p('etd'), ['type' => 'datetime-local', 'class' => 'col-md-2', 'required' => true]) ?>
  <?= input_field('eta', 'Arrival (blank = transit time)', p('eta'), ['type' => 'datetime-local', 'class' => 'col-md-2']) ?>
  <?= select_field('status', 'Start as', ['draft' => 'Draft', 'published' => 'Published', 'on_sale' => 'On sale'], p('status', 'on_sale'), ['class' => 'col-md-2']) ?>
  <?= input_field('berth', 'Berth', p('berth'), ['class' => 'col-md-2']) ?><?= input_field('pax_quota', 'Passenger quota (blank = vessel limit)', p('pax_quota'), ['type' => 'number', 'class' => 'col-md-3', 'step' => '1']) ?>
  <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-sm w-100">Schedule voyage</button></div>
</div><div class="form-text">The same vessel cannot be on two voyages at once and a berth is not given twice within an hour; both are checked when you save.</div></div></form></div>
<?php endif; ?>
<form class="toolbar mb-3" method="get"><input type="hidden" name="p" value="<?= h($page) ?>">
  <?= input_field('from', 'From', $from, ['type' => 'date', 'class' => '']) ?><?= input_field('to', 'To', $to, ['type' => 'date', 'class' => '']) ?>
  <?= select_field('route', 'Route', Ship::routeOptions(false), $route, ['class' => '', 'blank' => 'All routes']) ?><?= select_field('vessel', 'Vessel', Ship::vesselOptions(false), $vessel, ['class' => '', 'blank' => 'All vessels']) ?>
  <?= select_field('status', 'Status', Ship::VOYAGE_STATUS, $status, ['class' => '', 'blank' => 'Any status']) ?>
  <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Show</button></div>
</form>
<div class="voyage-list">
<?php foreach ($rows as $r):
    $lfp = $r['cap'] ? 100 * $r['paid'] / $r['cap'] : 0;
    $extra = '<div class="small"><b>' . (int)$r['paid'] . '</b> / ' . (int)$r['cap'] . ' paid, <b>' . (int)$r['boarded'] . '</b> boarded</div><div style="min-width:150px">' . meter($lfp, $lfp > 92 ? 'warn' : '') . '</div>'
        . ($r['holds'] && !in_array($r['status'], ['completed', 'cancelled', 'arrived', 'departed'], true) ? '<div class="mt-1"><span class="st st-bad">Vessel on hold</span></div>' : '');
    echo voyage_card($r, url($page, ['id' => $r['id']]), $extra);
endforeach; ?>
<?php if (!$rows): ?><?= empty_note('No voyage in these dates.') ?><?php endif; ?>
</div>
<?php
layout_end();
