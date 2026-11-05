<?php
declare(strict_types=1);

/**
 * Booking Desk: find a voyage, enter the passengers (and vehicles), see the itemized price, hold the places,
 * then take the payment. Tickets are numbered, signed and posted to the books only when the payment is recorded.
 *
 *   (no parameter)   voyage search, and a look-up of a reserved booking by its reference
 *   ?voyage=ID       the booking form of that voyage
 *   ?booking=ID      the payment of a held booking (any channel: counter, portal, agent), or its confirmation
 */

$page = 'tkt.booking';
$voyageId = gint('voyage');
$bookingId = gint('booking');


if (!function_exists('shp_bk_status')) {
    function shp_bk_status(string $s): string
    {
        $map = ['held' => ['warn', 'Held, waiting for payment'], 'confirmed' => ['good', 'Paid and confirmed'], 'completed' => ['good', 'Completed'],
            'cancelled' => ['bad', 'Cancelled'], 'expired' => ['bad', 'Hold expired']];
        [$tone, $text] = $map[$s] ?? ['muted', ucfirst($s)];
        return '<span class="st st-' . $tone . '">' . h($text) . '</span>';
    }

    /** One passenger card of the form; $i is the row key (or __I__ in the template) */
    function shp_bk_pax_card(string $i, array $r, array $cats, array $cap, bool $canOverride): string
    {
        $v = fn(string $k, string $d = '') => h((string)($r[$k] ?? $d));
        $catOpts = '';
        foreach ($cats as $c) {
            $catOpts .= '<option value="' . (int)$c['id'] . '"' . ((string)($r['category_id'] ?? '') === (string)$c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>';
        }
        $accOpts = '';
        foreach ($cap as $c) {
            $sel = (string)($r['accom_code'] ?? '') === $c['code'];
            $accOpts .= '<option value="' . h($c['code']) . '" data-reserved="' . (int)$c['reserved'] . '"' . ($sel ? ' selected' : '') . ($c['left'] < 1 && !$sel ? ' disabled' : '') . '>'
                . h($c['name']) . ' (' . (int)$c['left'] . ' left)</option>';
        }
        $g = (string)($r['gender'] ?? '');
        $html = '<div class="pax-card" data-pax="' . h($i) . '"><div class="card-top"><span class="pax-no">Passenger <b class="pax-n"></b></span>'
            . '<button type="button" class="btn btn-link btn-sm text-danger p-0 rm-pax"><i class="bi bi-x-lg"></i> Remove</button></div>'
            . '<div class="row g-2">'
            . '<div class="col-6 col-md-3"><label class="form-label">Last name</label><input class="form-control form-control-sm" name="pax[' . $i . '][last_name]" value="' . $v('last_name') . '" data-q autocomplete="off"></div>'
            . '<div class="col-6 col-md-3"><label class="form-label">First name</label><input class="form-control form-control-sm" name="pax[' . $i . '][first_name]" value="' . $v('first_name') . '" data-q autocomplete="off"></div>'
            . '<div class="col-6 col-md-2"><label class="form-label">Middle name</label><input class="form-control form-control-sm" name="pax[' . $i . '][middle_name]" value="' . $v('middle_name') . '" autocomplete="off"></div>'
            . '<div class="col-6 col-md-2"><label class="form-label">Birth date</label><input class="form-control form-control-sm" type="date" name="pax[' . $i . '][birth_date]" value="' . $v('birth_date') . '" data-age></div>'
            . '<div class="col-6 col-md-2"><label class="form-label">Sex</label><select class="form-select form-select-sm" name="pax[' . $i . '][gender]"><option value=""></option>'
            . '<option value="M"' . ($g === 'M' ? ' selected' : '') . '>Male</option><option value="F"' . ($g === 'F' ? ' selected' : '') . '>Female</option></select></div>'
            . '<div class="col-6 col-md-3"><label class="form-label">Category</label><select class="form-select form-select-sm" name="pax[' . $i . '][category_id]" data-q data-cat>' . $catOpts . '</select></div>'
            . '<div class="col-6 col-md-3 id-box"><label class="form-label id-label">ID number</label><input class="form-control form-control-sm" name="pax[' . $i . '][id_no]" value="' . $v('id_no') . '" autocomplete="off"></div>'
            . '<div class="col-6 col-md-3"><label class="form-label">Accommodation</label><select class="form-select form-select-sm" name="pax[' . $i . '][accom_code]" data-q data-accom>' . $accOpts . '</select></div>'
            . '<div class="col-6 col-md-3"><label class="form-label">Seat</label><input type="hidden" name="pax[' . $i . '][seat_id]" value="' . $v('seat_id') . '" data-seat-id>'
            . '<button type="button" class="btn btn-sm btn-outline-primary w-100 seat-btn" data-seat-no="' . $v('seat_no') . '"><i class="bi bi-grid-3x3-gap"></i> <span>' . (($r['seat_no'] ?? '') !== '' ? h((string)$r['seat_no']) : 'Next free seat') . '</span></button>'
            . '<input type="hidden" name="pax[' . $i . '][seat_no]" value="' . $v('seat_no') . '" data-seat-no-field></div>'
            . '<div class="col-6 col-md-3"><label class="form-label">Nationality</label><input class="form-control form-control-sm" name="pax[' . $i . '][nationality]" value="' . $v('nationality', 'Filipino') . '"></div>'
            . '<div class="col-6 col-md-3"><label class="form-label">Mobile no.</label><input class="form-control form-control-sm" name="pax[' . $i . '][contact_no]" value="' . $v('contact_no') . '" inputmode="tel"></div>'
            . '<div class="col-12 col-md-' . ($canOverride ? '4' : '6') . '"><label class="form-label">Special assistance</label><input class="form-control form-control-sm" name="pax[' . $i . '][special_assist]" value="' . $v('special_assist') . '" placeholder="Wheelchair, stretcher, travelling with an infant ..."></div>'
            . ($canOverride ? '<div class="col-6 col-md-2"><label class="form-label">Fare override</label><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="override[' . $i . ']" value="' . h((string)($r['_override'] ?? '')) . '" data-q placeholder="Gross"></div>' : '')
            . '</div><div class="small text-muted age-note mt-1"></div></div>';
        return $html;
    }

    function shp_bk_veh_card(string $i, array $r, array $classes): string
    {
        $v = fn(string $k, string $d = '') => h((string)($r[$k] ?? $d));
        $opts = '<option value=""></option>';
        foreach ($classes as $c) {
            $opts .= '<option value="' . (int)$c['id'] . '" data-len="' . h((string)$c['max_length_m']) . '"' . ((string)($r['vehicle_class_id'] ?? '') === (string)$c['id'] ? ' selected' : '') . '>'
                . h($c['name']) . ((float)$c['max_length_m'] > 0 ? ' (to ' . qty($c['max_length_m']) . ' m)' : '') . '</option>';
        }
        return '<div class="pax-card veh-card" data-veh="' . h($i) . '"><div class="card-top"><span class="pax-no"><i class="bi bi-truck-front"></i> Vehicle</span>'
            . '<button type="button" class="btn btn-link btn-sm text-danger p-0 rm-veh"><i class="bi bi-x-lg"></i> Remove</button></div><div class="row g-2">'
            . '<div class="col-12 col-md-4"><label class="form-label">Vehicle class</label><select class="form-select form-select-sm" name="veh[' . $i . '][vehicle_class_id]" data-q>' . $opts . '</select></div>'
            . '<div class="col-6 col-md-2"><label class="form-label">Plate no.</label><input class="form-control form-control-sm text-uppercase" name="veh[' . $i . '][plate_no]" value="' . $v('plate_no') . '" data-q autocomplete="off"></div>'
            . '<div class="col-6 col-md-3"><label class="form-label">Make / model</label><input class="form-control form-control-sm" name="veh[' . $i . '][make_model]" value="' . $v('make_model') . '"></div>'
            . '<div class="col-6 col-md-3"><label class="form-label">Driver (a passenger)</label><select class="form-select form-select-sm driver-sel" name="veh[' . $i . '][driver_index]" data-val="' . $v('driver_index') . '"><option value="">None on this booking</option></select></div>'
            . '<div class="col-4 col-md-2"><label class="form-label">Length (m)</label><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="veh[' . $i . '][length_m]" value="' . $v('length_m') . '"></div>'
            . '<div class="col-4 col-md-2"><label class="form-label">Weight (t)</label><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="veh[' . $i . '][weight_t]" value="' . $v('weight_t') . '"></div>'
            . '<div class="col-4 col-md-2"><label class="form-label">Axles</label><input class="form-control form-control-sm" type="number" step="1" min="2" name="veh[' . $i . '][axles]" value="' . $v('axles', '2') . '"></div>'
            . '<div class="col-12 col-md-6"><label class="form-label">Declared cargo on board</label><input class="form-control form-control-sm" name="veh[' . $i . '][declared_cargo]" value="' . $v('declared_cargo') . '"></div>'
            . '<div class="col-12 col-md-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input haz-chk" type="checkbox" value="1" id="hz' . $i . '" name="veh[' . $i . '][hazardous]"' . (!empty($r['hazardous']) ? ' checked' : '') . '>'
            . '<label class="form-check-label small" for="hz' . $i . '">Carries hazardous goods</label></div></div>'
            . '<div class="col-12 col-md-8 haz-box"' . (!empty($r['hazardous']) ? '' : ' hidden') . '><label class="form-label">Hazardous goods declared</label><input class="form-control form-control-sm" name="veh[' . $i . '][hazardous_desc]" value="' . $v('hazardous_desc') . '"></div>'
            . '<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" value="1" id="ack' . $i . '" name="veh[' . $i . '][restrictions_ack]"' . (!empty($r['restrictions_ack']) ? ' checked' : '') . '>'
            . '<label class="form-check-label small" for="ack' . $i . '">The driver was told of the prohibited and restricted carriage rules and confirms the declaration</label></div></div>'
            . '</div><div class="pb-2"></div></div>';
    }
}

// ------------------------------------------------------------------ actions
handle_post(function () use ($page) {
    $act = (string)p('act');
    if ($act === 'hold') {
        ship_require($page, 'create');
        $voyage = Ship::voyage((int)pint('voyage_id'));
        if (!$voyage) {
            fail('Choose a voyage.');
        }
        $channel = in_array(p('channel'), ['counter', 'corporate', 'agent'], true) ? (string)p('channel') : 'counter';
        $account = pint('account_id');
        if ($channel !== 'counter' && !$account) {
            fail('Choose the ' . ($channel === 'agent' ? 'travel agent' : 'corporate account') . ' of this booking.');
        }
        $offices = Ship::userOffices();
        $office = pint('office_id');
        if (!$office || !isset($offices[$office])) {
            fail('Choose the ticket office you are selling at.');
        }
        $pax = [];
        $pos = [];
        foreach ((array)($_POST['pax'] ?? []) as $k => $r) {
            if (!is_array($r)) {
                continue;
            }
            $pos[(string)$k] = count($pax);
            $pax[] = $r;
        }
        $over = [];
        foreach ((array)($_POST['override'] ?? []) as $k => $val) {
            if (isset($pos[(string)$k]) && trim((string)$val) !== '') {
                $over[$pos[(string)$k]] = (float)$val;
            }
        }
        $veh = [];
        foreach ((array)($_POST['veh'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $d = (string)($r['driver_index'] ?? '');
            $r['driver_index'] = $d !== '' && isset($pos[$d]) ? $pos[$d] : '';
            $veh[] = $r;
        }
        $bid = Booking::hold([
            'voyage_id' => (int)$voyage['id'], 'channel' => $channel, 'office_id' => $office,
            'customer_id' => $channel === 'corporate' ? $account : null, 'agent_customer_id' => $channel === 'agent' ? $account : null,
            'contact_name' => p('contact_name'), 'contact_email' => p('contact_email') ?: null, 'contact_phone' => p('contact_phone') ?: null,
            'promo_code' => p('promo_code'), 'notes' => p('notes') ?: null,
        ], $pax, $veh, ['override_gross' => $over, 'override_reason' => p('override_reason')]);
        redirect(url($page, ['booking' => $bid]));
    }
    $b = Booking::find((int)pint('booking_id'));
    if (!$b) {
        fail('Booking not found.');
    }
    if ($act === 'pay') {
        ship_require($page, 'create');
        $channel = (string)p('pay_channel');
        Booking::pay((int)$b['id'], ['channel' => $channel, 'amount' => pnum('amount'), 'provider_ref' => p('provider_ref'),
            'idempotency_key' => p('idem'), 'office_id' => $b['office_id'] ?: (array_key_first(Ship::userOffices()) ?: null)]);
        flash('success', 'Booking ' . $b['booking_ref'] . ' is paid. The e-tickets are ready to print or send.');
        redirect(url($page, ['booking' => $b['id']]));
    }
    if ($act === 'release') {
        if (!Access::can($page, 'create') && !Access::can('tkt.bookings', 'edit')) {
            Access::denyPage($page, 'create');
        }
        if ($b['status'] !== 'held') {
            fail('Only a booking that is still held can be released.');
        }
        Booking::release((int)$b['id'], 'cancelled', 'Hold released at the counter by ' . (Auth::user()['full_name'] ?? '') . '.');
        Ship::audit('release', 'tkt', 'shp_booking', (int)$b['id'], 'Hold of ' . $b['booking_ref'] . ' released', ['page_key' => 'tkt.booking']);
        flash('info', 'The places held by ' . $b['booking_ref'] . ' are back on sale.');
        redirect(url($page));
    }
    fail('Unknown action.');
});

// a reserved booking looked up by its reference
if (($ref = strtoupper(trim((string)g('ref')))) !== '') {
    $found = DB::row('SELECT id, status FROM shp_bookings WHERE booking_ref = :r', ['r' => $ref]);
    if (!$found) {
        flash('warning', 'No booking has the reference ' . $ref . '.');
        redirect(url($page));
    }
    redirect(Access::page('tkt.bookings') && $found['status'] !== 'held' ? url('tkt.bookings', ['id' => $found['id']]) : url($page, ['booking' => $found['id']]));
}

// ================================================================== payment / confirmation of a booking
if ($bookingId) {
    $b = Booking::find($bookingId);
    if (!$b) {
        layout_start('Booking not found');
        echo empty_note('That booking does not exist.');
        layout_end();
        return;
    }
    $tickets = Booking::tickets($bookingId);
    $vehicles = Booking::vehicles($bookingId);
    $vid = (int)($tickets[0]['voyage_id'] ?? ($vehicles[0]['voyage_id'] ?? 0));
    if ($b['status'] === 'held' && $vid) {
        Booking::expireHolds($vid);
        $b = Booking::find($bookingId);
        $tickets = Booking::tickets($bookingId);
        $vehicles = Booking::vehicles($bookingId);
    }
    $v = $vid ? Ship::voyage($vid) : null;
    $paid = in_array($b['status'], ['confirmed', 'completed'], true);
    $left = $b['status'] === 'held' && $b['hold_expires_at'] ? max(0, strtotime($b['hold_expires_at']) - strtotime(Ship::now())) : 0;
    $actions = '';
    if ($paid) {
        $actions .= btn_link(url('shp.ticket', ['booking' => $bookingId]), 'Print e-tickets', 'btn-primary', 'printer') . ' ';
    }
    if (Access::page('tkt.bookings')) {
        $actions .= btn_link(url('tkt.bookings', ['id' => $bookingId]), 'Booking record', 'btn-outline-secondary', 'journal-text') . ' ';
    }
    if ($v && in_array($v['status'], ['on_sale', 'boarding'], true)) {
        $actions .= btn_link(url($page, ['voyage' => $v['id']]), 'New booking on this voyage', 'btn-outline-primary', 'plus-lg');
    }
    layout_start('Booking ' . $b['booking_ref'], ['actions' => $actions]);
    $shift = Cashier::current((int)Auth::id());
    $isAccount = $b['channel'] === 'corporate' || $b['channel'] === 'agent';
    ?>
    <div class="row g-3">
      <div class="col-xl-8">
        <?php if ($v): ?><?= voyage_card($v, Access::page('ops.voyages') ? url('ops.voyages', ['id' => $v['id']]) : null, '<div class="small text-muted">' . h($v['route_name']) . '</div>') ?><?php endif; ?>
        <div class="card reveal mt-3"><div class="card-header d-flex flex-wrap justify-content-between gap-2"><span>Passengers and vehicles</span><?= shp_bk_status($b['status']) ?></div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Passenger</th><th>Category</th><th>Place</th><th class="num">Fare</th><th class="num">Discount</th><th class="num">Surcharge</th><th class="num">VAT</th><th class="num">Fees</th><th class="num">Total</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($tickets as $t): ?>
              <tr><td><div class="fw-semibold"><?= h($t['last_name'] . ', ' . $t['first_name']) ?></div><div class="small text-muted text-nowrap"><?= h($t['ticket_no'] ?: 'Number given on payment') ?></div></td>
                <td><?= h($t['cat_name']) ?></td><td><?= h($t['accom_code']) ?><?= $t['seat_no'] ? ' ' . h($t['seat_no']) : '' ?></td>
                <td class="num"><?= money($t['fare_gross']) ?></td><td class="num"><?= money((float)$t['discount_amount'] + (float)$t['promo_amount'], true) ?></td><td class="num"><?= money($t['surcharge_net'], true) ?></td>
                <td class="num"><?= money($t['vat_amount'], true) ?></td><td class="num"><?= money($t['fees_total'], true) ?></td><td class="num fw-semibold"><?= money($t['total']) ?></td>
                <td><?= Ship::label($t['status']) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($vehicles as $x): ?>
              <tr><td><div class="fw-semibold"><?= h($x['plate_no']) ?></div><div class="small text-muted"><?= h($x['vehicle_no'] ?: 'Pass number given on payment') ?></div></td>
                <td><?= h($x['class_name']) ?></td><td><?= qty($x['length_m']) ?> m</td><td class="num"><?= money($x['fare_gross']) ?></td><td class="num"></td><td class="num"></td>
                <td class="num"><?= money($x['vat_amount'], true) ?></td><td class="num"><?= money($x['fees_total'], true) ?></td><td class="num fw-semibold"><?= money($x['total']) ?></td><td><?= Ship::label($x['status']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="grid-totals"><td colspan="8" class="fw-semibold">Booking total</td><td class="num fw-bold"><?= money($b['total_amount']) ?></td><td></td></tr></tfoot>
          </table></div>
          <div class="card-body small text-muted pt-2">Fares of Senior Citizen and PWD passengers are VAT-exempt and discounted on the fare net of VAT. Terminal fees and passenger insurance are collected for their owners and are not company revenue.</div>
        </div>
      </div>
      <div class="col-xl-4">
        <div class="card reveal mb-3"><div class="card-body">
          <div class="info-grid">
            <?= info_pair('Booked by', $b['contact_name']) ?><?= info_pair('Mobile', Ship::maskPhone($b['contact_phone']) ?: '-') ?>
            <?= info_pair('Sold through', ['counter' => 'Ticket counter', 'portal' => 'Online reservation', 'agent' => 'Travel agent', 'corporate' => 'Corporate account'][$b['channel']] ?? $b['channel']) ?>
            <?= info_pair('Office', $b['office_name'] ?: '-') ?>
            <?= $isAccount ? info_pair($b['channel'] === 'agent' ? 'Agent' : 'Account', $b['channel'] === 'agent' ? ($b['agent_name'] ?: '-') : ($b['customer_name'] ?: '-')) : '' ?>
            <?= info_pair('Created', fdatetime($b['created_at'])) ?>
          </div>
        </div></div>
        <?php if ($b['status'] === 'held'): ?>
          <div class="card reveal pay-card"><div class="card-header d-flex justify-content-between align-items-center"><span>Take the payment</span>
            <span class="small hold-clock" data-left="<?= (int)$left ?>"><i class="bi bi-hourglass-split"></i> <b><?= gmdate($left >= 3600 ? 'G:i:s' : 'i:s', $left) ?></b> left</span></div>
          <div class="card-body">
            <div class="money-big mb-1">₱<?= money($b['total_amount']) ?></div>
            <div class="small text-muted mb-3">Held until <?= h(fdatetime($b['hold_expires_at'])) ?>. Unpaid places go back on sale after that.</div>
            <?php if (!Access::can($page, 'create')): ?>
              <p class="small text-muted mb-0">Your role can see this booking but not take its payment.</p>
            <?php else: ?>
            <form method="post" id="payForm"><?= csrf_field() ?><input type="hidden" name="act" value="pay"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
              <input type="hidden" name="idem" value="<?= h(p('idem') ?: 'desk-' . $b['id'] . '-' . bin2hex(random_bytes(6))) ?>"><input type="hidden" name="amount" value="<?= h((string)$b['total_amount']) ?>">
              <div class="pay-tiles mb-3">
              <?php
              $chans = ['cash' => ['Cash', 'cash-coin'], 'card' => ['Card', 'credit-card'], 'ewallet' => ['GCash', 'phone'], 'maya' => ['Maya', 'phone-vibrate'],
                  'bank_transfer' => ['Bank transfer', 'bank'], 'check' => ['Check', 'pencil-square']];
              if ($b['channel'] === 'corporate') {
                  $chans['corporate'] = ['Charge to account', 'building'];
              }
              if ($b['channel'] === 'agent') {
                  $chans['agent'] = ['Charge to agent', 'briefcase'];
              }
              $pc = (string)p('pay_channel', $isAccount ? $b['channel'] : 'cash');
              foreach ($chans as $k => [$label, $ico]): ?>
                <label class="pay-tile"><input type="radio" name="pay_channel" value="<?= h($k) ?>" <?= $pc === $k ? 'checked' : '' ?>><span><i class="bi bi-<?= h($ico) ?>"></i><?= h($label) ?></span></label>
              <?php endforeach; ?>
              </div>
              <div class="ref-box mb-2"><label class="form-label ref-label">Approval / reference no.</label><input class="form-control form-control-sm" name="provider_ref" value="<?= h((string)p('provider_ref')) ?>" autocomplete="off"></div>
              <div class="cash-box mb-2"><label class="form-label">Cash received (for the change)</label><input class="form-control form-control-sm" type="number" step="0.01" min="0" id="tendered">
                <div class="small mt-1">Change: <b id="change">0.00</b></div>
                <?php if (!$shift && in_array($b['channel'], ['counter', 'portal'], true)): ?>
                  <div class="alert alert-warning small mt-2 mb-0">Your cashier shift is not open. Cash is counted per shift; <?= Access::page('tkt.cashier') ? '<a href="' . h(url('tkt.cashier')) . '">open your shift</a>' : 'ask your supervisor to open your shift' ?> before taking cash.</div>
                <?php elseif ($shift): ?><div class="small text-muted mt-1">Shift <?= h($shift['shift_no']) ?>, <?= h($shift['office_name']) ?></div><?php endif; ?>
              </div>
              <div class="acct-box small text-muted mb-2">An invoice goes to the account for the booking total; the tickets are issued now.</div>
              <button class="btn btn-primary w-100" data-once><i class="bi bi-check2-circle"></i> Record payment and issue tickets</button>
            </form>
            <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="act" value="release"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
              <button class="btn btn-link btn-sm text-danger px-0" data-confirm="Release the places held by this booking?">Release the hold</button></form>
            <?php endif; ?>
          </div></div>
        <?php elseif ($paid): ?>
          <div class="card reveal done-card"><div class="card-body text-center">
            <div class="done-mark"><i class="bi bi-check-lg"></i></div>
            <div class="fw-bold mt-2">Paid ₱<?= money($b['paid_amount']) ?></div>
            <div class="small text-muted mb-3"><?= count(array_filter($tickets, fn($t) => $t['ticket_no'])) ?> e-ticket(s)<?= $vehicles ? ', ' . count($vehicles) . ' vehicle pass(es)' : '' ?>, confirmed <?= h(fdatetime($b['confirmed_at'])) ?></div>
            <a class="btn btn-primary w-100" href="<?= h(url('shp.ticket', ['booking' => $bookingId])) ?>"><i class="bi bi-printer"></i> Print e-tickets</a>
          </div></div>
        <?php else: ?>
          <div class="card reveal"><div class="card-body"><?= shp_bk_status($b['status']) ?><p class="small text-muted mt-2 mb-0"><?= h((string)$b['notes']) ?></p>
            <?php if ($v && in_array($v['status'], ['on_sale', 'boarding'], true) && Access::can($page, 'create')): ?><a class="btn btn-sm btn-outline-primary mt-2" href="<?= h(url($page, ['voyage' => $v['id']])) ?>">Book again</a><?php endif; ?></div></div>
        <?php endif; ?>
      </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var clock = document.querySelector('.hold-clock');
      if (clock) {
        var left = parseInt(clock.getAttribute('data-left'), 10), b = clock.querySelector('b');
        var tick = function () {
          if (left <= 0) { b.textContent = '0:00'; clock.classList.add('text-danger'); return; }
          var h = Math.floor(left / 3600), m = Math.floor(left % 3600 / 60), s = left % 60;
          b.textContent = (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
          if (left < 120) clock.classList.add('text-danger');
          left--; setTimeout(tick, 1000);
        };
        tick();
      }
      var form = document.getElementById('payForm');
      if (!form) return;
      var total = <?= json_encode((float)$b['total_amount']) ?>;
      var labels = { card: 'Card approval no.', ewallet: 'GCash reference no.', maya: 'Maya reference no.', bank_transfer: 'Bank transfer reference', check: 'Check no. and bank' };
      function sync() {
        var c = (form.querySelector('input[name=pay_channel]:checked') || {}).value || 'cash';
        form.querySelector('.ref-box').hidden = !labels[c];
        form.querySelector('.ref-label').textContent = labels[c] || '';
        form.querySelector('.cash-box').hidden = c !== 'cash';
        form.querySelector('.acct-box').hidden = c !== 'corporate' && c !== 'agent';
      }
      form.addEventListener('change', sync); sync();
      var t = document.getElementById('tendered');
      t.addEventListener('input', function () { var c = (parseFloat(t.value) || 0) - total; document.getElementById('change').textContent = c > 0 ? c.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00'; });
      form.addEventListener('submit', function () { var btn = form.querySelector('[data-once]'); setTimeout(function () { btn.disabled = true; }, 0); });
    });
    </script>
    <?php
    layout_end();
    return;
}

// ================================================================== the booking form of a voyage
$v = $voyageId ? Ship::voyage($voyageId) : null;
if ($v) {
    Booking::expireHolds((int)$v['id']);
    $cap = Booking::capacity($v);
    $limit = Booking::paxLimit($v);
    $taken = array_sum(array_column($cap, 'taken'));
    $onSale = in_array($v['status'], ['on_sale', 'boarding'], true);
    $close = $v['booking_close'] ?: date('Y-m-d H:i:s', strtotime($v['etd']) - 60 * Ship::intSetting('booking_close_minutes', 30));
    $closed = Ship::now() > $close;
    $cats = DB::all('SELECT * FROM shp_fare_categories WHERE is_active = 1 ORDER BY sort_order, name');
    $vclasses = (float)$v['lane_meters'] > 0 ? DB::all('SELECT * FROM shp_vehicle_classes WHERE is_active = 1 ORDER BY sort_order, id') : [];
    $canOverride = (bool)Access::special('shp_fare_override');
    $offices = Ship::userOffices();
    $accounts = DB::pairs("SELECT c.id, c.name FROM customers c LEFT JOIN customer_types t ON t.id = c.customer_type_id
                            WHERE c.is_active = 1 AND c.id <> :w AND (t.name IS NULL OR t.name <> 'Walk-in Passenger') ORDER BY c.name", ['w' => ShipGL::walkInCustomer()]);
    $paxRows = is_post() ? array_filter((array)($_POST['pax'] ?? []), 'is_array') : [];
    foreach ($paxRows as $k => $r) {
        $paxRows[$k]['_override'] = $_POST['override'][$k] ?? '';
    }
    if (!$paxRows) {
        $paxRows = ['0' => ['category_id' => Fares::regularCategory(), 'accom_code' => array_key_first(array_filter($cap, fn($c) => $c['left'] > 0)) ?? '', 'nationality' => 'Filipino']];
    }
    $vehRows = is_post() ? array_filter((array)($_POST['veh'] ?? []), 'is_array') : [];
    $catJs = [];
    foreach ($cats as $c) {
        $catJs[$c['id']] = ['id' => (int)$c['requires_id'], 'label' => $c['id_label'] ?: 'ID number', 'min' => $c['min_age'] !== null ? (int)$c['min_age'] : null,
            'max' => $c['max_age'] !== null ? (int)$c['max_age'] : null, 'inf' => strtoupper($c['code']) === 'INF'];
    }
    layout_start('Booking Desk', ['actions' => btn_link(url($page), 'Another voyage', 'btn-outline-secondary', 'search')]);
    ?>
    <form method="post" id="bookForm" autocomplete="off" data-api="<?= h(url('shp.api')) ?>" data-voyage="<?= (int)$v['id'] ?>">
    <?= csrf_field() ?><input type="hidden" name="act" value="hold"><input type="hidden" name="voyage_id" value="<?= (int)$v['id'] ?>">
    <div class="row g-3">
      <div class="col-xl-8">
        <?= voyage_card($v, null, '<div class="small text-muted">' . h($v['route_name']) . '</div><div class="small">' . ($closed ? '<span class="st st-bad">Sales closed</span>' : 'Sales close ' . h(date('M j g:i A', strtotime($close)))) . '</div>') ?>
        <?php if (!$onSale || $closed): ?>
          <div class="alert alert-warning mt-3">This voyage is <?= strtolower(strip_tags(Ship::label($v['status']))) ?><?= $closed ? ' and its sales closed at ' . h(fdatetime($close)) : '' ?>. Holding places is refused; choose another voyage.</div>
        <?php endif; ?>
        <div class="card reveal mt-3"><div class="card-header">Who is booking</div><div class="card-body"><div class="row g-2">
          <?= input_field('contact_name', 'Name', p('contact_name'), ['class' => 'col-md-4', 'required' => true]) ?>
          <?= input_field('contact_phone', 'Mobile no. (for notices)', p('contact_phone'), ['class' => 'col-md-4', 'extra' => 'inputmode="tel"']) ?>
          <?= input_field('contact_email', 'Email (for the e-tickets)', p('contact_email'), ['class' => 'col-md-4', 'type' => 'email']) ?>
          <?= select_field('channel', 'Sale', ['counter' => 'Walk-in / counter', 'corporate' => 'Corporate account', 'agent' => 'Travel agent'], p('channel', 'counter'), ['class' => 'col-md-4', 'extra' => 'id="chanSel"']) ?>
          <?= select_field('account_id', 'Account / agent', $accounts, p('account_id'), ['class' => 'col-md-4 acct-field', 'blank' => '']) ?>
          <?= select_field('office_id', 'Ticket office', $offices, p('office_id', (string)(Cashier::current((int)Auth::id())['office_id'] ?? array_key_first($offices))), ['class' => 'col-md-4']) ?>
        </div></div></div>

        <div class="d-flex justify-content-between align-items-center mt-3 mb-2"><h2 class="h6 mb-0">Passengers</h2>
          <button type="button" class="btn btn-sm btn-outline-primary" id="addPax"><i class="bi bi-person-plus"></i> Add passenger</button></div>
        <div id="paxList"><?php foreach ($paxRows as $k => $r) { echo shp_bk_pax_card((string)$k, $r, $cats, $cap, $canOverride); } ?></div>
        <template id="paxTpl"><?= shp_bk_pax_card('__I__', ['category_id' => Fares::regularCategory(), 'accom_code' => '', 'nationality' => 'Filipino'], $cats, $cap, $canOverride) ?></template>

        <?php if ($vclasses): ?>
          <div class="d-flex justify-content-between align-items-center mt-3 mb-2"><h2 class="h6 mb-0">Vehicles</h2>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addVeh"><i class="bi bi-truck-front"></i> Add vehicle</button></div>
          <div id="vehList"><?php foreach ($vehRows as $k => $r) { echo shp_bk_veh_card((string)$k, $r, $vclasses); } ?></div>
          <template id="vehTpl"><?= shp_bk_veh_card('__I__', [], $vclasses) ?></template>
          <p class="small text-muted" id="vehEmpty" <?= $vehRows ? 'hidden' : '' ?>>No vehicle on this booking. The deck has <?= qty(max(0, Booking::laneLimit($v) - Booking::lanesUsed((int)$v['id']))) ?> lane meters left.</p>
        <?php endif; ?>

        <div class="card reveal mt-3"><div class="card-body"><div class="row g-2">
          <?= input_field('promo_code', 'Promo code', p('promo_code'), ['class' => 'col-md-4', 'extra' => 'data-q style="text-transform:uppercase"']) ?>
          <?php if ($canOverride): ?><?= input_field('override_reason', 'Reason for any fare override', p('override_reason'), ['class' => 'col-md-8']) ?><?php endif; ?>
          <?= input_field('notes', 'Notes', p('notes'), ['class' => 'col-12']) ?>
        </div></div></div>
      </div>

      <div class="col-xl-4"><div class="desk-side">
        <div class="card reveal mb-3"><div class="card-header d-flex justify-content-between"><span>Places left</span><span class="small text-muted"><?= $taken ?> of <?= $limit ?> taken</span></div><div class="card-body">
          <?php foreach ($cap as $c): $pc = $c['capacity'] ? 100 * $c['taken'] / $c['capacity'] : 0; ?>
            <div class="mb-2"><div class="d-flex justify-content-between small"><span class="fw-semibold"><?= h($c['name']) ?></span><span><?= (int)$c['left'] ?> left</span></div><?= meter($pc, $pc > 95 ? 'bad' : ($pc > 80 ? 'warn' : '')) ?></div>
          <?php endforeach; ?>
        </div></div>
        <div class="card reveal"><div class="card-header d-flex justify-content-between align-items-center"><span>Price</span><span class="small text-muted" id="qState"></span></div><div class="card-body">
          <div class="quote-box" id="quote"><div class="small text-muted">Enter the passengers to see the fare.</div></div>
          <div id="qNotes" class="small text-warning-emphasis mt-2"></div>
          <?php if (Access::can($page, 'create')): ?>
            <button class="btn btn-primary w-100 mt-3" <?= !$onSale || $closed ? 'disabled' : '' ?>><i class="bi bi-lock"></i> Hold the places</button>
            <div class="small text-muted mt-2">The places are held for <?= Ship::intSetting('counter_hold_minutes', 20) ?> minutes while the payment is taken. The whole group is held, or none of it.</div>
          <?php endif; ?>
        </div></div>
      </div></div>
    </div>
    <?php if (Access::can($page, 'create') && $onSale && !$closed): ?>
    <div class="desk-bar d-xl-none"><div><div class="small text-muted">Total to pay</div><b id="barTotal">-</b></div><button class="btn btn-primary"><i class="bi bi-lock"></i> Hold the places</button></div>
    <?php endif; ?>
    </form>

    <div class="modal fade" id="seatModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Choose a seat</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body text-center"><div id="seatWrap" class="seat-scroll"></div>
        <div class="seat-legend justify-content-center mt-3"><span><i></i>Free</span><span><i class="l-pr"></i>Priority (seniors, PWD)</span><span><i class="l-tk"></i>Taken</span><span><i class="l-pk"></i>This passenger</span></div></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" id="seatAuto">Give the next free seat</button><button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">Done</button></div>
    </div></div></div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var form = document.getElementById('bookForm');
      var api = form.getAttribute('data-api');
      var voyage = form.getAttribute('data-voyage');
      var cats = <?= json_encode($catJs) ?>;
      var depart = <?= json_encode(substr($v['etd'], 0, 10)) ?>;
      var paxList = document.getElementById('paxList');
      var n = 1000;
      function money(v) { return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
      function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
      function age(b) { if (!b) return null; var d = new Date(depart), x = new Date(b); var a = d.getFullYear() - x.getFullYear(); if (d.getMonth() < x.getMonth() || (d.getMonth() === x.getMonth() && d.getDate() < x.getDate())) a--; return a; }

      function refreshPax() {
        var cards = paxList.querySelectorAll('.pax-card');
        cards.forEach(function (c, i) {
          c.querySelector('.pax-n').textContent = i + 1;
          var cat = cats[c.querySelector('[data-cat]').value] || {};
          var idBox = c.querySelector('.id-box');
          idBox.hidden = !cat.id;
          c.querySelector('.id-label').textContent = cat.label || 'ID number';
          var acc = c.querySelector('[data-accom]');
          var opt = acc.options[acc.selectedIndex];
          var seatBtn = c.querySelector('.seat-btn');
          var reserved = opt && opt.getAttribute('data-reserved') === '1' && !cat.inf;
          seatBtn.disabled = !reserved;
          if (!reserved) { c.querySelector('[data-seat-id]').value = ''; c.querySelector('[data-seat-no-field]').value = ''; seatBtn.querySelector('span').textContent = cat.inf ? 'On lap, no seat' : 'Open seating'; }
          else if (!c.querySelector('[data-seat-id]').value) seatBtn.querySelector('span').textContent = 'Next free seat';
          var a = age(c.querySelector('[data-age]').value), note = c.querySelector('.age-note');
          var msg = '';
          if (a !== null) {
            msg = 'Age ' + a + ' on the day of the voyage';
            if (cat.min !== null && cat.min !== undefined && a < cat.min) msg += ', too young for this category';
            if (cat.max !== null && cat.max !== undefined && a > cat.max) msg += ', too old for this category';
          } else if ((cat.min !== null && cat.min !== undefined) || (cat.max !== null && cat.max !== undefined)) msg = 'Birth date needed for this category';
          note.textContent = msg;
          note.classList.toggle('text-danger', /too|needed/.test(msg));
          c.querySelector('.rm-pax').hidden = cards.length < 2;
        });
        // driver lists of the vehicles
        document.querySelectorAll('.driver-sel').forEach(function (s) {
          var keep = s.value || s.getAttribute('data-val') || '';
          s.innerHTML = '<option value="">None on this booking</option>';
          paxList.querySelectorAll('.pax-card').forEach(function (c, i) {
            var nm = (c.querySelector('[name$="[last_name]"]').value + ', ' + c.querySelector('[name$="[first_name]"]').value).replace(/^, |, $/g, '');
            var o = document.createElement('option'); o.value = c.getAttribute('data-pax'); o.textContent = (i + 1) + '. ' + (nm || 'Passenger');
            if (o.value === keep) o.selected = true;
            s.appendChild(o);
          });
          s.setAttribute('data-val', '');
        });
      }
      function add(listId, tplId, attr) {
        var html = document.getElementById(tplId).innerHTML.replace(/__I__/g, String(n++));
        var wrap = document.createElement('div'); wrap.innerHTML = html.trim();
        var card = wrap.firstChild;
        document.getElementById(listId).appendChild(card);
        return card;
      }
      document.getElementById('addPax').addEventListener('click', function () {
        var last = paxList.querySelector('.pax-card:last-child');
        var card = add('paxList', 'paxTpl');
        if (last) { // same class as the passenger before, the usual case for a family
          card.querySelector('[data-accom]').value = last.querySelector('[data-accom]').value;
          card.querySelector('[name$="[last_name]"]').value = last.querySelector('[name$="[last_name]"]').value;
        }
        refreshPax(); quoteSoon(); card.querySelector('[name$="[first_name]"]').focus();
      });
      var addVeh = document.getElementById('addVeh');
      if (addVeh) addVeh.addEventListener('click', function () { add('vehList', 'vehTpl'); document.getElementById('vehEmpty').hidden = true; refreshPax(); quoteSoon(); });
      form.addEventListener('click', function (e) {
        var rp = e.target.closest('.rm-pax'), rv = e.target.closest('.rm-veh');
        if (rp) { rp.closest('.pax-card').remove(); refreshPax(); quoteSoon(); }
        if (rv) { rv.closest('.veh-card').remove(); var vl = document.getElementById('vehList'); if (vl && !vl.children.length) document.getElementById('vehEmpty').hidden = false; refreshPax(); quoteSoon(); }
        var sb = e.target.closest('.seat-btn'); if (sb) openSeats(sb.closest('.pax-card'));
      });
      form.addEventListener('change', function (e) {
        if (e.target.matches('.haz-chk')) e.target.closest('.veh-card').querySelector('.haz-box').hidden = !e.target.checked;
        if (e.target.matches('[data-accom]')) { var c = e.target.closest('.pax-card'); c.querySelector('[data-seat-id]').value = ''; c.querySelector('[data-seat-no-field]').value = ''; }
        refreshPax(); quoteSoon();
      });
      form.addEventListener('input', function (e) { if (e.target.matches('[data-q]')) { if (e.target.matches('[name$="_name]"]')) refreshPax(); quoteSoon(); } });
      var chan = document.getElementById('chanSel');
      function syncChan() { document.querySelector('.acct-field').hidden = chan.value === 'counter'; }
      chan.addEventListener('change', syncChan); syncChan();

      // ---------------- live itemized quote (nothing is saved)
      var qt = null, seq = 0;
      function quoteSoon() { clearTimeout(qt); qt = setTimeout(quote, 300); }
      function quote() {
        var fd = new FormData(form); fd.set('a', 'quote'); fd.delete('act');
        var my = ++seq;
        document.getElementById('qState').textContent = 'Pricing...';
        fetch(api + '&a=quote', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
          if (my !== seq) return;
          document.getElementById('qState').textContent = '';
          var box = document.getElementById('quote');
          if (!d.ok) { box.innerHTML = '<div class="small text-danger">' + esc(d.error) + '</div>'; return; }
          if (!d.lines.length) { box.innerHTML = '<div class="small text-muted">Enter the passengers to see the fare.</div>'; }
          else {
            var h = '<table class="w-100">';
            d.lines.forEach(function (l) {
              if (l.error) { h += '<tr><td colspan="2" class="text-danger small pt-1">' + esc(l.label) + ': ' + esc(l.error) + '</td></tr>'; return; }
              h += '<tr class="q-head"><td><b>' + esc(l.label) + '</b><div class="text-muted small">' + esc(l.sub) + (l.override ? ', fare overridden' : '') + '</div></td><td><b>' + money(l.total) + '</b></td></tr>';
              h += '<tr class="q-sub"><td>' + (l.kind === 'vehicle' ? 'Vehicle rate' : 'Published fare') + '</td><td>' + money(l.gross) + '</td></tr>';
              if (l.vat_exempt) { var out = Number(l.gross) - Number(l.discount) - Number(l.net) - Number(l.surcharge); if (out > 0.004) h += '<tr class="q-sub"><td>Less VAT (exempt)</td><td>(' + money(out) + ')</td></tr>'; }
              if (Number(l.discount) > 0) h += '<tr class="q-sub"><td>Less ' + (l.vat_exempt ? 'statutory discount' : 'discount') + '</td><td>(' + money(l.discount) + ')</td></tr>';
              h += '<tr class="q-sub"><td>' + (l.vat_exempt ? 'Fare (VAT-exempt)' : 'Fare net of VAT') + '</td><td>' + money(l.net) + '</td></tr>';
              if (Number(l.surcharge) > 0) h += '<tr class="q-sub"><td>Fuel surcharge</td><td>' + money(l.surcharge) + '</td></tr>';
              if (Number(l.vat) > 0) h += '<tr class="q-sub"><td>VAT 12%</td><td>' + money(l.vat) + '</td></tr>';
              (l.fees || []).forEach(function (f) { h += '<tr class="q-sub"><td>' + esc(f.name) + (f.owner !== 'principal' ? ' (third party)' : '') + '</td><td>' + money(f.amount) + '</td></tr>'; });
            });
            h += '<tr class="q-total"><td>Total to pay</td><td>₱' + money(d.totals.total) + '</td></tr></table>';
            var bt = document.getElementById('barTotal'); if (bt) bt.textContent = '₱' + money(d.totals.total);
            if (d.promo) h += '<div class="small text-muted mt-1">Promo ' + esc(d.promo) + '</div>';
            box.innerHTML = h;
            box.classList.remove('bump'); void box.offsetWidth; box.classList.add('bump');
          }
          document.getElementById('qNotes').innerHTML = (d.notes || []).map(function (x) { return '<div><i class="bi bi-info-circle"></i> ' + esc(x) + '</div>'; }).join('');
        }).catch(function () { document.getElementById('qState').textContent = 'Could not price; check the connection'; });
      }

      // ---------------- seat map
      var seatCard = null, modal = null;
      function openSeats(card) {
        seatCard = card;
        var code = card.querySelector('[data-accom]').value;
        var wrap = document.getElementById('seatWrap');
        wrap.innerHTML = '<div class="small text-muted py-4">Loading the seats...</div>';
        if (!modal) { var mEl = document.getElementById('seatModal'); document.body.appendChild(mEl); modal = new bootstrap.Modal(mEl); }
        modal.show();
        fetch(api + '&a=seatmap&voyage=' + voyage + '&class=' + encodeURIComponent(code), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
          if (!d.ok) { wrap.innerHTML = '<div class="text-danger small">' + esc(d.error) + '</div>'; return; }
          var mine = card.querySelector('[data-seat-id]').value;
          var others = {};
          paxList.querySelectorAll('.pax-card').forEach(function (c) { if (c !== card && c.querySelector('[data-seat-id]').value) others[c.querySelector('[data-seat-id]').value] = 1; });
          var aisle = Math.ceil(d.cols / 2);
          var cols = []; for (var i = 1; i <= d.cols; i++) { cols.push('34px'); if (i === aisle && d.cols > 2) cols.push('12px'); }
          var h = '<div class="seat-deck"><div class="bow">' + esc(d.class) + (d.deck ? ', ' + esc(d.deck) : '') + ' (bow)</div><div class="seat-grid" style="grid-template-columns:' + cols.join(' ') + '">';
          d.seats.forEach(function (s) {
            var colPos = s.c + (s.c > aisle && d.cols > 2 ? 1 : 0);
            var cls = 'seat' + (s.p ? ' priority' : '') + (String(s.id) === mine ? ' picked' : '') + ((s.t && String(s.id) !== mine) || others[s.id] ? ' taken' : '');
            h += '<button type="button" class="' + cls + '" style="grid-row:' + s.r + ';grid-column:' + colPos + '" data-id="' + s.id + '" data-no="' + esc(s.no) + '"' + ((s.t && String(s.id) !== mine) || others[s.id] ? ' disabled' : '') + ' title="' + esc(s.no) + '">' + esc(s.no) + '</button>';
          });
          wrap.innerHTML = h + '</div></div>';
        });
      }
      function setSeat(id, no) {
        if (!seatCard) return;
        seatCard.querySelector('[data-seat-id]').value = id || '';
        seatCard.querySelector('[data-seat-no-field]').value = no || '';
        seatCard.querySelector('.seat-btn span').textContent = no || 'Next free seat';
      }
      document.getElementById('seatWrap').addEventListener('click', function (e) {
        var s = e.target.closest('.seat'); if (!s || s.disabled) return;
        document.querySelectorAll('#seatWrap .seat.picked').forEach(function (x) { x.classList.remove('picked'); });
        s.classList.add('picked'); setSeat(s.getAttribute('data-id'), s.getAttribute('data-no'));
      });
      document.getElementById('seatAuto').addEventListener('click', function () { setSeat('', ''); document.querySelectorAll('#seatWrap .seat.picked').forEach(function (x) { x.classList.remove('picked'); }); });

      refreshPax(); quote();
    });
    </script>
    <?php
    layout_end();
    return;
}

// ================================================================== voyage search
$route = gint('route');
$date = gdate('date', Ship::today());
$days = max(1, min(7, (int)g('days', '3')));
$where = "v.status IN ('on_sale', 'boarding') AND v.voyage_date BETWEEN :f AND :t AND v.etd > :now";
$par = ['f' => $date, 't' => date('Y-m-d', strtotime($date . ' +' . ($days - 1) . ' days')), 'now' => Ship::now()];
if ($route) {
    $where .= ' AND v.route_id = :r';
    $par['r'] = $route;
}
$rows = DB::all("SELECT v.*, po.code origin_code, pd.code dest_code, r.name route_name, s.name vessel_name, s.pax_capacity, s.lane_meters
                   FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id
                   JOIN shp_vessels s ON s.id = v.vessel_id WHERE $where ORDER BY v.etd LIMIT 60", $par);
layout_start('Booking Desk', ['actions' => Access::page('tkt.bookings') ? btn_link(url('tkt.bookings'), 'Bookings & Tickets', 'btn-outline-secondary', 'journal-text') : '']);
$shift = Cashier::current((int)Auth::id());
?>
<div class="row g-3 mb-3">
  <div class="col-lg-8"><form class="card reveal h-100" method="get"><input type="hidden" name="p" value="<?= h($page) ?>"><div class="card-body"><div class="toolbar">
    <?= select_field('route', 'Route', Ship::routeOptions(), $route, ['class' => 'flex-fill', 'blank' => 'All routes']) ?>
    <?= input_field('date', 'Departing from', $date, ['type' => 'date', 'class' => '']) ?>
    <?= select_field('days', 'Days', ['1' => '1 day', '3' => '3 days', '7' => '7 days'], (string)$days, ['class' => '']) ?>
    <div><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Find voyages</button></div>
  </div></div></form></div>
  <div class="col-lg-4"><form class="card reveal h-100" method="get"><input type="hidden" name="p" value="<?= h($page) ?>"><div class="card-body">
    <label class="form-label">Pay a reserved booking (online or phone)</label>
    <div class="input-group input-group-sm"><input class="form-control text-uppercase" name="ref" placeholder="Booking reference" maxlength="12" required><button class="btn btn-outline-primary">Open</button></div>
    <div class="small mt-2"><?= $shift ? '<span class="st st-good">Shift ' . h($shift['shift_no']) . ' open</span> at ' . h($shift['office_name']) : '<span class="st st-warn">No cashier shift open</span>' . (Access::page('tkt.cashier') ? ' <a href="' . h(url('tkt.cashier')) . '">Open shift</a>' : '') ?></div>
  </div></form></div>
</div>
<div class="voyage-list">
<?php foreach ($rows as $r):
    $cap = Booking::capacity($r);
    $txt = [];
    foreach ($cap as $c) {
        $txt[] = '<span class="' . ($c['left'] < 1 ? 'text-danger' : '') . '">' . h($c['code']) . ' <b>' . (int)$c['left'] . '</b></span>';
    }
    $lim = Booking::paxLimit($r);
    $tk = array_sum(array_column($cap, 'taken'));
    $pc = $lim ? 100 * $tk / $lim : 0;
    $extra = '<div class="small class-left">' . implode(' ', $txt) . '</div><div style="min-width:150px" class="mt-1">' . meter($pc, $pc > 92 ? 'warn' : '') . '</div>'
        . '<div class="small text-muted mt-1">' . max(0, $lim - $tk) . ' places left</div>';
    echo voyage_card($r, url($page, ['voyage' => $r['id']]), $extra);
endforeach; ?>
<?php if (!$rows): ?><?= empty_note('No voyage on sale in these dates. Try another date or route.', 'calendar-x') ?><?php endif; ?>
</div>
<?php
layout_end();
