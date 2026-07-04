<?php
// salary/index.php?action=structure&user_id=5  (GET)  -> current structure + components
// salary/index.php?action=save                 (POST) -> wage do, components khud calculate ho ke save
//
// calculation rules (profile page wale hi):
//   basic = 50% of wage, hra = 50% of basic, standard = 8.33% of wage,
//   performance + lta = 8.33% of basic, fixed = wage - baaki sab
//   deductions: pf employee 12% of basic, professional tax 200

require_once __DIR__ . '/../bootstrap.php';

// company ke salary components find-or-create by code
function component_id(PDO $pdo, int $companyId, string $code, string $name, string $kind): int
{
    $stmt = $pdo->prepare("SELECT id FROM salary_components WHERE company_id = ? AND code = ?");
    $stmt->execute([$companyId, $code]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    $pdo->prepare("INSERT INTO salary_components (company_id, name, code, kind) VALUES (?, ?, ?, ?)")
        ->execute([$companyId, $name, $code, $kind]);
    return (int)$pdo->lastInsertId();
}

function calc_components(float $wage): array
{
    $basic = round($wage * 0.50, 2);
    $hra   = round($basic * 0.50, 2);
    $std   = round($wage * 0.0833, 2);
    $perf  = round($basic * 0.0833, 2);
    $lta   = round($basic * 0.0833, 2);
    $fixed = round(max($wage - ($basic + $hra + $std + $perf + $lta), 0), 2);
    $pf    = round($basic * 0.12, 2);

    return [
        ['BASIC', 'Basic Salary',           'earning',   $basic],
        ['HRA',   'House Rent Allowance',   'earning',   $hra],
        ['STDA',  'Standard Allowance',     'earning',   $std],
        ['PERF',  'Performance Bonus',      'earning',   $perf],
        ['LTA',   'Leave Travel Allowance', 'earning',   $lta],
        ['FIXED', 'Fixed Allowance',        'earning',   $fixed],
        ['PFEMP', 'PF Employee',            'deduction', $pf],
        ['PTAX',  'Professional Tax',       'deduction', 200.00],
    ];
}

if ($ACTION === 'structure' && $METHOD === 'GET') {
    $me  = require_auth();
    $uid = (int)($_GET['user_id'] ?? $me['id']);

    // apna structure sab dekh sakte hai, dusre ka sirf admin
    if ($uid !== (int)$me['id'] && !in_array('payroll.view_all', $me['permissions'], true)) {
        fail('Forbidden', 403);
    }

    $stmt = db()->prepare(
        "SELECT s.id, s.user_id, s.ctc_annual, s.effective_from
         FROM salary_structures s
         JOIN users u ON u.id = s.user_id
         WHERE s.user_id = ? AND u.company_id = ? AND s.effective_to IS NULL
         ORDER BY s.effective_from DESC LIMIT 1"
    );
    $stmt->execute([$uid, $me['company_id']]);
    $structure = $stmt->fetch();
    if (!$structure) ok(null, 'No salary structure yet');

    $items = db()->prepare(
        "SELECT sc.code, sc.name, sc.kind, ssi.monthly_amt
         FROM salary_structure_items ssi
         JOIN salary_components sc ON sc.id = ssi.component_id
         WHERE ssi.structure_id = ? ORDER BY sc.kind, ssi.id"
    );
    $items->execute([$structure['id']]);
    $structure['items'] = $items->fetchAll();
    $structure['month_wage'] = round($structure['ctc_annual'] / 12, 2);
    ok($structure);
}

if ($ACTION === 'save' && $METHOD === 'POST') {
    $me = require_auth('payroll.manage');
    $in = body();
    require_fields($in, ['user_id', 'month_wage']);

    $uid  = (int)$in['user_id'];
    $wage = (float)$in['month_wage'];
    if ($wage <= 0) fail('Wage 0 se zyada hona chahiye', 422);

    // employee isi company ka hona chahiye
    $stmt = db()->prepare("SELECT id FROM users WHERE id = ? AND company_id = ? AND delete_flag = 0");
    $stmt->execute([$uid, $me['company_id']]);
    if (!$stmt->fetch()) fail('Employee not found', 404);

    $comps = calc_components($wage);
    $effectiveFrom = !empty($in['effective_from']) ? $in['effective_from'] : date('Y-m-d');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // purana structure band karo, history me rehta hai (amounts kabhi update nahi hote)
        $pdo->prepare(
            "UPDATE salary_structures SET effective_to = DATE_SUB(?, INTERVAL 1 DAY)
             WHERE user_id = ? AND effective_to IS NULL"
        )->execute([$effectiveFrom, $uid]);

        $pdo->prepare(
            "INSERT INTO salary_structures (user_id, ctc_annual, effective_from, reason, created_by)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$uid, $wage * 12, $effectiveFrom, $in['reason'] ?? 'revision', $me['id']]);
        $structureId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            "INSERT INTO salary_structure_items (structure_id, component_id, monthly_amt) VALUES (?, ?, ?)"
        );
        foreach ($comps as [$code, $name, $kind, $amt]) {
            $itemStmt->execute([$structureId, component_id($pdo, (int)$me['company_id'], $code, $name, $kind), $amt]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('create', 'salary_structure', $structureId, null, ['user_id' => $uid, 'month_wage' => $wage]);

    // employee ko batao, apni salary khud set kare to nahi
    if ($uid !== (int)$me['id']) {
        notify($uid, 'salary', 'Salary structure updated',
            'Your monthly wage is now ₹' . number_format($wage) . '. Check Salary Info in your profile.',
            'salary_structure', $structureId);
    }

    ok(['structure_id' => $structureId, 'month_wage' => $wage, 'components' => $comps], 'Salary structure saved');
}

fail('Unknown action: ' . $ACTION, 404);
