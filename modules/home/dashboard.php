<?php
declare(strict_types=1);

/*
 * Home: what the logged-in person needs today, from live records only. Each panel shows only when the user's
 * role may open the page behind it, so a boarding officer, an engineer and the accountant each see their own.
 */
$today = Ship::today();
$now = Ship::now();
$uid = (int)Auth::id();
$canOps = Access::page('ops.voyages');
$canTkt = Access::page('tkt.bookings') || Access::page('tkt.booking');
$canFleet = Access::page('fleet.workorders') || Access::page('fleet.defects');
$canCare = Access::page('care.cases');
Booking::expireHolds();
if ($canFleet || $canOps) {
    Holds::syncCertificates();
}
if ($canCare) {
    Care::escalateOverdue();
}

$tiles = [];
if ($canOps || $canTkt) {
    $todayV = DB::all("SELECT v.id, v.status, v.vessel_id FROM shp_voyages v WHERE v.voyage_date = :d AND v.status <> 'cancelled'", ['d' => $today]);
    $atSea = (int)DB::value("SELECT COUNT(*) FROM shp_voyages WHERE status = 'departed'");
    $tiles[] = kpi_tile('Sailings today', (string)count($todayV), 'compass', $atSea . ' at sea now', '', $canOps ? url('ops.voyages', ['date' => $today]) : null, (float)count($todayV));
    $paxToday = DB::row("SELECT COUNT(*) n, SUM(t.status = 'boarded') b FROM shp_tickets t JOIN shp_voyages v ON v.id = t.voyage_id WHERE v.voyage_date = :d AND t.status IN ('issued', 'checked_in', 'boarded', 'no_show')", ['d' => $today]);
    $tiles[] = kpi_tile('Passengers today', number_format((int)$paxToday['n']), 'people', number_format((int)$paxToday['b']) . ' boarded so far', '', Access::page('tkt.manifest') ? url('tkt.manifest') : null, (float)$paxToday['n']);
}
if ($canTkt) {
    $sales = (float)DB::value("SELECT COALESCE(SUM(amount), 0) FROM shp_payments WHERE status IN ('captured', 'settled', 'authorized') AND received_at BETWEEN :a AND :b", ['a' => $today . ' 00:00:00', 'b' => $today . ' 23:59:59']);
    $n = (int)DB::value("SELECT COUNT(*) FROM shp_payments WHERE received_at BETWEEN :a AND :b", ['a' => $today . ' 00:00:00', 'b' => $today . ' 23:59:59']);
    $tiles[] = kpi_tile('Collections today', '₱' . number_format($sales), 'cash-coin', $n . ' payment(s), tickets and freight', 'good', Access::page('rpt.shp_sales') ? url('rpt.shp_sales', ['from' => $today, 'to' => $today]) : null, $sales);
    $lf = DB::row("SELECT COALESCE(SUM(x.sold), 0) sold, COALESCE(SUM(x.cap), 0) cap FROM (SELECT v.id, (SELECT COUNT(*) FROM shp_tickets t WHERE t.voyage_id = v.id AND t.status IN ('held', 'issued', 'checked_in', 'boarded')) sold,
                    COALESCE(v.pax_quota, s.pax_capacity) cap FROM shp_voyages v JOIN shp_vessels s ON s.id = v.vessel_id WHERE v.status IN ('on_sale', 'boarding', 'ready') AND v.etd BETWEEN :a AND :b) x",
        ['a' => $now, 'b' => date('Y-m-d H:i:s', strtotime($now . ' +7 days'))]);
    $pct = (float)$lf['cap'] > 0 ? 100 * (float)$lf['sold'] / (float)$lf['cap'] : 0;
    $tiles[] = kpi_tile('Booked, next 7 days', number_format((int)$lf['sold']), 'calendar2-week', round($pct, 1) . '% of the seats offered' . meter($pct), '', Access::page('rpt.shp_load_factor') ? url('rpt.shp_load_factor') : null, (float)$lf['sold']);
}
if ($canFleet || $canOps) {
    $holds = DB::all("SELECT h.*, s.name vessel FROM shp_holds h JOIN shp_vessels s ON s.id = h.vessel_id WHERE h.status = 'active' ORDER BY h.id");
    $tiles[] = kpi_tile('Safety holds', (string)count($holds), 'shield-exclamation', $holds ? h($holds[0]['vessel'] . ': ' . mb_strimwidth($holds[0]['reason'], 0, 60, '...')) : 'No vessel on hold', $holds ? 'bad' : 'good', Access::page('ops.holds') ? url('ops.holds') : null);
}
if ($canFleet) {
    $pmDue = Cmms::pmDue();
    $overdue = count(array_filter($pmDue, fn($p) => $p['overdue']));
    $openWo = (int)DB::value("SELECT COUNT(*) FROM shp_work_orders WHERE status NOT IN ('closed', 'cancelled', 'completed')");
    $tiles[] = kpi_tile('Open work orders', (string)$openWo, 'wrench-adjustable', count($pmDue) . ' planned task(s) due, ' . $overdue . ' overdue', $overdue ? 'warn' : '', url('fleet.workorders'), (float)$openWo);
}
if ($canCare) {
    $open = (int)DB::value("SELECT COUNT(*) FROM shp_cases WHERE status IN ('open', 'in_progress', 'waiting')");
    $late = (int)DB::value("SELECT COUNT(*) FROM shp_cases WHERE status IN ('open', 'in_progress', 'waiting') AND sla_due_at < :n", ['n' => $now]);
    $tiles[] = kpi_tile('Open support cases', (string)$open, 'headset', $late . ' past the service time', $late ? 'warn' : '', url('care.cases'), (float)$open);
}
if (Access::page('ops.approvals')) {
    $ap = ShipApproval::pendingCount();
    $tiles[] = kpi_tile('Approvals for you', (string)$ap, 'patch-check', 'Refunds, overrides, purchases', $ap ? 'warn' : 'good', url('ops.approvals'), (float)$ap);
}
if (Access::page('rpt.ar_aging_summary') || Access::page('cust.center')) {
    [$in, $p] = DB::in('a', Accts::arIds() ?: [0]);
    $ar = (float)DB::value("SELECT COALESCE(SUM(debit-credit),0) FROM journal_lines WHERE account_id IN $in", $p);
    $tiles[] = kpi_tile('Accounts receivable', '₱' . number_format($ar), 'people', 'Corporate, agent and cargo accounts', '', Access::page('rpt.ar_aging_summary') ? url('rpt.ar_aging_summary') : null, $ar);
}
if (Access::page('rpt.ap_aging_summary') || Access::page('vend.center')) {
    [$in, $p] = DB::in('a', Accts::apIds() ?: [0]);
    $apb = (float)DB::value("SELECT COALESCE(SUM(credit-debit),0) FROM journal_lines WHERE account_id IN $in", $p);
    $tiles[] = kpi_tile('Accounts payable', '₱' . number_format($apb), 'truck', 'Suppliers, yards, fuel, ports', '', Access::page('vend.pay_bills') ? url('vend.pay_bills') : null, $apb);
}
if (Access::page('coa.accounts') || Access::page('rpt.balance_sheet')) {
    $def = (float)DB::value('SELECT COALESCE(SUM(credit - debit), 0) FROM journal_lines WHERE account_id IN (:a, :b)', ['a' => Accts::get('shp_deferred_pax'), 'b' => Accts::get('shp_deferred_freight')]);
    $tiles[] = kpi_tile('Fares not yet earned', '₱' . number_format($def), 'hourglass-split', 'Paid fares and freight of voyages not yet completed', '', Access::page('rpt.general_ledger') ? url('rpt.general_ledger', ['account_id' => Accts::get('shp_deferred_pax')]) : null, $def);
}

layout_start('Home');
$shift = Access::page('tkt.cashier') ? Cashier::current($uid) : null;
?>
<?php if (Access::page('tkt.booking') && !$shift && Access::can('tkt.cashier', 'create')): ?>
  <div class="alert alert-info d-flex align-items-center gap-2 reveal"><i class="bi bi-cash-stack"></i><div>No cashier shift is open for you. <a href="<?= h(url('tkt.cashier')) ?>">Open your shift</a> before taking cash at the counter.</div></div>
<?php endif; ?>
<?php if ($tiles): ?><div class="kpi-grid mb-3"><?= implode('', $tiles) ?></div><?php endif; ?>

<div class="row g-3">
<?php if ($canOps || $canTkt): ?>
  <div class="col-xl-7">
    <div class="card reveal h-100"><div class="card-header d-flex justify-content-between align-items-center"><span>Sailings today and tomorrow</span>
      <?php if ($canOps): ?><a class="small" href="<?= h(url('ops.voyages')) ?>">All voyages</a><?php endif; ?></div>
    <div class="card-body"><div class="voyage-list">
    <?php
    $rows = DB::all("SELECT v.*, po.code origin_code, pd.code dest_code, s.name vessel_name, COALESCE(v.pax_quota, s.pax_capacity) cap,
                            (SELECT COUNT(*) FROM shp_tickets t WHERE t.voyage_id = v.id AND t.status IN ('issued', 'checked_in', 'boarded', 'no_show')) paid,
                            (SELECT COUNT(*) FROM shp_tickets t WHERE t.voyage_id = v.id AND t.status = 'boarded') boarded
                       FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id
                       JOIN shp_vessels s ON s.id = v.vessel_id WHERE (v.voyage_date BETWEEN :a AND :b) OR v.status IN ('departed', 'boarding', 'ready') ORDER BY v.etd LIMIT 12",
        ['a' => $today, 'b' => date('Y-m-d', strtotime($today . ' +1 day'))]);
    foreach ($rows as $r) {
        $lfp = $r['cap'] ? 100 * $r['paid'] / $r['cap'] : 0;
        $extra = '<div class="small"><b>' . (int)$r['paid'] . '</b> paid, <b>' . (int)$r['boarded'] . '</b> boarded</div><div style="min-width:140px">' . meter($lfp, $lfp > 92 ? 'warn' : '') . '</div>';
        echo voyage_card($r, $canOps ? url('ops.voyages', ['id' => $r['id']]) : (Access::page('tkt.manifest') ? url('tkt.manifest', ['voyage' => $r['id']]) : null), $extra);
    }
    if (!$rows) {
        echo empty_note('No sailing today or tomorrow.');
    }
    ?>
    </div></div></div>
  </div>
<?php endif; ?>
  <div class="<?= ($canOps || $canTkt) ? 'col-xl-5' : 'col-12' ?>">
    <div class="card reveal mb-3"><div class="card-header">Needs attention</div><div class="card-body p-0"><ul class="check-list px-3">
    <?php
    $items = [];
    foreach ($holds ?? [] as $hd) {
        $items[] = ['no', 'No-sail hold on ' . $hd['vessel'], $hd['reason'], Access::page('ops.holds') ? url('ops.holds') : null];
    }
    if ($canFleet || $canOps) {
        foreach (DB::all("SELECT c.*, s.name vessel FROM shp_certificates c JOIN shp_vessels s ON s.id = c.vessel_id WHERE c.expires_on BETWEEN :a AND :b ORDER BY c.expires_on LIMIT 5", ['a' => $today, 'b' => date('Y-m-d', strtotime($today . ' +30 days'))]) as $c) {
            $items[] = ['soft', $c['cert_type'] . ' of ' . $c['vessel'] . ' expires ' . fdate($c['expires_on']), ($c['is_critical'] ? 'Critical: an expired one stops the vessel. ' : '') . 'Renew it under Vessel Certificates.', Access::page('fleet.certificates') ? url('fleet.certificates') : null];
        }
        $crewExp = (int)DB::value('SELECT COUNT(*) FROM shp_crew_certs WHERE expires_on BETWEEN :a AND :b', ['a' => date('Y-m-d', strtotime($today . ' -60 days')), 'b' => date('Y-m-d', strtotime($today . ' +30 days'))]);
        if ($crewExp) {
            $items[] = ['soft', $crewExp . ' crew certificate(s) expired or expiring within 30 days', 'STCW, medical fitness and competency certificates', Access::page('rpt.shp_crew_certs') ? url('rpt.shp_crew_certs') : null];
        }
    }
    if ($canFleet) {
        foreach (DB::all("SELECT d.*, s.name vessel FROM shp_defects d JOIN shp_vessels s ON s.id = d.vessel_id WHERE d.status IN ('reported', 'triaged', 'work_order') ORDER BY d.safety_critical DESC, d.id DESC LIMIT 4") as $d) {
            $items[] = [$d['safety_critical'] ? 'no' : 'soft', 'Defect ' . $d['defect_no'] . ' on ' . $d['vessel'] . ': ' . $d['title'], strip_tags(Ship::label($d['status'])) . ', ' . $d['severity'], url('fleet.defects', ['id' => $d['id']])];
        }
    }
    if (Access::page('ops.fuel')) {
        foreach (DB::all("SELECT * FROM shp_fuel_logs WHERE status = 'pending' ORDER BY id DESC LIMIT 2") as $f) {
            $items[] = ['soft', 'Fuel sounding differs from the books by ' . qty($f['variance_l']) . ' L', 'Waiting for approval in the Approvals Queue', url('ops.approvals')];
        }
    }
    if ($canCare) {
        foreach (DB::all("SELECT * FROM shp_cases WHERE status IN ('open', 'in_progress', 'waiting') AND sla_due_at < :n ORDER BY sla_due_at LIMIT 3", ['n' => $now]) as $c) {
            $items[] = ['soft', 'Case ' . $c['case_no'] . ' past its service time', $c['subject'], url('care.cases', ['id' => $c['id']])];
        }
    }
    if (Access::page('tkt.refunds')) {
        $ready = (int)DB::value("SELECT COUNT(*) FROM shp_refunds WHERE status = 'approved'");
        if ($ready) {
            $items[] = ['soft', $ready . ' approved refund(s) waiting to be paid out', 'Refunds & Cancellations', url('tkt.refunds', ['tab' => 'approved'])];
        }
    }
    foreach ($items as [$tone, $title, $detail, $href]) {
        echo '<li><span class="ck ' . $tone . '"><i class="bi bi-' . ($tone === 'no' ? 'exclamation-lg' : 'clock') . '"></i></span><div>'
            . ($href ? '<a href="' . h($href) . '">' . h($title) . '</a>' : h($title)) . '<div class="detail">' . h($detail) . '</div></div></li>';
    }
    if (!$items) {
        echo '<li><span class="ck ok"><i class="bi bi-check-lg"></i></span><div>All clear<div class="detail">No hold, expiring certificate, open critical defect or late case.</div></div></li>';
    }
    ?>
    </ul></div></div>
    <?php if ($canTkt):
        $days = DB::pairs("SELECT DATE(received_at) d, SUM(amount) FROM shp_payments WHERE status IN ('captured', 'settled', 'authorized') AND received_at >= :f GROUP BY DATE(received_at) ORDER BY d",
            ['f' => date('Y-m-d', strtotime($today . ' -13 days')) . ' 00:00:00']);
        $max = $days ? max($days) : 0; ?>
    <div class="card reveal"><div class="card-header">Collections, last 14 days</div><div class="card-body">
      <div class="d-flex align-items-end gap-1" style="height:120px">
      <?php for ($i = 13; $i >= 0; $i--): $d = date('Y-m-d', strtotime($today . " -$i days")); $val = (float)($days[$d] ?? 0); $hgt = $max > 0 ? max(2, round(110 * $val / $max)) : 2; ?>
        <div class="flex-fill text-center" title="<?= h(fdate($d) . ': ' . money($val)) ?>"><div class="mx-auto rounded-top" style="height:<?= $hgt ?>px;width:70%;background:linear-gradient(180deg,#14a3ad,#0b6f7b);transition:height .9s"></div></div>
      <?php endfor; ?>
      </div>
      <div class="d-flex justify-content-between small text-muted mt-1"><span><?= h(date('M j', strtotime($today . ' -13 days'))) ?></span><span>Today</span></div>
    </div></div>
    <?php endif; ?>
  </div>
</div>
<?php
if (Access::special('view_bank_balances') && Access::page('bank.register')):
    $banks = DB::all("SELECT a.id, a.code, a.name, COALESCE(SUM(j.debit - j.credit),0) AS bal FROM accounts a LEFT JOIN journal_lines j ON j.account_id = a.id
                       WHERE a.account_type = 'Bank' AND a.is_active = 1 AND a.parent_id IS NOT NULL GROUP BY a.id, a.code, a.name ORDER BY a.code"); ?>
<div class="card reveal mt-3"><div class="card-header">Bank and cash position</div><div class="table-responsive"><table class="table table-sm mb-0">
  <tbody><?php foreach ($banks as $b): ?><tr><td><?= h($b['code'] . ' ' . $b['name']) ?></td><td class="num fw-semibold"><?= money($b['bal']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endif;
layout_end();
