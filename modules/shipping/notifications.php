<?php
declare(strict_types=1);

$page = 'care.notifications';
$canRetry = Access::can($page, 'edit') || Access::can($page, 'create');
$channels = ['outbound' => 'E-mail and SMS', 'email' => 'E-mail', 'sms' => 'SMS', 'in_app' => 'In-app (staff)'];
$statusNames = ['queued' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'failed' => 'Failed', 'no_provider' => 'No provider', 'read' => 'Read'];

if (!function_exists('notif_mask')) {
    function notif_mask(string $channel, string $to): string
    {
        if (Ship::canPii() || $channel === 'in_app') {
            return $channel === 'in_app' && str_starts_with($to, 'user:') ? Ship::userName((int)substr($to, 5)) : $to;
        }
        if ($channel === 'sms') {
            return Ship::maskPhone($to);
        }
        [$user, $domain] = array_pad(explode('@', $to, 2), 2, '');
        return mb_substr($user, 0, 1) . str_repeat('*', max(2, mb_strlen($user) - 1)) . ($domain !== '' ? '@' . $domain : '');
    }
}

handle_post(function () use ($page, $canRetry) {
    if (!$canRetry) {
        Access::denyPage($page, 'retry notices on');
    }
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    if (p('act') === 'retry_waiting') {
        $ids = array_map('intval', DB::column("SELECT id FROM shp_notifications WHERE channel IN ('email', 'sms') AND status IN ('no_provider', 'failed', 'queued') ORDER BY id DESC LIMIT 500"));
    }
    if (!$ids) {
        fail('Tick the notices to send again.');
    }
    $n = Notify::retry($ids);
    $email = Ship::setting('email_provider');
    $sms = Ship::setting('sms_provider');
    flash($email === '' && $sms === '' ? 'warning' : 'success', $n . ' notice(s) tried again.' . ($email === '' && $sms === '' ? ' No e-mail or SMS provider is connected yet, so they are still waiting.' : ''));
    $keep = array_filter(array_map(fn($v) => is_string($v) ? $v : '', array_intersect_key((array)($_POST['keep'] ?? []), array_flip(['channel', 'status', 'type', 'from', 'to', 'q']))), fn($v) => $v !== '');
    redirect(url($page, $keep));
});

$channel = (string)g('channel', 'outbound');
$status = (string)g('status');
$type = (string)g('type');
$q = trim((string)g('q'));
$from = gdate('from', date('Y-m-d', strtotime(Ship::today() . ' -7 days')));
$to = gdate('to', Ship::today());
$where = 'n.created_at >= :f AND n.created_at < :t';
$par = ['f' => $from . ' 00:00:00', 't' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
if ($channel === 'outbound') {
    $where .= " AND n.channel IN ('email', 'sms')";
} elseif (isset($channels[$channel])) {
    $where .= ' AND n.channel = :c';
    $par['c'] = $channel;
}
if (isset($statusNames[$status])) {
    $where .= ' AND n.status = :st';
    $par['st'] = $status;
}
if ($type !== '') {
    $where .= ' AND n.event_type = :ty';
    $par['ty'] = $type;
}
if ($q !== '') {
    $where .= ' AND (n.recipient LIKE :q OR n.subject LIKE :q2)';
    $par += ['q' => "%$q%", 'q2' => "%$q%"];
}
$total = (int)DB::value("SELECT COUNT(*) FROM shp_notifications n WHERE $where", $par);
$per = 100;
$pages = max(1, (int)ceil($total / $per));
$pg = min($pages, max(1, (int)g('pg', '1')));
$rows = DB::all("SELECT n.* FROM shp_notifications n WHERE $where ORDER BY n.id DESC LIMIT $per OFFSET " . (($pg - 1) * $per), $par);
$pgUrl = fn(int $n) => url($page, array_filter(['channel' => $channel, 'status' => $status, 'type' => $type, 'from' => $from, 'to' => $to, 'q' => $q, 'pg' => $n], fn($v) => $v !== '' && $v !== null));
$byStatus = DB::pairs("SELECT status, COUNT(*) FROM shp_notifications WHERE channel IN ('email', 'sms') GROUP BY status");
$types = DB::column('SELECT DISTINCT event_type FROM shp_notifications ORDER BY event_type');
$emailProv = Ship::setting('email_provider');
$smsProv = Ship::setting('sms_provider');
$waiting = (int)($byStatus['no_provider'] ?? 0) + (int)($byStatus['failed'] ?? 0) + (int)($byStatus['queued'] ?? 0);
$actions = Access::page('shp.settings') ? btn_link(url('shp.settings'), 'Providers and policies', 'btn-outline-secondary', 'plug') : '';
layout_start('Notifications & Notices', ['actions' => $actions]);
?>
<?php if ($emailProv === '' || $smsProv === ''): ?>
<div class="alert alert-warning small d-flex gap-2"><i class="bi bi-plug"></i><div>
  <?= $emailProv === '' && $smsProv === '' ? 'No e-mail or SMS provider is connected.' : ($emailProv === '' ? 'No e-mail provider is connected.' : 'No SMS provider is connected.') ?>
  Booking confirmations, schedule changes, cancellations and refund notices are still written here, one per event and recipient, with the status <b>No provider</b>, so nothing is ever shown as sent when it was not.
  Once a provider is set in Shipping Policies, use <b>Send the waiting ones</b> to try them again. In-app notices to staff work without a provider.</div></div>
<?php endif; ?>
<div class="kpi-grid mb-3">
  <?= kpi_tile('E-mail provider', $emailProv !== '' ? $emailProv : 'None', 'envelope', $emailProv !== '' ? 'Messages go out through it' : 'Not connected; e-mails wait here', $emailProv !== '' ? 'good' : 'warn') ?>
  <?= kpi_tile('SMS provider', $smsProv !== '' ? $smsProv : 'None', 'phone', $smsProv !== '' ? 'Messages go out through it' : 'Not connected; texts wait here', $smsProv !== '' ? 'good' : 'warn') ?>
  <?= kpi_tile('Waiting to go out', number_format($waiting), 'hourglass-split', 'E-mail and SMS, all dates', $waiting ? 'warn' : 'good', null, (float)$waiting) ?>
  <?= kpi_tile('Sent or delivered', number_format((int)($byStatus['sent'] ?? 0) + (int)($byStatus['delivered'] ?? 0)), 'send-check', 'Confirmed by a provider', '', null) ?>
</div>
<form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>">
  <?= select_field('channel', 'Channel', $channels, $channel, ['class' => '', 'blank' => 'Every channel']) ?>
  <?= select_field('status', 'Status', $statusNames, $status, ['class' => '', 'blank' => 'Any status']) ?>
  <?= select_field('type', 'Event', array_combine($types, array_map(fn($t) => ucfirst(str_replace('_', ' ', $t)), $types)) ?: [], $type, ['class' => '', 'blank' => 'Every event']) ?>
  <?= input_field('from', 'From', $from, ['type' => 'date', 'class' => '']) ?><?= input_field('to', 'To', $to, ['type' => 'date', 'class' => '']) ?>
  <?= input_field('q', 'Recipient or subject', $q, ['class' => '']) ?>
  <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Show</button></div>
</form>
<form method="post"><?= csrf_field() ?>
<?php foreach (['channel' => $channel, 'status' => $status, 'type' => $type, 'from' => $from, 'to' => $to, 'q' => $q] as $k => $val): ?><input type="hidden" name="keep[<?= h($k) ?>]" value="<?= h((string)$val) ?>"><?php endforeach; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <div class="small text-muted d-flex align-items-center gap-2"><?= number_format($total) ?> notice(s)<?= $pages > 1 ? ', page ' . $pg . ' of ' . $pages : '' ?>
    <?php if ($pages > 1): ?><span class="btn-group btn-group-sm"><a class="btn btn-outline-secondary <?= $pg <= 1 ? 'disabled' : '' ?>" href="<?= h($pgUrl($pg - 1)) ?>" aria-label="Newer"><i class="bi bi-chevron-left"></i></a><a class="btn btn-outline-secondary <?= $pg >= $pages ? 'disabled' : '' ?>" href="<?= h($pgUrl($pg + 1)) ?>" aria-label="Older"><i class="bi bi-chevron-right"></i></a></span><?php endif; ?></div>
  <?php if ($canRetry): ?><div class="d-flex gap-2">
    <button class="btn btn-sm btn-outline-primary" name="act" value="retry"><i class="bi bi-arrow-repeat"></i> Try the ticked again</button>
    <button class="btn btn-sm btn-primary" name="act" value="retry_waiting" data-confirm="Try every waiting e-mail and SMS again (up to 500)?"><i class="bi bi-send"></i> Send the waiting ones</button></div><?php endif; ?>
</div>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><?php if ($canRetry): ?><th style="width:32px"></th><?php endif; ?><th>When</th><th>Channel</th><th>To</th><th>Message</th><th>Event</th><th>Status</th><th class="num">Tries</th></tr></thead><tbody>
  <?php foreach ($rows as $n): $retry = $n['channel'] !== 'in_app' && in_array($n['status'], ['no_provider', 'failed', 'queued'], true); ?>
    <tr><?php if ($canRetry): ?><td><?php if ($retry): ?><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$n['id'] ?>" aria-label="Tick notice <?= (int)$n['id'] ?>"><?php endif; ?></td><?php endif; ?>
      <td class="small text-nowrap"><?= h(fdatetime($n['created_at'])) ?></td>
      <td class="text-nowrap"><i class="bi bi-<?= ['email' => 'envelope', 'sms' => 'phone', 'in_app' => 'bell'][$n['channel']] ?? 'send' ?> text-muted"></i> <?= h(['email' => 'E-mail', 'sms' => 'SMS', 'in_app' => 'In-app'][$n['channel']] ?? $n['channel']) ?></td>
      <td class="small"><?= h(notif_mask($n['channel'], (string)$n['recipient'])) ?><div class="text-muted"><?= h(ucfirst(str_replace('_', ' ', $n['recipient_type']))) ?></div></td>
      <td class="small"><b><?= h($n['subject']) ?></b><div class="text-muted" style="max-width:420px"><?= h(mb_strimwidth((string)$n['body'], 0, 160, '...')) ?></div></td>
      <td class="small"><?= h(ucfirst(str_replace('_', ' ', $n['event_type']))) ?></td>
      <td><?= Ship::label($n['status'], $statusNames) ?><?= $n['provider_status'] ? '<div class="small text-muted" style="max-width:220px">' . h($n['provider_status']) . '</div>' : '' ?></td>
      <td class="num"><?= (int)$n['attempts'] ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8"><?= empty_note('No notice matches.', 'send') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
</form>
<?php
layout_end();
