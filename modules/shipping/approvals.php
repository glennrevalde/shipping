<?php
declare(strict_types=1);

$page = 'ops.approvals';
$tab = (string)g('tab', 'pending');
$focus = gint('id');
$me = (int)Auth::id();

if (!function_exists('approvals_record_link')) {
    /** Where the record behind an approval request is opened */
    function approvals_record_link(array $a): string
    {
        $ref = (string)($a['ref_no'] ?: '#' . $a['ref_id']);
        $id = (int)$a['ref_id'];
        return match ($a['approval_type']) {
            'refund'             => safe_link('tkt.refunds', ['tab' => $a['status'] === 'pending' ? 'requested' : 'approved'], $ref),
            'compensation'       => safe_link('care.cases', ['id' => $id], $ref),
            'departure_override' => safe_link('ops.voyages', ['id' => $id, 'tab' => 'readiness'], $ref),
            'fuel_adjustment'    => safe_link('ops.fuel', ['vessel' => (int)DB::value('SELECT vessel_id FROM shp_fuel_logs WHERE id = :i', ['i' => $id])], $ref),
            'wo_deferral'        => safe_link('fleet.workorders', ['id' => $id], $ref),
            'requisition'        => safe_link('fleet.requisitions', ['id' => $id], $ref),
            'fare_change'        => safe_link('shp.fares', [], $ref),
            default              => h($ref),
        };
    }

    function approvals_age(string $at): string
    {
        $m = max(0, Ship::minutesBetween($at, Ship::now()));
        return $m < 60 ? $m . ' min' : ($m < 1440 ? floor($m / 60) . ' h' : floor($m / 1440) . ' d ' . floor(($m % 1440) / 60) . ' h');
    }
}

handle_post(function () use ($page) {
    $id = (int)pint('approval_id');
    $decision = (string)p('decision');
    if (!in_array($decision, ['approve', 'reject'], true)) {
        fail('Choose approve or reject.');
    }
    $a = DB::row('SELECT * FROM shp_approvals WHERE id = :id', ['id' => $id]);
    if (!$a) {
        fail('The request was not found.');
    }
    ShipApproval::decide($id, $decision === 'approve', (string)p('notes'));
    $label = ShipApproval::TYPES[$a['approval_type']][0] ?? 'Request';
    flash('success', $label . ' ' . ($a['ref_no'] ?? '') . ($decision === 'approve' ? ' approved and carried out.' : ' rejected; the requester was told.'));
    redirect(url($page));
});

$pending = DB::all("SELECT a.*, u.full_name requester FROM shp_approvals a LEFT JOIN users u ON u.id = a.requested_by WHERE a.status = 'pending' ORDER BY a.requested_at");
$mineToDecide = [];
$others = [];
foreach ($pending as $a) {
    [$ok, $why] = ShipApproval::canDecide($a);
    $a['_ok'] = $ok;
    $a['_why'] = $why;
    // above the user's limit: they may still reject it, and the reason the approval is not theirs is shown
    $a['_reject'] = $ok || ShipApproval::canDecide($a, null, false)[0];
    if ($a['_reject']) {
        $mineToDecide[] = $a;
    } else {
        $others[] = $a;
    }
}
$myRequests = (int)DB::value("SELECT COUNT(*) FROM shp_approvals WHERE requested_by = :u AND status = 'pending'", ['u' => $me]);
$tabs = [
    'pending' => ['For my decision', url($page), count($mineToDecide)],
    'all'     => ['All waiting', url($page, ['tab' => 'all']), count($pending)],
    'history' => ['Decided', url($page, ['tab' => 'history']), null],
];
if (!isset($tabs[$tab])) {
    $tab = 'pending';
}
$limit = Access::special('shp_approve_limit');
$delegs = DB::all('SELECT d.*, u.full_name from_name FROM shp_delegations d JOIN users u ON u.id = d.from_user_id WHERE d.to_user_id = :u AND d.is_active = 1 AND d.valid_to >= :d ORDER BY d.valid_from', ['u' => $me, 'd' => Ship::today()]);
layout_start('Approvals Queue', ['actions' => Access::page('shp.delegations') ? btn_link(url('shp.delegations'), 'Delegations', 'btn-outline-secondary', 'person-up') : '']);
?>
<div class="kpi-grid mb-3">
  <?= kpi_tile('Waiting for you', (string)count($mineToDecide), 'patch-check', 'You may decide these', count($mineToDecide) ? 'warn' : 'good', null, (float)count($mineToDecide)) ?>
  <?= kpi_tile('Waiting in total', (string)count($pending), 'hourglass-split', $myRequests ? $myRequests . ' of them asked by you' : 'Across the company', '', url($page, ['tab' => 'all'])) ?>
  <?= kpi_tile('Your approval limit', $limit === null ? 'No limit' : ((float)$limit > 0 ? '₱' . money($limit) : 'None'), 'shield-check', 'Amounts above it go to someone with a higher limit') ?>
  <?= kpi_tile('Delegated to you', (string)count($delegs), 'person-up', $delegs ? 'From ' . implode(', ', array_unique(array_column($delegs, 'from_name'))) : 'No active delegation') ?>
</div>
<?= tabs_line($tabs, $tab) ?>
<?php if ($tab !== 'history'):
    $list = $tab === 'pending' ? $mineToDecide : array_merge($mineToDecide, $others); ?>
  <p class="small text-muted">Maker and checker are always two people: you cannot decide a request you made, and a rejection needs its reason. Approving carries the decision out at once (the refund is credited, the override is recorded, the stock is adjusted).</p>
  <div class="row g-3">
  <?php foreach ($list as $a):
      [$label] = ShipApproval::TYPES[$a['approval_type']] ?? [ucfirst($a['approval_type'])]; ?>
    <div class="col-xl-6"><div class="card reveal h-100 <?= $focus === (int)$a['id'] ? 'border-primary' : '' ?>" id="ap<?= (int)$a['id'] ?>"><div class="card-body">
      <div class="d-flex justify-content-between gap-2 flex-wrap">
        <div><div class="fw-bold"><?= h($label) ?> <?= approvals_record_link($a) ?></div>
          <div class="small text-muted">Asked by <?= h((string)$a['requester']) ?>, <?= h(fdatetime($a['requested_at'])) ?> (<?= h(approvals_age($a['requested_at'])) ?> ago)</div></div>
        <?php if ($a['amount'] !== null): ?><div class="text-end"><div class="small text-muted">Amount</div><div class="fw-bold fs-5">₱<?= money($a['amount']) ?></div></div><?php endif; ?>
      </div>
      <p class="small mt-2 mb-2"><?= h((string)$a['summary']) ?></p>
      <?php if ($a['_reject']): ?>
        <?php if ($a['_why']): ?><div class="small <?= $a['_ok'] ? 'text-info' : 'text-warning-emphasis' ?> mb-1"><i class="bi bi-<?= $a['_ok'] ? 'person-up' : 'lock' ?>"></i> <?= h($a['_why']) ?><?= $a['_ok'] ? '' : ' You may still reject it.' ?></div><?php endif; ?>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-start"><?= csrf_field() ?><input type="hidden" name="approval_id" value="<?= (int)$a['id'] ?>">
          <input class="form-control form-control-sm flex-fill" style="min-width:200px" name="notes" placeholder="Notes (needed to reject)" aria-label="Decision notes">
          <?php if ($a['_ok']): ?><button class="btn btn-sm btn-primary" name="decision" value="approve" data-confirm="Approve <?= h($label . ' ' . $a['ref_no']) ?>?"><i class="bi bi-check-lg"></i> Approve</button><?php endif; ?>
          <button class="btn btn-sm btn-outline-danger" name="decision" value="reject"><i class="bi bi-x-lg"></i> Reject</button>
        </form>
      <?php else: ?>
        <div class="small text-muted"><i class="bi bi-lock"></i> <?= h($a['_why']) ?></div>
      <?php endif; ?>
    </div></div></div>
  <?php endforeach; ?>
  <?php if (!$list): ?><div class="col-12"><?= empty_note($tab === 'pending' ? 'Nothing is waiting for your decision.' : 'No request is waiting.', 'patch-check') ?></div><?php endif; ?>
  </div>
<?php else:
    $type = (string)g('type');
    $from = gdate('from', date('Y-m-d', strtotime(Ship::today() . ' -30 days')));
    $to = gdate('to', Ship::today());
    $where = "a.status <> 'pending' AND DATE(a.decided_at) BETWEEN :f AND :t";
    $par = ['f' => $from, 't' => $to];
    if (isset(ShipApproval::TYPES[$type])) {
        $where .= ' AND a.approval_type = :ty';
        $par['ty'] = $type;
    }
    $rows = DB::all("SELECT a.*, ru.full_name requester, du.full_name decider FROM shp_approvals a LEFT JOIN users ru ON ru.id = a.requested_by LEFT JOIN users du ON du.id = a.decided_by
                      WHERE $where ORDER BY a.decided_at DESC LIMIT 300", $par); ?>
  <form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>"><input type="hidden" name="tab" value="history">
    <?= input_field('from', 'Decided from', $from, ['type' => 'date', 'class' => '']) ?><?= input_field('to', 'To', $to, ['type' => 'date', 'class' => '']) ?>
    <?= select_field('type', 'Kind', array_map(fn($t) => $t[0], ShipApproval::TYPES), $type, ['class' => '', 'blank' => 'Every kind']) ?>
    <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Show</button></div>
  </form>
  <div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Kind</th><th>Record</th><th>Summary</th><th class="num">Amount</th><th>Asked by</th><th>Decision</th><th>Decided by</th><th>Notes</th></tr></thead><tbody>
    <?php foreach ($rows as $a): ?>
      <tr><td><?= h(ShipApproval::TYPES[$a['approval_type']][0] ?? $a['approval_type']) ?></td><td><?= approvals_record_link($a) ?></td><td class="small"><?= h((string)$a['summary']) ?></td>
        <td class="num"><?= $a['amount'] !== null ? money($a['amount']) : '' ?></td><td class="small"><?= h((string)$a['requester']) ?><div class="text-muted"><?= h(fdatetime($a['requested_at'])) ?></div></td>
        <td><?= Ship::label($a['status']) ?></td><td class="small"><?= h((string)$a['decider']) ?><?= $a['decided_for'] ? '<div class="text-muted">for ' . h(Ship::userName((int)$a['decided_for'])) . '</div>' : '' ?><div class="text-muted"><?= h(fdatetime($a['decided_at'])) ?></div></td>
        <td class="small"><?= h((string)$a['decision_notes']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8"><?= empty_note('Nothing decided in these dates.', 'patch-check') ?></td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php endif; ?>
<?php if ($focus): ?><script>var f = document.getElementById('ap<?= (int)$focus ?>'); if (f) { f.scrollIntoView({block: 'center'}); }</script><?php endif; ?>
<?php
layout_end();
