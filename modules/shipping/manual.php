<?php
declare(strict_types=1);

/*
 * Manual ticket contingency. When the system, the network or the power is down, the counter issues pre-numbered
 * paper tickets. Once the system is back every stub is recorded here (a series number used twice is flagged),
 * and each one is then either:
 *   encoded   - entered as a real booking and ticket (voyage still on sale), paid in cash into the issuing
 *               cashier's shift, so the drawer, the manifest and the ledger agree; or
 *   posted    - for a voyage that has already sailed, a cash sale posted straight to earned fare revenue
 *               (fees to their own payable accounts) and counted in the issuing cashier's shift; or
 *   linked    - matched to a ticket someone already re-entered at the Booking Desk; or
 *   void      - a spoiled or cancelled stub, with the reason.
 */
$page = 'tkt.manual';
$tab = (string)g('tab', 'open');
$me = (int)Auth::id();
$canRecord = Access::can($page, 'create');
$canWork = Access::can($page, 'edit');
$stNames = ['recorded' => 'To encode', 'duplicate' => 'Series used twice', 'encoded' => 'Encoded as booking', 'posted' => 'Posted (sailed)', 'linked' => 'Linked to ticket', 'void' => 'Void'];
$stTone = ['recorded' => 'warn', 'duplicate' => 'bad', 'encoded' => 'good', 'posted' => 'good', 'linked' => 'good', 'void' => 'muted'];

if (!function_exists('manual_split_name')) {
    /** "Dela Cruz, Juan" or "Juan Dela Cruz" into [last, first] */
    function manual_split_name(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if (str_contains($name, ',')) {
            [$l, $f] = array_map('trim', explode(',', $name, 2));
            return [$l, $f];
        }
        $parts = explode(' ', $name);
        if (count($parts) < 2) {
            return [$name, ''];
        }
        $last = array_pop($parts);
        return [$last, implode(' ', $parts)];
    }

    /** The open shift the cash of a manual ticket belongs to: the issuing cashier's, else the person encoding it */
    function manual_shift(int $issuedBy): ?array
    {
        return Cashier::current($issuedBy) ?: Cashier::current((int)Auth::id());
    }
}

handle_post(function () use ($page, $canRecord, $canWork, $me) {
    $act = (string)p('act');
    if ($act === 'record') {
        if (!$canRecord) {
            Access::denyPage($page, 'record manual tickets on');
        }
        $voyageId = (int)pint('voyage_id');
        $v = Ship::voyage($voyageId);
        if (!$v) {
            fail('Choose the voyage the stubs were sold for.');
        }
        $issuedBy = (int)pint('issued_by');
        $issuedAt = p('issued_at') ? date('Y-m-d H:i:s', strtotime((string)p('issued_at'))) : Ship::now();
        $note = trim((string)p('incident_note'));
        if ($note === '') {
            fail('Describe the outage (what was down, from when to when).');
        }
        $classes = array_column(Booking::capacity($v), 'name', 'code');
        $rows = [];
        foreach ((array)($_POST['rows'] ?? []) as $i => $r) {
            $series = strtoupper(trim((string)($r['series_no'] ?? '')));
            $name = trim((string)($r['passenger_name'] ?? ''));
            if ($series === '' && $name === '') {
                continue;
            }
            if ($series === '' || $name === '') {
                fail('Line ' . ((int)$i + 1) . ': enter both the stub number and the passenger name.');
            }
            $accom = (string)($r['accom_code'] ?? '');
            if (!isset($classes[$accom])) {
                fail('Line ' . ((int)$i + 1) . ': choose an accommodation class of ' . $v['vessel_name'] . '.');
            }
            $amt = to_num($r['amount'] ?? '');
            if ($amt <= 0) {
                fail('Line ' . ((int)$i + 1) . ': enter the amount written on the stub.');
            }
            if (in_array($series, array_column($rows, 0), true)) {
                fail('Stub ' . $series . ' is entered twice in this list.');
            }
            if (DB::value('SELECT 1 FROM shp_manual_tickets WHERE voyage_id = :v AND series_no = :s', ['v' => $voyageId, 's' => $series])) {
                fail('Stub ' . $series . ' is already recorded for this voyage. Nothing was saved; remove that line.');
            }
            $rows[] = [$series, $name, (int)($r['category_id'] ?? 0) ?: Fares::regularCategory(), $accom, r2($amt)];
        }
        if (!$rows) {
            fail('Enter at least one stub.');
        }
        $dup = 0;
        DB::tx(function () use ($rows, $voyageId, $issuedBy, $issuedAt, $note, $me, &$dup) {
            foreach ($rows as [$series, $name, $cat, $accom, $amt]) {
                $first = DB::value("SELECT id FROM shp_manual_tickets WHERE series_no = :s AND status <> 'void' ORDER BY id LIMIT 1", ['s' => $series]);
                $id = DB::insert('shp_manual_tickets', [
                    'voyage_id' => $voyageId, 'series_no' => mb_substr($series, 0, 30), 'passenger_name' => mb_substr($name, 0, 160), 'category_id' => $cat,
                    'accom_code' => $accom, 'amount' => $amt, 'issued_by' => $issuedBy ?: null, 'issued_at' => $issuedAt,
                    'status' => $first ? 'duplicate' : 'recorded', 'duplicate_of' => $first ?: null, 'incident_note' => mb_substr($note, 0, 255), 'created_by' => $me,
                ]);
                $dup += $first ? 1 : 0;
                Ship::audit('create', 'tkt', 'shp_manual_ticket', $id, 'Manual ticket ' . $series . ($first ? ' (series used twice)' : ''), ['amount' => $amt, 'reason' => $note, 'page_key' => 'tkt.manual']);
            }
            if ($dup) {
                Notify::staff('manual:dup:' . date('YmdHis'), 'manual_duplicate', ['Ticketing Manager', 'Internal Auditor'], 'Manual ticket number used twice',
                    $dup . ' manual stub(s) repeat a series number already recorded. Check them before encoding.', 'shp_manual_tickets', null);
            }
        });
        flash($dup ? 'warning' : 'success', count($rows) . ' stub(s) recorded.' . ($dup ? ' ' . $dup . ' repeat a series number already on file and are flagged for checking.' : ' Encode each one next.'));
        redirect(url($page));
    }

    if (!$canWork) {
        Access::denyPage($page, 'encode manual tickets on');
    }
    $mid = (int)pint('manual_id');
    $m = DB::row('SELECT * FROM shp_manual_tickets WHERE id = :id', ['id' => $mid]);
    if (!$m) {
        fail('Manual ticket not found.');
    }
    if (!in_array($m['status'], ['recorded', 'duplicate'], true)) {
        fail('Stub ' . $m['series_no'] . ' is already settled.');
    }
    if ($m['status'] === 'duplicate' && $act !== 'void' && p('confirm_dup') !== '1') {
        fail('Stub ' . $m['series_no'] . ' repeats a series number. Tick that you checked the physical stub before encoding it, or void it.');
    }
    $v = Ship::voyage((int)$m['voyage_id']);
    $who = (int)($m['issued_by'] ?: $me);

    if ($act === 'encode') {
        [$last, $first] = manual_split_name((string)p('passenger_name', $m['passenger_name']));
        if ($first === '') {
            fail('Write the passenger as "Surname, First name".');
        }
        $shift = manual_shift($who);
        if (!$shift) {
            fail('Neither the cashier who sold the stub nor you has an open shift. Open a shift first: the cash of the stub is counted in it.');
        }
        $tid = DB::tx(function () use ($m, $v, $last, $first, $shift, $me) {
            $bid = Booking::hold(['voyage_id' => $v['id'], 'channel' => 'counter', 'office_id' => (int)$shift['office_id'], 'contact_name' => $first . ' ' . $last,
                'contact_phone' => p('contact_no') ?: null, 'notes' => 'Manual ticket ' . $m['series_no'] . ' (' . $m['incident_note'] . ')'],
                [['last_name' => $last, 'first_name' => $first, 'birth_date' => pdate('birth_date'), 'gender' => p('gender') ?: null, 'nationality' => 'Filipino',
                    'contact_no' => p('contact_no') ?: null, 'id_no' => p('id_no') ?: null, 'category_id' => (int)$m['category_id'], 'accom_code' => $m['accom_code']]]);
            $total = (float)DB::value('SELECT total_amount FROM shp_bookings WHERE id = :b', ['b' => $bid]);
            Booking::pay($bid, ['channel' => 'cash', 'amount' => $total, 'shift_id' => (int)$shift['id'], 'office_id' => (int)$shift['office_id'], 'idempotency_key' => 'manual:' . $m['id']]);
            $tid = (int)DB::value('SELECT id FROM shp_tickets WHERE booking_id = :b ORDER BY id LIMIT 1', ['b' => $bid]);
            DB::update('shp_tickets', ['manual_ticket_no' => $m['series_no']], 'id = :id', ['id' => $tid]);
            DB::update('shp_manual_tickets', ['status' => 'encoded', 'ticket_id' => $tid], 'id = :id', ['id' => $m['id']]);
            $diff = r2((float)$m['amount'] - $total);
            Ship::audit('encode', 'tkt', 'shp_manual_ticket', (int)$m['id'], 'Manual ticket ' . $m['series_no'] . ' encoded as booking', ['amount' => $total,
                'reason' => abs($diff) >= 0.005 ? 'Stub ' . money($m['amount']) . ', fare table ' . money($total) . ' (difference ' . money($diff) . ')' : 'Amounts agree', 'page_key' => 'tkt.manual']);
            return [$tid, $diff, $shift['shift_no']];
        });
        [$t, $diff, $sh] = $tid;
        flash(abs($diff) >= 0.005 ? 'warning' : 'success', 'Encoded as e-ticket ' . DB::value('SELECT ticket_no FROM shp_tickets WHERE id = :t', ['t' => $t]) . ', cash counted in shift ' . $sh . '.'
            . (abs($diff) >= 0.005 ? ' The stub says ₱' . money($m['amount']) . ' but the fare table gives ₱' . money((float)$m['amount'] - $diff) . '; the difference will show on that shift\'s cash count.' : ''));
    } elseif ($act === 'post') {
        if (!in_array($v['status'], ['departed', 'arrived', 'completed'], true)) {
            fail('Voyage ' . $v['voyage_no'] . ' has not sailed; encode the stub as a booking instead.');
        }
        $cat = Fares::category((int)$m['category_id']);
        $q = Fares::quotePax($v, (string)$m['accom_code'], $cat);
        $amount = (float)$m['amount'];
        if ($amount + 0.005 < (float)$q['fees_total']) {
            fail('The stub amount is less than the fees alone (' . money($q['fees_total']) . ').');
        }
        $shift = manual_shift($who);
        $office = $shift ? (int)$shift['office_id'] : (int)(array_key_first(Ship::userOffices($who)) ?? 0);
        DB::tx(function () use ($m, $v, $cat, $q, $amount, $shift, $office) {
            $class = ShipGL::vesselClass((int)$v['vessel_id']);
            $nt = ShipGL::taxCode('NT');
            $lines = [];
            foreach ($q['fees'] as $f) {
                $lines[] = ShipGL::line(ShipGL::item('SHP-TFEE'), 'Fee on manual ticket ' . $m['series_no'], (float)$f['amount'], 0, $nt, (int)$f['account_id'], $class);
            }
            [$net, $vat] = ShipGL::splitVat(r2($amount - (float)$q['fees_total']), (int)$cat['vat_exempt'] === 1);
            $lines[] = ShipGL::line(ShipGL::item('SHP-FARE'), 'Fare on manual ticket ' . $m['series_no'] . ' (' . $m['passenger_name'] . ')', $net, $vat,
                ShipGL::taxCode((int)$cat['vat_exempt'] === 1 ? 'EX' : 'VAT12'), Accts::get('shp_fare_revenue'), $class);
            [$acct, $pmId] = ShipGL::channelAccounts('cash', $office ?: null);
            $memo = 'Manual ticket ' . $m['series_no'] . ', voyage ' . $v['voyage_no'] . ' (sold during an outage)';
            $txn = ShipGL::once('manual:' . $m['id'], ['source_type' => 'manual_ticket', 'source_id' => (int)$m['id'], 'voyage_id' => (int)$v['id'], 'vessel_id' => (int)$v['vessel_id'], 'memo' => $memo],
                fn() => ShipGL::salesReceipt(ShipGL::walkInCustomer(), Ship::today(), $acct, $pmId, $lines, $memo, $class, $m['series_no']));
            DB::insert('shp_payments', ['payment_no' => Ship::no('shp_payment', 'PAY-', 7), 'channel' => 'cash', 'payment_method_id' => $pmId, 'amount' => r2($amount),
                'status' => 'captured', 'shift_id' => $shift['id'] ?? null, 'office_id' => $office ?: null, 'txn_id' => $txn, 'received_by' => Auth::id(),
                'received_at' => Ship::now(), 'notes' => $memo]);
            DB::update('shp_manual_tickets', ['status' => 'posted'], 'id = :id', ['id' => $m['id']]);
            Ship::audit('post', 'tkt', 'shp_manual_ticket', (int)$m['id'], 'Manual ticket ' . $m['series_no'] . ' posted as a sailed sale', ['amount' => $amount, 'page_key' => 'tkt.manual']);
        });
        flash('success', 'Posted as a cash sale of a sailed voyage' . ($shift ? ', counted in shift ' . $shift['shift_no'] : '') . '. Add the passenger to the voyage manifest by hand if they are not on it.');
    } elseif ($act === 'link') {
        $tn = strtoupper(trim((string)p('ticket_no')));
        $t = DB::row('SELECT * FROM shp_tickets WHERE ticket_no = :n', ['n' => $tn]);
        if (!$t) {
            fail('No ticket ' . $tn . ' was found.');
        }
        if ((int)$t['voyage_id'] !== (int)$m['voyage_id']) {
            fail('Ticket ' . $tn . ' is for another voyage.');
        }
        if (DB::value('SELECT 1 FROM shp_manual_tickets WHERE ticket_id = :t AND id <> :m', ['t' => $t['id'], 'm' => $m['id']])) {
            fail('Ticket ' . $tn . ' is already linked to another stub.');
        }
        DB::tx(function () use ($m, $t) {
            DB::update('shp_manual_tickets', ['status' => 'linked', 'ticket_id' => $t['id']], 'id = :id', ['id' => $m['id']]);
            DB::update('shp_tickets', ['manual_ticket_no' => $m['series_no']], 'id = :id', ['id' => $t['id']]);
            Ship::audit('link', 'tkt', 'shp_manual_ticket', (int)$m['id'], 'Manual ticket ' . $m['series_no'] . ' linked to ' . $t['ticket_no'], ['page_key' => 'tkt.manual']);
        });
        flash('success', 'Linked to ticket ' . $tn . '.');
    } elseif ($act === 'void') {
        $why = trim((string)p('reason'));
        if ($why === '') {
            fail('Give the reason for voiding the stub.');
        }
        DB::update('shp_manual_tickets', ['status' => 'void', 'incident_note' => mb_substr($m['incident_note'] . ' | Void: ' . $why, 0, 255)], 'id = :id', ['id' => $m['id']]);
        Ship::audit('void', 'tkt', 'shp_manual_ticket', (int)$m['id'], 'Manual ticket ' . $m['series_no'] . ' voided', ['reason' => $why, 'page_key' => 'tkt.manual']);
        flash('success', 'Stub ' . $m['series_no'] . ' voided.');
    } else {
        fail('Unknown action.');
    }
    redirect(url($page));
});

$counts = DB::pairs('SELECT status, COUNT(*) FROM shp_manual_tickets GROUP BY status');
$openN = (int)($counts['recorded'] ?? 0) + (int)($counts['duplicate'] ?? 0);
$tabs = ['open' => ['To settle', url($page), $openN], 'done' => ['Settled', url($page, ['tab' => 'done']), null], 'record' => ['Record stubs', url($page, ['tab' => 'record']), null]];
if (!isset($tabs[$tab])) {
    $tab = 'open';
}
layout_start('Manual Ticket Contingency');
?>
<div class="kpi-grid mb-3">
  <?= kpi_tile('Stubs to settle', (string)$openN, 'journal-text', 'Recorded, not yet in the system', $openN ? 'warn' : 'good', url($page), (float)$openN) ?>
  <?= kpi_tile('Series used twice', (string)(int)($counts['duplicate'] ?? 0), 'files', 'Check the physical stubs', (int)($counts['duplicate'] ?? 0) ? 'bad' : 'good') ?>
  <?= kpi_tile('Settled', (string)((int)($counts['encoded'] ?? 0) + (int)($counts['posted'] ?? 0) + (int)($counts['linked'] ?? 0)), 'check2-circle', (int)($counts['void'] ?? 0) . ' void', 'good', url($page, ['tab' => 'done'])) ?>
</div>
<?= tabs_line($tabs, $tab) ?>
<?php if ($tab === 'record'):
    $voyOpts = Ship::voyageOptions(['on_sale', 'boarding', 'ready', 'departed', 'arrived', 'completed'], null, 3);
    $vSel = gint('voyage') ?: (int)p('voyage_id');
    $vSelRow = $vSel ? Ship::voyage($vSel) : null;
    $accoms = $vSelRow ? array_column(Booking::capacity($vSelRow), 'name', 'code') : DB::pairs('SELECT DISTINCT code, name FROM shp_accom_classes ORDER BY code');
    $cats = Fares::categoryOptions();
    $sellers = [];
    foreach (DB::all("SELECT id, full_name FROM users WHERE status = 'active' AND is_platform = 0 ORDER BY full_name") as $u) {
        if (Access::can('tkt.cashier', 'create', (int)$u['id'])) {
            $sellers[(int)$u['id']] = $u['full_name'];
        }
    } ?>
  <?php if (!$canRecord): ?><?= empty_note('Your role can see manual tickets but not record them.', 'lock') ?>
  <?php else: ?>
  <form method="get" class="toolbar mb-2"><input type="hidden" name="p" value="<?= h($page) ?>"><input type="hidden" name="tab" value="record">
    <?= select_field('voyage', 'Voyage the stubs were sold for', $voyOpts, $vSel, ['class' => '', 'blank' => '', 'extra' => 'data-autosubmit style="min-width:320px"']) ?>
    <div><button class="btn btn-sm btn-outline-primary">Use this voyage</button></div>
  </form>
  <?php if ($vSelRow): ?>
  <form method="post" class="card reveal"><?= csrf_field() ?><input type="hidden" name="act" value="record"><input type="hidden" name="voyage_id" value="<?= (int)$vSelRow['id'] ?>">
    <div class="card-header"><?= h(Ship::voyageTitle($vSelRow)) ?>, <?= h($vSelRow['vessel_name']) ?></div><div class="card-body">
    <div class="row g-2 mb-3">
      <?= select_field('issued_by', 'Sold by', $sellers, p('issued_by', $me), ['class' => 'col-md-4']) ?>
      <?= input_field('issued_at', 'Sold at (about)', p('issued_at', date('Y-m-d\TH:i')), ['type' => 'datetime-local', 'class' => 'col-md-3']) ?>
      <?= input_field('incident_note', 'The outage', p('incident_note'), ['class' => 'col-md-5', 'required' => true, 'extra' => 'placeholder="Power out at Pier 4, 6:40 to 7:55 AM"']) ?>
    </div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-2" id="stubRows"><thead><tr><th>Stub no.</th><th>Passenger (Surname, First name)</th><th>Category</th><th>Class</th><th class="num">Amount</th></tr></thead><tbody>
    <?php for ($i = 0; $i < 6; $i++): ?>
      <tr><td><input class="form-control form-control-sm" name="rows[<?= $i ?>][series_no]" style="min-width:110px" aria-label="Stub number"></td>
        <td><input class="form-control form-control-sm" name="rows[<?= $i ?>][passenger_name]" style="min-width:200px" aria-label="Passenger"></td>
        <td><select class="form-select form-select-sm" name="rows[<?= $i ?>][category_id]" aria-label="Category"><?= options($cats, Fares::regularCategory()) ?></select></td>
        <td><select class="form-select form-select-sm" name="rows[<?= $i ?>][accom_code]" aria-label="Class"><?= options($accoms, array_key_first($accoms)) ?></select></td>
        <td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="rows[<?= $i ?>][amount]" style="min-width:100px" aria-label="Amount"></td></tr>
    <?php endfor; ?>
    </tbody></table></div>
    <button type="button" class="btn btn-sm btn-link" id="addStub"><i class="bi bi-plus-lg"></i> Another line</button>
    <div class="mt-2"><button class="btn btn-primary btn-sm">Record the stubs</button>
      <span class="small text-muted ms-2">Copy each stub exactly, including spoiled ones (void them afterwards). A number already on file is flagged, never merged.</span></div>
  </div></form>
  <script>
  document.getElementById('addStub').addEventListener('click', function () {
    var body = document.querySelector('#stubRows tbody'), last = body.lastElementChild, row = last.cloneNode(true), n = body.children.length;
    row.querySelectorAll('[name]').forEach(function (el) { el.name = el.name.replace(/rows\[\d+\]/, 'rows[' + n + ']'); if (el.tagName === 'INPUT') { el.value = ''; } });
    body.appendChild(row);
  });
  </script>
  <?php endif; ?>
  <?php endif; ?>
<?php else:
    $where = $tab === 'open' ? "m.status IN ('recorded', 'duplicate')" : "m.status NOT IN ('recorded', 'duplicate')";
    $rows = DB::all("SELECT m.*, v.voyage_no, v.status voyage_status, v.etd, c.name cat_name, u.full_name seller, t.ticket_no, d.series_no dup_series
                       FROM shp_manual_tickets m JOIN shp_voyages v ON v.id = m.voyage_id JOIN shp_fare_categories c ON c.id = m.category_id
                       LEFT JOIN users u ON u.id = m.issued_by LEFT JOIN shp_tickets t ON t.id = m.ticket_id LEFT JOIN shp_manual_tickets d ON d.id = m.duplicate_of
                      WHERE $where ORDER BY " . ($tab === 'open' ? 'm.issued_at, m.series_no' : 'm.id DESC') . ' LIMIT 300'); ?>
  <?php if ($tab === 'open'): ?><p class="small text-muted">A stub for a voyage still on sale is encoded as a real booking, so the passenger is on the manifest and can board with an e-ticket. A stub for a voyage that already sailed is posted as a cash sale of that voyage. The cash is counted in the shift of the cashier who sold it (or yours, if theirs is closed).</p><?php endif; ?>
  <div class="row g-3">
  <?php foreach ($rows as $m):
      $sailed = in_array($m['voyage_status'], ['departed', 'arrived', 'completed'], true);
      $selling = in_array($m['voyage_status'], ['on_sale', 'boarding'], true); ?>
    <div class="col-xl-6"><div class="card reveal h-100"><div class="card-body">
      <div class="d-flex justify-content-between gap-3">
        <div class="min-w-0"><div class="fw-bold">Stub <?= h($m['series_no']) ?>, <?= h($m['passenger_name']) ?></div>
          <div class="small text-muted"><?= safe_link('ops.voyages', ['id' => $m['voyage_id']], $m['voyage_no']) ?> (<?= h(Ship::VOYAGE_STATUS[$m['voyage_status']] ?? $m['voyage_status']) ?>), <?= h($m['cat_name']) ?>, <?= h($m['accom_code']) ?>, sold by <?= h((string)$m['seller']) ?> <?= h(fdatetime($m['issued_at'])) ?></div>
          <div class="small text-muted"><?= h((string)$m['incident_note']) ?></div></div>
        <div class="text-end text-nowrap"><div class="fw-bold">₱<?= money($m['amount']) ?></div><span class="st st-<?= $stTone[$m['status']] ?? 'muted' ?>"><?= h($stNames[$m['status']] ?? $m['status']) ?></span>
          <?= $m['ticket_no'] ? '<div class="small">' . h($m['ticket_no']) . '</div>' : '' ?><?= $m['dup_series'] ? '<div class="small text-danger">same no. as an earlier stub</div>' : '' ?></div>
      </div>
      <?php if ($tab === 'open' && $canWork): ?>
      <div class="mt-2 pt-2 border-top">
        <?php if ($selling): ?>
          <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="act" value="encode"><input type="hidden" name="manual_id" value="<?= (int)$m['id'] ?>">
            <?= input_field('passenger_name', 'Surname, First name', str_contains($m['passenger_name'], ',') ? $m['passenger_name'] : implode(', ', array_reverse(manual_split_name($m['passenger_name']))), ['class' => 'col-md-5']) ?>
            <?= input_field('birth_date', 'Birth date (discounts)', '', ['type' => 'date', 'class' => 'col-md-3']) ?>
            <?= input_field('id_no', 'ID no. (discounts)', '', ['class' => 'col-md-2']) ?>
            <?= select_field('gender', 'Sex', ['M' => 'M', 'F' => 'F'], '', ['class' => 'col-md-2', 'blank' => '']) ?>
            <?php if ($m['status'] === 'duplicate'): ?><?= check_field('confirm_dup', 'I checked the physical stub', false, 'col-md-6') ?><?php endif; ?>
            <div class="col-md-6"><button class="btn btn-primary btn-sm">Encode as booking</button></div>
          </form>
        <?php elseif ($sailed): ?>
          <form method="post" class="d-flex flex-wrap gap-2 align-items-center"><?= csrf_field() ?><input type="hidden" name="act" value="post"><input type="hidden" name="manual_id" value="<?= (int)$m['id'] ?>">
            <?php if ($m['status'] === 'duplicate'): ?><label class="small"><input class="form-check-input" type="checkbox" name="confirm_dup" value="1"> I checked the physical stub</label><?php endif; ?>
            <button class="btn btn-primary btn-sm" data-confirm="Post stub <?= h($m['series_no']) ?> as a cash sale of the sailed voyage?">Post as sailed sale</button></form>
        <?php else: ?><div class="small text-muted">The voyage is <?= h(strtolower(Ship::VOYAGE_STATUS[$m['voyage_status']] ?? $m['voyage_status'])) ?>; link the stub to a ticket or void it.</div><?php endif; ?>
        <div class="row g-2 mt-1">
          <form method="post" class="col-md-6"><?= csrf_field() ?><input type="hidden" name="act" value="link"><input type="hidden" name="manual_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="confirm_dup" value="1">
            <div class="input-group input-group-sm"><input class="form-control" name="ticket_no" placeholder="Already re-entered as ET-..." required aria-label="Ticket number"><button class="btn btn-outline-secondary">Link</button></div></form>
          <form method="post" class="col-md-6"><?= csrf_field() ?><input type="hidden" name="act" value="void"><input type="hidden" name="manual_id" value="<?= (int)$m['id'] ?>">
            <div class="input-group input-group-sm"><input class="form-control" name="reason" placeholder="Why void (spoiled, cancelled)" required aria-label="Reason to void"><button class="btn btn-outline-danger">Void</button></div></form>
        </div>
      </div>
      <?php endif; ?>
    </div></div></div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="col-12"><?= empty_note($tab === 'open' ? 'No manual stub is waiting. Record stubs here after an outage.' : 'Nothing settled yet.', 'journal-check') ?></div><?php endif; ?>
  </div>
<?php endif; ?>
<?php
layout_end();
