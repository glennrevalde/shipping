<?php
declare(strict_types=1);

/**
 * A list-and-form page for a shipping master table (ports, vehicle classes, passenger categories, fees, offices ...).
 *
 * cfg:
 *   table, page, title, order, intro, singular
 *   fields  name => [label, type (text|number|select|check|date|textarea), options|null, required, column class, help]
 *   list    name => [label, kind (text|money|num|bool|select|date|html), callable|null]
 *   unique  field that must be unique (e.g. code), upper (bool) to upper-case it
 *   active  name of the is_active column (rows are switched off, never deleted, once used)
 *   before_save fn(array $row, ?array $old): array   (validate / transform; fail() to refuse)
 *   after_save  fn(int $id, array $row, ?array $old)
 *   search  fields searched by the box above the list
 */
function ship_master_page(array $cfg): void
{
    $page = $cfg['page'];
    $table = $cfg['table'];
    $fields = $cfg['fields'];
    $a = (string)g('a');
    $id = gint('id');
    $edit = $id ? DB::row("SELECT * FROM `$table` WHERE id = :id", ['id' => $id]) : null;
    $active = $cfg['active'] ?? 'is_active';

    handle_post(function () use ($cfg, $page, $table, $fields, $edit, $active) {
        if (p('toggle') !== '' && $edit) {
            ship_require($page, 'delete');
            $now = (int)$edit[$active] === 1 ? 0 : 1;
            DB::update($table, [$active => $now], 'id = :id', ['id' => $edit['id']]);
            Ship::audit($now ? 'activate' : 'deactivate', 'shp', $table, $edit['id'], ($cfg['singular'] ?? 'Record') . ' ' . ($edit['code'] ?? $edit['name'] ?? $edit['id']), ['page_key' => $page]);
            flash('success', ($cfg['singular'] ?? 'Record') . ' ' . ($now ? 'switched on' : 'switched off') . '.');
            redirect(url($page));
        }
        ship_require($page, $edit ? 'edit' : 'create');
        $row = [];
        foreach ($fields as $name => $f) {
            [$label, $type] = $f;
            $req = !empty($f[3]);
            if ($type === 'users') {
                continue;
            }
            if ($type === 'check') {
                $row[$name] = p($name) === '1' ? 1 : 0;
                continue;
            }
            $v = trim((string)p($name));
            if ($req && $v === '') {
                fail('Enter the ' . strtolower($label) . '.');
            }
            if ($type === 'number') {
                $row[$name] = $v === '' ? null : to_num($v);
            } elseif ($type === 'date') {
                $row[$name] = $v !== '' && valid_date($v) ? $v : null;
            } elseif ($type === 'select') {
                $row[$name] = $v === '' ? null : $v;
            } else {
                $row[$name] = $v === '' ? null : mb_substr($v, 0, 500);
            }
        }
        if (!empty($cfg['unique'])) {
            $u = $cfg['unique'];
            if (!empty($cfg['upper'])) {
                $row[$u] = strtoupper((string)$row[$u]);
            }
            $dup = DB::value("SELECT id FROM `$table` WHERE `$u` = :v" . ($edit ? ' AND id <> :id' : ''), $edit ? ['v' => $row[$u], 'id' => $edit['id']] : ['v' => $row[$u]]);
            if ($dup) {
                fail('Another record already has ' . $fields[$u][0] . ' ' . $row[$u] . '.');
            }
        }
        if (isset($cfg['before_save'])) {
            $row = ($cfg['before_save'])($row, $edit);
        }
        if ($edit) {
            DB::update($table, $row, 'id = :id', ['id' => $edit['id']]);
            $newId = (int)$edit['id'];
            Ship::audit('edit', 'shp', $table, $newId, ($cfg['singular'] ?? 'Record') . ' ' . ($row['code'] ?? $row['name'] ?? $newId), ['changes' => Ship::changes($edit, $row), 'page_key' => $page]);
        } else {
            $newId = DB::insert($table, $row);
            Ship::audit('create', 'shp', $table, $newId, ($cfg['singular'] ?? 'Record') . ' ' . ($row['code'] ?? $row['name'] ?? $newId), ['page_key' => $page]);
        }
        if (isset($cfg['after_save'])) {
            ($cfg['after_save'])($newId, $row, $edit);
        }
        flash('success', ($cfg['singular'] ?? 'Record') . ' saved.');
        redirect_after_save(url($page, ['a' => 'new']), url($page));
    });

    $showForm = $a === 'new' || $edit || is_post();
    $actions = Access::can($page, 'create') && !$showForm ? btn_link(url($page, ['a' => 'new']), 'New ' . strtolower($cfg['singular'] ?? 'record'), 'btn-primary', 'plus-lg') : '';
    layout_start($cfg['title'], ['actions' => $actions]);
    if (!empty($cfg['intro'])) {
        echo '<p class="text-muted small mb-3">' . $cfg['intro'] . '</p>';
    }
    if ($showForm) {
        $can = Access::can($page, $edit ? 'edit' : 'create');
        echo '<div class="card mb-3 reveal"><div class="card-header">' . ($edit ? 'Edit ' : 'New ') . h(strtolower($cfg['singular'] ?? 'record')) . '</div><div class="card-body">';
        echo '<form method="post">' . csrf_field() . '<fieldset ' . ($can ? '' : 'disabled') . '><div class="row g-3">';
        foreach ($fields as $name => $f) {
            [$label, $type] = $f;
            $opts = $f[2] ?? null;
            $req = !empty($f[3]);
            $cls = $f[4] ?? 'col-md-4';
            $help = $f[5] ?? '';
            $val = fv($name, $edit, $f[6] ?? '');
            if ($type === 'users') {
                $picked = $edit ? DB::column('SELECT user_id FROM shp_user_offices WHERE office_id = :o', ['o' => $edit['id']]) : [];
                echo multi_check_field('users', $label, DB::pairs("SELECT id, CONCAT(full_name, ' (', username, ')') FROM users WHERE status = 'active' AND is_platform = 0 ORDER BY full_name"),
                    array_map('strval', (array)($_POST['users'] ?? $picked)), ['class' => $cls, 'all' => 'Nobody assigned', 'noun' => 'users']);
                continue;
            }
            if ($type === 'select') {
                echo select_field($name, $label, is_callable($opts) ? $opts() : (array)$opts, $val, ['class' => $cls, 'blank' => $req ? null : '(none)', 'required' => $req]);
            } elseif ($type === 'check') {
                echo check_field($name, $label, (bool)$val, $cls);
            } elseif ($type === 'textarea') {
                echo '<div class="' . h($cls) . '"><label class="form-label">' . h($label) . '</label><textarea class="form-control form-control-sm" name="' . h($name) . '" rows="3">' . h($val) . '</textarea></div>';
            } else {
                echo input_field($name, $label, $val, ['type' => $type === 'number' ? 'number' : ($type === 'date' ? 'date' : 'text'), 'class' => $cls, 'required' => $req, 'step' => $f[7] ?? '0.01']);
            }
            if ($help !== '') {
                echo '<div class="col-12 mt-0"><div class="form-text">' . h($help) . '</div></div>';
            }
        }
        echo '</div><div class="mt-3">' . save_buttons('Save', url($page), !$edit) . '</div></fieldset></form></div></div>';
    }
    $where = '';
    $params = [];
    $q = trim((string)g('q'));
    if ($q !== '' && !empty($cfg['search'])) {
        $parts = [];
        foreach ($cfg['search'] as $i => $col) {
            $parts[] = "`$col` LIKE :q$i";
            $params["q$i"] = '%' . $q . '%';
        }
        $where = ' WHERE (' . implode(' OR ', $parts) . ')';
    }
    $rows = DB::all("SELECT * FROM `$table`$where ORDER BY " . ($cfg['order'] ?? 'id'), $params);
    echo '<div class="card reveal"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><span>' . count($rows) . ' ' . h(strtolower($cfg['plural'] ?? 'records')) . '</span>';
    if (!empty($cfg['search'])) {
        echo '<form class="d-flex gap-1" method="get"><input type="hidden" name="p" value="' . h($page) . '"><input class="form-control form-control-sm" type="search" name="q" value="' . h($q) . '" placeholder="Search"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button></form>';
    }
    echo '</div><div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr>';
    foreach ($cfg['list'] as $name => $c) {
        echo '<th class="' . (in_array($c[1], ['money', 'num'], true) ? 'num' : '') . '">' . h($c[0]) . '</th>';
    }
    echo '<th></th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="' . (count($cfg['list']) + 1) . '">' . empty_note('Nothing here yet.') . '</td></tr>';
    }
    foreach ($rows as $r) {
        $off = isset($r[$active]) && (int)$r[$active] === 0;
        echo '<tr class="' . ($off ? 'text-muted' : '') . '">';
        foreach ($cfg['list'] as $name => $c) {
            $v = isset($c[2]) && is_callable($c[2]) ? ($c[2])($r) : ($r[$name] ?? '');
            echo match ($c[1]) {
                'money' => '<td class="num">' . money($v) . '</td>',
                'num' => '<td class="num">' . h(qty($v)) . '</td>',
                'bool' => '<td>' . ((int)$v ? '<span class="st st-good">Yes</span>' : '<span class="text-muted">No</span>') . '</td>',
                'date' => '<td>' . h(fdate($v)) . '</td>',
                'html' => '<td>' . $v . '</td>',
                default => '<td>' . h((string)$v) . '</td>',
            };
        }
        echo '<td class="text-end text-nowrap">';
        if (Access::can($page, 'edit')) {
            echo '<a class="btn btn-sm btn-outline-secondary" href="' . h(url($page, ['id' => $r['id']])) . '"><i class="bi bi-pencil"></i></a> ';
        }
        if (isset($r[$active]) && Access::can($page, 'delete')) {
            echo '<form class="d-inline" method="post" action="' . h(url($page, ['id' => $r['id']])) . '">' . csrf_field() . '<button class="btn btn-sm btn-outline-secondary" name="toggle" value="1" title="' . ($off ? 'Switch on' : 'Switch off') . '"><i class="bi bi-' . ($off ? 'toggle-off' : 'toggle-on') . '"></i></button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div></div>';
    layout_end();
}
