<?php
declare(strict_types=1);

/**
 * Voyages: the common reference of reservations, manifests, cargo, vehicles, fuel, crew, maintenance and the
 * voyage P&L. Status changes follow Ship::VOYAGE_FLOW and are kept in shp_voyage_events.
 *
 *  ready     the departure readiness checks must all pass (safety holds, certificates, capacity, crew,
 *            passenger information, final manifest, weather, clearance, checklist), or an approved override
 *            for the checks the law allows to be overridden. A no-sail hold and a boarded count above the
 *            passenger limit can never be overridden.
 *  departed  tickets that never boarded become no-shows; loaded cargo is in transit.
 *  completed the deferred fares, vehicle freight and prepaid cargo of the voyage are recognized as revenue
 *            (one journal per voyage, posted once).
 *  cancelled new sales stop, unpaid holds are released, passengers are told; each paid ticket is then refunded
 *            in full or moved free of charge (Refunds & Cancellations).
 */
final class Voyages
{
    public const CHECKLIST = [
        'safety_docs' => 'Safety certificates and documents on board and valid',
        'security'    => 'Security inspection of passengers, baggage and vehicles done',
        'stability'   => 'Loading, stability and lashing checked by the Chief Mate',
        'lifesaving'  => 'Life-saving and fire-fighting appliances ready; safety briefing set',
        'engine'      => 'Engine room ready for sea (Chief Engineer)',
        'provisions'  => 'Fuel, fresh water and provisions sufficient for the voyage',
    ];

    public static function create(array $d): int
    {
        $routeId = (int)($d['route_id'] ?? 0);
        $vesselId = (int)($d['vessel_id'] ?? 0);
        $route = DB::row('SELECT * FROM shp_routes WHERE id = :id AND is_active = 1', ['id' => $routeId]);
        $vessel = DB::row("SELECT * FROM shp_vessels WHERE id = :id AND status = 'active'", ['id' => $vesselId]);
        if (!$route) {
            fail('Choose an active route.');
        }
        if (!$vessel) {
            fail('Choose a vessel in service.');
        }
        $etd = (string)($d['etd'] ?? '');
        if (!strtotime($etd)) {
            fail('Enter the departure date and time.');
        }
        $etd = date('Y-m-d H:i:00', strtotime($etd));
        $eta = !empty($d['eta']) && strtotime((string)$d['eta']) ? date('Y-m-d H:i:00', strtotime((string)$d['eta'])) : date('Y-m-d H:i:00', strtotime($etd) + 60 * max(30, (int)$route['transit_minutes']));
        if ($eta <= $etd) {
            fail('The arrival has to be after the departure.');
        }
        if (!DB::value('SELECT 1 FROM shp_accom_classes WHERE vessel_id = :v', ['v' => $vesselId])) {
            fail($vessel['name'] . ' has no accommodation plan yet (Fleet & Maintenance > Vessel Registry).');
        }
        // the same vessel cannot sail two voyages at once, and the same sailing is not scheduled twice
        $clash = DB::row("SELECT voyage_no, etd, eta FROM shp_voyages WHERE vessel_id = :v AND status <> 'cancelled' AND etd < :eta AND eta > :etd LIMIT 1",
            ['v' => $vesselId, 'eta' => $eta, 'etd' => $etd]);
        if ($clash) {
            fail($vessel['name'] . ' is already scheduled on ' . $clash['voyage_no'] . ' (' . fdatetime($clash['etd']) . ' to ' . fdatetime($clash['eta']) . ').');
        }
        if (!empty($d['berth'])) {
            $berth = DB::row("SELECT v.voyage_no FROM shp_voyages v JOIN shp_routes r ON r.id = v.route_id WHERE r.origin_port_id = :p AND v.berth = :b
                               AND v.status <> 'cancelled' AND v.etd BETWEEN :a AND :z LIMIT 1", ['p' => $route['origin_port_id'], 'b' => $d['berth'],
                'a' => date('Y-m-d H:i:s', strtotime($etd) - 3600), 'z' => date('Y-m-d H:i:s', strtotime($etd) + 3600)]);
            if ($berth) {
                fail('Berth ' . $d['berth'] . ' is taken within an hour of this departure by ' . $berth['voyage_no'] . '.');
            }
        }
        $no = $vessel['code'] . '-' . date('ymd-Hi', strtotime($etd));
        if (DB::value('SELECT 1 FROM shp_voyages WHERE voyage_no = :n', ['n' => $no])) {
            $no .= '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
        }
        $status = in_array($d['status'] ?? 'draft', ['draft', 'published', 'on_sale'], true) ? ($d['status'] ?? 'draft') : 'draft';
        $id = DB::insert('shp_voyages', [
            'voyage_no' => $no, 'route_id' => $routeId, 'vessel_id' => $vesselId, 'voyage_date' => substr($etd, 0, 10), 'etd' => $etd, 'eta' => $eta,
            'status' => $status, 'booking_open' => $status === 'on_sale' ? Ship::now() : null,
            'booking_close' => date('Y-m-d H:i:s', strtotime($etd) - 60 * Ship::intSetting('booking_close_minutes', 30)),
            'boarding_open_min' => (int)($d['boarding_open_min'] ?? 60), 'pax_quota' => !empty($d['pax_quota']) ? (int)$d['pax_quota'] : null,
            'vehicle_quota_lm' => !empty($d['vehicle_quota_lm']) ? (float)$d['vehicle_quota_lm'] : null, 'berth' => $d['berth'] ?? null,
            'distance_nm' => $route['distance_nm'], 'created_by' => Auth::id(),
        ]);
        foreach (self::CHECKLIST as $k => $label) {
            DB::insert('shp_voyage_checklist', ['voyage_id' => $id, 'item_key' => $k, 'label' => $label]);
        }
        Ship::voyageEvent($id, 'status', 'Voyage scheduled', null, $status);
        Ship::audit('create', 'ops', 'shp_voyage', $id, 'Voyage ' . $no, ['page_key' => 'ops.voyages']);
        return $id;
    }

    /** Recurring schedule: one voyage per chosen weekday and time in the date range. Returns [created ids, skipped notes]. */
    public static function generate(int $routeId, int $vesselId, array $times, array $weekdays, string $from, string $to, string $status = 'on_sale'): array
    {
        if (!valid_date($from) || !valid_date($to) || $to < $from) {
            fail('Enter a valid date range.');
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > 120) {
            fail('Generate at most 120 days at a time.');
        }
        if (!$times || !$weekdays) {
            fail('Choose at least one departure time and one weekday.');
        }
        $made = [];
        $skipped = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            if (!in_array((int)date('N', $d), array_map('intval', $weekdays), true)) {
                continue;
            }
            foreach ($times as $tm) {
                if (!preg_match('/^\d{1,2}:\d{2}$/', trim($tm))) {
                    continue;
                }
                $etd = date('Y-m-d', $d) . ' ' . trim($tm) . ':00';
                try {
                    $made[] = DB::tx(fn() => self::create(['route_id' => $routeId, 'vessel_id' => $vesselId, 'etd' => $etd, 'status' => $status]));
                } catch (UserError $e) {
                    $skipped[] = fdatetime($etd) . ': ' . $e->getMessage();
                }
            }
        }
        return [$made, $skipped];
    }

    // ------------------------------------------------------------------ readiness

    /** Every departure check: [key => [label, ok, detail, overridable]] */
    public static function readiness(int $voyageId): array
    {
        $v = Ship::voyage($voyageId);
        Holds::syncCertificates((int)$v['vessel_id']);
        $out = [];
        $holds = Holds::active((int)$v['vessel_id']);
        $noSail = array_filter($holds, fn($h) => $h['hold_type'] === 'no_sail');
        $restricted = array_filter($holds, fn($h) => $h['hold_type'] === 'restricted');
        $out['holds'] = ['No unresolved no-sail hold on the vessel', !$noSail, $noSail ? implode('; ', array_column($noSail, 'reason')) : 'None', false];
        $out['restricted'] = ['No restricted-sail hold', !$restricted, $restricted ? implode('; ', array_column($restricted, 'reason')) : 'None', true];
        $expiring = DB::all('SELECT cert_type, expires_on FROM shp_certificates WHERE vessel_id = :v AND is_critical = 1 AND expires_on IS NOT NULL AND expires_on < :d', ['v' => $v['vessel_id'], 'd' => substr($v['eta'], 0, 10)]);
        $out['certs'] = ['Critical certificates valid through arrival', !$expiring, $expiring ? implode(', ', array_map(fn($c) => $c['cert_type'] . ' (' . fdate($c['expires_on']) . ')', $expiring)) : 'All valid', false];
        $c = Boarding::counts($voyageId);
        $limit = Booking::paxLimit($v);
        $out['capacity'] = ['Boarded passengers within the approved limit', $c['boarded'] <= $limit, $c['boarded'] . ' boarded, limit ' . $limit, false];
        $missing = (int)DB::value("SELECT COUNT(*) FROM shp_tickets WHERE voyage_id = :v AND status = 'boarded' AND (birth_date IS NULL OR gender IS NULL OR nationality IS NULL OR nationality = '')", ['v' => $voyageId]);
        $out['pax_info'] = ['Critical passenger information complete (birth date, sex, nationality)', $missing === 0, $missing ? $missing . ' boarded passenger(s) incomplete' : 'Complete', false];
        $m = DB::row("SELECT * FROM shp_manifests WHERE voyage_id = :v AND status = 'final' ORDER BY version_no DESC LIMIT 1", ['v' => $voyageId]);
        $lastScan = DB::value("SELECT MAX(scanned_at) FROM shp_boarding_scans WHERE voyage_id = :v AND result = 'accepted'", ['v' => $voyageId]);
        $manifestOk = $m && (int)$m['pax_boarded'] === $c['boarded'] && (!$lastScan || $m['generated_at'] >= $lastScan);
        $out['manifest'] = ['Final manifest generated and equal to the boarded count', (bool)$manifestOk,
            $m ? 'Version ' . $m['version_no'] . ': ' . $m['pax_boarded'] . ' boarded' . ($manifestOk ? '' : ', regenerate after the last boarding') : 'No final manifest yet', true];
        $crew = DB::all('SELECT c.rank_title, c.id FROM shp_voyage_crew vc JOIN shp_crew c ON c.id = vc.crew_id WHERE vc.voyage_id = :v AND vc.signed_on = 1', ['v' => $voyageId]);
        $ranks = array_map('strtolower', array_column($crew, 'rank_title'));
        $hasMaster = (bool)array_filter($ranks, fn($r) => str_contains($r, 'master') || str_contains($r, 'captain'));
        $hasChief = (bool)array_filter($ranks, fn($r) => str_contains($r, 'chief engineer'));
        $complement = (int)DB::value('SELECT crew_complement FROM shp_vessels WHERE id = :v', ['v' => $v['vessel_id']]);
        $out['crew'] = ['Crew complement signed on (with Master and Chief Engineer)', $hasMaster && $hasChief && count($crew) >= $complement,
            count($crew) . ' of ' . $complement . ' signed on' . ($hasMaster ? '' : ', no Master') . ($hasChief ? '' : ', no Chief Engineer'), true];
        $expiredCrew = $crew ? (int)DB::value('SELECT COUNT(DISTINCT cc.crew_id) FROM shp_crew_certs cc WHERE cc.is_required = 1 AND cc.expires_on < :d AND cc.crew_id IN (' . implode(',', array_map('intval', array_column($crew, 'id'))) . ')', ['d' => Ship::today()]) : 0;
        $out['crew_certs'] = ['Required crew certificates valid', $expiredCrew === 0, $expiredCrew ? $expiredCrew . ' crew member(s) with an expired certificate' : 'Valid', true];
        $out['weather'] = ['Weather advisory acknowledged', (int)$v['weather_ack'] === 1, $v['weather_note'] ?: 'Not acknowledged', true];
        $out['clearance'] = ['PCG / port clearance recorded', trim((string)$v['clearance_ref']) !== '', $v['clearance_ref'] ?: 'Not recorded', true];
        $open = DB::column('SELECT label FROM shp_voyage_checklist WHERE voyage_id = :v AND is_done = 0', ['v' => $voyageId]);
        $out['checklist'] = ['Departure checklist signed off', !$open, $open ? count($open) . ' item(s) open' : 'All done', true];
        $vehOver = Booking::lanesUsed($voyageId) > Booking::laneLimit($v) + 0.001;
        $out['vehicles'] = ['Vehicle deck within its lane meters', !$vehOver, qty(Booking::lanesUsed($voyageId)) . ' of ' . qty(Booking::laneLimit($v)) . ' m', false];
        $cargoT = (float)DB::value("SELECT COALESCE(SUM(weight_kg), 0) / 1000 FROM shp_cargo WHERE voyage_id = :v AND status IN ('loaded')", ['v' => $voyageId]);
        $cargoCap = (float)$v['cargo_capacity_t'];
        $out['cargo'] = ['Cargo loaded within the vessel cargo capacity', $cargoCap <= 0 || $cargoT <= $cargoCap + 0.001, qty($cargoT) . ' t of ' . qty($cargoCap) . ' t', false];
        return $out;
    }

    /** [all clear, the failed checks that cannot be overridden, the failed checks that can] */
    public static function readinessVerdict(int $voyageId): array
    {
        $hard = [];
        $soft = [];
        foreach (self::readiness($voyageId) as $k => [$label, $ok, $detail, $overridable]) {
            if (!$ok) {
                $overridable ? $soft[$k] = $label . ': ' . $detail : $hard[$k] = $label . ': ' . $detail;
            }
        }
        return [!$hard && !$soft, $hard, $soft];
    }

    // ------------------------------------------------------------------ status changes

    public static function transition(int $id, string $to, array $ctx = []): void
    {
        DB::tx(function () use ($id, $to, $ctx) {
            $v = Ship::voyage($id, true);
            if (!$v) {
                fail('Voyage not found.');
            }
            if (isset($ctx['version']) && (int)$ctx['version'] !== (int)$v['version']) {
                fail('Someone else changed this voyage a moment ago. Reload the page and try again.');
            }
            $from = $v['status'];
            Ship::assertFlow(Ship::VOYAGE_FLOW, $from, $to, 'the voyage');
            $now = $ctx['at'] ?? Ship::now();
            $upd = ['status' => $to, 'version' => (int)$v['version'] + 1, 'updated_at' => Ship::now()];
            $note = trim((string)($ctx['note'] ?? ''));
            switch ($to) {
                case 'on_sale':
                    $upd['booking_open'] = $v['booking_open'] ?: $now;
                    break;
                case 'ready':
                    [$clear, $hard, $soft] = self::readinessVerdict($id);
                    if ($hard) {
                        fail('Departure cannot be released: ' . implode('; ', $hard) . '. These checks cannot be overridden.');
                    }
                    if ($soft && !$v['override_by']) {
                        fail('Departure cannot be released yet: ' . implode('; ', $soft) . '. Complete them, or request a departure override with evidence.');
                    }
                    $upd['released_by'] = Auth::id();
                    $upd['released_at'] = $now;
                    break;
                case 'departed':
                    $upd['atd'] = $now;
                    $upd['delay_minutes'] = max(0, Ship::minutesBetween($v['etd'], $now));
                    if (!empty($ctx['delay_cause'])) {
                        $upd['delay_cause'] = mb_substr((string)$ctx['delay_cause'], 0, 160);
                    }
                    if (isset($ctx['fuel_rob_departure']) && $ctx['fuel_rob_departure'] !== '') {
                        $upd['fuel_rob_departure'] = (float)$ctx['fuel_rob_departure'];
                    }
                    $n = Booking::markNoShows($id);
                    DB::run("UPDATE shp_cargo SET status = 'in_transit', updated_at = :n WHERE voyage_id = :v AND status = 'loaded'", ['v' => $id, 'n' => Ship::now()]);
                    $note .= ($note ? ' ' : '') . $n . ' no-show(s).';
                    break;
                case 'arrived':
                    $upd['ata'] = $now;
                    $upd['sailing_hours'] = $v['atd'] ? round((strtotime($now) - strtotime($v['atd'])) / 3600, 2) : null;
                    if (isset($ctx['fuel_rob_arrival']) && $ctx['fuel_rob_arrival'] !== '') {
                        $upd['fuel_rob_arrival'] = (float)$ctx['fuel_rob_arrival'];
                    }
                    DB::run("UPDATE shp_cargo SET status = 'landed', updated_at = :n WHERE voyage_id = :v AND status = 'in_transit'", ['v' => $id, 'n' => Ship::now()]);
                    DB::run("UPDATE shp_vehicles SET status = 'unloaded', unloaded_at = :n WHERE voyage_id = :v AND status = 'loaded'", ['v' => $id, 'n' => $now]);
                    break;
                case 'completed':
                    if ($v['fuel_item_id'] && !DB::value("SELECT 1 FROM shp_fuel_logs WHERE voyage_id = :v AND log_type = 'issue' AND status = 'posted'", ['v' => $id])) {
                        fail('Record the fuel used on the voyage first (the ROB at departure and arrival): the voyage cost has to be complete before it is closed.');
                    }
                    if (!$v['report_signed_at']) {
                        fail('The Master signs the voyage report before the voyage is completed.');
                    }
                    self::recognizeRevenue($id, substr($now, 0, 10));
                    break;
                case 'cancelled':
                    if (trim((string)($ctx['reason'] ?? '')) === '' || trim((string)($ctx['authority'] ?? '')) === '') {
                        fail('Give the reason for the cancellation and the authority (PCG advisory, management decision ...).');
                    }
                    $upd['cancel_reason'] = mb_substr((string)$ctx['reason'], 0, 255);
                    $upd['cancel_authority'] = mb_substr((string)$ctx['authority'], 0, 120);
                    foreach (DB::column("SELECT DISTINCT booking_id FROM shp_tickets WHERE voyage_id = :v AND status = 'held'", ['v' => $id]) as $bid) {
                        Booking::release((int)$bid, 'cancelled', 'Voyage cancelled.');
                    }
                    foreach (DB::column("SELECT DISTINCT booking_id FROM shp_vehicles WHERE voyage_id = :v AND status = 'held'", ['v' => $id]) as $bid) {
                        Booking::release((int)$bid, 'cancelled', 'Voyage cancelled.');
                    }
                    self::noticeAffected($v, 'voyage_cancelled', 'Voyage ' . $v['voyage_no'] . ' is cancelled',
                        'Voyage ' . $v['voyage_no'] . ' (' . $v['origin_name'] . ' to ' . $v['dest_name'] . ', ' . fdatetime($v['etd']) . ') is cancelled: ' . $ctx['reason']
                        . '. You may move your ticket to another voyage free of charge or get a full refund at any ticket office or through our customer service.');
                    $note = 'Cancelled: ' . $ctx['reason'] . ' (authority: ' . $ctx['authority'] . ')';
                    break;
            }
            DB::update('shp_voyages', $upd, 'id = :id', ['id' => $id]);
            Ship::voyageEvent($id, 'status', $note !== '' ? $note : 'Status changed', $from, $to, $now);
            Ship::audit('status', 'ops', 'shp_voyage', $id, $v['voyage_no'] . ': ' . $from . ' to ' . $to, ['reason' => $note, 'page_key' => 'ops.voyages']);
            if ($to === 'boarding') {
                self::noticeAffected($v, 'boarding_open', 'Boarding is open for ' . $v['voyage_no'], 'Boarding is now open for voyage ' . $v['voyage_no'] . ' at ' . $v['origin_name'] . ($v['berth'] ? ', berth ' . $v['berth'] : '') . '. Please proceed to the gate with your e-ticket and ID.');
            }
        });
    }

    /** A delay or a change of time, told to every passenger booked */
    public static function reschedule(int $id, string $newEtd, string $cause): void
    {
        DB::tx(function () use ($id, $newEtd, $cause) {
            $v = Ship::voyage($id, true);
            if (!in_array($v['status'], ['draft', 'published', 'on_sale', 'boarding', 'ready'], true)) {
                fail('Only a voyage that has not departed can be rescheduled.');
            }
            if (!strtotime($newEtd) || trim($cause) === '') {
                fail('Enter the new departure time and the cause.');
            }
            $etd = date('Y-m-d H:i:00', strtotime($newEtd));
            $eta = date('Y-m-d H:i:00', strtotime($v['eta']) + (strtotime($etd) - strtotime($v['etd'])));
            $clash = DB::row("SELECT voyage_no FROM shp_voyages WHERE vessel_id = :vs AND id <> :id AND status <> 'cancelled' AND etd < :eta AND eta > :etd LIMIT 1", ['vs' => $v['vessel_id'], 'id' => $id, 'eta' => $eta, 'etd' => $etd]);
            if ($clash) {
                fail('The vessel is already scheduled on ' . $clash['voyage_no'] . ' at that time.');
            }
            DB::update('shp_voyages', ['etd' => $etd, 'eta' => $eta, 'voyage_date' => substr($etd, 0, 10), 'delay_cause' => mb_substr($cause, 0, 160),
                'booking_close' => date('Y-m-d H:i:s', strtotime($etd) - 60 * Ship::intSetting('booking_close_minutes', 30)), 'version' => (int)$v['version'] + 1], 'id = :id', ['id' => $id]);
            Ship::voyageEvent($id, 'delay', 'Departure moved from ' . fdatetime($v['etd']) . ' to ' . fdatetime($etd) . ': ' . $cause);
            Ship::audit('reschedule', 'ops', 'shp_voyage', $id, $v['voyage_no'], ['reason' => $cause, 'page_key' => 'ops.voyages']);
            self::noticeAffected($v, 'schedule_change', 'New departure time for ' . $v['voyage_no'], 'Voyage ' . $v['voyage_no'] . ' now departs ' . fdatetime($etd) . ' (was ' . fdatetime($v['etd']) . '): ' . $cause . '.', 'reschedule:' . $etd);
        });
    }

    private static function noticeAffected(array $v, string $type, string $subject, string $body, string $suffix = ''): void
    {
        $rows = DB::all("SELECT DISTINCT b.id, b.contact_email, b.contact_phone FROM shp_bookings b JOIN shp_tickets t ON t.booking_id = b.id
                          WHERE t.voyage_id = :v AND t.status IN ('issued', 'checked_in', 'held')", ['v' => $v['id']]);
        foreach ($rows as $b) {
            Notify::customer('voyage:' . $v['id'] . ':' . $type . $suffix . ':' . $b['id'], $type, 'passenger', $b['contact_email'], $b['contact_phone'], $subject, $body, 'shp_voyages', (int)$v['id']);
        }
        foreach (DB::all("SELECT DISTINCT shipper_customer_id, shipper_phone FROM shp_cargo WHERE voyage_id = :v AND status NOT IN ('cancelled', 'closed')", ['v' => $v['id']]) as $c) {
            $email = $c['shipper_customer_id'] ? DB::value('SELECT email FROM customers WHERE id = :id', ['id' => $c['shipper_customer_id']]) : null;
            Notify::customer('voyage:' . $v['id'] . ':' . $type . $suffix . ':cargo:' . ($c['shipper_customer_id'] ?: $c['shipper_phone']), $type, 'cargo_customer', $email ?: null, $c['shipper_phone'], $subject, $body, 'shp_voyages', (int)$v['id']);
        }
        Notify::staff('voyage:' . $v['id'] . ':' . $type . $suffix, $type, ['Operations Manager', 'Ticketing Manager', 'Customer Service'], $subject, $body, 'shp_voyages', (int)$v['id']);
    }

    /** Asks for a departure override of the checks that can be overridden (approved by someone else) */
    public static function requestOverride(int $id, string $evidence): int
    {
        if (trim($evidence) === '') {
            fail('Write the lawful basis and the evidence for the override.');
        }
        $v = Ship::voyage($id);
        [, $hard, $soft] = self::readinessVerdict($id);
        if ($hard) {
            fail('These checks cannot be overridden: ' . implode('; ', $hard) . '.');
        }
        if (!$soft) {
            fail('Every check passes; no override is needed.');
        }
        DB::update('shp_voyages', ['override_reason' => mb_substr($evidence, 0, 255)], 'id = :id', ['id' => $id]);
        Ship::voyageEvent($id, 'override', 'Departure override requested: ' . $evidence . ' (open: ' . implode('; ', $soft) . ')');
        return ShipApproval::request('departure_override', 'shp_voyages', $id, $v['voyage_no'], null, 'Departure override for ' . $v['voyage_no'] . ': ' . $evidence . '. Open checks: ' . implode('; ', $soft));
    }

    public static function overrideDecision(int $id, bool $approve, string $notes): void
    {
        if ($approve) {
            DB::update('shp_voyages', ['override_by' => Auth::id()], 'id = :id', ['id' => $id]);
        }
        Ship::voyageEvent($id, 'override', 'Departure override ' . ($approve ? 'approved' : 'rejected') . ' by ' . (Auth::user()['full_name'] ?? '') . ($notes ? ': ' . $notes : ''));
    }

    public static function signReport(int $id, string $text, array $figures = []): void
    {
        $v = Ship::voyage($id);
        if (!in_array($v['status'], ['arrived', 'completed'], true)) {
            fail('The voyage report is signed after arrival.');
        }
        if (trim($text) === '') {
            fail('Write the voyage report.');
        }
        $upd = ['report_text' => $text, 'report_signed_by' => Auth::id(), 'report_signed_at' => Ship::now()];
        foreach (['distance_nm', 'weather_note'] as $k) {
            if (isset($figures[$k]) && $figures[$k] !== '') {
                $upd[$k] = $k === 'distance_nm' ? (float)$figures[$k] : mb_substr((string)$figures[$k], 0, 160);
            }
        }
        DB::update('shp_voyages', $upd, 'id = :id', ['id' => $id]);
        Ship::voyageEvent($id, 'note', 'Voyage report signed by ' . (Auth::user()['full_name'] ?? ''));
        Ship::audit('voyage_report', 'ops', 'shp_voyage', $id, $v['voyage_no'], ['page_key' => 'ops.voyages']);
    }

    // ------------------------------------------------------------------ revenue recognition

    /**
     * One journal per voyage: Deferred Passenger / Freight Revenue to Fare Revenue (gross of the statutory
     * discount, which shows on its own line), Fuel Surcharge, Forfeited Fares (no-shows), Vehicle and Cargo
     * Freight. Only what was deferred and not yet recognized is moved; a second run finds the event key.
     */
    public static function recognizeRevenue(int $voyageId, string $date): ?int
    {
        if (Ship::setting('revenue_policy', 'deferred') === 'on_sale') {
            return null;
        }
        $v = Ship::voyage($voyageId);
        $class = ShipGL::vesselClass((int)$v['vessel_id']);
        $lines = [];
        $tickets = DB::all("SELECT t.*, c.vat_exempt, c.is_statutory FROM shp_tickets t JOIN shp_fare_categories c ON c.id = t.category_id
                             WHERE t.voyage_id = :v AND t.status IN ('boarded', 'no_show') AND t.revenue_done = 0", ['v' => $voyageId]);
        $deferPax = 0.0;
        $fare = 0.0;
        $disc = 0.0;
        $promo = 0.0;
        $sur = 0.0;
        $noshow = 0.0;
        $noshowPolicy = Ship::setting('noshow_policy', 'forfeit');
        foreach ($tickets as $t) {
            $net = (float)$t['fare_net'];
            $deferPax += $net + (float)$t['surcharge_net'];
            if ($t['status'] === 'no_show' && $noshowPolicy === 'forfeit') {
                $noshow += $net + (float)$t['surcharge_net'];
                continue;
            }
            // the regular fare net of VAT, and the privilege / promo taken from it
            $grossEx = ShipGL::splitVat((float)$t['fare_gross'])[0];
            $off = max(0.0, r2($grossEx - $net));
            // a privilege given by law shows as Statutory Fare Discounts; company discounts (child, infant, promo) as Sales Discounts
            if ((int)$t['is_statutory'] === 1) {
                $disc += $off;
            } else {
                $promo += $off;
            }
            $fare += $net + $off;
            $sur += (float)$t['surcharge_net'];
        }
        $veh = DB::all("SELECT * FROM shp_vehicles WHERE voyage_id = :v AND status IN ('loaded', 'unloaded', 'no_show') AND revenue_done = 0", ['v' => $voyageId]);
        $deferFr = 0.0;
        $vehRev = 0.0;
        foreach ($veh as $x) {
            $deferFr += (float)$x['fare_net'];
            $vehRev += (float)$x['fare_net'];
        }
        $cargo = DB::all("SELECT * FROM shp_cargo WHERE voyage_id = :v AND payment_terms = 'prepaid' AND paid_txn_id IS NOT NULL AND revenue_done = 0
                           AND status NOT IN ('cancelled', 'quotation', 'booked')", ['v' => $voyageId]);
        $cargoRev = 0.0;
        $handRev = 0.0;
        foreach ($cargo as $c) {
            [$fNet] = ShipGL::splitVat((float)$c['freight_gross'] - (float)$c['discount_amount']);
            [$hNet] = ShipGL::splitVat((float)$c['handling_fee']);
            $deferFr += $fNet + $hNet;
            $cargoRev += $fNet;
            $handRev += $hNet;
        }
        $memo = 'Revenue of voyage ' . $v['voyage_no'] . ' ' . $v['origin_code'] . '-' . $v['dest_code'];
        if ($deferPax > 0) {
            $lines[] = ShipGL::jl(Accts::get('shp_deferred_pax'), r2($deferPax), 'Deferred fares earned, ' . $v['voyage_no'], $class);
        }
        if ($fare > 0) {
            $lines[] = ShipGL::jl(Accts::get('shp_fare_revenue'), -r2($fare), 'Passenger fares, ' . $v['voyage_no'], $class);
        }
        if ($disc > 0) {
            $lines[] = ShipGL::jl(Accts::get('shp_statutory_disc'), r2($disc), 'Statutory fare discounts, ' . $v['voyage_no'], $class);
        }
        if ($promo > 0) {
            $lines[] = ShipGL::jl(Accts::get('sales_discounts'), r2($promo), 'Promo discounts, ' . $v['voyage_no'], $class);
        }
        if ($sur > 0) {
            $lines[] = ShipGL::jl(Accts::get('shp_surcharge_revenue'), -r2($sur), 'Fuel surcharge, ' . $v['voyage_no'], $class);
        }
        if ($noshow > 0) {
            $lines[] = ShipGL::jl(Accts::get('shp_noshow_revenue'), -r2($noshow), 'No-show fares forfeited, ' . $v['voyage_no'], $class);
        }
        if ($deferFr > 0) {
            $lines[] = ShipGL::jl(Accts::get('shp_deferred_freight'), r2($deferFr), 'Deferred freight earned, ' . $v['voyage_no'], $class);
        }
        if ($vehRev > 0) {
            $lines[] = ShipGL::jl(Accts::get('shp_vehicle_revenue'), -r2($vehRev), 'Vehicle / RoRo freight, ' . $v['voyage_no'], $class);
        }
        if ($cargoRev > 0) {
            $lines[] = ShipGL::jl(Accts::get('shp_cargo_revenue'), -r2($cargoRev), 'Cargo freight, ' . $v['voyage_no'], $class);
        }
        if ($handRev > 0) {
            $lines[] = ShipGL::jl(Accts::get('shp_handling_revenue'), -r2($handRev), 'Cargo handling, ' . $v['voyage_no'], $class);
        }
        if (!$lines) {
            return null;
        }
        // rounding of the gross / discount split: the last cent goes to the fare line
        $sum = r2(array_sum(array_column($lines, 'amount')));
        if (abs($sum) >= 0.005) {
            foreach ($lines as &$l) {
                if ($l['account_id'] === Accts::get('shp_fare_revenue')) {
                    $l['amount'] = r2($l['amount'] - $sum);
                    $sum = 0.0;
                    break;
                }
            }
            unset($l);
            if (abs($sum) >= 0.005) {
                fail('The revenue journal of ' . $v['voyage_no'] . ' does not balance by ' . money($sum) . '.');
            }
        }
        $txn = ShipGL::once('voyage:' . $voyageId . ':revenue', ['source_type' => 'voyage', 'source_id' => $voyageId, 'voyage_id' => $voyageId, 'vessel_id' => (int)$v['vessel_id'], 'memo' => $memo],
            fn() => ShipGL::journal($date, $lines, $memo, $class, $v['voyage_no']));
        if ($tickets) {
            DB::run('UPDATE shp_tickets SET revenue_done = 1 WHERE id IN (' . implode(',', array_map('intval', array_column($tickets, 'id'))) . ')');
        }
        if ($veh) {
            DB::run('UPDATE shp_vehicles SET revenue_done = 1 WHERE id IN (' . implode(',', array_map('intval', array_column($veh, 'id'))) . ')');
        }
        if ($cargo) {
            DB::run('UPDATE shp_cargo SET revenue_done = 1 WHERE id IN (' . implode(',', array_map('intval', array_column($cargo, 'id'))) . ')');
        }
        DB::update('shp_voyages', ['revenue_txn_id' => $txn], 'id = :id', ['id' => $voyageId]);
        return $txn;
    }

    // ------------------------------------------------------------------ voyage P&L

    /** Revenue and costs of a voyage from the general ledger lines of the documents linked to it */
    public static function pnl(int $voyageId): array
    {
        $rows = DB::all("SELECT a.id, a.code, a.name, a.category, a.account_type, SUM(j.credit - j.debit) AS net
                           FROM shp_postings p JOIN journal_lines j ON j.txn_id = p.txn_id JOIN accounts a ON a.id = j.account_id
                           JOIN transactions t ON t.id = p.txn_id AND t.is_voided = 0
                          WHERE p.voyage_id = :v AND a.category IN ('Income', 'Expense')
                          GROUP BY a.id, a.code, a.name, a.category, a.account_type ORDER BY a.code", ['v' => $voyageId]);
        $income = array_values(array_filter($rows, fn($r) => $r['category'] === 'Income'));
        $expense = array_values(array_filter($rows, fn($r) => $r['category'] === 'Expense'));
        $rev = r2(array_sum(array_map(fn($r) => (float)$r['net'], $income)));
        $direct = r2(-array_sum(array_map(fn($r) => (float)$r['net'], $expense)));
        $deferred = (float)DB::value("SELECT COALESCE(SUM(fare_net + surcharge_net), 0) FROM shp_tickets WHERE voyage_id = :v AND revenue_done = 0 AND status IN ('issued', 'checked_in', 'boarded', 'no_show')", ['v' => $voyageId]);
        return ['income' => $income, 'expense' => $expense, 'revenue' => $rev, 'direct_cost' => $direct, 'contribution' => r2($rev - $direct), 'still_deferred' => r2($deferred)];
    }
}
