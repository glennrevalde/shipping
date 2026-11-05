<?php
declare(strict_types=1);

$page = 'ops.crew';
$id = gint('id');
$new = g('new') === '1';
$canCreate = Access::can($page, 'create');
$canEdit = Access::can($page, 'edit');
$soon = date('Y-m-d', strtotime(Ship::today() . ' +60 days'));
$departments = ['deck' => 'Deck', 'engine' => 'Engine', 'hotel' => 'Hotel / catering'];
$statuses = ['active' => 'Active', 'on_leave' => 'On leave', 'standby' => 'Standby / relief', 'resigned' => 'Resigned'];
$certTypes = ['STCW Basic Safety Training', 'Medical Fitness Certificate', 'Certificate of Competency (Master)', 'Certificate of Competency (Chief Mate)',
    'Certificate of Competency (Chief Engineer)', 'Certificate of Competency (Second Engineer)', 'Certificate of Proficiency (Survival Craft)',
    'Advanced Fire Fighting', 'Medical First Aid', 'Ship Security Awareness', 'Seafarer\'s Identity Document', 'Seaman\'s Book (SIRB)'];

if (!function_exists('crew_cert_state')) {
    /** [tone, words] of a certificate's expiry */
    function crew_cert_state(?string $exp, string $soon): array
    {
        if (!$exp) {
            return ['muted', 'No expiry'];
        }
        if ($exp < Ship::today()) {
            return ['bad', 'Expired ' . fdate($exp)];
        }
        if ($exp <= $soon) {
            return ['warn', 'Expires ' . fdate($exp)];
        }
        return ['good', 'Valid to ' . fdate($exp)];
    }
}

handle_post(function () use ($page, $canCreate, $canEdit, $departments, $statuses) {
    $act = (string)p('act');
    if ($act === 'save') {
        $cid = pint('id');
        if (!($cid ? $canEdit : $canCreate)) {
            Access::denyPage($page, $cid ? 'edit' : 'create');
        }
        $row = [
            'last_name' => mb_substr(trim((string)p('last_name')), 0, 60), 'first_name' => mb_substr(trim((string)p('first_name')), 0, 60),
            'rank_title' => mb_substr(trim((string)p('rank_title')), 0, 60), 'department' => isset($departments[p('department')]) ? (string)p('department') : 'deck',
            'vessel_id' => pint('vessel_id'), 'status' => isset($statuses[p('status')]) ? (string)p('status') : 'active',
            'mobile' => p('mobile') ?: null, 'seafarer_id' => p('seafarer_id') ?: null, 'license_no' => p('license_no') ?: null,
            'daily_allowance' => r2(pnum('daily_allowance')), 'hire_date' => pdate('hire_date'),
        ];
        if ($row['last_name'] === '' || $row['first_name'] === '' || $row['rank_title'] === '') {
            fail('Enter the surname, the first name and the rank.');
        }
        if ($cid && !Ship::canPii()) {
            unset($row['mobile']);   // the number was shown masked; the one on file stays
        }
        if ($cid) {
            $before = DB::row('SELECT * FROM shp_crew WHERE id = :id', ['id' => $cid]);
            if (!$before) {
                fail('Crew member not found.');
            }
            DB::update('shp_crew', $row, 'id = :id', ['id' => $cid]);
            Ship::audit('edit', 'ops', 'shp_crew', $cid, $before['crew_no'] . ' ' . $row['last_name'], ['changes' => Ship::changes($before, $row), 'page_key' => $page]);
        } else {
            if (!DB::value("SELECT 1 FROM sequences WHERE seq_key = 'shp_crew'")) {
                $max = (int)DB::value("SELECT COALESCE(MAX(CAST(SUBSTRING(crew_no, 4) AS UNSIGNED)), 0) FROM shp_crew WHERE crew_no LIKE 'CR-%'");
                DB::insert('sequences', ['seq_key' => 'shp_crew', 'prefix' => 'CR-', 'next_no' => $max + 1, 'pad' => 4]);
            }
            $row['crew_no'] = Ship::no('shp_crew', 'CR-', 4);
            $cid = DB::insert('shp_crew', $row);
            Ship::audit('create', 'ops', 'shp_crew', $cid, $row['crew_no'] . ' ' . $row['last_name'], ['page_key' => $page]);
        }
        flash('success', 'Crew record saved.');
        redirect(url($page, ['id' => $cid]));
    }
    if ($act === 'cert' || $act === 'cert_delete') {
        if (!$canEdit && !$canCreate) {
            Access::denyPage($page, 'edit certificates on');
        }
        $cid = (int)pint('crew_id');
        $certId = pint('cert_id');
        if ($act === 'cert_delete') {
            if (!$canEdit) {
                Access::denyPage($page, 'remove certificates on');
            }
            DB::run('DELETE FROM shp_crew_certs WHERE id = :id AND crew_id = :c', ['id' => $certId, 'c' => $cid]);
            Ship::audit('delete', 'ops', 'shp_crew_cert', $certId, 'Crew certificate removed', ['page_key' => $page]);
            flash('success', 'Certificate removed.');
            redirect(url($page, ['id' => $cid]));
        }
        $row = ['crew_id' => $cid, 'cert_type' => mb_substr(trim((string)p('cert_type')), 0, 80), 'cert_no' => p('cert_no') ?: null,
            'issued_on' => pdate('issued_on'), 'expires_on' => pdate('expires_on'), 'is_required' => p('is_required') === '1' ? 1 : 0];
        if ($row['cert_type'] === '') {
            fail('Enter the kind of certificate.');
        }
        if ($row['issued_on'] && $row['expires_on'] && $row['expires_on'] < $row['issued_on']) {
            fail('The expiry date is before the issue date.');
        }
        if ($certId) {
            DB::update('shp_crew_certs', $row, 'id = :id AND crew_id = :c', ['id' => $certId, 'c' => $cid]);
        } else {
            $certId = DB::insert('shp_crew_certs', $row);
        }
        Ship::audit($act === 'cert' && pint('cert_id') ? 'edit' : 'create', 'ops', 'shp_crew_cert', $certId, $row['cert_type'] . ' ' . ($row['cert_no'] ?? ''), ['page_key' => $page]);
        flash('success', 'Certificate saved.');
        redirect(url($page, ['id' => $cid]));
    }
    fail('Unknown action.');
});

// ------------------------------------------------------------------ one crew member
if ($id || $new) {
    $c = $id ? DB::row('SELECT c.*, s.name vessel_name FROM shp_crew c LEFT JOIN shp_vessels s ON s.id = c.vessel_id WHERE c.id = :id', ['id' => $id]) : null;
    if ($id && !$c) {
        flash('warning', 'That crew member was not found.');
        redirect(url($page));
    }
    if (!$c && !$canCreate) {
        Access::denyPage($page, 'create');
    }
    $certs = $c ? DB::all('SELECT * FROM shp_crew_certs WHERE crew_id = :c ORDER BY is_required DESC, expires_on', ['c' => $c['id']]) : [];
    $voyages = $c ? DB::all('SELECT v.id, v.voyage_no, v.etd, v.status, vc.position FROM shp_voyage_crew vc JOIN shp_voyages v ON v.id = vc.voyage_id WHERE vc.crew_id = :c ORDER BY v.etd DESC LIMIT 12', ['c' => $c['id']]) : [];
    $editable = $c ? $canEdit : $canCreate;
    $editCert = gint('cert');
    layout_start($c ? $c['last_name'] . ', ' . $c['first_name'] : 'New crew member', ['actions' => btn_link(url($page), 'Crew list', 'btn-outline-secondary', 'people')]);
    ?>
    <div class="row g-3">
      <div class="col-lg-6"><form method="post" class="card reveal"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($c['id'] ?? 0) ?: '' ?>">
        <div class="card-header"><?= $c ? h($c['crew_no']) . ', ' . h($c['rank_title']) : 'Crew details' ?></div><div class="card-body"><fieldset <?= $editable ? '' : 'disabled' ?>><div class="row g-2">
          <?= input_field('last_name', 'Surname', $c['last_name'] ?? p('last_name'), ['class' => 'col-md-6', 'required' => true]) ?>
          <?= input_field('first_name', 'First name', $c['first_name'] ?? p('first_name'), ['class' => 'col-md-6', 'required' => true]) ?>
          <?= input_field('rank_title', 'Rank / position', $c['rank_title'] ?? p('rank_title'), ['class' => 'col-md-6', 'required' => true, 'extra' => 'list="ranks"']) ?>
          <?= select_field('department', 'Department', $departments, $c['department'] ?? p('department', 'deck'), ['class' => 'col-md-3']) ?>
          <?= select_field('status', 'Status', $statuses, $c['status'] ?? p('status', 'active'), ['class' => 'col-md-3']) ?>
          <?= select_field('vessel_id', 'Assigned vessel', Ship::vesselOptions(), $c['vessel_id'] ?? p('vessel_id', g('vessel')), ['class' => 'col-md-6', 'blank' => 'Shore / pool']) ?>
          <?= input_field('hire_date', 'Hired', $c['hire_date'] ?? p('hire_date'), ['type' => 'date', 'class' => 'col-md-3']) ?>
          <?= input_field('daily_allowance', 'Daily allowance', $c['daily_allowance'] ?? p('daily_allowance', '0'), ['type' => 'number', 'class' => 'col-md-3']) ?>
          <?= input_field('mobile', 'Mobile', Ship::canPii() || !$c ? ($c['mobile'] ?? p('mobile')) : Ship::maskPhone($c['mobile']), ['class' => 'col-md-4']) ?>
          <?= input_field('seafarer_id', 'Seafarer ID (SID / SIRB)', $c['seafarer_id'] ?? p('seafarer_id'), ['class' => 'col-md-4']) ?>
          <?= input_field('license_no', 'License no.', $c['license_no'] ?? p('license_no'), ['class' => 'col-md-4']) ?>
        </div>
        <datalist id="ranks"><?php foreach (array_unique(DB::column('SELECT DISTINCT rank_title FROM shp_crew ORDER BY rank_title')) as $r): ?><option value="<?= h($r) ?>"><?php endforeach; ?></datalist>
        <?php if ($editable): ?><button class="btn btn-primary btn-sm mt-3">Save</button><?php endif; ?></fieldset>
        <?php if ($c && !Ship::canPii()): ?><div class="form-text">The mobile number is masked for your role; leave it as it is to keep the number on file.</div><?php endif; ?>
        </div></form>
        <?php if ($voyages): ?>
        <div class="card reveal mt-3"><div class="card-header">Recent voyages signed on</div><div class="table-responsive"><table class="table table-sm mb-0"><tbody>
          <?php foreach ($voyages as $v): ?><tr><td><?= safe_link('ops.voyages', ['id' => $v['id']], $v['voyage_no']) ?></td><td><?= h(fdatetime($v['etd'])) ?></td><td><?= h((string)$v['position']) ?></td><td><?= Ship::label($v['status']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
        <?php endif; ?>
      </div>
      <?php if ($c): ?>
      <div class="col-lg-6"><div class="card reveal"><div class="card-header">Certificates</div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Certificate</th><th>No.</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($certs as $ct): [$tone, $words] = crew_cert_state($ct['expires_on'], $soon); ?>
          <tr><td><?= h($ct['cert_type']) ?><?= (int)$ct['is_required'] ? '' : ' <span class="small text-muted">(optional)</span>' ?></td><td class="small"><?= h((string)$ct['cert_no']) ?></td>
            <td><span class="st st-<?= $tone ?>"><?= h($words) ?></span></td>
            <td class="text-end text-nowrap"><?php if ($canEdit): ?><a class="small" href="<?= h(url($page, ['id' => $c['id'], 'cert' => $ct['id']])) ?>#certForm">Edit</a>
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="cert_delete"><input type="hidden" name="crew_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="cert_id" value="<?= (int)$ct['id'] ?>">
                <button class="btn btn-link btn-sm text-danger p-0 ms-2" data-confirm="Remove this certificate?">Remove</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$certs): ?><tr><td colspan="4"><?= empty_note('No certificate on file.', 'award') ?></td></tr><?php endif; ?>
        </tbody></table></div>
        <?php if ($canEdit || $canCreate):
            $ec = $editCert ? DB::row('SELECT * FROM shp_crew_certs WHERE id = :id AND crew_id = :c', ['id' => $editCert, 'c' => $c['id']]) : null; ?>
        <form method="post" class="card-body border-top" id="certForm"><?= csrf_field() ?><input type="hidden" name="act" value="cert"><input type="hidden" name="crew_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="cert_id" value="<?= (int)($ec['id'] ?? 0) ?: '' ?>">
          <div class="fw-semibold small mb-2"><?= $ec ? 'Edit certificate' : 'Add a certificate' ?></div><div class="row g-2">
          <?= input_field('cert_type', 'Certificate', $ec['cert_type'] ?? '', ['class' => 'col-md-7', 'required' => true, 'extra' => 'list="certTypes"']) ?>
          <?= input_field('cert_no', 'Number', $ec['cert_no'] ?? '', ['class' => 'col-md-5']) ?>
          <?= input_field('issued_on', 'Issued', $ec['issued_on'] ?? '', ['type' => 'date', 'class' => 'col-md-4']) ?>
          <?= input_field('expires_on', 'Expires', $ec['expires_on'] ?? '', ['type' => 'date', 'class' => 'col-md-4']) ?>
          <?= check_field('is_required', 'Required for sailing', $ec ? (int)$ec['is_required'] === 1 : true, 'col-md-4') ?>
          </div><datalist id="certTypes"><?php foreach ($certTypes as $t): ?><option value="<?= h($t) ?>"><?php endforeach; ?></datalist>
          <button class="btn btn-outline-primary btn-sm mt-2"><?= $ec ? 'Save certificate' : 'Add certificate' ?></button>
          <?php if ($ec): ?><a class="btn btn-link btn-sm mt-2" href="<?= h(url($page, ['id' => $c['id']])) ?>">Cancel</a><?php endif; ?>
          <div class="form-text">A required certificate that has expired blocks the crew check of departure readiness for every voyage this person is signed on.</div>
        </form>
        <?php endif; ?>
      </div></div>
      <?php endif; ?>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the crew list
$vessel = gint('vessel');
$dept = (string)g('dept');
$status = (string)g('status', 'active');
$q = trim((string)g('q'));
$where = '1 = 1';
$par = ['today' => Ship::today(), 'soon' => $soon];
if ($vessel) {
    $where .= ' AND c.vessel_id = :v';
    $par['v'] = $vessel;
}
if (isset($departments[$dept])) {
    $where .= ' AND c.department = :d';
    $par['d'] = $dept;
}
if (isset($statuses[$status])) {
    $where .= ' AND c.status = :st';
    $par['st'] = $status;
}
if ($q !== '') {
    $where .= ' AND (c.last_name LIKE :q OR c.first_name LIKE :q2 OR c.crew_no LIKE :q3 OR c.rank_title LIKE :q4)';
    $par += ['q' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%", 'q4' => "%$q%"];
}
$rows = DB::all("SELECT c.*, s.name vessel_name,
                        (SELECT COUNT(*) FROM shp_crew_certs x WHERE x.crew_id = c.id AND x.is_required = 1 AND x.expires_on < :today) expired,
                        (SELECT COUNT(*) FROM shp_crew_certs x WHERE x.crew_id = c.id AND x.is_required = 1 AND x.expires_on >= :today2 AND x.expires_on <= :soon) expiring,
                        (SELECT COUNT(*) FROM shp_crew_certs x WHERE x.crew_id = c.id) certs
                   FROM shp_crew c LEFT JOIN shp_vessels s ON s.id = c.vessel_id
                  WHERE $where ORDER BY s.name IS NULL, s.name, FIELD(c.department, 'deck', 'engine', 'hotel'), c.id", $par + ['today2' => Ship::today()]);
$expiring = DB::all("SELECT x.*, c.last_name, c.first_name, c.rank_title, c.id crew_id, s.name vessel_name FROM shp_crew_certs x JOIN shp_crew c ON c.id = x.crew_id LEFT JOIN shp_vessels s ON s.id = c.vessel_id
                      WHERE c.status = 'active' AND x.is_required = 1 AND x.expires_on <= :soon ORDER BY x.expires_on LIMIT 40", ['soon' => $soon]);
$complement = DB::all("SELECT s.id, s.name, s.crew_complement, (SELECT COUNT(*) FROM shp_crew c WHERE c.vessel_id = s.id AND c.status = 'active') on_roll FROM shp_vessels s WHERE s.status <> 'retired' ORDER BY s.name");
layout_start('Crew & Certificates', ['actions' => $canCreate ? btn_link(url($page, ['new' => 1]), 'New crew member', 'btn-primary', 'person-plus') : '']);
?>
<div class="row g-3 mb-3">
  <div class="col-lg-5"><div class="kpi-grid">
  <?php foreach ($complement as $v): $pct = $v['crew_complement'] ? 100 * $v['on_roll'] / $v['crew_complement'] : 0; ?>
    <?= kpi_tile($v['name'], $v['on_roll'] . ' / ' . $v['crew_complement'], 'people', 'Active crew against the safe manning complement', $v['on_roll'] < $v['crew_complement'] ? 'warn' : 'good', url($page, ['vessel' => $v['id']])) ?>
  <?php endforeach; ?>
  </div></div>
  <div class="col-lg-7"><div class="card reveal h-100"><div class="card-header d-flex justify-content-between"><span>Required certificates expired or due in 60 days</span><span class="small text-muted"><?= count($expiring) ?></span></div>
    <div class="table-responsive" style="max-height:260px;overflow:auto"><table class="table table-sm mb-0"><tbody>
    <?php foreach ($expiring as $x): [$tone, $words] = crew_cert_state($x['expires_on'], $soon); ?>
      <tr><td><a href="<?= h(url($page, ['id' => $x['crew_id']])) ?>"><?= h($x['last_name'] . ', ' . $x['first_name']) ?></a><div class="small text-muted"><?= h($x['rank_title'] . ($x['vessel_name'] ? ', ' . $x['vessel_name'] : '')) ?></div></td>
        <td class="small"><?= h($x['cert_type']) ?></td><td><span class="st st-<?= $tone ?>"><?= h($words) ?></span></td></tr>
    <?php endforeach; ?>
    <?php if (!$expiring): ?><tr><td><?= empty_note('Every required certificate is valid for the next 60 days.', 'award') ?></td></tr><?php endif; ?>
    </tbody></table></div></div></div>
</div>
<form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>">
  <?= input_field('q', 'Find', $q, ['class' => '', 'extra' => 'placeholder="Name, crew no. or rank"']) ?>
  <?= select_field('vessel', 'Vessel', Ship::vesselOptions(false), $vessel, ['class' => '', 'blank' => 'All vessels']) ?>
  <?= select_field('dept', 'Department', $departments, $dept, ['class' => '', 'blank' => 'All departments']) ?>
  <?= select_field('status', 'Status', $statuses, $status, ['class' => '', 'blank' => 'Any status']) ?>
  <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Show</button></div>
</form>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Crew no.</th><th>Name</th><th>Rank</th><th>Department</th><th>Vessel</th><th>Mobile</th><th>Certificates</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= h($r['crew_no']) ?></td><td><a href="<?= h(url($page, ['id' => $r['id']])) ?>"><?= h($r['last_name'] . ', ' . $r['first_name']) ?></a></td><td><?= h($r['rank_title']) ?></td>
      <td><?= h($departments[$r['department']] ?? $r['department']) ?></td><td><?= h((string)($r['vessel_name'] ?? 'Shore / pool')) ?></td>
      <td class="small"><?= h(Ship::canPii() ? (string)$r['mobile'] : Ship::maskPhone($r['mobile'])) ?></td>
      <td><?= $r['expired'] ? '<span class="st st-bad">' . (int)$r['expired'] . ' expired</span>' : ($r['expiring'] ? '<span class="st st-warn">' . (int)$r['expiring'] . ' due soon</span>' : ($r['certs'] ? '<span class="st st-good">Valid</span>' : '<span class="st st-muted">None on file</span>')) ?></td>
      <td><?= Ship::label($r['status'], $statuses) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8"><?= empty_note('No crew member matches.', 'people') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php
layout_end();
