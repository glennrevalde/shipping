<?php
declare(strict_types=1);

require_once APP_ROOT . '/views/ship_master.php';

$users = fn() => DB::pairs("SELECT id, full_name FROM users WHERE status = 'active' AND is_platform = 0 ORDER BY full_name");
ship_master_page([
    'table' => 'shp_compliance', 'page' => 'shp.compliance', 'title' => 'Compliance Register', 'singular' => 'Compliance task', 'plural' => 'tasks', 'active' => '-',
    'order' => "FIELD(status, 'needs_review', 'open', 'done', 'not_applicable'), due_date IS NULL, due_date",
    'intro' => 'Regulatory requirements kept as tasks with an owner, a due date and the evidence (BIR invoicing and electronic invoicing, CAS registration, MARINA SOTS, PCG, PPA, data privacy). '
        . 'It records what the company did; it is not a certification of compliance. Check the current official issuances and get the review of your external accountant or counsel.',
    'search' => ['title', 'authority', 'reference'],
    'fields' => [
        'title' => ['Requirement', 'text', null, true, 'col-md-8'],
        'authority' => ['Authority', 'select', ['BIR' => 'BIR', 'MARINA' => 'MARINA', 'PCG' => 'PCG', 'PPA' => 'PPA', 'NPC' => 'NPC', 'LGU' => 'LGU', 'OTHER' => 'Other'], true, 'col-md-2'],
        'frequency' => ['Frequency', 'select', ['once' => 'Once', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annual' => 'Annual'], true, 'col-md-2'],
        'reference' => ['Issuance / reference', 'text', null, false, 'col-md-4'],
        'due_date' => ['Due date', 'date', null, false, 'col-md-2'],
        'owner_user_id' => ['Owner', 'select', $users, false, 'col-md-3'],
        'status' => ['Status', 'select', ['needs_review' => 'Needs review', 'open' => 'Open', 'done' => 'Done', 'not_applicable' => 'Not applicable'], true, 'col-md-3'],
        'description' => ['Description', 'textarea', null, false, 'col-md-6'],
        'evidence_note' => ['Evidence / where it is kept', 'textarea', null, false, 'col-md-6'],
    ],
    'after_save' => function (int $id, array $row, ?array $old) {
        if ($row['status'] === 'done' && ($old['status'] ?? '') !== 'done') {
            DB::update('shp_compliance', ['completed_by' => Auth::id(), 'completed_at' => Ship::now()], 'id = :id', ['id' => $id]);
        }
    },
    'list' => ['authority' => ['Authority', 'text'], 'title' => ['Requirement', 'text'], 'reference' => ['Reference', 'text'], 'frequency' => ['Frequency', 'text'],
        'due_date' => ['Due', 'date'], 'owner' => ['Owner', 'text', fn($r) => Ship::userName($r['owner_user_id'] ? (int)$r['owner_user_id'] : null)],
        'status' => ['Status', 'html', fn($r) => Ship::label($r['status'], ['needs_review' => 'Needs review', 'not_applicable' => 'Not applicable'])]],
]);
