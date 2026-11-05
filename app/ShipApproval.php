<?php
declare(strict_types=1);

/**
 * Maker-checker approvals of the shipping operations: refunds, compensation, departure overrides, fuel
 * adjustments, deferral of safety-critical work, purchase requisitions and fare changes.
 *
 * Who may decide: someone other than the requester, with the Approve right on the page the request belongs to
 * (or a delegation from such a person, within its dates), and an approval limit at or above the amount.
 * Every decision is in the audit trail with the delegation used, and the request's own record follows it.
 */
final class ShipApproval
{
    /** type => [label, page whose Approve right decides it, special permission also needed] */
    public const TYPES = [
        'refund'             => ['Refund', 'tkt.refunds', null],
        'compensation'       => ['Customer compensation', 'care.cases', null],
        'departure_override' => ['Departure override', 'ops.voyages', 'shp_departure_override'],
        'fuel_adjustment'    => ['Fuel ROB adjustment', 'ops.fuel', null],
        'wo_deferral'        => ['Deferral of safety-critical work', 'fleet.workorders', null],
        'requisition'        => ['Purchase requisition', 'fleet.requisitions', null],
        'fare_change'        => ['Fare change', 'shp.fares', null],
    ];

    public static function request(string $type, string $refTable, int $refId, ?string $refNo, ?float $amount, string $summary): int
    {
        // one open request per record and type
        $open = DB::value("SELECT id FROM shp_approvals WHERE approval_type = :t AND ref_table = :rt AND ref_id = :ri AND status = 'pending'", ['t' => $type, 'rt' => $refTable, 'ri' => $refId]);
        if ($open) {
            return (int)$open;
        }
        $id = DB::insert('shp_approvals', [
            'approval_type' => $type, 'ref_table' => $refTable, 'ref_id' => $refId, 'ref_no' => $refNo, 'amount' => $amount !== null ? r2($amount) : null,
            'summary' => mb_substr($summary, 0, 255), 'requested_by' => (int)Auth::id(), 'requested_at' => Ship::now(), 'status' => 'pending',
        ]);
        Ship::audit('approval_request', 'ops', 'shp_approval', $id, self::TYPES[$type][0] . ' ' . ($refNo ?? ''), ['amount' => $amount, 'reason' => $summary, 'page_key' => self::TYPES[$type][1]]);
        $roles = ['refund' => ['Ticketing Manager', 'General Manager'], 'compensation' => ['Ticketing Manager', 'General Manager'], 'departure_override' => ['Operations Manager', 'General Manager'],
            'fuel_adjustment' => ['Fleet Manager', 'Operations Manager'], 'wo_deferral' => ['Fleet Manager', 'Chief Engineer'], 'requisition' => ['Fleet Manager', 'General Manager'],
            'fare_change' => ['General Manager']][$type] ?? ['General Manager'];
        Notify::staff('approval:' . $id, 'approval', $roles, 'Approval needed: ' . self::TYPES[$type][0] . ' ' . ($refNo ?? ''), $summary . ($amount !== null ? ' Amount ' . money($amount) . '.' : ''), 'shp_approvals', $id);
        return $id;
    }

    /** [can decide, why not] */
    /** Whether the user may decide the request; the amount limit applies to approving, not to rejecting */
    public static function canDecide(array $a, ?int $userId = null, bool $approving = true): array
    {
        $userId ??= (int)Auth::id();
        if ($a['status'] !== 'pending') {
            return [false, 'This request was already decided.'];
        }
        if ((int)$a['requested_by'] === $userId) {
            return [false, 'You made this request; another person has to approve it.'];
        }
        [$label, $page, $special] = self::TYPES[$a['approval_type']] ?? ['', '', null];
        $self = Access::can($page, 'approve', $userId) && (!$special || Access::special($special, $userId));
        $for = null;
        if (!$self) {
            $for = self::delegatedFrom($a['approval_type'], $userId, $page, $special, (int)$a['requested_by']);
            if (!$for) {
                return [false, 'Your role cannot approve a ' . strtolower($label) . ' and no one has delegated it to you.'];
            }
        }
        $limitUser = $for ?: $userId;
        $limit = Access::special('shp_approve_limit', $limitUser);
        if ($approving && $a['amount'] !== null && $limit !== null && $limit !== true && (float)$a['amount'] > (float)$limit + 0.005) {
            return [false, 'The amount (' . money($a['amount']) . ') is above the approval limit (' . money($limit) . ').'];
        }
        return [true, $for ? 'Approving for ' . Ship::userName($for) . ' (delegation).' : ''];
    }

    /** The user who delegated this kind of approval to $userId today (not the requester), or null */
    public static function delegatedFrom(string $type, int $userId, string $page, ?string $special, int $requester): ?int
    {
        foreach (DB::all('SELECT * FROM shp_delegations WHERE to_user_id = :u AND is_active = 1 AND valid_from <= :d AND valid_to >= :d2', ['u' => $userId, 'd' => Ship::today(), 'd2' => Ship::today()]) as $d) {
            $types = array_map('trim', explode(',', (string)$d['approval_types']));
            if (!in_array('*', $types, true) && !in_array($type, $types, true)) {
                continue;
            }
            $from = (int)$d['from_user_id'];
            if ($from === $requester) {
                continue;
            }
            if (Access::can($page, 'approve', $from) && (!$special || Access::special($special, $from))) {
                return $from;
            }
        }
        return null;
    }

    public static function decide(int $id, bool $approve, string $notes): void
    {
        DB::tx(function () use ($id, $approve, $notes) {
            $a = DB::row('SELECT * FROM shp_approvals WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$a) {
                fail('The approval request was not found.');
            }
            [$ok, $why] = self::canDecide($a, null, $approve);
            if (!$ok) {
                fail($why);
            }
            if (!$approve && trim($notes) === '') {
                fail('Give the reason for rejecting the request.');
            }
            [$label, $page, $special] = self::TYPES[$a['approval_type']];
            $for = Access::can($page, 'approve') && (!$special || Access::special($special)) ? null
                : self::delegatedFrom($a['approval_type'], (int)Auth::id(), $page, $special, (int)$a['requested_by']);
            DB::update('shp_approvals', [
                'status' => $approve ? 'approved' : 'rejected', 'decided_by' => Auth::id(), 'decided_at' => Ship::now(),
                'decided_for' => $for, 'decision_notes' => mb_substr($notes, 0, 255),
            ], 'id = :id', ['id' => $id]);
            match ($a['approval_type']) {
                'refund'             => Booking::refundDecision((int)$a['ref_id'], $approve, $notes),
                'compensation'       => Care::compensationDecision((int)$a['ref_id'], $approve, $notes),
                'departure_override' => Voyages::overrideDecision((int)$a['ref_id'], $approve, $notes),
                'fuel_adjustment'    => Fuel::adjustmentDecision((int)$a['ref_id'], $approve, $notes),
                'wo_deferral'        => Cmms::deferralDecision((int)$a['ref_id'], $approve, $notes),
                'requisition'        => Procure::decision((int)$a['ref_id'], $approve, $notes),
                'fare_change'        => null,
                default              => null,
            };
            Ship::audit($approve ? 'approve' : 'reject', 'ops', 'shp_approval', $id, $label . ' ' . ($a['ref_no'] ?? ''), [
                'amount' => $a['amount'], 'reason' => $notes . ($for ? ' (for ' . Ship::userName($for) . ' by delegation)' : ''), 'page_key' => $page,
                'approved_by' => Auth::user()['username'] ?? null,
            ]);
            Notify::user((int)$a['requested_by'], 'approval:' . $id . ':decided', 'approval', $label . ' ' . ($a['ref_no'] ?? '') . ($approve ? ' approved' : ' rejected'),
                ($approve ? 'Approved by ' : 'Rejected by ') . (Auth::user()['full_name'] ?? '') . ($notes !== '' ? ': ' . $notes : '.'), 'shp_approvals', $id);
        });
    }

    public static function pendingCount(): int
    {
        $n = 0;
        foreach (DB::all("SELECT * FROM shp_approvals WHERE status = 'pending'") as $a) {
            if (self::canDecide($a)[0]) {
                $n++;
            }
        }
        return $n;
    }

    public static function forRecord(string $refTable, int $refId): array
    {
        return DB::all('SELECT * FROM shp_approvals WHERE ref_table = :t AND ref_id = :i ORDER BY id', ['t' => $refTable, 'i' => $refId]);
    }
}
