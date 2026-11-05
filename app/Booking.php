<?php
declare(strict_types=1);

/**
 * Passenger and vehicle bookings on one shared, real-time capacity inventory (counter, portal, agents).
 *
 *  hold()   seats and lane meters are held for a limited time. The voyage row is locked (SELECT ... FOR UPDATE)
 *           for the whole check-and-insert, so parallel sales of the last seats are served one after the other:
 *           confirmed tickets can never exceed the capacity of a class or the passenger quota, and a group that
 *           does not fit fails as a whole (no partial hold). One seat on one voyage is also protected by the
 *           primary key of shp_seat_allocations.
 *  pay()    verified payment issues the tickets (numbered, QR-signed) and records the accounting document:
 *           a sales receipt (cash, card, e-wallet, bank) or an invoice (corporate / agent account), with the fare
 *           to Deferred Passenger Revenue, VAT to Output Tax and third-party fees to their payable accounts.
 *           The idempotency key makes a replayed payment (double click, webhook sent twice) return the first one.
 *  refunds  are requested, approved by another person (Approvals Queue), posted as a credit memo (plus the
 *           cancellation fee earned) and paid out as a customer refund; a refund never exceeds what is refundable.
 *  transfer moves a ticket to another voyage (rebooking), with the change fee and any fare difference.
 */
final class Booking
{
    // ------------------------------------------------------------------ capacity

    /** Live passengers on a voyage by accommodation class: code => count */
    public static function liveByClass(int $voyageId): array
    {
        [$in, $p] = DB::in('ls', Ship::LIVE_TICKET);
        $p['v'] = $voyageId;
        return DB::pairs("SELECT accom_code, COUNT(*) FROM shp_tickets WHERE voyage_id = :v AND status IN $in GROUP BY accom_code", $p);
    }

    /** Capacity of each accommodation class of the voyage's vessel, with what is taken and what is left */
    public static function capacity(array $voyage): array
    {
        $live = self::liveByClass((int)$voyage['id']);
        $out = [];
        foreach (DB::all('SELECT * FROM shp_accom_classes WHERE vessel_id = :v ORDER BY sort_order, id', ['v' => $voyage['vessel_id']]) as $c) {
            $taken = (int)($live[$c['code']] ?? 0);
            $out[$c['code']] = ['code' => $c['code'], 'name' => $c['name'], 'kind' => $c['kind'], 'capacity' => (int)$c['capacity'], 'taken' => $taken,
                'left' => max(0, (int)$c['capacity'] - $taken), 'id' => (int)$c['id'], 'reserved' => (int)$c['reserved_seating']];
        }
        return $out;
    }

    public static function paxLimit(array $voyage): int
    {
        $cap = (int)$voyage['pax_capacity'];
        return $voyage['pax_quota'] !== null && (int)$voyage['pax_quota'] > 0 ? min($cap, (int)$voyage['pax_quota']) : $cap;
    }

    public static function lanesUsed(int $voyageId): float
    {
        return (float)DB::value("SELECT COALESCE(SUM(c.lane_m), 0) FROM shp_vehicles v JOIN shp_vehicle_classes c ON c.id = v.vehicle_class_id
                                  WHERE v.voyage_id = :v AND v.status IN ('held', 'issued', 'checked_in', 'loaded')", ['v' => $voyageId]);
    }

    public static function laneLimit(array $voyage): float
    {
        $cap = (float)$voyage['lane_meters'];
        return $voyage['vehicle_quota_lm'] !== null && (float)$voyage['vehicle_quota_lm'] > 0 ? min($cap, (float)$voyage['vehicle_quota_lm']) : $cap;
    }

    /** Releases the seats of holds that ran out of time (on this voyage, or everywhere) */
    public static function expireHolds(?int $voyageId = null): int
    {
        $sql = "SELECT DISTINCT b.id FROM shp_bookings b JOIN shp_tickets t ON t.booking_id = b.id
                 WHERE b.status = 'held' AND b.hold_expires_at IS NOT NULL AND b.hold_expires_at < :n";
        $p = ['n' => Ship::now()];
        if ($voyageId) {
            $sql .= ' AND t.voyage_id = :v';
            $p['v'] = $voyageId;
        }
        $ids = DB::column($sql, $p);
        // bookings with only vehicles
        $sql2 = "SELECT DISTINCT b.id FROM shp_bookings b JOIN shp_vehicles x ON x.booking_id = b.id
                  WHERE b.status = 'held' AND b.hold_expires_at IS NOT NULL AND b.hold_expires_at < :n" . ($voyageId ? ' AND x.voyage_id = :v' : '');
        $ids = array_unique(array_merge($ids, DB::column($sql2, $p)));
        foreach ($ids as $bid) {
            self::release((int)$bid, 'expired', 'Hold time ran out before payment.');
        }
        return count($ids);
    }

    /** Ends an unpaid booking: its places go back on sale */
    public static function release(int $bookingId, string $status, string $why): void
    {
        DB::tx(function () use ($bookingId, $status, $why) {
            $b = DB::row('SELECT * FROM shp_bookings WHERE id = :id FOR UPDATE', ['id' => $bookingId]);
            if (!$b || $b['status'] !== 'held') {
                return;
            }
            $tickets = DB::column("SELECT id FROM shp_tickets WHERE booking_id = :b AND status = 'held'", ['b' => $bookingId]);
            if ($tickets) {
                [$in, $p] = DB::in('tk', $tickets);
                DB::run("DELETE FROM shp_seat_allocations WHERE ticket_id IN $in", $p);
                DB::run("UPDATE shp_tickets SET status = :s, updated_at = :n WHERE id IN $in", $p + ['s' => $status, 'n' => Ship::now()]);
                DB::run("UPDATE shp_ticket_fees SET status = 'waived' WHERE ticket_id IN $in", $p);
            }
            DB::run("UPDATE shp_vehicles SET status = :s WHERE booking_id = :b AND status = 'held'", ['s' => $status === 'expired' ? 'cancelled' : $status, 'b' => $bookingId]);
            DB::update('shp_bookings', ['status' => $status, 'notes' => trim(($b['notes'] ? $b['notes'] . ' ' : '') . $why)], 'id = :id', ['id' => $bookingId]);
        });
    }

    // ------------------------------------------------------------------ hold

    /**
     * @param array $h    voyage_id, channel, office_id, customer_id, agent_customer_id, portal_user_id, contact_name, contact_email, contact_phone, promo_code, notes
     * @param array $pax  rows: last_name, first_name, middle_name, birth_date, gender, nationality, contact_no, id_type, id_no,
     *                    emergency_name, emergency_phone, special_assist, category_id, accom_code, seat_id, is_driver
     * @param array $veh  rows: vehicle_class_id, plate_no, make_model, length_m, weight_t, axles, operator_name, driver_index, declared_cargo, hazardous, hazardous_desc, restrictions_ack
     * @param array $opt  hold_minutes, override_gross (pax index => fare), override_reason
     */
    public static function hold(array $h, array $pax, array $veh = [], array $opt = []): int
    {
        $voyageId = (int)($h['voyage_id'] ?? 0);
        if (!$pax && !$veh) {
            fail('Add at least one passenger or vehicle.');
        }
        $maxPax = Ship::intSetting('max_pax_per_booking', 20);
        if (count($pax) > $maxPax) {
            fail('One booking can have at most ' . $maxPax . ' passengers. Split the group into separate bookings.');
        }
        if (trim((string)($h['contact_name'] ?? '')) === '') {
            fail('Enter the name of the person making the booking.');
        }
        return DB::tx(function () use ($h, $pax, $veh, $opt, $voyageId) {
            // the voyage row is locked until this booking is saved: every sale of this voyage waits its turn here
            $v = Ship::voyage($voyageId, true);
            if (!$v) {
                fail('Choose a voyage.');
            }
            if (!in_array($v['status'], ['on_sale', 'boarding'], true)) {
                fail('Voyage ' . $v['voyage_no'] . ' is ' . strtolower(strip_tags(Ship::label($v['status']))) . ' and is not on sale.');
            }
            $close = $v['booking_close'] ?: date('Y-m-d H:i:s', strtotime($v['etd']) - 60 * Ship::intSetting('booking_close_minutes', 30));
            if (Ship::now() > $close) {
                fail('Sales for voyage ' . $v['voyage_no'] . ' closed at ' . fdatetime($close) . '.');
            }
            self::expireHolds($voyageId);

            // ---------------- capacity: the whole group fits, or nothing is held
            $cap = self::capacity($v);
            $need = [];
            foreach ($pax as $i => $p) {
                $code = (string)($p['accom_code'] ?? '');
                if (!isset($cap[$code])) {
                    fail('Passenger ' . ($i + 1) . ': choose an accommodation class this vessel has.');
                }
                $need[$code] = ($need[$code] ?? 0) + 1;
            }
            foreach ($need as $code => $n) {
                if ($n > $cap[$code]['left']) {
                    fail('Only ' . $cap[$code]['left'] . ' place(s) left in ' . $cap[$code]['name'] . ' and this booking needs ' . $n . '. Nothing was held; choose another class or voyage, or split the group.');
                }
            }
            $liveTotal = array_sum(array_column($cap, 'taken'));
            $limit = self::paxLimit($v);
            if ($liveTotal + count($pax) > $limit) {
                fail('The voyage can take ' . max(0, $limit - $liveTotal) . ' more passenger(s) (limit ' . $limit . ') and this booking has ' . count($pax) . '. Nothing was held.');
            }
            if ($veh) {
                $used = self::lanesUsed($voyageId);
                $add = 0.0;
                foreach ($veh as $i => $x) {
                    $vc = DB::row('SELECT * FROM shp_vehicle_classes WHERE id = :id AND is_active = 1', ['id' => (int)($x['vehicle_class_id'] ?? 0)]);
                    if (!$vc) {
                        fail('Vehicle ' . ($i + 1) . ': choose the vehicle class.');
                    }
                    if (trim((string)($x['plate_no'] ?? '')) === '') {
                        fail('Vehicle ' . ($i + 1) . ': enter the plate number.');
                    }
                    $len = (float)($x['length_m'] ?? 0);
                    $wt = (float)($x['weight_t'] ?? 0);
                    if ((float)$vc['max_length_m'] > 0 && $len > (float)$vc['max_length_m']) {
                        fail('Vehicle ' . strtoupper((string)$x['plate_no']) . ' is ' . qty($len) . ' m long; class ' . $vc['name'] . ' takes up to ' . qty($vc['max_length_m']) . ' m. Choose the right class.');
                    }
                    if ((float)$v['max_vehicle_length_m'] > 0 && $len > (float)$v['max_vehicle_length_m']) {
                        fail('Vehicle ' . strtoupper((string)$x['plate_no']) . ' (' . qty($len) . ' m) is longer than ' . $v['vessel_name'] . ' can load (' . qty($v['max_vehicle_length_m']) . ' m).');
                    }
                    if ((float)$v['max_vehicle_weight_t'] > 0 && $wt > (float)$v['max_vehicle_weight_t']) {
                        fail('Vehicle ' . strtoupper((string)$x['plate_no']) . ' (' . qty($wt) . ' t) is heavier than the vehicle deck limit of ' . $v['vessel_name'] . ' (' . qty($v['max_vehicle_weight_t']) . ' t).');
                    }
                    if (!empty($x['hazardous']) && trim((string)($x['hazardous_desc'] ?? '')) === '') {
                        fail('Vehicle ' . strtoupper((string)$x['plate_no']) . ': describe the hazardous goods declared.');
                    }
                    if (empty($x['restrictions_ack'])) {
                        fail('Vehicle ' . strtoupper((string)$x['plate_no']) . ': the driver has to acknowledge the prohibited and restricted carriage notice.');
                    }
                    $add += (float)$vc['lane_m'];
                }
                $laneLimit = self::laneLimit($v);
                if ($used + $add > $laneLimit + 0.001) {
                    fail('The vehicle deck has ' . qty(max(0, $laneLimit - $used)) . ' lane meters left and these vehicles need ' . qty($add) . '. Nothing was held.');
                }
            }

            // ---------------- the booking
            $promo = Fares::promo($h['promo_code'] ?? null, (int)$v['route_id'], substr($v['etd'], 0, 10));
            if ($promo && count($pax) > (int)$promo['max_per_booking']) {
                fail('Promo ' . $promo['code'] . ' allows at most ' . $promo['max_per_booking'] . ' passengers per booking.');
            }
            $minutes = (int)($opt['hold_minutes'] ?? Ship::intSetting(($h['channel'] ?? 'counter') === 'portal' ? 'portal_hold_minutes' : 'counter_hold_minutes', 20));
            $bid = DB::insert('shp_bookings', [
                'booking_ref' => self::newRef(), 'channel' => $h['channel'] ?? 'counter', 'office_id' => $h['office_id'] ?? null,
                'customer_id' => $h['customer_id'] ?? ShipGL::walkInCustomer(), 'agent_customer_id' => $h['agent_customer_id'] ?? null,
                'portal_user_id' => $h['portal_user_id'] ?? null, 'contact_name' => mb_substr(trim((string)$h['contact_name']), 0, 120),
                'contact_email' => $h['contact_email'] ?? null, 'contact_phone' => $h['contact_phone'] ?? null, 'status' => 'held',
                'hold_expires_at' => date('Y-m-d H:i:s', strtotime(Ship::now()) + 60 * max(1, $minutes)), 'promo_id' => $promo['id'] ?? null,
                'notes' => $h['notes'] ?? null, 'created_by' => Auth::id(),
            ]);
            $total = 0.0;
            $seq = 0;
            $ticketIds = [];
            $priorityCats = DB::column('SELECT id FROM shp_fare_categories WHERE vat_exempt = 1');
            foreach ($pax as $i => $p) {
                $last = trim((string)($p['last_name'] ?? ''));
                $first = trim((string)($p['first_name'] ?? ''));
                if ($last === '' || $first === '') {
                    fail('Passenger ' . ($i + 1) . ': enter the last and first name (needed on the manifest).');
                }
                $cat = Fares::category((int)($p['category_id'] ?? 0));
                $age = Ship::age($p['birth_date'] ?? null, substr($v['etd'], 0, 10));
                if (($cat['min_age'] !== null || $cat['max_age'] !== null) && $age === null) {
                    fail('Passenger ' . ($i + 1) . ' (' . $cat['name'] . '): enter the date of birth; the category depends on age.');
                }
                if ($cat['min_age'] !== null && $age < (int)$cat['min_age']) {
                    fail('Passenger ' . ($i + 1) . ' is ' . $age . ' and the ' . $cat['name'] . ' category starts at ' . $cat['min_age'] . '.');
                }
                if ($cat['max_age'] !== null && $age > (int)$cat['max_age']) {
                    fail('Passenger ' . ($i + 1) . ' is ' . $age . ' and the ' . $cat['name'] . ' category ends at ' . $cat['max_age'] . '.');
                }
                $idNo = preg_replace('/\s+/', '', (string)($p['id_no'] ?? ''));
                if ((int)$cat['requires_id'] === 1 && $idNo === '') {
                    fail('Passenger ' . ($i + 1) . ' (' . $cat['name'] . '): enter the ' . ($cat['id_label'] ?: 'ID') . ' number; the discount needs it.');
                }
                $override = $opt['override_gross'][$i] ?? null;
                if ($override !== null && $override !== '') {
                    if (!Access::special('shp_fare_override')) {
                        fail('Your role cannot change a fare.');
                    }
                    if (trim((string)($opt['override_reason'] ?? '')) === '') {
                        fail('Give the reason for changing the fare.');
                    }
                }
                $q = Fares::quotePax($v, (string)$p['accom_code'], $cat, $promo, $override !== null && $override !== '' ? (float)$override : null);
                $tid = DB::insert('shp_tickets', [
                    'booking_id' => $bid, 'pax_seq' => ++$seq, 'voyage_id' => $voyageId,
                    'last_name' => mb_substr($last, 0, 60), 'first_name' => mb_substr($first, 0, 60), 'middle_name' => ($p['middle_name'] ?? '') !== '' ? mb_substr((string)$p['middle_name'], 0, 60) : null,
                    'birth_date' => !empty($p['birth_date']) && valid_date($p['birth_date']) ? $p['birth_date'] : null,
                    'gender' => in_array($p['gender'] ?? '', ['M', 'F'], true) ? $p['gender'] : null, 'nationality' => ($p['nationality'] ?? '') ?: 'Filipino',
                    'contact_no' => $p['contact_no'] ?? null, 'id_type' => ($p['id_type'] ?? '') ?: ($idNo !== '' ? $cat['id_label'] : null),
                    'id_last4' => $idNo !== '' ? substr($idNo, -4) : null, 'emergency_name' => $p['emergency_name'] ?? null, 'emergency_phone' => $p['emergency_phone'] ?? null,
                    'special_assist' => $p['special_assist'] ?? null, 'category_id' => (int)$cat['id'],
                    'discount_verified_by' => (int)$cat['requires_id'] === 1 ? Auth::id() : null,
                    'accom_code' => $p['accom_code'], 'is_driver' => !empty($p['is_driver']) ? 1 : 0, 'fare_id' => $q['fare_id'],
                    'fare_gross' => $q['fare_gross'], 'discount_amount' => $q['discount'], 'promo_amount' => $q['promo'], 'fare_net' => $q['fare_net'],
                    'vat_amount' => $q['vat'], 'surcharge_net' => $q['surcharge_net'], 'fees_total' => $q['fees_total'], 'total' => $q['total'], 'status' => 'held',
                ]);
                foreach ($q['fees'] as $f) {
                    DB::insert('shp_ticket_fees', ['ticket_id' => $tid, 'fee_id' => $f['fee_id'], 'amount' => $f['amount'], 'ownership' => $f['ownership'], 'account_id' => $f['account_id'], 'status' => 'held']);
                }
                // seat: the one chosen, else the next free one (priority seats go to seniors and PWD first)
                $class = $cap[$p['accom_code']];
                if ($class['reserved'] && !self::isInfantLap($cat)) {
                    $seatId = !empty($p['seat_id']) ? (int)$p['seat_id'] : self::freeSeat($voyageId, (int)$v['vessel_id'], $class['id'], in_array($cat['id'], $priorityCats));
                    if ($seatId) {
                        self::allocate($voyageId, (int)$v['vessel_id'], $class['id'], $seatId, $tid);
                    }
                }
                $total += $q['total'];
                $ticketIds[$i] = $tid;
                if ($override !== null && $override !== '') {
                    Ship::audit('fare_override', 'tkt', 'shp_ticket', $tid, 'Fare changed to ' . money($override), ['reason' => (string)$opt['override_reason'], 'amount' => $override]);
                }
            }
            foreach ($veh as $i => $x) {
                $q = Fares::quoteVehicle($v, (int)$x['vehicle_class_id']);
                $driver = isset($x['driver_index']) && $x['driver_index'] !== '' && isset($ticketIds[(int)$x['driver_index']]) ? $ticketIds[(int)$x['driver_index']] : null;
                if ($driver) {
                    DB::update('shp_tickets', ['is_driver' => 1], 'id = :id', ['id' => $driver]);
                }
                $vid = DB::insert('shp_vehicles', [
                    'booking_id' => $bid, 'voyage_id' => $voyageId, 'vehicle_class_id' => (int)$x['vehicle_class_id'],
                    'plate_no' => strtoupper(mb_substr(trim((string)$x['plate_no']), 0, 20)), 'make_model' => $x['make_model'] ?? null,
                    'length_m' => (float)($x['length_m'] ?? 0), 'weight_t' => (float)($x['weight_t'] ?? 0), 'axles' => (int)($x['axles'] ?? 2),
                    'operator_name' => $x['operator_name'] ?? null, 'driver_ticket_id' => $driver, 'declared_cargo' => $x['declared_cargo'] ?? null,
                    'hazardous' => !empty($x['hazardous']) ? 1 : 0, 'hazardous_desc' => $x['hazardous_desc'] ?? null, 'restrictions_ack' => 1,
                    'rate_id' => $q['rate_id'], 'fare_gross' => $q['fare_gross'], 'fare_net' => $q['fare_net'], 'vat_amount' => $q['vat'],
                    'fees_total' => $q['fees_total'], 'total' => $q['total'], 'status' => 'held',
                ]);
                foreach ($q['fees'] as $f) {
                    DB::insert('shp_ticket_fees', ['vehicle_id' => $vid, 'fee_id' => $f['fee_id'], 'amount' => $f['amount'], 'ownership' => $f['ownership'], 'account_id' => $f['account_id'], 'status' => 'held']);
                }
                $total += $q['total'];
            }
            DB::update('shp_bookings', ['total_amount' => r2($total)], 'id = :id', ['id' => $bid]);
            $ref = (string)DB::value('SELECT booking_ref FROM shp_bookings WHERE id = :id', ['id' => $bid]);
            Ship::audit('create', 'tkt', 'shp_booking', $bid, 'Booking ' . $ref . ' on ' . $v['voyage_no'], ['amount' => $total, 'page_key' => 'tkt.booking']);
            return $bid;
        });
    }

    private static function isInfantLap(array $cat): bool
    {
        return strtoupper((string)$cat['code']) === 'INF';
    }

    public static function newRef(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $ref = '';
            for ($i = 0; $i < 6; $i++) {
                $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (DB::value('SELECT 1 FROM shp_bookings WHERE booking_ref = :r', ['r' => $ref]));
        return $ref;
    }

    public static function freeSeat(int $voyageId, int $vesselId, int $classId, bool $priority): ?int
    {
        $base = 'SELECT s.id FROM shp_seats s LEFT JOIN shp_seat_allocations a ON a.seat_id = s.id AND a.voyage_id = :v
                  WHERE s.vessel_id = :vs AND s.accom_class_id = :c AND s.is_active = 1 AND a.seat_id IS NULL';
        $p = ['v' => $voyageId, 'vs' => $vesselId, 'c' => $classId];
        // priority seats are kept for seniors / PWD while other seats remain
        $id = DB::value($base . ($priority ? ' ORDER BY s.is_priority DESC, s.seat_row, s.seat_col' : ' AND s.is_priority = 0 ORDER BY s.seat_row, s.seat_col') . ' LIMIT 1', $p);
        if (!$id && !$priority) {
            $id = DB::value($base . ' ORDER BY s.seat_row, s.seat_col LIMIT 1', $p);
        }
        return $id ? (int)$id : null;
    }

    public static function allocate(int $voyageId, int $vesselId, int $classId, int $seatId, int $ticketId): void
    {
        $seat = DB::row('SELECT * FROM shp_seats WHERE id = :id AND vessel_id = :v AND is_active = 1', ['id' => $seatId, 'v' => $vesselId]);
        if (!$seat) {
            fail('That seat is not on this vessel.');
        }
        if ((int)$seat['accom_class_id'] !== $classId) {
            fail('Seat ' . $seat['seat_no'] . ' is not in the accommodation class chosen.');
        }
        try {
            DB::insert('shp_seat_allocations', ['voyage_id' => $voyageId, 'seat_id' => $seatId, 'ticket_id' => $ticketId]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                fail('Seat ' . $seat['seat_no'] . ' was just taken by another booking. Choose another seat.');
            }
            throw $e;
        }
        DB::update('shp_tickets', ['seat_id' => $seatId], 'id = :id', ['id' => $ticketId]);
    }

    // ------------------------------------------------------------------ payment and issuance

    /**
     * @param array $pay channel (cash, card, ewallet, maya, bank_transfer, gateway, corporate, agent), amount, provider_ref,
     *                   idempotency_key, shift_id, office_id, received_at, date
     */
    public static function pay(int $bookingId, array $pay): int
    {
        $channel = (string)($pay['channel'] ?? 'cash');
        if (!in_array($channel, ['cash', 'check', 'card', 'ewallet', 'maya', 'bank_transfer', 'gateway', 'corporate', 'agent'], true)) {
            fail('Choose how the booking is paid.');
        }
        $idem = isset($pay['idempotency_key']) && $pay['idempotency_key'] !== '' ? substr((string)$pay['idempotency_key'], 0, 64) : null;
        if ($idem && ($done = DB::value('SELECT id FROM shp_payments WHERE idempotency_key = :k', ['k' => $idem]))) {
            return (int)$done;   // the same payment event again: nothing new is recorded
        }
        return DB::tx(function () use ($bookingId, $pay, $channel, $idem) {
            // the voyage is locked before the booking (the same order as hold()), so the two never wait on each other
            $vid = DB::value('SELECT voyage_id FROM shp_tickets WHERE booking_id = :b UNION SELECT voyage_id FROM shp_vehicles WHERE booking_id = :b2 LIMIT 1', ['b' => $bookingId, 'b2' => $bookingId]);
            if ($vid) {
                DB::value('SELECT id FROM shp_voyages WHERE id = :v FOR UPDATE', ['v' => $vid]);
            }
            $b = DB::row('SELECT * FROM shp_bookings WHERE id = :id FOR UPDATE', ['id' => $bookingId]);
            if (!$b) {
                fail('Booking not found.');
            }
            if ($idem && ($done = DB::value('SELECT id FROM shp_payments WHERE idempotency_key = :k', ['k' => $idem]))) {
                return (int)$done;
            }
            if ($b['status'] === 'confirmed') {
                fail('Booking ' . $b['booking_ref'] . ' is already paid.');
            }
            if ($b['status'] !== 'held') {
                fail('Booking ' . $b['booking_ref'] . ' is ' . $b['status'] . ' and cannot be paid. Make a new booking.');
            }
            if ($b['hold_expires_at'] && $b['hold_expires_at'] < Ship::now()) {
                self::release($bookingId, 'expired', 'Hold time ran out before payment.');
                fail('The hold on booking ' . $b['booking_ref'] . ' expired and its places were released. Make the booking again.');
            }
            $amount = r2($pay['amount'] ?? $b['total_amount']);
            if (abs($amount - (float)$b['total_amount']) >= 0.005) {
                fail('The payment (' . money($amount) . ') has to equal the booking total (' . money($b['total_amount']) . '); tickets are issued only when fully paid.');
            }
            if (in_array($channel, ['check', 'card', 'ewallet', 'maya', 'bank_transfer', 'gateway'], true) && trim((string)($pay['provider_ref'] ?? '')) === '') {
                fail('Enter the approval / reference number of the ' . str_replace('_', ' ', $channel) . ' payment; tickets are issued only after the payment is verified.');
            }
            if ($channel === 'gateway' && Ship::setting('gateway_provider') === '') {
                fail('No online payment gateway is connected, so a gateway payment cannot be verified here.');
            }
            $providerRef = trim((string)($pay['provider_ref'] ?? '')) ?: null;
            if ($providerRef && DB::value('SELECT 1 FROM shp_payments WHERE channel = :c AND provider_ref = :r', ['c' => $channel, 'r' => $providerRef])) {
                fail('Payment reference ' . $providerRef . ' was already used for another payment. A duplicate transfer is not accepted.');
            }
            $tickets = DB::all("SELECT t.*, c.code AS cat_code, c.name AS cat_name, c.is_statutory FROM shp_tickets t JOIN shp_fare_categories c ON c.id = t.category_id
                                 WHERE t.booking_id = :b AND t.status = 'held' ORDER BY t.pax_seq", ['b' => $bookingId]);
            $vehicles = DB::all("SELECT x.*, c.name AS class_name FROM shp_vehicles x JOIN shp_vehicle_classes c ON c.id = x.vehicle_class_id WHERE x.booking_id = :b AND x.status = 'held'", ['b' => $bookingId]);
            if (!$tickets && !$vehicles) {
                fail('There is nothing left to pay on this booking.');
            }
            $voyageId = (int)($tickets[0]['voyage_id'] ?? $vehicles[0]['voyage_id']);
            $v = Ship::voyage($voyageId, true);
            if (!in_array($v['status'], ['on_sale', 'boarding', 'published'], true)) {
                fail('Voyage ' . $v['voyage_no'] . ' is no longer on sale; the booking cannot be paid.');
            }
            $date = $pay['date'] ?? Ship::today();
            $now = $pay['received_at'] ?? Ship::now();
            $class = ShipGL::vesselClass((int)$v['vessel_id']);
            $shiftId = isset($pay['shift_id']) ? (int)$pay['shift_id'] : null;
            if ($channel === 'cash' && ($b['channel'] === 'counter' || $b['channel'] === 'portal') && !$shiftId && PHP_SAPI !== 'cli') {
                $shiftId = Cashier::requireOpen((int)Auth::id());
            }
            Cashier::assertOpen($shiftId);

            // ---------------- tickets are numbered and signed
            $issued = [];
            foreach ($tickets as $t) {
                $no = Ship::no('shp_ticket', 'ET-', 8);
                DB::update('shp_tickets', ['ticket_no' => $no, 'qr_sig' => Ship::sign('pax', $no, $voyageId), 'status' => 'issued', 'issued_at' => $now,
                    'revenue_done' => Ship::setting('revenue_policy', 'deferred') === 'on_sale' ? 1 : 0], 'id = :id', ['id' => $t['id']]);
                $t['ticket_no'] = $no;
                $issued[] = $t;
            }
            foreach ($vehicles as &$x) {
                $no = Ship::no('shp_vehicle', 'VP-', 7);
                DB::update('shp_vehicles', ['vehicle_no' => $no, 'qr_sig' => Ship::sign('vehicle', $no, $voyageId), 'status' => 'issued',
                    'revenue_done' => Ship::setting('revenue_policy', 'deferred') === 'on_sale' ? 1 : 0], 'id = :id', ['id' => $x['id']]);
                $x['vehicle_no'] = $no;
            }
            unset($x);

            // ---------------- the accounting document
            $lines = self::saleLines($issued, $vehicles, $v, $class);
            $customerId = (int)($b['customer_id'] ?: ShipGL::walkInCustomer());
            $memo = 'Booking ' . $b['booking_ref'] . ', voyage ' . $v['voyage_no'] . ' ' . $v['origin_code'] . '-' . $v['dest_code'];
            $txnId = ShipGL::once('booking:' . $bookingId . ':pay', ['source_type' => 'booking', 'source_id' => $bookingId, 'voyage_id' => $voyageId, 'vessel_id' => (int)$v['vessel_id'], 'memo' => $memo],
                function () use ($channel, $customerId, $b, $date, $lines, $memo, $class, $pay) {
                    if ($channel === 'corporate' || $channel === 'agent') {
                        $bill = $channel === 'agent' && $b['agent_customer_id'] ? (int)$b['agent_customer_id'] : $customerId;
                        if ($bill === ShipGL::walkInCustomer()) {
                            fail('Charging to an account needs the corporate or agent customer on the booking.');
                        }
                        return ShipGL::invoice($bill, $date, $lines, $memo, $class, $b['booking_ref']);
                    }
                    [$acct, $pmId] = ShipGL::channelAccounts($channel, $b['office_id'] ? (int)$b['office_id'] : null);
                    return ShipGL::salesReceipt($customerId, $date, $acct, $pmId, $lines, $memo, $class, $b['booking_ref']);
                });
            if (!ShipGL::balanced((int)$txnId)) {
                fail('The accounting entry of the booking did not balance; nothing was saved.');
            }
            [, $pmId] = ShipGL::channelAccounts($channel === 'corporate' || $channel === 'agent' ? 'cash' : $channel);
            $payId = DB::insert('shp_payments', [
                'payment_no' => Ship::no('shp_payment', 'PAY-', 7), 'booking_id' => $bookingId, 'channel' => $channel,
                'payment_method_id' => in_array($channel, ['corporate', 'agent'], true) ? null : $pmId, 'provider_ref' => $providerRef,
                'idempotency_key' => $idem, 'amount' => $amount, 'status' => in_array($channel, ['corporate', 'agent'], true) ? 'authorized' : 'captured',
                'shift_id' => $shiftId, 'office_id' => $pay['office_id'] ?? $b['office_id'], 'txn_id' => $txnId,
                'received_by' => Auth::id(), 'received_at' => $now,
            ]);
            DB::run("UPDATE shp_ticket_fees f JOIN shp_tickets t ON t.id = f.ticket_id SET f.status = 'collected' WHERE t.booking_id = :b AND f.status = 'held'", ['b' => $bookingId]);
            DB::run("UPDATE shp_ticket_fees f JOIN shp_vehicles x ON x.id = f.vehicle_id SET f.status = 'collected' WHERE x.booking_id = :b AND f.status = 'held'", ['b' => $bookingId]);
            DB::update('shp_bookings', ['status' => 'confirmed', 'paid_amount' => $amount, 'confirmed_at' => $now, 'hold_expires_at' => null], 'id = :id', ['id' => $bookingId]);
            if ($b['promo_id']) {
                DB::run('UPDATE shp_promos SET used_count = used_count + 1 WHERE id = :id', ['id' => $b['promo_id']]);
            }
            Ship::audit('payment', 'tkt', 'shp_booking', $bookingId, 'Booking ' . $b['booking_ref'] . ' paid (' . $channel . ')', ['amount' => $amount, 'page_key' => 'tkt.booking']);
            // the confirmation goes out only now that the payment is recorded in the books
            Notify::customer('booking:' . $bookingId . ':confirmed', 'ticket_issued', 'passenger', $b['contact_email'], $b['contact_phone'],
                'Your e-tickets for ' . $v['voyage_no'] . ' (booking ' . $b['booking_ref'] . ')',
                'Booking ' . $b['booking_ref'] . ' is paid: ' . count($issued) . ' passenger ticket(s)' . ($vehicles ? ' and ' . count($vehicles) . ' vehicle pass(es)' : '') . ' for '
                . $v['origin_name'] . ' to ' . $v['dest_name'] . ', departing ' . fdatetime($v['etd']) . '. Boarding opens ' . (int)$v['boarding_open_min'] . ' minutes before departure.',
                'shp_bookings', $bookingId);
            return $payId;
        });
    }

    /** The sales lines of tickets and vehicle passes: fare, surcharge and each fee on its own line and account */
    public static function saleLines(array $tickets, array $vehicles, array $v, ?int $class): array
    {
        $vat = ShipGL::taxCode('VAT12');
        $ex = ShipGL::taxCode('EX');
        $nt = ShipGL::taxCode('NT');
        $lines = [];
        foreach ($tickets as $t) {
            $cat = DB::row('SELECT vat_exempt FROM shp_fare_categories WHERE id = :id', ['id' => $t['category_id']]);
            $isExempt = (int)($cat['vat_exempt'] ?? 0) === 1;
            [$sNet, $sVat] = [(float)$t['surcharge_net'], 0.0];
            $fareVat = (float)$t['vat_amount'];
            if (!$isExempt && $sNet > 0) {
                $sVat = r2($sNet * ShipGL::vatRate() / 100);
                $fareVat = r2($fareVat - $sVat);
            }
            $who = $t['ticket_no'] . ' ' . $t['last_name'] . ', ' . $t['first_name'] . ' (' . ($t['cat_name'] ?? '') . ')';
            if ((float)$t['fare_net'] > 0 || $fareVat > 0) {
                $lines[] = ShipGL::line(ShipGL::item('SHP-FARE'), 'Fare ' . $who . ' ' . $t['accom_code'], (float)$t['fare_net'], $fareVat, $isExempt ? $ex : $vat, ShipGL::paxSaleAccount(), $class);
            }
            if ($sNet > 0) {
                $lines[] = ShipGL::line(ShipGL::item('SHP-SURCH'), 'Fuel surcharge ' . $t['ticket_no'], $sNet, $sVat, $isExempt ? $ex : $vat, ShipGL::surchargeSaleAccount(), $class);
            }
            foreach (DB::all('SELECT f.*, s.name, s.code FROM shp_ticket_fees f JOIN shp_fees s ON s.id = f.fee_id WHERE f.ticket_id = :t', ['t' => $t['id']]) as $f) {
                $lines[] = ShipGL::line(ShipGL::item($f['ownership'] === 'agent' && stripos($f['code'], 'INS') !== false ? 'SHP-PINS' : 'SHP-TFEE'), $f['name'] . ' ' . $t['ticket_no'], (float)$f['amount'], 0, $nt, (int)$f['account_id'], $class);
            }
        }
        foreach ($vehicles as $x) {
            $lines[] = ShipGL::line(ShipGL::item('SHP-VEH'), 'Vehicle ' . $x['vehicle_no'] . ' ' . $x['plate_no'] . ' (' . ($x['class_name'] ?? '') . ')', (float)$x['fare_net'], (float)$x['vat_amount'], $vat, ShipGL::freightSaleAccount('vehicle'), $class);
            foreach (DB::all('SELECT f.*, s.name FROM shp_ticket_fees f JOIN shp_fees s ON s.id = f.fee_id WHERE f.vehicle_id = :x', ['x' => $x['id']]) as $f) {
                $lines[] = ShipGL::line(ShipGL::item('SHP-TFEE'), $f['name'] . ' ' . $x['vehicle_no'], (float)$f['amount'], 0, $nt, (int)$f['account_id'], $class);
            }
        }
        return $lines;
    }

    // ------------------------------------------------------------------ look-ups

    public static function find(int $id): ?array
    {
        return DB::row('SELECT b.*, c.name AS customer_name, a.name AS agent_name, o.name AS office_name FROM shp_bookings b
                          LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN customers a ON a.id = b.agent_customer_id
                          LEFT JOIN shp_offices o ON o.id = b.office_id WHERE b.id = :id', ['id' => $id]);
    }

    public static function tickets(int $bookingId): array
    {
        return DB::all('SELECT t.*, c.name AS cat_name, c.code AS cat_code, s.seat_no, v.voyage_no, v.etd, v.status AS voyage_status
                          FROM shp_tickets t JOIN shp_fare_categories c ON c.id = t.category_id LEFT JOIN shp_seats s ON s.id = t.seat_id
                          JOIN shp_voyages v ON v.id = t.voyage_id WHERE t.booking_id = :b ORDER BY t.voyage_id, t.pax_seq', ['b' => $bookingId]);
    }

    public static function vehicles(int $bookingId): array
    {
        return DB::all('SELECT x.*, c.name AS class_name FROM shp_vehicles x JOIN shp_vehicle_classes c ON c.id = x.vehicle_class_id WHERE x.booking_id = :b ORDER BY x.id', ['b' => $bookingId]);
    }

    // ------------------------------------------------------------------ refunds

    /** What a ticket would refund now: [refundable, retained fee, fees refunded, fare gross paid] */
    public static function refundQuote(array $t, string $type): array
    {
        $farePaid = r2((float)$t['fare_net'] + (float)$t['vat_amount'] + (float)$t['surcharge_net']);
        $fees = (float)$t['fees_total'];
        if ($type === 'voyage_cancelled' || $type === 'overcharge') {
            return [r2($farePaid + $fees), 0.0, $fees, $farePaid];
        }
        $v = DB::row('SELECT etd FROM shp_voyages WHERE id = :id', ['id' => $t['voyage_id']]);
        $late = strtotime($v['etd']) - strtotime(Ship::now()) < 3600 * Ship::intSetting('cancel_late_hours', 24);
        $pct = (float)Ship::setting($late ? 'cancel_late_fee_pct' : 'cancel_fee_pct', $late ? '50' : '20');
        $retained = r2($farePaid * $pct / 100);
        $feesBack = Ship::setting('refund_fees_unused', '1') === '1' ? $fees : 0.0;
        return [r2($farePaid - $retained + $feesBack), $retained, $feesBack, $farePaid];
    }

    /**
     * Asks for a refund of tickets / vehicle passes of one booking. Another person approves it in the
     * Approvals Queue; nothing is posted until then.
     */
    public static function requestRefund(int $bookingId, array $ticketIds, array $vehicleIds, string $type, string $reason, string $method = 'cash', ?int $caseId = null): int
    {
        if (trim($reason) === '') {
            fail('Give the reason for the refund.');
        }
        return DB::tx(function () use ($bookingId, $ticketIds, $vehicleIds, $type, $reason, $method, $caseId) {
            $b = DB::row('SELECT * FROM shp_bookings WHERE id = :id FOR UPDATE', ['id' => $bookingId]);
            if (!$b || !in_array($b['status'], ['confirmed', 'completed'], true)) {
                fail('Only a paid booking can be refunded.');
            }
            if (!$ticketIds && !$vehicleIds) {
                fail('Tick the tickets or vehicle passes to refund.');
            }
            $original = 0.0;
            $refund = 0.0;
            $retained = 0.0;
            $refs = [];
            foreach ($ticketIds as $tid) {
                $t = DB::row('SELECT * FROM shp_tickets WHERE id = :id AND booking_id = :b FOR UPDATE', ['id' => (int)$tid, 'b' => $bookingId]);
                if (!$t) {
                    fail('A ticket is not on this booking.');
                }
                if (!in_array($t['status'], ['issued', 'checked_in', 'no_show'], true) || ($t['status'] === 'no_show' && $type !== 'voyage_cancelled')) {
                    fail('Ticket ' . $t['ticket_no'] . ' is ' . strtolower(Ship::TICKET_STATUS[$t['status']] ?? $t['status']) . ' and cannot be refunded.');
                }
                if (DB::value("SELECT 1 FROM shp_refunds WHERE ticket_id = :t AND status IN ('requested', 'approved')", ['t' => $t['id']])) {
                    fail('Ticket ' . $t['ticket_no'] . ' already has a refund in progress.');
                }
                [$r, $ret] = self::refundQuote($t, $type);
                $original += (float)$t['total'];
                $refund += $r;
                $retained += $ret;
                $refs[] = ['ticket', (int)$t['id'], $t['ticket_no'], (float)$t['total'], $r, $ret];
            }
            foreach ($vehicleIds as $xid) {
                $x = DB::row('SELECT * FROM shp_vehicles WHERE id = :id AND booking_id = :b FOR UPDATE', ['id' => (int)$xid, 'b' => $bookingId]);
                if (!$x || !in_array($x['status'], ['issued', 'checked_in', 'no_show'], true)) {
                    fail('A vehicle pass on this booking cannot be refunded in its present status.');
                }
                $paid = r2((float)$x['fare_net'] + (float)$x['vat_amount']);
                $ret = in_array($type, ['voyage_cancelled', 'overcharge'], true) ? 0.0 : r2($paid * (float)Ship::setting('cancel_fee_pct', '20') / 100);
                $r = r2($paid - $ret + (float)$x['fees_total']);
                $original += (float)$x['total'];
                $refund += $r;
                $retained += $ret;
                $refs[] = ['vehicle', (int)$x['id'], $x['vehicle_no'], (float)$x['total'], $r, $ret];
            }
            $first = 0;
            foreach ($refs as [$kind, $rid, $no, $orig, $r, $ret]) {
                $id = DB::insert('shp_refunds', [
                    'refund_no' => Ship::no('shp_refund', 'RF-', 6), 'booking_id' => $bookingId,
                    'ticket_id' => $kind === 'ticket' ? $rid : null, 'vehicle_id' => $kind === 'vehicle' ? $rid : null,
                    'refund_type' => $type, 'reason' => mb_substr($reason, 0, 255), 'original_amount' => $orig, 'fee_retained' => $ret,
                    'refund_amount' => $r, 'method' => $method, 'status' => 'requested', 'requested_by' => Auth::id(), 'requested_at' => Ship::now(), 'case_id' => $caseId,
                ]);
                $first = $first ?: $id;
                $refNo = (string)DB::value('SELECT refund_no FROM shp_refunds WHERE id = :id', ['id' => $id]);
                ShipApproval::request('refund', 'shp_refunds', $id, $refNo, $r, 'Refund ' . $refNo . ' of ' . $no . ' (booking ' . $b['booking_ref'] . '): ' . $reason);
            }
            return $first;
        });
    }

    /** Called by the Approvals Queue */
    public static function refundDecision(int $refundId, bool $approve, string $notes): void
    {
        $r = DB::row('SELECT * FROM shp_refunds WHERE id = :id FOR UPDATE', ['id' => $refundId]);
        if (!$r || $r['status'] !== 'requested') {
            fail('The refund is no longer waiting for approval.');
        }
        if (!$approve) {
            DB::update('shp_refunds', ['status' => 'rejected', 'approved_by' => Auth::id(), 'approved_at' => Ship::now(), 'notes' => $notes], 'id = :id', ['id' => $refundId]);
            return;
        }
        $b = DB::row('SELECT * FROM shp_bookings WHERE id = :id FOR UPDATE', ['id' => $r['booking_id']]);
        $customerId = (int)($b['customer_id'] ?: ShipGL::walkInCustomer());
        $vat = ShipGL::taxCode('VAT12');
        $ex = ShipGL::taxCode('EX');
        $nt = ShipGL::taxCode('NT');
        $lines = [];
        $feeLines = [];
        if ($r['ticket_id']) {
            $t = DB::row('SELECT t.*, c.vat_exempt FROM shp_tickets t JOIN shp_fare_categories c ON c.id = t.category_id WHERE t.id = :id FOR UPDATE', ['id' => $r['ticket_id']]);
            // the passenger may have travelled after all since the refund was asked for
            if (!in_array($t['status'], ['issued', 'checked_in', 'no_show'], true)) {
                fail('Ticket ' . $t['ticket_no'] . ' is now ' . strtolower(Ship::TICKET_STATUS[$t['status']] ?? $t['status']) . ' and can no longer be refunded. Reject the refund instead.');
            }
            $v = Ship::voyage((int)$t['voyage_id']);
            $class = ShipGL::vesselClass((int)$v['vessel_id']);
            $farePaid = r2((float)$t['fare_net'] + (float)$t['vat_amount'] + (float)$t['surcharge_net']);
            $share = $farePaid > 0 ? ($farePaid - (float)$r['fee_retained']) / $farePaid : 0;   // part of the fare given back
            $revAcct = (int)$t['revenue_done'] === 1 ? Accts::get('shp_fare_revenue') : Accts::get('shp_deferred_pax');
            $netBack = r2(((float)$t['fare_net'] + (float)$t['surcharge_net']) * $share);
            $vatBack = r2((float)$t['vat_amount'] * $share);
            if ($netBack > 0 || $vatBack > 0) {
                $lines[] = ShipGL::line(ShipGL::item('SHP-FARE'), 'Refund of fare ' . $t['ticket_no'], $netBack, $vatBack, (int)$t['vat_exempt'] === 1 ? $ex : $vat, $revAcct, $class);
            }
            $feeRows = DB::all("SELECT * FROM shp_ticket_fees WHERE ticket_id = :t AND status = 'collected'", ['t' => $t['id']]);
            $feesBack = (float)$r['refund_amount'] - $netBack - $vatBack;
            if ($feesBack > 0.004) {
                foreach ($feeRows as $f) {
                    $lines[] = ShipGL::line(ShipGL::item('SHP-TFEE'), 'Refund of fee ' . $t['ticket_no'], (float)$f['amount'], 0, $nt, (int)$f['account_id'], $class);
                    DB::update('shp_ticket_fees', ['status' => 'refunded'], 'id = :id', ['id' => $f['id']]);
                }
            }
            // the part of the fare kept is earned now as a cancellation fee
            if ((float)$r['fee_retained'] > 0) {
                $keepNet = r2(((float)$t['fare_net'] + (float)$t['surcharge_net']) - $netBack);
                $feeLines[] = ShipGL::jl($revAcct, $keepNet, 'Fare kept on cancelled ticket ' . $t['ticket_no'], $class);
                $feeLines[] = ShipGL::jl(Accts::get('shp_change_fees'), -$keepNet, 'Cancellation fee ' . $t['ticket_no'], $class);
            }
            $voyageId = (int)$t['voyage_id'];
            $vesselId = (int)$v['vessel_id'];
            $label = $t['ticket_no'];
            DB::run('DELETE FROM shp_seat_allocations WHERE ticket_id = :t', ['t' => $t['id']]);
            DB::update('shp_tickets', ['status' => 'refunded', 'updated_at' => Ship::now(), 'version' => (int)$t['version'] + 1], 'id = :id', ['id' => $t['id']]);
        } else {
            $x = DB::row('SELECT * FROM shp_vehicles WHERE id = :id FOR UPDATE', ['id' => $r['vehicle_id']]);
            $v = Ship::voyage((int)$x['voyage_id']);
            $class = ShipGL::vesselClass((int)$v['vessel_id']);
            $paid = r2((float)$x['fare_net'] + (float)$x['vat_amount']);
            $share = $paid > 0 ? ($paid - (float)$r['fee_retained']) / $paid : 0;
            $acct = (int)$x['revenue_done'] === 1 ? Accts::get('shp_vehicle_revenue') : Accts::get('shp_deferred_freight');
            $netBack = r2((float)$x['fare_net'] * $share);
            $vatBack = r2((float)$x['vat_amount'] * $share);
            $lines[] = ShipGL::line(ShipGL::item('SHP-VEH'), 'Refund of vehicle pass ' . $x['vehicle_no'], $netBack, $vatBack, $vat, $acct, $class);
            foreach (DB::all("SELECT * FROM shp_ticket_fees WHERE vehicle_id = :x AND status = 'collected'", ['x' => $x['id']]) as $f) {
                $lines[] = ShipGL::line(ShipGL::item('SHP-TFEE'), 'Refund of fee ' . $x['vehicle_no'], (float)$f['amount'], 0, $nt, (int)$f['account_id'], $class);
                DB::update('shp_ticket_fees', ['status' => 'refunded'], 'id = :id', ['id' => $f['id']]);
            }
            if ((float)$r['fee_retained'] > 0) {
                $keepNet = r2((float)$x['fare_net'] - $netBack);
                $feeLines[] = ShipGL::jl($acct, $keepNet, 'Fare kept on cancelled vehicle pass ' . $x['vehicle_no'], $class);
                $feeLines[] = ShipGL::jl(Accts::get('shp_change_fees'), -$keepNet, 'Cancellation fee ' . $x['vehicle_no'], $class);
            }
            $voyageId = (int)$x['voyage_id'];
            $vesselId = (int)$v['vessel_id'];
            $label = $x['vehicle_no'];
            DB::update('shp_vehicles', ['status' => 'refunded'], 'id = :id', ['id' => $x['id']]);
        }
        $sum = 0.0;
        foreach ($lines as $l) {
            $sum += $l['amount'] + $l['tax_amount'];
        }
        if ($sum > (float)$r['refund_amount'] + 0.02) {
            fail('The refund lines (' . money($sum) . ') would be more than the approved refund (' . money($r['refund_amount']) . ').');
        }
        $memo = 'Refund ' . $r['refund_no'] . ' of ' . $label . ' (booking ' . $b['booking_ref'] . ')';
        $link = ['source_type' => 'refund', 'source_id' => $refundId, 'voyage_id' => $voyageId, 'vessel_id' => $vesselId, 'memo' => $memo];
        $cm = ShipGL::once('refund:' . $refundId . ':credit', $link, fn() => ShipGL::creditMemo($customerId, Ship::today(), $lines, $memo, $class));
        $feeTxn = $feeLines ? ShipGL::once('refund:' . $refundId . ':fee', $link, fn() => ShipGL::journal(Ship::today(), $feeLines, 'Cancellation fee of ' . $label, $class, $r['refund_no'])) : null;
        DB::update('shp_refunds', ['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => Ship::now(), 'credit_txn_id' => $cm, 'fee_txn_id' => $feeTxn,
            'refund_amount' => r2($sum)], 'id = :id', ['id' => $refundId]);
        self::refreshBookingStatus((int)$r['booking_id']);
        Notify::customer('refund:' . $refundId . ':approved', 'refund', 'passenger', $b['contact_email'], $b['contact_phone'], 'Refund ' . $r['refund_no'] . ' approved',
            'Your refund of ' . money($sum) . ' for ' . $label . ' was approved. It is released at the ticket office or to your original payment channel.', 'shp_refunds', $refundId);
    }

    /** Pays out an approved refund (cash from the counter fund, or back to the card / e-wallet / bank) */
    public static function payRefund(int $refundId, ?string $method = null, ?int $shiftId = null): int
    {
        return DB::tx(function () use ($refundId, $method, $shiftId) {
            $r = DB::row('SELECT * FROM shp_refunds WHERE id = :id FOR UPDATE', ['id' => $refundId]);
            if (!$r || $r['status'] !== 'approved') {
                fail('Only an approved refund can be paid out.');
            }
            $method = $method ?: $r['method'];
            $b = DB::row('SELECT * FROM shp_bookings WHERE id = :id FOR UPDATE', ['id' => $r['booking_id']]);
            $customerId = (int)($b['customer_id'] ?: ShipGL::walkInCustomer());
            $acct = ShipGL::refundAccount($method);
            [, $pmId] = ShipGL::channelAccounts($method);
            $voyage = $r['ticket_id'] ? DB::value('SELECT voyage_id FROM shp_tickets WHERE id = :t', ['t' => $r['ticket_id']]) : DB::value('SELECT voyage_id FROM shp_vehicles WHERE id = :x', ['x' => $r['vehicle_id']]);
            $vesselId = (int)DB::value('SELECT vessel_id FROM shp_voyages WHERE id = :v', ['v' => $voyage]);
            $memo = 'Refund ' . $r['refund_no'] . ' paid (booking ' . $b['booking_ref'] . ')';
            $txn = ShipGL::once('refund:' . $refundId . ':paid', ['source_type' => 'refund', 'source_id' => $refundId, 'voyage_id' => (int)$voyage, 'vessel_id' => $vesselId, 'memo' => $memo],
                fn() => ShipGL::refundPaid((int)$r['credit_txn_id'], $customerId, $acct, $pmId, (float)$r['refund_amount'], Ship::today(), $memo, ShipGL::vesselClass($vesselId)));
            if ($method === 'cash' && !$shiftId && PHP_SAPI !== 'cli') {
                $shiftId = Cashier::requireOpen((int)Auth::id());
            }
            Cashier::assertOpen($shiftId);
            DB::update('shp_refunds', ['status' => 'paid', 'method' => $method, 'paid_by' => Auth::id(), 'paid_at' => Ship::now(), 'paid_txn_id' => $txn, 'shift_id' => $shiftId], 'id = :id', ['id' => $refundId]);
            DB::run('UPDATE shp_bookings SET refunded_amount = refunded_amount + :a WHERE id = :id', ['a' => $r['refund_amount'], 'id' => $r['booking_id']]);
            Ship::audit('refund_paid', 'tkt', 'shp_refund', $refundId, $r['refund_no'], ['amount' => $r['refund_amount'], 'page_key' => 'tkt.refunds']);
            return (int)$txn;
        });
    }

    public static function refreshBookingStatus(int $bookingId): void
    {
        $live = (int)DB::value("SELECT COUNT(*) FROM shp_tickets WHERE booking_id = :b AND status IN ('issued', 'checked_in', 'boarded', 'no_show')", ['b' => $bookingId])
              + (int)DB::value("SELECT COUNT(*) FROM shp_vehicles WHERE booking_id = :b AND status IN ('issued', 'checked_in', 'loaded', 'unloaded', 'no_show')", ['b' => $bookingId]);
        if ($live === 0) {
            DB::run("UPDATE shp_bookings SET status = 'cancelled' WHERE id = :b AND status = 'confirmed'", ['b' => $bookingId]);
        }
    }

    // ------------------------------------------------------------------ rebooking

    /**
     * Moves a paid ticket to another voyage of the same route. A cancelled voyage or a free transfer costs nothing;
     * otherwise the change fee is collected, with any higher fare, on a sales receipt. A lower fare is not refunded.
     */
    public static function transfer(int $ticketId, int $newVoyageId, array $opt = []): int
    {
        return DB::tx(function () use ($ticketId, $newVoyageId, $opt) {
            $t = DB::row('SELECT * FROM shp_tickets WHERE id = :id FOR UPDATE', ['id' => $ticketId]);
            if (!$t || !in_array($t['status'], ['issued', 'checked_in', 'no_show'], true)) {
                fail('Only an issued ticket that has not boarded can be moved to another voyage.');
            }
            $old = Ship::voyage((int)$t['voyage_id']);
            $free = !empty($opt['free']) || $old['status'] === 'cancelled';
            if ($t['status'] === 'no_show' && !$free && !Access::special('shp_fare_override')) {
                fail('A no-show ticket can be revalidated only with the fare-override permission (company policy).');
            }
            $new = Ship::voyage($newVoyageId, true);
            if (!$new || !in_array($new['status'], ['on_sale', 'boarding'], true)) {
                fail('Choose a voyage that is on sale.');
            }
            if ((int)$new['id'] === (int)$old['id']) {
                fail('Choose a different voyage.');
            }
            if ((int)$new['route_id'] !== (int)$old['route_id']) {
                fail('A ticket can be moved only to another voyage of the same route. For another route, refund it and book again.');
            }
            $accom = (string)($opt['accom_code'] ?? $t['accom_code']);
            self::expireHolds($newVoyageId);
            $cap = self::capacity($new);
            if (!isset($cap[$accom]) || $cap[$accom]['left'] < 1) {
                fail('There is no place left in ' . ($cap[$accom]['name'] ?? $accom) . ' on ' . $new['voyage_no'] . '.');
            }
            if (array_sum(array_column($cap, 'taken')) + 1 > self::paxLimit($new)) {
                fail('Voyage ' . $new['voyage_no'] . ' is full.');
            }
            $cat = Fares::category((int)$t['category_id']);
            $q = Fares::quotePax($new, $accom, $cat);
            $oldFare = r2((float)$t['fare_net'] + (float)$t['vat_amount'] + (float)$t['surcharge_net']);
            $newFare = r2($q['fare_net'] + $q['vat'] + $q['surcharge_net']);
            $diff = $free ? 0.0 : max(0.0, r2($newFare - $oldFare));
            $fee = $free ? 0.0 : r2((float)Ship::setting('change_fee', '150'));
            $no = Ship::no('shp_ticket', 'ET-', 8);
            $seqMax = (int)DB::value('SELECT COALESCE(MAX(pax_seq), 0) FROM shp_tickets WHERE booking_id = :b', ['b' => $t['booking_id']]);
            $copy = $t;
            foreach (['id', 'created_at', 'updated_at', 'ticket_no', 'seat_id', 'checked_in_at', 'boarded_at', 'boarded_by'] as $k) {
                unset($copy[$k]);
            }
            $newId = DB::insert('shp_tickets', array_merge($copy, [
                'ticket_no' => $no, 'pax_seq' => $seqMax + 1, 'voyage_id' => $newVoyageId, 'accom_code' => $accom, 'status' => 'issued',
                'qr_sig' => Ship::sign('pax', $no, $newVoyageId), 'issued_at' => Ship::now(), 'transferred_from_id' => $ticketId, 'version' => 1,
                // the fare paid stays with the ticket (it is still deferred); the difference collected is added
                'total' => r2((float)$t['total'] + $diff),
            ]));
            DB::run('UPDATE shp_ticket_fees SET ticket_id = :n WHERE ticket_id = :o', ['n' => $newId, 'o' => $ticketId]);
            DB::run('DELETE FROM shp_seat_allocations WHERE ticket_id = :t', ['t' => $ticketId]);
            DB::update('shp_tickets', ['status' => 'transferred', 'updated_at' => Ship::now(), 'version' => (int)$t['version'] + 1], 'id = :id', ['id' => $ticketId]);
            if ($cap[$accom]['reserved']) {
                $seat = !empty($opt['seat_id']) ? (int)$opt['seat_id'] : self::freeSeat($newVoyageId, (int)$new['vessel_id'], $cap[$accom]['id'], (int)$cat['vat_exempt'] === 1);
                if ($seat) {
                    self::allocate($newVoyageId, (int)$new['vessel_id'], $cap[$accom]['id'], $seat, $newId);
                }
            }
            $b = DB::row('SELECT * FROM shp_bookings WHERE id = :id', ['id' => $t['booking_id']]);
            if ($diff > 0 || $fee > 0) {
                $channel = (string)($opt['channel'] ?? 'cash');
                if (in_array($channel, ['card', 'ewallet', 'maya', 'bank_transfer'], true) && trim((string)($opt['provider_ref'] ?? '')) === '') {
                    fail('Enter the reference of the payment of the change fee.');
                }
                $class = ShipGL::vesselClass((int)$new['vessel_id']);
                $lines = [];
                if ($diff > 0) {
                    [$dNet, $dVat] = ShipGL::splitVat($diff, (int)$cat['vat_exempt'] === 1);
                    $lines[] = ShipGL::line(ShipGL::item('SHP-FARE'), 'Fare difference ' . $no, $dNet, $dVat, ShipGL::taxCode((int)$cat['vat_exempt'] === 1 ? 'EX' : 'VAT12'), ShipGL::paxSaleAccount(), $class);
                    DB::update('shp_tickets', ['fare_net' => r2((float)$t['fare_net'] + $dNet), 'vat_amount' => r2((float)$t['vat_amount'] + $dVat)], 'id = :id', ['id' => $newId]);
                }
                if ($fee > 0) {
                    [$fNet, $fVat] = ShipGL::splitVat($fee);
                    $lines[] = ShipGL::line(ShipGL::item('SHP-CHG'), 'Change fee ' . $no . ' (from ' . $t['ticket_no'] . ')', $fNet, $fVat, ShipGL::taxCode('VAT12'), Accts::get('shp_change_fees'), $class);
                }
                [$acct, $pmId] = ShipGL::channelAccounts($channel, $b['office_id'] ? (int)$b['office_id'] : null);
                $memo = 'Rebooking of ' . $t['ticket_no'] . ' to ' . $new['voyage_no'];
                $txn = ShipGL::once('ticket:' . $ticketId . ':transfer', ['source_type' => 'ticket', 'source_id' => $newId, 'voyage_id' => $newVoyageId, 'vessel_id' => (int)$new['vessel_id'], 'memo' => $memo],
                    fn() => ShipGL::salesReceipt((int)($b['customer_id'] ?: ShipGL::walkInCustomer()), Ship::today(), $acct, $pmId, $lines, $memo, $class, $b['booking_ref']));
                $shift = $channel === 'cash' && PHP_SAPI !== 'cli' ? Cashier::requireOpen((int)Auth::id()) : ($opt['shift_id'] ?? null);
                Cashier::assertOpen($shift !== null ? (int)$shift : null);
                DB::insert('shp_payments', ['payment_no' => Ship::no('shp_payment', 'PAY-', 7), 'booking_id' => $t['booking_id'], 'channel' => $channel,
                    'payment_method_id' => $pmId, 'provider_ref' => ($opt['provider_ref'] ?? '') ?: null, 'amount' => r2($diff + $fee), 'status' => 'captured',
                    'shift_id' => $shift, 'office_id' => $b['office_id'], 'txn_id' => $txn, 'received_by' => Auth::id(), 'received_at' => Ship::now(), 'notes' => $memo]);
                DB::run('UPDATE shp_bookings SET total_amount = total_amount + :a, paid_amount = paid_amount + :a2 WHERE id = :id', ['a' => r2($diff + $fee), 'a2' => r2($diff + $fee), 'id' => $t['booking_id']]);
            }
            Ship::audit('transfer', 'tkt', 'shp_ticket', $ticketId, $t['ticket_no'] . ' moved to ' . $new['voyage_no'] . ' as ' . $no, ['amount' => $diff + $fee, 'page_key' => 'tkt.bookings', 'reason' => (string)($opt['reason'] ?? '')]);
            Notify::customer('ticket:' . $ticketId . ':transfer', 'schedule_change', 'passenger', $b['contact_email'], $b['contact_phone'], 'Your ticket was moved to ' . $new['voyage_no'],
                'Ticket ' . $t['ticket_no'] . ' is replaced by ' . $no . ' on voyage ' . $new['voyage_no'] . ', departing ' . fdatetime($new['etd']) . '. The old ticket can no longer board.', 'shp_tickets', $newId);
            return $newId;
        });
    }

    /** At departure: tickets that never boarded become no-shows (boarding is never assumed from a purchase) */
    public static function markNoShows(int $voyageId): int
    {
        DB::run("UPDATE shp_vehicles SET status = 'no_show' WHERE voyage_id = :v AND status IN ('issued', 'checked_in')", ['v' => $voyageId]);
        return DB::run("UPDATE shp_tickets SET status = 'no_show', updated_at = :n WHERE voyage_id = :v AND status IN ('issued', 'checked_in')", ['v' => $voyageId, 'n' => Ship::now()])->rowCount();
    }
}
