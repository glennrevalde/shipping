<?php
declare(strict_types=1);

/**
 * Ticketing cashier shifts: the drawer is opened with a float, every cash collection and cash refund of the
 * shift is counted against it, and the shift is closed with a cash count. A difference posts to Cash Over and
 * Short; a supervisor (another person) reviews the shift. The collections themselves wait in Undeposited Funds
 * for Banking > Make Deposits, so cash register, deposit and ledger reconcile.
 */
final class Cashier
{
    public static function current(int $userId): ?array
    {
        return DB::row("SELECT s.*, o.name AS office_name FROM shp_cashier_shifts s JOIN shp_offices o ON o.id = s.office_id
                         WHERE s.cashier_id = :u AND s.status = 'open' ORDER BY s.id DESC LIMIT 1", ['u' => $userId]);
    }

    /** The open shift of the user, or a clear stop asking them to open one */
    public static function requireOpen(int $userId): int
    {
        $s = self::current($userId);
        if (!$s) {
            fail('Open your cashier shift first (Ticketing & Boarding > Cashier Shifts): cash is counted per shift.');
        }
        return (int)$s['id'];
    }

    /** Cash taken or paid out goes into a shift that is still open (never into one already counted) */
    public static function assertOpen(?int $shiftId): void
    {
        if ($shiftId === null) {
            return;
        }
        $st = DB::value('SELECT status FROM shp_cashier_shifts WHERE id = :id', ['id' => $shiftId]);
        if ($st !== 'open') {
            fail('That cashier shift is ' . ($st ?: 'unknown') . ': cash can only go into a shift that is still open.');
        }
    }

    public static function open(int $officeId, float $float, ?int $userId = null, ?string $at = null): int
    {
        $userId ??= (int)Auth::id();
        if (self::current($userId)) {
            fail('You already have an open shift. Close it before opening another.');
        }
        if (!DB::value('SELECT 1 FROM shp_offices WHERE id = :o AND is_active = 1', ['o' => $officeId])) {
            fail('Choose the ticket office of the shift.');
        }
        if ($float < 0) {
            fail('The opening float cannot be negative.');
        }
        $id = DB::insert('shp_cashier_shifts', [
            'shift_no' => Ship::no('shp_shift', 'SH-', 6), 'office_id' => $officeId, 'cashier_id' => $userId,
            'opened_at' => $at ?? Ship::now(), 'opening_float' => r2($float), 'status' => 'open',
        ]);
        Ship::audit('shift_open', 'tkt', 'shp_shift', $id, 'Cashier shift opened', ['amount' => $float, 'page_key' => 'tkt.cashier']);
        return $id;
    }

    /** Totals of a shift: cash collected, non-cash, cash refunds paid, expected cash in the drawer */
    public static function totals(int $shiftId): array
    {
        $s = DB::row('SELECT * FROM shp_cashier_shifts WHERE id = :id', ['id' => $shiftId]);
        $byChannel = DB::pairs("SELECT channel, COALESCE(SUM(amount), 0) FROM shp_payments WHERE shift_id = :s AND status IN ('captured', 'settled') GROUP BY channel", ['s' => $shiftId]);
        $cash = (float)($byChannel['cash'] ?? 0);
        $nonCash = r2(array_sum($byChannel) - $cash);
        $refunds = (float)DB::value("SELECT COALESCE(SUM(refund_amount), 0) FROM shp_refunds WHERE shift_id = :s AND status = 'paid' AND method = 'cash'", ['s' => $shiftId]);
        $count = (int)DB::value('SELECT COUNT(*) FROM shp_payments WHERE shift_id = :s', ['s' => $shiftId]);
        return ['by_channel' => $byChannel, 'cash' => r2($cash), 'non_cash' => $nonCash, 'refunds_cash' => r2($refunds), 'count' => $count,
            'expected' => r2((float)$s['opening_float'] + $cash - $refunds)];
    }

    public static function close(int $shiftId, float $counted, string $notes = '', ?string $at = null): void
    {
        DB::tx(function () use ($shiftId, $counted, $notes, $at) {
            $s = DB::row('SELECT * FROM shp_cashier_shifts WHERE id = :id FOR UPDATE', ['id' => $shiftId]);
            if (!$s || $s['status'] !== 'open') {
                fail('Only an open shift can be closed.');
            }
            if ((int)$s['cashier_id'] !== (int)Auth::id() && !Access::can('tkt.cashier', 'approve')) {
                fail('Only the cashier of the shift (or a supervisor) can close it.');
            }
            $tot = self::totals($shiftId);
            $diff = r2($counted - $tot['expected']);
            $txn = null;
            if (abs($diff) >= 0.005) {
                $class = (int)DB::value('SELECT class_id FROM shp_offices WHERE id = :o', ['o' => $s['office_id']]) ?: null;
                $cashAcct = (int)(DB::value('SELECT cash_account_id FROM shp_offices WHERE id = :o', ['o' => $s['office_id']]) ?: Accts::get('undeposited_funds'));
                $memo = 'Cash ' . ($diff > 0 ? 'over' : 'short') . ', ticketing shift ' . $s['shift_no'];
                $txn = ShipGL::once('shift:' . $shiftId . ':overshort', ['source_type' => 'shift', 'source_id' => $shiftId, 'memo' => $memo], fn() => ShipGL::journal(substr($at ?? Ship::now(), 0, 10), [
                    ShipGL::jl($cashAcct, $diff, $memo, $class),
                    ShipGL::jl(Accts::get('cash_over_short'), -$diff, $memo, $class),
                ], $memo, $class, $s['shift_no']));
            }
            DB::update('shp_cashier_shifts', [
                'closed_at' => $at ?? Ship::now(), 'expected_cash' => $tot['expected'], 'counted_cash' => r2($counted), 'over_short' => $diff,
                'non_cash_total' => $tot['non_cash'], 'refunds_cash' => $tot['refunds_cash'], 'status' => 'closed', 'over_short_txn_id' => $txn,
                'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null,
            ], 'id = :id', ['id' => $shiftId]);
            Ship::audit('shift_close', 'tkt', 'shp_shift', $shiftId, 'Shift ' . $s['shift_no'] . ' closed', ['amount' => $counted, 'reason' => 'Expected ' . money($tot['expected']) . ', over/short ' . money($diff), 'page_key' => 'tkt.cashier']);
            if (abs($diff) >= 0.005) {
                Notify::staff('shift:' . $shiftId . ':diff', 'cash_variance', ['Ticketing Manager', 'Treasurer'], 'Cash ' . ($diff > 0 ? 'over' : 'short') . ' on shift ' . $s['shift_no'],
                    Ship::userName((int)$s['cashier_id']) . ' counted ' . money($counted) . ' against ' . money($tot['expected']) . ' expected.', 'shp_cashier_shifts', $shiftId);
            }
        });
    }

    public static function review(int $shiftId, string $notes = ''): void
    {
        $s = DB::row('SELECT * FROM shp_cashier_shifts WHERE id = :id', ['id' => $shiftId]);
        if (!$s || $s['status'] !== 'closed') {
            fail('Only a closed shift can be reviewed.');
        }
        if ((int)$s['cashier_id'] === (int)Auth::id()) {
            fail('Another person has to review your shift.');
        }
        if (!Access::can('tkt.cashier', 'approve')) {
            Access::denyPage('tkt.cashier', 'review shifts on');
        }
        DB::update('shp_cashier_shifts', ['status' => 'reviewed', 'reviewed_by' => Auth::id(), 'reviewed_at' => Ship::now(),
            'notes' => trim(($s['notes'] ? $s['notes'] . ' ' : '') . $notes) ?: null], 'id = :id', ['id' => $shiftId]);
        Ship::audit('shift_review', 'tkt', 'shp_shift', $shiftId, 'Shift ' . $s['shift_no'] . ' reviewed', ['reason' => $notes, 'page_key' => 'tkt.cashier']);
    }
}
