<?php
declare(strict_types=1);

$page = 'fleet.certificates';
$id = gint('id');
$a = (string)g('a');
$vessel = gint('vessel');
$cert = $id ? DB::row('SELECT * FROM shp_certificates WHERE id = :id', ['id' => $id]) : null;
if ($id && !$cert) {
    flash('warning', 'That certificate no longer exists.');
    redirect(url($page));
}

if (!function_exists('cert_state')) {
    /** [state key, label, days left] of a certificate on today's date */
    function cert_state(array $c): array
    {
        if (!$c['expires_on']) {
            return ['none', 'No expiry', null];
        }
        $days = (int)floor((strtotime($c['expires_on']) - strtotime(Ship::today())) / 86400);
        if ($days < 0) {
            return ['expired', 'Expired', $days];
        }
        if ($days <= max(30, (int)$c['alert_days'])) {
            return ['due', 'Renew now', $days];
        }
        if ($days <= 90) {
            return ['soon', 'Due within 90 days', $days];
        }
        return ['valid', 'Valid', $days];
    }
}

handle_post(function () use ($page, $cert) {
    $act = (string)p('act');
    if ($act === 'delete') {
        ship_require($page, 'delete');
        if (!$cert) {
            fail('Certificate not found.');
        }
        if (DB::value("SELECT 1 FROM shp_holds WHERE source_type = 'certificate' AND source_id = :c AND status = 'active'", ['c' => $cert['id']])) {
            fail('This certificate holds the vessel (expired and critical). Renew it instead of deleting it.');
        }
        DB::run('DELETE FROM shp_certificates WHERE id = :id', ['id' => $cert['id']]);
        Ship::audit('delete', 'fleet', 'shp_certificate', (int)$cert['id'], $cert['cert_type'] . ' ' . $cert['cert_no'], ['before' => $cert, 'page_key' => $page]);
        flash('success', 'Certificate deleted.');
        redirect(url($page, ['vessel' => $cert['vessel_id']]));
    }
    $renew = $act === 'renew';
    ship_require($page, $cert ? 'edit' : 'create');
    $d = [
        'vessel_id' => (int)pint('vessel_id'), 'cert_type' => mb_substr(trim((string)p('cert_type')), 0, 80), 'cert_no' => p('cert_no') ?: null, 'issuer' => p('issuer') ?: null,
        'issued_on' => pdate('issued_on'), 'expires_on' => pdate('expires_on'), 'is_critical' => p('is_critical') === '1' ? 1 : 0,
        'alert_days' => max(1, (int)p('alert_days', '30')), 'owner_user_id' => pint('owner_user_id'), 'notes' => p('notes') ?: null,
    ];
    if (!DB::value('SELECT 1 FROM shp_vessels WHERE id = :id', ['id' => $d['vessel_id']])) {
        fail('Choose the vessel.');
    }
    if ($d['cert_type'] === '') {
        fail('Enter the certificate.');
    }
    if ($d['issued_on'] && $d['expires_on'] && $d['expires_on'] <= $d['issued_on']) {
        fail('The expiry date comes after the date of issue.');
    }
    if ($renew) {
        if (!$d['expires_on'] || ($cert['expires_on'] && $d['expires_on'] <= $cert['expires_on'])) {
            fail('A renewal has a new expiry date later than ' . fdate($cert['expires_on']) . '.');
        }
        if (trim((string)$d['cert_no']) === '') {
            fail('Enter the number of the renewed certificate.');
        }
        $d['notes'] = mb_substr(trim('Renewed ' . fdate(Ship::today()) . ' (was ' . $cert['cert_no'] . ', expiry ' . fdate($cert['expires_on']) . '). ' . (string)$d['notes']), 0, 255);
    }
    $nid = DB::tx(function () use ($cert, $d, $renew, $page) {
        if ($cert) {
            DB::update('shp_certificates', $d, 'id = :id', ['id' => $cert['id']]);
            Ship::audit($renew ? 'renew' : 'edit', 'fleet', 'shp_certificate', (int)$cert['id'], $d['cert_type'] . ' ' . $d['cert_no'], ['changes' => Ship::changes($cert, $d), 'page_key' => $page]);
            $nid = (int)$cert['id'];
        } else {
            $nid = DB::insert('shp_certificates', $d);
            Ship::audit('create', 'fleet', 'shp_certificate', $nid, $d['cert_type'] . ' ' . $d['cert_no'], ['page_key' => $page]);
        }
        // an expired critical certificate holds the vessel; a renewed one lifts its own hold
        Holds::syncCertificates((int)$d['vessel_id']);
        return $nid;
    });
    $held = DB::value("SELECT 1 FROM shp_holds WHERE source_type = 'certificate' AND source_id = :c AND status = 'active'", ['c' => $nid]);
    flash($held ? 'warning' : 'success', $held ? 'Saved. The certificate is expired and critical: the vessel is on a no-sail hold until it is renewed.' : ($renew ? 'Certificate renewed.' : 'Certificate saved.'));
    redirect(url($page, ['vessel' => $d['vessel_id']]));
});

$canEdit = Access::can($page, 'edit');
$canCreate = Access::can($page, 'create');
$owners = DB::pairs("SELECT id, full_name FROM users WHERE status = 'active' AND is_platform = 0 ORDER BY full_name");

// ------------------------------------------------------------------ form
if (in_array($a, ['new', 'edit', 'renew'], true) || (is_post() && p('act') !== 'delete')) {
    $mode = is_post() ? (p('act') ?: ($cert ? 'edit' : 'new')) : $a;
    $e = $cert;
    ship_require($page, $e ? 'edit' : 'create');
    $renew = $mode === 'renew';
    $title = $renew ? 'Renew ' . $e['cert_type'] : ($e ? 'Edit ' . $e['cert_type'] : 'New vessel certificate');
    $val = function (string $k, $default = '') use ($e, $renew) {
        if ($renew && !is_post() && in_array($k, ['cert_no', 'issued_on', 'expires_on', 'notes'], true)) {
            if ($k === 'issued_on') {
                return Ship::today();
            }
            if ($k === 'expires_on' && $e['issued_on'] && $e['expires_on']) {
                $span = strtotime($e['expires_on']) - strtotime($e['issued_on']);
                return date('Y-m-d', strtotime(Ship::today()) + $span);
            }
            return '';
        }
        return fv($k, $e, $default);
    };
    layout_start($title, ['actions' => btn_link(url($page, ['vessel' => $e['vessel_id'] ?? $vessel]), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    $types = DB::column('SELECT DISTINCT cert_type FROM shp_certificates ORDER BY cert_type');
    ?>
    <form method="post" class="card reveal" style="max-width:920px"><?= csrf_field() ?><input type="hidden" name="act" value="<?= $renew ? 'renew' : 'save' ?>">
      <div class="card-body">
      <?php if ($renew): ?><p class="small text-muted">Enter the details of the new certificate. The old number and expiry are kept in the notes and the audit trail; a no-sail hold placed because this certificate expired is lifted once the new expiry is in the future.</p><?php endif; ?>
      <div class="row g-3">
        <?= select_field('vessel_id', 'Vessel', Ship::vesselOptions(), $val('vessel_id', $vessel), ['class' => 'col-md-4', 'blank' => '', 'required' => true]) ?>
        <div class="col-md-8"><label class="form-label">Certificate</label><input class="form-control form-control-sm" name="cert_type" list="certTypes" value="<?= h((string)$val('cert_type')) ?>" required <?= $renew ? 'readonly' : '' ?>>
          <datalist id="certTypes"><?php foreach ($types as $t): ?><option value="<?= h($t) ?>"><?php endforeach; ?></datalist></div>
        <?= input_field('cert_no', 'Certificate number', $val('cert_no'), ['class' => 'col-md-4', 'required' => $renew]) ?>
        <?= input_field('issuer', 'Issued by (MARINA, PCG, class society, NTC, insurer)', $val('issuer'), ['class' => 'col-md-8']) ?>
        <?= input_field('issued_on', 'Date of issue', $val('issued_on'), ['type' => 'date', 'class' => 'col-md-3']) ?>
        <?= input_field('expires_on', 'Expires on', $val('expires_on'), ['type' => 'date', 'class' => 'col-md-3', 'required' => $renew]) ?>
        <?= input_field('alert_days', 'Remind this many days before', $val('alert_days', 30), ['type' => 'number', 'step' => '1', 'class' => 'col-md-3']) ?>
        <?= select_field('owner_user_id', 'Who renews it', $owners, $val('owner_user_id'), ['class' => 'col-md-3', 'blank' => '']) ?>
        <?= check_field('is_critical', 'Critical: the vessel may not sail when it is expired', (int)$val('is_critical', 1) === 1, 'col-md-12') ?>
        <?= input_field('notes', 'Notes', $val('notes'), ['class' => 'col-12']) ?>
      </div></div>
      <div class="card-footer bg-transparent"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> <?= $renew ? 'Save the renewal' : 'Save certificate' ?></button></div>
    </form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
Holds::syncCertificates($vessel ?: null);
$tab = (string)g('tab', 'all');
$where = '1 = 1';
$par = [];
if ($vessel) {
    $where .= ' AND c.vessel_id = :v';
    $par['v'] = $vessel;
}
$all = DB::all("SELECT c.*, s.name AS vessel_name, s.code AS vessel_code, u.full_name AS owner_name,
                       (SELECT h.id FROM shp_holds h WHERE h.source_type = 'certificate' AND h.source_id = c.id AND h.status = 'active' LIMIT 1) AS hold_id
                  FROM shp_certificates c JOIN shp_vessels s ON s.id = c.vessel_id LEFT JOIN users u ON u.id = c.owner_user_id
                 WHERE $where ORDER BY c.expires_on IS NULL, c.expires_on, s.code", $par);
$counts = ['all' => count($all), 'due' => 0, 'expired' => 0, 'soon' => 0, 'valid' => 0];
foreach ($all as &$c) {
    $c['state'] = cert_state($c);
    $k = $c['state'][0];
    if (isset($counts[$k])) {
        $counts[$k]++;
    }
}
unset($c);
$rows = $tab === 'all' ? $all : array_values(array_filter($all, fn($c) => $c['state'][0] === $tab));
$critExpired = count(array_filter($all, fn($c) => $c['state'][0] === 'expired' && (int)$c['is_critical'] === 1));

$actions = $canCreate ? btn_link(url($page, ['a' => 'new', 'vessel' => $vessel]), 'Add certificate', 'btn-primary', 'plus-lg') . ' ' : '';
$actions .= Access::page('rpt.shp_cert_expiry') ? btn_link(url('rpt.shp_cert_expiry'), 'Expiry report', 'btn-outline-secondary', 'bar-chart') : '';
layout_start('Vessel Certificates', ['actions' => $actions]);
echo '<div class="kpi-grid mb-3">'
    . kpi_tile('Certificates', (string)$counts['all'], 'patch-check', $vessel ? 'For this vessel' : 'Across the fleet', '', null, (float)$counts['all'])
    . kpi_tile('Renew now', (string)$counts['due'], 'alarm', 'Inside their reminder period', $counts['due'] ? 'warn' : 'good', url($page, ['tab' => 'due', 'vessel' => $vessel]), (float)$counts['due'])
    . kpi_tile('Due within 90 days', (string)$counts['soon'], 'calendar-event', 'Plan the survey or renewal', '', url($page, ['tab' => 'soon', 'vessel' => $vessel]), (float)$counts['soon'])
    . kpi_tile('Expired', (string)$counts['expired'], 'x-octagon', $critExpired ? $critExpired . ' critical: the vessel is held' : 'None critical', $counts['expired'] ? 'bad' : 'good', url($page, ['tab' => 'expired', 'vessel' => $vessel]), (float)$counts['expired'])
    . '</div>';
$tabs = [];
foreach (['all' => 'All', 'due' => 'Renew now', 'soon' => 'Within 90 days', 'expired' => 'Expired', 'valid' => 'Valid'] as $k => $l) {
    $tabs[$k] = [$l, url($page, array_filter(['tab' => $k, 'vessel' => $vessel])), $counts[$k]];
}
?>
<form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
  <?= select_field('vessel', 'Vessel', Ship::vesselOptions(false), $vessel, ['class' => '', 'blank' => 'Every vessel', 'extra' => 'data-autosubmit']) ?>
</form>
<?= tabs_line($tabs, $tab) ?>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>Vessel</th><th>Certificate</th><th>Number</th><th>Issued by</th><th>Issued</th><th>Expires</th><th style="min-width:150px">Time left</th><th>Status</th><th>Renewed by</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $c):
      [$st, $label, $days] = $c['state'];
      $span = $c['issued_on'] && $c['expires_on'] ? max(1, (strtotime($c['expires_on']) - strtotime($c['issued_on'])) / 86400) : 365;
      $pct = $days === null ? 100 : max(0, min(100, 100 * $days / $span));
      $tone = ['expired' => 'bad', 'due' => 'warn', 'soon' => 'warn', 'valid' => 'good', 'none' => 'muted'][$st]; ?>
    <tr><td class="fw-semibold text-nowrap"><?= h($c['vessel_code']) ?> <span class="fw-normal text-muted small"><?= h($c['vessel_name']) ?></span></td>
      <td><?= h($c['cert_type']) ?><?= (int)$c['is_critical'] ? '<div class="small text-muted">Critical for sailing</div>' : '' ?></td>
      <td class="small"><?= h((string)$c['cert_no']) ?></td><td class="small"><?= h((string)$c['issuer']) ?></td>
      <td class="small text-nowrap"><?= h(fdate($c['issued_on'])) ?></td><td class="small text-nowrap fw-semibold"><?= h(fdate($c['expires_on'])) ?></td>
      <td><?php if ($days !== null): ?><div class="small"><?= $days < 0 ? abs($days) . ' day(s) ago' : $days . ' day(s)' ?></div><div class="exp-bar <?= $tone === 'good' ? '' : $tone ?>"><span style="width:<?= $st === 'expired' ? 100 : round($pct, 1) ?>%"></span></div><?php endif; ?></td>
      <td><span class="st st-<?= $tone ?>"><?= h($label) ?></span><?= $c['hold_id'] ? '<div class="small text-danger">No-sail hold</div>' : '' ?></td>
      <td class="small"><?= h((string)$c['owner_name']) ?></td>
      <td class="text-nowrap text-end"><?php if ($canEdit): ?>
        <?php if ($c['expires_on'] && in_array($st, ['expired', 'due', 'soon'], true)): ?><a class="btn btn-sm btn-outline-primary" href="<?= h(url($page, ['a' => 'renew', 'id' => $c['id']])) ?>">Renew</a><?php endif; ?>
        <a class="btn btn-sm btn-link" href="<?= h(url($page, ['a' => 'edit', 'id' => $c['id']])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
      <?php endif; ?>
      <?php if (Access::can($page, 'delete') && !$c['hold_id']): ?><form method="post" action="<?= h(url($page, ['id' => $c['id']])) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><button class="btn btn-sm btn-link text-danger" title="Delete" data-confirm="Delete this certificate record?"><i class="bi bi-trash"></i></button></form><?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="10"><?= empty_note('No certificate here.', 'patch-check') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<p class="small text-muted mt-2">An expired critical certificate puts its own no-sail hold on the vessel, so no voyage of it can be released for departure. Renewing the certificate lifts that hold.</p>
<?php
layout_end();
