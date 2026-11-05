<?php
declare(strict_types=1);

/*
 * Fare Matrix & Rates: passenger fares by route and accommodation class, vehicle rates, cargo tariffs, peak dates and promos.
 * Every rate has an effective date and the old one stays in the history: a change is a new rate from a date
 * (the old rate is ended the day before), never an edit of a rate already sold on.
 */
$page = 'shp.fares';
$tab = in_array(g('tab'), ['pax', 'vehicle', 'cargo', 'peak', 'promo'], true) ? g('tab') : 'pax';
$routes = Ship::routeOptions(false);
$accomCodes = DB::pairs('SELECT DISTINCT code, MAX(name) FROM shp_accom_classes GROUP BY code ORDER BY code') ?: ['ECO' => 'Economy', 'TOU' => 'Tourist', 'BUS' => 'Business', 'CAB' => 'Cabin'];

handle_post(function () use ($page) {
    ship_require($page, 'create');
    $kind = (string)p('kind');
    $from = pdate('effective_from', Ship::today());
    DB::tx(function () use ($kind, $from, $page) {
        if ($kind === 'end') {
            $tbl = ['pax' => 'shp_fares', 'vehicle' => 'shp_vehicle_rates', 'cargo' => 'shp_cargo_tariffs'][(string)p('t')] ?? null;
            if ($tbl && ($id = pint('id'))) {
                DB::update($tbl, ['effective_to' => pdate('end_on', Ship::today())], 'id = :id', ['id' => $id]);
                Ship::audit('rate_end', 'shp', $tbl, $id, 'Rate ended ' . pdate('end_on', Ship::today()), ['page_key' => $page]);
                flash('success', 'Rate ended.');
            }
            return;
        }
        if ($kind === 'pax') {
            $route = pint('route_id');
            $code = (string)p('accom_code');
            $base = pnum('base_fare');
            if (!$route || $code === '' || $base <= 0) {
                fail('Choose the route and the class, and enter the fare.');
            }
            DB::run('UPDATE shp_fares SET effective_to = :e WHERE route_id = :r AND accom_code = :c AND effective_to IS NULL AND effective_from < :f',
                ['e' => date('Y-m-d', strtotime($from . ' -1 day')), 'r' => $route, 'c' => $code, 'f' => $from]);
            if (DB::value('SELECT 1 FROM shp_fares WHERE route_id = :r AND accom_code = :c AND effective_from = :f', ['r' => $route, 'c' => $code, 'f' => $from])) {
                fail('A fare for this route and class already starts on ' . fdate($from) . '. End it first or choose another date.');
            }
            $id = DB::insert('shp_fares', ['route_id' => $route, 'accom_code' => $code, 'base_fare' => $base, 'peak_fare' => p('peak_fare') !== '' ? pnum('peak_fare') : null,
                'fuel_surcharge' => pnum('fuel_surcharge'), 'tax_code_id' => ShipGL::taxCode('VAT12'), 'effective_from' => $from, 'approved_by' => Auth::id(),
                'notes' => p('notes') ?: null, 'created_by' => Auth::id()]);
            Ship::audit('fare_change', 'shp', 'shp_fare', $id, 'Fare ' . $code . ' on route #' . $route . ' from ' . $from, ['amount' => $base, 'page_key' => $page]);
        } elseif ($kind === 'vehicle') {
            $route = pint('route_id');
            $cls = pint('vehicle_class_id');
            if (!$route || !$cls || pnum('rate') <= 0) {
                fail('Choose the route and the vehicle class, and enter the rate.');
            }
            DB::run('UPDATE shp_vehicle_rates SET effective_to = :e WHERE route_id = :r AND vehicle_class_id = :c AND effective_to IS NULL AND effective_from < :f',
                ['e' => date('Y-m-d', strtotime($from . ' -1 day')), 'r' => $route, 'c' => $cls, 'f' => $from]);
            $id = DB::insert('shp_vehicle_rates', ['route_id' => $route, 'vehicle_class_id' => $cls, 'rate' => pnum('rate'), 'includes_driver' => p('includes_driver') === '1' ? 1 : 0, 'effective_from' => $from]);
            Ship::audit('rate_change', 'shp', 'shp_vehicle_rate', $id, 'Vehicle rate from ' . $from, ['amount' => pnum('rate'), 'page_key' => $page]);
        } elseif ($kind === 'cargo') {
            $route = pint('route_id');
            $cc = (string)p('commodity_class');
            DB::run('UPDATE shp_cargo_tariffs SET effective_to = :e WHERE ' . ($route ? 'route_id = :r' : 'route_id IS NULL') . ' AND commodity_class = :c AND effective_to IS NULL AND effective_from < :f',
                array_merge(['e' => date('Y-m-d', strtotime($from . ' -1 day')), 'c' => $cc, 'f' => $from], $route ? ['r' => $route] : []));
            $id = DB::insert('shp_cargo_tariffs', ['route_id' => $route, 'commodity_class' => $cc, 'rate_per_kg' => pnum('rate_per_kg'), 'rate_per_cbm' => pnum('rate_per_cbm'),
                'min_charge' => pnum('min_charge'), 'handling_fee' => pnum('handling_fee'), 'insurance_pct' => pnum('insurance_pct'), 'effective_from' => $from]);
            Ship::audit('rate_change', 'shp', 'shp_cargo_tariff', $id, 'Cargo tariff ' . $cc . ' from ' . $from, ['page_key' => $page]);
        } elseif ($kind === 'peak') {
            if (!pdate('date_from') || !pdate('date_to') || trim((string)p('name')) === '') {
                fail('Enter the name and the dates of the peak period.');
            }
            DB::insert('shp_peak_dates', ['name' => p('name'), 'date_from' => pdate('date_from'), 'date_to' => pdate('date_to')]);
        } elseif ($kind === 'promo') {
            $code = strtoupper(trim((string)p('code')));
            if ($code === '' || pnum('discount_pct') <= 0 || pnum('discount_pct') > 100) {
                fail('Enter the promo code and a discount between 0 and 100%.');
            }
            if (DB::value('SELECT 1 FROM shp_promos WHERE code = :c', ['c' => $code])) {
                fail('Promo code ' . $code . ' exists already.');
            }
            DB::insert('shp_promos', ['code' => $code, 'name' => p('name') ?: $code, 'discount_pct' => pnum('discount_pct'), 'route_id' => pint('route_id'),
                'valid_from' => pdate('valid_from'), 'valid_to' => pdate('valid_to'), 'max_uses' => p('max_uses') !== '' ? (int)p('max_uses') : null, 'max_per_booking' => max(1, (int)p('max_per_booking', '10'))]);
        }
        flash('success', 'Saved.');
    });
    redirect(url($page, ['tab' => p('tab', 'pax'), 'route' => g('route')]));
});

$routeFilter = gint('route');
$canAdd = Access::can($page, 'create');
layout_start('Fare Matrix & Rates');
echo tabs_line([
    'pax' => ['Passenger fares', url($page, ['tab' => 'pax']), null], 'vehicle' => ['Vehicle rates', url($page, ['tab' => 'vehicle']), null],
    'cargo' => ['Cargo tariffs', url($page, ['tab' => 'cargo']), null], 'peak' => ['Peak dates', url($page, ['tab' => 'peak']), null], 'promo' => ['Promo codes', url($page, ['tab' => 'promo']), null],
], $tab);
$endForm = fn(string $t, int $id) => $canAdd ? '<form class="d-inline-flex gap-1" method="post">' . csrf_field() . '<input type="hidden" name="kind" value="end"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="tab" value="' . h($tab) . '"><input type="hidden" name="id" value="' . $id . '"><input class="form-control form-control-sm" style="width:9rem" type="date" name="end_on" value="' . Ship::today() . '"><button class="btn btn-sm btn-outline-secondary">End</button></form>' : '';
$state = fn($r) => $r['effective_from'] > Ship::today() ? '<span class="st st-info">Upcoming</span>' : (($r['effective_to'] && $r['effective_to'] < Ship::today()) ? '<span class="st st-muted">Ended</span>' : '<span class="st st-good">In force</span>');
?>
<?php if ($tab === 'pax'): ?>
  <?php if ($canAdd): ?>
  <div class="card mb-3 reveal"><div class="card-header">New fare (from a date; the fare in force is ended the day before)</div><div class="card-body">
  <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="kind" value="pax"><input type="hidden" name="tab" value="pax">
    <?= select_field('route_id', 'Route', $routes, $routeFilter, ['class' => 'col-md-3', 'blank' => '', 'required' => true]) ?>
    <?= select_field('accom_code', 'Accommodation', $accomCodes, '', ['class' => 'col-md-2', 'required' => true]) ?>
    <?= input_field('base_fare', 'Regular fare (VAT incl.)', '', ['type' => 'number', 'class' => 'col-md-2', 'required' => true]) ?>
    <?= input_field('peak_fare', 'Peak fare', '', ['type' => 'number', 'class' => 'col-md-1']) ?>
    <?= input_field('fuel_surcharge', 'Fuel surcharge', '0', ['type' => 'number', 'class' => 'col-md-1']) ?>
    <?= input_field('effective_from', 'Effective from', Ship::today(), ['type' => 'date', 'class' => 'col-md-2']) ?>
    <div class="col-md-1"><button class="btn btn-primary btn-sm w-100">Add</button></div>
  </form></div></div>
  <?php endif; ?>
  <form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>"><input type="hidden" name="tab" value="pax">
    <?= select_field('route', 'Route', $routes, $routeFilter, ['class' => '', 'blank' => 'All routes', 'extra' => 'data-autosubmit']) ?></form>
  <div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr><th>Route</th><th>Class</th><th class="num">Regular fare</th><th class="num">Peak</th><th class="num">Fuel surcharge</th><th>From</th><th>To</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach (DB::all('SELECT f.*, r.code FROM shp_fares f JOIN shp_routes r ON r.id = f.route_id' . ($routeFilter ? ' WHERE f.route_id = :r' : '') . ' ORDER BY r.code, f.accom_code, f.effective_from DESC', $routeFilter ? ['r' => $routeFilter] : []) as $f): ?>
    <tr><td><?= h($f['code']) ?></td><td><?= h($accomCodes[$f['accom_code']] ?? $f['accom_code']) ?></td><td class="num fw-semibold"><?= money($f['base_fare']) ?></td><td class="num"><?= $f['peak_fare'] !== null ? money($f['peak_fare']) : '' ?></td>
      <td class="num"><?= money($f['fuel_surcharge'], true) ?></td><td><?= h(fdate($f['effective_from'])) ?></td><td><?= h(fdate($f['effective_to'])) ?></td><td><?= $state($f) ?></td>
      <td class="text-end"><?= !$f['effective_to'] ? $endForm('pax', (int)$f['id']) : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php elseif ($tab === 'vehicle'): ?>
  <?php if ($canAdd): ?>
  <div class="card mb-3 reveal"><div class="card-header">New vehicle rate</div><div class="card-body">
  <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="kind" value="vehicle"><input type="hidden" name="tab" value="vehicle">
    <?= select_field('route_id', 'Route', $routes, '', ['class' => 'col-md-3', 'blank' => '', 'required' => true]) ?>
    <?= select_field('vehicle_class_id', 'Vehicle class', DB::pairs('SELECT id, name FROM shp_vehicle_classes WHERE is_active = 1 ORDER BY sort_order'), '', ['class' => 'col-md-3', 'required' => true]) ?>
    <?= input_field('rate', 'Rate (VAT incl.)', '', ['type' => 'number', 'class' => 'col-md-2', 'required' => true]) ?>
    <?= check_field('includes_driver', 'Driver fare included', false, 'col-md-2') ?>
    <?= input_field('effective_from', 'Effective from', Ship::today(), ['type' => 'date', 'class' => 'col-md-2']) ?>
    <div class="col-12"><button class="btn btn-primary btn-sm">Add rate</button></div>
  </form></div></div>
  <?php endif; ?>
  <div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr><th>Route</th><th>Vehicle class</th><th class="num">Rate</th><th>Driver incl.</th><th>From</th><th>To</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach (DB::all('SELECT v.*, r.code, c.name AS cname FROM shp_vehicle_rates v JOIN shp_routes r ON r.id = v.route_id JOIN shp_vehicle_classes c ON c.id = v.vehicle_class_id ORDER BY r.code, c.sort_order, v.effective_from DESC') as $f): ?>
    <tr><td><?= h($f['code']) ?></td><td><?= h($f['cname']) ?></td><td class="num fw-semibold"><?= money($f['rate']) ?></td><td><?= $f['includes_driver'] ? 'Yes' : 'No' ?></td>
      <td><?= h(fdate($f['effective_from'])) ?></td><td><?= h(fdate($f['effective_to'])) ?></td><td><?= $state($f) ?></td><td class="text-end"><?= !$f['effective_to'] ? $endForm('vehicle', (int)$f['id']) : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php elseif ($tab === 'cargo'): ?>
  <?php $cc = ['general' => 'General cargo', 'perishable' => 'Perishable', 'dangerous' => 'Dangerous goods', 'valuable' => 'Valuables', 'livestock' => 'Livestock']; ?>
  <?php if ($canAdd): ?>
  <div class="card mb-3 reveal"><div class="card-header">New cargo tariff (freight = the higher of weight and volume, never below the minimum)</div><div class="card-body">
  <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="kind" value="cargo"><input type="hidden" name="tab" value="cargo">
    <?= select_field('route_id', 'Route', $routes, '', ['class' => 'col-md-3', 'blank' => 'Every route']) ?>
    <?= select_field('commodity_class', 'Commodity', $cc, 'general', ['class' => 'col-md-2']) ?>
    <?= input_field('rate_per_kg', 'Per kg', '', ['type' => 'number', 'class' => 'col-md-1', 'step' => '0.0001']) ?>
    <?= input_field('rate_per_cbm', 'Per cbm', '', ['type' => 'number', 'class' => 'col-md-1']) ?>
    <?= input_field('min_charge', 'Minimum', '', ['type' => 'number', 'class' => 'col-md-1']) ?>
    <?= input_field('handling_fee', 'Handling', '0', ['type' => 'number', 'class' => 'col-md-1']) ?>
    <?= input_field('insurance_pct', 'Insurance % of value', '0', ['type' => 'number', 'class' => 'col-md-1', 'step' => '0.001']) ?>
    <?= input_field('effective_from', 'From', Ship::today(), ['type' => 'date', 'class' => 'col-md-2']) ?>
    <div class="col-12"><button class="btn btn-primary btn-sm">Add tariff</button></div>
  </form></div></div>
  <?php endif; ?>
  <div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr><th>Route</th><th>Commodity</th><th class="num">Per kg</th><th class="num">Per cbm</th><th class="num">Minimum</th><th class="num">Handling</th><th class="num">Insurance</th><th>From</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach (DB::all('SELECT t.*, r.code FROM shp_cargo_tariffs t LEFT JOIN shp_routes r ON r.id = t.route_id ORDER BY r.code, t.commodity_class, t.effective_from DESC') as $f): ?>
    <tr><td><?= h($f['code'] ?: 'Every route') ?></td><td><?= h($cc[$f['commodity_class']] ?? $f['commodity_class']) ?></td><td class="num"><?= h(number_format((float)$f['rate_per_kg'], 4)) ?></td>
      <td class="num"><?= money($f['rate_per_cbm']) ?></td><td class="num"><?= money($f['min_charge']) ?></td><td class="num"><?= money($f['handling_fee']) ?></td><td class="num"><?= h(qty($f['insurance_pct'])) ?>%</td>
      <td><?= h(fdate($f['effective_from'])) ?></td><td><?= $state($f) ?></td><td class="text-end"><?= !$f['effective_to'] ? $endForm('cargo', (int)$f['id']) : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php elseif ($tab === 'peak'): ?>
  <?php if ($canAdd): ?>
  <div class="card mb-3 reveal"><div class="card-body"><form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="kind" value="peak"><input type="hidden" name="tab" value="peak">
    <?= input_field('name', 'Peak period', '', ['class' => 'col-md-4', 'required' => true]) ?><?= input_field('date_from', 'From', '', ['type' => 'date', 'class' => 'col-md-3']) ?>
    <?= input_field('date_to', 'To', '', ['type' => 'date', 'class' => 'col-md-3']) ?><div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Add</button></div></form></div></div>
  <?php endif; ?>
  <div class="card reveal"><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Peak period</th><th>From</th><th>To</th></tr></thead><tbody>
  <?php foreach (DB::all('SELECT * FROM shp_peak_dates ORDER BY date_from DESC') as $pk): ?><tr><td><?= h($pk['name']) ?></td><td><?= h(fdate($pk['date_from'])) ?></td><td><?= h(fdate($pk['date_to'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><div class="card-body small text-muted">On these dates the peak fare of a class is used when one is set.</div></div>
<?php else: ?>
  <?php if ($canAdd): ?>
  <div class="card mb-3 reveal"><div class="card-body"><form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="kind" value="promo"><input type="hidden" name="tab" value="promo">
    <?= input_field('code', 'Code', '', ['class' => 'col-md-2', 'required' => true]) ?><?= input_field('name', 'Name', '', ['class' => 'col-md-3']) ?>
    <?= input_field('discount_pct', 'Discount %', '', ['type' => 'number', 'class' => 'col-md-1']) ?><?= select_field('route_id', 'Route', $routes, '', ['class' => 'col-md-2', 'blank' => 'Any']) ?>
    <?= input_field('valid_from', 'From', '', ['type' => 'date', 'class' => 'col-md-2']) ?><?= input_field('valid_to', 'To', '', ['type' => 'date', 'class' => 'col-md-2']) ?>
    <?= input_field('max_uses', 'Max uses', '', ['type' => 'number', 'class' => 'col-md-2', 'step' => '1']) ?><?= input_field('max_per_booking', 'Max passengers per booking', '10', ['type' => 'number', 'class' => 'col-md-3', 'step' => '1']) ?>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Add promo</button></div></form>
    <div class="form-text mt-2">A promo never stacks on a statutory privilege (senior, PWD, student, Medal of Valor): the privilege applies and the promo is dropped.</div></div></div>
  <?php endif; ?>
  <div class="card reveal"><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Code</th><th>Name</th><th class="num">Discount</th><th>Valid</th><th class="num">Used</th><th>Per booking</th></tr></thead><tbody>
  <?php foreach (DB::all('SELECT * FROM shp_promos ORDER BY id DESC') as $pr): ?><tr><td class="fw-semibold"><?= h($pr['code']) ?></td><td><?= h($pr['name']) ?></td><td class="num"><?= h(qty($pr['discount_pct'])) ?>%</td>
    <td><?= h(fdate($pr['valid_from']) . ' to ' . fdate($pr['valid_to'])) ?></td><td class="num"><?= (int)$pr['used_count'] ?><?= $pr['max_uses'] !== null ? ' / ' . (int)$pr['max_uses'] : '' ?></td><td><?= (int)$pr['max_per_booking'] ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php endif;
layout_end();
