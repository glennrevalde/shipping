<?php
declare(strict_types=1);

$page = 'ops.schedule';
$days = (int)g('days', '7') === 14 ? 14 : 7;
$start = gdate('start', null) ?: date('Y-m-d', strtotime('monday this week', strtotime(Ship::today())));
$end = date('Y-m-d', strtotime($start . ' +' . ($days - 1) . ' days'));
$canMake = Access::can($page, 'create');
$weekdayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

handle_post(function () use ($page, $canMake) {
    if (!$canMake) {
        Access::denyPage($page, 'create voyages on');
    }
    $times = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)p('times')) ?: [])));
    $times = array_map(fn($t) => preg_match('/^\d{1,2}:\d{2}$/', $t) ? str_pad($t, 5, '0', STR_PAD_LEFT) : $t, $times);
    foreach ($times as $t) {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
            fail('Write the departure times as 24-hour HH:MM, for example 08:00, 13:00.');
        }
    }
    $status = in_array(p('status'), ['draft', 'published', 'on_sale'], true) ? (string)p('status') : 'on_sale';
    [$made, $skipped] = Voyages::generate((int)pint('route_id'), (int)pint('vessel_id'), $times, array_map('intval', (array)($_POST['weekdays'] ?? [])),
        (string)pdate('from'), (string)pdate('to'), $status);
    if (!$made && !$skipped) {
        fail('No date in that range falls on the weekdays you ticked.');
    }
    $msg = count($made) . ' voyage(s) created.';
    if ($skipped) {
        $msg .= ' Skipped ' . count($skipped) . ': ' . implode(' ', array_slice($skipped, 0, 6)) . (count($skipped) > 6 ? ' ...' : '');
    }
    flash($made ? ($skipped ? 'warning' : 'success') : 'danger', $msg);
    $first = $made ? (string)DB::value('SELECT voyage_date FROM shp_voyages WHERE id = :id', ['id' => $made[0]]) : (string)pdate('from');
    redirect(url($page, ['start' => date('Y-m-d', strtotime('monday this week', strtotime($first))), 'days' => 14]));
});

$vessels = DB::all("SELECT id, code, name, vessel_type FROM shp_vessels WHERE status <> 'retired' ORDER BY name");
$voy = DB::all("SELECT v.id, v.voyage_no, v.vessel_id, v.voyage_date, v.etd, v.eta, v.status, po.code o, pd.code d, COALESCE(v.pax_quota, s.pax_capacity) cap,
                       (SELECT COUNT(*) FROM shp_tickets t WHERE t.voyage_id = v.id AND t.status IN ('issued', 'checked_in', 'boarded', 'no_show')) paid
                  FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id
                  JOIN shp_vessels s ON s.id = v.vessel_id
                 WHERE v.voyage_date BETWEEN :f AND :t ORDER BY v.etd", ['f' => $start, 't' => $end]);
$grid = [];
foreach ($voy as $x) {
    $grid[(int)$x['vessel_id']][$x['voyage_date']][] = $x;
}
$byStatus = array_count_values(array_column($voy, 'status'));
$nav = fn(int $shift) => url($page, ['start' => date('Y-m-d', strtotime($start . ' ' . ($shift >= 0 ? '+' : '') . $shift . ' days')), 'days' => $days]);
$actions = ($canMake ? '<button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#generator"><i class="bi bi-magic"></i> Generate a schedule</button> ' : '')
    . (Access::page('ops.voyages') ? btn_link(url('ops.voyages'), 'Voyage board', 'btn-outline-secondary', 'compass') : '');
layout_start('Schedule Planner', ['actions' => $actions]);
?>
<?php if ($canMake): ?>
<div class="collapse <?= is_post() ? 'show' : '' ?>" id="generator"><form method="post" class="card mb-3"><?= csrf_field() ?><div class="card-header">Generate recurring voyages</div><div class="card-body">
  <div class="row g-2">
    <?= select_field('route_id', 'Route', Ship::routeOptions(), p('route_id'), ['class' => 'col-md-4', 'blank' => '', 'required' => true]) ?>
    <?= select_field('vessel_id', 'Vessel', Ship::vesselOptions(), p('vessel_id'), ['class' => 'col-md-3', 'blank' => '', 'required' => true]) ?>
    <?= input_field('times', 'Departure times (24-hour)', p('times', '08:00'), ['class' => 'col-md-3', 'required' => true, 'extra' => 'placeholder="08:00, 13:00"']) ?>
    <?= select_field('status', 'Start them as', ['on_sale' => 'On sale', 'published' => 'Published (not yet sold)', 'draft' => 'Draft'], p('status', 'on_sale'), ['class' => 'col-md-2']) ?>
    <?= input_field('from', 'From', p('from', date('Y-m-d', strtotime($end . ' +1 day'))), ['type' => 'date', 'class' => 'col-md-3', 'required' => true]) ?>
    <?= input_field('to', 'To', p('to', date('Y-m-d', strtotime($end . ' +7 days'))), ['type' => 'date', 'class' => 'col-md-3', 'required' => true]) ?>
    <div class="col-md-6"><label class="form-label">Sails on</label><div class="d-flex flex-wrap gap-3">
      <?php foreach ($weekdayNames as $n => $wd): $on = is_post() ? in_array((string)$n, (array)($_POST['weekdays'] ?? []), true) : true; ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="weekdays[]" value="<?= $n ?>" id="wd<?= $n ?>" <?= checked($on) ?>><label class="form-check-label" for="wd<?= $n ?>"><?= $wd ?></label></div>
      <?php endforeach; ?></div></div>
  </div>
  <button class="btn btn-primary btn-sm mt-3"><i class="bi bi-magic"></i> Create the voyages</button>
  <span class="small text-muted ms-2">Arrival comes from the route's transit time. A departure that would put the vessel on two voyages at once is skipped and listed.</span>
</div></form></div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <div class="btn-group btn-group-sm" role="group" aria-label="Move the calendar">
    <a class="btn btn-outline-secondary" href="<?= h($nav(-$days)) ?>" aria-label="Earlier"><i class="bi bi-chevron-left"></i></a>
    <a class="btn btn-outline-secondary" href="<?= h(url($page, ['days' => $days])) ?>">This week</a>
    <a class="btn btn-outline-secondary" href="<?= h($nav($days)) ?>" aria-label="Later"><i class="bi bi-chevron-right"></i></a>
  </div>
  <div class="fw-semibold"><?= h(date('M j', strtotime($start))) ?> to <?= h(date('M j, Y', strtotime($end))) ?></div>
  <div class="btn-group btn-group-sm" role="group" aria-label="Days shown">
    <a class="btn <?= $days === 7 ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h(url($page, ['start' => $start, 'days' => 7])) ?>">1 week</a>
    <a class="btn <?= $days === 14 ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h(url($page, ['start' => $start, 'days' => 14])) ?>">2 weeks</a>
  </div>
</div>
<div class="small text-muted mb-2"><?= count($voy) ?> voyage(s) in view<?= $byStatus ? ': ' . h(implode(', ', array_map(fn($k, $n) => $n . ' ' . strtolower(Ship::VOYAGE_STATUS[$k] ?? $k), array_keys($byStatus), $byStatus))) : '' ?>.</div>

<div class="card reveal sched-card"><div class="sched-scroll"><table class="sched-grid d<?= $days ?>">
  <thead><tr><th class="sched-vessel">Vessel</th>
  <?php for ($i = 0; $i < $days; $i++): $d = date('Y-m-d', strtotime($start . " +$i days")); ?>
    <th class="<?= $d === Ship::today() ? 'today' : '' ?> <?= date('N', strtotime($d)) >= 6 ? 'wkend' : '' ?>"><span><?= h(date('D', strtotime($d))) ?></span><b><?= h(date('M j', strtotime($d))) ?></b></th>
  <?php endfor; ?></tr></thead>
  <tbody>
  <?php foreach ($vessels as $s): ?>
    <tr><th class="sched-vessel"><?= h($s['name']) ?><span><?= h($s['code']) ?></span></th>
    <?php for ($i = 0; $i < $days; $i++): $d = date('Y-m-d', strtotime($start . " +$i days")); ?>
      <td class="<?= $d === Ship::today() ? 'today' : '' ?>">
      <?php foreach ($grid[(int)$s['id']][$d] ?? [] as $x): $lf = $x['cap'] ? min(100, 100 * $x['paid'] / $x['cap']) : 0; ?>
        <a class="sched-voy tone-<?= h(Ship::tone($x['status'])) ?>" href="<?= h(url('ops.voyages', ['id' => $x['id']])) ?>" title="<?= h($x['voyage_no'] . ', ' . (Ship::VOYAGE_STATUS[$x['status']] ?? $x['status']) . ', ' . $x['paid'] . ' of ' . $x['cap'] . ' paid') ?>">
          <b><?= h(date('g:i A', strtotime($x['etd']))) ?></b><span><?= h($x['o'] . ' to ' . $x['d']) ?></span>
          <em><?= h(Ship::VOYAGE_STATUS[$x['status']] ?? $x['status']) ?>, <?= (int)$x['paid'] ?> pax</em><i style="width:<?= round($lf, 1) ?>%"></i></a>
      <?php endforeach; ?>
      </td>
    <?php endfor; ?></tr>
  <?php endforeach; ?>
  <?php if (!$vessels): ?><tr><td colspan="<?= $days + 1 ?>"><?= empty_note('Add a vessel in the Vessel Registry first.') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<div class="sched-legend small text-muted mt-2">
  <span class="tone-info">Published, on sale, boarding, sailing</span><span class="tone-warn">Draft</span><span class="tone-good">Completed</span><span class="tone-bad">Cancelled</span>
  <span>The bar under each voyage fills with the passengers paid against its limit.</span>
</div>
<?php
layout_end();
