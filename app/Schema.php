<?php
declare(strict_types=1);

/**
 * Knows which upgrade scripts (database/migrations) the program files need and which of them
 * have not been run on this database yet. index.php stops with instructions while any is pending,
 * so nobody works on a half-upgraded system.
 */
final class Schema
{
    private const FLAG = 'schema_ok_2026_12_10';

    /**
     * file => one cheap probe.
     *   A string probe is pending when the query fails: "WHERE 1 = 0" reads no rows, but MySQL still
     *   checks that every table and column it names exists.
     *   An [sql, expected] probe is pending when the query fails OR does not give back what is expected;
     *   that is how a migration which only adds a row of data is recognised.
     */
    private const MIGRATIONS = [
        'database/migrations/2026_09_19_classes_cash_voucher.sql' =>
            'SELECT (SELECT COUNT(*) FROM classes WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM other_names WHERE 1 = 0)
                  + (SELECT COUNT(class_id) + COUNT(other_name_id) FROM transactions WHERE 1 = 0)
                  + (SELECT COUNT(class_id) + COUNT(other_name_id) FROM transaction_lines WHERE 1 = 0)
                  + (SELECT COUNT(class_id) + COUNT(other_name_id) FROM journal_lines WHERE 1 = 0)
                  + (SELECT COUNT(show_in_pos) FROM payment_methods WHERE 1 = 0)
                  + (SELECT COUNT(class_id) FROM pos_terminals WHERE 1 = 0)',
        'database/migrations/2026_09_21_asset_maintenance_debit_memo_logo.sql' =>
            'SELECT (SELECT COUNT(*) FROM asset_locations WHERE 1 = 0)
                  + (SELECT COUNT(location_id) + COUNT(class_id) + COUNT(current_meter) FROM fixed_assets WHERE 1 = 0)
                  + (SELECT COUNT(txn_id) + COUNT(schedule_id) FROM asset_maintenance WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM asset_maintenance_parts WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM asset_maintenance_labor WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM asset_maintenance_photos WHERE 1 = 0)
                  + (SELECT COUNT(next_due_meter) FROM asset_pm_schedules WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM asset_pm_log WHERE 1 = 0)',
        'database/migrations/2026_09_22_memorized_budgets_imports.sql' =>
            'SELECT (SELECT COUNT(*) FROM memorized_txns WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM budgets WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM budget_lines WHERE 1 = 0)
                  + (SELECT COUNT(options) FROM report_layouts WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM import_batches WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM backup_log WHERE 1 = 0)',
        'database/migrations/2026_09_23_item_search_per_transaction.sql' =>
            ['SELECT COUNT(*) FROM settings WHERE setting_key = \'item_search_by_page\'', 1],
        // the column has to exist (the query fails without it) and both report pages have to be there
        'database/migrations/2026_09_24_subsidiary_ledger.sql' =>
            ['SELECT 1 + (SELECT COUNT(with_sl) FROM accounts WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM pages WHERE page_key IN (\'rpt.subsidiary_ledger\', \'rpt.schedule_of_accounts\'))', 3],
        // settings only: all six rows of the Bluetooth receipt printer have to be there
        'database/migrations/2026_09_25_bluetooth_receipt_printer.sql' =>
            ['SELECT COUNT(*) FROM settings WHERE setting_key IN
                (\'bt_printer_enabled\', \'bt_printer_width\', \'bt_printer_name\', \'bt_printer_feed\', \'bt_printer_cut\', \'bt_printer_autoprint\')', 6],
        // the two columns have to be there (the query fails without them)
        'database/migrations/2026_09_26_pos_default_site_class.sql' =>
            'SELECT COUNT(pos_default_site_id) + COUNT(pos_default_class_id) FROM users WHERE 1 = 0',
        // data only: both Find pages have to be there
        'database/migrations/2026_09_27_find_report_design.sql' =>
            ['SELECT COUNT(*) FROM pages WHERE page_key IN (\'home.find\', \'rpt.find\')', 2],
        // the approval columns of users have to be there, and the four company pages
        'database/migrations/2026_09_28_multi_company.sql' =>
            ['SELECT 4 + (SELECT COUNT(approval_status) + COUNT(created_via) + COUNT(approved_by) + COUNT(approved_at) + COUNT(rejected_reason) + COUNT(is_platform) FROM users WHERE 1 = 0)
                       - (SELECT COUNT(*) FROM pages WHERE page_key IN (\'company.profile\', \'company.users\', \'company.join_requests\', \'admin.multi_company\'))', 0],
        // the saved service charge and interest of a reconciliation (the query fails without the columns)
        'database/migrations/2026_09_29_reconcile_saved_charges.sql' =>
            'SELECT COUNT(service_charge) + COUNT(service_date) + COUNT(service_account_id) + COUNT(interest_earned)
                  + COUNT(interest_date) + COUNT(interest_account_id) + COUNT(class_id) FROM bank_reconciliations WHERE 1 = 0',
        // the approval_codes table (the query fails without it) and its page have to be there
        'database/migrations/2026_10_01_price_levels_approval_codes.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key = \'pos.approval_codes\') + (SELECT COUNT(*) FROM approval_codes WHERE 1 = 0)', 1],
        // the Category List and the five reports have to be there, and the two shift columns (the query fails without them)
        'database/migrations/2026_10_03_categories_reports_shift_corrections.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key IN (\'inv.categories\', \'rpt.income_customer_summary\', \'rpt.income_customer_detail\', \'rpt.expenses_vendor_summary\', \'rpt.expenses_vendor_detail\', \'rpt.inv_no_movement\'))
                       + (SELECT COUNT(corrected_by) + COUNT(corrected_at) FROM pos_shifts WHERE 1 = 0)', 6],
        // the Customized Inventory Report has to be there, and the POS shift of a deposit line (the query fails without it)
        'database/migrations/2026_10_08_custom_report_deposit_edit.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key = \'rpt.inv_custom\') + (SELECT COUNT(pos_shift_id) FROM transaction_lines WHERE 1 = 0)', 1],
        // the BIR Reports module pages, the advances page, the withholding tables, the wht_ids columns and the default accounts
        'database/migrations/2026_10_15_withholding_bir_advances.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key IN (\'bir.wht_table\', \'bir.form_2307\', \'vend.advances\', \'rpt.bir_2550q\'))
                       + (SELECT COUNT(*) FROM default_accounts WHERE setting_key IN (\'wht_payable\', \'vat_withheld_payable\', \'cvat_withheld\', \'vendor_advances\') AND account_id IS NOT NULL)
                       + (SELECT COUNT(*) FROM withholding_taxes WHERE 1 = 0) + (SELECT COUNT(*) FROM payment_withholdings WHERE 1 = 0)
                       + (SELECT COUNT(v.wht_ids) + COUNT(c.wht_ids) FROM vendors v, customers c WHERE 1 = 0)', 8],
        // the inventory movement reports and their drill-down page
        'database/migrations/2026_10_22_inventory_movement_reports.sql' =>
            ['SELECT COUNT(*) FROM pages WHERE page_key IN (\'rpt.inv_movement_summary\', \'rpt.inv_movement_detail\', \'rpt.inv_movement_txns\', \'rpt.sales_inv_movement\')', 4],
        // the Ending Inventory - POS Cashier report
        'database/migrations/2026_10_29_pos_ending_inventory_shift_times.sql' =>
            ['SELECT COUNT(*) FROM pages WHERE page_key = \'rpt.pos_ending_inventory\'', 1],
        // item groups: the group columns on lines and items, the group sales table, received_via, the two reports
        'database/migrations/2026_11_05_item_groups_receiving_lists.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key IN (\'rpt.sales_item_group\', \'rpt.pos_sales_item_group\'))
                       + (SELECT COUNT(group_ref) + COUNT(group_role) FROM transaction_lines WHERE 1 = 0)
                       + (SELECT COUNT(group_variance_account_id) FROM items WHERE 1 = 0) + (SELECT COUNT(received_via) FROM transactions WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM item_group_sales WHERE 1 = 0)', 2],
        // comparative statements and the Financial Set-up & Reports menu (named Financial since v32)
        'database/migrations/2026_11_12_comparative_statements_menu.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key LIKE \'rpt.cmp\\_%\') + (SELECT COUNT(*) FROM modules WHERE code = \'coa\' AND name IN (\'Financial Set-up & Reports\', \'Financial\'))', 9],
        // v31: the quantities on hand by site set to the totals of the movements; the Average Cost Changes report
        'database/migrations/2026_11_26_average_cost_books.sql' =>
            ['SELECT COUNT(*) FROM pages WHERE page_key = \'rpt.avg_cost_changes\'', 1],
        // v32: the table of the receipts ticked to settle (the query fails without it), and the Financial menu
        'database/migrations/2026_12_03_settle_receipts_financial_menu.sql' =>
            ['SELECT (SELECT COUNT(*) FROM clearing_settlements WHERE 1 = 0) + (SELECT COUNT(*) FROM modules WHERE code = \'coa\' AND name = \'Financial\')', 1],
        // v33: the Trial Balance by Class and the Statement of Cash Flows by Class
        'database/migrations/2026_12_10_class_reports.sql' =>
            ['SELECT COUNT(*) FROM pages WHERE page_key IN (\'rpt.trial_balance_class\', \'rpt.cash_flow_class\')', 2],
    ];

    private static ?array $pending = null;

    public static function flush(): void
    {
        self::$pending = null;
    }

    /** Upgrade scripts still to be run on this request's database, oldest first (empty = up to date) */
    public static function pending(): array
    {
        if (self::$pending !== null) {
            return self::$pending;
        }
        // remembered per database: a Super Admin moves between companies in one session
        $flag = self::FLAG . ':' . DB::databaseName();
        $inSession = session_status() === PHP_SESSION_ACTIVE;
        if ($inSession && !empty($_SESSION[$flag])) {
            return self::$pending = [];
        }
        $out = self::pendingWith(fn(string $sql) => DB::value($sql));
        if (!$out && $inSession) {
            $_SESSION[$flag] = 1;
        }
        return self::$pending = $out;
    }

    /** Upgrade scripts still to be run on another company's database (the platform's update page) */
    public static function pendingOn(Sql $db): array
    {
        return self::pendingWith(fn(string $sql) => $db->value($sql));
    }

    /** @return string[] every upgrade script the program knows, oldest first */
    public static function files(): array
    {
        return array_keys(self::MIGRATIONS);
    }

    private static function pendingWith(callable $value): array
    {
        $out = [];
        foreach (self::MIGRATIONS as $file => $probe) {
            [$sql, $want] = is_array($probe) ? $probe : [$probe, null];
            try {
                $got = $value($sql);
                if ($want !== null && (int)$got !== (int)$want) {
                    $out[] = $file;
                }
            } catch (Throwable $e) {
                $out[] = $file;
            }
        }
        return $out;
    }

    public static function ready(): bool
    {
        return !self::pending();
    }
}
