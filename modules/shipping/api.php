<?php
declare(strict_types=1);

/**
 * JSON calls of the ticketing pages (open route shp.api). The user is already logged in (index.php) and every
 * POST has passed the session's CSRF token check (verify_csrf); each action checks its own page access here.
 *
 *   GET  a=seatmap  voyage, class        the seats of an accommodation class, with the ones taken     (Booking Desk / Bookings)
 *   POST a=quote    voyage_id, pax[], veh[], promo_code, override[]   itemized price, nothing saved    (Booking Desk)
 *   POST a=scan     voyage_id, code, type, device_id                  one QR / ticket-number scan        (Boarding Scanner)
 *   POST a=sync     voyage_id, scans (JSON list)                      scans kept offline, in scan order  (Boarding Scanner)
 *   GET  a=counts   voyage                                            live boarding counts               (Boarding Scanner)
 */

$act = (string)($_GET['a'] ?? $_POST['a'] ?? '');

if (!function_exists('shp_api_deny')) {
    function shp_api_deny(string $what): never
    {
        json_out(['ok' => false, 'error' => 'Your role cannot ' . $what . '.'], 403);
    }

    function shp_api_need_post(): void
    {
        if (!is_post()) {
            json_out(['ok' => false, 'error' => 'This call has to be sent as POST.'], 405);
        }
    }

    function shp_api_counts(int $voyageId): array
    {
        $v = Ship::voyage($voyageId);
        $c = Boarding::counts($voyageId);
        return $c + ['limit' => $v ? Booking::paxLimit($v) : 0, 'status' => $v['status'] ?? ''];
    }
}

try {
    switch ($act) {
        case 'seatmap':
            if (!Access::page('tkt.booking') && !Access::page('tkt.bookings')) {
                shp_api_deny('see the seat map');
            }
            $v = Ship::voyage((int)($_GET['voyage'] ?? 0));
            if (!$v) {
                fail('Voyage not found.');
            }
            $class = DB::row('SELECT * FROM shp_accom_classes WHERE vessel_id = :v AND code = :c', ['v' => $v['vessel_id'], 'c' => (string)($_GET['class'] ?? '')]);
            if (!$class) {
                fail('That accommodation class is not on ' . $v['vessel_name'] . '.');
            }
            $seats = [];
            foreach (Fleet::seatMap((int)$v['id'], (int)$class['id']) as $s) {
                $seats[] = ['id' => (int)$s['id'], 'no' => $s['seat_no'], 'r' => (int)$s['seat_row'], 'c' => (int)$s['seat_col'], 'p' => (int)$s['is_priority'], 't' => (int)$s['taken']];
            }
            json_out(['ok' => true, 'class' => $class['name'], 'code' => $class['code'], 'deck' => $class['deck'], 'reserved' => (int)$class['reserved_seating'],
                'rows' => (int)$class['seat_rows'], 'cols' => (int)$class['seat_cols'], 'seats' => $seats]);

        case 'quote':
            shp_api_need_post();
            if (!Access::can('tkt.booking', 'create')) {
                shp_api_deny('price a booking');
            }
            $v = Ship::voyage((int)p('voyage_id'));
            if (!$v) {
                fail('Choose a voyage.');
            }
            $notes = [];
            $promo = null;
            try {
                $promo = Fares::promo((string)p('promo_code'), (int)$v['route_id'], substr($v['etd'], 0, 10));
            } catch (UserError $e) {
                $notes[] = $e->getMessage();
            }
            $canOverride = (bool)Access::special('shp_fare_override');
            $cap = Booking::capacity($v);
            $lines = [];
            $need = [];
            $sum = ['gross' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'vat' => 0.0, 'surcharge' => 0.0, 'fees' => 0.0, 'total' => 0.0];
            foreach ((array)($_POST['pax'] ?? []) as $i => $px) {
                if (!is_array($px)) {
                    continue;
                }
                $code = (string)($px['accom_code'] ?? '');
                $catId = (int)($px['category_id'] ?? 0);
                $name = trim(trim((string)($px['last_name'] ?? '')) . ', ' . trim((string)($px['first_name'] ?? '')), ', ');
                if (!isset($cap[$code]) || !$catId) {
                    $lines[] = ['key' => (string)$i, 'label' => $name ?: 'Passenger', 'error' => 'Choose the category and the accommodation.'];
                    continue;
                }
                $need[$code] = ($need[$code] ?? 0) + 1;
                $ov = $_POST['override'][$i] ?? '';
                $ov = $canOverride && is_numeric($ov) ? (float)$ov : null;
                try {
                    $cat = Fares::category($catId);
                    $q = Fares::quotePax($v, $code, $cat, $promo, $ov);
                } catch (UserError $e) {
                    $lines[] = ['key' => (string)$i, 'label' => $name ?: 'Passenger', 'error' => $e->getMessage()];
                    continue;
                }
                foreach ($q['notes'] as $n) {
                    $notes[] = $n;
                }
                $fees = array_map(fn($f) => ['name' => $f['name'], 'amount' => $f['amount'], 'owner' => $f['ownership']], $q['fees']);
                $lines[] = ['key' => (string)$i, 'kind' => 'pax', 'label' => $name ?: 'Passenger', 'sub' => $cat['name'] . ', ' . $cap[$code]['name'],
                    'gross' => $q['fare_gross'], 'discount' => r2($q['discount'] + $q['promo']), 'net' => $q['fare_net'], 'vat' => $q['vat'],
                    'surcharge' => $q['surcharge_net'], 'fees' => $fees, 'fees_total' => $q['fees_total'], 'total' => $q['total'], 'vat_exempt' => $q['vat_exempt'],
                    'override' => $ov !== null];
                $sum['gross'] += $q['fare_gross'];
                $sum['discount'] += $q['discount'] + $q['promo'];
                $sum['net'] += $q['fare_net'];
                $sum['vat'] += $q['vat'];
                $sum['surcharge'] += $q['surcharge_net'];
                $sum['fees'] += $q['fees_total'];
                $sum['total'] += $q['total'];
            }
            foreach ((array)($_POST['veh'] ?? []) as $i => $x) {
                if (!is_array($x) || !(int)($x['vehicle_class_id'] ?? 0)) {
                    continue;
                }
                try {
                    $q = Fares::quoteVehicle($v, (int)$x['vehicle_class_id']);
                } catch (UserError $e) {
                    $lines[] = ['key' => 'v' . $i, 'label' => 'Vehicle', 'error' => $e->getMessage()];
                    continue;
                }
                $cls = (string)DB::value('SELECT name FROM shp_vehicle_classes WHERE id = :id', ['id' => (int)$x['vehicle_class_id']]);
                $fees = array_map(fn($f) => ['name' => $f['name'], 'amount' => $f['amount'], 'owner' => $f['ownership']], $q['fees']);
                $lines[] = ['key' => 'v' . $i, 'kind' => 'vehicle', 'label' => strtoupper(trim((string)($x['plate_no'] ?? ''))) ?: 'Vehicle', 'sub' => $cls,
                    'gross' => $q['fare_gross'], 'discount' => 0, 'net' => $q['fare_net'], 'vat' => $q['vat'], 'surcharge' => 0, 'fees' => $fees,
                    'fees_total' => $q['fees_total'], 'total' => $q['total'], 'vat_exempt' => false, 'override' => false];
                $sum['gross'] += $q['fare_gross'];
                $sum['net'] += $q['fare_net'];
                $sum['vat'] += $q['vat'];
                $sum['fees'] += $q['fees_total'];
                $sum['total'] += $q['total'];
            }
            foreach ($need as $code => $n) {
                if ($n > $cap[$code]['left']) {
                    $notes[] = 'Only ' . $cap[$code]['left'] . ' place(s) left in ' . $cap[$code]['name'] . '; this booking needs ' . $n . '.';
                }
            }
            json_out(['ok' => true, 'lines' => $lines, 'totals' => array_map('r2', $sum), 'notes' => array_values(array_unique($notes)),
                'promo' => $promo ? $promo['code'] . ' (' . qty($promo['discount_pct']) . '% off)' : null]);

        case 'scan':
            shp_api_need_post();
            if (!Access::can('tkt.boarding', 'create')) {
                shp_api_deny('scan tickets');
            }
            $voyageId = (int)p('voyage_id');
            $type = p('type') === 'checkin' ? 'checkin' : 'board';
            $r = Boarding::scan($voyageId, (string)p('code'), $type, ['device_id' => (string)p('device_id')]);
            json_out(['ok' => true, 'scan' => $r, 'counts' => shp_api_counts($voyageId)]);

        case 'sync':
            shp_api_need_post();
            if (!Access::can('tkt.boarding', 'create')) {
                shp_api_deny('scan tickets');
            }
            if (Ship::setting('offline_scans', '1') !== '1') {
                fail('Offline scanning is switched off in the shipping policies; the scans kept on this device were not applied.');
            }
            $voyageId = (int)p('voyage_id');
            $scans = json_decode((string)($_POST['scans'] ?? '[]'), true);
            if (!is_array($scans)) {
                fail('The offline scans could not be read.');
            }
            $res = Boarding::syncOffline($voyageId, $scans);
            json_out(['ok' => true, 'results' => $res, 'counts' => shp_api_counts($voyageId)]);

        case 'counts':
            if (!Access::page('tkt.boarding')) {
                shp_api_deny('see the boarding counts');
            }
            $voyageId = (int)($_GET['voyage'] ?? 0);
            json_out(['ok' => true, 'counts' => shp_api_counts($voyageId)]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown call.'], 404);
    }
} catch (UserError $e) {
    if (DB::inTransaction()) {
        DB::rollBack();
    }
    json_out(['ok' => false, 'error' => $e->getMessage()], 422);
}
