<?php
declare(strict_types=1);

/** Key/value company, security and module settings. */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'company_name'            => 'My Company',
        'company_address'         => '',
        'company_tin'             => '',
        'company_phone'           => '',
        'company_email'           => '',
        'fiscal_year_start_month' => '1',
        'closing_date'            => '',
        'pw_min_length'           => '8',
        'pw_require_upper'        => '1',
        'pw_require_number'       => '1',
        'pw_require_symbol'       => '1',
        'pw_expiry_days'          => '90',
        'max_failed_logins'       => '5',
        'session_timeout_minutes' => '30',
        'audit_retention_years'   => '10',
        'finance_charge_rate'     => '12',
        'finance_charge_min'      => '50',
        'finance_charge_grace_days' => '0',
        'finance_charge_on_charges' => '0',
        'pos_posting_mode'        => 'per_sale',
        'costing_method'          => 'weighted_average',
        'allow_negative_stock'    => '1',
        // item groups: their sales kept apart (item_group_sales, Sales by Item Group) and the group price followed on sales
        'track_group_sales'       => '1',
        'adopt_group_price'       => '1',
        'block_zero_cost_sales'   => '1',   // v29: no sale of an inventory part or item group item with no unit cost
        'block_zero_cost_processing' => '1', // v29: no processing order posted with an input or output at no unit cost
        'large_discount_pct'      => '10',
        // wording printed on POS receipts (System Administration > Company, Security & Closing Date)
        'pos_receipt_title'         => 'OFFICIAL RECEIPT',
        'pos_receipt_title_return'  => 'RETURN / REFUND',
        'pos_receipt_title_charge'  => 'CHARGE INVOICE',
        'pos_receipt_title_payment' => 'ACKNOWLEDGEMENT RECEIPT',
        'pos_receipt_footer'        => "Thank you for shopping!\nThis serves as your official receipt.",
        'pos_receipt_show_customer' => '1',
        // receipts printed straight from the browser to a Bluetooth roll printer (app/Receipt.php, assets/js/btprint.js)
        'bt_printer_enabled'        => '0',
        'bt_printer_width'          => '32',
        'bt_printer_name'           => '',
        'bt_printer_feed'           => '4',
        'bt_printer_cut'            => '0',
        'bt_printer_autoprint'      => '0',
        // company logo: file name inside storage/company/, and where it is printed (one key per line)
        // how items are shown in the drop-downs of the transaction forms:
        // code_name = item name/code first, then the display name; name_code = display name first, then the item name/code
        // item_search_format is what a transaction uses when nothing is chosen for it on its own;
        // item_search_by_page holds the transactions that were set differently, one "page key=format" per line
        'item_search_format'        => 'code_name',
        'item_search_by_page'       => '',
        'company_logo'              => '',
        // BIR: the company withholds taxes from its suppliers, and what BIR Form 2307 prints for the payor
        'withholding_agent'         => '1',
        'bir_zip_code'              => '',
        'bir_signatory_name'        => '',
        'bir_signatory_title'       => '',
        'bir_signatory_tin'         => '',
        'logo_targets'              => '',
        // ---------------------------------------------------------------- shipping operations (Shipping Set-up > Shipping Policies & Posting Accounts)
        'shp_revenue_policy'        => 'deferred',   // deferred = fares are a liability until the voyage is completed; on_sale = revenue when sold
        'shp_vat_inclusive'         => '1',          // published fares include VAT
        'shp_counter_hold_minutes'  => '20',         // seats held at the counter while the passenger pays
        'shp_portal_hold_minutes'   => '120',        // a portal reservation is kept this long for payment at a ticket office
        'shp_booking_close_minutes' => '30',         // sales stop this many minutes before departure
        'shp_max_pax_per_booking'   => '20',         // anti-scalping limit for one booking
        'shp_cancel_fee_pct'        => '20',         // fare kept when the passenger cancels early
        'shp_cancel_late_fee_pct'   => '50',         // fare kept when the passenger cancels within the late window
        'shp_cancel_late_hours'     => '24',
        'shp_change_fee'            => '150',        // rebooking / date change fee per ticket (VAT included)
        'shp_refund_fees_unused'    => '1',          // terminal fees and insurance of an unused ticket are refunded in full
        'shp_noshow_policy'         => 'forfeit',    // forfeit = a no-show fare is earned when the voyage is completed
        'shp_ticket_is_invoice'     => '0',          // 0 = the e-ticket is a travel document; the invoice / receipt is a separate accounting document
        'shp_ticket_terms'          => "Present this e-ticket (printed or on your phone) and a valid ID at the boarding gate at least 30 minutes before departure.\nPassengers availing of statutory discounts must present the required ID.\nTickets are valid only for the voyage shown. Changes and refunds follow the company's published policy.",
        'shp_qr_secret'             => '',
        'shp_post_labor'            => '1',          // job-card labor is costed to the vessel through a journal (Repairs / Labor Absorbed)
        'shp_labor_rate'            => '250',        // default hourly rate for job-card labor
        'shp_fuel_variance_pct'     => '2',          // ROB variance above this % of the voyage consumption is flagged
        'shp_sla_urgent_hours'      => '4',
        'shp_sla_high_hours'        => '12',
        'shp_sla_normal_hours'      => '48',
        'shp_sla_low_hours'         => '96',
        'shp_email_provider'        => '',           // blank = no e-mail provider connected: notices stay in the queue as "No provider"
        'shp_sms_provider'          => '',
        'shp_gateway_provider'      => '',           // blank = no online payment gateway connected
        'shp_offline_scans'         => '1',          // boarding scanners may queue scans while offline (reconciled when back online)
        'shp_large_refund_amount'   => '5000',       // refunds above this need a manager in the Approvals Queue (every refund needs another person)
    ];

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach (DB::all('SELECT setting_key, setting_value FROM settings') as $r) {
                    self::$cache[$r['setting_key']] = (string)$r['setting_value'];
                }
            } catch (Throwable $e) {
                // table not installed yet
            }
        }
        return self::$cache;
    }

    /** Forget what was read (another company's database was switched on) */
    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::all()[$key] ?? $default;
    }

    public static function int(string $key): int
    {
        return (int)self::get($key, '0');
    }

    public static function set(string $key, string $value): void
    {
        DB::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['k' => $key, 'v' => $value]
        );
        self::$cache = null;
    }

    public static function closingDate(): ?string
    {
        $d = self::get('closing_date');
        return valid_date($d) ? $d : null;
    }

    // ------------------------------------------------------------------ searching for items

    /**
     * Every transaction whose item boxes follow the "Searching for items" setting, in the order
     * they are listed on the settings page. Page key => the name the user knows it by.
     */
    public const ITEM_SEARCH_PAGES = [
        'cust.estimates'       => 'Create Estimates',
        'cust.sales_orders'    => 'Create Sales Orders',
        'cust.invoices'        => 'Create Invoices',
        'cust.batch_invoices'  => 'Create Batch Invoices',
        'cust.sales_receipts'  => 'Enter Sales Receipts',
        'cust.credit_memos'    => 'Create Credit Memos/Refunds',
        'vend.bills'           => 'Enter Bills',
        'vend.debit_memos'     => 'Supplier Debit Memos',
        'vend.receipts'        => 'Receipt Management',
        'vend.purchase_orders' => 'Create Purchase Orders',
        'vend.receive_bill'    => 'Receive Items and Enter Bills',
        'vend.receive_items'   => 'Receive Items',
        'vend.bill_received'   => 'Enter Bill for Received Items',
        'inv.transfers'        => 'Transfer Inventory',
        'inv.adjust'           => 'Adjust Quantity/Value on Hand',
        'inv.build'            => 'Build Assemblies',
        'inv.process'          => 'Disassemble / Process Items',
        'inv.physical_count'   => 'Physical Inventory Count',
        'inv.change_prices'    => 'Change Item Prices',
        'inv.set_prices'       => 'Set Item Prices and Levels',
        'pos.register'         => 'POS Register',
        'bank.write_checks'    => 'Write Checks',
    ];

    public const ITEM_FORMATS = [
        'code_name' => 'Item name/code + Display name',
        'name_code' => 'Display name + Item name/code',
    ];

    /** The transactions that were set differently from the overall choice: page key => format */
    public static function itemFormatMap(): array
    {
        static $map = null;
        static $from = null;
        $raw = self::get('item_search_by_page');
        if ($map !== null && $from === $raw) {
            return $map;
        }
        $from = $raw;
        $map = [];
        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            [$k, $v] = array_pad(explode('=', trim($line), 2), 2, '');
            $k = trim($k);
            $v = trim($v);
            if ($k !== '' && isset(self::ITEM_FORMATS[$v])) {
                $map[$k] = $v;
            }
        }
        return $map;
    }

    /**
     * Which way round the items are listed on one transaction: what was chosen for that transaction,
     * or the overall choice when nothing was. With no page given it is the page being looked at.
     */
    public static function itemFormat(?string $pageKey = null): string
    {
        $pageKey = $pageKey ?? (string)($GLOBALS['CURRENT_PAGE'] ?? '');
        $map = self::itemFormatMap();
        if ($pageKey !== '' && isset($map[$pageKey])) {
            return $map[$pageKey];
        }
        $all = self::get('item_search_format', 'code_name');
        return isset(self::ITEM_FORMATS[$all]) ? $all : 'code_name';
    }

    /** Saves the per-transaction choices; a transaction that matches the overall choice is not stored */
    public static function saveItemFormats(string $overall, array $byPage): void
    {
        $overall = isset(self::ITEM_FORMATS[$overall]) ? $overall : 'code_name';
        $lines = [];
        foreach (self::ITEM_SEARCH_PAGES as $key => $label) {
            $v = (string)($byPage[$key] ?? '');
            if (isset(self::ITEM_FORMATS[$v]) && $v !== $overall) {
                $lines[] = $key . '=' . $v;
            }
        }
        self::set('item_search_format', $overall);
        self::set('item_search_by_page', implode("\n", $lines));
    }
}
