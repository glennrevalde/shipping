<?php
declare(strict_types=1);

/**
 * Check-in and boarding by QR (or the ticket number typed by the officer).
 *
 * A scan is accepted only for a paid, issued ticket of this voyage whose QR signature matches. Duplicate scans,
 * the wrong voyage, cancelled / refunded / moved tickets, unpaid holds and boarding before the gate opens are
 * refused, and every scan (accepted or not) is logged with the operator, the device and the time on the device.
 * Boarded is never assumed from a purchase: the counts below are of real scans.
 *
 * Offline: a scanner that loses the connection keeps its scans (signed with the device id and time) and sends
 * them when it is back; they are applied in the order they were scanned, and a scan that conflicts with one
 * already recorded (the same ticket boarded on another device) comes back as a conflict to resolve.
 */
final class Boarding
{
    /** [kind pax|vehicle, number, signature or null] */
    public static function parse(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        if (preg_match('/^(GST1|GSV1)\.([A-Z0-9\-]{3,30})\.([A-F0-9]{12})$/', $code, $m)) {
            return [$m[1] === 'GSV1' ? 'vehicle' : 'pax', $m[2], $m[3]];
        }
        if (preg_match('/^(ET-\d{4,10})$/', $code, $m)) {
            return ['pax', $m[1], null];
        }
        if (preg_match('/^(VP-\d{4,10})$/', $code, $m)) {
            return ['vehicle', $m[1], null];
        }
        return null;
    }

    /**
     * @param string $type checkin | board
     * @param array  $ctx  device_id, scanned_at, offline (bool)
     * @return array result, message, name, seat, accom, category, ticket_no
     */
    public static function scan(int $voyageId, string $code, string $type = 'board', array $ctx = []): array
    {
        return DB::tx(function () use ($voyageId, $code, $type, $ctx) {
            $at = !empty($ctx['scanned_at']) && strtotime((string)$ctx['scanned_at']) ? date('Y-m-d H:i:s', strtotime((string)$ctx['scanned_at'])) : Ship::now();
            $offline = !empty($ctx['offline']);
            $log = function (string $result, string $msg, ?int $ticketId = null, ?int $vehicleId = null, array $extra = []) use ($voyageId, $code, $type, $ctx, $at, $offline) {
                DB::insert('shp_boarding_scans', [
                    'voyage_id' => $voyageId, 'ticket_id' => $ticketId, 'vehicle_id' => $vehicleId, 'scan_code' => mb_substr($code, 0, 120),
                    'scan_type' => $vehicleId ? 'vehicle' : $type, 'result' => $result, 'message' => mb_substr($msg, 0, 255),
                    'device_id' => isset($ctx['device_id']) ? mb_substr((string)$ctx['device_id'], 0, 40) : null, 'operator_id' => Auth::id(),
                    'scanned_at' => $at, 'is_offline' => $offline ? 1 : 0,
                ]);
                return array_merge(['result' => $result, 'message' => $msg, 'ok' => $result === 'accepted'], $extra);
            };
            $v = Ship::voyage($voyageId, true);
            if (!$v) {
                fail('Choose the voyage being boarded.');
            }
            $p = self::parse($code);
            if (!$p) {
                return $log('invalid', 'Not a ticket or vehicle pass of this company.');
            }
            [$kind, $no, $sig] = $p;
            if (!in_array($v['status'], ['boarding', 'ready'], true)) {
                $msg = in_array($v['status'], ['departed', 'arrived', 'completed'], true) ? 'Voyage ' . $v['voyage_no'] . ' has already departed.'
                    : ($v['status'] === 'cancelled' ? 'Voyage ' . $v['voyage_no'] . ' is cancelled.' : 'Boarding of ' . $v['voyage_no'] . ' is not open yet.');
                return $log('not_open', $msg);
            }
            if ($kind === 'vehicle') {
                return self::scanVehicle($v, $no, $sig, $log, $at);
            }
            $t = DB::row('SELECT t.*, c.name AS cat_name, s.seat_no FROM shp_tickets t JOIN shp_fare_categories c ON c.id = t.category_id
                           LEFT JOIN shp_seats s ON s.id = t.seat_id WHERE t.ticket_no = :n FOR UPDATE', ['n' => $no]);
            if (!$t) {
                return $log('invalid', 'Ticket ' . $no . ' does not exist.');
            }
            $extra = ['name' => Ship::maskName($t['last_name'], $t['first_name']), 'seat' => $t['seat_no'], 'accom' => $t['accom_code'], 'category' => $t['cat_name'], 'ticket_no' => $t['ticket_no']];
            if ($sig !== null && !hash_equals(Ship::sign('pax', $t['ticket_no'], (int)$t['voyage_id']), $sig)) {
                return $log('invalid', 'The QR signature does not match ticket ' . $no . ': possible tampering.', (int)$t['id'], null, $extra);
            }
            if ((int)$t['voyage_id'] !== $voyageId) {
                $other = DB::row('SELECT voyage_no, etd FROM shp_voyages WHERE id = :id', ['id' => $t['voyage_id']]);
                return $log('wrong_voyage', 'Ticket ' . $no . ' is for voyage ' . ($other['voyage_no'] ?? '?') . ' (' . fdatetime($other['etd'] ?? '') . '), not this one.', (int)$t['id'], null, $extra);
            }
            switch ($t['status']) {
                case 'held':
                case 'expired':
                    return $log('unpaid', 'Ticket ' . $no . ' is not paid.', (int)$t['id'], null, $extra);
                case 'cancelled':
                case 'refunded':
                case 'transferred':
                    return $log('cancelled', 'Ticket ' . $no . ' was ' . ($t['status'] === 'transferred' ? 'moved to another voyage' : $t['status']) . ' and cannot board.', (int)$t['id'], null, $extra);
                case 'no_show':
                    return $log('cancelled', 'Ticket ' . $no . ' is a no-show of a departed voyage.', (int)$t['id'], null, $extra);
                case 'boarded':
                    $when = $t['boarded_at'] ? fdatetime($t['boarded_at']) : '';
                    return $log($offline ? 'conflict' : 'duplicate', 'Already boarded' . ($when ? ' at ' . $when : '') . '. Duplicate scan refused.', (int)$t['id'], null, $extra);
            }
            if ($type === 'checkin') {
                if ($t['status'] === 'checked_in') {
                    return $log('duplicate', 'Already checked in.', (int)$t['id'], null, $extra);
                }
                DB::update('shp_tickets', ['status' => 'checked_in', 'checked_in_at' => $at, 'version' => (int)$t['version'] + 1, 'updated_at' => Ship::now()], 'id = :id', ['id' => $t['id']]);
                return $log('accepted', 'Checked in.', (int)$t['id'], null, $extra);
            }
            $boarded = (int)DB::value("SELECT COUNT(*) FROM shp_tickets WHERE voyage_id = :v AND status = 'boarded'", ['v' => $voyageId]);
            if ($boarded >= Booking::paxLimit($v)) {
                return $log('invalid', 'The voyage has reached its passenger limit (' . Booking::paxLimit($v) . '). No more boarding.', (int)$t['id'], null, $extra);
            }
            DB::update('shp_tickets', ['status' => 'boarded', 'boarded_at' => $at, 'boarded_by' => Auth::id(), 'checked_in_at' => $t['checked_in_at'] ?: $at,
                'version' => (int)$t['version'] + 1, 'updated_at' => Ship::now()], 'id = :id', ['id' => $t['id']]);
            return $log('accepted', 'Boarded' . ($sig === null ? ' (number typed, ID checked)' : '') . '.', (int)$t['id'], null, $extra);
        });
    }

    private static function scanVehicle(array $v, string $no, ?string $sig, callable $log, string $at): array
    {
        $x = DB::row('SELECT x.*, c.name AS class_name FROM shp_vehicles x JOIN shp_vehicle_classes c ON c.id = x.vehicle_class_id WHERE x.vehicle_no = :n FOR UPDATE', ['n' => $no]);
        if (!$x) {
            return $log('invalid', 'Vehicle pass ' . $no . ' does not exist.');
        }
        $extra = ['name' => $x['plate_no'] . ' ' . $x['class_name'], 'seat' => $x['lane_no'] ? 'Lane ' . $x['lane_no'] : '', 'accom' => 'Vehicle', 'category' => $x['class_name'], 'ticket_no' => $x['vehicle_no']];
        if ($sig !== null && !hash_equals(Ship::sign('vehicle', $x['vehicle_no'], (int)$x['voyage_id']), $sig)) {
            return $log('invalid', 'The QR signature does not match vehicle pass ' . $no . '.', null, (int)$x['id'], $extra);
        }
        if ((int)$x['voyage_id'] !== (int)$v['id']) {
            return $log('wrong_voyage', 'Vehicle pass ' . $no . ' is for another voyage.', null, (int)$x['id'], $extra);
        }
        if ($x['status'] === 'held') {
            return $log('unpaid', 'Vehicle pass ' . $no . ' is not paid.', null, (int)$x['id'], $extra);
        }
        if (in_array($x['status'], ['cancelled', 'refunded', 'no_show'], true)) {
            return $log('cancelled', 'Vehicle pass ' . $no . ' is ' . $x['status'] . '.', null, (int)$x['id'], $extra);
        }
        if ($x['status'] === 'loaded') {
            return $log('duplicate', 'Vehicle already loaded.', null, (int)$x['id'], $extra);
        }
        if ($x['driver_ticket_id']) {
            $drv = DB::value('SELECT status FROM shp_tickets WHERE id = :t', ['t' => $x['driver_ticket_id']]);
            if (!in_array($drv, ['issued', 'checked_in', 'boarded'], true)) {
                return $log('invalid', 'The driver\'s passenger ticket is not valid; every traveller needs a valid ticket.', null, (int)$x['id'], $extra);
            }
        }
        DB::update('shp_vehicles', ['status' => 'loaded', 'loaded_at' => $at], 'id = :id', ['id' => $x['id']]);
        return $log('accepted', 'Vehicle loaded' . ($x['lane_no'] ? ' to lane ' . $x['lane_no'] : '') . '.', null, (int)$x['id'], $extra);
    }

    /** Scans queued offline: applied in the order they were made */
    public static function syncOffline(int $voyageId, array $scans): array
    {
        usort($scans, fn($a, $b) => strcmp((string)($a['scanned_at'] ?? ''), (string)($b['scanned_at'] ?? '')));
        $out = [];
        foreach (array_slice($scans, 0, 500) as $s) {
            $code = (string)($s['code'] ?? '');
            try {
                $r = self::scan($voyageId, $code, (string)($s['type'] ?? 'board'), ['device_id' => $s['device_id'] ?? null, 'scanned_at' => $s['scanned_at'] ?? null, 'offline' => true]);
            } catch (UserError $e) {
                $r = ['result' => 'invalid', 'message' => $e->getMessage(), 'ok' => false];
            }
            $out[] = ['code' => $code, 'local_id' => $s['local_id'] ?? null] + $r;
        }
        return $out;
    }

    /** Booked, paid, checked-in and boarded counts, each from its own records */
    public static function counts(int $voyageId): array
    {
        $by = DB::pairs('SELECT status, COUNT(*) FROM shp_tickets WHERE voyage_id = :v GROUP BY status', ['v' => $voyageId]);
        $g = fn(string $s) => (int)($by[$s] ?? 0);
        $veh = DB::pairs('SELECT status, COUNT(*) FROM shp_vehicles WHERE voyage_id = :v GROUP BY status', ['v' => $voyageId]);
        $vehPax = (int)DB::value("SELECT COUNT(*) FROM shp_tickets WHERE voyage_id = :v AND is_driver = 1 AND status = 'boarded'", ['v' => $voyageId]);
        return [
            'held' => $g('held'), 'paid' => $g('issued') + $g('checked_in') + $g('boarded') + $g('no_show'),
            'booked' => $g('held') + $g('issued') + $g('checked_in') + $g('boarded'),
            'checked_in' => $g('checked_in') + $g('boarded'), 'boarded' => $g('boarded'), 'no_show' => $g('no_show'),
            'cancelled' => $g('cancelled') + $g('refunded') + $g('transferred'),
            'vehicles_booked' => (int)(($veh['issued'] ?? 0) + ($veh['checked_in'] ?? 0) + ($veh['loaded'] ?? 0) + ($veh['unloaded'] ?? 0)),
            'vehicles_loaded' => (int)(($veh['loaded'] ?? 0) + ($veh['unloaded'] ?? 0)), 'vehicle_drivers_boarded' => $vehPax,
            'rejected_scans' => (int)DB::value("SELECT COUNT(*) FROM shp_boarding_scans WHERE voyage_id = :v AND result <> 'accepted'", ['v' => $voyageId]),
            'duplicate_scans' => (int)DB::value("SELECT COUNT(*) FROM shp_boarding_scans WHERE voyage_id = :v AND result IN ('duplicate', 'conflict')", ['v' => $voyageId]),
        ];
    }
}
