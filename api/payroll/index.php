<?php
// payroll.php?action=my_payslips  (GET)  -> apni payslips (?id= to ek ki detail)
// payroll.php?action=runs         (GET)  -> admin, saare payroll runs
// payroll.php?action=create_run   (POST) -> admin, ek mahine ka run banao {month, year}
// payroll.php?action=run_payslips  GET   -> admin, ek run ki payslips list (?run_id=)
//                                  POST  -> admin, us run ki payslips generate karo {run_id}

require_once __DIR__ . '/../bootstrap.php';

// mahine ke working days - weekend aur company holidays nikaal ke
function payroll_working_days(int $companyId, string $monthStart, string $monthEnd): int
{
    $stmt = db()->prepare(
        "SELECT holiday_date FROM holidays
         WHERE company_id = ? AND is_optional = 0 AND holiday_date BETWEEN ? AND ?"
    );
    $stmt->execute([$companyId, $monthStart, $monthEnd]);
    $holidays = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $days = 0;
    for ($cur = strtotime($monthStart), $end = strtotime($monthEnd); $cur <= $end; $cur = strtotime('+1 day', $cur)) {
        $dow = (int)date('w', $cur);
        if ($dow !== 0 && $dow !== 6 && !isset($holidays[date('Y-m-d', $cur)])) $days++;
    }
    return $days;
}

if ($ACTION === 'my_payslips' && $METHOD === 'GET') {
    $me = require_auth('payroll.view_self');

    // ek payslip ki poori detail (line items ke saath)
    if (!empty($_GET['id'])) {
        $stmt = db()->prepare(
            "SELECT ps.*, pr.month, pr.year, pr.status AS run_status
             FROM payslips ps JOIN payroll_runs pr ON pr.id = ps.run_id
             WHERE ps.id = ? AND ps.user_id = ?"
        );
        $stmt->execute([(int)$_GET['id'], $me['id']]);
        $slip = $stmt->fetch();
        if (!$slip) fail('Payslip not found', 404);

        $items = db()->prepare("SELECT component_name, kind, amount FROM payslip_items WHERE payslip_id = ? ORDER BY kind DESC, id");
        $items->execute([$slip['id']]);
        $slip['items'] = $items->fetchAll();
        ok($slip);
    }

    $stmt = db()->prepare(
        "SELECT ps.id, ps.gross, ps.deductions, ps.net_pay, ps.lop_days, pr.month, pr.year, pr.status AS run_status
         FROM payslips ps JOIN payroll_runs pr ON pr.id = ps.run_id
         WHERE ps.user_id = ? AND pr.status IN ('finalized','paid')
         ORDER BY pr.year DESC, pr.month DESC"
    );
    $stmt->execute([$me['id']]);
    ok($stmt->fetchAll());
}

if ($ACTION === 'runs' && $METHOD === 'GET') {
    $me = require_auth('payroll.view_all');
    $stmt = db()->prepare(
        "SELECT pr.*, COUNT(ps.id) AS payslip_count, COALESCE(SUM(ps.net_pay), 0) AS total_net
         FROM payroll_runs pr LEFT JOIN payslips ps ON ps.run_id = pr.id
         WHERE pr.company_id = ?
         GROUP BY pr.id
         ORDER BY pr.year DESC, pr.month DESC"
    );
    $stmt->execute([$me['company_id']]);
    ok($stmt->fetchAll());
}

if ($ACTION === 'create_run' && $METHOD === 'POST') {
    $me = require_auth('payroll.manage');
    $in = body();
    require_fields($in, ['month', 'year']);
    $month = (int)$in['month'];
    $year  = (int)$in['year'];
    if ($month < 1 || $month > 12) fail('Month must be 1-12', 422);
    if ($year < 2000 || $year > 2100) fail('Invalid year', 422);

    // ek mahine ka ek hi run
    $stmt = db()->prepare("SELECT id FROM payroll_runs WHERE company_id = ? AND month = ? AND year = ?");
    $stmt->execute([$me['company_id'], $month, $year]);
    if ($stmt->fetch()) fail('Run already exists for this month', 409);

    db()->prepare("INSERT INTO payroll_runs (company_id, month, year, processed_by) VALUES (?, ?, ?, ?)")
        ->execute([$me['company_id'], $month, $year, $me['id']]);
    $runId = (int)db()->lastInsertId();

    audit('create', 'payroll_run', $runId);
    ok(['id' => $runId, 'month' => $month, 'year' => $year, 'status' => 'draft'], 'Payroll run created');
}

if ($ACTION === 'run_payslips' && $METHOD === 'GET') {
    $me = require_auth('payroll.view_all');
    $runId = (int)($_GET['run_id'] ?? 0);
    if (!$runId) fail('run_id required', 422);

    $stmt = db()->prepare(
        "SELECT ps.id, ps.user_id, u.emp_code, p.first_name, p.last_name,
                ps.working_days, ps.present_days, ps.paid_leaves, ps.lop_days,
                ps.gross, ps.deductions, ps.net_pay
         FROM payslips ps
         JOIN payroll_runs pr ON pr.id = ps.run_id
         JOIN users u ON u.id = ps.user_id
         LEFT JOIN employee_profiles p ON p.user_id = u.id
         WHERE ps.run_id = ? AND pr.company_id = ?
         ORDER BY u.emp_code"
    );
    $stmt->execute([$runId, $me['company_id']]);
    ok($stmt->fetchAll());
}

if ($ACTION === 'run_payslips' && $METHOD === 'POST') {
    $me = require_auth('payroll.manage');
    $in = body();
    require_fields($in, ['run_id']);

    $stmt = db()->prepare("SELECT * FROM payroll_runs WHERE id = ? AND company_id = ?");
    $stmt->execute([(int)$in['run_id'], $me['company_id']]);
    $run = $stmt->fetch();
    if (!$run) fail('Run not found', 404);
    if (in_array($run['status'], ['finalized', 'paid'], true)) fail('Run already finalized', 409);

    $monthStart = sprintf('%04d-%02d-01', $run['year'], $run['month']);
    $monthEnd   = date('Y-m-t', strtotime($monthStart));
    $workingDays = payroll_working_days((int)$me['company_id'], $monthStart, $monthEnd);

    // sabhi active employees
    $emps = db()->prepare(
        "SELECT id FROM users WHERE company_id = ? AND delete_flag = 0 AND status = 'active'"
    );
    $emps->execute([$me['company_id']]);
    $userIds = $emps->fetchAll(PDO::FETCH_COLUMN);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // dobara generate ho to purani payslips hata do (sirf draft run me)
        $pdo->prepare("DELETE FROM payslips WHERE run_id = ?")->execute([$run['id']]);

        $generated = 0;
        $skipped   = 0;

        foreach ($userIds as $uid) {
            // us mahine me lagu salary structure
            $s = $pdo->prepare(
                "SELECT * FROM salary_structures
                 WHERE user_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
                 ORDER BY effective_from DESC LIMIT 1"
            );
            $s->execute([$uid, $monthEnd, $monthStart]);
            $structure = $s->fetch();
            if (!$structure) { $skipped++; continue; }   // structure hi nahi to payslip nahi

            $items = $pdo->prepare(
                "SELECT ssi.monthly_amt, sc.id AS component_id, sc.name AS component_name, sc.kind
                 FROM salary_structure_items ssi
                 JOIN salary_components sc ON sc.id = ssi.component_id
                 WHERE ssi.structure_id = ?"
            );
            $items->execute([$structure['id']]);
            $lines = $items->fetchAll();

            // present days attendance se, half day = 0.5
            $pd = $pdo->prepare(
                "SELECT COALESCE(SUM(CASE status WHEN 'present' THEN 1 WHEN 'half_day' THEN 0.5 ELSE 0 END), 0)
                 FROM attendance WHERE user_id = ? AND att_date BETWEEN ? AND ?"
            );
            $pd->execute([$uid, $monthStart, $monthEnd]);
            $presentDays = (float)$pd->fetchColumn();

            // approved paid leaves - mahine me overlap karti hui.
            // note: total_days poora le rahe hai, mahine ke bahar spill ho to thoda over-count ho sakta hai
            $pl = $pdo->prepare(
                "SELECT COALESCE(SUM(lr.total_days), 0)
                 FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id
                 WHERE lr.user_id = ? AND lr.status = 'approved' AND lt.is_paid = 1
                   AND lr.from_date <= ? AND lr.to_date >= ?"
            );
            $pl->execute([$uid, $monthEnd, $monthStart]);
            $paidLeaves = (float)$pl->fetchColumn();

            $paidDays = min($presentDays + $paidLeaves, $workingDays);
            $lop      = max($workingDays - $paidDays, 0);
            $ratio    = $workingDays > 0 ? $paidDays / $workingDays : 0;

            // earnings/deductions dono ko proportion me kaat lo (LOP ka asar)
            $gross = 0.0; $ded = 0.0;
            $slipLines = [];
            foreach ($lines as $ln) {
                $amt = round((float)$ln['monthly_amt'] * $ratio, 2);
                if ($ln['kind'] === 'earning') $gross += $amt; else $ded += $amt;
                $slipLines[] = [$ln['component_id'], $ln['component_name'], $ln['kind'], $amt];
            }
            $net = $gross - $ded;

            $pdo->prepare(
                "INSERT INTO payslips
                    (run_id, user_id, structure_id, working_days, present_days, paid_leaves, lop_days, gross, deductions, net_pay)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $run['id'], $uid, $structure['id'], $workingDays,
                $presentDays, $paidLeaves, $lop, $gross, $ded, $net,
            ]);
            $slipId = (int)$pdo->lastInsertId();

            foreach ($slipLines as $sl) {
                $pdo->prepare(
                    "INSERT INTO payslip_items (payslip_id, component_id, component_name, kind, amount)
                     VALUES (?, ?, ?, ?, ?)"
                )->execute([$slipId, $sl[0], $sl[1], $sl[2], $sl[3]]);
            }

            notify((int)$uid, 'payslip', 'Payslip generated',
                date('F', strtotime($monthStart)) . ' ' . $run['year'] . ' - net ' . number_format($net, 2),
                'payslip', $slipId);

            $generated++;
        }

        $pdo->prepare("UPDATE payroll_runs SET status = 'finalized', processed_by = ?, finalized_at = NOW() WHERE id = ?")
            ->execute([$me['id'], $run['id']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('finalize', 'payroll_run', (int)$run['id']);
    ok([
        'run_id'       => (int)$run['id'],
        'working_days' => $workingDays,
        'generated'    => $generated,
        'skipped'      => $skipped,   // salary structure na hone wale
    ], "Payslips generated for {$generated} employee(s)");
}

fail('Unknown action: ' . $ACTION, 404);
