<?php
declare(strict_types=1);

/**
 * No-sail and restricted-sail holds on a vessel.
 *
 * A hold comes from a designated critical defect, an expired critical certificate, or is placed by hand.
 * While a no-sail hold is active no voyage of the vessel can be released for departure. Lifting it takes two
 * people: the technical release (an engineer with the technical-release permission, after the repair passed
 * its inspection) and then the management release (another person with the safety-release permission),
 * each with written evidence. The software records the decision; it never replaces the Master or the regulator.
 */
final class Holds
{
    public static function place(int $vesselId, string $type, string $sourceType, ?int $sourceId, string $reason, ?int $voyageId = null): int
    {
        if ($sourceId && ($open = DB::value("SELECT id FROM shp_holds WHERE vessel_id = :v AND source_type = :t AND source_id = :s AND status = 'active'", ['v' => $vesselId, 't' => $sourceType, 's' => $sourceId]))) {
            return (int)$open;
        }
        $id = DB::insert('shp_holds', [
            'vessel_id' => $vesselId, 'voyage_id' => $voyageId, 'hold_type' => $type === 'restricted' ? 'restricted' : 'no_sail',
            'source_type' => $sourceType, 'source_id' => $sourceId, 'reason' => mb_substr($reason, 0, 255), 'status' => 'active',
            'placed_by' => Auth::id(), 'placed_at' => Ship::now(),
        ]);
        $vessel = (string)DB::value('SELECT name FROM shp_vessels WHERE id = :v', ['v' => $vesselId]);
        Ship::audit('hold_placed', 'ops', 'shp_hold', $id, ($type === 'restricted' ? 'Restricted-sail' : 'No-sail') . ' hold on ' . $vessel, ['reason' => $reason, 'page_key' => 'ops.holds']);
        Notify::staff('hold:' . $id, 'safety_hold', ['Operations Manager', 'Fleet Manager', 'Captain / Master', 'Chief Engineer'],
            ($type === 'restricted' ? 'Restricted-sail' : 'No-sail') . ' hold on ' . $vessel, $reason, 'shp_holds', $id);
        foreach (DB::column("SELECT id FROM shp_voyages WHERE vessel_id = :v AND status IN ('published', 'on_sale', 'boarding', 'ready')", ['v' => $vesselId]) as $vid) {
            Ship::voyageEvent((int)$vid, 'hold', 'Vessel hold placed: ' . $reason);
        }
        return $id;
    }

    public static function active(int $vesselId): array
    {
        return DB::all("SELECT * FROM shp_holds WHERE vessel_id = :v AND status = 'active' ORDER BY id", ['v' => $vesselId]);
    }

    /** Expired critical certificates put their own no-sail hold on the vessel (run before readiness is checked) */
    public static function syncCertificates(?int $vesselId = null): void
    {
        $sql = 'SELECT c.*, s.name AS vessel_name FROM shp_certificates c JOIN shp_vessels s ON s.id = c.vessel_id WHERE c.is_critical = 1 AND c.expires_on IS NOT NULL AND c.expires_on < :d';
        $p = ['d' => Ship::today()];
        if ($vesselId) {
            $sql .= ' AND c.vessel_id = :v';
            $p['v'] = $vesselId;
        }
        foreach (DB::all($sql, $p) as $c) {
            self::place((int)$c['vessel_id'], 'no_sail', 'certificate', (int)$c['id'], 'Critical certificate expired: ' . $c['cert_type'] . ($c['cert_no'] ? ' ' . $c['cert_no'] : '') . ' (expired ' . fdate($c['expires_on']) . ')');
        }
        // a renewed certificate lifts its own hold (the renewal is the evidence)
        foreach (DB::all("SELECT h.id, h.source_id FROM shp_holds h JOIN shp_certificates c ON c.id = h.source_id WHERE h.source_type = 'certificate' AND h.status = 'active' AND (c.expires_on >= :d OR c.is_critical = 0)", ['d' => Ship::today()]) as $h) {
            DB::update('shp_holds', ['status' => 'released', 'released_at' => Ship::now(), 'release_evidence' => 'Certificate renewed / no longer critical.'], 'id = :id', ['id' => $h['id']]);
        }
    }

    public static function technicalRelease(int $holdId, string $evidence): void
    {
        DB::tx(function () use ($holdId, $evidence) {
            $h = DB::row('SELECT * FROM shp_holds WHERE id = :id FOR UPDATE', ['id' => $holdId]);
            if (!$h || $h['status'] !== 'active') {
                fail('The hold is not active.');
            }
            if (!Access::special('shp_technical_release')) {
                fail('The technical release is signed by an engineer with the technical-release permission.');
            }
            if (trim($evidence) === '') {
                fail('Write the inspection evidence for the technical release.');
            }
            if ($h['source_type'] === 'defect') {
                $wo = DB::row('SELECT w.* FROM shp_work_orders w WHERE w.defect_id = :d ORDER BY w.id DESC LIMIT 1', ['d' => $h['source_id']]);
                if (!$wo || !in_array($wo['status'], ['completed', 'closed'], true) || $wo['inspection_result'] !== 'pass') {
                    fail('The repair work order of this defect has to pass its inspection first.');
                }
                if ((int)$wo['executed_by'] === (int)Auth::id()) {
                    fail('You carried out the repair; another engineer signs its technical release.');
                }
            }
            if ($h['source_type'] === 'certificate') {
                fail('A certificate hold is lifted by renewing the certificate (Fleet & Maintenance > Vessel Certificates).');
            }
            DB::update('shp_holds', ['technical_release_by' => Auth::id(), 'technical_release_at' => Ship::now(),
                'release_evidence' => mb_substr('Technical: ' . $evidence, 0, 500)], 'id = :id', ['id' => $holdId]);
            Ship::audit('hold_technical_release', 'ops', 'shp_hold', $holdId, 'Technical release of hold #' . $holdId, ['reason' => $evidence, 'page_key' => 'ops.holds']);
        });
    }

    public static function release(int $holdId, string $evidence): void
    {
        DB::tx(function () use ($holdId, $evidence) {
            $h = DB::row('SELECT * FROM shp_holds WHERE id = :id FOR UPDATE', ['id' => $holdId]);
            if (!$h || $h['status'] !== 'active') {
                fail('The hold is not active.');
            }
            if (!Access::special('shp_safety_release')) {
                fail('The management release needs the safety-release permission.');
            }
            if (trim($evidence) === '') {
                fail('Write the evidence and authority for the release.');
            }
            if ($h['source_type'] === 'certificate') {
                fail('A certificate hold is lifted by renewing the certificate.');
            }
            if ($h['source_type'] === 'defect' && !$h['technical_release_by']) {
                fail('The technical release has to be signed first.');
            }
            if ((int)$h['technical_release_by'] === (int)Auth::id()) {
                fail('You signed the technical release; another person gives the management release.');
            }
            if ($h['source_type'] === 'defect') {
                $wo = DB::row('SELECT executed_by FROM shp_work_orders WHERE defect_id = :d ORDER BY id DESC LIMIT 1', ['d' => $h['source_id']]);
                if ($wo && (int)$wo['executed_by'] === (int)Auth::id()) {
                    fail('You carried out the repair; you cannot release the safety hold of your own work.');
                }
            }
            DB::update('shp_holds', ['status' => 'released', 'released_by' => Auth::id(), 'released_at' => Ship::now(),
                'release_evidence' => mb_substr(trim(($h['release_evidence'] ? $h['release_evidence'] . ' | ' : '') . 'Management: ' . $evidence), 0, 500)], 'id = :id', ['id' => $holdId]);
            if ($h['source_type'] === 'defect') {
                DB::run("UPDATE shp_defects SET status = 'closed' WHERE id = :d AND status IN ('resolved', 'work_order')", ['d' => $h['source_id']]);
            }
            Ship::audit('hold_released', 'ops', 'shp_hold', $holdId, 'Hold #' . $holdId . ' released', ['reason' => $evidence, 'page_key' => 'ops.holds']);
            foreach (DB::column("SELECT id FROM shp_voyages WHERE vessel_id = :v AND status IN ('published', 'on_sale', 'boarding', 'ready')", ['v' => $h['vessel_id']]) as $vid) {
                Ship::voyageEvent((int)$vid, 'hold', 'Vessel hold #' . $holdId . ' released: ' . $evidence);
            }
        });
    }
}
