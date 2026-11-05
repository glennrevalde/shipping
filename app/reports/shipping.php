<?php
declare(strict_types=1);

/**
 * Shipping reports (rpt.shp_*). Every figure is read from the shipping records (voyages, tickets, scans, manifests,
 * cargo, fuel, work orders, cases) or from the general ledger lines of the documents those records posted
 * (shp_postings), so the operating and the accounting views can be compared.
 *
 * Operations   shp_voyage_pnl, shp_vessel_pnl, shp_punctuality, shp_fuel, shp_crew_certs
 * Ticketing    shp_sales, shp_sold_boarded, shp_load_factor, shp_discounts, shp_refunds, shp_cashier, shp_advance
 * Cargo        shp_cargo_volume, shp_cargo_exceptions, shp_lane_util, shp_port_fees
 * Maintenance  shp_wo_status, shp_pm_due, shp_repair_spend, shp_reliability, shp_cert_expiry, shp_spares
 * Service      shp_cases
 * Control      shp_exceptions, shp_reconcile
 */

// ---------------------------------------------------------------------------------------------- shared pieces

/** A status as plain words (the screens show it as coloured text) */
function shprpt_st(?string $s, array $names = []): string
{
    return html_entity_decode(strip_tags(Ship::label($s, $names)), ENT_QUOTES, 'UTF-8');
}

function shprpt_route_opts(): array
{
    return Ship::routeOptions(false);
}

function shprpt_vessel_opts(): array
{
    return Ship::vesselOptions(false);
}

function shprpt_office_opts(): array
{
    return DB::pairs('SELECT id, name FROM shp_offices ORDER BY name');
}

function shprpt_voyage_opts(): array
{
    return DB::pairs("SELECT v.id, CONCAT(v.voyage_no, ' ', po.code, '-', pd.code) FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id
                        JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id ORDER BY v.etd DESC");
}

/** Route, vessel and (optionally) voyage drop-downs */
function shprpt_vfilters(bool $voyage = true): array
{
    $f = ['route' => ['Route', 'shprpt_route_opts'], 'vessel' => ['Vessel', 'shprpt_vessel_opts']];
    if ($voyage) {
        $f['voyage'] = ['Voyage', 'shprpt_voyage_opts'];
    }
    return $f;
}

function shprpt_vfrom(): string
{
    return 'shp_voyages v JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id
            JOIN shp_ports pd ON pd.id = r.dest_port_id JOIN shp_vessels s ON s.id = v.vessel_id';
}

function shprpt_vcols(): string
{
    return "v.id, v.voyage_no, v.voyage_date, v.etd, v.eta, v.atd, v.ata, v.status, v.sailing_hours, v.distance_nm, v.delay_minutes, v.delay_cause,
            v.pax_quota, v.vehicle_quota_lm, v.cargo_quota_t, v.fuel_planned_l, v.fuel_rob_departure, v.fuel_rob_arrival, v.vessel_id, v.route_id,
            r.code AS route_code, r.distance_nm AS route_nm, CONCAT(po.code, '-', pd.code) AS route_label, s.name AS vessel, s.code AS vessel_code,
            s.pax_capacity, s.lane_meters, s.cargo_capacity_t, s.class_id AS vessel_class_id, s.fuel_rate_lph";
}

/** WHERE of the voyages a report covers: the voyage date in the report dates (unless one voyage is asked for), the route and vessel */
function shprpt_vwhere(array $f, array &$p, bool $dates = true): string
{
    $w = ['1 = 1'];
    if (!empty($f['voyage'])) {
        $w[] = 'v.id = :fvoy';
        $p['fvoy'] = (int)$f['voyage'];
    } elseif ($dates && isset($f['from'])) {
        $w[] = 'v.voyage_date BETWEEN :fvf AND :fvt';
        $p['fvf'] = $f['from'];
        $p['fvt'] = $f['to'];
    }
    if (!empty($f['route'])) {
        $w[] = 'v.route_id = :fvr';
        $p['fvr'] = (int)$f['route'];
    }
    if (!empty($f['vessel'])) {
        $w[] = 'v.vessel_id = :fvs';
        $p['fvs'] = (int)$f['vessel'];
    }
    return implode(' AND ', $w);
}

/** "(:t0,:t1,...)" for a list of ids (never empty) */
function shprpt_in(array $ids, string $tag, array &$p): string
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return '(0)';
    }
    [$in, $ip] = DB::in($tag, $ids);
    $p += $ip;
    return $in;
}

/** Ticket counts of voyages: [voyage id => [status => n]] */
function shprpt_ticket_counts(array $ids): array
{
    $p = [];
    $in = shprpt_in($ids, 'tc', $p);
    $out = [];
    foreach (DB::all("SELECT voyage_id, status, COUNT(*) AS n FROM shp_tickets WHERE voyage_id IN $in GROUP BY voyage_id, status", $p) as $r) {
        $out[(int)$r['voyage_id']][$r['status']] = (int)$r['n'];
    }
    return $out;
}

function shprpt_n(array $counts, int $vid, array $statuses): int
{
    $n = 0;
    foreach ($statuses as $s) {
        $n += (int)($counts[$vid][$s] ?? 0);
    }
    return $n;
}

/** The passenger limit of a voyage: the vessel's capacity, or the voyage quota when lower */
function shprpt_pax_limit(array $v): int
{
    $cap = (int)$v['pax_capacity'];
    return $v['pax_quota'] !== null && (int)$v['pax_quota'] > 0 ? min($cap, (int)$v['pax_quota']) : $cap;
}

function shprpt_lane_limit(array $v): float
{
    $cap = (float)$v['lane_meters'];
    return $v['vehicle_quota_lm'] !== null && (float)$v['vehicle_quota_lm'] > 0 ? min($cap, (float)$v['vehicle_quota_lm']) : $cap;
}

function shprpt_sailed(string $status): bool
{
    return in_array($status, ['departed', 'arrived', 'completed'], true);
}

/** Income and expense of voyages from the ledger lines of the documents linked to them: [voyage id => [rev, cost]] */
function shprpt_ledger(array $ids): array
{
    $p = [];
    $in = shprpt_in($ids, 'vl', $p);
    $out = [];
    foreach (DB::all("SELECT x.voyage_id, a.category, SUM(j.credit - j.debit) AS net
                        FROM (SELECT DISTINCT voyage_id, txn_id FROM shp_postings WHERE voyage_id IN $in AND txn_id IS NOT NULL) x
                        JOIN transactions t ON t.id = x.txn_id AND t.is_voided = 0
                        JOIN journal_lines j ON j.txn_id = x.txn_id JOIN accounts a ON a.id = j.account_id
                       WHERE a.category IN ('Income', 'Expense') GROUP BY x.voyage_id, a.category", $p) as $r) {
        $vid = (int)$r['voyage_id'];
        $out[$vid] ??= ['rev' => 0.0, 'cost' => 0.0];
        if ($r['category'] === 'Income') {
            $out[$vid]['rev'] += (float)$r['net'];
        } else {
            $out[$vid]['cost'] -= (float)$r['net'];
        }
    }
    return $out;
}

/** Ledger lines of documents that are not linked to a voyage (overheads, vessel costs between voyages) */
function shprpt_nonvoyage_sql(): string
{
    return "FROM journal_lines j JOIN accounts a ON a.id = j.account_id JOIN transactions t ON t.id = j.txn_id AND t.is_voided = 0
            LEFT JOIN (SELECT DISTINCT txn_id FROM shp_postings WHERE voyage_id IS NOT NULL AND txn_id IS NOT NULL) vp ON vp.txn_id = j.txn_id
           WHERE vp.txn_id IS NULL AND j.txn_date BETWEEN :nf AND :nt";
}

/** [overhead pool, sailing hours of every voyage] of a period: expenses not linked to a voyage, spread over the hours sailed */
function shprpt_overhead(string $from, string $to): array
{
    $pool = (float)DB::value("SELECT COALESCE(SUM(j.debit - j.credit), 0) " . shprpt_nonvoyage_sql() . " AND a.category = 'Expense'", ['nf' => $from, 'nt' => $to]);
    $hours = (float)DB::value("SELECT COALESCE(SUM(sailing_hours), 0) FROM shp_voyages WHERE voyage_date BETWEEN :f AND :t AND status IN ('departed', 'arrived', 'completed')", ['f' => $from, 't' => $to]);
    return [r2($pool), $hours];
}

function shprpt_pct(float $part, float $whole): ?float
{
    return abs($whole) > 0.00001 ? round(100 * $part / $whole, 1) : null;
}

function shprpt_hours(?string $a, ?string $b): ?float
{
    if (!$a || !$b) {
        return null;
    }
    return round((strtotime($b) - strtotime($a)) / 3600, 2);
}

function shprpt_minutes(?string $a, ?string $b): ?int
{
    if (!$a || !$b) {
        return null;
    }
    return (int)round((strtotime($b) - strtotime($a)) / 60);
}

function shprpt_channel_label(string $c): string
{
    return ['counter' => 'Ticket office counter', 'portal' => 'Passenger portal', 'agent' => 'Travel agent', 'corporate' => 'Corporate account', 'phone' => 'Phone booking'][$c]
        ?? ucwords(str_replace('_', ' ', $c));
}

function shprpt_method_label(string $c): string
{
    return ['cash' => 'Cash', 'card' => 'Credit/debit card', 'ewallet' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer', 'gateway' => 'Online payment gateway',
        'check' => 'Check', 'agent' => 'Agent account (invoice)', 'corporate' => 'Corporate account (invoice)'][$c] ?? ucwords(str_replace('_', ' ', $c));
}

function shprpt_link_voyage(int $id): ?string
{
    return Report::link('ops.voyages', ['id' => $id]);
}

function shprpt_txn_link(?int $txnId, ?string $type): ?string
{
    return $txnId && $type ? Report::txnLink(['txn_type' => $type, 'txn_id' => $txnId]) : null;
}

/** "Expiring within" choices of the certificate reports */
function shprpt_within_opts(): array
{
    return ['30' => '30 days', '60' => '60 days', '90' => '90 days', '180' => '180 days', '365' => 'One year', 'all' => 'Any date (all certificates)'];
}

/** Totals of the money keys of rows */
function shprpt_sum(array $rows, array $keys): array
{
    $t = array_fill_keys($keys, 0.0);
    foreach ($rows as $r) {
        if (str_contains((string)($r['_class'] ?? ''), 'group-row') || str_contains((string)($r['_class'] ?? ''), 'subtotal-row')) {
            continue;
        }
        foreach ($keys as $k) {
            $t[$k] += is_numeric($r[$k] ?? null) ? (float)$r[$k] : 0.0;
        }
    }
    return array_map(fn($v) => round($v, 2), $t);
}

// ================================================================================================ OPERATIONS

Report::define('shp_voyage_pnl', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => shprpt_vfilters(),
    'checks' => ['full_cost' => 'Allocate overheads by sailing hours'],
    'run' => function (array $f): array {
        $p = [];
        $w = shprpt_vwhere($f, $p);
        $vs = DB::all('SELECT ' . shprpt_vcols() . ' FROM ' . shprpt_vfrom() . " WHERE $w AND v.status <> 'draft' ORDER BY v.etd", $p);
        $ids = array_map('intval', array_column($vs, 'id'));
        $led = shprpt_ledger($ids);
        $tk = shprpt_ticket_counts($ids);
        $full = ($f['full_cost'] ?? '') === '1';
        [$pool, $hoursAll] = $full ? shprpt_overhead($f['from'], $f['to']) : [0.0, 0.0];
        $rate = $hoursAll > 0 ? $pool / $hoursAll : 0.0;
        $rows = [];
        foreach ($vs as $v) {
            $vid = (int)$v['id'];
            $rev = r2($led[$vid]['rev'] ?? 0);
            $cost = r2($led[$vid]['cost'] ?? 0);
            $hours = (float)$v['sailing_hours'];
            $row = ['voyage' => $v['voyage_no'], 'date' => $v['voyage_date'], 'route' => $v['route_label'], 'vessel' => $v['vessel'], 'status' => shprpt_st($v['status']),
                'pax' => shprpt_n($tk, $vid, ['boarded']), 'hours' => $hours ?: null, 'revenue' => $rev, 'direct' => $cost, 'contribution' => r2($rev - $cost),
                'margin' => shprpt_pct($rev - $cost, $rev), '_links' => ['voyage' => shprpt_link_voyage($vid)]];
            if ($full) {
                $oh = r2($rate * $hours);
                $row += ['overhead' => $oh, 'net' => r2($rev - $cost - $oh), 'net_margin' => shprpt_pct($rev - $cost - $oh, $rev)];
            }
            $rows[] = $row;
        }
        $cols = [['key' => 'voyage', 'label' => 'Voyage'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'route', 'label' => 'Route'], ['key' => 'vessel', 'label' => 'Vessel'],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'pax', 'label' => 'Passengers boarded', 'type' => 'int'], ['key' => 'hours', 'label' => 'Sailing hours', 'type' => 'qty'],
            ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'money'], ['key' => 'direct', 'label' => 'Direct voyage costs', 'type' => 'money'],
            ['key' => 'contribution', 'label' => 'Gross contribution', 'type' => 'money'], ['key' => 'margin', 'label' => 'Margin', 'type' => 'pct']];
        $keys = ['revenue', 'direct', 'contribution'];
        if ($full) {
            $cols = array_merge($cols, [['key' => 'overhead', 'label' => 'Overhead allocated', 'type' => 'money'], ['key' => 'net', 'label' => 'Full-cost result', 'type' => 'money'],
                ['key' => 'net_margin', 'label' => 'Full-cost margin', 'type' => 'pct']]);
            $keys = array_merge($keys, ['overhead', 'net']);
        }
        $tot = shprpt_sum($rows, $keys);
        $totals = ['voyage' => 'TOTAL', 'pax' => array_sum(array_column($rows, 'pax')), 'hours' => array_sum(array_map('floatval', array_column($rows, 'hours')))] + $tot
            + ['margin' => shprpt_pct($tot['contribution'], $tot['revenue'])];
        if ($full) {
            $totals['net_margin'] = shprpt_pct($tot['net'], $tot['revenue']);
        }
        $notes = 'Revenue and direct costs are the income and expense lines of the documents posted for each voyage: the fares, surcharges, vehicle and cargo freight '
            . 'recognized when the voyage is completed, fuel issued, port charges, pilotage, crew allowances and other voyage costs. A voyage that has not sailed carries its fares '
            . 'as deferred revenue and shows no revenue yet.';
        if ($full) {
            $notes .= ' Overheads of ' . fdate($f['from']) . ' to ' . fdate($f['to']) . ' (expenses not linked to any voyage: ' . money($pool) . ') are spread over the '
                . qty($hoursAll) . ' hours sailed by every vessel in that period, ' . money($rate) . ' an hour.';
        }
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $totals, 'notes' => h($notes)];
    },
]);

Report::define('shp_vessel_pnl', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => ['by' => ['Show by', fn() => ['vessel' => 'Vessel', 'route' => 'Route', 'vessel_route' => 'Vessel and route'], null]] + shprpt_vfilters(false),
    'run' => function (array $f): array {
        $by = in_array($f['by'] ?? '', ['vessel', 'route', 'vessel_route'], true) ? $f['by'] : 'vessel';
        $p = [];
        $w = shprpt_vwhere($f, $p);
        $vs = DB::all('SELECT ' . shprpt_vcols() . ', r.name AS route_name FROM ' . shprpt_vfrom() . " WHERE $w AND v.status IN ('departed', 'arrived', 'completed') ORDER BY v.etd", $p);
        $ids = array_map('intval', array_column($vs, 'id'));
        $led = shprpt_ledger($ids);
        $tk = shprpt_ticket_counts($ids);
        $pv = [];
        $vin = shprpt_in($ids, 'vv', $pv);
        $veh = DB::pairs("SELECT voyage_id, COUNT(*) FROM shp_vehicles WHERE voyage_id IN $vin AND status IN ('loaded', 'unloaded') GROUP BY voyage_id", $pv);
        $cgo = DB::pairs("SELECT voyage_id, SUM(weight_kg) / 1000 FROM shp_cargo WHERE voyage_id IN $vin AND status NOT IN ('quotation', 'cancelled', 'booked', 'rolled_over') GROUP BY voyage_id", $pv);
        $g = [];
        foreach ($vs as $v) {
            $vid = (int)$v['id'];
            $key = match ($by) {
                'route' => $v['route_label'] . ' ' . $v['route_name'],
                'vessel_route' => $v['vessel'] . ', ' . $v['route_label'],
                default => $v['vessel'],
            };
            $g[$key] ??= ['voyages' => 0, 'hours' => 0.0, 'pax' => 0, 'veh' => 0, 'cargo' => 0.0, 'rev' => 0.0, 'cost' => 0.0, 'class' => (int)$v['vessel_class_id'], 'vessel_id' => (int)$v['vessel_id'], 'route_id' => (int)$v['route_id']];
            $g[$key]['voyages']++;
            $g[$key]['hours'] += (float)$v['sailing_hours'];
            $g[$key]['pax'] += shprpt_n($tk, $vid, ['boarded']);
            $g[$key]['veh'] += (int)($veh[$vid] ?? 0);
            $g[$key]['cargo'] += (float)($cgo[$vid] ?? 0);
            $g[$key]['rev'] += (float)($led[$vid]['rev'] ?? 0);
            $g[$key]['cost'] += (float)($led[$vid]['cost'] ?? 0);
        }
        // costs and income of each vessel between voyages: its class on the documents not linked to a voyage
        $other = [];
        if ($by === 'vessel') {
            foreach (DB::all('SELECT j.class_id, a.category, SUM(j.credit - j.debit) AS net ' . shprpt_nonvoyage_sql() . " AND j.class_id IS NOT NULL AND a.category IN ('Income', 'Expense')
                               GROUP BY j.class_id, a.category", ['nf' => $f['from'], 'nt' => $f['to']]) as $r) {
                $other[(int)$r['class_id']][$r['category']] = (float)$r['net'];
            }
            // a vessel with no voyage in the period still shows its costs
            foreach (DB::all('SELECT id, name, class_id FROM shp_vessels' . (!empty($f['vessel']) ? ' WHERE id = ' . (int)$f['vessel'] : '')) as $s) {
                if (!isset($g[$s['name']]) && isset($other[(int)$s['class_id']]) && empty($f['route'])) {
                    $g[$s['name']] = ['voyages' => 0, 'hours' => 0.0, 'pax' => 0, 'veh' => 0, 'cargo' => 0.0, 'rev' => 0.0, 'cost' => 0.0, 'class' => (int)$s['class_id'], 'vessel_id' => (int)$s['id'], 'route_id' => 0];
                }
            }
        }
        ksort($g);
        $rows = [];
        foreach ($g as $name => $x) {
            $contrib = r2($x['rev'] - $x['cost']);
            $row = ['name' => $name, 'voyages' => $x['voyages'], 'hours' => round($x['hours'], 2), 'pax' => $x['pax'], 'vehicles' => $x['veh'], 'cargo' => round($x['cargo'], 3),
                'revenue' => r2($x['rev']), 'direct' => r2($x['cost']), 'contribution' => $contrib, 'margin' => shprpt_pct($contrib, $x['rev']),
                'per_hour' => $x['hours'] > 0 ? r2($contrib / $x['hours']) : null,
                '_links' => ['name' => Report::link('rpt.shp_voyage_pnl', ['dates' => 'custom', 'from' => $f['from'], 'to' => $f['to']] + ($by !== 'route' ? ['vessel' => $x['vessel_id']] : []) + ($by !== 'vessel' ? ['route' => $x['route_id']] : []))]];
            if ($by === 'vessel') {
                $oi = r2($other[$x['class']]['Income'] ?? 0);
                $oc = r2(-($other[$x['class']]['Expense'] ?? 0));
                $row += ['other_income' => $oi, 'other_cost' => $oc, 'result' => r2($contrib + $oi - $oc)];
            }
            $rows[] = $row;
        }
        $cols = [['key' => 'name', 'label' => ['vessel' => 'Vessel', 'route' => 'Route', 'vessel_route' => 'Vessel and route'][$by]], ['key' => 'voyages', 'label' => 'Voyages sailed', 'type' => 'int'],
            ['key' => 'hours', 'label' => 'Sailing hours', 'type' => 'qty'], ['key' => 'pax', 'label' => 'Passengers', 'type' => 'int'], ['key' => 'vehicles', 'label' => 'Vehicles', 'type' => 'int'],
            ['key' => 'cargo', 'label' => 'Cargo (t)', 'type' => 'qty'], ['key' => 'revenue', 'label' => 'Voyage revenue', 'type' => 'money'], ['key' => 'direct', 'label' => 'Direct voyage costs', 'type' => 'money'],
            ['key' => 'contribution', 'label' => 'Contribution', 'type' => 'money'], ['key' => 'margin', 'label' => 'Margin', 'type' => 'pct'], ['key' => 'per_hour', 'label' => 'Contribution per sailing hour', 'type' => 'money']];
        $keys = ['revenue', 'direct', 'contribution'];
        if ($by === 'vessel') {
            $cols = array_merge($cols, [['key' => 'other_income', 'label' => 'Other vessel income', 'type' => 'money'], ['key' => 'other_cost', 'label' => 'Vessel costs between voyages', 'type' => 'money'],
                ['key' => 'result', 'label' => 'Vessel result', 'type' => 'money']]);
            $keys = array_merge($keys, ['other_income', 'other_cost', 'result']);
        }
        $tot = shprpt_sum($rows, $keys);
        $hours = array_sum(array_column($rows, 'hours'));
        $totals = ['name' => 'TOTAL', 'voyages' => array_sum(array_column($rows, 'voyages')), 'hours' => $hours, 'pax' => array_sum(array_column($rows, 'pax')),
            'vehicles' => array_sum(array_column($rows, 'vehicles')), 'cargo' => array_sum(array_column($rows, 'cargo')), 'margin' => shprpt_pct($tot['contribution'], $tot['revenue']),
            'per_hour' => $hours > 0 ? r2($tot['contribution'] / $hours) : null] + $tot;
        $notes = 'Voyages that sailed in the period. Voyage revenue and direct costs come from the documents linked to each voyage.';
        if ($by === 'vessel') {
            $notes .= ' "Vessel costs between voyages" are the expenses posted with the vessel\'s class that are not linked to a voyage: depreciation, hull and machinery insurance, repairs and spares of work orders, dry-docking; "Other vessel income" is onboard canteen and other income booked to the vessel.';
        }
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $totals, 'notes' => h($notes)];
    },
]);

Report::define('shp_punctuality', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => [
        'view' => ['Show', fn() => ['detail' => 'Each voyage', 'route' => 'By route', 'vessel' => 'By vessel', 'cause' => 'By delay cause'], null],
        'grace' => ['On time within', fn() => ['0' => 'On the minute', '5' => '5 minutes', '10' => '10 minutes', '15' => '15 minutes', '30' => '30 minutes'], null],
    ] + shprpt_vfilters(false),
    'filter_hook' => fn(array $f) => ['grace' => ($f['grace'] ?? '') !== '' ? $f['grace'] : '15'] + $f,
    'run' => function (array $f): array {
        $view = in_array($f['view'] ?? '', ['detail', 'route', 'vessel', 'cause'], true) ? $f['view'] : 'detail';
        $grace = ($f['grace'] ?? '') !== '' ? (int)$f['grace'] : 15;
        $p = [];
        $w = shprpt_vwhere($f, $p);
        $vs = DB::all('SELECT ' . shprpt_vcols() . ' FROM ' . shprpt_vfrom() . " WHERE $w AND v.atd IS NOT NULL ORDER BY v.etd", $p);
        $n = count($vs);
        $onTime = 0;
        $rows = [];
        $g = [];
        foreach ($vs as $v) {
            $dep = max(0, (int)shprpt_minutes($v['etd'], $v['atd']));
            $arr = $v['ata'] ? shprpt_minutes($v['eta'], $v['ata']) : null;
            $ok = $dep <= $grace;
            $onTime += $ok ? 1 : 0;
            if ($view === 'detail') {
                $rows[] = ['voyage' => $v['voyage_no'], 'route' => $v['route_label'], 'vessel' => $v['vessel'], 'etd' => $v['etd'], 'atd' => $v['atd'], 'dep_delay' => $dep,
                    'eta' => $v['eta'], 'ata' => $v['ata'], 'arr_delay' => $arr, 'on_time' => $ok ? 'Yes' : 'No', 'cause' => $dep > 0 ? ((string)$v['delay_cause'] ?: 'Not given') : '',
                    '_links' => ['voyage' => shprpt_link_voyage((int)$v['id'])]];
                continue;
            }
            $key = match ($view) {
                'route' => $v['route_label'],
                'vessel' => $v['vessel'],
                default => $dep > $grace ? ((string)$v['delay_cause'] ?: 'Cause not given') : 'On time',
            };
            $g[$key] ??= ['n' => 0, 'ok' => 0, 'sum' => 0, 'max' => 0, 'arr' => 0, 'arr_n' => 0];
            $g[$key]['n']++;
            $g[$key]['ok'] += $ok ? 1 : 0;
            $g[$key]['sum'] += $dep;
            $g[$key]['max'] = max($g[$key]['max'], $dep);
            if ($arr !== null) {
                $g[$key]['arr'] += $arr;
                $g[$key]['arr_n']++;
            }
        }
        $summary = 'On time (departed within ' . $grace . ' minute' . ($grace === 1 ? '' : 's') . ' of the schedule): ' . $onTime . ' of ' . $n . ' departures'
            . ($n ? ', ' . number_format(100 * $onTime / $n, 1) . '%.' : '.');
        if ($view === 'detail') {
            $cols = [['key' => 'voyage', 'label' => 'Voyage'], ['key' => 'route', 'label' => 'Route'], ['key' => 'vessel', 'label' => 'Vessel'], ['key' => 'etd', 'label' => 'Scheduled departure', 'type' => 'datetime'],
                ['key' => 'atd', 'label' => 'Actual departure', 'type' => 'datetime'], ['key' => 'dep_delay', 'label' => 'Departure delay (min)', 'type' => 'int'],
                ['key' => 'eta', 'label' => 'Scheduled arrival', 'type' => 'datetime'], ['key' => 'ata', 'label' => 'Actual arrival', 'type' => 'datetime'],
                ['key' => 'arr_delay', 'label' => 'Arrival delay (min)', 'type' => 'int'], ['key' => 'on_time', 'label' => 'On time'], ['key' => 'cause', 'label' => 'Delay cause']];
            $totals = ['voyage' => 'TOTAL', 'dep_delay' => array_sum(array_column($rows, 'dep_delay')), 'on_time' => $onTime . ' of ' . $n];
            return ['columns' => $cols, 'rows' => $rows, 'totals' => $totals, 'notes' => h($summary . ' Early arrivals show as a negative arrival delay.')];
        }
        ksort($g);
        foreach ($g as $k => $x) {
            $rows[] = ['name' => $k, 'departures' => $x['n'], 'on_time' => $x['ok'], 'pct' => shprpt_pct($x['ok'], $x['n']), 'avg' => round($x['sum'] / max(1, $x['n']), 1),
                'max' => $x['max'], 'total' => $x['sum'], 'arr' => $x['arr_n'] ? round($x['arr'] / $x['arr_n'], 1) : null];
        }
        $label = ['route' => 'Route', 'vessel' => 'Vessel', 'cause' => 'Delay cause'][$view];
        $cols = [['key' => 'name', 'label' => $label], ['key' => 'departures', 'label' => 'Departures', 'type' => 'int'], ['key' => 'on_time', 'label' => 'On time', 'type' => 'int'],
            ['key' => 'pct', 'label' => 'On-time rate', 'type' => 'pct'], ['key' => 'avg', 'label' => 'Average delay (min)', 'type' => 'qty'], ['key' => 'max', 'label' => 'Longest delay (min)', 'type' => 'int'],
            ['key' => 'total', 'label' => 'Delay minutes', 'type' => 'int'], ['key' => 'arr', 'label' => 'Average arrival delay (min)', 'type' => 'qty']];
        $sum = array_sum(array_column($rows, 'total'));
        $totals = ['name' => 'TOTAL', 'departures' => $n, 'on_time' => $onTime, 'pct' => shprpt_pct($onTime, $n), 'avg' => $n ? round($sum / $n, 1) : null, 'total' => $sum,
            'max' => $rows ? max(array_column($rows, 'max')) : null];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $totals, 'notes' => h($summary)];
    },
]);

Report::define('shp_fuel', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => ['view' => ['Show', fn() => ['voyage' => 'Consumption by voyage', 'vessel' => 'Consumption by vessel', 'rob' => 'Tank soundings (ROB variance)', 'bunkering' => 'Bunkering received'], null]]
        + shprpt_vfilters(false),
    'run' => function (array $f): array {
        $view = in_array($f['view'] ?? '', ['voyage', 'vessel', 'rob', 'bunkering'], true) ? $f['view'] : 'voyage';
        if ($view === 'rob' || $view === 'bunkering') {
            $p = ['f' => $f['from'], 't' => $f['to'] . ' 23:59:59'];
            $sql = "SELECT l.*, s.name AS vessel, vd.name AS vendor, t.txn_type, t.txn_no FROM shp_fuel_logs l JOIN shp_vessels s ON s.id = l.vessel_id
                      LEFT JOIN vendors vd ON vd.id = l.vendor_id LEFT JOIN transactions t ON t.id = l.txn_id
                     WHERE l.log_type = :lt AND l.log_at BETWEEN :f AND :t";
            $p['lt'] = $view === 'rob' ? 'adjustment' : 'bunkering';
            if (!empty($f['vessel'])) {
                $sql .= ' AND l.vessel_id = :vs';
                $p['vs'] = (int)$f['vessel'];
            }
            $rows = [];
            foreach (DB::all($sql . ' ORDER BY l.log_at, l.id', $p) as $l) {
                $doc = $l['txn_id'] ? Txn::label((string)$l['txn_type']) . ' ' . $l['txn_no'] : '';
                if ($view === 'rob') {
                    $rows[] = ['date' => $l['log_at'], 'vessel' => $l['vessel'], 'book' => (float)$l['rob_book'], 'sounding' => (float)$l['rob_sounding'], 'variance' => (float)$l['variance_l'],
                        'pct' => shprpt_pct((float)$l['variance_l'], (float)$l['rob_book']), 'amount' => (float)$l['amount'], 'status' => shprpt_st($l['status']), 'notes' => (string)$l['notes'],
                        'doc' => $doc, '_links' => ['doc' => shprpt_txn_link($l['txn_id'] ? (int)$l['txn_id'] : null, $l['txn_type'])]];
                } else {
                    $rows[] = ['date' => $l['log_at'], 'vessel' => $l['vessel'], 'vendor' => $l['vendor'], 'ref' => $l['ref_no'], 'liters' => (float)$l['liters'], 'unit' => (float)$l['unit_cost'],
                        'amount' => (float)$l['amount'], 'doc' => $doc, '_links' => ['doc' => shprpt_txn_link($l['txn_id'] ? (int)$l['txn_id'] : null, $l['txn_type'])]];
                }
            }
            if ($view === 'rob') {
                $cols = [['key' => 'date', 'label' => 'Sounded', 'type' => 'date'], ['key' => 'vessel', 'label' => 'Vessel'], ['key' => 'book', 'label' => 'Book ROB (L)', 'type' => 'qty'],
                    ['key' => 'sounding', 'label' => 'Sounding (L)', 'type' => 'qty'], ['key' => 'variance', 'label' => 'Variance (L)', 'type' => 'qty'], ['key' => 'pct', 'label' => 'Variance', 'type' => 'pct'],
                    ['key' => 'amount', 'label' => 'Value', 'type' => 'money'], ['key' => 'status', 'label' => 'Status'], ['key' => 'notes', 'label' => 'Notes'], ['key' => 'doc', 'label' => 'Adjustment']];
                $tot = ['date' => 'TOTAL', 'variance' => array_sum(array_column($rows, 'variance')), 'amount' => r2(array_sum(array_column($rows, 'amount')))];
                return ['columns' => $cols, 'rows' => $rows, 'totals' => $tot,
                    'notes' => h('Tank soundings against the book remaining-on-board. A variance is adjusted in the stock only after another person approves it.')];
            }
            $cols = [['key' => 'date', 'label' => 'Received', 'type' => 'date'], ['key' => 'vessel', 'label' => 'Vessel'], ['key' => 'vendor', 'label' => 'Supplier'], ['key' => 'ref', 'label' => 'Delivery receipt'],
                ['key' => 'liters', 'label' => 'Liters', 'type' => 'qty'], ['key' => 'unit', 'label' => 'Price per liter', 'type' => 'money'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'doc', 'label' => 'Bill']];
            $tl = array_sum(array_column($rows, 'liters'));
            $ta = r2(array_sum(array_column($rows, 'amount')));
            return ['columns' => $cols, 'rows' => $rows, 'totals' => ['date' => 'TOTAL', 'liters' => $tl, 'amount' => $ta, 'unit' => $tl > 0 ? round($ta / $tl, 4) : null]];
        }
        $p = [];
        $w = shprpt_vwhere($f, $p);
        $vs = DB::all('SELECT ' . shprpt_vcols() . ", l.liters, l.amount, l.variance_l, l.txn_id, t.txn_type
                         FROM " . shprpt_vfrom() . " JOIN shp_fuel_logs l ON l.voyage_id = v.id AND l.log_type = 'issue' AND l.status = 'posted'
                         LEFT JOIN transactions t ON t.id = l.txn_id WHERE $w ORDER BY v.etd", $p);
        $rows = [];
        $g = [];
        foreach ($vs as $v) {
            $used = -(float)$v['liters'];
            $hours = (float)$v['sailing_hours'];
            $planned = $v['fuel_planned_l'] !== null ? (float)$v['fuel_planned_l'] : ($hours > 0 && (float)$v['fuel_rate_lph'] > 0 ? round($hours * (float)$v['fuel_rate_lph'], 2) : null);
            $nm = (float)($v['distance_nm'] ?: $v['route_nm']);
            if ($view === 'voyage') {
                $rows[] = ['voyage' => $v['voyage_no'], 'date' => $v['voyage_date'], 'vessel' => $v['vessel'], 'route' => $v['route_label'], 'hours' => $hours ?: null, 'nm' => $nm ?: null,
                    'rob_dep' => $v['fuel_rob_departure'] !== null ? (float)$v['fuel_rob_departure'] : null, 'rob_arr' => $v['fuel_rob_arrival'] !== null ? (float)$v['fuel_rob_arrival'] : null,
                    'planned' => $planned, 'used' => $used, 'variance' => $planned !== null ? round($used - $planned, 2) : null, 'var_pct' => $planned ? shprpt_pct($used - $planned, $planned) : null,
                    'lph' => $hours > 0 ? round($used / $hours, 2) : null, 'lpnm' => $nm > 0 ? round($used / $nm, 2) : null, 'cost' => (float)$v['amount'],
                    '_links' => ['voyage' => shprpt_link_voyage((int)$v['id']), 'cost' => shprpt_txn_link($v['txn_id'] ? (int)$v['txn_id'] : null, $v['txn_type'])]];
                continue;
            }
            $k = $v['vessel'];
            $g[$k] ??= ['n' => 0, 'hours' => 0.0, 'nm' => 0.0, 'planned' => 0.0, 'used' => 0.0, 'cost' => 0.0];
            $g[$k]['n']++;
            $g[$k]['hours'] += $hours;
            $g[$k]['nm'] += $nm;
            $g[$k]['planned'] += (float)$planned;
            $g[$k]['used'] += $used;
            $g[$k]['cost'] += (float)$v['amount'];
        }
        if ($view === 'voyage') {
            $cols = [['key' => 'voyage', 'label' => 'Voyage'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'vessel', 'label' => 'Vessel'], ['key' => 'route', 'label' => 'Route'],
                ['key' => 'hours', 'label' => 'Sailing hours', 'type' => 'qty'], ['key' => 'nm', 'label' => 'Distance (nm)', 'type' => 'qty'], ['key' => 'rob_dep', 'label' => 'ROB at departure (L)', 'type' => 'qty'],
                ['key' => 'rob_arr', 'label' => 'ROB at arrival (L)', 'type' => 'qty'], ['key' => 'planned', 'label' => 'Planned (L)', 'type' => 'qty'], ['key' => 'used', 'label' => 'Used (L)', 'type' => 'qty'],
                ['key' => 'variance', 'label' => 'Variance (L)', 'type' => 'qty'], ['key' => 'var_pct', 'label' => 'Variance', 'type' => 'pct'], ['key' => 'lph', 'label' => 'Liters per hour', 'type' => 'qty'],
                ['key' => 'lpnm', 'label' => 'Liters per nm', 'type' => 'qty'], ['key' => 'cost', 'label' => 'Fuel cost', 'type' => 'money']];
            $tp = array_sum(array_map('floatval', array_column($rows, 'planned')));
            $tu = array_sum(array_column($rows, 'used'));
            $th = array_sum(array_map('floatval', array_column($rows, 'hours')));
            $totals = ['voyage' => 'TOTAL', 'hours' => $th, 'planned' => $tp, 'used' => $tu, 'variance' => round($tu - $tp, 2), 'var_pct' => $tp ? shprpt_pct($tu - $tp, $tp) : null,
                'lph' => $th > 0 ? round($tu / $th, 2) : null, 'cost' => r2(array_sum(array_column($rows, 'cost')))];
            $thr = (float)Ship::setting('fuel_variance_pct', '2');
            return ['columns' => $cols, 'rows' => $rows, 'totals' => $totals,
                'notes' => h('Fuel used is the ROB at departure less the ROB at arrival, issued from the vessel\'s tanks to Voyage Fuel. Planned fuel is the sailing hours times the vessel\'s rated consumption. Variances above ' . qty($thr) . '% are notified to the Fleet Manager and the Chief Engineer.')];
        }
        ksort($g);
        foreach ($g as $k => $x) {
            $rows[] = ['vessel' => $k, 'voyages' => $x['n'], 'hours' => round($x['hours'], 2), 'nm' => round($x['nm'], 1), 'planned' => round($x['planned'], 2), 'used' => round($x['used'], 2),
                'variance' => round($x['used'] - $x['planned'], 2), 'var_pct' => shprpt_pct($x['used'] - $x['planned'], $x['planned']), 'lph' => $x['hours'] > 0 ? round($x['used'] / $x['hours'], 2) : null,
                'lpnm' => $x['nm'] > 0 ? round($x['used'] / $x['nm'], 2) : null, 'cost' => r2($x['cost']), 'per_l' => $x['used'] > 0 ? round($x['cost'] / $x['used'], 4) : null];
        }
        $cols = [['key' => 'vessel', 'label' => 'Vessel'], ['key' => 'voyages', 'label' => 'Voyages', 'type' => 'int'], ['key' => 'hours', 'label' => 'Sailing hours', 'type' => 'qty'],
            ['key' => 'nm', 'label' => 'Distance (nm)', 'type' => 'qty'], ['key' => 'planned', 'label' => 'Planned (L)', 'type' => 'qty'], ['key' => 'used', 'label' => 'Used (L)', 'type' => 'qty'],
            ['key' => 'variance', 'label' => 'Variance (L)', 'type' => 'qty'], ['key' => 'var_pct', 'label' => 'Variance', 'type' => 'pct'], ['key' => 'lph', 'label' => 'Liters per hour', 'type' => 'qty'],
            ['key' => 'lpnm', 'label' => 'Liters per nm', 'type' => 'qty'], ['key' => 'cost', 'label' => 'Fuel cost', 'type' => 'money'], ['key' => 'per_l', 'label' => 'Cost per liter', 'type' => 'money']];
        $tu = array_sum(array_column($rows, 'used'));
        $tp = array_sum(array_column($rows, 'planned'));
        $tc = r2(array_sum(array_column($rows, 'cost')));
        return ['columns' => $cols, 'rows' => $rows, 'totals' => ['vessel' => 'TOTAL', 'voyages' => array_sum(array_column($rows, 'voyages')), 'hours' => array_sum(array_column($rows, 'hours')),
            'planned' => $tp, 'used' => $tu, 'variance' => round($tu - $tp, 2), 'var_pct' => shprpt_pct($tu - $tp, $tp), 'cost' => $tc, 'per_l' => $tu > 0 ? round($tc / $tu, 4) : null]];
    },
]);

Report::define('shp_crew_certs', [
    'filters' => [],
    'filter_hook' => fn(array $f) => ['within' => ($f['within'] ?? '') !== '' ? $f['within'] : '90'] + $f,
    'extra_filters' => ['within' => ['Expiring within', 'shprpt_within_opts', null], 'vessel' => ['Vessel', 'shprpt_vessel_opts'],
        'dept' => ['Department', fn() => ['deck' => 'Deck', 'engine' => 'Engine', 'hotel' => 'Hotel and catering']]],
    'checks' => ['required_only' => 'Required certificates only'],
    'run' => function (array $f): array {
        $within = ($f['within'] ?? '') !== '' ? (string)$f['within'] : '90';
        $today = Ship::today();
        $p = [];
        $sql = "SELECT cc.*, c.crew_no, c.last_name, c.first_name, c.rank_title, c.department, c.id AS crew_id, s.name AS vessel
                  FROM shp_crew_certs cc JOIN shp_crew c ON c.id = cc.crew_id LEFT JOIN shp_vessels s ON s.id = c.vessel_id
                 WHERE c.status = 'active'";
        if ($within !== 'all') {
            $sql .= ' AND cc.expires_on IS NOT NULL AND cc.expires_on <= :lim';
            $p['lim'] = date('Y-m-d', strtotime($today . ' +' . (int)$within . ' days'));
        }
        if (!empty($f['vessel'])) {
            $sql .= ' AND c.vessel_id = :vs';
            $p['vs'] = (int)$f['vessel'];
        }
        if (!empty($f['dept'])) {
            $sql .= ' AND c.department = :dp';
            $p['dp'] = (string)$f['dept'];
        }
        if (($f['required_only'] ?? '') === '1') {
            $sql .= ' AND cc.is_required = 1';
        }
        $rows = [];
        $expired = 0;
        foreach (DB::all($sql . ' ORDER BY cc.expires_on IS NULL, cc.expires_on, c.last_name', $p) as $c) {
            $days = $c['expires_on'] ? (int)floor((strtotime($c['expires_on']) - strtotime($today)) / 86400) : null;
            $state = $days === null ? 'No expiry' : ($days < 0 ? 'Expired' : ($days <= 30 ? 'Expiring within 30 days' : ($days <= 90 ? 'Expiring within 90 days' : 'Valid')));
            $expired += $days !== null && $days < 0 ? 1 : 0;
            $rows[] = ['crew_no' => $c['crew_no'], 'name' => $c['last_name'] . ', ' . $c['first_name'], 'rank' => $c['rank_title'], 'dept' => ucfirst((string)$c['department']), 'vessel' => $c['vessel'],
                'cert' => $c['cert_type'], 'cert_no' => $c['cert_no'], 'issued' => $c['issued_on'], 'expires' => $c['expires_on'], 'days' => $days, 'state' => $state,
                'required' => (int)$c['is_required'] ? 'Yes' : 'No', '_links' => ['name' => Report::link('ops.crew', ['id' => (int)$c['crew_id']])]];
        }
        $cols = [['key' => 'crew_no', 'label' => 'Crew no.'], ['key' => 'name', 'label' => 'Name'], ['key' => 'rank', 'label' => 'Rank'], ['key' => 'dept', 'label' => 'Department'], ['key' => 'vessel', 'label' => 'Vessel'],
            ['key' => 'cert', 'label' => 'Certificate'], ['key' => 'cert_no', 'label' => 'Number'], ['key' => 'issued', 'label' => 'Issued', 'type' => 'date'], ['key' => 'expires', 'label' => 'Expires', 'type' => 'date'],
            ['key' => 'days', 'label' => 'Days left', 'type' => 'int'], ['key' => 'state', 'label' => 'Standing'], ['key' => 'required', 'label' => 'Required for sailing']];
        return ['columns' => $cols, 'rows' => $rows, 'notes' => h(count($rows) . ' certificate' . (count($rows) === 1 ? '' : 's') . ' listed, ' . $expired . ' expired. A crew member with an expired required certificate fails the departure readiness check of the voyages he is signed on.')];
    },
]);

// ================================================================================================ TICKETING

Report::define('shp_sales', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => [
        'by' => ['Show by', fn() => ['date' => 'Date sold', 'route' => 'Route', 'voyage' => 'Voyage', 'office' => 'Ticket office', 'channel' => 'Sales channel', 'method' => 'Payment method',
            'category' => 'Passenger category', 'accom' => 'Accommodation class', 'seller' => 'Sold by'], null],
        'route' => ['Route', 'shprpt_route_opts'], 'office' => ['Office', 'shprpt_office_opts'],
        'channel' => ['Channel', fn() => ['counter' => 'Ticket office counter', 'portal' => 'Passenger portal', 'agent' => 'Travel agent', 'corporate' => 'Corporate account']],
        'category' => ['Category', fn() => DB::pairs('SELECT id, name FROM shp_fare_categories ORDER BY sort_order, name')],
    ],
    'run' => function (array $f): array {
        $by = ($f['by'] ?? '') !== '' ? (string)$f['by'] : 'date';
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $sql = "SELECT t.*, b.channel, b.office_id, b.created_by AS seller_id, o.name AS office, c.name AS cat_name, v.voyage_no, v.id AS vid, v.route_id,
                       CONCAT(po.code, '-', pd.code) AS route_label, COALESCE(ac.name, t.accom_code) AS accom_name,
                       (SELECT p.channel FROM shp_payments p WHERE p.booking_id = b.id AND p.status IN ('captured', 'authorized', 'settled') ORDER BY p.id LIMIT 1) AS method
                  FROM shp_tickets t JOIN shp_bookings b ON b.id = t.booking_id JOIN shp_fare_categories c ON c.id = t.category_id
                  JOIN shp_voyages v ON v.id = t.voyage_id JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id
                  LEFT JOIN shp_offices o ON o.id = b.office_id LEFT JOIN shp_accom_classes ac ON ac.vessel_id = v.vessel_id AND ac.code = t.accom_code
                 WHERE t.issued_at IS NOT NULL AND t.issued_at BETWEEN :f AND :t";
        foreach (['route' => 'v.route_id', 'office' => 'b.office_id', 'category' => 't.category_id'] as $k => $col) {
            if (!empty($f[$k])) {
                $sql .= " AND $col = :x$k";
                $p['x' . $k] = (int)$f[$k];
            }
        }
        if (!empty($f['channel'])) {
            $sql .= ' AND b.channel = :xch';
            $p['xch'] = (string)$f['channel'];
        }
        $g = [];
        foreach (DB::all($sql . ' ORDER BY t.issued_at', $p) as $t) {
            [$key, $label, $link] = match ($by) {
                'route' => [$t['route_label'], $t['route_label'], null],
                'voyage' => [$t['voyage_no'], $t['voyage_no'] . ' ' . $t['route_label'], shprpt_link_voyage((int)$t['vid'])],
                'office' => [(string)$t['office'], $t['office'] ?: '(no office)', null],
                'channel' => [$t['channel'], shprpt_channel_label((string)$t['channel']), null],
                'method' => [(string)$t['method'], $t['method'] ? shprpt_method_label((string)$t['method']) : '(not recorded)', null],
                'category' => [$t['cat_name'], $t['cat_name'], null],
                'accom' => [$t['accom_name'], $t['accom_name'], null],
                'seller' => [(string)$t['seller_id'], Ship::userName($t['seller_id'] ? (int)$t['seller_id'] : null) ?: 'Passenger portal', null],
                default => [substr($t['issued_at'], 0, 10), substr($t['issued_at'], 0, 10), null],
            };
            $g[$key] ??= ['label' => $label, 'link' => $link, 'n' => 0, 'gross' => 0.0, 'disc' => 0.0, 'net' => 0.0, 'vat' => 0.0, 'sur' => 0.0, 'fees' => 0.0, 'total' => 0.0, 'refunded' => 0];
            $x = &$g[$key];
            $x['n']++;
            $x['gross'] += (float)$t['fare_gross'];
            $x['disc'] += (float)$t['discount_amount'] + (float)$t['promo_amount'];
            $x['net'] += (float)$t['fare_net'];
            $x['vat'] += (float)$t['vat_amount'];
            $x['sur'] += (float)$t['surcharge_net'];
            $x['fees'] += (float)$t['fees_total'];
            $x['total'] += (float)$t['total'];
            $x['refunded'] += in_array($t['status'], ['refunded', 'cancelled'], true) ? 1 : 0;
            unset($x);
        }
        ksort($g);
        $rows = [];
        foreach ($g as $x) {
            $rows[] = ['name' => $by === 'date' ? fdate($x['label']) : $x['label'], 'tickets' => $x['n'], 'gross' => r2($x['gross']), 'disc' => r2($x['disc']), 'net' => r2($x['net']), 'vat' => r2($x['vat']),
                'sur' => r2($x['sur']), 'fees' => r2($x['fees']), 'total' => r2($x['total']), 'avg' => $x['n'] ? r2($x['total'] / $x['n']) : null, 'refunded' => $x['refunded'],
                '_links' => ['name' => $x['link']]];
        }
        $label = ['date' => 'Date sold', 'route' => 'Route', 'voyage' => 'Voyage', 'office' => 'Ticket office', 'channel' => 'Sales channel', 'method' => 'Payment method',
            'category' => 'Passenger category', 'accom' => 'Accommodation', 'seller' => 'Sold by'][$by] ?? 'Date sold';
        $cols = [['key' => 'name', 'label' => $label], ['key' => 'tickets', 'label' => 'Tickets', 'type' => 'int'], ['key' => 'gross', 'label' => 'Regular fare', 'type' => 'money'],
            ['key' => 'disc', 'label' => 'Discounts', 'type' => 'money'], ['key' => 'net', 'label' => 'Fare net of VAT', 'type' => 'money'], ['key' => 'vat', 'label' => 'VAT', 'type' => 'money'],
            ['key' => 'sur', 'label' => 'Fuel surcharge', 'type' => 'money'], ['key' => 'fees', 'label' => 'Terminal fees and insurance', 'type' => 'money'], ['key' => 'total', 'label' => 'Collected', 'type' => 'money'],
            ['key' => 'avg', 'label' => 'Average per ticket', 'type' => 'money'], ['key' => 'refunded', 'label' => 'Later refunded', 'type' => 'int']];
        $tot = shprpt_sum($rows, ['gross', 'disc', 'net', 'vat', 'sur', 'fees', 'total']);
        $n = array_sum(array_column($rows, 'tickets'));
        return ['columns' => $cols, 'rows' => $rows, 'totals' => ['name' => 'TOTAL', 'tickets' => $n, 'avg' => $n ? r2($tot['total'] / $n) : null, 'refunded' => array_sum(array_column($rows, 'refunded'))] + $tot,
            'notes' => h('Passenger tickets issued (paid) in the period, on the date they were issued. Terminal fees and passenger insurance are collected for the port and the insurer and are not revenue. Refunds are on the Refunds & Cancellations Register.')];
    },
]);

Report::define('shp_sold_boarded', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => shprpt_vfilters(),
    'checks' => ['only_diff' => 'Only voyages with a difference'],
    'run' => function (array $f): array {
        $p = [];
        $w = shprpt_vwhere($f, $p);
        $vs = DB::all('SELECT ' . shprpt_vcols() . ' FROM ' . shprpt_vfrom() . " WHERE $w AND v.status NOT IN ('draft', 'cancelled') ORDER BY v.etd", $p);
        $ids = array_map('intval', array_column($vs, 'id'));
        $tk = shprpt_ticket_counts($ids);
        $p2 = [];
        $in = shprpt_in($ids, 'sb', $p2);
        $scans = DB::pairs("SELECT voyage_id, COUNT(DISTINCT ticket_id) FROM shp_boarding_scans WHERE voyage_id IN $in AND scan_type = 'board' AND result = 'accepted' AND ticket_id IS NOT NULL GROUP BY voyage_id", $p2);
        $man = [];
        foreach (DB::all("SELECT m.voyage_id, m.version_no, m.pax_boarded, m.signed_at FROM shp_manifests m
                           WHERE m.voyage_id IN $in AND m.status = 'final'
                             AND m.version_no = (SELECT MAX(m2.version_no) FROM shp_manifests m2 WHERE m2.voyage_id = m.voyage_id AND m2.status = 'final')", $p2) as $m) {
            $man[(int)$m['voyage_id']] = $m;
        }
        $unpaid = DB::pairs("SELECT t.voyage_id, COUNT(*) FROM shp_tickets t WHERE t.voyage_id IN $in AND t.status = 'boarded'
                               AND NOT EXISTS (SELECT 1 FROM shp_payments p WHERE p.booking_id = t.booking_id AND p.status IN ('captured', 'authorized', 'settled')) GROUP BY t.voyage_id", $p2);
        $rows = [];
        foreach ($vs as $v) {
            $vid = (int)$v['id'];
            $paid = shprpt_n($tk, $vid, Ship::PAID_TICKET);
            $boarded = shprpt_n($tk, $vid, ['boarded']);
            $sc = (int)($scans[$vid] ?? 0);
            $m = $man[$vid] ?? null;
            $sailed = shprpt_sailed($v['status']);
            $issues = [];
            if ($sc !== $boarded) {
                $issues[] = 'boarded tickets ' . $boarded . ' against ' . $sc . ' accepted scans';
            }
            if ($sailed && !$m) {
                $issues[] = 'no final manifest';
            } elseif ($m && (int)$m['pax_boarded'] !== $boarded) {
                $issues[] = 'manifest shows ' . (int)$m['pax_boarded'];
            }
            if ((int)($unpaid[$vid] ?? 0) > 0) {
                $issues[] = (int)$unpaid[$vid] . ' boarded without payment';
            }
            if ($sailed && shprpt_n($tk, $vid, ['issued', 'checked_in']) > 0) {
                $issues[] = shprpt_n($tk, $vid, ['issued', 'checked_in']) . ' not closed as no-show';
            }
            if ($boarded > shprpt_pax_limit($v)) {
                $issues[] = 'boarded above the passenger limit';
            }
            if (($f['only_diff'] ?? '') === '1' && !$issues) {
                continue;
            }
            $rows[] = ['voyage' => $v['voyage_no'], 'date' => $v['voyage_date'], 'route' => $v['route_label'], 'status' => shprpt_st($v['status']),
                'held' => shprpt_n($tk, $vid, ['held']), 'paid' => $paid, 'cancelled' => shprpt_n($tk, $vid, ['cancelled', 'refunded', 'transferred']),
                'checked' => shprpt_n($tk, $vid, ['checked_in', 'boarded']), 'boarded' => $boarded, 'scans' => $sc, 'manifest' => $m ? (int)$m['pax_boarded'] : null,
                'no_show' => shprpt_n($tk, $vid, ['no_show']), 'result' => $issues ? ucfirst(implode('; ', $issues)) : 'Agrees', '_links' => ['voyage' => shprpt_link_voyage($vid)]];
        }
        $cols = [['key' => 'voyage', 'label' => 'Voyage'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'route', 'label' => 'Route'], ['key' => 'status', 'label' => 'Status'],
            ['key' => 'held', 'label' => 'Held unpaid', 'type' => 'int'], ['key' => 'paid', 'label' => 'Sold and paid', 'type' => 'int'], ['key' => 'cancelled', 'label' => 'Refunded or moved', 'type' => 'int'],
            ['key' => 'checked', 'label' => 'Checked in', 'type' => 'int'], ['key' => 'boarded', 'label' => 'Boarded', 'type' => 'int'], ['key' => 'scans', 'label' => 'Accepted boarding scans', 'type' => 'int'],
            ['key' => 'manifest', 'label' => 'Final manifest', 'type' => 'int'], ['key' => 'no_show', 'label' => 'No-shows', 'type' => 'int'], ['key' => 'result', 'label' => 'Result']];
        $totals = ['voyage' => 'TOTAL'];
        foreach (['held', 'paid', 'cancelled', 'checked', 'boarded', 'scans', 'no_show'] as $k) {
            $totals[$k] = array_sum(array_column($rows, $k));
        }
        $totals['manifest'] = array_sum(array_map('intval', array_column($rows, 'manifest')));
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $totals,
            'notes' => h('Each count is taken from its own records: tickets by their status, boarding from the scans the gate accepted, the manifest from the last final version. They should agree; a difference is shown in the last column.')];
    },
]);

Report::define('shp_load_factor', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => ['view' => ['Show', fn() => ['voyage' => 'Each voyage', 'route' => 'By route', 'vessel' => 'By vessel'], null]] + shprpt_vfilters(false),
    'run' => function (array $f): array {
        $view = in_array($f['view'] ?? '', ['voyage', 'route', 'vessel'], true) ? $f['view'] : 'voyage';
        $p = [];
        $w = shprpt_vwhere($f, $p);
        $vs = DB::all('SELECT ' . shprpt_vcols() . ' FROM ' . shprpt_vfrom() . " WHERE $w AND v.status NOT IN ('draft', 'cancelled') ORDER BY v.etd", $p);
        $ids = array_map('intval', array_column($vs, 'id'));
        $tk = shprpt_ticket_counts($ids);
        $p2 = [];
        $in = shprpt_in($ids, 'lf', $p2);
        $lanes = DB::pairs("SELECT x.voyage_id, SUM(c.lane_m) FROM shp_vehicles x JOIN shp_vehicle_classes c ON c.id = x.vehicle_class_id
                             WHERE x.voyage_id IN $in AND x.status IN ('held', 'issued', 'checked_in', 'loaded', 'unloaded', 'no_show') GROUP BY x.voyage_id", $p2);
        $cargo = DB::pairs("SELECT voyage_id, SUM(weight_kg) / 1000 FROM shp_cargo WHERE voyage_id IN $in AND status NOT IN ('quotation', 'cancelled', 'rolled_over') GROUP BY voyage_id", $p2);
        $rows = [];
        $g = [];
        foreach ($vs as $v) {
            $vid = (int)$v['id'];
            $sailed = shprpt_sailed($v['status']);
            $seats = shprpt_pax_limit($v);
            $carried = $sailed ? shprpt_n($tk, $vid, ['boarded']) : shprpt_n($tk, $vid, ['issued', 'checked_in', 'boarded']);
            $laneCap = shprpt_lane_limit($v);
            $laneUsed = (float)($lanes[$vid] ?? 0);
            $cgoCap = (float)$v['cargo_capacity_t'];
            $cgoT = (float)($cargo[$vid] ?? 0);
            if ($view === 'voyage') {
                $rows[] = ['voyage' => $v['voyage_no'], 'date' => $v['voyage_date'], 'route' => $v['route_label'], 'vessel' => $v['vessel'], 'status' => shprpt_st($v['status']),
                    'seats' => $seats, 'pax' => $carried, 'lf' => shprpt_pct($carried, $seats), 'lane_cap' => $laneCap ?: null, 'lane_used' => $laneCap > 0 ? round($laneUsed, 1) : null,
                    'lane_pct' => $laneCap > 0 ? shprpt_pct($laneUsed, $laneCap) : null, 'cargo_cap' => $cgoCap ?: null, 'cargo' => $cgoCap > 0 || $cgoT > 0 ? round($cgoT, 3) : null,
                    'cargo_pct' => $cgoCap > 0 ? shprpt_pct($cgoT, $cgoCap) : null, '_links' => ['voyage' => shprpt_link_voyage($vid)]];
                continue;
            }
            $k = $view === 'route' ? $v['route_label'] : $v['vessel'];
            $g[$k] ??= ['n' => 0, 'seats' => 0, 'pax' => 0, 'lane_cap' => 0.0, 'lane_used' => 0.0, 'cargo_cap' => 0.0, 'cargo' => 0.0];
            $g[$k]['n']++;
            $g[$k]['seats'] += $seats;
            $g[$k]['pax'] += $carried;
            $g[$k]['lane_cap'] += $laneCap;
            $g[$k]['lane_used'] += $laneUsed;
            $g[$k]['cargo_cap'] += $cgoCap;
            $g[$k]['cargo'] += $cgoT;
        }
        $notes = 'Passengers are those boarded for a voyage that sailed, and those holding a paid ticket for one that has not. Vehicle lanes count the lane meters of each vehicle class booked; cargo counts the declared weight.';
        if ($view === 'voyage') {
            $cols = [['key' => 'voyage', 'label' => 'Voyage'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'route', 'label' => 'Route'], ['key' => 'vessel', 'label' => 'Vessel'],
                ['key' => 'status', 'label' => 'Status'], ['key' => 'seats', 'label' => 'Passenger limit', 'type' => 'int'], ['key' => 'pax', 'label' => 'Passengers', 'type' => 'int'],
                ['key' => 'lf', 'label' => 'Passenger load factor', 'type' => 'pct'], ['key' => 'lane_cap', 'label' => 'Lane meters', 'type' => 'qty'], ['key' => 'lane_used', 'label' => 'Lane meters used', 'type' => 'qty'],
                ['key' => 'lane_pct', 'label' => 'Vehicle deck used', 'type' => 'pct'], ['key' => 'cargo_cap', 'label' => 'Cargo capacity (t)', 'type' => 'qty'], ['key' => 'cargo', 'label' => 'Cargo (t)', 'type' => 'qty'],
                ['key' => 'cargo_pct', 'label' => 'Cargo capacity used', 'type' => 'pct']];
            $s = array_sum(array_column($rows, 'seats'));
            $c = array_sum(array_column($rows, 'pax'));
            $lc = array_sum(array_map('floatval', array_column($rows, 'lane_cap')));
            $lu = array_sum(array_map('floatval', array_column($rows, 'lane_used')));
            return ['columns' => $cols, 'rows' => $rows, 'notes' => h($notes),
                'totals' => ['voyage' => 'TOTAL', 'seats' => $s, 'pax' => $c, 'lf' => shprpt_pct($c, $s), 'lane_cap' => $lc, 'lane_used' => $lu, 'lane_pct' => shprpt_pct($lu, $lc)]];
        }
        ksort($g);
        foreach ($g as $k => $x) {
            $rows[] = ['name' => $k, 'voyages' => $x['n'], 'seats' => $x['seats'], 'pax' => $x['pax'], 'lf' => shprpt_pct($x['pax'], $x['seats']), 'avg' => round($x['pax'] / max(1, $x['n']), 1),
                'lane_pct' => $x['lane_cap'] > 0 ? shprpt_pct($x['lane_used'], $x['lane_cap']) : null, 'cargo' => round($x['cargo'], 3), 'cargo_pct' => $x['cargo_cap'] > 0 ? shprpt_pct($x['cargo'], $x['cargo_cap']) : null];
        }
        $cols = [['key' => 'name', 'label' => $view === 'route' ? 'Route' : 'Vessel'], ['key' => 'voyages', 'label' => 'Voyages', 'type' => 'int'], ['key' => 'seats', 'label' => 'Seats offered', 'type' => 'int'],
            ['key' => 'pax', 'label' => 'Passengers', 'type' => 'int'], ['key' => 'lf', 'label' => 'Passenger load factor', 'type' => 'pct'], ['key' => 'avg', 'label' => 'Passengers per voyage', 'type' => 'qty'],
            ['key' => 'lane_pct', 'label' => 'Vehicle deck used', 'type' => 'pct'], ['key' => 'cargo', 'label' => 'Cargo (t)', 'type' => 'qty'], ['key' => 'cargo_pct', 'label' => 'Cargo capacity used', 'type' => 'pct']];
        $s = array_sum(array_column($rows, 'seats'));
        $c = array_sum(array_column($rows, 'pax'));
        return ['columns' => $cols, 'rows' => $rows, 'notes' => h($notes),
            'totals' => ['name' => 'TOTAL', 'voyages' => array_sum(array_column($rows, 'voyages')), 'seats' => $s, 'pax' => $c, 'lf' => shprpt_pct($c, $s), 'cargo' => array_sum(array_column($rows, 'cargo'))]];
    },
]);

Report::define('shp_discounts', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => [
        'category' => ['Category', fn() => DB::pairs('SELECT id, name FROM shp_fare_categories WHERE discount_pct > 0 ORDER BY sort_order, name')],
        'route' => ['Route', 'shprpt_route_opts'],
    ],
    'checks' => ['all_disc' => 'Include company discounts (child, infant, promo)'],
    'run' => function (array $f): array {
        $pii = Ship::canPii();
        $rate = ShipGL::vatRate();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $sql = "SELECT t.*, c.name AS cat_name, c.code AS cat_code, c.legal_basis, c.vat_exempt, c.is_statutory, v.voyage_no, v.voyage_date, v.id AS vid,
                       CONCAT(po.code, '-', pd.code) AS route_label, u.full_name AS verifier
                  FROM shp_tickets t JOIN shp_fare_categories c ON c.id = t.category_id JOIN shp_voyages v ON v.id = t.voyage_id
                  JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id
                  LEFT JOIN users u ON u.id = t.discount_verified_by
                 WHERE t.issued_at IS NOT NULL AND t.issued_at BETWEEN :f AND :t";
        $sql .= ($f['all_disc'] ?? '') === '1' ? ' AND (t.discount_amount > 0 OR t.promo_amount > 0)' : ' AND c.is_statutory = 1 AND t.discount_amount > 0';
        if (!empty($f['category'])) {
            $sql .= ' AND t.category_id = :cat';
            $p['cat'] = (int)$f['category'];
        }
        if (!empty($f['route'])) {
            $sql .= ' AND v.route_id = :rt';
            $p['rt'] = (int)$f['route'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY t.issued_at, t.id', $p) as $t) {
            $exemptVat = (int)$t['vat_exempt'] ? r2((float)$t['fare_gross'] - (float)$t['fare_gross'] / (1 + $rate / 100)) : 0.0;
            $idNo = $t['id_last4'] ? ($pii ? 'ending ' . $t['id_last4'] : '****') : '';
            $rows[] = ['date' => substr($t['issued_at'], 0, 10), 'ticket' => $t['ticket_no'], 'voyage' => $t['voyage_no'], 'travel' => $t['voyage_date'], 'route' => $t['route_label'],
                'name' => Ship::maskName((string)$t['last_name'], (string)$t['first_name']), 'age' => Ship::age($t['birth_date'], $t['voyage_date']),
                'category' => $t['cat_name'], 'id_type' => $t['id_type'], 'id_no' => $idNo, 'basis' => $t['legal_basis'],
                'gross' => (float)$t['fare_gross'], 'vat_exempt' => $exemptVat, 'discount' => r2((float)$t['discount_amount'] + (float)$t['promo_amount']), 'net' => (float)$t['fare_net'],
                'vat' => (float)$t['vat_amount'], 'verified' => (string)$t['verifier'], 'status' => shprpt_st($t['status']),
                '_links' => ['ticket' => Report::link('tkt.bookings', ['id' => (int)$t['booking_id']]), 'voyage' => shprpt_link_voyage((int)$t['vid'])]];
        }
        $cols = [['key' => 'date', 'label' => 'Date sold', 'type' => 'date'], ['key' => 'ticket', 'label' => 'Ticket'], ['key' => 'voyage', 'label' => 'Voyage'], ['key' => 'travel', 'label' => 'Travel date', 'type' => 'date'],
            ['key' => 'route', 'label' => 'Route'], ['key' => 'name', 'label' => 'Passenger'], ['key' => 'age', 'label' => 'Age', 'type' => 'int'], ['key' => 'category', 'label' => 'Category'],
            ['key' => 'id_type', 'label' => 'ID presented'], ['key' => 'id_no', 'label' => 'ID number'], ['key' => 'basis', 'label' => 'Legal basis'],
            ['key' => 'gross', 'label' => 'Regular fare', 'type' => 'money'], ['key' => 'vat_exempt', 'label' => 'VAT exempted', 'type' => 'money'], ['key' => 'discount', 'label' => 'Discount', 'type' => 'money'],
            ['key' => 'net', 'label' => 'Fare net of VAT', 'type' => 'money'], ['key' => 'vat', 'label' => 'VAT', 'type' => 'money'], ['key' => 'verified', 'label' => 'ID checked by'], ['key' => 'status', 'label' => 'Ticket status']];
        $tot = shprpt_sum($rows, ['gross', 'vat_exempt', 'discount', 'net', 'vat']);
        $notes = 'Fares given a discount required by law (senior citizens RA 9994, persons with disability RA 10754, students RA 11314, AFP/PNP Medal of Valor RA 9049), for the BIR and the LGU. '
            . 'Senior citizens and persons with disability are also exempt from VAT: the discount is taken from the fare net of VAT. '
            . ($pii ? '' : 'Names and ID numbers are masked: the full details need the permission to see passenger personal data.');
        return ['columns' => $cols, 'rows' => $rows, 'totals' => ['date' => 'TOTAL'] + $tot, 'notes' => h($notes)];
    },
]);

Report::define('shp_refunds', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => [
        'status' => ['Status', fn() => ['requested' => 'Requested', 'approved' => 'Approved, not yet paid', 'paid' => 'Paid', 'rejected' => 'Rejected']],
        'type' => ['Type', fn() => DB::pairs('SELECT DISTINCT refund_type, refund_type FROM shp_refunds ORDER BY refund_type')],
    ],
    'run' => function (array $f): array {
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $sql = "SELECT rf.*, b.booking_ref, t.ticket_no, t.last_name, t.first_name, x.vehicle_no, x.plate_no, v.voyage_no, v.id AS vid,
                       cm.txn_type AS cm_type, cm.txn_no AS cm_no, pt.txn_type AS pd_type, pt.txn_no AS pd_no
                  FROM shp_refunds rf JOIN shp_bookings b ON b.id = rf.booking_id
                  LEFT JOIN shp_tickets t ON t.id = rf.ticket_id LEFT JOIN shp_vehicles x ON x.id = rf.vehicle_id
                  LEFT JOIN shp_voyages v ON v.id = COALESCE(t.voyage_id, x.voyage_id)
                  LEFT JOIN transactions cm ON cm.id = rf.credit_txn_id LEFT JOIN transactions pt ON pt.id = rf.paid_txn_id
                 WHERE rf.requested_at BETWEEN :f AND :t";
        if (!empty($f['status'])) {
            $sql .= ' AND rf.status = :st';
            $p['st'] = (string)$f['status'];
        }
        if (!empty($f['type'])) {
            $sql .= ' AND rf.refund_type = :ty';
            $p['ty'] = (string)$f['type'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY rf.requested_at, rf.id', $p) as $r) {
            $what = $r['ticket_no'] ? $r['ticket_no'] . ' ' . Ship::maskName((string)$r['last_name'], (string)$r['first_name']) : ($r['vehicle_no'] ? $r['vehicle_no'] . ' ' . $r['plate_no'] : '');
            $rows[] = ['no' => $r['refund_no'], 'date' => substr($r['requested_at'], 0, 10), 'booking' => $r['booking_ref'], 'item' => $what, 'voyage' => $r['voyage_no'],
                'type' => ucwords(str_replace('_', ' ', (string)$r['refund_type'])), 'reason' => $r['reason'], 'original' => (float)$r['original_amount'], 'fee' => (float)$r['fee_retained'],
                'refund' => (float)$r['refund_amount'], 'method' => shprpt_method_label((string)$r['method']), 'status' => shprpt_st($r['status']),
                'requested_by' => Ship::userName($r['requested_by'] ? (int)$r['requested_by'] : null), 'approved_by' => Ship::userName($r['approved_by'] ? (int)$r['approved_by'] : null),
                'paid_by' => Ship::userName($r['paid_by'] ? (int)$r['paid_by'] : null), 'paid_at' => $r['paid_at'] ? substr($r['paid_at'], 0, 10) : '',
                'credit' => $r['cm_no'] ? Txn::label((string)$r['cm_type']) . ' ' . $r['cm_no'] : '', 'paid_doc' => $r['pd_no'] ? Txn::label((string)$r['pd_type']) . ' ' . $r['pd_no'] : '',
                '_links' => ['no' => Report::link('tkt.refunds', ['id' => (int)$r['id']]), 'booking' => Report::link('tkt.bookings', ['id' => (int)$r['booking_id']]),
                    'voyage' => $r['vid'] ? shprpt_link_voyage((int)$r['vid']) : null,
                    'credit' => shprpt_txn_link($r['credit_txn_id'] ? (int)$r['credit_txn_id'] : null, $r['cm_type']), 'paid_doc' => shprpt_txn_link($r['paid_txn_id'] ? (int)$r['paid_txn_id'] : null, $r['pd_type'])]];
        }
        $cols = [['key' => 'no', 'label' => 'Refund'], ['key' => 'date', 'label' => 'Requested', 'type' => 'date'], ['key' => 'booking', 'label' => 'Booking'], ['key' => 'item', 'label' => 'Ticket / vehicle'],
            ['key' => 'voyage', 'label' => 'Voyage'], ['key' => 'type', 'label' => 'Type'], ['key' => 'reason', 'label' => 'Reason'], ['key' => 'original', 'label' => 'Amount paid', 'type' => 'money'],
            ['key' => 'fee', 'label' => 'Fee retained', 'type' => 'money'], ['key' => 'refund', 'label' => 'Refund', 'type' => 'money'], ['key' => 'method', 'label' => 'Method'], ['key' => 'status', 'label' => 'Status'],
            ['key' => 'requested_by', 'label' => 'Requested by'], ['key' => 'approved_by', 'label' => 'Decided by'], ['key' => 'paid_by', 'label' => 'Paid by'], ['key' => 'paid_at', 'label' => 'Paid', 'type' => 'date'],
            ['key' => 'credit', 'label' => 'Credit memo'], ['key' => 'paid_doc', 'label' => 'Payment']];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => ['no' => 'TOTAL'] + shprpt_sum($rows, ['original', 'fee', 'refund']),
            'notes' => h('A refund is asked for by one person and approved by another; the approval raises the credit memo (the fee retained stays as Change and Cancellation Fees) and the payment is made from the ticket office cash or back to the card or e-wallet.')];
    },
]);

Report::define('shp_cashier', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => [
        'office' => ['Office', 'shprpt_office_opts'],
        'cashier' => ['Cashier', fn() => DB::pairs('SELECT DISTINCT u.id, u.full_name FROM shp_cashier_shifts s JOIN users u ON u.id = s.cashier_id ORDER BY u.full_name')],
        'status' => ['Status', fn() => ['open' => 'Open', 'closed' => 'Closed, not reviewed', 'reviewed' => 'Reviewed']],
    ],
    'checks' => ['undeposited' => 'Only shifts with cash not yet deposited'],
    'run' => function (array $f): array {
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $sql = 'SELECT s.*, o.name AS office, u.full_name AS cashier FROM shp_cashier_shifts s JOIN users u ON u.id = s.cashier_id LEFT JOIN shp_offices o ON o.id = s.office_id WHERE s.opened_at BETWEEN :f AND :t';
        foreach (['office' => 's.office_id', 'cashier' => 's.cashier_id'] as $k => $col) {
            if (!empty($f[$k])) {
                $sql .= " AND $col = :x$k";
                $p['x' . $k] = (int)$f[$k];
            }
        }
        if (!empty($f['status'])) {
            $sql .= ' AND s.status = :st';
            $p['st'] = (string)$f['status'];
        }
        $shifts = DB::all($sql . ' ORDER BY s.opened_at, s.id', $p);
        $ids = array_map('intval', array_column($shifts, 'id'));
        $p2 = [];
        $in = shprpt_in($ids, 'cs', $p2);
        $pay = [];
        foreach (DB::all("SELECT p.shift_id, p.channel, p.amount, p.received_at, dep.dd
                            FROM shp_payments p
                            LEFT JOIN (SELECT l.ref_txn_id, MAX(d.txn_date) AS dd FROM transaction_lines l JOIN transactions d ON d.id = l.txn_id AND d.txn_type = 'deposit' AND d.is_voided = 0
                                        WHERE l.ref_txn_id IS NOT NULL GROUP BY l.ref_txn_id) dep ON dep.ref_txn_id = p.txn_id
                           WHERE p.shift_id IN $in AND p.status IN ('captured', 'settled')", $p2) as $r) {
            $sid = (int)$r['shift_id'];
            $pay[$sid] ??= ['cash' => 0.0, 'noncash' => 0.0, 'dep' => 0.0, 'last_dep' => null, 'n' => 0];
            $pay[$sid]['n']++;
            if ($r['channel'] === 'cash') {
                $pay[$sid]['cash'] += (float)$r['amount'];
                if ($r['dd']) {
                    $pay[$sid]['dep'] += (float)$r['amount'];
                    $pay[$sid]['last_dep'] = max((string)$pay[$sid]['last_dep'], (string)$r['dd']);
                }
            } else {
                $pay[$sid]['noncash'] += (float)$r['amount'];
            }
        }
        $refunds = DB::pairs("SELECT shift_id, SUM(refund_amount) FROM shp_refunds WHERE shift_id IN $in AND status = 'paid' AND method = 'cash' GROUP BY shift_id", $p2);
        $rows = [];
        foreach ($shifts as $s) {
            $sid = (int)$s['id'];
            $x = $pay[$sid] ?? ['cash' => 0.0, 'noncash' => 0.0, 'dep' => 0.0, 'last_dep' => null, 'n' => 0];
            $ref = (float)($refunds[$sid] ?? 0);
            $undep = r2($x['cash'] - $x['dep']);
            if (($f['undeposited'] ?? '') === '1' && $undep < 0.005) {
                continue;
            }
            $open = $s['status'] === 'open';
            $now = r2((float)$s['opening_float'] + $x['cash'] - $ref);
            $late = $open ? 0.0 : r2($now - (float)$s['expected_cash']);
            $rows[] = ['shift' => $s['shift_no'], 'office' => $s['office'], 'cashier' => $s['cashier'], 'opened' => $s['opened_at'], 'closed' => $s['closed_at'],
                'payments' => $x['n'], 'float' => (float)$s['opening_float'], 'cash' => r2($x['cash']), 'noncash' => r2($x['noncash']), 'refunds' => r2($ref),
                'expected' => $open ? $now : (float)$s['expected_cash'], 'counted' => $open ? null : (float)$s['counted_cash'],
                'over_short' => $open ? null : (float)$s['over_short'], 'late' => abs($late) >= 0.005 ? $late : null, 'deposited' => r2($x['dep']), 'undeposited' => $undep,
                'dep_date' => $x['last_dep'], 'status' => shprpt_st($s['status'], ['closed' => 'Closed']), 'reviewed_by' => Ship::userName($s['reviewed_by'] ? (int)$s['reviewed_by'] : null),
                '_links' => ['shift' => Report::link('tkt.cashier', ['id' => $sid])]];
        }
        $cols = [['key' => 'shift', 'label' => 'Shift'], ['key' => 'office', 'label' => 'Office'], ['key' => 'cashier', 'label' => 'Cashier'], ['key' => 'opened', 'label' => 'Opened', 'type' => 'datetime'],
            ['key' => 'closed', 'label' => 'Closed', 'type' => 'datetime'], ['key' => 'payments', 'label' => 'Payments', 'type' => 'int'], ['key' => 'float', 'label' => 'Opening float', 'type' => 'money'],
            ['key' => 'cash', 'label' => 'Cash collected', 'type' => 'money'], ['key' => 'noncash', 'label' => 'Card and e-wallet', 'type' => 'money'], ['key' => 'refunds', 'label' => 'Cash refunds', 'type' => 'money'],
            ['key' => 'expected', 'label' => 'Expected at close', 'type' => 'money'], ['key' => 'counted', 'label' => 'Counted', 'type' => 'money'], ['key' => 'over_short', 'label' => 'Over / (short)', 'type' => 'money'],
            ['key' => 'late', 'label' => 'Cash not in the count', 'type' => 'money'], ['key' => 'deposited', 'label' => 'Cash deposited', 'type' => 'money'],
            ['key' => 'undeposited', 'label' => 'Not yet deposited', 'type' => 'money'], ['key' => 'dep_date', 'label' => 'Last deposit', 'type' => 'date'], ['key' => 'status', 'label' => 'Status'],
            ['key' => 'reviewed_by', 'label' => 'Reviewed by']];
        $tot = shprpt_sum($rows, ['float', 'cash', 'noncash', 'refunds', 'over_short', 'late', 'deposited', 'undeposited']);
        return ['columns' => $cols, 'rows' => $rows, 'totals' => ['shift' => 'TOTAL', 'payments' => array_sum(array_column($rows, 'payments'))] + $tot,
            'notes' => h('Cash collected is every cash payment taken on the shift; the cash is deposited from Undeposited Funds by a bank deposit that lists the receipts. "Cash not in the count" is the cash the shift\'s payments and refunds now add up to, less what was expected when the drawer was counted: payments entered or changed on a shift after it was closed. It should be nil.')];
    },
]);

Report::define('shp_advance', [
    'filters' => [],
    'extra_filters' => shprpt_vfilters(false),
    'checks' => ['future_only' => 'Future voyages only'],
    'run' => function (array $f): array {
        $now = Ship::now();
        $p = ['now' => $now];
        $w = shprpt_vwhere($f, $p, false);
        $fut = ($f['future_only'] ?? '') === '1';
        $sql = 'SELECT ' . shprpt_vcols() . ' FROM ' . shprpt_vfrom() . " WHERE $w AND v.status <> 'cancelled' AND ("
            . "(v.etd >= :now AND v.status IN ('draft', 'published', 'on_sale', 'boarding', 'ready'))"
            . ($fut ? '' : " OR EXISTS (SELECT 1 FROM shp_tickets t WHERE t.voyage_id = v.id AND t.revenue_done = 0 AND t.status IN ('issued', 'checked_in', 'boarded', 'no_show'))")
            . ') ORDER BY v.etd';
        $vs = DB::all($sql, $p);
        $ids = array_map('intval', array_column($vs, 'id'));
        $tk = shprpt_ticket_counts($ids);
        $p2 = [];
        $in = shprpt_in($ids, 'ad', $p2);
        $defPax = DB::pairs("SELECT voyage_id, SUM(fare_net + surcharge_net) FROM shp_tickets WHERE voyage_id IN $in AND revenue_done = 0 AND status IN ('issued', 'checked_in', 'boarded', 'no_show') GROUP BY voyage_id", $p2);
        $defVeh = DB::pairs("SELECT voyage_id, SUM(fare_net) FROM shp_vehicles WHERE voyage_id IN $in AND revenue_done = 0 AND status IN ('issued', 'checked_in', 'loaded', 'unloaded', 'no_show') GROUP BY voyage_id", $p2);
        $defCgo = [];
        foreach (DB::all("SELECT voyage_id, freight_gross, discount_amount, handling_fee FROM shp_cargo WHERE voyage_id IN $in AND payment_terms = 'prepaid' AND paid_txn_id IS NOT NULL AND revenue_done = 0
                            AND status NOT IN ('cancelled', 'quotation')", $p2) as $c) {
            $defCgo[(int)$c['voyage_id']] = ($defCgo[(int)$c['voyage_id']] ?? 0) + ShipGL::splitVat((float)$c['freight_gross'] - (float)$c['discount_amount'])[0] + ShipGL::splitVat((float)$c['handling_fee'])[0];
        }
        $rows = [];
        foreach ($vs as $v) {
            $vid = (int)$v['id'];
            $seats = shprpt_pax_limit($v);
            $paid = shprpt_n($tk, $vid, Ship::PAID_TICKET);
            $dp = r2((float)($defPax[$vid] ?? 0));
            $dv = r2((float)($defVeh[$vid] ?? 0));
            $dc = r2((float)($defCgo[$vid] ?? 0));
            $rows[] = ['voyage' => $v['voyage_no'], 'etd' => $v['etd'], 'route' => $v['route_label'], 'vessel' => $v['vessel'], 'status' => shprpt_st($v['status']),
                'days' => (int)floor((strtotime(substr($v['etd'], 0, 10)) - strtotime(Ship::today())) / 86400), 'seats' => $seats, 'paid' => $paid,
                'held' => shprpt_n($tk, $vid, ['held']), 'booked' => shprpt_pct($paid, $seats), 'def_pax' => $dp, 'def_veh' => $dv, 'def_cgo' => $dc, 'def_total' => r2($dp + $dv + $dc),
                '_links' => ['voyage' => shprpt_link_voyage($vid)]];
        }
        $cols = [['key' => 'voyage', 'label' => 'Voyage'], ['key' => 'etd', 'label' => 'Departure', 'type' => 'datetime'], ['key' => 'route', 'label' => 'Route'], ['key' => 'vessel', 'label' => 'Vessel'],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'days', 'label' => 'Days ahead', 'type' => 'int'], ['key' => 'seats', 'label' => 'Passenger limit', 'type' => 'int'],
            ['key' => 'paid', 'label' => 'Paid passengers', 'type' => 'int'], ['key' => 'held', 'label' => 'Held unpaid', 'type' => 'int'], ['key' => 'booked', 'label' => 'Booked', 'type' => 'pct'],
            ['key' => 'def_pax', 'label' => 'Deferred fares', 'type' => 'money'], ['key' => 'def_veh', 'label' => 'Deferred vehicle freight', 'type' => 'money'],
            ['key' => 'def_cgo', 'label' => 'Deferred cargo freight', 'type' => 'money'], ['key' => 'def_total', 'label' => 'Total deferred', 'type' => 'money']];
        $tot = shprpt_sum($rows, ['def_pax', 'def_veh', 'def_cgo', 'def_total']);
        // tie-out of the deferred revenue on the records with the two liability accounts, for every voyage
        $recPax = (float)DB::value("SELECT COALESCE(SUM(fare_net + surcharge_net), 0) FROM shp_tickets WHERE revenue_done = 0 AND status IN ('issued', 'checked_in', 'boarded', 'no_show')");
        $recFr = (float)DB::value("SELECT COALESCE(SUM(fare_net), 0) FROM shp_vehicles WHERE revenue_done = 0 AND status IN ('issued', 'checked_in', 'loaded', 'unloaded', 'no_show')");
        foreach (DB::all("SELECT freight_gross, discount_amount, handling_fee FROM shp_cargo WHERE payment_terms = 'prepaid' AND paid_txn_id IS NOT NULL AND revenue_done = 0 AND status NOT IN ('cancelled', 'quotation')") as $c) {
            $recFr += ShipGL::splitVat((float)$c['freight_gross'] - (float)$c['discount_amount'])[0] + ShipGL::splitVat((float)$c['handling_fee'])[0];
        }
        $tie = '';
        foreach ([['shp_deferred_pax', 'Deferred passenger revenue', $recPax], ['shp_deferred_freight', 'Deferred freight revenue', $recFr]] as [$key, $label, $rec]) {
            $acct = DB::row('SELECT id, code, name FROM accounts WHERE id = :id', ['id' => Accts::get($key)]);
            $gl = $acct ? Accts::balance((int)$acct['id']) : 0.0;
            $diff = r2($rec - $gl);
            $tie .= '<tr><td>' . h($label) . ($acct ? ' (' . h($acct['code'] . ' ' . $acct['name']) . ')' : '') . '</td><td class="num">' . money($rec) . '</td><td class="num">' . money($gl) . '</td>'
                . '<td class="num' . (abs($diff) >= 0.01 ? ' text-danger fw-semibold' : '') . '">' . money($diff) . '</td></tr>';
        }
        $footer = '<div class="small fw-semibold mb-1">Deferred revenue on the records against the general ledger, every voyage</div>'
            . '<div class="table-responsive"><table class="table table-sm" style="max-width:760px"><thead><tr><th>Balance</th><th class="num">Unflown tickets and freight</th><th class="num">General ledger</th><th class="num">Difference</th></tr></thead><tbody>'
            . $tie . '</tbody></table></div>';
        return ['columns' => $cols, 'rows' => $rows, 'totals' => ['voyage' => 'TOTAL', 'paid' => array_sum(array_column($rows, 'paid')), 'held' => array_sum(array_column($rows, 'held'))] + $tot,
            'footer' => $footer,
            'notes' => h('Voyages still to sail, and voyages that sailed but are not yet completed, with the fares and freight paid for them. These are carried as deferred revenue (a liability) until the voyage is completed.')];
    },
]);

// ================================================================================================ CARGO

Report::define('shp_cargo_volume', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => [
        'by' => ['Show by', fn() => ['route' => 'Route', 'commodity' => 'Commodity class', 'shipper' => 'Shipper', 'vessel' => 'Vessel', 'voyage' => 'Voyage', 'terms' => 'Payment terms'], null],
        'route' => ['Route', 'shprpt_route_opts'], 'vessel' => ['Vessel', 'shprpt_vessel_opts'],
        'commodity' => ['Commodity', fn() => DB::pairs('SELECT DISTINCT commodity_class, commodity_class FROM shp_cargo ORDER BY commodity_class')],
    ],
    'run' => function (array $f): array {
        $by = ($f['by'] ?? '') !== '' ? (string)$f['by'] : 'route';
        $p = [];
        $w = shprpt_vwhere($f, $p);
        $sql = "SELECT c.*, v.voyage_no, v.id AS vid, CONCAT(po.code, '-', pd.code) AS route_label, s.name AS vessel
                  FROM shp_cargo c JOIN shp_voyages v ON v.id = c.voyage_id JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id
                  JOIN shp_ports pd ON pd.id = r.dest_port_id JOIN shp_vessels s ON s.id = v.vessel_id
                 WHERE $w AND c.status NOT IN ('quotation', 'cancelled')";
        if (!empty($f['commodity'])) {
            $sql .= ' AND c.commodity_class = :cc';
            $p['cc'] = (string)$f['commodity'];
        }
        $g = [];
        foreach (DB::all($sql . ' ORDER BY v.etd, c.id', $p) as $c) {
            [$key, $link] = match ($by) {
                'commodity' => [ucwords(str_replace('_', ' ', (string)$c['commodity_class'])), null],
                'shipper' => [(string)$c['shipper_name'], null],
                'vessel' => [(string)$c['vessel'], null],
                'voyage' => [$c['voyage_no'] . ' ' . $c['route_label'], shprpt_link_voyage((int)$c['vid'])],
                'terms' => [ucfirst((string)$c['payment_terms']), null],
                default => [$c['route_label'], null],
            };
            $g[$key] ??= ['link' => $link, 'n' => 0, 'pk' => 0, 'kg' => 0.0, 'cbm' => 0.0, 'fr' => 0.0, 'hd' => 0.0, 'ins' => 0.0, 'vat' => 0.0, 'tot' => 0.0];
            $x = &$g[$key];
            $x['n']++;
            $x['pk'] += (int)$c['packages'];
            $x['kg'] += (float)$c['weight_kg'];
            $x['cbm'] += (float)$c['volume_cbm'];
            $x['fr'] += (float)$c['freight_net'];
            $x['hd'] += (float)$c['handling_fee'];
            $x['ins'] += (float)$c['insurance_fee'];
            $x['vat'] += (float)$c['vat_amount'];
            $x['tot'] += (float)$c['total_amount'];
            unset($x);
        }
        ksort($g);
        $rows = [];
        foreach ($g as $k => $x) {
            $rows[] = ['name' => $k, 'waybills' => $x['n'], 'packages' => $x['pk'], 'tonnes' => round($x['kg'] / 1000, 3), 'cbm' => round($x['cbm'], 3), 'freight' => r2($x['fr']),
                'handling' => r2($x['hd']), 'insurance' => r2($x['ins']), 'vat' => r2($x['vat']), 'total' => r2($x['tot']), 'per_t' => $x['kg'] > 0 ? r2($x['fr'] / ($x['kg'] / 1000)) : null,
                '_links' => ['name' => $x['link']]];
        }
        $label = ['route' => 'Route', 'commodity' => 'Commodity class', 'shipper' => 'Shipper', 'vessel' => 'Vessel', 'voyage' => 'Voyage', 'terms' => 'Payment terms'][$by] ?? 'Route';
        $cols = [['key' => 'name', 'label' => $label], ['key' => 'waybills', 'label' => 'Waybills', 'type' => 'int'], ['key' => 'packages', 'label' => 'Packages', 'type' => 'int'],
            ['key' => 'tonnes', 'label' => 'Weight (t)', 'type' => 'qty'], ['key' => 'cbm', 'label' => 'Volume (cbm)', 'type' => 'qty'], ['key' => 'freight', 'label' => 'Freight net of VAT', 'type' => 'money'],
            ['key' => 'handling', 'label' => 'Handling', 'type' => 'money'], ['key' => 'insurance', 'label' => 'Insurance', 'type' => 'money'], ['key' => 'vat', 'label' => 'VAT', 'type' => 'money'],
            ['key' => 'total', 'label' => 'Total charges', 'type' => 'money'], ['key' => 'per_t', 'label' => 'Freight per tonne', 'type' => 'money']];
        $tot = shprpt_sum($rows, ['freight', 'handling', 'insurance', 'vat', 'total']);
        $t = array_sum(array_column($rows, 'tonnes'));
        return ['columns' => $cols, 'rows' => $rows, 'totals' => ['name' => 'TOTAL', 'waybills' => array_sum(array_column($rows, 'waybills')), 'packages' => array_sum(array_column($rows, 'packages')),
            'tonnes' => $t, 'cbm' => array_sum(array_column($rows, 'cbm')), 'per_t' => $t > 0 ? r2($tot['freight'] / $t) : null] + $tot,
            'notes' => h('Waybills booked on voyages dated in the period (quotations and cancelled waybills left out).')];
    },
]);

Report::define('shp_cargo_exceptions', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => shprpt_vfilters(false),
    'run' => function (array $f): array {
        $p = [];
        $w = shprpt_vwhere($f, $p);
        $exc = "('short_landed', 'damaged', 'claimed', 'rolled_over')";
        $sql = "SELECT c.*, v.voyage_no, v.voyage_date, v.id AS vid, CONCAT(po.code, '-', pd.code) AS route_label
                  FROM shp_cargo c JOIN shp_voyages v ON v.id = c.voyage_id JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id
                  JOIN shp_ports pd ON pd.id = r.dest_port_id JOIN shp_vessels s ON s.id = v.vessel_id
                 WHERE $w AND (c.status IN $exc OR EXISTS (SELECT 1 FROM shp_cargo_events e WHERE e.cargo_id = c.id AND e.to_status IN $exc)
                       OR EXISTS (SELECT 1 FROM shp_cases k WHERE k.cargo_id = c.id))";
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY v.etd, c.id', $p) as $c) {
            $ev = DB::row("SELECT details, to_status, created_at FROM shp_cargo_events WHERE cargo_id = :c AND to_status IN $exc ORDER BY id DESC LIMIT 1", ['c' => $c['id']]);
            $case = DB::row('SELECT id, case_no, status, comp_amount, comp_status, resolution_code FROM shp_cases WHERE cargo_id = :c ORDER BY id DESC LIMIT 1', ['c' => $c['id']]);
            $rows[] = ['waybill' => $c['waybill_no'], 'voyage' => $c['voyage_no'], 'date' => $c['voyage_date'], 'route' => $c['route_label'], 'shipper' => $c['shipper_name'],
                'consignee' => $c['consignee_name'], 'goods' => $c['description'], 'packages' => (int)$c['packages'], 'received' => $c['received_qty'] !== null ? (int)$c['received_qty'] : null,
                'value' => (float)$c['declared_value'], 'exception' => shprpt_st($ev['to_status'] ?? $c['status']), 'details' => (string)($ev['details'] ?? ''), 'status' => shprpt_st($c['status']),
                'case' => $case['case_no'] ?? '', 'case_status' => $case ? ucwords(str_replace('_', ' ', (string)$case['status'])) : '',
                'claim' => $case && $case['comp_amount'] !== null ? (float)$case['comp_amount'] : null, 'claim_status' => $case ? ucwords(str_replace('_', ' ', (string)$case['comp_status'])) : '',
                '_links' => ['waybill' => Report::link('cgo.shipments', ['id' => (int)$c['id']]), 'voyage' => shprpt_link_voyage((int)$c['vid']),
                    'case' => $case ? Report::link('care.cases', ['id' => (int)$case['id']]) : null]];
        }
        $cols = [['key' => 'waybill', 'label' => 'Waybill'], ['key' => 'voyage', 'label' => 'Voyage'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'route', 'label' => 'Route'],
            ['key' => 'shipper', 'label' => 'Shipper'], ['key' => 'consignee', 'label' => 'Consignee'], ['key' => 'goods', 'label' => 'Goods'], ['key' => 'packages', 'label' => 'Packages', 'type' => 'int'],
            ['key' => 'received', 'label' => 'Received', 'type' => 'int'], ['key' => 'value', 'label' => 'Declared value', 'type' => 'money'], ['key' => 'exception', 'label' => 'Exception'],
            ['key' => 'details', 'label' => 'What was found'], ['key' => 'status', 'label' => 'Waybill status'], ['key' => 'case', 'label' => 'Case'], ['key' => 'case_status', 'label' => 'Case status'],
            ['key' => 'claim', 'label' => 'Claim / compensation', 'type' => 'money'], ['key' => 'claim_status', 'label' => 'Compensation status']];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => ['waybill' => 'TOTAL', 'packages' => array_sum(array_column($rows, 'packages'))] + shprpt_sum($rows, ['value', 'claim']),
            'notes' => h('Waybills short-landed, damaged, claimed or rolled over to another voyage, and those with a support case or claim.')];
    },
]);

Report::define('shp_lane_util', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => shprpt_vfilters(false),
    'run' => function (array $f): array {
        $p = [];
        $w = shprpt_vwhere($f, $p);
        $vs = DB::all('SELECT ' . shprpt_vcols() . ' FROM ' . shprpt_vfrom() . " WHERE $w AND s.lane_meters > 0 AND v.status NOT IN ('draft', 'cancelled') ORDER BY v.etd", $p);
        $ids = array_map('intval', array_column($vs, 'id'));
        $p2 = [];
        $in = shprpt_in($ids, 'lu', $p2);
        $by = [];
        foreach (DB::all("SELECT x.voyage_id, x.status, x.fare_net, c.code, c.lane_m FROM shp_vehicles x JOIN shp_vehicle_classes c ON c.id = x.vehicle_class_id
                           WHERE x.voyage_id IN $in AND x.status NOT IN ('expired', 'cancelled', 'refunded', 'transferred')", $p2) as $x) {
            $vid = (int)$x['voyage_id'];
            $by[$vid] ??= ['n' => 0, 'booked' => 0.0, 'loaded' => 0.0, 'rev' => 0.0, 'mix' => []];
            $by[$vid]['n']++;
            $by[$vid]['booked'] += (float)$x['lane_m'];
            if (in_array($x['status'], ['loaded', 'unloaded'], true)) {
                $by[$vid]['loaded'] += (float)$x['lane_m'];
            }
            if ($x['status'] !== 'held') {
                $by[$vid]['rev'] += (float)$x['fare_net'];
            }
            $by[$vid]['mix'][$x['code']] = ($by[$vid]['mix'][$x['code']] ?? 0) + 1;
        }
        $rows = [];
        foreach ($vs as $v) {
            $vid = (int)$v['id'];
            $x = $by[$vid] ?? ['n' => 0, 'booked' => 0.0, 'loaded' => 0.0, 'rev' => 0.0, 'mix' => []];
            $cap = shprpt_lane_limit($v);
            $used = shprpt_sailed($v['status']) ? $x['loaded'] : $x['booked'];
            ksort($x['mix']);
            $rows[] = ['voyage' => $v['voyage_no'], 'date' => $v['voyage_date'], 'route' => $v['route_label'], 'vessel' => $v['vessel'], 'status' => shprpt_st($v['status']), 'vehicles' => $x['n'],
                'mix' => implode(', ', array_map(fn($k, $n) => $n . ' ' . $k, array_keys($x['mix']), $x['mix'])), 'booked' => round($x['booked'], 1), 'loaded' => round($x['loaded'], 1),
                'cap' => $cap, 'util' => shprpt_pct($used, $cap), 'revenue' => r2($x['rev']), 'per_m' => $used > 0 ? r2($x['rev'] / $used) : null, '_links' => ['voyage' => shprpt_link_voyage($vid)]];
        }
        $cols = [['key' => 'voyage', 'label' => 'Voyage'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'route', 'label' => 'Route'], ['key' => 'vessel', 'label' => 'Vessel'],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'vehicles', 'label' => 'Vehicles', 'type' => 'int'], ['key' => 'mix', 'label' => 'Vehicle classes'], ['key' => 'booked', 'label' => 'Lane m booked', 'type' => 'qty'],
            ['key' => 'loaded', 'label' => 'Lane m loaded', 'type' => 'qty'], ['key' => 'cap', 'label' => 'Lane m available', 'type' => 'qty'], ['key' => 'util', 'label' => 'Deck used', 'type' => 'pct'],
            ['key' => 'revenue', 'label' => 'Vehicle freight net of VAT', 'type' => 'money'], ['key' => 'per_m', 'label' => 'Freight per lane meter', 'type' => 'money']];
        $cap = array_sum(array_column($rows, 'cap'));
        $loaded = array_sum(array_column($rows, 'loaded'));
        return ['columns' => $cols, 'rows' => $rows, 'totals' => ['voyage' => 'TOTAL', 'vehicles' => array_sum(array_column($rows, 'vehicles')), 'booked' => array_sum(array_column($rows, 'booked')),
            'loaded' => $loaded, 'cap' => $cap, 'revenue' => r2(array_sum(array_column($rows, 'revenue')))],
            'notes' => h('Vessels with a vehicle deck. The deck used is the lane meters loaded for a voyage that sailed, and the lane meters booked for one still to sail.')];
    },
]);

Report::define('shp_port_fees', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => ['fee' => ['Fee', fn() => DB::pairs('SELECT id, name FROM shp_fees ORDER BY sort_order, name')]] + shprpt_vfilters(false),
    'checks' => ['outstanding' => 'Only fees not yet remitted'],
    'run' => function (array $f): array {
        $p = [];
        $w = shprpt_vwhere($f, $p);
        $sql = "SELECT v.id AS vid, v.voyage_no, v.voyage_date, CONCAT(po.code, '-', pd.code) AS route_label, fe.id AS fee_id, fe.code AS fee_code, fe.name AS fee_name, fe.sort_order,
                       vd.name AS payee,
                       SUM(CASE WHEN tf.status IN ('collected', 'remitted') THEN 1 ELSE 0 END) AS n,
                       SUM(CASE WHEN tf.status IN ('collected', 'remitted') THEN tf.amount ELSE 0 END) AS collected,
                       SUM(CASE WHEN tf.status = 'refunded' THEN tf.amount ELSE 0 END) AS refunded,
                       SUM(CASE WHEN tf.status = 'remitted' THEN tf.amount ELSE 0 END) AS remitted,
                       SUM(CASE WHEN tf.status = 'collected' THEN tf.amount ELSE 0 END) AS outstanding,
                       MAX(tf.remit_txn_id) AS remit_txn, COUNT(DISTINCT tf.remit_txn_id) AS remit_n
                  FROM shp_ticket_fees tf JOIN shp_fees fe ON fe.id = tf.fee_id LEFT JOIN vendors vd ON vd.id = fe.vendor_id
                  LEFT JOIN shp_tickets t ON t.id = tf.ticket_id LEFT JOIN shp_vehicles x ON x.id = tf.vehicle_id LEFT JOIN shp_cargo cg ON cg.id = tf.cargo_id
                  JOIN shp_voyages v ON v.id = COALESCE(t.voyage_id, x.voyage_id, cg.voyage_id)
                  JOIN shp_routes r ON r.id = v.route_id JOIN shp_ports po ON po.id = r.origin_port_id JOIN shp_ports pd ON pd.id = r.dest_port_id JOIN shp_vessels s ON s.id = v.vessel_id
                 WHERE $w";
        if (!empty($f['fee'])) {
            $sql .= ' AND tf.fee_id = :fee';
            $p['fee'] = (int)$f['fee'];
        }
        $sql .= ' GROUP BY v.id, v.voyage_no, v.voyage_date, po.code, pd.code, fe.id, fe.code, fe.name, fe.sort_order, vd.name';
        if (($f['outstanding'] ?? '') === '1') {
            $sql .= ' HAVING outstanding > 0';
        }
        $all = DB::all($sql . ' ORDER BY v.etd, fe.sort_order', $p);
        $docs = [];
        $tx = array_filter(array_map('intval', array_column($all, 'remit_txn')));
        if ($tx) {
            $p3 = [];
            $tin = shprpt_in($tx, 'rt', $p3);
            foreach (DB::all("SELECT id, txn_type, txn_no FROM transactions WHERE id IN $tin", $p3) as $t) {
                $docs[(int)$t['id']] = $t;
            }
        }
        $rows = [];
        foreach ($all as $r) {
            $doc = $r['remit_txn'] ? ($docs[(int)$r['remit_txn']] ?? null) : null;
            $rows[] = ['voyage' => $r['voyage_no'], 'date' => $r['voyage_date'], 'route' => $r['route_label'], 'fee' => $r['fee_name'], 'payee' => $r['payee'], 'count' => (int)$r['n'],
                'collected' => r2((float)$r['collected']), 'refunded' => r2((float)$r['refunded']), 'remitted' => r2((float)$r['remitted']), 'outstanding' => r2((float)$r['outstanding']),
                'doc' => $doc ? Txn::label((string)$doc['txn_type']) . ' ' . $doc['txn_no'] . ((int)$r['remit_n'] > 1 ? ' and others' : '') : '',
                '_links' => ['voyage' => shprpt_link_voyage((int)$r['vid']), 'doc' => $doc ? shprpt_txn_link((int)$doc['id'], $doc['txn_type']) : null]];
        }
        $cols = [['key' => 'voyage', 'label' => 'Voyage'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'route', 'label' => 'Route'], ['key' => 'fee', 'label' => 'Fee'],
            ['key' => 'payee', 'label' => 'Remitted to'], ['key' => 'count', 'label' => 'Collected on', 'type' => 'int'], ['key' => 'collected', 'label' => 'Collected', 'type' => 'money'],
            ['key' => 'refunded', 'label' => 'Refunded', 'type' => 'money'], ['key' => 'remitted', 'label' => 'Remitted', 'type' => 'money'], ['key' => 'outstanding', 'label' => 'Still to remit', 'type' => 'money'],
            ['key' => 'doc', 'label' => 'Remittance']];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => ['voyage' => 'TOTAL', 'count' => array_sum(array_column($rows, 'count'))] + shprpt_sum($rows, ['collected', 'refunded', 'remitted', 'outstanding']),
            'notes' => h('Terminal fees and passenger insurance are collected as agent for the port authority and the insurer: they are carried as payables (not revenue) until remitted. A fee is refunded with its ticket when the ticket is refunded.')];
    },
]);

// ================================================================================================ MAINTENANCE

Report::define('shp_wo_status', [
    'filters' => [],
    'extra_filters' => [
        'state' => ['Work orders', fn() => ['open' => 'Open work orders', 'closed' => 'Completed and closed', 'all' => 'All work orders'], null],
        'vessel' => ['Vessel', 'shprpt_vessel_opts'],
        'wo_type' => ['Type', fn() => DB::pairs('SELECT DISTINCT wo_type, wo_type FROM shp_work_orders ORDER BY wo_type')],
        'priority' => ['Priority', fn() => DB::pairs('SELECT DISTINCT priority, priority FROM shp_work_orders ORDER BY priority')],
    ],
    'run' => function (array $f): array {
        $state = in_array($f['state'] ?? '', ['open', 'closed', 'all'], true) ? $f['state'] : 'open';
        $p = [];
        $sql = 'SELECT w.*, s.name AS vessel, e.code AS eq_code, e.name AS eq_name FROM shp_work_orders w JOIN shp_vessels s ON s.id = w.vessel_id LEFT JOIN shp_equipment e ON e.id = w.equipment_id WHERE 1 = 1';
        if ($state === 'open') {
            $sql .= " AND w.status NOT IN ('completed', 'closed', 'cancelled')";
        } elseif ($state === 'closed') {
            $sql .= " AND w.status IN ('completed', 'closed')";
        }
        foreach (['vessel' => 'w.vessel_id'] as $k => $col) {
            if (!empty($f[$k])) {
                $sql .= " AND $col = :x$k";
                $p['x' . $k] = (int)$f[$k];
            }
        }
        foreach (['wo_type' => 'w.wo_type', 'priority' => 'w.priority'] as $k => $col) {
            if (!empty($f[$k])) {
                $sql .= " AND $col = :x$k";
                $p['x' . $k] = (string)$f[$k];
            }
        }
        $today = Ship::today();
        $rows = [];
        $late = 0;
        foreach (DB::all($sql . " ORDER BY FIELD(w.priority, 'critical', 'urgent', 'high', 'normal', 'low'), w.created_at", $p) as $w) {
            $done = in_array($w['status'], ['completed', 'closed', 'cancelled'], true);
            $end = $done ? ($w['actual_end'] ?: $w['updated_at']) : Ship::now();
            $overdue = !$done && $w['planned_end'] && substr($w['planned_end'], 0, 10) < $today;
            $late += $overdue ? 1 : 0;
            $rows[] = ['wo' => $w['wo_no'], 'vessel' => $w['vessel'], 'equipment' => $w['eq_code'] ? $w['eq_code'] . ' ' . $w['eq_name'] : '', 'type' => ucfirst((string)$w['wo_type']),
                'title' => $w['title'], 'priority' => ucfirst((string)$w['priority']), 'safety' => (int)$w['safety_critical'] ? 'Yes' : '', 'status' => shprpt_st($w['status']),
                'created' => substr($w['created_at'], 0, 10), 'age' => (int)floor((strtotime($end) - strtotime($w['created_at'])) / 86400), 'planned_end' => $w['planned_end'] ? substr($w['planned_end'], 0, 10) : null,
                'overdue' => $overdue ? 'Overdue' : '', 'assigned' => Ship::userName($w['assigned_to'] ? (int)$w['assigned_to'] : null),
                'labor' => (float)$w['cost_labor'], 'parts' => (float)$w['cost_parts'], 'services' => (float)$w['cost_services'],
                'total' => r2((float)$w['cost_labor'] + (float)$w['cost_parts'] + (float)$w['cost_services']), '_links' => ['wo' => Report::link('fleet.workorders', ['id' => (int)$w['id']])]];
        }
        $cols = [['key' => 'wo', 'label' => 'Work order'], ['key' => 'vessel', 'label' => 'Vessel'], ['key' => 'equipment', 'label' => 'Equipment'], ['key' => 'type', 'label' => 'Type'], ['key' => 'title', 'label' => 'Work'],
            ['key' => 'priority', 'label' => 'Priority'], ['key' => 'safety', 'label' => 'Safety-critical'], ['key' => 'status', 'label' => 'Status'], ['key' => 'created', 'label' => 'Raised', 'type' => 'date'],
            ['key' => 'age', 'label' => 'Age (days)', 'type' => 'int'], ['key' => 'planned_end', 'label' => 'Planned finish', 'type' => 'date'], ['key' => 'overdue', 'label' => 'Late'],
            ['key' => 'assigned', 'label' => 'Assigned to'], ['key' => 'labor', 'label' => 'Labor', 'type' => 'money'], ['key' => 'parts', 'label' => 'Parts', 'type' => 'money'],
            ['key' => 'services', 'label' => 'Services', 'type' => 'money'], ['key' => 'total', 'label' => 'Cost to date', 'type' => 'money']];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => ['wo' => 'TOTAL'] + shprpt_sum($rows, ['labor', 'parts', 'services', 'total']),
            'notes' => h(count($rows) . ' work order' . (count($rows) === 1 ? '' : 's') . ', ' . $late . ' past the planned finish. Age counts from the day the work order was raised to its completion, or to today when still open.')];
    },
]);

Report::define('shp_pm_due', [
    'filters' => [],
    'extra_filters' => [
        'state' => ['Plans', fn() => ['due' => 'Due and overdue', 'overdue' => 'Overdue only', 'all' => 'Every active plan'], null],
        'vessel' => ['Vessel', 'shprpt_vessel_opts'],
    ],
    'checks' => ['regulatory' => 'Regulatory plans only'],
    'run' => function (array $f): array {
        $state = in_array($f['state'] ?? '', ['due', 'overdue', 'all'], true) ? $f['state'] : 'due';
        $today = Ship::today();
        $p = [];
        $sql = 'SELECT pl.*, s.name AS vessel, e.code AS eq_code, e.name AS eq_name, e.running_hours, w.wo_no, w.status AS wo_status
                  FROM shp_pm_plans pl JOIN shp_vessels s ON s.id = pl.vessel_id LEFT JOIN shp_equipment e ON e.id = pl.equipment_id LEFT JOIN shp_work_orders w ON w.id = pl.open_wo_id
                 WHERE pl.is_active = 1';
        if (!empty($f['vessel'])) {
            $sql .= ' AND pl.vessel_id = :vs';
            $p['vs'] = (int)$f['vessel'];
        }
        if (($f['regulatory'] ?? '') === '1') {
            $sql .= ' AND pl.is_regulatory = 1';
        }
        $rows = [];
        $n = ['over' => 0, 'due' => 0];
        foreach (DB::all($sql . ' ORDER BY pl.next_due_on IS NULL, pl.next_due_on, s.name', $p) as $pl) {
            $daysLeft = $pl['next_due_on'] ? (int)floor((strtotime($pl['next_due_on']) - strtotime($today)) / 86400) : null;
            $hrsLeft = $pl['next_due_hours'] !== null && $pl['running_hours'] !== null ? round((float)$pl['next_due_hours'] - (float)$pl['running_hours'], 1) : null;
            $overdue = ($daysLeft !== null && $daysLeft < 0) || ($hrsLeft !== null && $hrsLeft < 0);
            $due = $overdue || ($daysLeft !== null && $daysLeft <= (int)$pl['lead_days'])
                || ($hrsLeft !== null && $hrsLeft <= max(0, (float)$pl['interval_hours'] * 0.05));
            if (($state === 'due' && !$due) || ($state === 'overdue' && !$overdue)) {
                continue;
            }
            $n['over'] += $overdue ? 1 : 0;
            $n['due'] += $due && !$overdue ? 1 : 0;
            $interval = trim(((int)$pl['interval_days'] ? (int)$pl['interval_days'] . ' days' : '') . ((int)$pl['interval_days'] && (float)$pl['interval_hours'] ? ' or ' : '')
                . ((float)$pl['interval_hours'] ? qty($pl['interval_hours']) . ' running hours' : ''));
            $rows[] = ['plan' => $pl['name'], 'vessel' => $pl['vessel'], 'equipment' => $pl['eq_code'] ? $pl['eq_code'] . ' ' . $pl['eq_name'] : '', 'interval' => $interval,
                'regulatory' => (int)$pl['is_regulatory'] ? 'Yes' : '', 'last' => $pl['last_done_on'], 'next' => $pl['next_due_on'], 'days' => $daysLeft,
                'hours_now' => $pl['running_hours'] !== null ? (float)$pl['running_hours'] : null, 'hours_due' => $pl['next_due_hours'] !== null ? (float)$pl['next_due_hours'] : null, 'hours_left' => $hrsLeft,
                'state' => $overdue ? 'Overdue' : ($due ? 'Due' : 'Not yet due'), 'wo' => $pl['wo_no'] ? $pl['wo_no'] . ' (' . shprpt_st($pl['wo_status']) . ')' : '',
                '_links' => ['plan' => Report::link('fleet.pm', ['id' => (int)$pl['id']]), 'wo' => $pl['open_wo_id'] ? Report::link('fleet.workorders', ['id' => (int)$pl['open_wo_id']]) : null]];
        }
        $cols = [['key' => 'plan', 'label' => 'Maintenance plan'], ['key' => 'vessel', 'label' => 'Vessel'], ['key' => 'equipment', 'label' => 'Equipment'], ['key' => 'interval', 'label' => 'Every'],
            ['key' => 'regulatory', 'label' => 'Regulatory'], ['key' => 'last', 'label' => 'Last done', 'type' => 'date'], ['key' => 'next', 'label' => 'Next due', 'type' => 'date'],
            ['key' => 'days', 'label' => 'Days left', 'type' => 'int'], ['key' => 'hours_now', 'label' => 'Running hours', 'type' => 'qty'], ['key' => 'hours_due', 'label' => 'Due at hours', 'type' => 'qty'],
            ['key' => 'hours_left', 'label' => 'Hours left', 'type' => 'qty'], ['key' => 'state', 'label' => 'Standing'], ['key' => 'wo', 'label' => 'Work order raised']];
        return ['columns' => $cols, 'rows' => $rows, 'notes' => h($n['over'] . ' overdue, ' . $n['due'] . ' due within the lead time of the plan (or within 5% of the running-hour interval). A plan falls due by date or by running hours, whichever comes first.')];
    },
]);

Report::define('shp_repair_spend', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => ['vessel' => ['Vessel', 'shprpt_vessel_opts'], 'wo_type' => ['Type', fn() => DB::pairs('SELECT DISTINCT wo_type, wo_type FROM shp_work_orders ORDER BY wo_type')]],
    'run' => function (array $f): array {
        $p = ['f' => $f['from'], 't' => $f['to'], 'f2' => $f['from'] . ' 00:00:00', 't2' => $f['to'] . ' 23:59:59', 'f3' => $f['from'] . ' 00:00:00', 't3' => $f['to'] . ' 23:59:59'];
        $cond = '';
        if (!empty($f['vessel'])) {
            $cond .= ' AND w.vessel_id = ' . (int)$f['vessel'];
        }
        if (!empty($f['wo_type'])) {
            $cond .= ' AND w.wo_type = :wt';
            $p['wt'] = (string)$f['wo_type'];
        }
        $sql = "SELECT x.vessel_id, x.equipment_id, COUNT(DISTINCT x.wo_id) AS wos, SUM(x.hours) AS hours, SUM(x.labor) AS labor, SUM(x.parts) AS parts, SUM(x.services) AS services
                  FROM (SELECT w.vessel_id, w.equipment_id, w.id AS wo_id, l.hours, l.amount AS labor, 0 AS parts, 0 AS services FROM shp_wo_labor l JOIN shp_work_orders w ON w.id = l.wo_id
                         WHERE l.work_date BETWEEN :f AND :t $cond
                        UNION ALL
                        SELECT w.vessel_id, w.equipment_id, w.id, 0, 0, pt.amount, 0 FROM shp_wo_parts pt JOIN shp_work_orders w ON w.id = pt.wo_id
                         WHERE pt.issued_at BETWEEN :f2 AND :t2 $cond
                        UNION ALL
                        SELECT w.vessel_id, w.equipment_id, w.id, 0, 0, 0, sv.amount FROM shp_wo_services sv JOIN shp_work_orders w ON w.id = sv.wo_id
                         WHERE sv.created_at BETWEEN :f3 AND :t3 $cond) x
                 GROUP BY x.vessel_id, x.equipment_id";
        $data = DB::all($sql, $p);
        $vessels = DB::pairs('SELECT id, name FROM shp_vessels');
        $eq = DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM shp_equipment");
        $by = [];
        foreach ($data as $r) {
            $by[(int)$r['vessel_id']][] = $r;
        }
        $rows = [];
        $tot = ['wos' => 0, 'hours' => 0.0, 'labor' => 0.0, 'parts' => 0.0, 'services' => 0.0, 'total' => 0.0];
        foreach ($by as $vid => $list) {
            usort($list, fn($a, $b) => ((float)$b['labor'] + (float)$b['parts'] + (float)$b['services']) <=> ((float)$a['labor'] + (float)$a['parts'] + (float)$a['services']));
            $rows[] = ['name' => $vessels[$vid] ?? ('Vessel ' . $vid), '_class' => 'group-row'];
            $sub = ['wos' => 0, 'hours' => 0.0, 'labor' => 0.0, 'parts' => 0.0, 'services' => 0.0, 'total' => 0.0];
            foreach ($list as $r) {
                $t = r2((float)$r['labor'] + (float)$r['parts'] + (float)$r['services']);
                $rows[] = ['name' => $r['equipment_id'] ? ($eq[(int)$r['equipment_id']] ?? '') : '(vessel, no equipment named)', 'wos' => (int)$r['wos'], 'hours' => round((float)$r['hours'], 2),
                    'labor' => r2((float)$r['labor']), 'parts' => r2((float)$r['parts']), 'services' => r2((float)$r['services']), 'total' => $t, '_class' => 'indent-1',
                    '_links' => ['name' => Report::link('fleet.workorders', ['vessel' => $vid] + ($r['equipment_id'] ? ['equipment' => (int)$r['equipment_id']] : []))]];
                foreach (['wos', 'hours', 'labor', 'parts', 'services'] as $k) {
                    $sub[$k] += (float)$r[$k];
                }
                $sub['total'] += $t;
            }
            $rows[] = ['name' => 'Total ' . ($vessels[$vid] ?? ''), 'wos' => (int)$sub['wos'], 'hours' => $sub['hours'], 'labor' => r2($sub['labor']), 'parts' => r2($sub['parts']),
                'services' => r2($sub['services']), 'total' => r2($sub['total']), '_class' => 'subtotal-row'];
            foreach ($tot as $k => $_) {
                $tot[$k] += $sub[$k];
            }
        }
        $cols = [['key' => 'name', 'label' => 'Vessel and equipment'], ['key' => 'wos', 'label' => 'Work orders', 'type' => 'int'], ['key' => 'hours', 'label' => 'Labor hours', 'type' => 'qty'],
            ['key' => 'labor', 'label' => 'Labor', 'type' => 'money'], ['key' => 'parts', 'label' => 'Spare parts', 'type' => 'money'], ['key' => 'services', 'label' => 'Contractors and services', 'type' => 'money'],
            ['key' => 'total', 'label' => 'Total spend', 'type' => 'money']];
        return ['columns' => $cols, 'rows' => $rows, 'grouped' => true,
            'totals' => ['name' => 'TOTAL', 'wos' => (int)$tot['wos'], 'hours' => $tot['hours'], 'labor' => r2($tot['labor']), 'parts' => r2($tot['parts']), 'services' => r2($tot['services']), 'total' => r2($tot['total'])],
            'notes' => h('Labor on the date it was worked, spare parts on the date they were issued from stores, contractor services on the date they were billed, before VAT. A work order with spend on several days is counted once per equipment.')];
    },
]);

Report::define('shp_reliability', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'extra_filters' => ['view' => ['Show', fn() => ['vessel' => 'By vessel', 'equipment' => 'By equipment'], null], 'vessel' => ['Vessel', 'shprpt_vessel_opts']],
    'run' => function (array $f): array {
        $view = ($f['view'] ?? '') === 'equipment' ? 'equipment' : 'vessel';
        $from = $f['from'] . ' 00:00:00';
        $to = min($f['to'] . ' 23:59:59', Ship::now());
        $periodH = max(0.0, (strtotime($to) - strtotime($from)) / 3600);
        $vf = !empty($f['vessel']) ? ' AND x.vessel_id = ' . (int)$f['vessel'] : '';
        $p = ['f' => $from, 't' => $to];
        $defects = DB::all("SELECT x.vessel_id, x.equipment_id, x.severity, x.safety_critical FROM shp_defects x WHERE x.reported_at BETWEEN :f AND :t $vf", $p);
        $wos = DB::all("SELECT x.vessel_id, x.equipment_id, x.actual_start, x.actual_end, x.downtime_hours FROM shp_work_orders x
                         WHERE x.wo_type = 'corrective' AND x.actual_end IS NOT NULL AND x.actual_end BETWEEN :f AND :t $vf", $p);
        $holds = DB::all("SELECT x.vessel_id, x.hold_type, x.placed_at, x.released_at, d.equipment_id FROM shp_holds x
                            LEFT JOIN shp_defects d ON x.source_type = 'defect' AND d.id = x.source_id
                           WHERE x.hold_type IN ('no_sail', 'restricted') AND x.placed_at <= :t AND (x.released_at IS NULL OR x.released_at >= :f) $vf", $p);
        $sail = DB::pairs("SELECT x.vessel_id, SUM(x.sailing_hours) FROM shp_voyages x WHERE x.atd BETWEEN :f AND :t AND x.status IN ('departed', 'arrived', 'completed') $vf GROUP BY x.vessel_id", $p);
        $names = $view === 'vessel' ? DB::pairs('SELECT id, name FROM shp_vessels') : DB::pairs("SELECT e.id, CONCAT(s.code, ' ', e.code, ' ', e.name) FROM shp_equipment e JOIN shp_vessels s ON s.id = e.vessel_id");
        $key = fn(array $r) => $view === 'vessel' ? (int)$r['vessel_id'] : (int)($r['equipment_id'] ?? 0);
        $g = [];
        $blank = ['defects' => 0, 'failures' => 0, 'repairs' => 0, 'repair_h' => 0.0, 'down_h' => 0.0, 'holds' => 0, 'sail' => 0.0];
        if ($view === 'vessel') {
            foreach (DB::column('SELECT id FROM shp_vessels' . (!empty($f['vessel']) ? ' WHERE id = ' . (int)$f['vessel'] : '')) as $vid) {
                $g[(int)$vid] = $blank;
            }
        }
        foreach ($defects as $d) {
            $g[$key($d)] ??= $blank;
            $g[$key($d)]['defects']++;
            $g[$key($d)]['failures'] += $d['severity'] !== 'minor' || (int)$d['safety_critical'] ? 1 : 0;
        }
        foreach ($wos as $w) {
            $h = $w['downtime_hours'] !== null && (float)$w['downtime_hours'] > 0 ? (float)$w['downtime_hours'] : (float)shprpt_hours($w['actual_start'], $w['actual_end']);
            $g[$key($w)] ??= $blank;
            $g[$key($w)]['repairs']++;
            $g[$key($w)]['repair_h'] += max(0.0, $h);
        }
        foreach ($holds as $hd) {
            $a = max(strtotime($hd['placed_at']), strtotime($from));
            $b = min($hd['released_at'] ? strtotime($hd['released_at']) : strtotime(Ship::now()), strtotime($to));
            $g[$key($hd)] ??= $blank;
            $g[$key($hd)]['holds']++;
            $g[$key($hd)]['down_h'] += max(0, $b - $a) / 3600;
        }
        if ($view === 'vessel') {
            foreach ($sail as $vid => $h) {
                $g[(int)$vid] ??= $blank;
                $g[(int)$vid]['sail'] = (float)$h;
            }
        }
        $rows = [];
        foreach ($g as $k => $x) {
            if ($view === 'equipment' && !$x['defects'] && !$x['repairs'] && !$x['holds']) {
                continue;
            }
            $up = max(0.0, $periodH - $x['down_h']);
            $rows[] = ['name' => $k ? ($names[$k] ?? ('#' . $k)) : '(no equipment named)', 'defects' => $x['defects'], 'failures' => $x['failures'], 'repairs' => $x['repairs'],
                'mttr' => $x['repairs'] ? round($x['repair_h'] / $x['repairs'], 1) : null, 'holds' => $x['holds'], 'down' => round($x['down_h'], 1),
                'avail' => $view === 'vessel' && $periodH > 0 ? round(100 * $up / $periodH, 1) : null, 'mtbf' => $x['failures'] ? round($up / $x['failures'], 1) : null,
                'sail' => $view === 'vessel' ? round($x['sail'], 1) : null];
        }
        usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));
        $cols = [['key' => 'name', 'label' => $view === 'vessel' ? 'Vessel' : 'Equipment'], ['key' => 'defects', 'label' => 'Defects reported', 'type' => 'int'], ['key' => 'failures', 'label' => 'Failures', 'type' => 'int'],
            ['key' => 'repairs', 'label' => 'Repairs completed', 'type' => 'int'], ['key' => 'mttr', 'label' => 'Mean time to repair (h)', 'type' => 'qty'], ['key' => 'holds', 'label' => 'Safety holds', 'type' => 'int'],
            ['key' => 'down', 'label' => 'Hours on hold', 'type' => 'qty'], ['key' => 'avail', 'label' => 'Availability', 'type' => 'pct'], ['key' => 'mtbf', 'label' => 'Mean time between failures (h)', 'type' => 'qty'],
            ['key' => 'sail', 'label' => 'Sailing hours', 'type' => 'qty']];
        $totals = ['name' => 'TOTAL'];
        foreach (['defects', 'failures', 'repairs', 'holds'] as $k) {
            $totals[$k] = array_sum(array_column($rows, $k));
        }
        $totals['down'] = round(array_sum(array_column($rows, 'down')), 1);
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $totals,
            'notes' => h('Period of ' . qty(round($periodH, 1)) . ' hours. A failure is a defect that is not minor or is safety-critical; downtime is the time the vessel was under a no-sail or restricted-sail hold; '
                . 'mean time to repair is the hours of the corrective work orders completed in the period; availability is the share of the period the vessel was not on hold.')];
    },
]);

Report::define('shp_cert_expiry', [
    'filters' => [],
    'filter_hook' => fn(array $f) => ['within' => ($f['within'] ?? '') !== '' ? $f['within'] : '90'] + $f,
    'extra_filters' => ['within' => ['Expiring within', 'shprpt_within_opts', null], 'vessel' => ['Vessel', 'shprpt_vessel_opts']],
    'checks' => ['critical_only' => 'Certificates required for sailing only'],
    'run' => function (array $f): array {
        $within = ($f['within'] ?? '') !== '' ? (string)$f['within'] : '90';
        $today = Ship::today();
        $p = [];
        $sql = 'SELECT c.*, s.name AS vessel FROM shp_certificates c JOIN shp_vessels s ON s.id = c.vessel_id WHERE 1 = 1';
        if ($within !== 'all') {
            $sql .= ' AND c.expires_on IS NOT NULL AND c.expires_on <= :lim';
            $p['lim'] = date('Y-m-d', strtotime($today . ' +' . (int)$within . ' days'));
        }
        if (!empty($f['vessel'])) {
            $sql .= ' AND c.vessel_id = :vs';
            $p['vs'] = (int)$f['vessel'];
        }
        if (($f['critical_only'] ?? '') === '1') {
            $sql .= ' AND c.is_critical = 1';
        }
        $rows = [];
        $exp = 0;
        foreach (DB::all($sql . ' ORDER BY c.expires_on IS NULL, c.expires_on, s.name', $p) as $c) {
            $days = $c['expires_on'] ? (int)floor((strtotime($c['expires_on']) - strtotime($today)) / 86400) : null;
            $exp += $days !== null && $days < 0 ? 1 : 0;
            $rows[] = ['vessel' => $c['vessel'], 'cert' => $c['cert_type'], 'no' => $c['cert_no'], 'issuer' => $c['issuer'], 'issued' => $c['issued_on'], 'expires' => $c['expires_on'], 'days' => $days,
                'state' => $days === null ? 'No expiry' : ($days < 0 ? 'Expired' : ($days <= (int)($c['alert_days'] ?: 30) ? 'Renew now' : 'Valid')),
                'critical' => (int)$c['is_critical'] ? 'Yes' : 'No', 'owner' => Ship::userName($c['owner_user_id'] ? (int)$c['owner_user_id'] : null),
                '_links' => ['cert' => Report::link('fleet.certificates', ['id' => (int)$c['id']])]];
        }
        $cols = [['key' => 'vessel', 'label' => 'Vessel'], ['key' => 'cert', 'label' => 'Certificate'], ['key' => 'no', 'label' => 'Number'], ['key' => 'issuer', 'label' => 'Issued by'],
            ['key' => 'issued', 'label' => 'Issued', 'type' => 'date'], ['key' => 'expires', 'label' => 'Expires', 'type' => 'date'], ['key' => 'days', 'label' => 'Days left', 'type' => 'int'],
            ['key' => 'state', 'label' => 'Standing'], ['key' => 'critical', 'label' => 'Required for sailing'], ['key' => 'owner', 'label' => 'Renewal owner']];
        return ['columns' => $cols, 'rows' => $rows,
            'notes' => h(count($rows) . ' certificate' . (count($rows) === 1 ? '' : 's') . ' listed, ' . $exp . ' expired. A certificate required for sailing that expires before a voyage arrives places a no-sail hold on the vessel.')];
    },
]);

Report::define('shp_spares', [
    'filters' => ['range', 'item'],
    'default_dates' => 'this_year',
    'extra_filters' => ['vessel' => ['Vessel', 'shprpt_vessel_opts']],
    'run' => function (array $f): array {
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $sql = 'SELECT pt.*, w.wo_no, w.title, w.id AS wid, s.name AS vessel, e.code AS eq_code, e.name AS eq_name, i.code AS item_code, i.name AS item_name, i.unit, si.name AS site,
                       t.txn_type, t.txn_no
                  FROM shp_wo_parts pt JOIN shp_work_orders w ON w.id = pt.wo_id JOIN shp_vessels s ON s.id = w.vessel_id LEFT JOIN shp_equipment e ON e.id = w.equipment_id
                  JOIN items i ON i.id = pt.item_id LEFT JOIN sites si ON si.id = pt.site_id LEFT JOIN transactions t ON t.id = pt.txn_id
                 WHERE pt.issued_at BETWEEN :f AND :t';
        if (!empty($f['vessel'])) {
            $sql .= ' AND w.vessel_id = :vs';
            $p['vs'] = (int)$f['vessel'];
        }
        if (!empty($f['item_id'])) {
            $sql .= ' AND pt.item_id = :it';
            $p['it'] = (int)$f['item_id'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY pt.issued_at, pt.id', $p) as $r) {
            $rows[] = ['date' => substr($r['issued_at'], 0, 10), 'wo' => $r['wo_no'], 'work' => $r['title'], 'vessel' => $r['vessel'], 'equipment' => $r['eq_code'] ? $r['eq_code'] . ' ' . $r['eq_name'] : '',
                'item' => $r['item_code'] . ' ' . $r['item_name'], 'site' => $r['site'], 'qty' => (float)$r['qty'], 'unit' => $r['unit'], 'cost' => (float)$r['unit_cost'], 'amount' => (float)$r['amount'],
                'by' => Ship::userName($r['issued_by'] ? (int)$r['issued_by'] : null), 'doc' => $r['txn_no'] ? Txn::label((string)$r['txn_type']) . ' ' . $r['txn_no'] : '',
                '_links' => ['wo' => Report::link('fleet.workorders', ['id' => (int)$r['wid']]), 'doc' => shprpt_txn_link($r['txn_id'] ? (int)$r['txn_id'] : null, $r['txn_type'])],
                '_ref' => ['item' => (int)$r['item_id']]];
        }
        $cols = [['key' => 'date', 'label' => 'Issued', 'type' => 'date'], ['key' => 'wo', 'label' => 'Work order'], ['key' => 'work', 'label' => 'Work'], ['key' => 'vessel', 'label' => 'Vessel'],
            ['key' => 'equipment', 'label' => 'Equipment'], ['key' => 'item', 'label' => 'Spare part'], ['key' => 'site', 'label' => 'From stores'], ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'],
            ['key' => 'unit', 'label' => 'Unit'], ['key' => 'cost', 'label' => 'Unit cost', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'sensitive' => 'cost'],
            ['key' => 'by', 'label' => 'Issued by'], ['key' => 'doc', 'label' => 'Stock document']];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => ['date' => 'TOTAL', 'qty' => array_sum(array_column($rows, 'qty')), 'amount' => r2(array_sum(array_column($rows, 'amount')))],
            'notes' => h('Spare parts issued from the stores to work orders, at their average cost. Parts can only be issued from stock on hand.')];
    },
]);

// ================================================================================================ SERVICE

Report::define('shp_cases', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => [
        'view' => ['Show', fn() => ['category' => 'By category', 'priority' => 'By priority', 'channel' => 'By channel', 'owner' => 'By owner', 'detail' => 'Each case'], null],
        'category' => ['Category', fn() => Ship::CASE_CATEGORIES],
    ],
    'checks' => ['breached' => 'Only cases past their service time'],
    'run' => function (array $f): array {
        $view = in_array($f['view'] ?? '', ['category', 'priority', 'channel', 'owner', 'detail'], true) ? $f['view'] : 'category';
        $now = Ship::now();
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        $sql = 'SELECT c.* FROM shp_cases c WHERE c.created_at BETWEEN :f AND :t';
        if (!empty($f['category'])) {
            $sql .= ' AND c.category = :cat';
            $p['cat'] = (string)$f['category'];
        }
        $closed = ['resolved', 'closed'];
        $rows = [];
        $g = [];
        foreach (DB::all($sql . ' ORDER BY c.created_at', $p) as $c) {
            $done = in_array($c['status'], $closed, true) || $c['resolved_at'];
            $breach = $c['sla_due_at'] && (($c['resolved_at'] && $c['resolved_at'] > $c['sla_due_at']) || (!$c['resolved_at'] && $now > $c['sla_due_at']));
            if (($f['breached'] ?? '') === '1' && !$breach) {
                continue;
            }
            $resp = shprpt_hours($c['created_at'], $c['first_response_at']);
            $res = shprpt_hours($c['created_at'], $c['resolved_at']);
            $comp = $c['comp_amount'] !== null && in_array($c['comp_status'], ['approved', 'paid', 'issued'], true) ? (float)$c['comp_amount'] : 0.0;
            if ($view === 'detail') {
                $rows[] = ['case' => $c['case_no'], 'opened' => $c['created_at'], 'category' => Ship::CASE_CATEGORIES[$c['category']] ?? $c['category'], 'subject' => $c['subject'],
                    'priority' => ucfirst((string)$c['priority']), 'channel' => ucfirst((string)$c['channel']), 'owner' => Ship::userName($c['assigned_to'] ? (int)$c['assigned_to'] : null),
                    'status' => ucwords(str_replace('_', ' ', (string)$c['status'])), 'due' => $c['sla_due_at'], 'sla' => $breach ? 'Missed' : ($done ? 'Met' : 'Within time'),
                    'response' => $resp, 'resolution' => $res, 'outcome' => $c['resolution_code'] ? (Care::RESOLUTIONS[$c['resolution_code']] ?? $c['resolution_code']) : '',
                    'comp' => $comp ?: null, 'csat' => $c['satisfaction'] !== null ? (int)$c['satisfaction'] : null, '_links' => ['case' => Report::link('care.cases', ['id' => (int)$c['id']])]];
                continue;
            }
            $k = match ($view) {
                'priority' => ucfirst((string)$c['priority']),
                'channel' => ucfirst((string)$c['channel']),
                'owner' => Ship::userName($c['assigned_to'] ? (int)$c['assigned_to'] : null) ?: '(not assigned)',
                default => Ship::CASE_CATEGORIES[$c['category']] ?? (string)$c['category'],
            };
            $g[$k] ??= ['n' => 0, 'open' => 0, 'done' => 0, 'met' => 0, 'breach' => 0, 'resp' => 0.0, 'resp_n' => 0, 'res' => 0.0, 'res_n' => 0, 'comp' => 0.0, 'csat' => 0, 'csat_n' => 0];
            $x = &$g[$k];
            $x['n']++;
            $x[$done ? 'done' : 'open']++;
            $x[$breach ? 'breach' : 'met']++;
            if ($resp !== null) {
                $x['resp'] += $resp;
                $x['resp_n']++;
            }
            if ($res !== null) {
                $x['res'] += $res;
                $x['res_n']++;
            }
            $x['comp'] += $comp;
            if ($c['satisfaction'] !== null) {
                $x['csat'] += (int)$c['satisfaction'];
                $x['csat_n']++;
            }
            unset($x);
        }
        if ($view === 'detail') {
            $cols = [['key' => 'case', 'label' => 'Case'], ['key' => 'opened', 'label' => 'Opened', 'type' => 'datetime'], ['key' => 'category', 'label' => 'Category'], ['key' => 'subject', 'label' => 'Subject'],
                ['key' => 'priority', 'label' => 'Priority'], ['key' => 'channel', 'label' => 'Channel'], ['key' => 'owner', 'label' => 'Owner'], ['key' => 'status', 'label' => 'Status'],
                ['key' => 'due', 'label' => 'Service time due', 'type' => 'datetime'], ['key' => 'sla', 'label' => 'Service time'], ['key' => 'response', 'label' => 'First response (h)', 'type' => 'qty'],
                ['key' => 'resolution', 'label' => 'Resolved in (h)', 'type' => 'qty'], ['key' => 'outcome', 'label' => 'Resolution'], ['key' => 'comp', 'label' => 'Compensation', 'type' => 'money'],
                ['key' => 'csat', 'label' => 'Satisfaction (1-5)', 'type' => 'int']];
            return ['columns' => $cols, 'rows' => $rows, 'totals' => ['case' => 'TOTAL', 'comp' => r2(array_sum(array_map('floatval', array_column($rows, 'comp'))))]];
        }
        ksort($g);
        foreach ($g as $k => $x) {
            $rows[] = ['name' => $k, 'cases' => $x['n'], 'open' => $x['open'], 'resolved' => $x['done'], 'met' => $x['met'], 'breached' => $x['breach'], 'sla_pct' => shprpt_pct($x['met'], $x['n']),
                'response' => $x['resp_n'] ? round($x['resp'] / $x['resp_n'], 1) : null, 'resolution' => $x['res_n'] ? round($x['res'] / $x['res_n'], 1) : null, 'comp' => r2($x['comp']),
                'csat' => $x['csat_n'] ? round($x['csat'] / $x['csat_n'], 1) : null];
        }
        $label = ['category' => 'Category', 'priority' => 'Priority', 'channel' => 'Channel', 'owner' => 'Owner'][$view];
        $cols = [['key' => 'name', 'label' => $label], ['key' => 'cases', 'label' => 'Cases', 'type' => 'int'], ['key' => 'open', 'label' => 'Open', 'type' => 'int'], ['key' => 'resolved', 'label' => 'Resolved', 'type' => 'int'],
            ['key' => 'met', 'label' => 'Within service time', 'type' => 'int'], ['key' => 'breached', 'label' => 'Past service time', 'type' => 'int'], ['key' => 'sla_pct', 'label' => 'Service time met', 'type' => 'pct'],
            ['key' => 'response', 'label' => 'Average first response (h)', 'type' => 'qty'], ['key' => 'resolution', 'label' => 'Average time to resolve (h)', 'type' => 'qty'],
            ['key' => 'comp', 'label' => 'Compensation', 'type' => 'money'], ['key' => 'csat', 'label' => 'Average satisfaction (1-5)', 'type' => 'qty']];
        $n = array_sum(array_column($rows, 'cases'));
        $met = array_sum(array_column($rows, 'met'));
        return ['columns' => $cols, 'rows' => $rows,
            'totals' => ['name' => 'TOTAL', 'cases' => $n, 'open' => array_sum(array_column($rows, 'open')), 'resolved' => array_sum(array_column($rows, 'resolved')), 'met' => $met,
                'breached' => array_sum(array_column($rows, 'breached')), 'sla_pct' => shprpt_pct($met, $n), 'comp' => r2(array_sum(array_column($rows, 'comp')))],
            'notes' => h('Service times by priority: urgent ' . Ship::setting('sla_urgent_hours', '4') . ' h, high ' . Ship::setting('sla_high_hours', '12') . ' h, normal ' . Ship::setting('sla_normal_hours', '48')
                . ' h, low ' . Ship::setting('sla_low_hours', '96') . ' h from the time the case is opened. An open case past its time counts as missed.')];
    },
]);

// ================================================================================================ CONTROL

/** The kinds of control exception */
function shprpt_flag_opts(): array
{
    return [
        'refund_sod' => 'Refund approved by the seller or by the requester',
        'large_refund' => 'Large refunds',
        'fare_override' => 'Fares changed by hand',
        'manual' => 'Manual tickets not matched or duplicated',
        'scans' => 'Rejected boarding scans',
        'over_short' => 'Cash over or short',
        'after_close' => 'Cash entered on a shift after its count',
        'unpaid_boarded' => 'Boarded without payment',
        'unverified' => 'Discount without an ID check',
        'voided' => 'Shipping documents voided',
    ];
}

Report::define('shp_exceptions', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => ['flag' => ['Kind', 'shprpt_flag_opts']],
    'run' => function (array $f): array {
        $want = (string)($f['flag'] ?? '');
        $on = fn(string $k) => $want === '' || $want === $k;
        $kinds = shprpt_flag_opts();
        $F = $f['from'] . ' 00:00:00';
        $T = $f['to'] . ' 23:59:59';
        $rows = [];
        $add = function (string $kind, ?string $date, string $ref, ?string $link, string $who, ?float $amount, string $detail) use (&$rows, $kinds) {
            $rows[] = ['date' => $date ? substr($date, 0, 10) : null, 'kind' => $kinds[$kind], 'ref' => $ref, 'who' => $who, 'amount' => $amount, 'detail' => $detail, '_links' => ['ref' => $link]];
        };
        if ($on('refund_sod') || $on('large_refund')) {
            $large = (float)Ship::setting('large_refund_amount', '5000');
            foreach (DB::all("SELECT rf.*, b.booking_ref, b.created_by AS sold_by,
                                     (SELECT p.received_by FROM shp_payments p WHERE p.booking_id = rf.booking_id ORDER BY p.id LIMIT 1) AS received_by
                                FROM shp_refunds rf JOIN shp_bookings b ON b.id = rf.booking_id WHERE rf.requested_at BETWEEN :f AND :t ORDER BY rf.requested_at", ['f' => $F, 't' => $T]) as $r) {
                $link = Report::link('tkt.refunds', ['id' => (int)$r['id']]);
                $appr = $r['approved_by'] ? (int)$r['approved_by'] : 0;
                if ($on('refund_sod') && $appr) {
                    $why = [];
                    if ($appr === (int)$r['requested_by']) {
                        $why[] = 'the same person asked for and approved it';
                    }
                    if ($appr === (int)$r['sold_by'] || $appr === (int)$r['received_by']) {
                        $why[] = 'the approver sold or took payment for the booking';
                    }
                    if ($why) {
                        $add('refund_sod', $r['approved_at'], $r['refund_no'], $link, Ship::userName($appr), (float)$r['refund_amount'], 'Booking ' . $r['booking_ref'] . ': ' . implode('; ', $why) . '.');
                    }
                }
                if ($on('large_refund') && (float)$r['refund_amount'] >= $large && $large > 0) {
                    $add('large_refund', $r['requested_at'], $r['refund_no'], $link, Ship::userName((int)$r['requested_by']), (float)$r['refund_amount'],
                        'Booking ' . $r['booking_ref'] . ', ' . strtolower(shprpt_st($r['status'])) . ' (limit ' . money($large) . ').');
                }
            }
        }
        if ($on('fare_override')) {
            foreach (DB::all("SELECT a.created_at, a.username, a.record_id, a.record_ref, a.amount, a.reason, t.ticket_no, t.booking_id, t.fare_id, fr.base_fare
                                FROM audit_trail a LEFT JOIN shp_tickets t ON t.id = a.record_id LEFT JOIN shp_fares fr ON fr.id = t.fare_id
                               WHERE a.action = 'fare_override' AND a.created_at BETWEEN :f AND :t ORDER BY a.created_at", ['f' => $F, 't' => $T]) as $a) {
                $add('fare_override', $a['created_at'], (string)($a['ticket_no'] ?: $a['record_ref']), $a['booking_id'] ? Report::link('tkt.bookings', ['id' => (int)$a['booking_id']]) : null,
                    (string)$a['username'], $a['amount'] !== null ? (float)$a['amount'] : null,
                    'Fare set by hand' . ($a['base_fare'] !== null ? ' (fare matrix ' . money($a['base_fare']) . ')' : '') . ($a['reason'] ? ': ' . $a['reason'] : '') . '.');
            }
        }
        if ($on('manual')) {
            foreach (DB::all("SELECT m.*, v.voyage_no FROM shp_manual_tickets m LEFT JOIN shp_voyages v ON v.id = m.voyage_id
                               WHERE m.issued_at BETWEEN :f AND :t AND m.status <> 'void' AND (m.ticket_id IS NULL OR m.duplicate_of IS NOT NULL) ORDER BY m.issued_at", ['f' => $F, 't' => $T]) as $m) {
                $add('manual', $m['issued_at'], (string)$m['series_no'], Report::link('tkt.manual', ['id' => (int)$m['id']]), Ship::userName($m['issued_by'] ? (int)$m['issued_by'] : null),
                    (float)$m['amount'], ($m['duplicate_of'] ? 'Series number used twice' : 'Not yet matched to a system ticket') . ($m['voyage_no'] ? ', voyage ' . $m['voyage_no'] : '') . '.');
            }
        }
        if ($on('scans')) {
            foreach (DB::all("SELECT x.voyage_id, x.operator_id, v.voyage_no, MAX(x.scanned_at) AS last_at, COUNT(*) AS n,
                                     SUM(x.result = 'duplicate') AS dup, SUM(x.result = 'conflict') AS conf, SUM(x.result = 'wrong_voyage') AS wrong,
                                     SUM(x.result IN ('invalid', 'unpaid', 'cancelled', 'not_open')) AS bad
                                FROM shp_boarding_scans x JOIN shp_voyages v ON v.id = x.voyage_id
                               WHERE x.result <> 'accepted' AND x.scanned_at BETWEEN :f AND :t GROUP BY x.voyage_id, x.operator_id, v.voyage_no ORDER BY last_at", ['f' => $F, 't' => $T]) as $s) {
                $parts = array_filter([(int)$s['dup'] ? $s['dup'] . ' duplicate' : '', (int)$s['conf'] ? $s['conf'] . ' conflicting' : '', (int)$s['wrong'] ? $s['wrong'] . ' for another voyage' : '',
                    (int)$s['bad'] ? $s['bad'] . ' invalid, unpaid or cancelled' : '']);
                $add('scans', $s['last_at'], (string)$s['voyage_no'], Report::link('tkt.boarding', ['voyage' => (int)$s['voyage_id'], 'log' => 1]), Ship::userName($s['operator_id'] ? (int)$s['operator_id'] : null),
                    null, (int)$s['n'] . ' rejected scan' . ((int)$s['n'] === 1 ? '' : 's') . ': ' . implode(', ', $parts) . '.');
            }
        }
        if ($on('over_short')) {
            foreach (DB::all("SELECT s.*, o.name AS office FROM shp_cashier_shifts s LEFT JOIN shp_offices o ON o.id = s.office_id
                               WHERE s.closed_at BETWEEN :f AND :t AND ABS(s.over_short) >= 0.01 ORDER BY s.closed_at", ['f' => $F, 't' => $T]) as $s) {
                $add('over_short', $s['closed_at'], $s['shift_no'], Report::link('tkt.cashier', ['id' => (int)$s['id']]), Ship::userName((int)$s['cashier_id']), (float)$s['over_short'],
                    ((float)$s['over_short'] > 0 ? 'Over' : 'Short') . ': counted ' . money($s['counted_cash']) . ' against ' . money($s['expected_cash']) . ' expected, ' . $s['office'] . '.');
            }
        }
        if ($on('after_close')) {
            foreach (DB::all("SELECT s.*, (SELECT COALESCE(SUM(p.amount), 0) FROM shp_payments p WHERE p.shift_id = s.id AND p.channel = 'cash' AND p.status IN ('captured', 'settled')) AS cash,
                                     (SELECT COUNT(*) FROM shp_payments p WHERE p.shift_id = s.id AND p.channel = 'cash' AND p.status IN ('captured', 'settled')) AS n,
                                     (SELECT COALESCE(SUM(rf.refund_amount), 0) FROM shp_refunds rf WHERE rf.shift_id = s.id AND rf.status = 'paid' AND rf.method = 'cash') AS refunds
                                FROM shp_cashier_shifts s WHERE s.closed_at BETWEEN :f AND :t ORDER BY s.closed_at", ['f' => $F, 't' => $T]) as $s) {
                $diff = r2((float)$s['opening_float'] + (float)$s['cash'] - (float)$s['refunds'] - (float)$s['expected_cash']);
                if (abs($diff) >= 0.01) {
                    $add('after_close', $s['closed_at'], $s['shift_no'], Report::link('tkt.cashier', ['id' => (int)$s['id']]), Ship::userName((int)$s['cashier_id']), $diff,
                        'The shift\'s ' . (int)$s['n'] . ' cash payments and its refunds now come to ' . money((float)$s['opening_float'] + (float)$s['cash'] - (float)$s['refunds'])
                        . ' in the drawer, but ' . money($s['expected_cash']) . ' was expected when it was counted at ' . fdatetime($s['closed_at']) . '.');
                }
            }
        }
        if ($on('unpaid_boarded')) {
            foreach (DB::all("SELECT t.id, t.ticket_no, t.booking_id, t.total, t.boarded_at, t.boarded_by, v.voyage_no FROM shp_tickets t JOIN shp_voyages v ON v.id = t.voyage_id
                               WHERE t.status = 'boarded' AND t.boarded_at BETWEEN :f AND :t
                                 AND NOT EXISTS (SELECT 1 FROM shp_payments p WHERE p.booking_id = t.booking_id AND p.status IN ('captured', 'authorized', 'settled'))
                               ORDER BY t.boarded_at", ['f' => $F, 't' => $T]) as $t) {
                $add('unpaid_boarded', $t['boarded_at'], (string)$t['ticket_no'], Report::link('tkt.bookings', ['id' => (int)$t['booking_id']]), Ship::userName($t['boarded_by'] ? (int)$t['boarded_by'] : null),
                    (float)$t['total'], 'Boarded on ' . $t['voyage_no'] . ' with no payment recorded on the booking.');
            }
        }
        if ($on('unverified')) {
            foreach (DB::all("SELECT t.id, t.ticket_no, t.booking_id, t.discount_amount, t.issued_at, c.name, b.created_by FROM shp_tickets t JOIN shp_fare_categories c ON c.id = t.category_id
                                JOIN shp_bookings b ON b.id = t.booking_id
                               WHERE c.requires_id = 1 AND t.discount_amount > 0 AND t.discount_verified_by IS NULL AND t.issued_at BETWEEN :f AND :t
                                 AND t.status NOT IN ('cancelled', 'expired') ORDER BY t.issued_at", ['f' => $F, 't' => $T]) as $t) {
                $add('unverified', $t['issued_at'], (string)$t['ticket_no'], Report::link('tkt.bookings', ['id' => (int)$t['booking_id']]), Ship::userName($t['created_by'] ? (int)$t['created_by'] : null) ?: 'Passenger portal',
                    (float)$t['discount_amount'], $t['name'] . ' discount given without the ID being checked.');
            }
        }
        if ($on('voided')) {
            foreach (DB::all("SELECT DISTINCT t.id, t.txn_type, t.txn_no, t.total, t.voided_at, t.voided_by, t.void_reason, ps.memo FROM transactions t JOIN shp_postings ps ON ps.txn_id = t.id
                               WHERE t.is_voided = 1 AND t.voided_at BETWEEN :f AND :t ORDER BY t.voided_at", ['f' => $F, 't' => $T]) as $t) {
                $add('voided', $t['voided_at'], Txn::label((string)$t['txn_type']) . ' ' . $t['txn_no'], shprpt_txn_link((int)$t['id'], $t['txn_type']), Ship::userName($t['voided_by'] ? (int)$t['voided_by'] : null),
                    (float)$t['total'], 'Voided' . ($t['void_reason'] ? ': ' . $t['void_reason'] : '') . ' (' . $t['memo'] . ').');
            }
        }
        usort($rows, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
        $cols = [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'kind', 'label' => 'Exception'], ['key' => 'ref', 'label' => 'Record'], ['key' => 'who', 'label' => 'Person'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'detail', 'label' => 'What was found']];
        $count = [];
        foreach ($rows as $r) {
            $count[$r['kind']] = ($count[$r['kind']] ?? 0) + 1;
        }
        $summary = $count ? implode('; ', array_map(fn($k, $n) => $k . ': ' . $n, array_keys($count), $count)) . '.' : 'No exception found for these dates.';
        return ['columns' => $cols, 'rows' => $rows, 'notes' => h('Records that break a control and should be looked at by someone other than the people involved. ' . $summary)];
    },
]);

Report::define('shp_reconcile', [
    'filters' => ['range'],
    'default_dates' => 'this_month',
    'extra_filters' => ['view' => ['Show', fn() => ['balances' => 'Records against the general ledger', 'daily' => 'Payments against accounting documents, by day', 'manifest' => 'Boarded against manifest, by voyage'], null]]
        + shprpt_vfilters(false),
    'checks' => ['only_diff' => 'Only lines with a difference'],
    'run' => function (array $f): array {
        $view = in_array($f['view'] ?? '', ['balances', 'daily', 'manifest'], true) ? $f['view'] : 'balances';
        $onlyDiff = ($f['only_diff'] ?? '') === '1';
        $F = $f['from'] . ' 00:00:00';
        $T = $f['to'] . ' 23:59:59';
        // payments of the period and the sales receipts / invoices they raised
        $daily = DB::all("SELECT DATE(p.received_at) AS d, COUNT(*) AS n, SUM(p.amount) AS paid, SUM(p.txn_id IS NULL) AS missing,
                                 SUM(CASE WHEN t.id IS NOT NULL AND t.is_voided = 0 THEN t.total ELSE 0 END) AS docs,
                                 SUM(CASE WHEN t.id IS NOT NULL AND t.is_voided = 1 THEN 1 ELSE 0 END) AS voided
                            FROM shp_payments p LEFT JOIN transactions t ON t.id = p.txn_id
                           WHERE p.status IN ('captured', 'authorized', 'settled') AND p.received_at BETWEEN :f AND :t GROUP BY DATE(p.received_at) ORDER BY d", ['f' => $F, 't' => $T]);
        if ($view === 'daily') {
            $rows = [];
            foreach ($daily as $d) {
                $diff = r2((float)$d['paid'] - (float)$d['docs']);
                $ok = abs($diff) < 0.01 && !(int)$d['missing'] && !(int)$d['voided'];
                if ($onlyDiff && $ok) {
                    continue;
                }
                $rows[] = ['date' => $d['d'], 'n' => (int)$d['n'], 'paid' => r2((float)$d['paid']), 'docs' => r2((float)$d['docs']), 'diff' => $diff,
                    'result' => $ok ? 'Agrees' : trim(((int)$d['missing'] ? $d['missing'] . ' payment(s) without a document. ' : '') . ((int)$d['voided'] ? $d['voided'] . ' document(s) voided. ' : '')
                        . (abs($diff) >= 0.01 ? 'Documents differ from payments.' : ''))];
            }
            $cols = [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'n', 'label' => 'Payments', 'type' => 'int'],
                ['key' => 'paid', 'label' => 'Payments recorded', 'type' => 'money'], ['key' => 'docs', 'label' => 'Sales receipts and invoices', 'type' => 'money'], ['key' => 'diff', 'label' => 'Difference', 'type' => 'money'],
                ['key' => 'result', 'label' => 'Result']];
            return ['columns' => $cols, 'rows' => $rows, 'totals' => ['date' => 'TOTAL', 'n' => array_sum(array_column($rows, 'n'))] + shprpt_sum($rows, ['paid', 'docs', 'diff']),
                'notes' => h('Every payment taken (counter, portal, agents and corporate accounts, cargo) is matched with the sales receipt or invoice it raised.')];
        }
        if ($view === 'manifest') {
            $p = [];
            $w = shprpt_vwhere($f, $p);
            $vs = DB::all('SELECT ' . shprpt_vcols() . ' FROM ' . shprpt_vfrom() . " WHERE $w AND v.status IN ('departed', 'arrived', 'completed') ORDER BY v.etd", $p);
            $ids = array_map('intval', array_column($vs, 'id'));
            $tk = shprpt_ticket_counts($ids);
            $p2 = [];
            $in = shprpt_in($ids, 'rm', $p2);
            $scans = DB::pairs("SELECT voyage_id, COUNT(DISTINCT ticket_id) FROM shp_boarding_scans WHERE voyage_id IN $in AND scan_type = 'board' AND result = 'accepted' AND ticket_id IS NOT NULL GROUP BY voyage_id", $p2);
            $man = [];
            foreach (DB::all("SELECT m.* FROM shp_manifests m WHERE m.voyage_id IN $in AND m.status = 'final'
                                AND m.version_no = (SELECT MAX(m2.version_no) FROM shp_manifests m2 WHERE m2.voyage_id = m.voyage_id AND m2.status = 'final')", $p2) as $m) {
                $man[(int)$m['voyage_id']] = $m;
            }
            $lines = DB::pairs("SELECT m.voyage_id, COUNT(*) FROM shp_manifest_lines l JOIN shp_manifests m ON m.id = l.manifest_id
                                 WHERE m.voyage_id IN $in AND m.status = 'final' AND l.line_kind = 'passenger'
                                   AND m.version_no = (SELECT MAX(m2.version_no) FROM shp_manifests m2 WHERE m2.voyage_id = m.voyage_id AND m2.status = 'final') GROUP BY m.voyage_id", $p2);
            $rows = [];
            foreach ($vs as $v) {
                $vid = (int)$v['id'];
                $b = shprpt_n($tk, $vid, ['boarded']);
                $m = $man[$vid] ?? null;
                $sc = (int)($scans[$vid] ?? 0);
                $mb = $m ? (int)$m['pax_boarded'] : null;
                $ml = isset($lines[$vid]) ? (int)$lines[$vid] : null;
                $issues = [];
                if (!$m) {
                    $issues[] = 'No final manifest';
                } else {
                    if ($mb !== $b) {
                        $issues[] = 'Manifest count differs from the boarded tickets';
                    }
                    if ($ml !== null && $ml !== $mb) {
                        $issues[] = 'Manifest lines differ from its count';
                    }
                    if (!$m['signed_at']) {
                        $issues[] = 'Manifest not signed';
                    }
                }
                if ($sc !== $b) {
                    $issues[] = 'Scans differ from the boarded tickets';
                }
                if ($onlyDiff && !$issues) {
                    continue;
                }
                $rows[] = ['voyage' => $v['voyage_no'], 'date' => $v['voyage_date'], 'route' => $v['route_label'], 'boarded' => $b, 'scans' => $sc, 'manifest' => $mb, 'lines' => $ml,
                    'version' => $m ? (int)$m['version_no'] : null, 'signed' => $m && $m['signed_at'] ? Ship::userName((int)$m['signed_by']) : '', 'diff' => $mb !== null ? $mb - $b : null,
                    'result' => $issues ? implode('; ', $issues) . '.' : 'Agrees', '_links' => ['voyage' => shprpt_link_voyage($vid), 'manifest' => Report::link('tkt.manifest', ['voyage' => $vid])]];
            }
            $cols = [['key' => 'voyage', 'label' => 'Voyage'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'route', 'label' => 'Route'], ['key' => 'boarded', 'label' => 'Boarded tickets', 'type' => 'int'],
                ['key' => 'scans', 'label' => 'Accepted scans', 'type' => 'int'], ['key' => 'manifest', 'label' => 'Manifest count', 'type' => 'int'], ['key' => 'lines', 'label' => 'Manifest passenger lines', 'type' => 'int'],
                ['key' => 'version', 'label' => 'Version', 'type' => 'int'], ['key' => 'signed', 'label' => 'Signed by'], ['key' => 'diff', 'label' => 'Difference', 'type' => 'int'], ['key' => 'result', 'label' => 'Result']];
            return ['columns' => $cols, 'rows' => $rows, 'totals' => ['voyage' => 'TOTAL', 'boarded' => array_sum(array_column($rows, 'boarded')), 'scans' => array_sum(array_column($rows, 'scans')),
                'manifest' => array_sum(array_map('intval', array_column($rows, 'manifest')))]];
        }
        // ---------------- balances: the shipping records against the general ledger accounts they feed
        $rows = [];
        $line = function (string $check, string $basis, float $rec, float $gl, ?string $link = null) use (&$rows, $onlyDiff) {
            $diff = r2($rec - $gl);
            if ($onlyDiff && abs($diff) < 0.01) {
                return;
            }
            $rows[] = ['check' => $check, 'basis' => $basis, 'records' => r2($rec), 'ledger' => r2($gl), 'diff' => $diff, 'result' => abs($diff) < 0.01 ? 'Agrees' : 'Difference to explain', '_links' => ['check' => $link]];
        };
        $acctLabel = function (int $id): string {
            $a = DB::row('SELECT code, name FROM accounts WHERE id = :id', ['id' => $id]);
            return $a ? $a['code'] . ' ' . $a['name'] : '';
        };
        $glLink = fn(int $id) => Report::link('rpt.general_ledger', ['account_id' => $id, 'dates' => 'all']);
        $recPax = (float)DB::value("SELECT COALESCE(SUM(fare_net + surcharge_net), 0) FROM shp_tickets WHERE revenue_done = 0 AND status IN ('issued', 'checked_in', 'boarded', 'no_show')");
        $a = Accts::get('shp_deferred_pax');
        $line('Deferred passenger revenue', 'Paid tickets of voyages not yet completed against ' . $acctLabel($a), $recPax, Accts::balance($a), $glLink($a));
        $recFr = (float)DB::value("SELECT COALESCE(SUM(fare_net), 0) FROM shp_vehicles WHERE revenue_done = 0 AND status IN ('issued', 'checked_in', 'loaded', 'unloaded', 'no_show')");
        foreach (DB::all("SELECT freight_gross, discount_amount, handling_fee FROM shp_cargo WHERE payment_terms = 'prepaid' AND paid_txn_id IS NOT NULL AND revenue_done = 0 AND status NOT IN ('cancelled', 'quotation')") as $c) {
            $recFr += ShipGL::splitVat((float)$c['freight_gross'] - (float)$c['discount_amount'])[0] + ShipGL::splitVat((float)$c['handling_fee'])[0];
        }
        $a = Accts::get('shp_deferred_freight');
        $line('Deferred freight revenue', 'Paid vehicle passes and prepaid waybills not yet carried against ' . $acctLabel($a), $recFr, Accts::balance($a), $glLink($a));
        // fees collected for the port and the insurer (ticket fees not yet remitted, and the cargo insurance billed on waybills)
        $fees = [];
        foreach (DB::all("SELECT tf.account_id, SUM(tf.amount) AS amt FROM shp_ticket_fees tf WHERE tf.status = 'collected' AND tf.account_id IS NOT NULL GROUP BY tf.account_id") as $r) {
            $fees[(int)$r['account_id']] = ['tickets' => (float)$r['amt'], 'cargo' => 0.0];
        }
        $cins = (int)DB::value("SELECT income_account_id FROM items WHERE code = 'SHP-CINS'");
        if ($cins) {
            $amt = (float)DB::value("SELECT COALESCE(SUM(insurance_fee), 0) FROM shp_cargo WHERE status NOT IN ('quotation', 'cancelled') AND (paid_txn_id IS NOT NULL OR invoice_txn_id IS NOT NULL)");
            $fees[$cins] = ['tickets' => $fees[$cins]['tickets'] ?? 0.0, 'cargo' => $amt];
        }
        foreach (DB::column('SELECT DISTINCT liability_account_id FROM shp_fees WHERE liability_account_id IS NOT NULL') as $id) {
            $fees[(int)$id] ??= ['tickets' => 0.0, 'cargo' => 0.0];
        }
        ksort($fees);
        foreach ($fees as $id => $x) {
            $basis = 'Fees collected on tickets and vehicle passes, not yet remitted' . ($x['cargo'] > 0 ? ', and cargo insurance billed on waybills (' . money($x['cargo']) . ')' : '');
            $line('Fees collected for others', $basis . ', against ' . $acctLabel($id), $x['tickets'] + $x['cargo'], Accts::balance($id), $glLink($id));
        }
        $paid = array_sum(array_map(fn($d) => (float)$d['paid'], $daily));
        $docs = array_sum(array_map(fn($d) => (float)$d['docs'], $daily));
        $line('Payments of the period', 'Payments taken ' . fdate($f['from']) . ' to ' . fdate($f['to']) . ' against their sales receipts and invoices', $paid, $docs,
            Report::link('rpt.shp_reconcile', ['view' => 'daily', 'dates' => 'custom', 'from' => $f['from'], 'to' => $f['to']]));
        $refundPaid = (float)DB::value("SELECT COALESCE(SUM(rf.refund_amount), 0) FROM shp_refunds rf WHERE rf.status = 'paid' AND rf.paid_at BETWEEN :f AND :t", ['f' => $F, 't' => $T]);
        $refundDocs = (float)DB::value("SELECT COALESCE(SUM(t.total), 0) FROM shp_refunds rf JOIN transactions t ON t.id = rf.paid_txn_id AND t.is_voided = 0 WHERE rf.status = 'paid' AND rf.paid_at BETWEEN :f AND :t", ['f' => $F, 't' => $T]);
        $line('Refunds paid in the period', 'Refunds paid out against their refund documents', $refundPaid, $refundDocs);
        $cols = [['key' => 'check', 'label' => 'Check'], ['key' => 'basis', 'label' => 'What is compared'], ['key' => 'records', 'label' => 'Shipping records', 'type' => 'money'],
            ['key' => 'ledger', 'label' => 'General ledger', 'type' => 'money'], ['key' => 'diff', 'label' => 'Difference', 'type' => 'money'], ['key' => 'result', 'label' => 'Result']];
        return ['columns' => $cols, 'rows' => $rows,
            'notes' => h('Balances are as of today; the payment and refund lines cover the report dates. Every line should agree: a difference points to a document posted by hand to a shipping account, or a record changed without its document.')];
    },
]);
