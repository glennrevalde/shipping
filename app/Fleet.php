<?php
declare(strict_types=1);

/**
 * The vessel registry. A new vessel gets its own accounting class (so the financial statements by class are the
 * vessel P&L) and its own inventory site (its fuel tanks and stores), and an approved accommodation plan whose
 * seats are generated row by row. The passenger limit is the approved capacity, never more than the sum of the plan.
 */
final class Fleet
{
    public const FIELDS = ['code', 'name', 'vessel_type', 'official_no', 'imo_no', 'call_sign', 'classification', 'owner_operator', 'year_built', 'gross_tonnage', 'net_tonnage',
        'deadweight_t', 'length_m', 'breadth_m', 'pax_capacity', 'crew_complement', 'lane_meters', 'vehicle_lanes', 'max_vehicle_weight_t', 'max_vehicle_length_m',
        'cargo_capacity_t', 'cargo_capacity_cbm', 'service_speed_kn', 'fuel_type', 'fuel_item_id', 'fuel_tank_l', 'fuel_rate_lph', 'home_port_id', 'status', 'notes', 'fixed_asset_id'];

    public static function save(array $d, ?int $id = null): int
    {
        $old = $id ? DB::row('SELECT * FROM shp_vessels WHERE id = :id', ['id' => $id]) : null;
        $row = [];
        foreach (self::FIELDS as $k) {
            if (array_key_exists($k, $d)) {
                $row[$k] = $d[$k] === '' ? null : $d[$k];
            }
        }
        $row['code'] = strtoupper(trim((string)($row['code'] ?? $old['code'] ?? '')));
        $row['name'] = trim((string)($row['name'] ?? $old['name'] ?? ''));
        if ($row['code'] === '' || $row['name'] === '') {
            fail('Enter the vessel code and name.');
        }
        if (!preg_match('/^[A-Z0-9]{2,10}$/', $row['code'])) {
            fail('The vessel code is 2 to 10 letters or digits (it starts every voyage number).');
        }
        if (DB::value('SELECT id FROM shp_vessels WHERE code = :c' . ($old ? ' AND id <> :id' : ''), $old ? ['c' => $row['code'], 'id' => $old['id']] : ['c' => $row['code']])) {
            fail('Another vessel already has code ' . $row['code'] . '.');
        }
        foreach (['gross_tonnage', 'net_tonnage', 'deadweight_t', 'length_m', 'breadth_m', 'lane_meters', 'max_vehicle_weight_t', 'max_vehicle_length_m', 'cargo_capacity_t',
            'cargo_capacity_cbm', 'service_speed_kn', 'fuel_tank_l', 'fuel_rate_lph'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (float)($row[$k] ?? 0);
            }
        }
        foreach (['pax_capacity', 'crew_complement', 'vehicle_lanes'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (int)($row[$k] ?? 0);
            }
        }
        if ($old && isset($row['pax_capacity'])) {
            $plan = (int)DB::value('SELECT COALESCE(SUM(capacity), 0) FROM shp_accom_classes WHERE vessel_id = :v', ['v' => $old['id']]);
            if ($plan > 0 && $row['pax_capacity'] > $plan) {
                fail('The passenger limit (' . $row['pax_capacity'] . ') is more than the accommodation plan holds (' . $plan . ').');
            }
        }
        return DB::tx(function () use ($row, $old) {
            if ($old) {
                DB::update('shp_vessels', $row, 'id = :id', ['id' => $old['id']]);
                if ($old['class_id'] && $row['name'] !== $old['name'] && !DB::value('SELECT 1 FROM classes WHERE name = :n AND id <> :id', ['n' => $row['name'], 'id' => $old['class_id']])) {
                    DB::update('classes', ['name' => $row['name']], 'id = :id', ['id' => $old['class_id']]);
                    Classes::reset();
                }
                Ship::audit('edit', 'fleet', 'shp_vessel', $old['id'], 'Vessel ' . $row['name'], ['changes' => Ship::changes($old, $row), 'page_key' => 'fleet.vessels']);
                return (int)$old['id'];
            }
            // the vessel's own accounting class and inventory site
            $className = DB::value('SELECT 1 FROM classes WHERE name = :n', ['n' => $row['name']]) ? $row['name'] . ' (' . $row['code'] . ')' : $row['name'];
            $row['class_id'] = DB::insert('classes', ['name' => $className, 'description' => 'Vessel ' . $row['code'] . ': revenue and costs of the vessel', 'sort_order' => 100, 'created_by' => Auth::id()]);
            Classes::reset();
            $siteCode = 'V-' . $row['code'];
            $row['site_id'] = DB::value('SELECT id FROM sites WHERE code = :c', ['c' => $siteCode]) ?: DB::insert('sites', ['code' => $siteCode, 'name' => $row['name'] . ' (tanks and stores)', 'site_type' => 'vessel']);
            $row['status'] ??= 'active';
            $id = DB::insert('shp_vessels', $row);
            Ship::audit('create', 'fleet', 'shp_vessel', $id, 'Vessel ' . $row['name'], ['page_key' => 'fleet.vessels']);
            return $id;
        });
    }

    /** Saves the accommodation plan: rows of [code, name, kind, deck, capacity, seat_rows, seat_cols, priority_seats, reserved_seating] */
    public static function saveAccommodation(int $vesselId, array $classes): void
    {
        DB::tx(function () use ($vesselId, $classes) {
            $sort = 0;
            $keep = [];
            foreach ($classes as $c) {
                $code = strtoupper(trim((string)($c['code'] ?? '')));
                if ($code === '') {
                    continue;
                }
                $reserved = !empty($c['reserved_seating']) ? 1 : 0;
                $rows = (int)($c['seat_rows'] ?? 0);
                $cols = (int)($c['seat_cols'] ?? 0);
                $cap = $reserved && $rows > 0 && $cols > 0 ? $rows * $cols : (int)($c['capacity'] ?? 0);
                if ($cap <= 0) {
                    fail('Class ' . $code . ': enter the capacity, or the rows and seats per row.');
                }
                $data = ['vessel_id' => $vesselId, 'code' => $code, 'name' => trim((string)($c['name'] ?? $code)) ?: $code, 'kind' => in_array($c['kind'] ?? 'seat', ['seat', 'bunk', 'cabin', 'open'], true) ? $c['kind'] : 'seat',
                    'deck' => $c['deck'] ?? null, 'capacity' => $cap, 'seat_rows' => $rows, 'seat_cols' => $cols, 'priority_seats' => (int)($c['priority_seats'] ?? 0),
                    'reserved_seating' => $reserved, 'sort_order' => ++$sort];
                $existing = DB::row('SELECT * FROM shp_accom_classes WHERE vessel_id = :v AND code = :c', ['v' => $vesselId, 'c' => $code]);
                if ($existing) {
                    $live = (int)DB::value("SELECT COUNT(*) FROM shp_tickets t JOIN shp_voyages v ON v.id = t.voyage_id WHERE v.vessel_id = :vs AND t.accom_code = :c AND t.status IN ('held', 'issued', 'checked_in')
                                             AND v.status NOT IN ('completed', 'cancelled')", ['vs' => $vesselId, 'c' => $code]);
                    if ($cap < (int)$existing['capacity'] && $live > 0) {
                        fail('Class ' . $code . ' has ' . $live . ' live ticket(s) on open voyages; its capacity cannot be reduced now.');
                    }
                    DB::update('shp_accom_classes', $data, 'id = :id', ['id' => $existing['id']]);
                    $cid = (int)$existing['id'];
                } else {
                    $cid = DB::insert('shp_accom_classes', $data);
                }
                $keep[] = $cid;
                self::generateSeats($cid);
            }
            if (!$keep) {
                fail('Add at least one accommodation class.');
            }
            [$in, $p] = DB::in('k', $keep);
            foreach (DB::all("SELECT id, code FROM shp_accom_classes WHERE vessel_id = :v AND id NOT IN $in", $p + ['v' => $vesselId]) as $gone) {
                if (DB::value('SELECT 1 FROM shp_tickets t JOIN shp_voyages v ON v.id = t.voyage_id WHERE v.vessel_id = :vs AND t.accom_code = :c LIMIT 1', ['vs' => $vesselId, 'c' => $gone['code']])) {
                    fail('Class ' . $gone['code'] . ' has tickets and cannot be removed from the plan.');
                }
                DB::run('DELETE FROM shp_seats WHERE accom_class_id = :c', ['c' => $gone['id']]);
                DB::run('DELETE FROM shp_accom_classes WHERE id = :c', ['c' => $gone['id']]);
            }
            $total = (int)DB::value('SELECT SUM(capacity) FROM shp_accom_classes WHERE vessel_id = :v', ['v' => $vesselId]);
            $cur = (int)DB::value('SELECT pax_capacity FROM shp_vessels WHERE id = :v', ['v' => $vesselId]);
            if ($cur <= 0 || $cur > $total) {
                DB::update('shp_vessels', ['pax_capacity' => $total], 'id = :id', ['id' => $vesselId]);
            }
            Ship::audit('accommodation', 'fleet', 'shp_vessel', $vesselId, 'Accommodation plan (' . $total . ' places)', ['page_key' => 'fleet.vessels']);
        });
    }

    /** Seats of a reserved-seating class, row letters A.. and numbers 1..; the first priority_seats are priority seats */
    public static function generateSeats(int $classId): void
    {
        $c = DB::row('SELECT * FROM shp_accom_classes WHERE id = :id', ['id' => $classId]);
        if (!$c || !(int)$c['reserved_seating'] || !(int)$c['seat_rows'] || !(int)$c['seat_cols']) {
            DB::run('UPDATE shp_seats SET is_active = 0 WHERE accom_class_id = :c', ['c' => $classId]);
            return;
        }
        $have = DB::pairs('SELECT seat_no, id FROM shp_seats WHERE accom_class_id = :c', ['c' => $classId]);
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $n = 0;
        $want = [];
        for ($r = 1; $r <= (int)$c['seat_rows']; $r++) {
            for ($col = 1; $col <= (int)$c['seat_cols']; $col++) {
                $no = $c['code'][0] . $r . $letters[($col - 1) % strlen($letters)];
                $want[$no] = true;
                $prio = ++$n <= (int)$c['priority_seats'] ? 1 : 0;
                if (isset($have[$no])) {
                    DB::update('shp_seats', ['is_active' => 1, 'is_priority' => $prio, 'seat_row' => $r, 'seat_col' => $col], 'id = :id', ['id' => $have[$no]]);
                } else {
                    $clash = DB::value('SELECT id FROM shp_seats WHERE vessel_id = :v AND seat_no = :n', ['v' => $c['vessel_id'], 'n' => $no]);
                    $seatNo = $clash ? substr($c['code'], 0, 2) . $r . $letters[($col - 1) % strlen($letters)] : $no;
                    DB::insert('shp_seats', ['vessel_id' => $c['vessel_id'], 'accom_class_id' => $classId, 'seat_no' => $seatNo, 'seat_row' => $r, 'seat_col' => $col, 'is_priority' => $prio]);
                }
            }
        }
        foreach ($have as $no => $sid) {
            if (!isset($want[$no])) {
                DB::update('shp_seats', ['is_active' => 0], 'id = :id', ['id' => $sid]);
            }
        }
    }

    public static function accommodation(int $vesselId): array
    {
        return DB::all('SELECT * FROM shp_accom_classes WHERE vessel_id = :v ORDER BY sort_order, id', ['v' => $vesselId]);
    }

    /** Seat map of a class on a voyage: rows of [seat id, seat no, priority, taken] */
    public static function seatMap(int $voyageId, int $classId): array
    {
        return DB::all('SELECT s.id, s.seat_no, s.seat_row, s.seat_col, s.is_priority, (a.seat_id IS NOT NULL) AS taken
                          FROM shp_seats s LEFT JOIN shp_seat_allocations a ON a.seat_id = s.id AND a.voyage_id = :v
                         WHERE s.accom_class_id = :c AND s.is_active = 1 ORDER BY s.seat_row, s.seat_col', ['v' => $voyageId, 'c' => $classId]);
    }
}
