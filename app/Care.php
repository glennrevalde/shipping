<?php
declare(strict_types=1);

/**
 * Customer service cases, separate from travel tickets and work orders: booking help, rebooking, refund
 * follow-up, missed departures, lost baggage, cargo claims, accessibility, delays, complaints.
 *
 * Each case has an owner, an SLA due time from its priority, escalation when the SLA passes, a resolution code,
 * and links to the booking, ticket, voyage or waybill (never to other passengers). Money given to a customer
 * (compensation, a credit voucher, a cash payment) is approved by another person and posted in the books.
 */
final class Care
{
    public const RESOLUTIONS = ['answered' => 'Information given', 'rebooked' => 'Rebooked', 'refunded' => 'Refunded', 'compensated' => 'Compensated',
        'found' => 'Item found / returned', 'claim_paid' => 'Claim paid', 'claim_denied' => 'Claim denied', 'no_fault' => 'No fault found', 'other' => 'Other'];

    public static function slaDue(string $priority, ?string $from = null): string
    {
        $hours = (int)Ship::setting('sla_' . $priority . '_hours', ['urgent' => '4', 'high' => '12', 'normal' => '48', 'low' => '96'][$priority] ?? '48');
        return date('Y-m-d H:i:s', strtotime($from ?? Ship::now()) + 3600 * max(1, $hours));
    }

    public static function open(array $d): int
    {
        $cat = (string)($d['category'] ?? 'other');
        if (!isset(Ship::CASE_CATEGORIES[$cat])) {
            $cat = 'other';
        }
        if (trim((string)($d['subject'] ?? '')) === '' || trim((string)($d['customer_name'] ?? '')) === '') {
            fail('Enter the customer\'s name and the subject of the case.');
        }
        $prio = in_array($d['priority'] ?? '', ['low', 'normal', 'high', 'urgent'], true) ? $d['priority'] : 'normal';
        $at = $d['created_at'] ?? Ship::now();
        $id = DB::insert('shp_cases', [
            'case_no' => Ship::no('shp_case', 'CS-', 6), 'category' => $cat, 'channel' => $d['channel'] ?? 'desk', 'subject' => mb_substr(trim((string)$d['subject']), 0, 160),
            'description' => $d['description'] ?? null, 'priority' => $prio, 'status' => 'open', 'customer_name' => mb_substr(trim((string)$d['customer_name']), 0, 120),
            'customer_email' => $d['customer_email'] ?? null, 'customer_phone' => $d['customer_phone'] ?? null, 'portal_user_id' => $d['portal_user_id'] ?? null,
            'booking_id' => !empty($d['booking_id']) ? (int)$d['booking_id'] : null, 'ticket_id' => !empty($d['ticket_id']) ? (int)$d['ticket_id'] : null,
            'voyage_id' => !empty($d['voyage_id']) ? (int)$d['voyage_id'] : null, 'cargo_id' => !empty($d['cargo_id']) ? (int)$d['cargo_id'] : null,
            'assigned_to' => !empty($d['assigned_to']) ? (int)$d['assigned_to'] : null, 'sla_due_at' => self::slaDue($prio, $at), 'created_by' => Auth::id(),
        ]);
        $no = (string)DB::value('SELECT case_no FROM shp_cases WHERE id = :id', ['id' => $id]);
        self::act($id, 'customer', (string)($d['description'] ?? $d['subject']), false);
        Ship::audit('create', 'care', 'shp_case', $id, 'Case ' . $no, ['page_key' => 'care.cases']);
        if (!empty($d['assigned_to'])) {
            Notify::user((int)$d['assigned_to'], 'case:' . $id . ':assigned', 'case_assigned', 'Case ' . $no . ' assigned to you', (string)$d['subject'], 'shp_cases', $id);
        } else {
            Notify::staff('case:' . $id, 'case_new', ['Customer Service', 'Ticketing Manager'], 'New case ' . $no . ' (' . Ship::CASE_CATEGORIES[$cat] . ')', (string)$d['subject'], 'shp_cases', $id);
        }
        if (!empty($d['customer_email'])) {
            Notify::customer('case:' . $id . ':ack', 'case_ack', 'passenger', (string)$d['customer_email'], null, 'We received your concern (' . $no . ')',
                'Thank you. Your concern "' . $d['subject'] . '" is case ' . $no . '. We will get back to you within the service time for its priority.', 'shp_cases', $id);
        }
        return $id;
    }

    public static function act(int $caseId, string $type, string $details, bool $internal = true): void
    {
        if (trim($details) === '') {
            return;
        }
        DB::insert('shp_case_actions', ['case_id' => $caseId, 'action_type' => $type, 'details' => mb_substr($details, 0, 1000), 'is_internal' => $internal ? 1 : 0, 'user_id' => Auth::id()]);
        if ($type === 'reply' || ($type === 'note' && !$internal)) {
            DB::run('UPDATE shp_cases SET first_response_at = COALESCE(first_response_at, :n), updated_at = :n2 WHERE id = :id', ['n' => Ship::now(), 'n2' => Ship::now(), 'id' => $caseId]);
        }
    }

    public static function update(int $id, array $d): void
    {
        DB::tx(function () use ($id, $d) {
            $c = DB::row('SELECT * FROM shp_cases WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$c) {
                fail('Case not found.');
            }
            if (in_array($c['status'], ['closed'], true) && ($d['status'] ?? 'closed') !== 'open') {
                fail('The case is closed. Reopen it to change it.');
            }
            $upd = ['updated_at' => Ship::now()];
            $notes = [];
            if (isset($d['assigned_to']) && (int)$d['assigned_to'] !== (int)$c['assigned_to']) {
                $upd['assigned_to'] = (int)$d['assigned_to'] ?: null;
                $notes[] = 'Assigned to ' . (Ship::userName((int)$d['assigned_to']) ?: 'nobody');
                if ((int)$d['assigned_to']) {
                    Notify::user((int)$d['assigned_to'], 'case:' . $id . ':assigned:' . (int)$d['assigned_to'], 'case_assigned', 'Case ' . $c['case_no'] . ' assigned to you', $c['subject'], 'shp_cases', $id);
                }
            }
            if (isset($d['priority']) && $d['priority'] !== $c['priority'] && in_array($d['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
                $upd['priority'] = $d['priority'];
                $upd['sla_due_at'] = self::slaDue($d['priority'], $c['created_at']);
                $notes[] = 'Priority ' . $c['priority'] . ' to ' . $d['priority'];
            }
            if (isset($d['status']) && $d['status'] !== $c['status']) {
                $to = $d['status'];
                if (!in_array($to, ['open', 'in_progress', 'waiting', 'resolved', 'closed'], true)) {
                    fail('Choose a valid status.');
                }
                if (in_array($to, ['resolved', 'closed'], true)) {
                    $code = (string)($d['resolution_code'] ?? $c['resolution_code'] ?? '');
                    if (!isset(self::RESOLUTIONS[$code])) {
                        fail('Choose the resolution code.');
                    }
                    if ($c['comp_status'] === 'requested') {
                        fail('The compensation request is still waiting for approval.');
                    }
                    $upd['resolution_code'] = $code;
                    $upd['resolution_notes'] = mb_substr((string)($d['resolution_notes'] ?? $c['resolution_notes'] ?? ''), 0, 500) ?: null;
                    $upd['resolved_at'] = $c['resolved_at'] ?: Ship::now();
                }
                $upd['status'] = $to;
                $notes[] = 'Status ' . $c['status'] . ' to ' . $to;
            }
            DB::update('shp_cases', $upd, 'id = :id', ['id' => $id]);
            if ($notes) {
                self::act($id, 'status', implode('. ', $notes) . '.');
                Ship::audit('edit', 'care', 'shp_case', $id, 'Case ' . $c['case_no'], ['reason' => implode('. ', $notes), 'page_key' => 'care.cases']);
            }
        });
    }

    /** Cases past their SLA are flagged and escalated once */
    public static function escalateOverdue(): int
    {
        $n = 0;
        foreach (DB::all("SELECT * FROM shp_cases WHERE status IN ('open', 'in_progress', 'waiting') AND escalated = 0 AND sla_due_at < :n", ['n' => Ship::now()]) as $c) {
            DB::update('shp_cases', ['escalated' => 1], 'id = :id', ['id' => $c['id']]);
            DB::insert('shp_case_actions', ['case_id' => $c['id'], 'action_type' => 'escalate', 'details' => 'Service time passed (' . fdatetime($c['sla_due_at']) . '): escalated.', 'is_internal' => 1]);
            Notify::staff('case:' . $c['id'] . ':escalated', 'case_escalated', ['Ticketing Manager', 'General Manager'], 'Case ' . $c['case_no'] . ' is past its service time', $c['subject'], 'shp_cases', (int)$c['id']);
            $n++;
        }
        return $n;
    }

    public static function requestCompensation(int $id, string $type, float $amount, string $reason): void
    {
        if (!in_array($type, ['voucher', 'cash'], true)) {
            fail('Choose a credit voucher or a cash payment. A fare refund goes through Refunds & Cancellations.');
        }
        if ($amount <= 0 || trim($reason) === '') {
            fail('Enter the amount and the reason.');
        }
        DB::tx(function () use ($id, $type, $amount, $reason) {
            $c = DB::row('SELECT * FROM shp_cases WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$c || in_array($c['status'], ['resolved', 'closed'], true)) {
                fail('Compensation is requested on an open case.');
            }
            if ($c['comp_status'] === 'requested' || $c['comp_status'] === 'approved' || $c['comp_status'] === 'paid') {
                fail('This case already has compensation ' . $c['comp_status'] . '.');
            }
            DB::update('shp_cases', ['comp_type' => $type, 'comp_amount' => r2($amount), 'comp_status' => 'requested'], 'id = :id', ['id' => $id]);
            self::act($id, 'compensation', 'Compensation requested: ' . ($type === 'voucher' ? 'credit voucher' : 'cash') . ' of ' . money($amount) . '. ' . $reason);
            ShipApproval::request('compensation', 'shp_cases', $id, $c['case_no'], $amount, 'Compensation for case ' . $c['case_no'] . ' (' . $c['customer_name'] . '): ' . $reason);
        });
    }

    /**
     * Approved compensation is posted: a credit voucher is a credit memo kept as the customer's credit
     * (Claims & Compensation expense / Accounts Receivable); cash is a check from the bank.
     */
    public static function compensationDecision(int $id, bool $approve, string $notes): void
    {
        $c = DB::row('SELECT * FROM shp_cases WHERE id = :id FOR UPDATE', ['id' => $id]);
        if (!$c || $c['comp_status'] !== 'requested') {
            fail('The compensation is no longer waiting for approval.');
        }
        if (!$approve) {
            DB::update('shp_cases', ['comp_status' => 'rejected'], 'id = :id', ['id' => $id]);
            self::act($id, 'compensation', 'Compensation rejected: ' . $notes);
            return;
        }
        $vesselId = $c['voyage_id'] ? (int)DB::value('SELECT vessel_id FROM shp_voyages WHERE id = :v', ['v' => $c['voyage_id']]) : null;
        if (!$vesselId && $c['cargo_id']) {
            $vesselId = (int)DB::value('SELECT v.vessel_id FROM shp_cargo c JOIN shp_voyages v ON v.id = c.voyage_id WHERE c.id = :c', ['c' => $c['cargo_id']]) ?: null;
        }
        $class = ShipGL::vesselClass($vesselId);
        $customer = $c['booking_id'] ? (int)DB::value('SELECT customer_id FROM shp_bookings WHERE id = :b', ['b' => $c['booking_id']]) : null;
        if (!$customer && $c['cargo_id']) {
            $customer = (int)DB::value('SELECT shipper_customer_id FROM shp_cargo WHERE id = :c', ['c' => $c['cargo_id']]) ?: null;
        }
        $customer = $customer ?: ShipGL::walkInCustomer();
        $memo = 'Compensation, case ' . $c['case_no'] . ' (' . $c['customer_name'] . ')';
        $link = ['source_type' => 'case', 'source_id' => $id, 'voyage_id' => $c['voyage_id'], 'vessel_id' => $vesselId, 'memo' => $memo];
        if ($c['comp_type'] === 'voucher') {
            $txn = ShipGL::once('case:' . $id . ':comp', $link, fn() => ShipGL::creditMemo($customer, Ship::today(), [
                ShipGL::line(ShipGL::item('SHP-FARE'), 'Credit voucher: ' . $memo, (float)$c['comp_amount'], 0, ShipGL::taxCode('NT'), Accts::get('shp_claims_expense'), $class),
            ], $memo, $class, 'keep'));
            $status = 'paid';
        } else {
            $bank = (int)DB::value("SELECT id FROM accounts WHERE account_type = 'Bank' AND is_active = 1 AND bank_account_no IS NOT NULL ORDER BY code LIMIT 1");
            $txn = ShipGL::once('case:' . $id . ':comp', $link, fn() => ShipGL::check($bank, null, $c['customer_name'], Ship::today(), $c['case_no'],
                [['account_id' => Accts::get('shp_claims_expense'), 'amount' => (float)$c['comp_amount'], 'description' => $memo]], $memo, $class));
            $status = 'paid';
        }
        DB::update('shp_cases', ['comp_status' => $status, 'comp_txn_id' => $txn], 'id = :id', ['id' => $id]);
        self::act($id, 'compensation', 'Compensation approved and posted (' . money($c['comp_amount']) . '). ' . $notes);
    }

    public static function find(int $id): ?array
    {
        return DB::row('SELECT c.*, b.booking_ref, t.ticket_no, v.voyage_no, g.waybill_no, u.full_name AS owner_name
                          FROM shp_cases c LEFT JOIN shp_bookings b ON b.id = c.booking_id LEFT JOIN shp_tickets t ON t.id = c.ticket_id
                          LEFT JOIN shp_voyages v ON v.id = c.voyage_id LEFT JOIN shp_cargo g ON g.id = c.cargo_id LEFT JOIN users u ON u.id = c.assigned_to
                         WHERE c.id = :id', ['id' => $id]);
    }
}
