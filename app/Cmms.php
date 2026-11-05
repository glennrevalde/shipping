<?php
declare(strict_types=1);

/**
 * Planned and corrective maintenance (CMMS): equipment hierarchy, running hours, planned maintenance by calendar
 * and / or running hours, defect reports, and work orders through reported > triaged > approved > planned >
 * waiting parts > in progress > inspection > completed > closed (or deferred / cancelled, with reasons).
 *
 * Costs go to the books as they happen, all with the vessel's class and linked to the work order:
 *   labor       journal: Vessel Repairs (or Capital WIP) / Internal Labor Absorbed
 *   spare parts inventory adjustment: Spare Parts Consumed (or Capital WIP) / Inventory, never below zero
 *   contractor  bill to the contractor: Vessel Repairs (or Capital WIP) / Accounts Payable
 *
 * Separation of duties: the technician who did the work cannot inspect it, and nobody accepts (closes) their own
 * work. A designated safety-critical defect puts a no-sail hold on the vessel until two people release it.
 */
final class Cmms
{
    // ------------------------------------------------------------------ equipment and meters

    public static function equipmentOptions(?int $vesselId = null): array
    {
        $sql = 'SELECT e.id, CONCAT(s.code, " ", e.code, " ", e.name) FROM shp_equipment e JOIN shp_vessels s ON s.id = e.vessel_id WHERE e.is_active = 1';
        $p = [];
        if ($vesselId) {
            $sql .= ' AND e.vessel_id = :v';
            $p['v'] = $vesselId;
        }
        return DB::pairs($sql . ' ORDER BY s.code, e.code', $p);
    }

    public static function reading(int $equipmentId, float $hours, ?string $at = null, ?int $voyageId = null): void
    {
        $e = DB::row('SELECT * FROM shp_equipment WHERE id = :id', ['id' => $equipmentId]);
        if (!$e) {
            fail('Equipment not found.');
        }
        if ($hours < (float)$e['running_hours']) {
            fail('The reading (' . qty($hours) . ' h) is lower than the last one (' . qty($e['running_hours']) . ' h). Running hours only go up.');
        }
        $at = $at ?? Ship::now();
        DB::insert('shp_meter_readings', ['equipment_id' => $equipmentId, 'reading' => $hours, 'read_at' => $at, 'voyage_id' => $voyageId, 'user_id' => Auth::id()]);
        DB::update('shp_equipment', ['running_hours' => $hours, 'hours_read_at' => $at], 'id = :id', ['id' => $equipmentId]);
    }

    // ------------------------------------------------------------------ planned maintenance

    /** Plans due now or within their lead time: [plan, due by date, due by hours, overdue] */
    public static function pmDue(?int $vesselId = null): array
    {
        $sql = 'SELECT p.*, e.code AS eq_code, e.name AS eq_name, e.running_hours, s.name AS vessel_name, s.code AS vessel_code
                  FROM shp_pm_plans p JOIN shp_vessels s ON s.id = p.vessel_id LEFT JOIN shp_equipment e ON e.id = p.equipment_id WHERE p.is_active = 1';
        $par = [];
        if ($vesselId) {
            $sql .= ' AND p.vessel_id = :v';
            $par['v'] = $vesselId;
        }
        $out = [];
        foreach (DB::all($sql . ' ORDER BY p.next_due_on', $par) as $p) {
            $byDate = $p['next_due_on'] && $p['next_due_on'] <= date('Y-m-d', strtotime(Ship::today() . ' +' . (int)$p['lead_days'] . ' days'));
            $byHours = $p['next_due_hours'] !== null && $p['running_hours'] !== null && (float)$p['running_hours'] >= (float)$p['next_due_hours'] - max(0, (float)$p['interval_hours'] * 0.05);
            if ($byDate || $byHours) {
                $overdue = ($p['next_due_on'] && $p['next_due_on'] < Ship::today()) || ($p['next_due_hours'] !== null && (float)$p['running_hours'] > (float)$p['next_due_hours']);
                $out[] = $p + ['due_by_date' => $byDate, 'due_by_hours' => $byHours, 'overdue' => $overdue];
            }
        }
        return $out;
    }

    /** Raises a preventive work order for each plan that is due and has none open. Returns the ids made. */
    public static function raiseDue(?int $vesselId = null): array
    {
        $made = [];
        foreach (self::pmDue($vesselId) as $p) {
            if ($p['open_wo_id'] && DB::value("SELECT 1 FROM shp_work_orders WHERE id = :id AND status NOT IN ('closed', 'cancelled')", ['id' => $p['open_wo_id']])) {
                continue;
            }
            $wo = self::createWo([
                'vessel_id' => $p['vessel_id'], 'equipment_id' => $p['equipment_id'], 'pm_plan_id' => $p['id'], 'wo_type' => (int)$p['is_regulatory'] === 1 ? 'survey' : 'preventive',
                'title' => $p['name'], 'description' => 'Planned maintenance due ' . ($p['next_due_on'] ? fdate($p['next_due_on']) : '') . ($p['next_due_hours'] !== null ? ' / at ' . qty($p['next_due_hours']) . ' h' : ''),
                'priority' => $p['overdue'] ? 'high' : 'normal', 'checklist' => $p['checklist'], 'status' => 'approved',
            ]);
            DB::update('shp_pm_plans', ['open_wo_id' => $wo], 'id = :id', ['id' => $p['id']]);
            $made[] = $wo;
        }
        return $made;
    }

    // ------------------------------------------------------------------ defects

    public static function reportDefect(array $d): int
    {
        $vesselId = (int)($d['vessel_id'] ?? 0);
        if (!DB::value('SELECT 1 FROM shp_vessels WHERE id = :id', ['id' => $vesselId])) {
            fail('Choose the vessel.');
        }
        if (trim((string)($d['title'] ?? '')) === '') {
            fail('Describe the defect in a few words.');
        }
        $sev = in_array($d['severity'] ?? '', ['minor', 'major', 'critical'], true) ? $d['severity'] : 'minor';
        $critical = !empty($d['safety_critical']) ? 1 : 0;
        return DB::tx(function () use ($d, $vesselId, $sev, $critical) {
            $id = DB::insert('shp_defects', [
                'defect_no' => Ship::no('shp_defect', 'DR-', 6), 'vessel_id' => $vesselId, 'equipment_id' => !empty($d['equipment_id']) ? (int)$d['equipment_id'] : null,
                'title' => mb_substr(trim((string)$d['title']), 0, 160), 'description' => $d['description'] ?? null, 'severity' => $sev, 'safety_critical' => $critical,
                'operating_impact' => $d['operating_impact'] ?? null, 'photo_path' => $d['photo_path'] ?? null, 'status' => 'reported',
                'reported_by' => Auth::id(), 'reported_at' => $d['reported_at'] ?? Ship::now(),
            ]);
            $no = (string)DB::value('SELECT defect_no FROM shp_defects WHERE id = :id', ['id' => $id]);
            if ($critical) {
                $hold = Holds::place($vesselId, 'no_sail', 'defect', $id, 'Safety-critical defect ' . $no . ': ' . $d['title']);
                DB::update('shp_defects', ['hold_id' => $hold], 'id = :id', ['id' => $id]);
            }
            Ship::audit('create', 'fleet', 'shp_defect', $id, 'Defect ' . $no . ' (' . $sev . ($critical ? ', safety-critical' : '') . ')', ['page_key' => 'fleet.defects']);
            Notify::staff('defect:' . $id, 'defect', ['Fleet Manager', 'Chief Engineer', 'Maintenance Planner'], 'Defect ' . $no . ' reported' . ($critical ? ' (safety-critical: no-sail hold)' : ''),
                (string)$d['title'], 'shp_defects', $id);
            return $id;
        });
    }

    /** Triage: confirm the severity and the designation, and raise the work order */
    public static function triage(int $defectId, string $severity, bool $critical, bool $raiseWo, ?int $assignTo = null, string $notes = ''): ?int
    {
        return DB::tx(function () use ($defectId, $severity, $critical, $raiseWo, $assignTo, $notes) {
            $d = DB::row('SELECT * FROM shp_defects WHERE id = :id FOR UPDATE', ['id' => $defectId]);
            if (!$d || !in_array($d['status'], ['reported', 'triaged'], true)) {
                fail('Only a reported defect is triaged.');
            }
            DB::update('shp_defects', ['severity' => $severity, 'safety_critical' => $critical ? 1 : 0, 'status' => $raiseWo ? 'work_order' : 'triaged',
                'triaged_by' => Auth::id(), 'triaged_at' => Ship::now()], 'id = :id', ['id' => $defectId]);
            if ($critical && !$d['hold_id']) {
                $hold = Holds::place((int)$d['vessel_id'], 'no_sail', 'defect', $defectId, 'Safety-critical defect ' . $d['defect_no'] . ': ' . $d['title']);
                DB::update('shp_defects', ['hold_id' => $hold], 'id = :id', ['id' => $defectId]);
            }
            $wo = null;
            if ($raiseWo) {
                $wo = self::createWo(['vessel_id' => $d['vessel_id'], 'equipment_id' => $d['equipment_id'], 'defect_id' => $defectId, 'wo_type' => 'corrective',
                    'title' => 'Repair: ' . $d['title'], 'description' => trim(($d['description'] ?? '') . ($notes ? "\nTriage: " . $notes : '')),
                    'priority' => $severity === 'critical' ? 'urgent' : ($severity === 'major' ? 'high' : 'normal'), 'safety_critical' => $critical ? 1 : 0,
                    'assigned_to' => $assignTo, 'status' => 'triaged']);
                DB::update('shp_defects', ['work_order_id' => $wo], 'id = :id', ['id' => $defectId]);
            }
            Ship::audit('triage', 'fleet', 'shp_defect', $defectId, 'Defect ' . $d['defect_no'] . ' triaged as ' . $severity . ($critical ? ', safety-critical' : ''), ['reason' => $notes, 'page_key' => 'fleet.defects']);
            return $wo;
        });
    }

    // ------------------------------------------------------------------ work orders

    public static function createWo(array $d): int
    {
        $vesselId = (int)($d['vessel_id'] ?? 0);
        if (!DB::value('SELECT 1 FROM shp_vessels WHERE id = :id', ['id' => $vesselId])) {
            fail('Choose the vessel.');
        }
        if (trim((string)($d['title'] ?? '')) === '') {
            fail('Enter the title of the work.');
        }
        $status = in_array($d['status'] ?? 'reported', ['reported', 'triaged', 'approved'], true) ? ($d['status'] ?? 'reported') : 'reported';
        $id = DB::insert('shp_work_orders', [
            'wo_no' => Ship::no('shp_wo', 'WO-', 6), 'vessel_id' => $vesselId, 'equipment_id' => !empty($d['equipment_id']) ? (int)$d['equipment_id'] : null,
            'defect_id' => $d['defect_id'] ?? null, 'pm_plan_id' => $d['pm_plan_id'] ?? null, 'drydock_id' => !empty($d['drydock_id']) ? (int)$d['drydock_id'] : null,
            'wo_type' => $d['wo_type'] ?? 'corrective', 'title' => mb_substr(trim((string)$d['title']), 0, 160), 'description' => $d['description'] ?? null,
            'priority' => in_array($d['priority'] ?? '', ['low', 'normal', 'high', 'urgent'], true) ? $d['priority'] : 'normal',
            'safety_critical' => !empty($d['safety_critical']) ? 1 : 0, 'status' => $status,
            'planned_start' => !empty($d['planned_start']) ? $d['planned_start'] : null, 'planned_end' => !empty($d['planned_end']) ? $d['planned_end'] : null,
            'assigned_to' => !empty($d['assigned_to']) ? (int)$d['assigned_to'] : null, 'expense_mode' => ($d['expense_mode'] ?? 'opex') === 'capex' ? 'capex' : 'opex',
            'checklist' => $d['checklist'] ?? null, 'approved_by' => $status === 'approved' ? Auth::id() : null, 'approved_at' => $status === 'approved' ? Ship::now() : null,
            'created_by' => Auth::id(),
        ]);
        DB::insert('shp_wo_log', ['wo_id' => $id, 'to_status' => $status, 'details' => 'Work order raised', 'user_id' => Auth::id()]);
        Ship::audit('create', 'fleet', 'shp_wo', $id, 'Work order ' . DB::value('SELECT wo_no FROM shp_work_orders WHERE id = :id', ['id' => $id]), ['page_key' => 'fleet.workorders']);
        if (!empty($d['assigned_to'])) {
            Notify::user((int)$d['assigned_to'], 'wo:' . $id . ':assigned', 'wo_assigned', 'Work order assigned to you', (string)$d['title'], 'shp_work_orders', $id);
        }
        return $id;
    }

    public static function wo(int $id): ?array
    {
        return DB::row('SELECT w.*, s.name AS vessel_name, s.code AS vessel_code, e.code AS eq_code, e.name AS eq_name, d.defect_no
                          FROM shp_work_orders w JOIN shp_vessels s ON s.id = w.vessel_id LEFT JOIN shp_equipment e ON e.id = w.equipment_id
                          LEFT JOIN shp_defects d ON d.id = w.defect_id WHERE w.id = :id', ['id' => $id]);
    }

    public static function transition(int $id, string $to, array $ctx = []): void
    {
        DB::tx(function () use ($id, $to, $ctx) {
            $w = DB::row('SELECT * FROM shp_work_orders WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$w) {
                fail('Work order not found.');
            }
            Ship::assertFlow(Ship::WO_FLOW, $w['status'], $to, 'the work order');
            $me = (int)Auth::id();
            $upd = ['status' => $to, 'updated_at' => Ship::now()];
            $note = trim((string)($ctx['note'] ?? ''));
            switch ($to) {
                case 'approved':
                    if (!Access::can('fleet.workorders', 'approve')) {
                        Access::denyPage('fleet.workorders', 'approve work orders on');
                    }
                    $upd['approved_by'] = $me;
                    $upd['approved_at'] = Ship::now();
                    break;
                case 'deferred':
                    if ($note === '' || empty($ctx['until'])) {
                        fail('Give the reason and the date the work is deferred to.');
                    }
                    $upd['deferral_reason'] = mb_substr($note, 0, 255);
                    $upd['deferral_until'] = $ctx['until'];
                    if ((int)$w['safety_critical'] === 1) {
                        // a critical item is deferred only with another person's approval; the vessel hold stays meanwhile
                        unset($upd['status']);
                        DB::update('shp_work_orders', ['deferral_reason' => $upd['deferral_reason'], 'deferral_until' => $upd['deferral_until']], 'id = :id', ['id' => $id]);
                        ShipApproval::request('wo_deferral', 'shp_work_orders', $id, $w['wo_no'], null, 'Defer safety-critical work ' . $w['wo_no'] . ' to ' . fdate($ctx['until']) . ': ' . $note);
                        DB::insert('shp_wo_log', ['wo_id' => $id, 'from_status' => $w['status'], 'to_status' => $w['status'], 'details' => 'Deferral requested (needs approval): ' . $note, 'user_id' => $me]);
                        return;
                    }
                    break;
                case 'in_progress':
                    $upd['actual_start'] = $w['actual_start'] ?: ($ctx['at'] ?? Ship::now());
                    $upd['executed_by'] = $w['executed_by'] ?: $me;
                    break;
                case 'inspection':
                    if (!DB::value('SELECT 1 FROM shp_wo_labor WHERE wo_id = :w', ['w' => $id]) && $w['wo_type'] !== 'inspection') {
                        fail('Log the labor hours of the work before sending it for inspection.');
                    }
                    $upd['actual_end'] = $ctx['at'] ?? Ship::now();
                    foreach (['findings', 'root_cause', 'repair_procedure', 'reading_before', 'reading_after'] as $k) {
                        if (isset($ctx[$k]) && $ctx[$k] !== '') {
                            $upd[$k] = mb_substr((string)$ctx[$k], 0, 500);
                        }
                    }
                    break;
                case 'completed':
                    $result = (string)($ctx['inspection_result'] ?? '');
                    if ($result !== 'pass') {
                        fail('Record a passed inspection to complete the work (a failed inspection sends it back to In progress).');
                    }
                    $workers = array_map('intval', DB::column('SELECT user_id FROM shp_wo_labor WHERE wo_id = :w AND user_id IS NOT NULL', ['w' => $id]));
                    if ((int)$w['executed_by'] === $me || in_array($me, $workers, true)) {
                        fail('You worked on this job; another person inspects it.');
                    }
                    if ((int)$w['safety_critical'] === 1 && !Access::special('shp_technical_release')) {
                        fail('Safety-critical work is inspected by an engineer with the technical-release permission.');
                    }
                    $upd['inspected_by'] = $me;
                    $upd['inspected_at'] = Ship::now();
                    $upd['inspection_result'] = 'pass';
                    $upd['inspection_notes'] = $note !== '' ? mb_substr($note, 0, 500) : null;
                    if ($w['defect_id']) {
                        DB::run("UPDATE shp_defects SET status = 'resolved' WHERE id = :d AND status IN ('work_order', 'triaged', 'reported')", ['d' => $w['defect_id']]);
                    }
                    break;
                case 'closed':
                    $workers = array_map('intval', DB::column('SELECT user_id FROM shp_wo_labor WHERE wo_id = :w AND user_id IS NOT NULL', ['w' => $id]));
                    if ((int)$w['executed_by'] === $me || in_array($me, $workers, true)) {
                        fail('Nobody accepts their own work: another person closes the work order.');
                    }
                    $upd['accepted_by'] = $me;
                    $upd['accepted_at'] = Ship::now();
                    if ($w['actual_start'] && $w['actual_end']) {
                        $upd['downtime_hours'] = isset($ctx['downtime_hours']) && $ctx['downtime_hours'] !== '' ? (float)$ctx['downtime_hours'] : round((strtotime($w['actual_end']) - strtotime($w['actual_start'])) / 3600, 2);
                    }
                    if ($w['pm_plan_id']) {
                        self::completePlan((int)$w['pm_plan_id'], substr((string)($w['actual_end'] ?: Ship::now()), 0, 10));
                    }
                    break;
            }
            if ($to === 'in_progress' && $w['status'] === 'inspection') {
                $upd['inspection_result'] = 'fail';
                $upd['inspection_notes'] = $note !== '' ? mb_substr($note, 0, 500) : 'Failed inspection';
                $upd['inspected_by'] = $me;
                $upd['inspected_at'] = Ship::now();
            }
            if (isset($ctx['checklist_done'])) {
                $upd['checklist_done'] = (string)$ctx['checklist_done'];
            }
            DB::update('shp_work_orders', $upd, 'id = :id', ['id' => $id]);
            DB::insert('shp_wo_log', ['wo_id' => $id, 'from_status' => $w['status'], 'to_status' => $to, 'details' => $note !== '' ? mb_substr($note, 0, 500) : null, 'user_id' => $me]);
            Ship::audit('status', 'fleet', 'shp_wo', $id, $w['wo_no'] . ': ' . $w['status'] . ' to ' . $to, ['reason' => $note, 'page_key' => 'fleet.workorders']);
        });
    }

    private static function completePlan(int $planId, string $doneOn): void
    {
        $p = DB::row('SELECT p.*, e.running_hours FROM shp_pm_plans p LEFT JOIN shp_equipment e ON e.id = p.equipment_id WHERE p.id = :id', ['id' => $planId]);
        if (!$p) {
            return;
        }
        $hours = $p['running_hours'] !== null ? (float)$p['running_hours'] : null;
        DB::update('shp_pm_plans', [
            'last_done_on' => $doneOn, 'last_done_hours' => $hours,
            'next_due_on' => $p['interval_days'] ? date('Y-m-d', strtotime($doneOn . ' +' . (int)$p['interval_days'] . ' days')) : null,
            'next_due_hours' => $p['interval_hours'] && $hours !== null ? $hours + (float)$p['interval_hours'] : null, 'open_wo_id' => null,
        ], 'id = :id', ['id' => $planId]);
    }

    public static function deferralDecision(int $woId, bool $approve, string $notes): void
    {
        $w = DB::row('SELECT * FROM shp_work_orders WHERE id = :id FOR UPDATE', ['id' => $woId]);
        if (!$approve) {
            DB::insert('shp_wo_log', ['wo_id' => $woId, 'from_status' => $w['status'], 'to_status' => $w['status'], 'details' => 'Deferral rejected: ' . $notes, 'user_id' => Auth::id()]);
            return;
        }
        DB::update('shp_work_orders', ['status' => 'deferred', 'deferral_approved_by' => Auth::id(), 'updated_at' => Ship::now()], 'id = :id', ['id' => $woId]);
        DB::insert('shp_wo_log', ['wo_id' => $woId, 'from_status' => $w['status'], 'to_status' => 'deferred', 'details' => 'Deferral approved (the vessel hold stays until released): ' . $notes, 'user_id' => Auth::id()]);
    }

    private static function costAccount(array $w, string $kind): int
    {
        if ($w['expense_mode'] === 'capex') {
            return Accts::get('shp_capex_wip');
        }
        return Accts::get($kind === 'parts' ? 'shp_spares_expense' : 'shp_repairs_expense');
    }

    private static function assertOpen(array $w): void
    {
        if (!in_array($w['status'], ['approved', 'planned', 'waiting_parts', 'in_progress', 'inspection'], true)) {
            fail('Costs are booked on an approved work order that is not completed yet.');
        }
    }

    public static function addLabor(int $woId, string $worker, ?int $userId, string $date, float $hours, ?float $rate = null, bool $overtime = false, string $notes = ''): int
    {
        return DB::tx(function () use ($woId, $worker, $userId, $date, $hours, $rate, $overtime, $notes) {
            $w = DB::row('SELECT * FROM shp_work_orders WHERE id = :id FOR UPDATE', ['id' => $woId]);
            self::assertOpen($w);
            if ($hours <= 0 || $hours > 24) {
                fail('Enter the hours worked (more than 0, at most 24 a day).');
            }
            if (trim($worker) === '') {
                fail('Enter who did the work.');
            }
            $rate ??= (float)Ship::setting('labor_rate', '250');
            $amount = r2($hours * $rate * ($overtime ? 1.25 : 1));
            $lid = DB::insert('shp_wo_labor', ['wo_id' => $woId, 'user_id' => $userId, 'worker_name' => mb_substr($worker, 0, 120), 'work_date' => $date,
                'hours' => $hours, 'rate' => $rate, 'amount' => $amount, 'is_overtime' => $overtime ? 1 : 0, 'notes' => $notes ?: null]);
            if (Ship::setting('post_labor', '1') === '1' && $amount > 0) {
                $class = ShipGL::vesselClass((int)$w['vessel_id']);
                $memo = 'Labor on ' . $w['wo_no'] . ': ' . $worker . ' ' . qty($hours) . ' h';
                $txn = ShipGL::once('wo:' . $woId . ':labor:' . $lid, ['source_type' => 'wo', 'source_id' => $woId, 'vessel_id' => (int)$w['vessel_id'], 'work_order_id' => $woId, 'memo' => $memo],
                    fn() => ShipGL::journal($date, [ShipGL::jl(self::costAccount($w, 'labor'), $amount, $memo, $class), ShipGL::jl(Accts::get('shp_labor_absorbed'), -$amount, $memo, $class)], $memo, $class, $w['wo_no']));
                DB::update('shp_wo_labor', ['txn_id' => $txn], 'id = :id', ['id' => $lid]);
            }
            DB::run('UPDATE shp_work_orders SET cost_labor = cost_labor + :a WHERE id = :id', ['a' => $amount, 'id' => $woId]);
            return $lid;
        });
    }

    /** Issues spare parts from stock to the job: never more than is on hand at the site */
    public static function issuePart(int $woId, int $itemId, int $siteId, float $qty, ?string $date = null): int
    {
        return DB::tx(function () use ($woId, $itemId, $siteId, $qty, $date) {
            $w = DB::row('SELECT * FROM shp_work_orders WHERE id = :id FOR UPDATE', ['id' => $woId]);
            self::assertOpen($w);
            $item = DB::row("SELECT * FROM items WHERE id = :i AND item_type = 'inventory'", ['i' => $itemId]);
            if (!$item) {
                fail('Choose a stocked spare part.');
            }
            $class = ShipGL::vesselClass((int)$w['vessel_id']);
            $date = $date ?? Ship::today();
            $memo = 'Issued to ' . $w['wo_no'] . ': ' . qty($qty) . ' ' . ($item['unit'] ?: 'pc') . ' ' . $item['name'];
            $pid = DB::insert('shp_wo_parts', ['wo_id' => $woId, 'item_id' => $itemId, 'site_id' => $siteId, 'qty' => $qty, 'issued_by' => Auth::id(), 'issued_at' => Ship::now()]);
            $res = [0, 0.0];
            $txn = ShipGL::once('wo:' . $woId . ':part:' . $pid, ['source_type' => 'wo', 'source_id' => $woId, 'vessel_id' => (int)$w['vessel_id'], 'work_order_id' => $woId, 'memo' => $memo],
                function () use ($date, $siteId, $itemId, $qty, $w, $class, $memo, &$res) {
                    $res = ShipGL::issueStock($date, $siteId, $itemId, $qty, self::costAccount($w, 'parts'), $class, $memo, $w['wo_no']);
                    return $res[0];
                });
            DB::update('shp_wo_parts', ['txn_id' => $txn, 'amount' => $res[1], 'unit_cost' => $qty > 0 ? round($res[1] / $qty, 4) : 0], 'id = :id', ['id' => $pid]);
            DB::run('UPDATE shp_work_orders SET cost_parts = cost_parts + :a WHERE id = :id', ['a' => $res[1], 'id' => $woId]);
            if ($w['status'] === 'waiting_parts') {
                DB::insert('shp_wo_log', ['wo_id' => $woId, 'from_status' => $w['status'], 'to_status' => $w['status'], 'details' => 'Parts issued: ' . $memo, 'user_id' => Auth::id()]);
            }
            return $pid;
        });
    }

    /** A contractor's service on the job: a bill to the contractor (a supplier invoice is entered only once) */
    public static function addService(int $woId, int $vendorId, string $desc, float $amount, string $refNo, bool $vatable = true, ?string $date = null): int
    {
        return DB::tx(function () use ($woId, $vendorId, $desc, $amount, $refNo, $vatable, $date) {
            $w = DB::row('SELECT * FROM shp_work_orders WHERE id = :id FOR UPDATE', ['id' => $woId]);
            self::assertOpen($w);
            if ($amount <= 0 || trim($desc) === '' || trim($refNo) === '') {
                fail('Enter the service, its amount (before VAT) and the contractor\'s invoice number.');
            }
            $class = ShipGL::vesselClass((int)$w['vessel_id']);
            $tax = $vatable ? r2($amount * ShipGL::vatRate() / 100) : 0.0;
            $memo = $w['wo_no'] . ': ' . $desc;
            $sid = DB::insert('shp_wo_services', ['wo_id' => $woId, 'vendor_id' => $vendorId, 'description' => mb_substr($desc, 0, 160), 'ref_no' => $refNo,
                'amount' => r2($amount), 'tax_code_id' => $vatable ? ShipGL::taxCode('VAT12') : ShipGL::taxCode('NT'), 'tax_amount' => $tax]);
            $txn = ShipGL::once('wo:' . $woId . ':service:' . $sid, ['source_type' => 'wo', 'source_id' => $woId, 'vessel_id' => (int)$w['vessel_id'], 'work_order_id' => $woId, 'memo' => $memo],
                fn() => ShipGL::bill($vendorId, $date ?? Ship::today(), $refNo, [['account_id' => self::costAccount($w, 'service'), 'amount' => $amount,
                    'tax_code_id' => $vatable ? ShipGL::taxCode('VAT12') : ShipGL::taxCode('NT'), 'tax_amount' => $tax, 'description' => $memo]], $memo, $class));
            DB::update('shp_wo_services', ['txn_id' => $txn], 'id = :id', ['id' => $sid]);
            DB::run('UPDATE shp_work_orders SET cost_services = cost_services + :a WHERE id = :id', ['a' => r2($amount), 'id' => $woId]);
            return $sid;
        });
    }

    /** Mean time to repair and failures per equipment over a period */
    public static function reliability(string $from, string $to, ?int $vesselId = null): array
    {
        $sql = "SELECT e.id, e.code, e.name, s.name AS vessel_name, COUNT(w.id) AS failures, COALESCE(SUM(w.downtime_hours), 0) AS downtime,
                       COALESCE(SUM(w.cost_labor + w.cost_parts + w.cost_services), 0) AS cost
                  FROM shp_work_orders w JOIN shp_equipment e ON e.id = w.equipment_id JOIN shp_vessels s ON s.id = w.vessel_id
                 WHERE w.wo_type = 'corrective' AND w.status IN ('completed', 'closed') AND DATE(w.created_at) BETWEEN :f AND :t";
        $p = ['f' => $from, 't' => $to];
        if ($vesselId) {
            $sql .= ' AND w.vessel_id = :v';
            $p['v'] = $vesselId;
        }
        return DB::all($sql . ' GROUP BY e.id, e.code, e.name, s.name ORDER BY failures DESC, downtime DESC', $p);
    }
}
