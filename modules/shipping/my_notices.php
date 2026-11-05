<?php
declare(strict_types=1);

$page = 'home.notices';
$me = (int)Auth::id();

if (!function_exists('notice_target')) {
    /** The page that shows the record a notice is about, or null when there is none (or the user may not open it) */
    function notice_target(?string $table, ?int $refId): ?string
    {
        if (!$table || !$refId) {
            return null;
        }
        [$key, $params] = match ($table) {
            'shp_voyages'        => ['ops.voyages', ['id' => $refId]],
            'shp_cases'          => ['care.cases', ['id' => $refId]],
            'shp_approvals'      => ['ops.approvals', ['id' => $refId, 'tab' => 'all']],
            'shp_holds'          => ['ops.holds', []],
            'shp_refunds'        => ['tkt.refunds', ['tab' => 'approved']],
            'shp_cashier_shifts' => ['tkt.cashier', ['id' => $refId]],
            'shp_fuel_logs'      => ['ops.fuel', []],
            'shp_work_orders'    => ['fleet.workorders', ['id' => $refId]],
            'shp_defects'        => ['fleet.defects', ['id' => $refId]],
            'shp_certificates'   => ['fleet.certificates', []],
            'shp_requisitions'   => ['fleet.requisitions', ['id' => $refId]],
            'shp_drydock'        => ['fleet.drydock', ['id' => $refId]],
            'shp_pm_plans'       => ['fleet.pm', []],
            'shp_cargo'          => ['cgo.shipments', ['id' => $refId]],
            'shp_bookings'       => ['tkt.bookings', ['id' => $refId]],
            'shp_tickets'        => ['tkt.bookings', ['id' => (int)DB::value('SELECT booking_id FROM shp_tickets WHERE id = :t', ['t' => $refId])]],
            'shp_crew'           => ['ops.crew', ['id' => $refId]],
            'shp_compliance'     => ['shp.compliance', []],
            default              => [null, []],
        };
        return $key && Access::page($key) ? url($key, $params) : null;
    }
}

// opening a notice from the bell marks it read and goes to its record
if (($rid = gint('read')) !== null) {
    $n = DB::row("SELECT * FROM shp_notifications WHERE id = :id AND user_id = :u AND channel = 'in_app'", ['id' => $rid, 'u' => $me]);
    if ($n) {
        Notify::markRead($me, $rid);
        $to = notice_target($n['ref_table'], $n['ref_id'] !== null ? (int)$n['ref_id'] : null);
        if ($to) {
            redirect($to);
        }
    }
    redirect(url($page, ['open' => $rid]));
}

handle_post(function () use ($page, $me) {
    if (p('act') === 'all_read') {
        Notify::markRead($me);
        flash('success', 'Every notice is marked as read.');
    } elseif (p('act') === 'read') {
        Notify::markRead($me, (int)pint('id'));
    }
    redirect(url($page, ['show' => g('show', 'all')]));
});

$show = (string)g('show', 'all') === 'unread' ? 'unread' : 'all';
$open = gint('open');
$unread = Notify::unreadCount($me);
$rows = DB::all("SELECT * FROM shp_notifications WHERE user_id = :u AND channel = 'in_app'" . ($show === 'unread' ? ' AND read_at IS NULL' : '') . ' ORDER BY read_at IS NULL DESC, id DESC LIMIT 150', ['u' => $me]);
$icons = ['approval' => 'patch-check', 'safety_hold' => 'sign-stop', 'defect' => 'wrench-adjustable', 'case_new' => 'headset', 'case_assigned' => 'person-check', 'case_escalated' => 'alarm',
    'cash_variance' => 'cash-coin', 'cargo_exception' => 'box-seam', 'boarding_open' => 'door-open', 'fuel_variance' => 'fuel-pump', 'voyage_cancelled' => 'x-octagon',
    'schedule_change' => 'clock-history', 'wo_assigned' => 'tools', 'cert_expiry' => 'award', 'pm_due' => 'calendar-check'];
$actions = $unread ? '<form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="act" value="all_read"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2-all"></i> Mark all as read</button></form>' : '';
layout_start('My Notices', ['actions' => $actions]);
echo tabs_line(['all' => ['All', url($page), null], 'unread' => ['Unread', url($page, ['show' => 'unread']), $unread]], $show);
?>
<div class="card reveal"><div class="notice-list">
<?php foreach ($rows as $n):
    $to = notice_target($n['ref_table'], $n['ref_id'] !== null ? (int)$n['ref_id'] : null);
    $isNew = $n['read_at'] === null; ?>
  <div class="notice-row <?= $isNew ? 'unread' : '' ?> <?= $open === (int)$n['id'] ? 'focus' : '' ?>">
    <span class="n-ico"><i class="bi bi-<?= h($icons[$n['event_type']] ?? 'bell') ?>"></i></span>
    <div class="flex-fill min-w-0">
      <div class="d-flex flex-wrap justify-content-between gap-2"><b><?= h($n['subject']) ?></b><span class="small text-muted text-nowrap"><?= h(fdatetime($n['created_at'])) ?></span></div>
      <div class="small text-body-secondary"><?= h($n['body']) ?></div>
      <div class="small mt-1 d-flex gap-3">
        <?php if ($to): ?><a href="<?= h(url($page, ['read' => $n['id']])) ?>">Open<i class="bi bi-arrow-right ms-1"></i></a><?php endif; ?>
        <?php if ($isNew): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="act" value="read"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn btn-link btn-sm p-0">Mark as read</button></form>
        <?php else: ?><span class="text-muted">Read <?= h(fdatetime($n['read_at'])) ?></span><?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$rows): ?><?= empty_note($show === 'unread' ? 'You have read everything.' : 'No notice yet. Approvals, holds, cases and voyage changes for your role appear here.', 'bell') ?><?php endif; ?>
</div></div>
<?php if (count($rows) === 150): ?><p class="small text-muted mt-2">Showing the latest 150 notices.</p><?php endif; ?>
<?php
layout_end();
