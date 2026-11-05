<?php
declare(strict_types=1);

/** UI pieces of the shipping theme, used by the layout and the shipping pages. */

function ship_brand_svg(): string
{
    // an original mark: a hull on a swell, under a rising sun line
    return '<svg viewBox="0 0 32 32" fill="none" aria-hidden="true">'
        . '<path d="M6 17.5h20l-2.6 5.2a3 3 0 0 1-2.7 1.6H11.3a3 3 0 0 1-2.7-1.6L6 17.5Z" fill="#fff"/>'
        . '<path d="M11 17.5v-4.2c0-.7.6-1.3 1.3-1.3h7.4c.7 0 1.3.6 1.3 1.3v4.2" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/>'
        . '<path d="M16 12V7.5" stroke="#5fd3c6" stroke-width="1.8" stroke-linecap="round"/>'
        . '<path d="M3 26.5c2.2 0 2.2-1.4 4.4-1.4s2.2 1.4 4.4 1.4 2.2-1.4 4.4-1.4 2.2 1.4 4.4 1.4 2.2-1.4 4.4-1.4 2.2 1.4 4.4 1.4" stroke="#5fd3c6" stroke-width="1.6" stroke-linecap="round"/>'
        . '</svg>';
}

function ship_icon(): string
{
    return '<svg width="22" height="14" viewBox="0 0 22 14" aria-hidden="true"><path d="M1 8h20l-2.2 4.2a1.6 1.6 0 0 1-1.4.8H4.6a1.6 1.6 0 0 1-1.4-.8L1 8Z" fill="currentColor"/>'
        . '<rect x="6" y="3.5" width="10" height="4.5" rx="1" fill="currentColor" opacity=".75"/><rect x="10" y="0.5" width="2" height="3" fill="#ef8a2b"/></svg>';
}

function ship_waves_svg(string $fill1 = 'rgba(95,211,198,.35)', string $fill2 = 'rgba(17,160,170,.35)'): string
{
    $path = 'M0 40 C 150 10, 300 70, 450 40 S 750 10, 900 40 S 1200 70, 1350 40 S 1650 10, 1800 40 L 1800 120 L 0 120 Z';
    return '<svg class="w1" viewBox="0 0 1800 120" preserveAspectRatio="none" aria-hidden="true"><path d="' . $path . '" fill="' . $fill1 . '"/></svg>'
        . '<svg class="w2" viewBox="0 0 1800 120" preserveAspectRatio="none" aria-hidden="true" style="bottom:-8px"><path d="' . $path . '" fill="' . $fill2 . '"/></svg>';
}

/** The animated sea behind the log-in and the public pages */
function ship_sea_scene(): string
{
    $stars = '';
    mt_srand(7);
    for ($i = 0; $i < 38; $i++) {
        $stars .= '<i class="star" style="left:' . mt_rand(0, 100) . '%;top:' . mt_rand(2, 55) . '%;animation-delay:' . (mt_rand(0, 40) / 10) . 's"></i>';
    }
    $wave = fn(string $cls, string $fill, int $amp) => '<svg class="' . $cls . '" viewBox="0 0 2400 200" preserveAspectRatio="none" aria-hidden="true"><path d="M0 ' . (60 + $amp)
        . ' C 200 ' . (20 + $amp) . ', 400 ' . (100 + $amp) . ', 600 ' . (60 + $amp) . ' S 1000 ' . (20 + $amp) . ', 1200 ' . (60 + $amp) . ' S 1600 ' . (100 + $amp) . ', 1800 ' . (60 + $amp)
        . ' S 2200 ' . (20 + $amp) . ', 2400 ' . (60 + $amp) . ' L 2400 200 L 0 200 Z" fill="' . $fill . '"/></svg>';
    $ship = '<svg viewBox="0 0 150 60" aria-hidden="true"><path d="M8 38h134l-10 14a6 6 0 0 1-5 3H24a6 6 0 0 1-5-3L8 38Z" fill="#e9f2f6"/>'
        . '<rect x="36" y="24" width="72" height="14" rx="2" fill="#f7fbfd"/><rect x="50" y="13" width="44" height="11" rx="2" fill="#ffffff"/>'
        . '<rect x="66" y="4" width="8" height="9" fill="#ef8a2b"/>'
        . '<g fill="#5fa8c4"><rect x="42" y="28" width="6" height="5" rx="1"/><rect x="53" y="28" width="6" height="5" rx="1"/><rect x="64" y="28" width="6" height="5" rx="1"/><rect x="75" y="28" width="6" height="5" rx="1"/><rect x="86" y="28" width="6" height="5" rx="1"/><rect x="97" y="28" width="6" height="5" rx="1"/></g>'
        . '<path d="M8 38h134" stroke="#c23b3b" stroke-width="3"/></svg>';
    return '<div class="sea-scene" aria-hidden="true">' . $stars . '<span class="light"></span><div class="vessel">' . $ship . '</div><div class="waves">'
        . $wave('c', 'rgba(17,160,170,.25)', 0) . $wave('b', 'rgba(11,122,135,.45)', 30) . $wave('a', 'rgba(8,80,98,.85)', 60) . '</div></div>';
}

function kpi_tile(string $label, string $value, string $icon, string $sub = '', string $tone = '', ?string $href = null, ?float $countTo = null): string
{
    $tag = $href ? 'a' : 'div';
    $val = $countTo !== null ? '<span data-count="' . h((string)$countTo) . '">' . h($value) . '</span>' : h($value);
    return '<' . $tag . ' class="kpi-tile reveal ' . ($tone ? 'tone-' . h($tone) : '') . '"' . ($href ? ' href="' . h($href) . '"' : '') . '>'
        . '<span class="k-ico"><i class="bi bi-' . h($icon) . '"></i></span><div class="k-label">' . h($label) . '</div>'
        . '<div class="k-value">' . $val . '</div>' . ($sub !== '' ? '<div class="k-sub">' . $sub . '</div>' : '') . '</' . $tag . '>';
}

function meter(float $pct, string $tone = ''): string
{
    $pct = max(0, min(100, $pct));
    return '<div class="meter ' . h($tone) . '"><span data-w="' . round($pct, 1) . '"></span></div>';
}

/** Position of the ship on the route line for a voyage status (0 - 100) */
function voyage_progress(array $v): array
{
    $now = strtotime(Ship::now());
    switch ($v['status']) {
        case 'departed':
            $span = max(1, strtotime($v['eta']) - strtotime($v['atd'] ?: $v['etd']));
            return [min(92, max(8, 100 * ($now - strtotime($v['atd'] ?: $v['etd'])) / $span)), true];
        case 'arrived':
        case 'completed':
            return [100, false];
        default:
            return [0, false];
    }
}

function voyage_card(array $v, ?string $href = null, string $extra = ''): string
{
    [$p, $moving] = voyage_progress($v);
    $tag = $href ? 'a' : 'div';
    return '<' . $tag . ' class="voyage-card reveal"' . ($href ? ' href="' . h($href) . '"' : '') . '>'
        . '<div><div class="vno">' . h($v['voyage_no']) . '</div><div class="vmeta">' . h($v['vessel_name']) . '</div><div class="mt-1">' . Ship::label($v['status']) . '</div></div>'
        . '<div class="route-line"><div class="port"><b>' . h($v['origin_code']) . '</b><span>' . h(date('M j g:i A', strtotime($v['etd']))) . '</span></div>'
        . '<div class="sea-track" style="--p:' . round($p, 1) . '%"><span class="done"></span><span class="ship' . ($moving ? ' moving' : '') . '">' . ship_icon() . '</span></div>'
        . '<div class="port"><b>' . h($v['dest_code']) . '</b><span>' . h(date('M j g:i A', strtotime($v['eta']))) . '</span></div></div>'
        . '<div class="text-lg-end">' . $extra . '</div></' . $tag . '>';
}

function tabs_line(array $tabs, string $current): string
{
    $out = '<nav class="tabs-line no-print">';
    foreach ($tabs as $key => [$label, $href, $n]) {
        $out .= '<a class="' . ($key === $current ? 'on' : '') . '" href="' . h($href) . '">' . h($label) . ($n !== null ? '<span class="n">' . h((string)$n) . '</span>' : '') . '</a>';
    }
    return $out . '</nav>';
}

function empty_note(string $text, string $icon = 'water'): string
{
    return '<div class="empty-note"><i class="bi bi-' . h($icon) . '"></i>' . h($text) . '</div>';
}

function info_pair(string $label, $value, bool $raw = false): string
{
    return '<div><span>' . h($label) . '</span><b>' . ($raw ? $value : h((string)$value)) . '</b></div>';
}

/** The flow of a record as steps, the current one marked */
function flow_steps(array $steps, string $current): string
{
    $out = '<ol class="flow-steps">';
    $passed = true;
    foreach ($steps as $k => $label) {
        $cls = $k === $current ? 'now' : ($passed ? 'done' : '');
        if ($k === $current) {
            $passed = false;
        }
        $out .= '<li class="' . $cls . '">' . h($label) . '</li>';
    }
    return $out . '</ol>';
}

function txn_doc_link(?int $txnId, ?string $text = null): string
{
    if (!$txnId) {
        return '';
    }
    return Txn::link($txnId, $text);
}

/** The accounting documents posted for a shipping record, as a small table */
function ship_documents_table(array $docs): string
{
    if (!$docs) {
        return empty_note('Nothing posted to the books yet.', 'journal-x');
    }
    $out = '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Document</th><th>Date</th><th>Event</th><th class="num">Amount</th></tr></thead><tbody>';
    foreach ($docs as $d) {
        $out .= '<tr><td>' . ($d['txn_id'] ? Txn::link((int)$d['txn_id'], Txn::label((string)$d['txn_type']) . ' ' . $d['txn_no']) : '') . ((int)($d['is_voided'] ?? 0) ? ' <span class="st st-bad">Void</span>' : '') . '</td>'
            . '<td>' . h(fdate($d['txn_date'])) . '</td><td class="small text-muted">' . h((string)$d['memo']) . '</td><td class="num">' . money($d['total'] ?? $d['amount']) . '</td></tr>';
    }
    return $out . '</tbody></table></div>';
}

/** Page access helper for the shipping pages: stops with the access-denied page */
function ship_require(string $page, string $action): void
{
    if (!Access::can($page, $action)) {
        Access::denyPage($page, $action);
    }
}
