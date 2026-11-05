<?php
declare(strict_types=1);

$page = 'ops.holds';
$me = (int)Auth::id();
$canPlace = Access::can($page, 'create');
$canAct = Access::can($page, 'edit') || $canPlace;
$canTech = (bool)Access::special('shp_technical_release');
$canSafety = (bool)Access::special('shp_safety_release');

if (!function_exists('holds_source')) {
    function holds_source(array $h): string
    {
        if ($h['source_type'] === 'defect') {
            $no = (string)DB::value('SELECT defect_no FROM shp_defects WHERE id = :d', ['d' => $h['source_id']]);
            return 'Defect ' . safe_link('fleet.defects', ['id' => $h['source_id']], $no ?: '#' . $h['source_id']);
        }
        if ($h['source_type'] === 'certificate') {
            $c = DB::row('SELECT cert_type, cert_no FROM shp_certificates WHERE id = :c', ['c' => $h['source_id']]);
            return 'Certificate ' . safe_link('fleet.certificates', ['vessel' => $h['vessel_id']], $c ? trim($c['cert_type'] . ' ' . $c['cert_no']) : '#' . $h['source_id']);
        }
        return 'Placed by hand';
    }
}

handle_post(function () use ($page, $canPlace, $canAct) {
    $act = (string)p('act');
    if ($act === 'place') {
        if (!$canPlace) {
            Access::denyPage($page, 'place holds on');
        }
        $vessel = (int)pint('vessel_id');
        if (!$vessel || !Ship::vessel($vessel)) {
            fail('Choose the vessel.');
        }
        if (trim((string)p('reason')) === '') {
            fail('Write why the vessel is held.');
        }
        DB::tx(fn() => Holds::place($vessel, p('hold_type') === 'restricted' ? 'restricted' : 'no_sail', 'manual', null, (string)p('reason'), pint('voyage_id')));
        flash('success', 'Hold placed. No voyage of the vessel can be released for departure until it is lifted.');
        redirect(url($page));
    }
    if (!$canAct) {
        Access::denyPage($page, 'release holds on');
    }
    $id = (int)pint('hold_id');
    if ($act === 'technical') {
        Holds::technicalRelease($id, (string)p('evidence'));
        flash('success', 'Technical release signed. Another person with the safety-release permission gives the management release.');
    } elseif ($act === 'release') {
        Holds::release($id, (string)p('evidence'));
        flash('success', 'Hold lifted. The voyages of the vessel may be released for departure again once their other checks pass.');
    } else {
        fail('Unknown action.');
    }
    redirect(url($page));
});

Holds::syncCertificates();
$vessels = DB::all("SELECT s.*, (SELECT COUNT(*) FROM shp_holds h WHERE h.vessel_id = s.id AND h.status = 'active' AND h.hold_type = 'no_sail') no_sail,
                           (SELECT COUNT(*) FROM shp_holds h WHERE h.vessel_id = s.id AND h.status = 'active' AND h.hold_type = 'restricted') restricted,
                           (SELECT MIN(v.etd) FROM shp_voyages v WHERE v.vessel_id = s.id AND v.status IN ('published', 'on_sale', 'boarding', 'ready') AND v.etd >= :n) next_etd,
                           (SELECT COUNT(*) FROM shp_voyages v WHERE v.vessel_id = s.id AND v.status IN ('published', 'on_sale', 'boarding', 'ready')) upcoming
                      FROM shp_vessels s WHERE s.status <> 'retired' ORDER BY s.name", ['n' => Ship::now()]);
$active = DB::all("SELECT h.*, s.name vessel_name, s.code vessel_code, v.voyage_no FROM shp_holds h JOIN shp_vessels s ON s.id = h.vessel_id LEFT JOIN shp_voyages v ON v.id = h.voyage_id
                    WHERE h.status = 'active' ORDER BY h.hold_type = 'no_sail' DESC, h.placed_at");
$past = DB::all("SELECT h.*, s.name vessel_name FROM shp_holds h JOIN shp_vessels s ON s.id = h.vessel_id WHERE h.status <> 'active' AND h.placed_at >= :f ORDER BY h.released_at DESC LIMIT 60",
    ['f' => date('Y-m-d', strtotime(Ship::today() . ' -180 days'))]);
layout_start('Vessel Holds & Release', ['actions' => $canPlace ? '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#placeHold"><i class="bi bi-sign-stop"></i> Place a hold</button>' : '']);
?>
<?php if ($canPlace): ?>
<div class="collapse <?= is_post() && p('act') === 'place' ? 'show' : '' ?>" id="placeHold"><form method="post" class="card mb-3"><?= csrf_field() ?><input type="hidden" name="act" value="place"><div class="card-body">
  <div class="row g-2">
    <?= select_field('vessel_id', 'Vessel', Ship::vesselOptions(), p('vessel_id'), ['class' => 'col-md-4', 'blank' => '', 'required' => true]) ?>
    <?= select_field('hold_type', 'Kind of hold', ['no_sail' => 'No-sail (the vessel may not depart)', 'restricted' => 'Restricted sailing (needs an approved override)'], p('hold_type', 'no_sail'), ['class' => 'col-md-4']) ?>
    <?= select_field('voyage_id', 'Raised on voyage (optional)', Ship::voyageOptions(['published', 'on_sale', 'boarding', 'ready'], null, 14), p('voyage_id'), ['class' => 'col-md-4', 'blank' => '']) ?>
    <?= input_field('reason', 'Reason (seen by operations, the Master and the engineers)', p('reason'), ['class' => 'col-12', 'required' => true]) ?>
  </div>
  <button class="btn btn-danger btn-sm mt-3" data-confirm="Place this hold? Departures of the vessel are blocked until it is released by two people.">Place hold</button>
  <span class="small text-muted ms-2">Defects marked safety-critical and expired critical certificates place their own holds.</span>
</div></form></div>
<?php endif; ?>

<div class="row g-3 mb-3">
<?php foreach ($vessels as $s):
    $tone = $s['no_sail'] ? 'bad' : ($s['restricted'] ? 'warn' : 'good'); ?>
  <div class="col-md-6 col-xl-4"><div class="card reveal h-100 hold-vessel tone-<?= $tone ?>"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2">
      <div><div class="fw-bold"><?= h($s['name']) ?></div><div class="small text-muted"><?= h(Ship::VESSEL_TYPES[$s['vessel_type']] ?? $s['vessel_type']) ?></div></div>
      <span class="hold-light <?= $tone ?>" aria-hidden="true"></span>
    </div>
    <div class="mt-2"><?= $s['no_sail'] ? '<span class="st st-bad st-live">No-sail hold</span>' : ($s['restricted'] ? '<span class="st st-warn">Restricted sailing</span>' : '<span class="st st-good">Clear to sail</span>') ?></div>
    <div class="small text-muted mt-1"><?= $s['next_etd'] ? 'Next departure ' . h(fdatetime($s['next_etd'])) . ', ' . (int)$s['upcoming'] . ' voyage(s) scheduled' : 'No voyage scheduled' ?></div>
  </div></div></div>
<?php endforeach; ?>
</div>

<div class="card reveal mb-3"><div class="card-header d-flex flex-wrap justify-content-between gap-2"><span>Active holds (<?= count($active) ?>)</span>
  <span class="small text-muted">Lifting a hold takes two people: the technical release by an engineer after the repair passed inspection, then the management release by someone else.</span></div>
<div class="card-body">
<?php foreach ($active as $h):
    $techDone = (bool)$h['technical_release_by'];
    $needsTech = $h['source_type'] === 'defect'; ?>
  <div class="hold-row">
    <div class="d-flex flex-wrap justify-content-between gap-2">
      <div><div class="fw-bold d-flex flex-wrap gap-2 align-items-center"><?= h($h['vessel_name']) ?> <?= $h['hold_type'] === 'no_sail' ? '<span class="st st-bad">No-sail</span>' : '<span class="st st-warn">Restricted</span>' ?></div>
        <div class="small"><?= h($h['reason']) ?></div>
        <div class="small text-muted"><?= holds_source($h) ?>, placed <?= h(fdatetime($h['placed_at'])) ?><?= $h['placed_by'] ? ' by ' . h(Ship::userName((int)$h['placed_by'])) : ' automatically' ?><?= $h['voyage_no'] ? ', on voyage ' . h($h['voyage_no']) : '' ?></div></div>
    </div>
    <ol class="flow-steps mt-2 mb-2">
      <li class="done">Hold placed</li>
      <li class="<?= $techDone ? 'done' : 'now' ?>">Technical release<?= $techDone ? ': ' . h(Ship::userName((int)$h['technical_release_by'])) : ($needsTech ? '' : ' (optional)') ?></li>
      <li class="<?= $techDone || !$needsTech ? 'now' : '' ?>">Management release</li>
    </ol>
    <?php if ($h['release_evidence']): ?><div class="small text-muted mb-2"><?= h($h['release_evidence']) ?></div><?php endif; ?>
    <?php if ($h['source_type'] === 'certificate'): ?>
      <div class="small text-muted">Lifted when the certificate is renewed in Vessel Certificates.</div>
    <?php elseif ($canAct): ?>
      <div class="row g-2">
      <?php if (!$techDone && $canTech): ?>
        <form method="post" class="col-md-6"><?= csrf_field() ?><input type="hidden" name="act" value="technical"><input type="hidden" name="hold_id" value="<?= (int)$h['id'] ?>">
          <div class="input-group input-group-sm"><input class="form-control" name="evidence" placeholder="Inspection evidence (test result, WO no.)" required aria-label="Technical evidence"><button class="btn btn-outline-primary">Sign technical release</button></div></form>
      <?php endif; ?>
      <?php if ($canSafety && ($techDone || !$needsTech)): ?>
        <?php if ((int)$h['technical_release_by'] === $me): ?><div class="col-md-6 small text-muted">You signed the technical release; another person gives the management release.</div>
        <?php else: ?>
        <form method="post" class="col-md-6"><?= csrf_field() ?><input type="hidden" name="act" value="release"><input type="hidden" name="hold_id" value="<?= (int)$h['id'] ?>">
          <div class="input-group input-group-sm"><input class="form-control" name="evidence" placeholder="Evidence and authority for lifting the hold" required aria-label="Release evidence"><button class="btn btn-primary" data-confirm="Lift this hold? The vessel may sail again once its other checks pass.">Lift hold</button></div></form>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!$canTech && !$canSafety): ?><div class="col-12 small text-muted">Your role can see holds but not sign their release.</div>
      <?php elseif (!$techDone && $needsTech && !$canTech): ?><div class="col-12 small text-muted">Waiting for the technical release by an engineer once the repair passes its inspection.</div><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$active): ?><?= empty_note('No vessel is on hold.', 'check2-circle') ?><?php endif; ?>
</div></div>

<div class="card reveal"><div class="card-header">Holds lifted in the last six months</div><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Vessel</th><th>Kind</th><th>Reason</th><th>Placed</th><th>Technical release</th><th>Lifted</th><th>Evidence</th></tr></thead><tbody>
  <?php foreach ($past as $h): ?>
    <tr><td class="text-nowrap"><?= h($h['vessel_name']) ?></td><td class="text-nowrap"><?= h($h['hold_type'] === 'no_sail' ? 'No-sail' : 'Restricted') ?></td><td class="small"><?= h($h['reason']) ?><div class="text-muted"><?= holds_source($h) ?></div></td>
      <td class="small text-nowrap"><?= h(fdatetime($h['placed_at'])) ?></td>
      <td class="small"><?= $h['technical_release_by'] ? h(Ship::userName((int)$h['technical_release_by'])) . '<div class="text-muted">' . h(fdatetime($h['technical_release_at'])) . '</div>' : '' ?></td>
      <td class="small"><?= h(Ship::userName((int)$h['released_by'])) ?><div class="text-muted"><?= $h['released_at'] ? h(fdatetime($h['released_at'])) : '' ?></div></td>
      <td class="small"><?= h((string)$h['release_evidence']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$past): ?><tr><td colspan="7"><?= empty_note('No hold lifted yet.') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php
layout_end();
