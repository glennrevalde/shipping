<?php
declare(strict_types=1);

/**
 * Passenger, crew and vehicle manifests as numbered versions. A draft lists the paid passengers; the final
 * version lists those who actually boarded (validated scans) and supersedes the earlier finals, so every
 * correction is a new version and no version is ever overwritten. The Master signs the final.
 * The export follows the usual MARINA / PCG columns; the system makes the file, it does not file it.
 */
final class Manifest
{
    public static function generate(int $voyageId, bool $final, string $remarks = ''): int
    {
        return DB::tx(function () use ($voyageId, $final, $remarks) {
            $v = Ship::voyage($voyageId, true);
            if (!$v) {
                fail('Voyage not found.');
            }
            if ($final && !in_array($v['status'], ['boarding', 'ready', 'departed', 'arrived', 'completed'], true)) {
                fail('The final manifest is made once boarding has started.');
            }
            $c = Boarding::counts($voyageId);
            $ver = (int)DB::value('SELECT COALESCE(MAX(version_no), 0) FROM shp_manifests WHERE voyage_id = :v', ['v' => $voyageId]) + 1;
            $statuses = $final ? ['boarded'] : ['issued', 'checked_in', 'boarded'];
            [$in, $p] = DB::in('ms', $statuses);
            $p['v'] = $voyageId;
            $pax = DB::all("SELECT t.*, c.name AS cat_name, s.seat_no, a.name AS accom_name FROM shp_tickets t JOIN shp_fare_categories c ON c.id = t.category_id
                             LEFT JOIN shp_seats s ON s.id = t.seat_id LEFT JOIN shp_accom_classes a ON a.vessel_id = :vs AND a.code = t.accom_code
                            WHERE t.voyage_id = :v AND t.status IN $in ORDER BY t.last_name, t.first_name", $p + ['vs' => $v['vessel_id']]);
            $crew = DB::all('SELECT c.* FROM shp_voyage_crew vc JOIN shp_crew c ON c.id = vc.crew_id WHERE vc.voyage_id = :v AND vc.signed_on = 1 ORDER BY c.department, c.last_name', ['v' => $voyageId]);
            $veh = DB::all("SELECT x.*, k.name AS class_name FROM shp_vehicles x JOIN shp_vehicle_classes k ON k.id = x.vehicle_class_id WHERE x.voyage_id = :v AND x.status IN ('issued', 'checked_in', 'loaded', 'unloaded') ORDER BY x.lane_no, x.plate_no", ['v' => $voyageId]);
            if ($final) {
                DB::run("UPDATE shp_manifests SET status = 'superseded' WHERE voyage_id = :v AND status IN ('final', 'draft')", ['v' => $voyageId]);
            } else {
                DB::run("UPDATE shp_manifests SET status = 'superseded' WHERE voyage_id = :v AND status = 'draft'", ['v' => $voyageId]);
            }
            $mid = DB::insert('shp_manifests', [
                'voyage_id' => $voyageId, 'version_no' => $ver, 'status' => $final ? 'final' : 'draft', 'pax_booked' => $c['booked'], 'pax_checked_in' => $c['checked_in'],
                'pax_boarded' => $c['boarded'], 'crew_count' => count($crew), 'vehicle_count' => count($veh),
                'cargo_count' => (int)DB::value("SELECT COUNT(*) FROM shp_cargo WHERE voyage_id = :v AND status IN ('loaded', 'in_transit', 'landed', 'delivered')", ['v' => $voyageId]),
                'remarks' => $remarks !== '' ? mb_substr($remarks, 0, 255) : null, 'generated_by' => Auth::id(), 'generated_at' => Ship::now(),
            ]);
            $n = 0;
            $on = substr($v['etd'], 0, 10);
            foreach ($pax as $t) {
                DB::insert('shp_manifest_lines', ['manifest_id' => $mid, 'line_no' => ++$n, 'line_kind' => 'pax', 'ref_id' => $t['id'],
                    'full_name' => trim($t['last_name'] . ', ' . $t['first_name'] . ' ' . ($t['middle_name'] ?? '')), 'age' => Ship::age($t['birth_date'], $on),
                    'gender' => $t['gender'], 'nationality' => $t['nationality'], 'category' => $t['cat_name'], 'accom' => $t['accom_name'] ?: $t['accom_code'],
                    'seat_no' => $t['seat_no'], 'ref_no' => $t['ticket_no'], 'status' => $t['status']]);
            }
            foreach ($crew as $cr) {
                DB::insert('shp_manifest_lines', ['manifest_id' => $mid, 'line_no' => ++$n, 'line_kind' => 'crew', 'ref_id' => $cr['id'],
                    'full_name' => $cr['last_name'] . ', ' . $cr['first_name'], 'category' => $cr['rank_title'], 'accom' => ucfirst($cr['department']), 'ref_no' => $cr['crew_no'], 'status' => 'signed_on', 'nationality' => 'Filipino']);
            }
            foreach ($veh as $x) {
                DB::insert('shp_manifest_lines', ['manifest_id' => $mid, 'line_no' => ++$n, 'line_kind' => 'vehicle', 'ref_id' => $x['id'],
                    'full_name' => $x['plate_no'] . ' ' . ($x['make_model'] ?? ''), 'category' => $x['class_name'], 'accom' => trim(($x['deck'] ?? '') . ' ' . ($x['lane_no'] ? 'Lane ' . $x['lane_no'] : '')),
                    'ref_no' => $x['vehicle_no'], 'status' => $x['status']]);
            }
            Ship::voyageEvent($voyageId, 'note', ($final ? 'Final' : 'Draft') . ' manifest version ' . $ver . ': ' . count($pax) . ' passenger(s), ' . count($crew) . ' crew, ' . count($veh) . ' vehicle(s)');
            Ship::audit('manifest', 'tkt', 'shp_manifest', $mid, 'Manifest v' . $ver . ' of ' . $v['voyage_no'], ['page_key' => 'tkt.manifest']);
            return $mid;
        });
    }

    public static function sign(int $manifestId): void
    {
        $m = DB::row('SELECT * FROM shp_manifests WHERE id = :id', ['id' => $manifestId]);
        if (!$m || $m['status'] !== 'final') {
            fail('Only the current final manifest is signed.');
        }
        if ($m['signed_at']) {
            fail('This manifest is already signed.');
        }
        DB::update('shp_manifests', ['signed_by' => Auth::id(), 'signed_at' => Ship::now()], 'id = :id', ['id' => $manifestId]);
        Ship::voyageEvent((int)$m['voyage_id'], 'note', 'Manifest version ' . $m['version_no'] . ' signed by ' . (Auth::user()['full_name'] ?? ''));
        Ship::audit('manifest_signed', 'tkt', 'shp_manifest', $manifestId, 'Manifest v' . $m['version_no'], ['page_key' => 'tkt.manifest']);
    }

    public static function lines(int $manifestId, ?string $kind = null): array
    {
        $sql = 'SELECT * FROM shp_manifest_lines WHERE manifest_id = :m';
        $p = ['m' => $manifestId];
        if ($kind) {
            $sql .= ' AND line_kind = :k';
            $p['k'] = $kind;
        }
        return DB::all($sql . ' ORDER BY line_no', $p);
    }

    public static function latest(int $voyageId): ?array
    {
        return DB::row("SELECT * FROM shp_manifests WHERE voyage_id = :v AND status <> 'superseded' ORDER BY version_no DESC LIMIT 1", ['v' => $voyageId]);
    }
}
