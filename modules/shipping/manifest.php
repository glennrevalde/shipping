<?php
declare(strict_types=1);

/**
 * Passenger Manifests: numbered versions per voyage (a draft lists the paid passengers, the final lists those who
 * actually boarded), the Master's signature, a printable MARINA / PCG style manifest of passengers, crew and
 * vehicles, and a CSV file in the usual columns (each export is recorded). Names are masked for roles without
 * the passenger-data permission, on screen and in the file.
 *
 *   ?voyage=ID [&m=manifest id] [&export=csv]
 */

$page = 'tkt.manifest';
$voyageId = gint('voyage');

if (!function_exists('shp_mf_name')) {
    /** "Last, First Middle" as stored on the manifest line, masked unless the user may see passenger data */
    function shp_mf_name(string $full, string $kind): string
    {
        if ($kind !== 'pax' || Ship::canPii()) {
            return $full;
        }
        [$last, $first] = array_pad(array_map('trim', explode(',', $full, 2)), 2, '');
        return Ship::maskName($last, $first);
    }

}

$v = $voyageId ? Ship::voyage($voyageId) : null;

handle_post(function () use ($page, $v) {
    if (!$v) {
        fail('Voyage not found.');
    }
    $act = (string)p('act');
    if ($act === 'generate') {
        ship_require($page, 'create');
        $mid = Manifest::generate((int)$v['id'], p('final') === '1', (string)p('remarks'));
        $m = DB::row('SELECT version_no, status FROM shp_manifests WHERE id = :id', ['id' => $mid]);
        flash('success', ucfirst($m['status']) . ' manifest version ' . $m['version_no'] . ' made. Earlier versions are kept as superseded.');
        redirect(url($page, ['voyage' => $v['id'], 'm' => $mid]));
    }
    if ($act === 'sign') {
        ship_require($page, 'edit');
        $mid = (int)pint('manifest_id');
        if (!DB::value('SELECT 1 FROM shp_manifests WHERE id = :m AND voyage_id = :v', ['m' => $mid, 'v' => $v['id']])) {
            fail('That manifest is not of this voyage.');
        }
        Manifest::sign($mid);
        flash('success', 'Manifest signed.');
        redirect(url($page, ['voyage' => $v['id'], 'm' => $mid]));
    }
    fail('Unknown action.');
});

// ================================================================== list of voyages
if (!$v) {
    $from = gdate('from', date('Y-m-d', strtotime(Ship::today() . ' -1 day')));
    $to = gdate('to', date('Y-m-d', strtotime(Ship::today() . ' +1 day')));
    $vessel = gint('vessel');
    $par = ['f' => $from, 't' => $to];
    $where = "v.voyage_date BETWEEN :f AND :t AND v.status <> 'draft'";
    if ($vessel) {
        $where .= ' AND v.vessel_id = :vs';
        $par['vs'] = $vessel;
    }
    $rows = DB::all("SELECT v.*, po.code oc, pd.code dc, s.name vessel_name,
                            (SELECT COUNT(*) FROM shp_tickets t WHERE t.voyage_id = v.id AND t.status IN ('issued', 'checked_in', 'boarded', 'no_show')) paid,
                            (SELECT COUNT(*) FROM shp_tickets t WHERE t.voyage_id = v.id AND t.status = 'boarded') boarded,
                            m.id mid, m.version_no, m.status mstatus, m.pax_boarded mboarded, m.signed_at
                       FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id
                       JOIN shp_vessels s ON s.id = v.vessel_id
                       LEFT JOIN shp_manifests m ON m.id = (SELECT m2.id FROM shp_manifests m2 WHERE m2.voyage_id = v.id AND m2.status <> 'superseded' ORDER BY m2.version_no DESC LIMIT 1)
                      WHERE $where ORDER BY v.etd", $par);
    layout_start('Passenger Manifests');
    ?>
    <form class="toolbar mb-3" method="get"><input type="hidden" name="p" value="<?= h($page) ?>">
      <?= input_field('from', 'From', $from, ['type' => 'date', 'class' => '']) ?><?= input_field('to', 'To', $to, ['type' => 'date', 'class' => '']) ?>
      <?= select_field('vessel', 'Vessel', Ship::vesselOptions(false), $vessel, ['class' => '', 'blank' => 'All vessels']) ?>
      <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Show</button></div>
    </form>
    <div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
      <thead><tr><th>Voyage</th><th>Departure</th><th>Vessel</th><th>Status</th><th class="num">Paid</th><th class="num">Boarded</th><th>Latest manifest</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $r):
          $stale = $r['mstatus'] === 'final' && (int)$r['mboarded'] !== (int)$r['boarded']; ?>
        <tr><td class="fw-semibold"><?= h($r['voyage_no']) ?><div class="small text-muted"><?= h($r['oc'] . ' to ' . $r['dc']) ?></div></td>
          <td class="text-nowrap"><?= h(date('M j, g:i A', strtotime($r['etd']))) ?></td><td><?= h($r['vessel_name']) ?></td><td><?= Ship::label($r['status']) ?></td>
          <td class="num"><?= (int)$r['paid'] ?></td><td class="num"><?= (int)$r['boarded'] ?></td>
          <td><?php if ($r['mid']): ?><?= Ship::label($r['mstatus'], ['final' => 'Final', 'draft' => 'Draft']) ?> <span class="small">v<?= (int)$r['version_no'] ?></span>
            <div class="small <?= $stale ? 'text-danger' : 'text-muted' ?>"><?= $stale ? 'Boarded count changed, regenerate' : ($r['signed_at'] ? 'Signed ' . h(date('M j g:i A', strtotime($r['signed_at']))) : ($r['mstatus'] === 'final' ? 'Not signed yet' : '')) ?></div>
            <?php else: ?><span class="text-muted small">None yet</span><?php endif; ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h(url($page, ['voyage' => $r['id']])) ?>">Open</a></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8"><?= empty_note('No voyage in these dates.', 'card-checklist') ?></td></tr><?php endif; ?>
      </tbody></table></div></div>
    <?php
    layout_end();
    return;
}

// ================================================================== one voyage
$versions = DB::all('SELECT m.*, g.full_name gen_name, s.full_name sign_name FROM shp_manifests m LEFT JOIN users g ON g.id = m.generated_by LEFT JOIN users s ON s.id = m.signed_by
                      WHERE m.voyage_id = :v ORDER BY m.version_no DESC', ['v' => $v['id']]);
$mid = gint('m');
$m = null;
foreach ($versions as $x) {
    if ($mid ? (int)$x['id'] === $mid : $x['status'] !== 'superseded') {
        $m = $x;
        break;
    }
}
$m ??= $versions[0] ?? null;
$c = Boarding::counts((int)$v['id']);

// CSV in the usual MARINA / PCG columns; every file made is recorded
if ($m && g('export') === 'csv') {
    if (!Access::can($page, 'print')) {
        Access::denyPage($page, 'print');
    }
    $lines = Manifest::lines((int)$m['id']);
    DB::insert('shp_reg_exports', ['voyage_id' => $v['id'], 'export_type' => 'manifest_csv', 'manifest_id' => $m['id'], 'row_count' => count($lines),
        'exported_by' => Auth::id(), 'note' => 'Version ' . $m['version_no'] . ' (' . $m['status'] . ')' . (Ship::canPii() ? '' : ', names masked')]);
    Ship::audit('export', 'tkt', 'shp_manifest', (int)$m['id'], 'Manifest v' . $m['version_no'] . ' of ' . $v['voyage_no'] . ' exported', ['page_key' => $page]);
    $out = [];
    foreach ($lines as $l) {
        $out[] = [$l['line_no'], ['pax' => 'Passenger', 'crew' => 'Crew', 'vehicle' => 'Vehicle'][$l['line_kind']] ?? $l['line_kind'], shp_mf_name($l['full_name'], $l['line_kind']),
            $l['age'], $l['gender'], $l['nationality'], $l['category'], $l['accom'], $l['seat_no'], $l['ref_no'], $l['status']];
    }
    csv_download('manifest_' . $v['voyage_no'] . '_v' . $m['version_no'] . '.csv',
        ['No.', 'Kind', 'Name / plate', 'Age', 'Sex', 'Nationality', 'Category / rank / class', 'Accommodation / department / lane', 'Seat', 'Ticket / crew / pass no.', 'Status'], $out);
}

$actions = btn_link(url($page), 'All voyages', 'btn-outline-secondary', 'list-ul') . ' '
    . (Access::page('tkt.boarding') ? btn_link(url('tkt.boarding', ['voyage' => $v['id']]), 'Boarding', 'btn-outline-secondary', 'qr-code-scan') . ' ' : '')
    . ($m && Access::can($page, 'print') ? btn_link(url($page, ['voyage' => $v['id'], 'm' => $m['id'], 'export' => 'csv']), 'CSV file', 'btn-outline-secondary', 'filetype-csv')
        . ' <button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>' : '');
layout_start('Manifest ' . $v['voyage_no'], ['actions' => $actions]);
$latestFinal = null;
foreach ($versions as $x) {
    if ($x['status'] === 'final') {
        $latestFinal = $x;
        break;
    }
}
$stale = $latestFinal && ((int)$latestFinal['pax_boarded'] !== $c['boarded']);
$canGen = Access::can($page, 'create');
$canSign = Access::can($page, 'edit');
$finalAllowed = in_array($v['status'], ['boarding', 'ready', 'departed', 'arrived', 'completed'], true);
?>
<div class="no-print">
  <?= voyage_card($v, Access::page('ops.voyages') ? url('ops.voyages', ['id' => $v['id']]) : null, '<div class="small"><b>' . (int)$c['boarded'] . '</b> boarded of <b>' . (int)$c['paid'] . '</b> paid</div><div class="small text-muted">' . (int)$c['checked_in'] . ' checked in, ' . (int)$c['vehicles_loaded'] . ' vehicles loaded</div>') ?>
  <?php if ($stale): ?>
    <div class="alert alert-warning mt-3 mb-0">The final manifest (version <?= (int)$latestFinal['version_no'] ?>) lists <?= (int)$latestFinal['pax_boarded'] ?> boarded, and <?= (int)$c['boarded'] ?> have boarded now. Make a new final version before departure.</div>
  <?php endif; ?>
  <div class="row g-3 mt-0">
    <div class="col-lg-5"><div class="card reveal h-100"><div class="card-header">Make a version</div><div class="card-body">
      <?php if ($canGen): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="generate">
          <label class="form-label">Remarks (optional)</label><input class="form-control form-control-sm mb-2" name="remarks" maxlength="255" placeholder="Correction of a passenger name, late boarding ...">
          <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-sm btn-outline-primary" name="final" value="0"><i class="bi bi-file-earmark"></i> Draft (paid passengers)</button>
            <button class="btn btn-sm btn-primary" name="final" value="1" <?= $finalAllowed ? '' : 'disabled' ?>><i class="bi bi-file-earmark-check"></i> Final (boarded only)</button>
          </div>
          <div class="small text-muted mt-2"><?= $finalAllowed ? 'A new version never overwrites an earlier one; the earlier finals stay on file as superseded.' : 'The final version is made once boarding has started.' ?></div>
        </form>
      <?php else: ?><p class="small text-muted mb-0">Your role can read and print the manifest but not make a new version.</p><?php endif; ?>
      <?php $exports = DB::all('SELECT e.*, u.full_name FROM shp_reg_exports e LEFT JOIN users u ON u.id = e.exported_by WHERE e.voyage_id = :v ORDER BY e.id DESC LIMIT 5', ['v' => $v['id']]); ?>
      <?php if ($exports): ?><div class="small mt-3"><div class="fw-semibold mb-1">Files exported</div>
        <?php foreach ($exports as $e): ?><div class="text-muted"><?= h(fdatetime($e['created_at'])) ?>, <?= h((string)$e['full_name']) ?>: <?= (int)$e['row_count'] ?> lines, <?= h((string)$e['note']) ?></div><?php endforeach; ?></div><?php endif; ?>
    </div></div></div>
    <div class="col-lg-7"><div class="card reveal h-100"><div class="card-header">Versions</div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>Version</th><th>Made</th><th class="num">Booked</th><th class="num">Boarded</th><th class="num">Crew</th><th class="num">Vehicles</th><th>Signed</th></tr></thead><tbody>
      <?php foreach ($versions as $x): ?>
        <tr class="<?= $m && (int)$x['id'] === (int)$m['id'] ? 'table-active' : '' ?>"><td><a href="<?= h(url($page, ['voyage' => $v['id'], 'm' => $x['id']])) ?>">v<?= (int)$x['version_no'] ?></a> <?= Ship::label($x['status'], ['final' => 'Final', 'draft' => 'Draft', 'superseded' => 'Superseded']) ?></td>
          <td class="small"><?= h(date('M j g:i A', strtotime($x['generated_at']))) ?><div class="text-muted"><?= h((string)$x['gen_name']) ?></div></td>
          <td class="num"><?= (int)$x['pax_booked'] ?></td><td class="num"><?= (int)$x['pax_boarded'] ?></td><td class="num"><?= (int)$x['crew_count'] ?></td><td class="num"><?= (int)$x['vehicle_count'] ?></td>
          <td class="small"><?php if ($x['signed_at']): ?><?= h((string)$x['sign_name']) ?><div class="text-muted"><?= h(date('M j g:i A', strtotime($x['signed_at']))) ?></div>
            <?php elseif ($x['status'] === 'final' && $canSign): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="sign"><input type="hidden" name="manifest_id" value="<?= (int)$x['id'] ?>">
              <button class="btn btn-sm btn-outline-primary" data-confirm="Sign manifest version <?= (int)$x['version_no'] ?> as the true list of persons on board?"><i class="bi bi-pen"></i> Sign</button></form>
            <?php else: ?><span class="text-muted">-</span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$versions): ?><tr><td colspan="7"><?= empty_note('No manifest made yet for this voyage.', 'card-checklist') ?></td></tr><?php endif; ?>
      </tbody></table></div></div></div>
  </div>
</div>

<?php if ($m):
    $pax = Manifest::lines((int)$m['id'], 'pax');
    $crew = Manifest::lines((int)$m['id'], 'crew');
    $veh = Manifest::lines((int)$m['id'], 'vehicle');
    $master = DB::value("SELECT CONCAT(c.first_name, ' ', c.last_name) FROM shp_voyage_crew vc JOIN shp_crew c ON c.id = vc.crew_id WHERE vc.voyage_id = :v AND (c.rank_title LIKE '%Master%' OR c.rank_title LIKE '%Captain%') LIMIT 1", ['v' => $v['id']]);
    $byGender = ['M' => 0, 'F' => 0];
    $minors = 0;
    foreach ($pax as $l) {
        if (isset($byGender[$l['gender'] ?? ''])) {
            $byGender[$l['gender']]++;
        }
        if ($l['age'] !== null && (int)$l['age'] < 18) {
            $minors++;
        }
    }
    ?>
<div class="manifest-doc reveal mt-3">
  <div class="m-head">
    <div><div class="m-title">Passenger and crew manifest</div>
      <div class="small"><?= h(Settings::get('company_name')) ?>, <?= h(Settings::get('company_address')) ?></div></div>
    <div class="text-end small"><div><b>Version <?= (int)$m['version_no'] ?></b> <?= Ship::label($m['status'], ['final' => 'Final', 'draft' => 'Draft', 'superseded' => 'Superseded']) ?></div>
      <div>Made <?= h(fdatetime($m['generated_at'])) ?> by <?= h((string)$m['gen_name']) ?></div>
      <?= $m['signed_at'] ? '<div>Signed ' . h(fdatetime($m['signed_at'])) . ' by ' . h((string)$m['sign_name']) . '</div>' : '' ?></div>
  </div>
  <div class="info-grid mb-2">
    <?= info_pair('Vessel', $v['vessel_name'] . ' (' . $v['vessel_code'] . ')') ?><?= info_pair('Voyage', $v['voyage_no']) ?>
    <?= info_pair('From', $v['origin_name']) ?><?= info_pair('To', $v['dest_name']) ?>
    <?= info_pair('Departure (ETD)', fdatetime($v['etd'])) ?><?= info_pair('Actual departure', $v['atd'] ? fdatetime($v['atd']) : '-') ?>
    <?= info_pair('Master', $master ?: '-') ?><?= info_pair('Clearance ref.', $v['clearance_ref'] ?: '-') ?>
    <?= info_pair('Passengers', count($pax) . ' (' . $byGender['M'] . ' male, ' . $byGender['F'] . ' female, ' . $minors . ' minors)') ?>
    <?= info_pair('Crew', (string)count($crew)) ?><?= info_pair('Vehicles', (string)count($veh)) ?><?= info_pair('Cargo shipments', (string)(int)$m['cargo_count']) ?>
  </div>
  <?php if ($m['remarks']): ?><p class="small mb-1"><b>Remarks:</b> <?= h((string)$m['remarks']) ?></p><?php endif; ?>
  <?php if (!Ship::canPii()): ?><p class="small text-muted mb-1">Passenger names are masked for your role.</p><?php endif; ?>

  <h3>Passengers (<?= count($pax) ?>)</h3>
  <div class="table-responsive"><table class="table table-sm table-bordered mb-0">
    <thead><tr><th class="num">No.</th><th>Name</th><th class="num">Age</th><th>Sex</th><th>Nationality</th><th>Category</th><th>Accommodation</th><th>Seat</th><th>Ticket no.</th><?= $m['status'] === 'draft' ? '<th>Status</th>' : '' ?></tr></thead><tbody>
    <?php $n = 0; foreach ($pax as $l): ?>
      <tr><td class="num"><?= ++$n ?></td><td><?= h(shp_mf_name($l['full_name'], 'pax')) ?></td><td class="num"><?= $l['age'] !== null ? (int)$l['age'] : '' ?></td><td><?= h((string)$l['gender']) ?></td>
        <td><?= h((string)$l['nationality']) ?></td><td><?= h((string)$l['category']) ?></td><td><?= h((string)$l['accom']) ?></td><td><?= h((string)$l['seat_no']) ?></td><td><?= h((string)$l['ref_no']) ?></td>
        <?= $m['status'] === 'draft' ? '<td>' . h(Ship::TICKET_STATUS[$l['status']] ?? (string)$l['status']) . '</td>' : '' ?></tr>
    <?php endforeach; ?>
    <?php if (!$pax): ?><tr><td colspan="10" class="text-center text-muted">No passenger on this version.</td></tr><?php endif; ?>
    </tbody></table></div>

  <h3>Crew (<?= count($crew) ?>)</h3>
  <div class="table-responsive"><table class="table table-sm table-bordered mb-0">
    <thead><tr><th class="num">No.</th><th>Name</th><th>Rank / position</th><th>Department</th><th>Nationality</th><th>Crew no.</th></tr></thead><tbody>
    <?php $n = 0; foreach ($crew as $l): ?>
      <tr><td class="num"><?= ++$n ?></td><td><?= h($l['full_name']) ?></td><td><?= h((string)$l['category']) ?></td><td><?= h((string)$l['accom']) ?></td><td><?= h((string)$l['nationality']) ?></td><td><?= h((string)$l['ref_no']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$crew): ?><tr><td colspan="6" class="text-center text-muted">No crew signed on for this voyage.</td></tr><?php endif; ?>
    </tbody></table></div>

  <?php if ($veh): ?>
  <h3>Vehicles (<?= count($veh) ?>)</h3>
  <div class="table-responsive"><table class="table table-sm table-bordered mb-0">
    <thead><tr><th class="num">No.</th><th>Plate / make</th><th>Class</th><th>Deck / lane</th><th>Pass no.</th><th>Status</th></tr></thead><tbody>
    <?php $n = 0; foreach ($veh as $l): ?>
      <tr><td class="num"><?= ++$n ?></td><td><?= h($l['full_name']) ?></td><td><?= h((string)$l['category']) ?></td><td><?= h((string)$l['accom']) ?></td><td><?= h((string)$l['ref_no']) ?></td><td><?= h(ucfirst(str_replace('_', ' ', (string)$l['status']))) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>

  <div class="m-sign">
    <div>Prepared by<br><b><?= h((string)$m['gen_name']) ?></b></div>
    <div>Master<br><b><?= h($m['signed_at'] ? (string)$m['sign_name'] : ($master ?: '')) ?></b></div>
    <div>Port / PCG inspector<br>&nbsp;</div>
  </div>
</div>
<?php endif; ?>
<?php
layout_end();
