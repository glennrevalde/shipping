<?php
declare(strict_types=1);

/**
 * The bridge between the shipping operations and the accounting books.
 *
 * Every money event of the shipping side (a ticket paid, a voyage completed, fuel burnt, a spare part issued,
 * a contractor billed, a refund, a port fee remitted) is recorded here as an ordinary accounting document:
 * a sales receipt, an invoice, a journal entry, an inventory adjustment, a bill, a check, a credit memo or a
 * customer refund, saved with Txn::save. The accounting posting engine then writes the general ledger, so the
 * trial balance, the financial statements, A/R, A/P, inventory and the audit trail all see it at once.
 *
 * Each event has a key (e.g. "booking:12:pay"). shp_postings keeps one row per key under a unique index, so
 * a replayed event (a double click, a payment webhook sent twice, a re-run of a voyage completion) can never
 * post twice: the second call finds the key and returns the document already made.
 *
 * Every document carries the vessel's accounting class, so Income Statement by Class is the vessel P&L,
 * and shp_postings links it to its voyage / work order for the voyage P&L.
 */
final class ShipGL
{
    /**
     * Runs $make once for $eventKey and links the accounting document it returns.
     * $link: source_type, source_id, voyage_id, vessel_id, work_order_id, memo
     */
    public static function once(string $eventKey, array $link, callable $make): ?int
    {
        return DB::tx(function () use ($eventKey, $link, $make) {
            $existing = DB::row('SELECT id, txn_id FROM shp_postings WHERE event_key = :k FOR UPDATE', ['k' => $eventKey]);
            if ($existing) {
                return $existing['txn_id'] ? (int)$existing['txn_id'] : null;
            }
            // the key is taken first: a parallel request with the same key waits on the unique index, then finds it
            $pid = DB::insert('shp_postings', [
                'event_key' => $eventKey, 'source_type' => $link['source_type'] ?? 'other', 'source_id' => (int)($link['source_id'] ?? 0),
                'voyage_id' => $link['voyage_id'] ?? null, 'vessel_id' => $link['vessel_id'] ?? null, 'work_order_id' => $link['work_order_id'] ?? null,
                'memo' => isset($link['memo']) ? mb_substr((string)$link['memo'], 0, 255) : null, 'created_by' => Auth::id(),
            ]);
            $txnId = $make();
            if ($txnId) {
                $total = (float)DB::value('SELECT total FROM transactions WHERE id = :t', ['t' => $txnId]);
                DB::update('shp_postings', ['txn_id' => $txnId, 'amount' => $total], 'id = :id', ['id' => $pid]);
            }
            return $txnId ? (int)$txnId : null;
        });
    }

    public static function posted(string $eventKey): ?int
    {
        $t = DB::value('SELECT txn_id FROM shp_postings WHERE event_key = :k', ['k' => $eventKey]);
        return $t ? (int)$t : null;
    }

    public static function acct(string $key): int
    {
        return Accts::get($key);
    }

    public static function vesselClass(?int $vesselId): ?int
    {
        if (!$vesselId) {
            return null;
        }
        $c = DB::value('SELECT class_id FROM shp_vessels WHERE id = :id', ['id' => $vesselId]);
        return $c ? (int)$c : null;
    }

    public static function item(string $code): int
    {
        static $cache = [];
        if (!isset($cache[$code])) {
            $id = DB::value('SELECT id FROM items WHERE code = :c', ['c' => $code]);
            if (!$id) {
                fail('The shipping item "' . $code . '" is missing from the Item List. It is used to record shipping sales; add it back under Inventory > Item List.');
            }
            $cache[$code] = (int)$id;
        }
        return $cache[$code];
    }

    public static function taxCode(string $code): ?int
    {
        static $cache = [];
        if (!array_key_exists($code, $cache)) {
            $id = DB::value('SELECT id FROM tax_codes WHERE code = :c', ['c' => $code]);
            $cache[$code] = $id ? (int)$id : null;
        }
        return $cache[$code];
    }

    public static function vatRate(): float
    {
        $id = self::taxCode('VAT12');
        return $id ? (float)DB::value('SELECT rate FROM tax_codes WHERE id = :id', ['id' => $id]) : 12.0;
    }

    /** A VAT-inclusive amount split into [net, vat] */
    public static function splitVat(float $gross, bool $exempt = false): array
    {
        if ($exempt) {
            return [r2($gross), 0.0];
        }
        $rate = self::vatRate();
        if (Ship::setting('vat_inclusive', '1') !== '1') {
            return [r2($gross), r2($gross * $rate / 100)];
        }
        $net = r2($gross / (1 + $rate / 100));
        return [$net, r2($gross - $net)];
    }

    /** The customer that individual passengers are receipted under */
    public static function walkInCustomer(): int
    {
        $id = DB::value("SELECT id FROM customers WHERE name = 'Walk-in Passenger' LIMIT 1");
        if (!$id) {
            $id = DB::insert('customers', ['name' => 'Walk-in Passenger', 'is_active' => 1]);
        }
        return (int)$id;
    }

    /** Payment method and deposit-to account of a payment channel */
    public static function channelAccounts(string $channel, ?int $officeId = null): array
    {
        $pmName = ['cash' => 'Cash', 'card' => 'Credit/Debit Card', 'ewallet' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank Transfer', 'gateway' => 'Online Payment Gateway', 'check' => 'Check'][$channel] ?? 'Cash';
        $pm = DB::row('SELECT id, clearing_account_id, method_type FROM payment_methods WHERE name = :n', ['n' => $pmName]);
        $pmId = $pm ? (int)$pm['id'] : null;
        if ($channel === 'cash' || $channel === 'check') {
            $office = $officeId ? DB::value('SELECT cash_account_id FROM shp_offices WHERE id = :o', ['o' => $officeId]) : null;
            return [$office ? (int)$office : Accts::get('undeposited_funds'), $pmId];
        }
        if ($pm && $pm['clearing_account_id']) {
            return [(int)$pm['clearing_account_id'], $pmId];
        }
        if ($channel === 'card' || $channel === 'gateway') {
            return [Accts::get('card_clearing'), $pmId];
        }
        if ($channel === 'ewallet' || $channel === 'maya') {
            return [Accts::get('ewallet_clearing'), $pmId];
        }
        // bank transfer: straight into the first bank account
        $bank = DB::value("SELECT id FROM accounts WHERE account_type = 'Bank' AND is_active = 1 AND bank_account_no IS NOT NULL ORDER BY code LIMIT 1")
            ?: DB::value("SELECT id FROM accounts WHERE account_type = 'Bank' AND is_active = 1 ORDER BY code LIMIT 1");
        return [(int)$bank, $pmId];
    }

    /** Where cash refunds are paid from (the ticket offices' cash fund) */
    public static function refundAccount(string $method): int
    {
        if ($method === 'cash') {
            $id = DB::value("SELECT id FROM accounts WHERE code = '1001'");
            return $id ? (int)$id : Accts::get('cash_on_hand');
        }
        [$acct] = self::channelAccounts($method);
        return $acct;
    }

    /** The account a fare is credited to when sold: a liability until the voyage sails, or revenue at once */
    public static function paxSaleAccount(): int
    {
        return Ship::setting('revenue_policy', 'deferred') === 'on_sale' ? Accts::get('shp_fare_revenue') : Accts::get('shp_deferred_pax');
    }

    public static function freightSaleAccount(string $kind): int
    {
        if (Ship::setting('revenue_policy', 'deferred') === 'on_sale') {
            return Accts::get($kind === 'vehicle' ? 'shp_vehicle_revenue' : 'shp_cargo_revenue');
        }
        return Accts::get('shp_deferred_freight');
    }

    public static function surchargeSaleAccount(): int
    {
        return Ship::setting('revenue_policy', 'deferred') === 'on_sale' ? Accts::get('shp_surcharge_revenue') : Accts::get('shp_deferred_pax');
    }

    // ------------------------------------------------------------------ document builders (all through Txn::save)

    public static function line(int $itemId, string $desc, float $amount, float $tax, ?int $taxCodeId, int $accountId, ?int $classId): array
    {
        return [
            'line_kind' => 'item', 'item_id' => $itemId, 'account_id' => $accountId, 'description' => mb_substr($desc, 0, 255),
            'qty' => 1, 'rate' => r2($amount), 'uom_qty' => 1, 'uom_rate' => r2($amount), 'amount' => r2($amount),
            'tax_code_id' => $taxCodeId, 'tax_amount' => r2($tax), 'class_id' => $classId,
        ];
    }

    public static function jl(int $accountId, float $signed, string $desc, ?int $classId, array $extra = []): array
    {
        return array_merge(['line_kind' => 'journal', 'account_id' => $accountId, 'amount' => r2($signed), 'description' => mb_substr($desc, 0, 255), 'class_id' => $classId], $extra);
    }

    public static function salesReceipt(int $customerId, string $date, int $depositAcct, ?int $pmId, array $lines, string $memo, ?int $classId, ?string $ref = null): int
    {
        return Txn::save('sales_receipt', [
            'txn_date' => $date, 'customer_id' => $customerId, 'account_id' => $depositAcct, 'payment_method_id' => $pmId,
            'memo' => mb_substr($memo, 0, 255), 'class_id' => $classId, 'ref_no' => $ref, 'status' => 'paid',
        ], $lines, null, ['reason' => 'Shipping: ' . $memo]);
    }

    public static function invoice(int $customerId, string $date, array $lines, string $memo, ?int $classId, ?string $ref = null): int
    {
        $c = DB::row('SELECT terms_id, billing_address FROM customers WHERE id = :id', ['id' => $customerId]) ?: [];
        $terms = !empty($c['terms_id']) ? (int)$c['terms_id'] : null;
        return Txn::save('invoice', [
            'txn_date' => $date, 'customer_id' => $customerId, 'account_id' => Accts::get('ar'), 'terms_id' => $terms,
            'due_date' => Txn::dueDate($date, $terms), 'billing_address' => $c['billing_address'] ?? null,
            'memo' => mb_substr($memo, 0, 255), 'class_id' => $classId, 'ref_no' => $ref,
        ], $lines, null, ['reason' => 'Shipping: ' . $memo]);
    }

    public static function journal(string $date, array $lines, string $memo, ?int $classId, ?string $ref = null): int
    {
        $dr = 0.0;
        $cr = 0.0;
        foreach ($lines as $l) {
            $l['amount'] > 0 ? $dr += $l['amount'] : $cr -= $l['amount'];
        }
        if (abs(r2($dr) - r2($cr)) >= 0.005) {
            fail('The journal for "' . $memo . '" does not balance (debits ' . money($dr) . ', credits ' . money($cr) . '). Nothing was posted.');
        }
        return Txn::save('journal', ['txn_date' => $date, 'memo' => mb_substr($memo, 0, 255), 'class_id' => $classId, 'ref_no' => $ref], $lines, null, ['reason' => 'Shipping: ' . $memo]);
    }

    /**
     * Takes stock out of a site at its average cost, to $offsetAcct (fuel to the voyage fuel expense, spares to repairs).
     * Stock may never go below zero here, whatever the company's general setting.
     * Returns [txn id, cost].
     */
    public static function issueStock(string $date, int $siteId, int $itemId, float $qty, int $offsetAcct, ?int $classId, string $memo, ?string $ref = null): array
    {
        if ($qty <= 0) {
            fail('The quantity to issue must be more than zero.');
        }
        $onHand = (float)DB::value('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = :i AND site_id = :s FOR UPDATE', ['i' => $itemId, 's' => $siteId]);
        if ($qty > $onHand + 0.0001) {
            $code = (string)DB::value('SELECT code FROM items WHERE id = :i', ['i' => $itemId]);
            $site = (string)DB::value('SELECT name FROM sites WHERE id = :s', ['s' => $siteId]);
            fail('Not enough stock: ' . $code . ' at ' . $site . ' has ' . qty($onHand) . ' on hand and ' . qty($qty) . ' was asked for. Receive the stock first, or record the issue against the stock that is there.');
        }
        $txn = Txn::save('inventory_adjustment', [
            'txn_date' => $date, 'site_id' => $siteId, 'account_id' => $offsetAcct, 'class_id' => $classId,
            'ref_no' => $ref, 'memo' => mb_substr($memo, 0, 255), 'status' => 'open',
        ], [[
            'line_kind' => 'item', 'item_id' => $itemId, 'qty' => -$qty, 'rate' => 0, 'uom_qty' => -$qty, 'value_change' => 0,
            'site_id' => $siteId, 'description' => mb_substr($memo, 0, 255), 'class_id' => $classId,
        ]], null, ['reason' => 'Shipping: ' . $memo]);
        $cost = (float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $txn, 'a' => $offsetAcct]);
        if ($cost == 0.0) {
            $cost = -(float)DB::value('SELECT COALESCE(SUM(total_cost), 0) FROM inventory_movements WHERE txn_id = :t', ['t' => $txn]);
        }
        return [$txn, r2(abs($cost))];
    }

    /** A stock correction up or down (fuel sounding variance) */
    public static function adjustStock(string $date, int $siteId, int $itemId, float $qtyChange, int $offsetAcct, ?int $classId, string $memo): int
    {
        if ($qtyChange < 0) {
            return self::issueStock($date, $siteId, $itemId, -$qtyChange, $offsetAcct, $classId, $memo)[0];
        }
        $avg = (float)DB::value('SELECT avg_cost FROM items WHERE id = :i', ['i' => $itemId]) ?: (float)DB::value('SELECT cost FROM items WHERE id = :i', ['i' => $itemId]);
        return Txn::save('inventory_adjustment', [
            'txn_date' => $date, 'site_id' => $siteId, 'account_id' => $offsetAcct, 'class_id' => $classId, 'memo' => mb_substr($memo, 0, 255), 'status' => 'open',
        ], [[
            'line_kind' => 'item', 'item_id' => $itemId, 'qty' => $qtyChange, 'rate' => $avg, 'uom_qty' => $qtyChange, 'value_change' => 0,
            'site_id' => $siteId, 'description' => mb_substr($memo, 0, 255), 'class_id' => $classId,
        ]], null, ['reason' => 'Shipping: ' . $memo]);
    }

    /** A supplier invoice can be entered once: the same supplier and invoice number twice is refused */
    public static function assertNewSupplierInvoice(int $vendorId, ?string $refNo): void
    {
        $refNo = trim((string)$refNo);
        if ($refNo === '') {
            return;
        }
        $dup = DB::row("SELECT txn_no FROM transactions WHERE vendor_id = :v AND ref_no = :r AND txn_type IN ('bill', 'check') AND is_voided = 0 LIMIT 1", ['v' => $vendorId, 'r' => $refNo]);
        if ($dup) {
            fail('Supplier invoice ' . $refNo . ' of this supplier is already in the books (' . $dup['txn_no'] . '). A supplier invoice can be entered only once.');
        }
    }

    /** Bill (A/P) or check: lines are [account_id, amount, tax_code_id, tax_amount, description] or item lines */
    public static function bill(int $vendorId, string $date, ?string $refNo, array $lines, string $memo, ?int $classId, ?int $siteId = null): int
    {
        self::assertNewSupplierInvoice($vendorId, $refNo);
        $v = DB::row('SELECT terms_id FROM vendors WHERE id = :id', ['id' => $vendorId]) ?: [];
        $terms = !empty($v['terms_id']) ? (int)$v['terms_id'] : null;
        return Txn::save('bill', [
            'txn_date' => $date, 'vendor_id' => $vendorId, 'account_id' => Accts::get('ap'), 'terms_id' => $terms,
            'due_date' => Txn::dueDate($date, $terms), 'ref_no' => $refNo ?: null, 'memo' => mb_substr($memo, 0, 255),
            'class_id' => $classId, 'site_id' => $siteId,
        ], self::purchaseLines($lines, $classId, $siteId), null, ['reason' => 'Shipping: ' . $memo]);
    }

    public static function check(int $bankAcct, ?int $vendorId, ?string $payee, string $date, ?string $refNo, array $lines, string $memo, ?int $classId): int
    {
        if ($vendorId) {
            self::assertNewSupplierInvoice($vendorId, $refNo);
        }
        return Txn::save('check', [
            'txn_date' => $date, 'account_id' => $bankAcct, 'vendor_id' => $vendorId, 'payee_name' => $payee,
            'ref_no' => $refNo ?: null, 'memo' => mb_substr($memo, 0, 255), 'class_id' => $classId,
        ], self::purchaseLines($lines, $classId, null), null, ['reason' => 'Shipping: ' . $memo]);
    }

    private static function purchaseLines(array $lines, ?int $classId, ?int $siteId): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (!empty($l['item_id'])) {
                $out[] = [
                    'line_kind' => 'item', 'item_id' => (int)$l['item_id'], 'description' => mb_substr((string)($l['description'] ?? ''), 0, 255),
                    'qty' => (float)$l['qty'], 'rate' => (float)$l['rate'], 'uom_qty' => (float)$l['qty'], 'uom_rate' => (float)$l['rate'],
                    'amount' => r2((float)$l['qty'] * (float)$l['rate']), 'tax_code_id' => $l['tax_code_id'] ?? null, 'tax_amount' => r2($l['tax_amount'] ?? 0),
                    'site_id' => $l['site_id'] ?? $siteId, 'class_id' => $l['class_id'] ?? $classId,
                ];
                continue;
            }
            $out[] = [
                'line_kind' => 'expense', 'account_id' => (int)$l['account_id'], 'description' => mb_substr((string)($l['description'] ?? ''), 0, 255),
                'amount' => r2($l['amount']), 'tax_code_id' => $l['tax_code_id'] ?? null, 'tax_amount' => r2($l['tax_amount'] ?? 0),
                'class_id' => $l['class_id'] ?? $classId,
            ];
        }
        return $out;
    }

    /** Credit memo to the customer (refund payable / credit voucher): lines debit the accounts given */
    public static function creditMemo(int $customerId, string $date, array $lines, string $memo, ?int $classId, string $disposition = 'refund'): int
    {
        $id = Txn::save('credit_memo', [
            'txn_date' => $date, 'customer_id' => $customerId, 'account_id' => Accts::get('ar'), 'memo' => mb_substr($memo, 0, 255),
            'class_id' => $classId, 'restock' => 0,
        ], $lines, null, ['reason' => 'Shipping: ' . $memo]);
        DB::update('transactions', ['credit_disposition' => $disposition], 'id = :id', ['id' => $id]);
        return $id;
    }

    /** Money paid back to the customer against a credit memo */
    public static function refundPaid(int $creditMemoId, int $customerId, int $fromAcct, ?int $pmId, float $amount, string $date, string $memo, ?int $classId): int
    {
        $cm = Txn::load($creditMemoId);
        if (!$cm) {
            fail('The credit memo of this refund was not found.');
        }
        if ($amount > Balances::creditRemaining($cm) + 0.005) {
            fail('The refund (' . money($amount) . ') is more than the credit still open on ' . Txn::ref($cm) . ' (' . money(Balances::creditRemaining($cm)) . ').');
        }
        return Txn::save('refund', [
            'txn_date' => $date, 'customer_id' => $customerId, 'payee_name' => (string)DB::value('SELECT name FROM customers WHERE id = :c', ['c' => $customerId]),
            'account_id' => $fromAcct, 'payment_method_id' => $pmId, 'total' => r2($amount), 'source_txn_id' => $creditMemoId,
            'memo' => mb_substr($memo, 0, 255), 'class_id' => $classId,
        ], [], null, ['keep_totals' => true, 'reason' => 'Shipping: ' . $memo]);
    }

    /** Debit and credit totals of a posted document (checks that it balanced) */
    public static function balanced(int $txnId): bool
    {
        $r = DB::row('SELECT COALESCE(SUM(debit), 0) d, COALESCE(SUM(credit), 0) c FROM journal_lines WHERE txn_id = :t', ['t' => $txnId]);
        return abs((float)$r['d'] - (float)$r['c']) < 0.005;
    }

    /** The accounting documents of a shipping record, newest last */
    public static function documents(string $sourceType, int $sourceId): array
    {
        return DB::all('SELECT p.*, t.txn_type, t.txn_no, t.txn_date, t.total, t.is_voided FROM shp_postings p LEFT JOIN transactions t ON t.id = p.txn_id
                         WHERE p.source_type = :s AND p.source_id = :i ORDER BY p.id', ['s' => $sourceType, 'i' => $sourceId]);
    }

    public static function documentsWhere(string $col, int $id): array
    {
        if (!in_array($col, ['voyage_id', 'vessel_id', 'work_order_id'], true)) {
            return [];
        }
        return DB::all("SELECT p.*, t.txn_type, t.txn_no, t.txn_date, t.total, t.is_voided FROM shp_postings p LEFT JOIN transactions t ON t.id = p.txn_id
                         WHERE p.$col = :i ORDER BY p.id", ['i' => $id]);
    }
}
