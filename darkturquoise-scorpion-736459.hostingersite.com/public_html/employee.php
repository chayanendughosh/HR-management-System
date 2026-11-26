<?php

declare(strict_types=1);

/**
 * Employee self-service portal.
 * Shows ONLY the signed-in employee's own attendance and payment history.
 * The employee is always taken from the login session, never from the URL or form data.
 */
require __DIR__ . '/config/bootstrap.php';
require __DIR__ . '/config/auth.php';
requireLogin($pdo);
requireRole(['employee']);

$authUser = currentUser();
$authName = $authUser['name'] ?? 'Employee';
$authInitials = userInitials($authName);
$employeeId = (int) ($authUser['employee_id'] ?? 0);

$pages = [
    'attendance' => ['My attendance', 'clock'],
    'payments' => ['Payment history', 'wallet'],
];
$page = $_GET['page'] ?? 'attendance';
if (!is_string($page) || !isset($pages[$page])) {
    $page = 'attendance';
}

function money(mixed $value): string
{
    return '₹' . number_format((float) $value, 2);
}

function shortDate(?string $date): string
{
    $timestamp = $date ? strtotime($date) : false;
    return $timestamp === false ? '—' : date('M j, Y', $timestamp);
}

function clockTime(?string $value): string
{
    $timestamp = $value ? strtotime($value) : false;
    return $timestamp === false ? '—' : date('g:i A', $timestamp);
}

$profile = $employeeId > 0
    ? row($pdo, 'SELECT employee_code, first_name, last_name, department, job_title FROM employees WHERE id = ?', [$employeeId])
    : null;

// Attendance: one month at a time (defaults to the current month).
$month = $_GET['month'] ?? date('Y-m');
if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$attendance = [];
$summary = ['present' => 0, 'late' => 0, 'half_day' => 0, 'absent' => 0, 'holiday' => 0, 'hours' => 0.0];
$payments = [];
$ytdNet = 0.0;

if ($profile) {
    if ($page === 'attendance') {
        $attendance = rows(
            $pdo,
            'SELECT attendance_date, time_in, time_out, hours_worked, overtime_hours, status FROM attendance_records WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date DESC',
            [$employeeId, $monthStart, $monthEnd]
        );
        foreach ($attendance as $record) {
            if (isset($summary[$record['status']])) {
                $summary[$record['status']]++;
            }
            $summary['hours'] += (float) $record['hours_worked'];
        }
    } else {
        // Drafts and cancelled runs are not shown to employees.
        $payments = rows(
            $pdo,
            "SELECT p.period_start, p.period_end, p.payment_date, p.status, r.basic_salary, r.allowances, r.deductions, r.tax, r.net_salary
             FROM payroll_records r JOIN payroll_periods p ON p.id = r.payroll_period_id
             WHERE r.employee_id = ? AND p.status IN ('processing', 'paid')
             ORDER BY p.period_start DESC LIMIT 60",
            [$employeeId]
        );
        foreach ($payments as $payment) {
            if (substr((string) $payment['period_start'], 0, 4) === date('Y')) {
                $ytdNet += (float) $payment['net_salary'];
            }
        }
    }
}

$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
$pageTitle = $pages[$page][0];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · PeopleHub HR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
    <link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="employee.php"><div class="brand-mark">PS</div><div><strong>PS Management System</strong><span>Employee portal</span></div></a>
        <div class="workspace-label">My workspace</div>
        <nav aria-label="Main navigation">
            <?php foreach ($pages as $key => [$label, $icon]): ?>
                <a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="employee.php?page=<?= e($key) ?>"><span class="icon" data-icon="<?= e($icon) ?>"></span><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-mini"><div class="avatar"><?= e($authInitials) ?></div><div><strong><?= e($authName) ?></strong><span>Employee</span></div>
                <form method="post" action="logout.php" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><button class="icon-button" type="submit" title="Sign out" aria-label="Sign out"><span class="icon" data-icon="log-out"></span></button></form>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <button class="menu-button" id="menuButton" aria-label="Toggle menu"><span class="icon" data-icon="menu"></span></button>
            <div class="breadcrumb"><a href="employee.php">My workspace</a><span>/</span><strong><?= e($pageTitle) ?></strong></div>
            <div class="topbar-actions"><div class="top-avatar" title="<?= e($authName) ?>"><?= e($authInitials) ?></div></div>
        </header>

        <div class="content">
            <?php if ($databaseError): ?>
                <div class="database-alert"><span class="icon" data-icon="info"></span><?= e($databaseError) ?></div>
            <?php endif; ?>

            <?php if (!$profile): ?>
                <div class="page-heading"><div><h1>Hello, <?= e(explode(' ', $authName)[0]) ?></h1><p class="muted">Your login is not linked to an employee record yet. Ask HR to link it so your attendance and payments can appear here.</p></div></div>
            <?php elseif ($page === 'attendance'): ?>
                <div class="page-heading"><div><p class="eyebrow"><?= e($profile['job_title']) ?> · <?= e($profile['department']) ?> · <?= e($profile['employee_code']) ?></p><h1>My attendance</h1><p class="muted"><?= e(date('F Y', strtotime($monthStart))) ?></p></div>
                    <div class="month-switch"><a class="text-link" href="employee.php?page=attendance&month=<?= e($prevMonth) ?>">Previous</a>
                        <form method="get"><input type="hidden" name="page" value="attendance"><input type="month" name="month" value="<?= e($month) ?>" max="<?= e(date('Y-m')) ?>" aria-label="Choose month" onchange="this.form.submit()"></form>
                        <a class="text-link" href="employee.php?page=attendance&month=<?= e($nextMonth) ?>">Next</a></div></div>
                <section class="metric-grid">
                    <article class="metric-card"><div class="metric-top"><span class="metric-icon green"><span class="icon" data-icon="clock"></span></span></div><strong class="metric-value"><?= (int) $summary['present'] ?></strong><span class="metric-label">Days present</span></article>
                    <article class="metric-card"><div class="metric-top"><span class="metric-icon orange"><span class="icon" data-icon="clock"></span></span></div><strong class="metric-value"><?= (int) ($summary['late'] + $summary['half_day']) ?></strong><span class="metric-label">Late or half days</span></article>
                    <article class="metric-card"><div class="metric-top"><span class="metric-icon violet"><span class="icon" data-icon="calendar"></span></span></div><strong class="metric-value"><?= (int) $summary['absent'] ?></strong><span class="metric-label">Days absent</span></article>
                    <article class="metric-card"><div class="metric-top"><span class="metric-icon blue"><span class="icon" data-icon="trending-up"></span></span></div><strong class="metric-value"><?= e(number_format($summary['hours'], 1)) ?></strong><span class="metric-label">Hours worked</span></article>
                </section>
                <section class="panel"><div class="panel-heading"><div><h2>Daily records</h2><p class="muted"><?= count($attendance) ?> record(s) this month</p></div></div>
                    <div class="responsive-table"><table><thead><tr><th>Date</th><th>Check in</th><th>Check out</th><th>Hours</th><th>Overtime</th><th>Status</th></tr></thead><tbody>
                    <?php foreach ($attendance as $record): ?>
                        <tr><td><strong><?= e(shortDate($record['attendance_date'])) ?></strong><small><?= e(date('l', strtotime((string) $record['attendance_date']))) ?></small></td><td><?= e(clockTime($record['time_in'])) ?></td><td><?= e(clockTime($record['time_out'])) ?></td><td><?= e((string) $record['hours_worked']) ?></td><td><?= e((string) $record['overtime_hours']) ?></td><td><span class="status <?= e(preg_replace('/[^a-z_]/', '', strtolower((string) $record['status']))) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $record['status']))) ?></span></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$attendance): ?><tr><td colspan="6" class="no-results">No attendance recorded for this month.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>
            <?php else: ?>
                <div class="page-heading"><div><p class="eyebrow"><?= e($profile['first_name'] . ' ' . $profile['last_name']) ?> · <?= e($profile['employee_code']) ?></p><h1>Payment history</h1><p class="muted">Your salary payments, newest first.</p></div></div>
                <section class="metric-grid">
                    <article class="metric-card"><div class="metric-top"><span class="metric-icon green"><span class="icon" data-icon="wallet"></span></span></div><strong class="metric-value"><?= e(money($payments[0]['net_salary'] ?? 0)) ?></strong><span class="metric-label">Latest net pay</span></article>
                    <article class="metric-card"><div class="metric-top"><span class="metric-icon blue"><span class="icon" data-icon="trending-up"></span></span></div><strong class="metric-value"><?= e(money($ytdNet)) ?></strong><span class="metric-label">Net pay this year</span></article>
                    <article class="metric-card"><div class="metric-top"><span class="metric-icon violet"><span class="icon" data-icon="file"></span></span></div><strong class="metric-value"><?= count($payments) ?></strong><span class="metric-label">Payments on record</span></article>
                </section>
                <section class="panel"><div class="panel-heading"><div><h2>Payslips</h2><p class="muted">Pay periods that HR has processed</p></div></div>
                    <div class="responsive-table"><table><thead><tr><th>Period</th><th>Paid on</th><th>Basic</th><th>Allowances</th><th>Deductions</th><th>Tax</th><th>Net pay</th><th>Status</th></tr></thead><tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr><td><strong><?= e(date('F Y', strtotime((string) $payment['period_start']))) ?></strong><small><?= e(shortDate($payment['period_start']) . ' – ' . shortDate($payment['period_end'])) ?></small></td><td><?= e(shortDate($payment['payment_date'])) ?></td><td><?= e(money($payment['basic_salary'])) ?></td><td><?= e(money($payment['allowances'])) ?></td><td><?= e(money($payment['deductions'])) ?></td><td><?= e(money($payment['tax'])) ?></td><td><strong><?= e(money($payment['net_salary'])) ?></strong></td><td><span class="status <?= e(preg_replace('/[^a-z_]/', '', strtolower((string) $payment['status']))) ?>"><?= e(ucfirst((string) $payment['status'])) ?></span></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$payments): ?><tr><td colspan="8" class="no-results">No payments on record yet.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
