<?php
declare(strict_types=1);

$page = 'shp.settings';
$fields = [
    'Revenue and taxes' => [
        'shp_revenue_policy' => ['Fare revenue is recognized', 'select', ['deferred' => 'When the voyage is completed (deferred until then)', 'on_sale' => 'When the ticket is sold']],
        'shp_vat_inclusive' => ['Published fares and rates include VAT', 'bool'],
        'shp_noshow_policy' => ['No-show fares', 'select', ['forfeit' => 'Earned when the voyage is completed (forfeited)']],
        'shp_ticket_is_invoice' => ['The e-ticket is the BIR invoice (only with a registered system that allows it)', 'bool'],
    ],
    'Sales, holds and changes' => [
        'shp_counter_hold_minutes' => ['Counter hold while the passenger pays (minutes)', 'int'],
        'shp_portal_hold_minutes' => ['Portal reservation kept for payment at an office (minutes)', 'int'],
        'shp_booking_close_minutes' => ['Sales close before departure (minutes)', 'int'],
        'shp_max_pax_per_booking' => ['Most passengers in one booking (anti-scalping)', 'int'],
        'shp_change_fee' => ['Rebooking / change fee per ticket (VAT incl.)', 'num'],
        'shp_cancel_fee_pct' => ['Fare kept when the passenger cancels (%)', 'num'],
        'shp_cancel_late_fee_pct' => ['Fare kept on a late cancellation (%)', 'num'],
        'shp_cancel_late_hours' => ['A cancellation is late within (hours of departure)', 'int'],
        'shp_refund_fees_unused' => ['Terminal fee and insurance of an unused ticket are refunded', 'bool'],
        'shp_large_refund_amount' => ['Refunds above this amount go to a manager', 'num'],
        'shp_ticket_terms' => ['Terms printed on the e-ticket', 'text'],
    ],
    'Operations, maintenance and service' => [
        'shp_fuel_variance_pct' => ['Fuel variance flagged above (% of planned)', 'num'],
        'shp_post_labor' => ['Job-card labor is costed to the vessel in the books', 'bool'],
        'shp_labor_rate' => ['Default job-card labor rate per hour', 'num'],
        'shp_offline_scans' => ['Boarding scanners may queue scans while offline', 'bool'],
        'shp_sla_urgent_hours' => ['Service time: urgent cases (hours)', 'int'],
        'shp_sla_high_hours' => ['Service time: high (hours)', 'int'],
        'shp_sla_normal_hours' => ['Service time: normal (hours)', 'int'],
        'shp_sla_low_hours' => ['Service time: low (hours)', 'int'],
    ],
    'Integrations (connected only with real credentials)' => [
        'shp_email_provider' => ['E-mail provider (blank = none connected)', 'str'],
        'shp_sms_provider' => ['SMS provider (blank = none connected)', 'str'],
        'shp_gateway_provider' => ['Online payment gateway (blank = none connected)', 'str'],
    ],
];

handle_post(function () use ($page, $fields) {
    ship_require($page, 'edit');
    $changes = [];
    foreach ($fields as $group) {
        foreach ($group as $k => [$label, $type]) {
            $v = $type === 'bool' ? (p($k) === '1' ? '1' : '0') : trim((string)p($k));
            if ($type === 'int' && ($v === '' || (int)$v < 0)) {
                fail($label . ': enter a whole number.');
            }
            if ($type === 'num' && ($v === '' || !is_numeric($v) || (float)$v < 0)) {
                fail($label . ': enter a number.');
            }
            $old = Settings::get($k);
            if ($old !== $v) {
                Settings::set($k, $v);
                $changes[] = ['field' => $label, 'old' => $old, 'new' => $v];
            }
        }
    }
    if ($changes) {
        Audit::log('edit', 'shp', 'settings', null, 'Shipping policies', ['changes' => $changes, 'page_key' => $page]);
    }
    flash('success', count($changes) . ' setting(s) changed.');
    redirect(url($page));
});

$can = Access::can($page, 'edit');
layout_start('Shipping Policies & Posting Accounts', ['actions' => Access::page('coa.default_accounts') ? btn_link(url('coa.default_accounts'), 'Change the posting accounts', 'btn-outline-primary', 'diagram-3') : '']);
?>
<div class="row g-3">
<div class="col-xl-7">
<form method="post"><?= csrf_field() ?><fieldset <?= $can ? '' : 'disabled' ?>>
<?php foreach ($fields as $group => $list): ?>
  <div class="card mb-3 reveal"><div class="card-header"><?= h($group) ?></div><div class="card-body"><div class="row g-3">
  <?php foreach ($list as $k => $f): [$label, $type] = $f; $val = Settings::get($k); ?>
    <?php if ($type === 'bool'): ?><?= check_field($k, $label, $val === '1', 'col-12') ?>
    <?php elseif ($type === 'select'): ?><?= select_field($k, $label, $f[2], $val, ['class' => 'col-md-8']) ?>
    <?php elseif ($type === 'text'): ?><div class="col-12"><label class="form-label"><?= h($label) ?></label><textarea class="form-control form-control-sm" name="<?= h($k) ?>" rows="3"><?= h($val) ?></textarea></div>
    <?php else: ?><?= input_field($k, $label, $val, ['type' => $type === 'str' ? 'text' : 'number', 'class' => 'col-md-6', 'step' => $type === 'int' ? '1' : '0.01']) ?>
    <?php endif; ?>
  <?php endforeach; ?>
  </div></div></div>
<?php endforeach; ?>
<?php if ($can): ?><button class="btn btn-primary">Save policies</button><?php endif; ?>
</fieldset></form>
</div>
<div class="col-xl-5">
  <div class="card reveal mb-3"><div class="card-header">Accounting event matrix (posted automatically)</div>
  <?php
    $a = fn(string $k) => (string)DB::value('SELECT CONCAT(a.code, " ", a.name) FROM default_accounts d JOIN accounts a ON a.id = d.account_id WHERE d.setting_key = :k', ['k' => $k]);
    $deferred = Settings::get('shp_revenue_policy') !== 'on_sale';
    $rows = [
        ['Ticket paid before sailing', 'Undeposited Funds / card or e-wallet clearing', ($deferred ? $a('shp_deferred_pax') : $a('shp_fare_revenue')) . '; ' . $a('output_tax') . '; fees to their payable accounts', 'Sales receipt'],
        ['Corporate / agent ticket on account', $a('ar'), 'as above', 'Invoice'],
        ['Voyage completed', $a('shp_deferred_pax') . '; ' . $a('shp_statutory_disc'), $a('shp_fare_revenue') . '; ' . $a('shp_surcharge_revenue') . '; ' . $a('shp_noshow_revenue'), 'Journal'],
        ['Vehicle / prepaid cargo earned', $a('shp_deferred_freight'), $a('shp_vehicle_revenue') . '; ' . $a('shp_cargo_revenue'), 'Journal'],
        ['Cargo invoice on credit (after delivery)', $a('ar'), $a('shp_cargo_revenue') . '; ' . $a('output_tax'), 'Invoice'],
        ['Customer pays a receivable', 'Bank / Undeposited Funds', $a('ar'), 'Receive Payments'],
        ['Terminal fee collected as agent', 'Cash / clearing', $a('shp_terminal_payable'), 'Sales receipt line'],
        ['Terminal fee remitted', $a('shp_terminal_payable'), 'Bank', 'Check'],
        ['Fuel burnt on a voyage', $a('shp_fuel_expense'), 'Inventory - Fuel (average cost)', 'Inventory adjustment'],
        ['Spare part issued to a repair', $a('shp_spares_expense') . ' or ' . $a('shp_capex_wip'), 'Inventory - Spare Parts', 'Inventory adjustment'],
        ['Job-card labor', $a('shp_repairs_expense'), $a('shp_labor_absorbed'), 'Journal'],
        ['Supplier invoice approved', 'Inventory / expense; ' . $a('input_tax'), $a('ap') . '; withholding where set', 'Bill'],
        ['Supplier paid', $a('ap'), 'Bank; withholding payable', 'Pay Bills'],
        ['Ticket refunded / cancelled', ($deferred ? $a('shp_deferred_pax') : $a('shp_fare_revenue')) . '; ' . $a('output_tax') . '; fee payables', $a('ar') . ' (refund payable), then Bank / cash fund; kept fare to ' . $a('shp_change_fees'), 'Credit memo, customer refund, journal'],
        ['Compensation / claim', $a('shp_claims_expense'), 'Customer credit or Bank', 'Credit memo or check'],
    ];
  ?>
  <div class="table-responsive"><table class="table table-sm small mb-0"><thead><tr><th>Event</th><th>Debit</th><th>Credit</th><th>Document</th></tr></thead><tbody>
  <?php foreach ($rows as [$e, $dr, $cr, $doc]): ?><tr><td class="fw-semibold"><?= h($e) ?></td><td><?= h($dr) ?></td><td><?= h($cr) ?></td><td class="text-muted"><?= h($doc) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="card-body small text-muted">Every document carries the vessel's accounting class and is linked to its voyage or work order. Each event posts once: a repeated request finds its own event key and posts nothing more. The tax treatment shown follows the tax codes set up; have it reviewed by your accountant against the BIR rules that apply to your company.</div>
  </div>
</div>
</div>
<?php
layout_end();
