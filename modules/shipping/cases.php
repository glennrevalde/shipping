<?php
declare(strict_types=1);

$page = 'care.cases';
$id = gint('id');
$me = (int)Auth::id();
$canOpen = Access::can($page, 'create');
$canEdit = Access::can($page, 'edit');
$canNote = $canOpen || $canEdit;
$statusNames = ['open' => 'Open', 'in_progress' => 'In progress', 'waiting' => 'Waiting on customer', 'resolved' => 'Resolved', 'closed' => 'Closed'];
$channelNames = ['desk' => 'Ticket counter', 'phone' => 'Phone', 'email' => 'E-mail', 'portal' => 'Passenger portal', 'social' => 'Social media', 'onboard' => 'On board'];
$prioNames = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

if (!function_exists('cases_owners')) {
    /** Users who can work cases (to assign them) */
    function cases_owners(): array
    {
        $out = [];
        foreach (DB::all("SELECT id, full_name FROM users WHERE status = 'active' AND is_platform = 0 ORDER BY full_name") as $u) {
            if (Access::can('care.cases', 'edit', (int)$u['id'])) {
                $out[(int)$u['id']] = $u['full_name'];
            }
        }
        return $out;
    }

    function cases_sla(array $c): string
    {
        if (in_array($c['status'], ['resolved', 'closed'], true)) {
            $late = $c['resolved_at'] && $c['resolved_at'] > $c['sla_due_at'];
            return '<span class="st st-' . ($late ? 'warn' : 'good') . '">' . ($late ? 'Resolved late' : 'Within service time') . '</span>';
        }
        $mins = Ship::minutesBetween(Ship::now(), $c['sla_due_at']);
        if ($mins < 0) {
            return '<span class="st st-bad st-live">Late by ' . h(cases_span(-$mins)) . '</span>';
        }
        return '<span class="st st-' . ($mins < 240 ? 'warn' : 'info') . '">Due in ' . h(cases_span($mins)) . '</span>';
    }

    function cases_span(int $m): string
    {
        return $m < 60 ? $m . ' min' : ($m < 2880 ? round($m / 60) . ' h' : round($m / 1440) . ' days');
    }
}

handle_post(function () use ($page, $canOpen, $canEdit, $canNote, $me, $prioNames, $channelNames) {
    $act = (string)p('act');
    if ($act === 'open') {
        if (!$canOpen) {
            Access::denyPage($page, 'create');
        }
        $bookingId = null;
        $ticketId = null;
        $cargoId = null;
        if (($ref = strtoupper(trim((string)p('booking_ref')))) !== '') {
            $bookingId = (int)DB::value('SELECT id FROM shp_bookings WHERE booking_ref = :r', ['r' => $ref]) ?: fail('No booking ' . $ref . ' was found.');
        }
        if (($tn = strtoupper(trim((string)p('ticket_no')))) !== '') {
            $t = DB::row('SELECT id, booking_id, voyage_id FROM shp_tickets WHERE ticket_no = :n', ['n' => $tn]) ?: fail('No ticket ' . $tn . ' was found.');
            $ticketId = (int)$t['id'];
            $bookingId = $bookingId ?: (int)$t['booking_id'];
        }
        if (($wb = strtoupper(trim((string)p('waybill_no')))) !== '') {
            $cargoId = (int)DB::value('SELECT id FROM shp_cargo WHERE waybill_no = :w', ['w' => $wb]) ?: fail('No waybill ' . $wb . ' was found.');
        }
        $voyageId = pint('voyage_id');
        if (!$voyageId && $ticketId) {
            $voyageId = (int)DB::value('SELECT voyage_id FROM shp_tickets WHERE id = :t', ['t' => $ticketId]) ?: null;
        }
        if (!$voyageId && $cargoId) {
            $voyageId = (int)DB::value('SELECT voyage_id FROM shp_cargo WHERE id = :c', ['c' => $cargoId]) ?: null;
        }
        $email = trim((string)p('customer_email'));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail('The e-mail address does not look right.');
        }
        $cid = DB::tx(fn() => Care::open([
            'category' => p('category'), 'channel' => isset($channelNames[p('channel')]) ? p('channel') : 'desk', 'subject' => p('subject'), 'description' => p('description') ?: null,
            'priority' => p('priority'), 'customer_name' => p('customer_name'), 'customer_email' => $email ?: null, 'customer_phone' => p('customer_phone') ?: null,
            'booking_id' => $bookingId, 'ticket_id' => $ticketId, 'voyage_id' => $voyageId, 'cargo_id' => $cargoId, 'assigned_to' => $canEdit ? pint('assigned_to') : null,
        ]));
        flash('success', 'Case opened.' . ($email !== '' ? ' The case number was queued to the customer\'s e-mail.' : ' Give the customer the case number.'));
        redirect(url($page, ['id' => $cid]));
    }
    $cid = (int)pint('case_id');
    $c = Care::find($cid);
    if (!$c) {
        fail('Case not found.');
    }
    switch ($act) {
        case 'action':
            if (!$canNote) {
                Access::denyPage($page, 'add to cases on');
            }
            $type = in_array(p('action_type'), ['note', 'reply', 'call'], true) ? (string)p('action_type') : 'note';
            $details = trim((string)p('details'));
            if ($details === '') {
                fail('Write the note or the reply.');
            }
            DB::tx(function () use ($c, $type, $details) {
                Care::act((int)$c['id'], $type === 'call' ? 'reply' : $type, ($type === 'call' ? 'Called the customer: ' : '') . $details, $type === 'note');
                if ($type === 'reply' && ($c['customer_email'] || $c['customer_phone'])) {
                    Notify::customer('case:' . $c['id'] . ':reply:' . DB::value('SELECT MAX(id) FROM shp_case_actions WHERE case_id = :c', ['c' => $c['id']]), 'case_reply', 'passenger',
                        $c['customer_email'] ?: null, $c['customer_phone'] ?: null, 'About your concern ' . $c['case_no'], $details, 'shp_cases', (int)$c['id']);
                }
                if ($c['status'] === 'open' && $type !== 'note') {
                    DB::update('shp_cases', ['status' => 'in_progress', 'updated_at' => Ship::now()], 'id = :id', ['id' => $c['id']]);
                }
            });
            flash('success', $type === 'reply' ? 'Reply recorded and queued to the customer.' : 'Added to the case.');
            break;
        case 'update':
            if (!$canEdit) {
                Access::denyPage($page, 'edit');
            }
            Care::update((int)$c['id'], ['status' => p('status'), 'priority' => p('priority'), 'assigned_to' => (int)pint('assigned_to'),
                'resolution_code' => p('resolution_code') ?: null, 'resolution_notes' => p('resolution_notes')]);
            flash('success', 'Case updated.');
            break;
        case 'escalate':
            if (!$canNote) {
                Access::denyPage($page, 'escalate cases on');
            }
            if (trim((string)p('why')) === '') {
                fail('Say why the case needs a manager.');
            }
            DB::tx(function () use ($c) {
                DB::update('shp_cases', ['escalated' => 1, 'updated_at' => Ship::now()], 'id = :id', ['id' => $c['id']]);
                Care::act((int)$c['id'], 'escalate', 'Escalated by ' . (Auth::user()['full_name'] ?? '') . ': ' . p('why'));
                Notify::staff('case:' . $c['id'] . ':escalated:manual:' . date('YmdHi'), 'case_escalated', ['Ticketing Manager', 'General Manager'], 'Case ' . $c['case_no'] . ' escalated', (string)p('why'), 'shp_cases', (int)$c['id']);
            });
            if ($c['priority'] !== 'urgent' && $c['priority'] !== 'high') {
                Care::update((int)$c['id'], ['priority' => 'high']);
            }
            flash('success', 'Escalated; the ticketing manager and the general manager were told.');
            break;
        case 'compensation':
            if (!$canEdit) {
                Access::denyPage($page, 'request compensation on');
            }
            Care::requestCompensation((int)$c['id'], (string)p('comp_type'), pnum('comp_amount'), (string)p('comp_reason'));
            flash('info', 'Compensation requested. Another person approves it in the Approvals Queue; it is posted to the books then.');
            break;
        default:
            fail('Unknown action.');
    }
    redirect(url($page, ['id' => $c['id']]));
});

// ------------------------------------------------------------------ one case
if ($id) {
    $c = Care::find($id);
    if (!$c) {
        flash('warning', 'That case was not found.');
        redirect(url($page));
    }
    $acts = DB::all('SELECT a.*, u.full_name FROM shp_case_actions a LEFT JOIN users u ON u.id = a.user_id WHERE a.case_id = :c ORDER BY a.id', ['c' => $id]);
    $pii = Ship::canPii();
    $closed = in_array($c['status'], ['resolved', 'closed'], true);
    $appr = ShipApproval::forRecord('shp_cases', $id);
    $actTone = ['customer' => '', 'reply' => '', 'note' => '', 'status' => '', 'escalate' => 't-bad', 'compensation' => 't-warn'];
    $actName = ['customer' => 'Customer', 'reply' => 'Reply to customer', 'note' => 'Internal note', 'status' => 'Change', 'escalate' => 'Escalated', 'compensation' => 'Compensation'];
    layout_start('Case ' . $c['case_no'], ['actions' => btn_link(url($page), 'All cases', 'btn-outline-secondary', 'list-ul')]);
    ?>
    <div class="card reveal mb-3"><div class="card-body">
      <div class="d-flex flex-wrap justify-content-between gap-3">
        <div class="min-w-0"><div class="fw-bold fs-5"><?= h($c['subject']) ?></div>
          <div class="small text-muted"><?= h(Ship::CASE_CATEGORIES[$c['category']] ?? $c['category']) ?>, by <?= h($channelNames[$c['channel']] ?? $c['channel']) ?>, opened <?= h(fdatetime($c['created_at'])) ?></div></div>
        <div class="text-end"><div class="d-flex gap-3 justify-content-end"><?= Ship::label($c['status'], $statusNames) ?><?= Ship::severityLabel($c['priority']) ?></div><div class="mt-1"><?= cases_sla($c) ?></div><?= (int)$c['escalated'] ? '<div class="small text-danger mt-1">Escalated</div>' : '' ?></div>
      </div>
      <div class="info-grid mt-3">
        <?= info_pair('Customer', $c['customer_name']) ?>
        <?= info_pair('E-mail', $c['customer_email'] ? ($pii ? $c['customer_email'] : mb_substr($c['customer_email'], 0, 2) . '***') : '-') ?>
        <?= info_pair('Phone', $c['customer_phone'] ? ($pii ? $c['customer_phone'] : Ship::maskPhone($c['customer_phone'])) : '-') ?>
        <?= info_pair('Owner', $c['owner_name'] ?: 'Not assigned') ?>
        <?= info_pair('Service time ends', fdatetime($c['sla_due_at'])) ?>
        <?= info_pair('First response', $c['first_response_at'] ? fdatetime($c['first_response_at']) : 'Not yet') ?>
        <?= $c['booking_ref'] ? info_pair('Booking', safe_link('tkt.bookings', ['id' => $c['booking_id']], $c['booking_ref']), true) : '' ?>
        <?= $c['ticket_no'] ? info_pair('Ticket', h($c['ticket_no']), true) : '' ?>
        <?= $c['voyage_no'] ? info_pair('Voyage', safe_link('ops.voyages', ['id' => $c['voyage_id']], $c['voyage_no']), true) : '' ?>
        <?= $c['waybill_no'] ? info_pair('Waybill', safe_link('cgo.shipments', ['id' => $c['cargo_id']], $c['waybill_no']), true) : '' ?>
        <?= $c['resolution_code'] ? info_pair('Resolution', (Care::RESOLUTIONS[$c['resolution_code']] ?? $c['resolution_code']) . ($c['resolution_notes'] ? ': ' . $c['resolution_notes'] : '')) : '' ?>
      </div>
    </div></div>
    <div class="row g-3">
      <div class="col-lg-7"><div class="card reveal mb-3"><div class="card-header">What happened</div><div class="card-body"><ul class="timeline">
        <?php foreach ($acts as $a): ?>
          <li class="<?= $actTone[$a['action_type']] ?? '' ?>"><div class="when"><?= h(fdatetime($a['created_at'])) ?>, <?= $a['action_type'] === 'customer' ? h($c['customer_name']) . ($a['full_name'] ? ', taken by ' . h($a['full_name']) : '') : h($a['full_name'] ?? 'system') . ', ' . h($actName[$a['action_type']] ?? $a['action_type']) ?><?= (int)$a['is_internal'] && $a['action_type'] !== 'note' ? ' (internal)' : '' ?></div>
            <div style="white-space:pre-line"><?= h($a['details']) ?></div></li>
        <?php endforeach; ?>
        <?php if (!$acts): ?><li><?= h((string)$c['description']) ?></li><?php endif; ?>
      </ul>
      <?php if ($canNote && $c['status'] !== 'closed'): ?>
        <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="act" value="action"><input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>">
          <div class="d-flex flex-wrap gap-3 mb-2 small">
            <?php foreach (['note' => 'Internal note', 'reply' => 'Reply to the customer', 'call' => 'Call made'] as $k => $l): ?>
              <div class="form-check"><input class="form-check-input" type="radio" name="action_type" value="<?= $k ?>" id="at_<?= $k ?>" <?= $k === 'note' ? 'checked' : '' ?>><label class="form-check-label" for="at_<?= $k ?>"><?= $l ?></label></div>
            <?php endforeach; ?></div>
          <textarea class="form-control form-control-sm" name="details" rows="3" required placeholder="A reply is sent to the customer's e-mail and phone; a note stays inside the company"></textarea>
          <button class="btn btn-primary btn-sm mt-2">Add</button>
        </form>
      <?php endif; ?>
      </div></div></div>
      <div class="col-lg-5">
      <?php if ($canEdit): ?>
        <form method="post" class="card reveal mb-3"><?= csrf_field() ?><input type="hidden" name="act" value="update"><input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"><div class="card-header">Status and owner</div><div class="card-body"><div class="row g-2">
          <?= select_field('status', 'Status', $statusNames, $c['status'], ['class' => 'col-6']) ?>
          <?= select_field('priority', 'Priority', $prioNames, $c['priority'], ['class' => 'col-6']) ?>
          <?= select_field('assigned_to', 'Owner', cases_owners(), $c['assigned_to'], ['class' => 'col-12', 'blank' => 'Not assigned']) ?>
          <?= select_field('resolution_code', 'Resolution (to resolve or close)', Care::RESOLUTIONS, $c['resolution_code'], ['class' => 'col-12', 'blank' => '']) ?>
          <?= input_field('resolution_notes', 'Resolution notes', $c['resolution_notes'], ['class' => 'col-12']) ?>
        </div><button class="btn btn-primary btn-sm mt-3">Save</button>
        <span class="small text-muted ms-2">Changing the priority resets the service time from when the case was opened.</span></div></form>
      <?php endif; ?>
        <div class="card reveal mb-3"><div class="card-header">Compensation</div><div class="card-body">
        <?php if ($c['comp_status'] && $c['comp_status'] !== 'none'): ?>
          <div class="d-flex justify-content-between"><span><?= h($c['comp_type'] === 'voucher' ? 'Credit voucher' : 'Cash payment') ?> of ₱<?= money($c['comp_amount']) ?></span><?= Ship::label($c['comp_status'], ['requested' => 'Waiting for approval', 'paid' => 'Approved and posted', 'rejected' => 'Rejected']) ?></div>
          <?php if ($c['comp_txn_id']): ?><div class="small mt-1">Posted: <?= txn_doc_link((int)$c['comp_txn_id']) ?></div><?php endif; ?>
          <?php foreach ($appr as $a): if ($a['approval_type'] !== 'compensation') { continue; } ?>
            <div class="small text-muted mt-1"><?= $a['status'] === 'pending' ? 'Asked ' . h(fdatetime($a['requested_at'])) . ' by ' . h(Ship::userName((int)$a['requested_by'])) . (Access::page('ops.approvals') ? ', <a href="' . h(url('ops.approvals', ['id' => $a['id'], 'tab' => 'all'])) . '">open in the queue</a>' : '') : h(ucfirst($a['status'])) . ' by ' . h(Ship::userName((int)$a['decided_by'])) . ($a['decision_notes'] ? ': ' . h($a['decision_notes']) : '') ?></div>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($canEdit && !$closed && !in_array($c['comp_status'], ['requested', 'approved', 'paid'], true)): ?>
          <form method="post" class="<?= $c['comp_status'] && $c['comp_status'] !== 'none' ? 'mt-3 pt-3 border-top' : '' ?>"><?= csrf_field() ?><input type="hidden" name="act" value="compensation"><input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"><div class="row g-2">
            <?= select_field('comp_type', 'Give', ['voucher' => 'Credit voucher (customer credit)', 'cash' => 'Cash payment (check)'], 'voucher', ['class' => 'col-md-7']) ?>
            <?= input_field('comp_amount', 'Amount', '', ['type' => 'number', 'class' => 'col-md-5', 'required' => true]) ?>
            <?= input_field('comp_reason', 'Reason', '', ['class' => 'col-12', 'required' => true]) ?>
          </div><button class="btn btn-outline-primary btn-sm mt-2">Request compensation</button>
          <div class="form-text">A fare refund is not compensation: request it from the booking so the ticket is cancelled with it.</div></form>
        <?php elseif (!$c['comp_status'] || $c['comp_status'] === 'none'): ?><p class="small text-muted mb-0">None given.</p><?php endif; ?>
        </div></div>
        <?php if ($canNote && !$closed && !(int)$c['escalated']): ?>
        <form method="post" class="card reveal"><?= csrf_field() ?><input type="hidden" name="act" value="escalate"><input type="hidden" name="case_id" value="<?= (int)$c['id'] ?>"><div class="card-body">
          <div class="fw-semibold small mb-1">Needs a manager?</div>
          <div class="input-group input-group-sm"><input class="form-control" name="why" placeholder="Why it needs to go up" required aria-label="Reason to escalate"><button class="btn btn-outline-danger">Escalate</button></div>
        </div></form>
        <?php endif; ?>
      </div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the case list
Care::escalateOverdue();
$status = (string)g('status', 'active');
$cat = (string)g('cat');
$prio = (string)g('prio');
$owner = (string)g('owner');
$q = trim((string)g('q'));
$where = '1 = 1';
$par = [];
if ($status === 'active') {
    $where .= " AND c.status IN ('open', 'in_progress', 'waiting')";
} elseif ($status === 'late') {
    $where .= " AND c.status IN ('open', 'in_progress', 'waiting') AND c.sla_due_at < :n";
    $par['n'] = Ship::now();
} elseif (isset($statusNames[$status])) {
    $where .= ' AND c.status = :st';
    $par['st'] = $status;
}
if (isset(Ship::CASE_CATEGORIES[$cat])) {
    $where .= ' AND c.category = :cat';
    $par['cat'] = $cat;
}
if (isset($prioNames[$prio])) {
    $where .= ' AND c.priority = :pr';
    $par['pr'] = $prio;
}
if ($owner === 'me') {
    $where .= ' AND c.assigned_to = :me';
    $par['me'] = $me;
} elseif ($owner === 'none') {
    $where .= ' AND c.assigned_to IS NULL';
}
if ($q !== '') {
    $where .= ' AND (c.case_no LIKE :q OR c.subject LIKE :q2 OR c.customer_name LIKE :q3)';
    $par += ['q' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%"];
}
$rows = DB::all("SELECT c.*, u.full_name owner_name FROM shp_cases c LEFT JOIN users u ON u.id = c.assigned_to WHERE $where
                  ORDER BY c.status IN ('resolved', 'closed'), FIELD(c.priority, 'urgent', 'high', 'normal', 'low'), c.sla_due_at LIMIT 300", $par);
$k = DB::row("SELECT SUM(status IN ('open', 'in_progress', 'waiting')) open_n, SUM(status IN ('open', 'in_progress', 'waiting') AND sla_due_at < :n) late_n,
                     SUM(status IN ('open', 'in_progress', 'waiting') AND assigned_to IS NULL) unassigned, SUM(comp_status = 'requested') comp_n,
                     SUM(resolved_at >= :m) resolved_m, SUM(resolved_at >= :m2 AND resolved_at <= sla_due_at) ontime_m
                FROM shp_cases", ['n' => Ship::now(), 'm' => date('Y-m-01', strtotime(Ship::today())), 'm2' => date('Y-m-01', strtotime(Ship::today()))]);
$pii = Ship::canPii();
layout_start('Support Cases & Claims', ['actions' => $canOpen ? '<button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#newCase"><i class="bi bi-plus-lg"></i> New case</button>' : '']);
if ($canOpen): ?>
<div class="collapse <?= is_post() ? 'show' : '' ?>" id="newCase"><form method="post" class="card mb-3"><?= csrf_field() ?><input type="hidden" name="act" value="open"><div class="card-header">New case</div><div class="card-body"><div class="row g-2">
  <?= input_field('customer_name', 'Customer name', p('customer_name'), ['class' => 'col-md-4', 'required' => true]) ?>
  <?= input_field('customer_phone', 'Mobile', p('customer_phone'), ['class' => 'col-md-4']) ?>
  <?= input_field('customer_email', 'E-mail', p('customer_email'), ['type' => 'email', 'class' => 'col-md-4']) ?>
  <?= select_field('category', 'Category', Ship::CASE_CATEGORIES, p('category', 'booking_help'), ['class' => 'col-md-4']) ?>
  <?= select_field('priority', 'Priority', $prioNames, p('priority', 'normal'), ['class' => 'col-md-2']) ?>
  <?= select_field('channel', 'Came in by', $channelNames, p('channel', 'desk'), ['class' => 'col-md-2']) ?>
  <?= $canEdit ? select_field('assigned_to', 'Owner', cases_owners(), p('assigned_to'), ['class' => 'col-md-4', 'blank' => 'Leave for the team']) : '' ?>
  <?= input_field('subject', 'Subject', p('subject'), ['class' => 'col-12', 'required' => true]) ?>
  <div class="col-12"><label class="form-label">What the customer said</label><textarea class="form-control form-control-sm" name="description" rows="3"><?= h((string)p('description')) ?></textarea></div>
  <?= input_field('booking_ref', 'Booking reference', p('booking_ref'), ['class' => 'col-md-3']) ?>
  <?= input_field('ticket_no', 'Ticket no.', p('ticket_no'), ['class' => 'col-md-3']) ?>
  <?= input_field('waybill_no', 'Waybill no.', p('waybill_no'), ['class' => 'col-md-3']) ?>
  <?= select_field('voyage_id', 'Voyage', Ship::voyageOptions(['on_sale', 'boarding', 'ready', 'departed', 'arrived', 'completed', 'cancelled'], null, 30), p('voyage_id'), ['class' => 'col-md-3', 'blank' => '']) ?>
</div><button class="btn btn-primary btn-sm mt-3">Open case</button>
<span class="small text-muted ms-2">Link only this customer's own booking, ticket or waybill; other passengers' details are never shown on a case.</span></div></form></div>
<?php endif; ?>
<div class="kpi-grid mb-3">
  <?= kpi_tile('Open cases', (string)(int)$k['open_n'], 'headset', (int)$k['unassigned'] . ' without an owner', '', url($page), (float)$k['open_n']) ?>
  <?= kpi_tile('Past service time', (string)(int)$k['late_n'], 'alarm', 'Escalated automatically', (int)$k['late_n'] ? 'bad' : 'good', url($page, ['status' => 'late']), (float)$k['late_n']) ?>
  <?= kpi_tile('Compensation waiting', (string)(int)$k['comp_n'], 'cash-coin', 'For approval by another person', (int)$k['comp_n'] ? 'warn' : '', Access::page('ops.approvals') ? url('ops.approvals', ['tab' => 'all']) : null) ?>
  <?= kpi_tile('Resolved this month', (string)(int)$k['resolved_m'], 'check2-circle', (int)$k['resolved_m'] ? round(100 * (int)$k['ontime_m'] / (int)$k['resolved_m']) . '% within service time' : 'None yet', 'good') ?>
</div>
<form class="toolbar mb-2" method="get"><input type="hidden" name="p" value="<?= h($page) ?>">
  <?= input_field('q', 'Find', $q, ['class' => '', 'extra' => 'placeholder="Case no., subject or customer"']) ?>
  <?= select_field('status', 'Status', ['active' => 'Open, in progress, waiting', 'late' => 'Past service time'] + $statusNames, $status, ['class' => '', 'blank' => 'Any status']) ?>
  <?= select_field('cat', 'Category', Ship::CASE_CATEGORIES, $cat, ['class' => '', 'blank' => 'Every category']) ?>
  <?= select_field('prio', 'Priority', $prioNames, $prio, ['class' => '', 'blank' => 'Any priority']) ?>
  <?= select_field('owner', 'Owner', ['me' => 'Mine', 'none' => 'Not assigned'], $owner, ['class' => '', 'blank' => 'Anyone']) ?>
  <div><button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Show</button></div>
</form>
<div class="card reveal"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>Case</th><th>Subject</th><th>Customer</th><th>Category</th><th>Priority</th><th>Owner</th><th>Service time</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="text-nowrap"><a href="<?= h(url($page, ['id' => $r['id']])) ?>"><?= h($r['case_no']) ?></a></td>
      <td><?= h($r['subject']) ?><?= $r['comp_status'] === 'requested' ? '<div class="small text-warning">Compensation waiting</div>' : '' ?></td>
      <td class="small"><?= h($r['customer_name']) ?><div class="text-muted"><?= h($r['customer_phone'] ? ($pii ? $r['customer_phone'] : Ship::maskPhone($r['customer_phone'])) : '') ?></div></td>
      <td class="small"><?= h(Ship::CASE_CATEGORIES[$r['category']] ?? $r['category']) ?></td><td><?= Ship::severityLabel($r['priority']) ?></td>
      <td class="small"><?= h($r['owner_name'] ?? 'Not assigned') ?></td><td><?= cases_sla($r) ?></td><td><?= Ship::label($r['status'], $statusNames) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8"><?= empty_note('No case matches.', 'headset') ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php
layout_end();
