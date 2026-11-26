<?php

declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';
require __DIR__ . '/config/auth.php';
requireLogin($pdo);
requireRole(['admin', 'hr']);
$authUser = currentUser();
$authName = $authUser['name'] ?? 'User';
$authInitials = userInitials($authName);
$authRole = ROLES[$authUser['role'] ?? 'admin'] ?? 'User';
$isAdmin = ($authUser['role'] ?? '') === 'admin';

$pages = [
    'dashboard' => ['Dashboard', 'grid'],
    'employees' => ['Employees', 'users'],
    'recruitment' => ['Recruitment', 'briefcase'],
    'attendance' => ['Attendance', 'clock'],
    'leaves' => ['Leave management', 'calendar'],
    'payroll' => ['Payroll', 'wallet'],
    'performance' => ['Performance', 'trending-up'],
    'training' => ['Training', 'book'],
    'benefits' => ['Benefits', 'heart'],
    'assets' => ['Assets', 'monitor'],
    'reports' => ['Reports', 'bar-chart'],
    'users' => ['User accounts', 'users'],
    'settings' => ['Settings', 'settings'],
    'help' => ['Help center', 'help'],
    'newhires' => ['New hires', 'user-plus'],
    'totalemployees' => ['Total employees', 'users'],
    'onleavetoday' => ['On leave today', 'calendar'],
    'presenttoday' => ['Present today', 'clock'],
];

// Settings (database status) is for admins only.
if (($authUser['role'] ?? '') !== 'admin') {
    unset($pages['settings']);
}

$page = $_GET['page'] ?? 'dashboard';
if (!is_string($page) || !isset($pages[$page])) {
    $page = 'dashboard';
}

function post(string $name): string
{
    $value = $_POST[$name] ?? '';
    return is_string($value) ? trim($value) : '';
}

function positiveId(string $name): int
{
    $value = filter_var($_POST[$name] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? 0 : (int) $value;
}

function isOneOf(string $value, array $allowed): bool
{
    return in_array($value, $allowed, true);
}

function timeOnDate(string $date, string $time): ?string
{
    if ($time === '') {
        return null;
    }

    $dateTime = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
    return $dateTime ? $dateTime->format('Y-m-d H:i:s') : null;
}

function attendanceStatus(string $status, string $attendanceDate, ?string $timeIn): string
{
    if ($timeIn !== null && $timeIn > $attendanceDate . ' 10:30:00') {
        return 'half_day';
    }

    return $status;
}

function displayDate(?string $date): string
{
    if (!$date) {
        return '—';
    }

    $timestamp = strtotime($date);
    return $timestamp === false ? '—' : date('M j, Y', $timestamp);
}

function statusClass(string $status): string
{
    return preg_replace('/[^a-z_]/', '', strtolower($status)) ?: 'neutral';
}

function tallyXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function payrollDays(string $startDate, string $endDate): int
{
    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    return (int) $start->diff($end)->days + 1;
}

function exportPayrollToTally(PDO $pdo, int $periodId): void
{
    $period = row($pdo, 'SELECT id, period_start, period_end, payment_date, status FROM payroll_periods WHERE id = ?', [$periodId]);
    if (!$period) {
        http_response_code(404);
        exit('Payroll period not found.');
    }

    $records = rows($pdo, 'SELECT r.basic_salary, r.allowances, r.deductions, r.tax, r.net_salary, e.employee_code, e.first_name, e.last_name FROM payroll_records r JOIN employees e ON e.id = r.employee_id WHERE r.payroll_period_id = ? ORDER BY e.first_name, e.last_name', [$periodId]);
    $voucherDate = $period['payment_date'] ?: $period['period_end'];
    $totalNet = array_sum(array_map(static fn (array $record): float => (float) $record['net_salary'], $records));
    $employeeLines = '';
    foreach ($records as $record) {
        $employeeName = trim($record['first_name'] . ' ' . $record['last_name']);
        $employeeLines .= '<ALLLEDGERENTRIES.LIST><LEDGERNAME>' . tallyXml($employeeName) . '</LEDGERNAME><ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE><AMOUNT>' . number_format((float) $record['net_salary'], 2, '.', '') . '</AMOUNT></ALLLEDGERENTRIES.LIST>';
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?><ENVELOPE><HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER><BODY><IMPORTDATA><REQUESTDESC><REPORTNAME>Vouchers</REPORTNAME><STATICVARIABLES><SVCURRENTCOMPANY>PeopleHub</SVCURRENTCOMPANY></STATICVARIABLES></REQUESTDESC><REQUESTDATA><TALLYMESSAGE xmlns:UDF="TallyUDF"><VOUCHER VCHTYPE="Journal" ACTION="Create"><DATE>' . date('Ymd', strtotime($voucherDate)) . '</DATE><VOUCHERTYPENAME>Journal</VOUCHERTYPENAME><NARRATION>PeopleHub payroll ' . tallyXml($period['period_start'] . ' to ' . $period['period_end']) . '</NARRATION><ALLLEDGERENTRIES.LIST><LEDGERNAME>Salary Expense</LEDGERNAME><ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE><AMOUNT>-' . number_format($totalNet, 2, '.', '') . '</AMOUNT></ALLLEDGERENTRIES.LIST>' . $employeeLines . '</VOUCHER></TALLYMESSAGE></REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>';
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="peoplehub-payroll-' . $periodId . '.xml"');
    echo $xml;
    exit;
}

if (isset($_GET['tally_export'])) {
    $periodId = filter_var($_GET['tally_export'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($pdo instanceof PDO && $periodId !== false) {
        exportPayrollToTally($pdo, (int) $periodId);
    }
    http_response_code(400);
    exit('Invalid payroll export request.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');
    $returnPage = post('return_page');
    $returnPage = isset($pages[$returnPage]) ? $returnPage : $page;

    if ($pdo === null) {
        flash('error', 'This action needs a database connection. Start MySQL and import database/schema.sql first.');
        redirectTo($returnPage);
    }

    try {
        if ($action === 'create_user') {
            $name = post('full_name');
            $email = strtolower(post('email'));
            $role = post('role');
            $password = (string) ($_POST['password'] ?? '');
            $employeeId = positiveId('employee_id');
            if (!isOneOf($role, array_keys(ROLES)) || ($role !== 'employee' && !$isAdmin)) {
                throw new InvalidArgumentException('You can only create employee accounts.');
            }
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
                throw new InvalidArgumentException('Enter a name, a valid email and a password of at least 8 characters.');
            }
            if ($role === 'employee') {
                if ($employeeId === 0 || !row($pdo, 'SELECT id FROM employees WHERE id = ?', [$employeeId])) {
                    throw new InvalidArgumentException('Choose the employee record this login belongs to.');
                }
                if (row($pdo, 'SELECT id FROM app_users WHERE employee_id = ?', [$employeeId])) {
                    throw new InvalidArgumentException('That employee already has a login.');
                }
            } else {
                $employeeId = 0;
            }
            if (row($pdo, 'SELECT id FROM app_users WHERE email = ?', [$email])) {
                throw new InvalidArgumentException('An account with that email already exists.');
            }
            $statement = $pdo->prepare('INSERT INTO app_users (full_name, email, password_hash, role, employee_id) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $employeeId ?: null]);
            flash('success', ROLES[$role] . ' account created for ' . $name . '.');
        } elseif ($action === 'reset_user_password') {
            $targetId = positiveId('id');
            $password = (string) ($_POST['password'] ?? '');
            $target = row($pdo, 'SELECT id, role FROM app_users WHERE id = ?', [$targetId]);
            if (!$target || ($target['role'] !== 'employee' && !$isAdmin)) {
                throw new InvalidArgumentException('You cannot change that account.');
            }
            if (strlen($password) < 8) {
                throw new InvalidArgumentException('The new password needs at least 8 characters.');
            }
            $pdo->prepare('UPDATE app_users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
            flash('success', 'Password updated.');
        } elseif ($action === 'delete_user') {
            $targetId = positiveId('id');
            $target = row($pdo, 'SELECT id, role FROM app_users WHERE id = ?', [$targetId]);
            if (!$target || ($target['role'] !== 'employee' && !$isAdmin)) {
                throw new InvalidArgumentException('You cannot remove that account.');
            }
            if ($targetId === (int) $authUser['id']) {
                throw new InvalidArgumentException('You cannot remove the account you are signed in with.');
            }
            if ($target['role'] === 'admin' && (int) $pdo->query("SELECT COUNT(*) FROM app_users WHERE role = 'admin'")->fetchColumn() <= 1) {
                throw new InvalidArgumentException('At least one admin account must remain.');
            }
            $pdo->prepare('DELETE FROM app_users WHERE id = ?')->execute([$targetId]);
            flash('success', 'Account removed.');
        } elseif ($action === 'save_employee') {
            $id = positiveId('id');
            $firstName = post('first_name');
            $lastName = post('last_name');
            $email = post('email');
            $department = post('department');
            $jobTitle = post('job_title');
            $hireDate = post('hire_date');
            $employmentStatus = post('employment_status') ?: 'active';
            $phone = post('phone') ?: null;
            $salary = post('salary');

            if ($firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $department === '' || $jobTitle === '' || !isValidDate($hireDate) || !isOneOf($employmentStatus, ['active', 'on_leave', 'inactive', 'terminated'])) {
                throw new InvalidArgumentException('Please complete every required employee field with valid information.');
            }
            if ($salary !== '' && (!is_numeric($salary) || (float) $salary < 0)) {
                throw new InvalidArgumentException('Salary must be a positive amount.');
            }

            $parameters = [
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':phone' => $phone,
                ':department' => $department,
                ':job_title' => $jobTitle,
                ':hire_date' => $hireDate,
                ':employment_status' => $employmentStatus,
                ':salary' => $salary === '' ? null : number_format((float) $salary, 2, '.', ''),
            ];
            if ($id > 0) {
                $parameters[':id'] = $id;
                $statement = $pdo->prepare('UPDATE employees SET first_name = :first_name, last_name = :last_name, email = :email, phone = :phone, department = :department, job_title = :job_title, hire_date = :hire_date, employment_status = :employment_status, salary = :salary WHERE id = :id');
                $statement->execute($parameters);
                flash('success', 'Employee profile updated.');
            } else {
                $parameters[':employee_code'] = 'EMP-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $statement = $pdo->prepare('INSERT INTO employees (employee_code, first_name, last_name, email, phone, department, job_title, hire_date, employment_status, salary) VALUES (:employee_code, :first_name, :last_name, :email, :phone, :department, :job_title, :hire_date, :employment_status, :salary)');
                $statement->execute($parameters);
                flash('success', 'Employee profile created.');
            }
        } elseif ($action === 'set_employee_status') {
            $id = positiveId('id');
            $status = post('status');
            if ($id === 0 || !isOneOf($status, ['active', 'inactive', 'terminated'])) {
                throw new InvalidArgumentException('The employee status could not be updated.');
            }
            $statement = $pdo->prepare('UPDATE employees SET employment_status = :status WHERE id = :id');
            $statement->execute([':status' => $status, ':id' => $id]);
            flash('success', 'Employee status updated.');
        } elseif ($action === 'save_attendance') {
            $employeeId = positiveId('employee_id');
            $attendanceDate = post('attendance_date');
            $status = post('status');
            $timeIn = timeOnDate($attendanceDate, post('time_in'));
            $timeOut = timeOnDate($attendanceDate, post('time_out'));
            if ($employeeId === 0 || !isValidDate($attendanceDate) || !isOneOf($status, ['present', 'absent', 'late', 'half_day', 'holiday'])) {
                throw new InvalidArgumentException('Choose an employee, a valid date, and an attendance status.');
            }
            $status = attendanceStatus($status, $attendanceDate, $timeIn);
            if ($timeIn !== null && $timeOut !== null && strtotime($timeOut) < strtotime($timeIn)) {
                throw new InvalidArgumentException('Check-out time must be after check-in time.');
            }
            $hours = ($timeIn !== null && $timeOut !== null) ? round((strtotime($timeOut) - strtotime($timeIn)) / 3600, 2) : 0;
            $statement = $pdo->prepare('INSERT INTO attendance_records (employee_id, attendance_date, time_in, time_out, hours_worked, status) VALUES (:employee_id, :attendance_date, :time_in, :time_out, :hours_worked, :status) ON DUPLICATE KEY UPDATE time_in = VALUES(time_in), time_out = VALUES(time_out), hours_worked = VALUES(hours_worked), status = VALUES(status)');
            $statement->execute([':employee_id' => $employeeId, ':attendance_date' => $attendanceDate, ':time_in' => $timeIn, ':time_out' => $timeOut, ':hours_worked' => $hours, ':status' => $status]);
            flash('success', 'Attendance saved.');
        } elseif ($action === 'save_leave') {
            $employeeId = positiveId('employee_id');
            $leaveTypeId = positiveId('leave_type_id');
            $startDate = post('start_date');
            $endDate = post('end_date');
            if ($employeeId === 0 || $leaveTypeId === 0 || !isValidDate($startDate) || !isValidDate($endDate) || $endDate < $startDate) {
                throw new InvalidArgumentException('Provide a valid employee, leave type, and date range.');
            }
            $statement = $pdo->prepare('INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, reason) VALUES (:employee_id, :leave_type_id, :start_date, :end_date, :reason)');
            $statement->execute([':employee_id' => $employeeId, ':leave_type_id' => $leaveTypeId, ':start_date' => $startDate, ':end_date' => $endDate, ':reason' => post('reason') ?: null]);
            flash('success', 'Leave request submitted.');
        } elseif ($action === 'review_leave') {
            $id = positiveId('id');
            $status = post('status');
            if ($id === 0 || !isOneOf($status, ['approved', 'rejected', 'cancelled'])) {
                throw new InvalidArgumentException('Select a valid leave decision.');
            }
            $statement = $pdo->prepare('UPDATE leave_requests SET status = :status, reviewed_at = NOW() WHERE id = :id');
            $statement->execute([':status' => $status, ':id' => $id]);
            flash('success', 'Leave request ' . $status . '.');
        } elseif ($action === 'save_job') {
            $title = post('title');
            $department = post('department');
            $description = post('description');
            $closingDate = post('closing_date') ?: null;
            if ($title === '' || $department === '' || $description === '' || ($closingDate !== null && !isValidDate($closingDate))) {
                throw new InvalidArgumentException('Enter a title, department, description, and a valid closing date.');
            }
            $statement = $pdo->prepare('INSERT INTO job_postings (title, department, description, status, closing_date) VALUES (:title, :department, :description, :status, :closing_date)');
            $statement->execute([':title' => $title, ':department' => $department, ':description' => $description, ':status' => 'open', ':closing_date' => $closingDate]);
            flash('success', 'Job posting published.');
        } elseif ($action === 'close_job') {
            $id = positiveId('id');
            if ($id === 0) {
                throw new InvalidArgumentException('The job posting could not be closed.');
            }
            $pdo->prepare("UPDATE job_postings SET status = 'closed' WHERE id = :id")->execute([':id' => $id]);
            flash('success', 'Job posting closed.');
        } elseif ($action === 'save_application') {
            $jobId = positiveId('job_posting_id');
            $candidateName = post('candidate_name');
            $email = post('email');
            if ($jobId === 0 || $candidateName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Choose an open job and enter a valid candidate name and email.');
            }
            if (row($pdo, "SELECT id FROM job_postings WHERE id = ? AND status = 'open'", [$jobId]) === null) {
                throw new InvalidArgumentException('Candidates can only be added to an open job posting.');
            }
            $statement = $pdo->prepare('INSERT INTO job_applications (job_posting_id, candidate_name, email, phone) VALUES (:job_posting_id, :candidate_name, :email, :phone)');
            $statement->execute([':job_posting_id' => $jobId, ':candidate_name' => $candidateName, ':email' => $email, ':phone' => post('phone') ?: null]);
            flash('success', 'Candidate added to the recruitment pipeline.');
        } elseif ($action === 'set_application_stage') {
            $id = positiveId('id');
            $stage = post('stage');
            if ($id === 0 || !isOneOf($stage, ['applied', 'screening', 'interview', 'offered', 'hired', 'rejected'])) {
                throw new InvalidArgumentException('Select a valid candidate stage.');
            }
            $pdo->prepare('UPDATE job_applications SET stage = :stage WHERE id = :id')->execute([':stage' => $stage, ':id' => $id]);
            flash('success', 'Candidate stage updated.');
        } elseif ($action === 'run_payroll') {
            $startDate = post('period_start');
            $endDate = post('period_end');
            $paymentDate = post('payment_date') ?: null;
            if (!isValidDate($startDate) || !isValidDate($endDate) || $endDate < $startDate || ($paymentDate !== null && !isValidDate($paymentDate))) {
                throw new InvalidArgumentException('Enter a valid payroll period and payment date.');
            }
            $pdo->beginTransaction();
            $statement = $pdo->prepare("INSERT INTO payroll_periods (period_start, period_end, payment_date, status) VALUES (:period_start, :period_end, :payment_date, 'processing')");
            $statement->execute([':period_start' => $startDate, ':period_end' => $endDate, ':payment_date' => $paymentDate]);
            $periodId = (int) $pdo->lastInsertId();
            $employeesForPayroll = rows($pdo, "SELECT id, COALESCE(salary, 0) AS salary FROM employees WHERE employment_status = 'active'");
            $periodDayCount = payrollDays($startDate, $endDate);
            $recordStatement = $pdo->prepare('INSERT INTO payroll_records (payroll_period_id, employee_id, basic_salary, deductions, net_salary) VALUES (:period_id, :employee_id, :basic_salary, :deductions, :net_salary)');
            $attendanceStatement = $pdo->prepare("SELECT attendance_date, status FROM attendance_records WHERE employee_id = :employee_id AND attendance_date BETWEEN :period_start AND :period_end AND status IN ('absent', 'half_day') ORDER BY attendance_date");
            $deductionStatement = $pdo->prepare('INSERT INTO payroll_deductions (payroll_record_id, deduction_date, deduction_type, amount, notes) VALUES (:record_id, :deduction_date, :deduction_type, :amount, :notes)');
            foreach ($employeesForPayroll as $employee) {
                $salary = (float) $employee['salary'];
                $dailySalary = $periodDayCount > 0 ? $salary / $periodDayCount : 0;
                $attendanceStatement->execute([':employee_id' => $employee['id'], ':period_start' => $startDate, ':period_end' => $endDate]);
                $deductions = 0.0;
                $deductionRows = $attendanceStatement->fetchAll();
                $recordStatement->execute([':period_id' => $periodId, ':employee_id' => $employee['id'], ':basic_salary' => $salary, ':deductions' => 0, ':net_salary' => $salary]);
                $recordId = (int) $pdo->lastInsertId();
                foreach ($deductionRows as $deduction) {
                    $amount = round($dailySalary * ($deduction['status'] === 'half_day' ? 0.5 : 1), 2);
                    $deductions += $amount;
                    $deductionStatement->execute([':record_id' => $recordId, ':deduction_date' => $deduction['attendance_date'], ':deduction_type' => $deduction['status'], ':amount' => $amount, ':notes' => ucfirst(str_replace('_', ' ', $deduction['status'])) . ' attendance']);
                }
                $pdo->prepare('UPDATE payroll_records SET deductions = :deductions, net_salary = GREATEST(0, basic_salary + allowances - deductions - tax) WHERE id = :id')->execute([':deductions' => $deductions, ':id' => $recordId]);
            }
            $pdo->commit();
            flash('success', 'Payroll draft created for ' . count($employeesForPayroll) . ' active employee(s), including day-wise attendance deductions.');
        } elseif ($action === 'mark_payroll_paid') {
            $id = positiveId('id');
            if ($id === 0) {
                throw new InvalidArgumentException('The payroll period could not be updated.');
            }
            $pdo->prepare("UPDATE payroll_periods SET status = 'paid' WHERE id = :id AND status IN ('draft', 'processing')")->execute([':id' => $id]);
            flash('success', 'Payroll period marked as paid.');
        } elseif ($action === 'save_training') {
            $name = post('name');
            $startDate = post('start_date') ?: null;
            $endDate = post('end_date') ?: null;
            if ($name === '' || ($startDate !== null && !isValidDate($startDate)) || ($endDate !== null && !isValidDate($endDate)) || ($startDate !== null && $endDate !== null && $endDate < $startDate)) {
                throw new InvalidArgumentException('Provide a program name and a valid date range.');
            }
            $statement = $pdo->prepare("INSERT INTO training_programs (name, description, provider, start_date, end_date, status) VALUES (:name, :description, :provider, :start_date, :end_date, 'planned')");
            $statement->execute([':name' => $name, ':description' => post('description') ?: null, ':provider' => post('provider') ?: null, ':start_date' => $startDate, ':end_date' => $endDate]);
            flash('success', 'Training program created.');
        } elseif ($action === 'enroll_training') {
            $programId = positiveId('training_program_id');
            $employeeId = positiveId('employee_id');
            if ($programId === 0 || $employeeId === 0) {
                throw new InvalidArgumentException('Choose a training program and employee.');
            }
            $statement = $pdo->prepare("INSERT INTO training_enrollments (training_program_id, employee_id, completion_status) VALUES (:training_program_id, :employee_id, 'enrolled')");
            $statement->execute([':training_program_id' => $programId, ':employee_id' => $employeeId]);
            flash('success', 'Employee enrolled in training.');
        } elseif ($action === 'save_review') {
            $employeeId = positiveId('employee_id');
            $reviewDate = post('review_date');
            $reviewer = post('reviewer_name');
            $rating = post('rating');
            if ($employeeId === 0 || !isValidDate($reviewDate) || $reviewer === '' || !is_numeric($rating) || (float) $rating < 1 || (float) $rating > 5) {
                throw new InvalidArgumentException('Choose an employee, reviewer, valid date, and a rating from 1 to 5.');
            }
            $statement = $pdo->prepare('INSERT INTO performance_reviews (employee_id, review_date, reviewer_name, rating, comments) VALUES (:employee_id, :review_date, :reviewer_name, :rating, :comments)');
            $statement->execute([':employee_id' => $employeeId, ':review_date' => $reviewDate, ':reviewer_name' => $reviewer, ':rating' => $rating, ':comments' => post('comments') ?: null]);
            flash('success', 'Performance review saved.');
        } elseif ($action === 'save_benefit') {
            $name = post('name');
            $cost = post('monthly_cost');
            if ($name === '' || !is_numeric($cost) || (float) $cost < 0) {
                throw new InvalidArgumentException('Enter a benefit name and a valid monthly cost.');
            }
            $statement = $pdo->prepare('INSERT INTO benefit_plans (name, provider, monthly_cost, description) VALUES (:name, :provider, :monthly_cost, :description)');
            $statement->execute([':name' => $name, ':provider' => post('provider') ?: null, ':monthly_cost' => $cost, ':description' => post('description') ?: null]);
            flash('success', 'Benefit plan created.');
        } elseif ($action === 'enroll_benefit') {
            $employeeId = positiveId('employee_id');
            $benefitId = positiveId('benefit_plan_id');
            $enrolledOn = post('enrolled_on');
            if ($employeeId === 0 || $benefitId === 0 || !isValidDate($enrolledOn)) {
                throw new InvalidArgumentException('Choose an employee, benefit plan, and enrollment date.');
            }
            $statement = $pdo->prepare("INSERT INTO employee_benefits (employee_id, benefit_plan_id, enrolled_on, status) VALUES (:employee_id, :benefit_plan_id, :enrolled_on, 'active') ON DUPLICATE KEY UPDATE enrolled_on = VALUES(enrolled_on), status = 'active'");
            $statement->execute([':employee_id' => $employeeId, ':benefit_plan_id' => $benefitId, ':enrolled_on' => $enrolledOn]);
            flash('success', 'Employee enrolled in the benefit plan.');
        } elseif ($action === 'save_asset') {
            $tag = post('asset_tag');
            $name = post('name');
            $category = post('category');
            $purchaseDate = post('purchase_date') ?: null;
            if ($tag === '' || $name === '' || $category === '' || ($purchaseDate !== null && !isValidDate($purchaseDate))) {
                throw new InvalidArgumentException('Enter an asset tag, name, category, and valid purchase date.');
            }
            $statement = $pdo->prepare("INSERT INTO company_assets (asset_tag, name, category, serial_number, purchase_date, status) VALUES (:asset_tag, :name, :category, :serial_number, :purchase_date, 'available')");
            $statement->execute([':asset_tag' => $tag, ':name' => $name, ':category' => $category, ':serial_number' => post('serial_number') ?: null, ':purchase_date' => $purchaseDate]);
            flash('success', 'Asset registered.');
        } elseif ($action === 'assign_asset') {
            $assetId = positiveId('asset_id');
            $employeeId = positiveId('employee_id');
            $assignedOn = post('assigned_on');
            if ($assetId === 0 || $employeeId === 0 || !isValidDate($assignedOn)) {
                throw new InvalidArgumentException('Choose an available asset, employee, and assignment date.');
            }
            $pdo->beginTransaction();
            $statement = $pdo->prepare("UPDATE company_assets SET status = 'assigned' WHERE id = :id AND status = 'available'");
            $statement->execute([':id' => $assetId]);
            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException('That asset is no longer available for assignment.');
            }
            $statement = $pdo->prepare('INSERT INTO asset_assignments (asset_id, employee_id, assigned_on, notes) VALUES (:asset_id, :employee_id, :assigned_on, :notes)');
            $statement->execute([':asset_id' => $assetId, ':employee_id' => $employeeId, ':assigned_on' => $assignedOn, ':notes' => post('notes') ?: null]);
            $pdo->commit();
            flash('success', 'Asset assigned.');
        } elseif ($action === 'return_asset') {
            $assetId = positiveId('asset_id');
            if ($assetId === 0) {
                throw new InvalidArgumentException('The asset could not be returned.');
            }
            $pdo->beginTransaction();
            $statement = $pdo->prepare('UPDATE asset_assignments SET returned_on = CURDATE() WHERE asset_id = :asset_id AND returned_on IS NULL');
            $statement->execute([':asset_id' => $assetId]);
            if ($statement->rowCount() < 1) {
                throw new InvalidArgumentException('There is no active assignment for that asset.');
            }
            $pdo->prepare("UPDATE company_assets SET status = 'available' WHERE id = :id")->execute([':id' => $assetId]);
            $pdo->commit();
            flash('success', 'Asset returned and made available.');
        } else {
            throw new InvalidArgumentException('That action is not available.');
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'The change could not be saved. Check that the database schema is current and that unique values such as email and asset tag have not already been used.');
    }
    redirectTo($returnPage);
}

$departments = rows($pdo, 'SELECT id, name FROM departments ORDER BY name');
$employees = rows($pdo, "SELECT id, first_name, last_name, department, job_title, employment_status, salary, hire_date, email FROM employees ORDER BY first_name, last_name");
$leaveTypes = rows($pdo, 'SELECT id, name, annual_days FROM leave_types ORDER BY name');
$openJobs = rows($pdo, "SELECT id, title FROM job_postings WHERE status = 'open' ORDER BY title");
$trainingPrograms = rows($pdo, 'SELECT id, name FROM training_programs ORDER BY name');
$benefitPlans = rows($pdo, 'SELECT id, name FROM benefit_plans WHERE active = 1 ORDER BY name');
$availableAssets = rows($pdo, "SELECT id, asset_tag, name FROM company_assets WHERE status = 'available' ORDER BY name");
$flashes = consumeFlashes();
$pageTitle = $pages[$page][0];
$today = date('Y-m-d');
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
        <a class="brand" href="index.php"><div class="brand-mark">PS</div><div><strong>PS Management System</strong><span>HR management</span></div></a>
        <div class="workspace-label">Workspace</div>
        <nav aria-label="Main navigation">
            <?php foreach (array_slice($pages, 0, 12, true) as $key => [$label, $icon]): ?>
                <a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="index.php?page=<?= e($key) ?>"><span class="icon" data-icon="<?= e($icon) ?>"></span><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <?php if (isset($pages['settings'])): ?><a class="nav-link <?= $page === 'settings' ? 'active' : '' ?>" href="index.php?page=settings"><span class="icon" data-icon="settings"></span><span>Settings</span></a><?php endif; ?>
            <a class="nav-link <?= $page === 'help' ? 'active' : '' ?>" href="index.php?page=help"><span class="icon" data-icon="help"></span><span>Help center</span></a>
            <div class="user-mini"><div class="avatar"><?= e($authInitials) ?></div><div><strong><?= e($authName) ?></strong><span><?= e($authRole) ?></span></div><form method="post" action="logout.php" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><button class="icon-button" type="submit" title="Sign out" aria-label="Sign out"><span class="icon" data-icon="log-out"></span></button></form></div>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <button class="menu-button" id="menuButton" aria-label="Toggle menu"><span class="icon" data-icon="menu"></span></button>
            <div class="breadcrumb"><a href="index.php">Workspace</a><span>/</span><strong><?= e($pageTitle) ?></strong></div>
            <form class="top-search" method="get" action="index.php"><input type="hidden" name="page" value="employees"><span class="icon" data-icon="search"></span><input aria-label="Search employees" name="q" placeholder="Search employees"></form>
            <div class="topbar-actions"><a class="icon-button notification" title="Review pending leave requests" href="index.php?page=leaves#requests"><span class="icon" data-icon="bell"></span><i></i></a><div class="top-avatar" title="<?= e($authName) ?>"><?= e($authInitials) ?></div></div>
        </header>

        <div class="content">
            <?php foreach ($flashes as $flash): ?>
                <div class="flash <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>
            <?php if ($databaseError): ?>
                <div class="database-alert"><span class="icon" data-icon="info"></span><?= e($databaseError) ?></div>
            <?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
                <?php
                $activeCount = dashboardCount($pdo, 'employees', "WHERE employment_status = 'active'");
                $newHires = dashboardCount($pdo, 'employees', "WHERE hire_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
                $onLeave = dashboardCount($pdo, 'leave_requests', "WHERE status = 'approved' AND CURDATE() BETWEEN start_date AND end_date");
                $present = dashboardCount($pdo, 'attendance_records', "WHERE attendance_date = CURDATE() AND status IN ('present', 'late', 'half_day')");
                $recentEmployees = rows($pdo, "SELECT first_name, last_name, department, job_title, hire_date FROM employees ORDER BY created_at DESC LIMIT 5");
                $pendingLeaves = rows($pdo, "SELECT l.id, l.start_date, l.end_date, l.status, e.first_name, e.last_name, t.name AS leave_type FROM leave_requests l JOIN employees e ON e.id = l.employee_id JOIN leave_types t ON t.id = l.leave_type_id ORDER BY FIELD(l.status, 'pending', 'approved', 'rejected', 'cancelled'), l.created_at DESC LIMIT 5");
                $weekAttendance = rows($pdo, "SELECT DAYOFWEEK(attendance_date) AS weekday, COUNT(*) AS total, SUM(status IN ('present', 'late', 'half_day')) AS attended FROM attendance_records WHERE attendance_date BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 6 DAY) GROUP BY DAYOFWEEK(attendance_date)");
                $attendanceByDay = [];
                foreach ($weekAttendance as $entry) { $attendanceByDay[(int) $entry['weekday']] = $entry; }
                ?>
                <div class="page-heading"><div><p class="eyebrow"><?= e(date('l, F j, Y')) ?></p><h1>Good day, <?= e(explode(' ', $authName)[0]) ?> <span>✦</span></h1><p class="muted">Here is what is happening across your organization today.</p></div><a class="primary-button" href="index.php?page=employees#employee-form"><span class="icon" data-icon="plus"></span>Add employee</a></div>
                <section class="metric-grid">
                    <a class="metric-card" href="index.php?page=totalemployees" title="View all active employees" style="display:block;cursor:pointer"><div class="metric-top"><span class="metric-icon blue"><span class="icon" data-icon="users"></span></span><span class="metric-change blue">Active</span></div><strong class="metric-value"><?= number_format($activeCount) ?></strong><span class="metric-label">Total employees</span></a>
                    <a class="metric-card" href="index.php?page=newhires" title="View new hires" style="display:block;cursor:pointer"><div class="metric-top"><span class="metric-icon violet"><span class="icon" data-icon="user-plus"></span></span><span class="metric-change violet">30 days</span></div><strong class="metric-value"><?= number_format($newHires) ?></strong><span class="metric-label">New hires</span></a>
                    <a class="metric-card" href="index.php?page=onleavetoday" title="View employees on leave today" style="display:block;cursor:pointer"><div class="metric-top"><span class="metric-icon orange"><span class="icon" data-icon="calendar"></span></span><span class="metric-change orange">Today</span></div><strong class="metric-value"><?= number_format($onLeave) ?></strong><span class="metric-label">On leave today</span></a>
                    <a class="metric-card" href="index.php?page=presenttoday" title="View employees present today" style="display:block;cursor:pointer"><div class="metric-top"><span class="metric-icon green"><span class="icon" data-icon="clock"></span></span><span class="metric-change green">Today</span></div><strong class="metric-value"><?= number_format($present) ?></strong><span class="metric-label">Present today</span></a>
                </section>
                <div class="dashboard-grid">
                    <section class="panel attendance-panel"><div class="panel-heading"><div><h2>Attendance overview</h2><p class="muted">Recorded attendance this week</p></div><a class="text-link" href="index.php?page=attendance">Manage attendance</a></div><div class="chart-area"><div class="chart-y"><span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0%</span></div><div class="bars"><?php foreach ([2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat', 1 => 'Sun'] as $weekday => $label): ?><?php $day = $attendanceByDay[$weekday] ?? null; $rate = $day && (int) $day['total'] > 0 ? round(((int) $day['attended'] / (int) $day['total']) * 100) : 0; ?><div class="bar-group <?= $rate === 0 ? 'muted-bar' : '' ?>"><div class="bar-track" title="<?= e($label) ?>: <?= $rate ?>%"><i style="height:<?= $rate ?>%"></i></div><span><?= e($label) ?></span></div><?php endforeach; ?></div></div><div class="chart-summary"><span><span class="dot blue-dot"></span>Present, late, or half day</span><span><span class="dot violet-dot"></span>Based on saved records</span></div></section>
                    <section class="panel"><div class="panel-heading"><div><h2>Leave requests</h2><p class="muted">Most recent requests</p></div><a class="text-link" href="index.php?page=leaves">View all</a></div><div class="leave-list"><?php if ($pendingLeaves): ?><?php foreach ($pendingLeaves as $leave): ?><div class="leave-item"><div class="avatar avatar-pink"><?= e(initials($leave['first_name'], $leave['last_name'])) ?></div><div class="leave-info"><strong><?= e($leave['first_name'] . ' ' . $leave['last_name']) ?></strong><span><?= e($leave['leave_type']) ?> · <?= e(displayDate($leave['start_date'])) ?></span></div><span class="status <?= e(statusClass($leave['status'])) ?>"><?= e(ucfirst($leave['status'])) ?></span></div><?php endforeach; ?><?php else: ?><div class="empty-state"><span class="icon" data-icon="calendar"></span><strong>No leave requests yet</strong><p>New requests will appear here.</p></div><?php endif; ?></div></section>
                </div>
                <div class="dashboard-grid bottom-grid"><section class="panel"><div class="panel-heading"><div><h2>Recent employees</h2><p class="muted">Latest people added to your team</p></div><a class="text-link" href="index.php?page=employees">View all</a></div><?php if ($recentEmployees): ?><div class="employee-table"><div class="table-head"><span>Employee</span><span>Department</span><span>Joined</span></div><?php foreach ($recentEmployees as $employee): ?><div class="table-row"><div class="employee-name"><div class="avatar"><?= e(initials($employee['first_name'], $employee['last_name'])) ?></div><strong><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></strong></div><span><?= e($employee['department']) ?></span><span><?= e(displayDate($employee['hire_date'])) ?></span></div><?php endforeach; ?></div><?php else: ?><div class="empty-state"><span class="icon" data-icon="users"></span><strong>No employee records yet</strong><p>Add your first employee to get started.</p></div><?php endif; ?></section><section class="panel quick-panel"><div class="panel-heading"><div><h2>Quick actions</h2><p class="muted">Common tasks</p></div></div><div class="quick-actions"><a href="index.php?page=employees#employee-form"><span class="quick-icon blue"><span class="icon" data-icon="user-plus"></span></span><span><strong>Add employee</strong><small>Create a new employee profile</small></span><span class="icon" data-icon="arrow-right"></span></a><a href="index.php?page=leaves#leave-form"><span class="quick-icon violet"><span class="icon" data-icon="calendar"></span></span><span><strong>Review leave</strong><small>Submit or decide on requests</small></span><span class="icon" data-icon="arrow-right"></span></a><a href="index.php?page=payroll#payroll-form"><span class="quick-icon orange"><span class="icon" data-icon="file"></span></span><span><strong>Run payroll</strong><small>Prepare the current pay period</small></span><span class="icon" data-icon="arrow-right"></span></a></div></section></div>

            <?php elseif ($page === 'employees'): ?>
                <?php $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); $editingEmployee = $editId ? row($pdo, 'SELECT * FROM employees WHERE id = ?', [(int) $editId]) : null; $search = trim((string) ($_GET['q'] ?? '')); $listedEmployees = $search !== '' ? rows($pdo, 'SELECT * FROM employees WHERE CONCAT(first_name, " ", last_name, " ", email, " ", department, " ", job_title) LIKE ? ORDER BY first_name, last_name', ['%' . $search . '%']) : $employees; ?>
                <div class="page-heading"><div><p class="eyebrow">People directory</p><h1>Employees</h1><p class="muted">Create, update, and manage your employee records.</p></div><a class="primary-button" href="#employee-form"><span class="icon" data-icon="plus"></span>Add employee</a></div>
                <div class="management-grid"><section class="panel" id="employee-form"><div class="panel-heading"><div><h2><?= $editingEmployee ? 'Edit employee' : 'Add employee' ?></h2><p class="muted">Required fields are marked with an asterisk.</p></div><?php if ($editingEmployee): ?><a class="text-link" href="index.php?page=employees">Cancel edit</a><?php endif; ?></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_employee"><input type="hidden" name="return_page" value="employees"><input type="hidden" name="id" value="<?= e((string) ($editingEmployee['id'] ?? '')) ?>"><label>First name *<input required name="first_name" value="<?= e($editingEmployee['first_name'] ?? '') ?>"></label><label>Last name *<input required name="last_name" value="<?= e($editingEmployee['last_name'] ?? '') ?>"></label><label>Email *<input required type="email" name="email" value="<?= e($editingEmployee['email'] ?? '') ?>"></label><label>Phone<input name="phone" value="<?= e($editingEmployee['phone'] ?? '') ?>"></label><label>Department *<input required list="departments" name="department" value="<?= e($editingEmployee['department'] ?? '') ?>"></label><label>Job title *<input required name="job_title" value="<?= e($editingEmployee['job_title'] ?? '') ?>"></label><label>Hire date *<input required type="date" name="hire_date" value="<?= e($editingEmployee['hire_date'] ?? $today) ?>"></label><label>Status *<select name="employment_status"><?php foreach (['active' => 'Active', 'on_leave' => 'On leave', 'inactive' => 'Inactive', 'terminated' => 'Terminated'] as $value => $label): ?><option value="<?= e($value) ?>" <?= ($editingEmployee['employment_status'] ?? 'active') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label>Monthly salary<input min="0" step="0.01" type="number" name="salary" value="<?= e((string) ($editingEmployee['salary'] ?? '')) ?>"></label><button class="primary-button" type="submit"><?= $editingEmployee ? 'Save changes' : 'Create employee' ?></button></form><datalist id="departments"><?php foreach ($departments as $department): ?><option value="<?= e($department['name']) ?>"><?php endforeach; ?></datalist></section><section class="panel"><div class="panel-heading"><div><h2>Employee directory</h2><p class="muted"><?= number_format(count($listedEmployees)) ?> record(s)<?= $search !== '' ? ' matching “' . e($search) . '”' : '' ?></p></div></div><form class="inline-filter" method="get"><input type="hidden" name="page" value="employees"><input name="q" value="<?= e($search) ?>" placeholder="Search by name, email, department"><button type="submit">Search</button><?php if ($search !== ''): ?><a href="index.php?page=employees">Clear</a><?php endif; ?></form><div class="responsive-table"><table><thead><tr><th>Employee</th><th>Department</th><th>Position</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($listedEmployees as $employee): ?><tr><td><strong><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></strong><small><?= e($employee['email']) ?></small></td><td><?= e($employee['department']) ?></td><td><?= e($employee['job_title']) ?></td><td><span class="status <?= e(statusClass($employee['employment_status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $employee['employment_status']))) ?></span></td><td class="table-actions"><a class="text-link" href="index.php?page=employees&edit=<?= (int) $employee['id'] ?>#employee-form">Edit</a><?php if ($employee['employment_status'] !== 'terminated'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="set_employee_status"><input type="hidden" name="return_page" value="employees"><input type="hidden" name="id" value="<?= (int) $employee['id'] ?>"><input type="hidden" name="status" value="inactive"><button class="link-button" data-confirm="Mark this employee inactive?">Deactivate</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$listedEmployees): ?><tr><td colspan="5" class="no-results">No employees found.</td></tr><?php endif; ?></tbody></table></div></section></div>

            <?php elseif ($page === 'newhires'): ?>
                <?php
                $periodOptions = [30 => 'Last 30 days', 60 => 'Last 60 days', 90 => 'Last 90 days', 365 => 'Last 12 months'];
                $days = (int) ($_GET['days'] ?? 30);
                if (!isset($periodOptions[$days])) { $days = 30; }
                $newHireList = rows($pdo, 'SELECT id, employee_code, first_name, last_name, email, phone, department, job_title, hire_date, employment_status FROM employees WHERE hire_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY) ORDER BY hire_date DESC, id DESC', [':days' => $days]);
                ?>
                <div class="page-heading"><div><p class="eyebrow">People</p><h1>New hires</h1><p class="muted"><?= number_format(count($newHireList)) ?> employee(s) joined in the <?= e(strtolower($periodOptions[$days])) ?>.</p></div><a class="primary-button" href="index.php?page=employees#employee-form"><span class="icon" data-icon="plus"></span>Add employee</a></div>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Recently joined employees</h2><p class="muted">Based on each employee's hire date</p></div><a class="text-link" href="index.php?page=dashboard">Back to dashboard</a></div>
                    <form class="inline-filter" method="get"><input type="hidden" name="page" value="newhires"><select name="days" onchange="this.form.submit()"><?php foreach ($periodOptions as $value => $label): ?><option value="<?= (int) $value ?>" <?= $days === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><noscript><button type="submit">Apply</button></noscript></form>
                    <div class="responsive-table"><table><thead><tr><th>Employee</th><th>Code</th><th>Department</th><th>Position</th><th>Joined</th><th>Status</th><th></th></tr></thead><tbody>
                    <?php foreach ($newHireList as $hire): ?>
                        <tr><td><strong><?= e($hire['first_name'] . ' ' . $hire['last_name']) ?></strong><small><?= e($hire['email']) ?></small></td><td><?= e($hire['employee_code']) ?></td><td><?= e($hire['department']) ?></td><td><?= e($hire['job_title']) ?></td><td><?= e(displayDate($hire['hire_date'])) ?></td><td><span class="status <?= e(statusClass($hire['employment_status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $hire['employment_status']))) ?></span></td><td class="table-actions"><a class="text-link" href="index.php?page=employees&edit=<?= (int) $hire['id'] ?>#employee-form">Edit</a></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$newHireList): ?><tr><td colspan="7" class="no-results">No new hires in this period.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>

            <?php elseif ($page === 'totalemployees'): ?>
                <?php
                $activeList = rows($pdo, "SELECT id, employee_code, first_name, last_name, email, phone, department, job_title, hire_date FROM employees WHERE employment_status = 'active' ORDER BY first_name, last_name");
                ?>
                <div class="page-heading"><div><p class="eyebrow">People</p><h1>Total employees</h1><p class="muted"><?= number_format(count($activeList)) ?> active employee(s).</p></div><a class="primary-button" href="index.php?page=employees#employee-form"><span class="icon" data-icon="plus"></span>Add employee</a></div>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Active employees</h2><p class="muted">Everyone currently marked as active</p></div><a class="text-link" href="index.php?page=dashboard">Back to dashboard</a></div>
                    <div class="responsive-table"><table><thead><tr><th>Employee</th><th>Code</th><th>Department</th><th>Position</th><th>Phone</th><th>Joined</th><th></th></tr></thead><tbody>
                    <?php foreach ($activeList as $person): ?>
                        <tr><td><strong><?= e($person['first_name'] . ' ' . $person['last_name']) ?></strong><small><?= e($person['email']) ?></small></td><td><?= e($person['employee_code']) ?></td><td><?= e($person['department']) ?></td><td><?= e($person['job_title']) ?></td><td><?= e($person['phone'] ?? '') ?></td><td><?= e(displayDate($person['hire_date'])) ?></td><td class="table-actions"><a class="text-link" href="index.php?page=employees&edit=<?= (int) $person['id'] ?>#employee-form">Edit</a></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$activeList): ?><tr><td colspan="7" class="no-results">No active employees found.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>

            <?php elseif ($page === 'onleavetoday'): ?>
                <?php
                $onLeaveList = rows($pdo, "SELECT e.first_name, e.last_name, e.department, e.job_title, l.start_date, l.end_date, l.reason, t.name AS leave_type FROM leave_requests l JOIN employees e ON e.id = l.employee_id JOIN leave_types t ON t.id = l.leave_type_id WHERE l.status = 'approved' AND CURDATE() BETWEEN l.start_date AND l.end_date ORDER BY l.end_date, e.first_name");
                ?>
                <div class="page-heading"><div><p class="eyebrow"><?= e(date('l, F j, Y')) ?></p><h1>On leave today</h1><p class="muted"><?= number_format(count($onLeaveList)) ?> employee(s) on approved leave today.</p></div><a class="primary-button" href="index.php?page=leaves#leave-form"><span class="icon" data-icon="plus"></span>New leave request</a></div>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Employees on approved leave</h2><p class="muted">Leave requests approved for today's date</p></div><a class="text-link" href="index.php?page=dashboard">Back to dashboard</a></div>
                    <div class="responsive-table"><table><thead><tr><th>Employee</th><th>Department</th><th>Leave type</th><th>From</th><th>To</th><th>Reason</th></tr></thead><tbody>
                    <?php foreach ($onLeaveList as $leaveRow): ?>
                        <tr><td><strong><?= e($leaveRow['first_name'] . ' ' . $leaveRow['last_name']) ?></strong><small><?= e($leaveRow['job_title']) ?></small></td><td><?= e($leaveRow['department']) ?></td><td><?= e($leaveRow['leave_type']) ?></td><td><?= e(displayDate($leaveRow['start_date'])) ?></td><td><?= e(displayDate($leaveRow['end_date'])) ?></td><td><?= e($leaveRow['reason'] ?? '') ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$onLeaveList): ?><tr><td colspan="6" class="no-results">Nobody is on approved leave today.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>

            <?php elseif ($page === 'presenttoday'): ?>
                <?php
                $presentList = rows($pdo, "SELECT e.first_name, e.last_name, e.department, e.job_title, a.status, a.time_in, a.time_out, a.hours_worked FROM attendance_records a JOIN employees e ON e.id = a.employee_id WHERE a.attendance_date = CURDATE() AND a.status IN ('present', 'late', 'half_day') ORDER BY e.first_name, e.last_name");
                ?>
                <div class="page-heading"><div><p class="eyebrow"><?= e(date('l, F j, Y')) ?></p><h1>Present today</h1><p class="muted"><?= number_format(count($presentList)) ?> employee(s) marked present, late or half day.</p></div><a class="primary-button" href="index.php?page=attendance"><span class="icon" data-icon="clock"></span>Manage attendance</a></div>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Today's attendance</h2><p class="muted">Based on saved attendance records for today</p></div><a class="text-link" href="index.php?page=dashboard">Back to dashboard</a></div>
                    <div class="responsive-table"><table><thead><tr><th>Employee</th><th>Department</th><th>Status</th><th>Time in</th><th>Time out</th><th>Hours</th></tr></thead><tbody>
                    <?php foreach ($presentList as $att): ?>
                        <tr><td><strong><?= e($att['first_name'] . ' ' . $att['last_name']) ?></strong><small><?= e($att['job_title']) ?></small></td><td><?= e($att['department']) ?></td><td><span class="status <?= e(statusClass($att['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $att['status']))) ?></span></td><td><?= $att['time_in'] ? e(date('g:i A', strtotime($att['time_in']))) : '—' ?></td><td><?= $att['time_out'] ? e(date('g:i A', strtotime($att['time_out']))) : '—' ?></td><td><?= e((string) ($att['hours_worked'] ?? '0')) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$presentList): ?><tr><td colspan="6" class="no-results">No attendance has been recorded as present today.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>

            <?php elseif ($page === 'attendance'): ?>
                <?php
                $attendance = rows($pdo, 'SELECT a.*, e.first_name, e.last_name FROM attendance_records a JOIN employees e ON e.id = a.employee_id ORDER BY a.attendance_date DESC, e.first_name LIMIT 100');
                $calendarMonth = is_string($_GET['month'] ?? null) ? (string) $_GET['month'] : date('Y-m');
                if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $calendarMonth)) {
                    $calendarMonth = date('Y-m');
                }
                $calendarEmployeeId = filter_input(INPUT_GET, 'employee_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
                $calendarStart = $calendarMonth . '-01';
                $calendarEnd = date('Y-m-t', strtotime($calendarStart));
                $calendarAttendance = rows(
                    $pdo,
                    'SELECT a.attendance_date, a.time_in, a.time_out, a.status, e.id AS employee_id, e.first_name, e.last_name
                     FROM attendance_records a JOIN employees e ON e.id = a.employee_id
                     WHERE a.attendance_date BETWEEN ? AND ? ORDER BY a.attendance_date, e.first_name, e.last_name',
                    [$calendarStart, $calendarEnd]
                );
                $calendarMap = [];
                foreach ($calendarAttendance as $record) {
                    if ($calendarEmployeeId > 0 && (int) $record['employee_id'] !== $calendarEmployeeId) {
                        continue;
                    }
                    $calendarMap[$record['attendance_date']][] = $record;
                }
                $calendarFirstDow = (int) date('w', strtotime($calendarStart));
                $calendarDayCount = (int) date('t', strtotime($calendarStart));
                $previousMonth = date('Y-m', strtotime($calendarStart . ' -1 month'));
                $nextMonth = date('Y-m', strtotime($calendarStart . ' +1 month'));
                ?>
                <div class="page-heading"><div><p class="eyebrow">Time records</p><h1>Attendance</h1><p class="muted">Save one attendance record per employee and date. Saving again updates it.</p></div></div><div class="management-grid"><section class="panel" id="attendance-form"><div class="panel-heading"><div><h2>Record attendance</h2><p class="muted">Check-in and check-out are optional for absent or holiday records.</p></div></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_attendance"><input type="hidden" name="return_page" value="attendance"><label>Employee *<select required name="employee_id"><option value="">Choose an employee</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>"><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></option><?php endforeach; ?></select></label><label>Date *<input required type="date" name="attendance_date" value="<?= e($today) ?>"></label><label>Status *<select name="status"><?php foreach (['present', 'late', 'half_day', 'absent', 'holiday'] as $status): ?><option value="<?= e($status) ?>"><?= e(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select></label><label>Check in<input type="time" name="time_in"></label><label>Check out<input type="time" name="time_out"></label><button class="primary-button" type="submit">Save attendance</button></form></section><section class="panel"><div class="panel-heading"><div><h2>Recent attendance</h2><p class="muted">Latest 100 records</p></div></div><div class="responsive-table"><table><thead><tr><th>Employee</th><th>Date</th><th>Time</th><th>Hours</th><th>Status</th></tr></thead><tbody><?php foreach ($attendance as $record): ?><tr><td><?= e($record['first_name'] . ' ' . $record['last_name']) ?></td><td><?= e(displayDate($record['attendance_date'])) ?></td><td><?= $record['time_in'] ? e(date('g:i A', strtotime($record['time_in']))) . ' – ' . ($record['time_out'] ? e(date('g:i A', strtotime($record['time_out']))) : '—') : '—' ?></td><td><?= e((string) $record['hours_worked']) ?></td><td><span class="status <?= e(statusClass($record['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $record['status']))) ?></span></td></tr><?php endforeach; ?><?php if (!$attendance): ?><tr><td colspan="5" class="no-results">No attendance records yet.</td></tr><?php endif; ?></tbody></table></div></section></div>
                <section class="panel attendance-calendar-panel">
                    <div class="panel-heading calendar-heading">
                        <div><h2>Attendance calendar</h2><p class="muted"><?= e(date('F Y', strtotime($calendarStart))) ?><?= $calendarEmployeeId > 0 ? ' · Selected employee' : '' ?></p></div>
                        <div class="calendar-controls">
                            <a class="text-link" href="index.php?page=attendance&month=<?= e($previousMonth) ?>&employee_id=<?= $calendarEmployeeId ?>">Previous</a>
                            <form method="get" class="calendar-filter">
                                <input type="hidden" name="page" value="attendance">
                                <input type="month" name="month" value="<?= e($calendarMonth) ?>" aria-label="Calendar month" onchange="this.form.submit()">
                                <select name="employee_id" aria-label="Filter by employee" onchange="this.form.submit()">
                                    <option value="0">All employees</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?= (int) $employee['id'] ?>" <?= $calendarEmployeeId === (int) $employee['id'] ? 'selected' : '' ?>><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <a class="text-link" href="index.php?page=attendance&month=<?= e($nextMonth) ?>&employee_id=<?= $calendarEmployeeId ?>">Next</a>
                        </div>
                    </div>
                    <div class="attendance-calendar" aria-label="Attendance calendar for <?= e(date('F Y', strtotime($calendarStart))) ?>">
                        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
                            <div class="calendar-weekday"><?= e($weekday) ?></div>
                        <?php endforeach; ?>
                        <?php for ($blank = 0; $blank < $calendarFirstDow; $blank++): ?>
                            <div class="calendar-day calendar-day-empty"></div>
                        <?php endfor; ?>
                        <?php for ($day = 1; $day <= $calendarDayCount; $day++): ?>
                            <?php $date = $calendarMonth . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT); $records = $calendarMap[$date] ?? []; ?>
                            <div class="calendar-day<?= $date === $today ? ' calendar-day-today' : '' ?>">
                                <span class="calendar-date"><?= $day ?></span>
                                <?php foreach ($records as $record): ?>
                                    <div class="calendar-record status <?= e(statusClass($record['status'])) ?>" title="<?= e($record['first_name'] . ' ' . $record['last_name']) . ' — ' . e(ucwords(str_replace('_', ' ', $record['status']))) ?>">
                                        <strong><?= e($record['first_name'] . ' ' . $record['last_name']) ?></strong>
                                        <small><?= e(ucwords(str_replace('_', ' ', $record['status']))) ?><?= $record['time_in'] ? ' · ' . e(date('g:i A', strtotime($record['time_in']))) : '' ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div class="calendar-legend" aria-label="Attendance status legend">
                        <span><i class="legend-present"></i>Present</span>
                        <span><i class="legend-late"></i>Late / Half day</span>
                        <span><i class="legend-absent"></i>Absent</span>
                        <span><i class="legend-holiday"></i>Holiday</span>
                    </div>
                </section>

            <?php elseif ($page === 'leaves'): ?>
                <?php $leaveRequests = rows($pdo, 'SELECT l.*, e.first_name, e.last_name, t.name AS leave_type FROM leave_requests l JOIN employees e ON e.id = l.employee_id JOIN leave_types t ON t.id = l.leave_type_id ORDER BY FIELD(l.status, "pending", "approved", "rejected", "cancelled"), l.created_at DESC'); ?>
                <div class="page-heading"><div><p class="eyebrow">Absence management</p><h1>Leave management</h1><p class="muted">Submit requests, then approve or reject them from the request list.</p></div></div><div class="management-grid"><section class="panel" id="leave-form"><div class="panel-heading"><div><h2>Submit leave request</h2><p class="muted">Requests are created as pending.</p></div></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_leave"><input type="hidden" name="return_page" value="leaves"><label>Employee *<select required name="employee_id"><option value="">Choose an employee</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>"><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></option><?php endforeach; ?></select></label><label>Leave type *<select required name="leave_type_id"><option value="">Choose a leave type</option><?php foreach ($leaveTypes as $type): ?><option value="<?= (int) $type['id'] ?>"><?= e($type['name'] . ' (' . $type['annual_days'] . ' days)') ?></option><?php endforeach; ?></select></label><label>Start date *<input required type="date" name="start_date" value="<?= e($today) ?>"></label><label>End date *<input required type="date" name="end_date" value="<?= e($today) ?>"></label><label class="full-field">Reason<textarea name="reason" rows="3" placeholder="Optional reason for leave"></textarea></label><button class="primary-button" type="submit">Submit request</button></form></section><section class="panel" id="requests"><div class="panel-heading"><div><h2>Leave requests</h2><p class="muted">Pending requests appear first.</p></div></div><div class="responsive-table"><table><thead><tr><th>Employee</th><th>Leave</th><th>Dates</th><th>Status</th><th>Decision</th></tr></thead><tbody><?php foreach ($leaveRequests as $request): ?><tr><td><?= e($request['first_name'] . ' ' . $request['last_name']) ?></td><td><?= e($request['leave_type']) ?></td><td><?= e(displayDate($request['start_date']) . ' – ' . displayDate($request['end_date'])) ?></td><td><span class="status <?= e(statusClass($request['status'])) ?>"><?= e(ucfirst($request['status'])) ?></span></td><td><?php if ($request['status'] === 'pending'): ?><form method="post" class="decision-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="review_leave"><input type="hidden" name="return_page" value="leaves"><input type="hidden" name="id" value="<?= (int) $request['id'] ?>"><button name="status" value="approved" class="approve">Approve</button><button name="status" value="rejected" class="reject" data-confirm="Reject this leave request?">Reject</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?><?php if (!$leaveRequests): ?><tr><td colspan="5" class="no-results">No leave requests yet.</td></tr><?php endif; ?></tbody></table></div></section></div>

            <?php elseif ($page === 'recruitment'): ?>
                <?php $jobs = rows($pdo, 'SELECT j.*, COUNT(a.id) AS applicants FROM job_postings j LEFT JOIN job_applications a ON a.job_posting_id = j.id GROUP BY j.id ORDER BY j.created_at DESC'); $applications = rows($pdo, 'SELECT a.*, j.title FROM job_applications a JOIN job_postings j ON j.id = a.job_posting_id ORDER BY a.applied_at DESC LIMIT 50'); ?>
                <div class="page-heading"><div><p class="eyebrow">Candidate pipeline</p><h1>Recruitment</h1><p class="muted">Publish positions and keep candidate applications in one place.</p></div></div><div class="split-panels"><section class="panel"><div class="panel-heading"><div><h2>Publish a job</h2><p class="muted">New postings are open immediately.</p></div></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_job"><input type="hidden" name="return_page" value="recruitment"><label>Job title *<input required name="title"></label><label>Department *<input required list="departments" name="department"></label><label>Closing date<input type="date" name="closing_date"></label><label class="full-field">Description *<textarea required name="description" rows="3"></textarea></label><button class="primary-button" type="submit">Publish job</button></form></section><section class="panel"><div class="panel-heading"><div><h2>Add candidate</h2><p class="muted">Candidates can be attached to open jobs.</p></div></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_application"><input type="hidden" name="return_page" value="recruitment"><label>Open position *<select required name="job_posting_id"><option value="">Choose a job</option><?php foreach ($openJobs as $job): ?><option value="<?= (int) $job['id'] ?>"><?= e($job['title']) ?></option><?php endforeach; ?></select></label><label>Candidate name *<input required name="candidate_name"></label><label>Email *<input required type="email" name="email"></label><label>Phone<input name="phone"></label><button class="primary-button" type="submit">Add candidate</button></form></section></div><div class="dashboard-grid bottom-grid"><section class="panel"><div class="panel-heading"><div><h2>Job postings</h2><p class="muted">Open and closed positions</p></div></div><div class="responsive-table"><table><thead><tr><th>Position</th><th>Department</th><th>Applicants</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($jobs as $job): ?><tr><td><strong><?= e($job['title']) ?></strong><small>Closes <?= e(displayDate($job['closing_date'])) ?></small></td><td><?= e($job['department']) ?></td><td><?= (int) $job['applicants'] ?></td><td><span class="status <?= e(statusClass($job['status'])) ?>"><?= e(ucfirst($job['status'])) ?></span></td><td><?php if ($job['status'] === 'open'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="close_job"><input type="hidden" name="return_page" value="recruitment"><input type="hidden" name="id" value="<?= (int) $job['id'] ?>"><button class="link-button" data-confirm="Close this job posting?">Close</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$jobs): ?><tr><td colspan="5" class="no-results">No job postings yet.</td></tr><?php endif; ?></tbody></table></div></section><section class="panel"><div class="panel-heading"><div><h2>Latest candidates</h2><p class="muted">Most recent applications</p></div></div><div class="responsive-table"><table><thead><tr><th>Candidate</th><th>Position</th><th>Stage</th></tr></thead><tbody><?php foreach ($applications as $application): ?><tr><td><strong><?= e($application['candidate_name']) ?></strong><small><?= e($application['email']) ?></small></td><td><?= e($application['title']) ?></td><td><span class="status <?= e(statusClass($application['stage'])) ?>"><?= e(ucfirst($application['stage'])) ?></span></td></tr><?php endforeach; ?><?php if (!$applications): ?><tr><td colspan="3" class="no-results">No candidates yet.</td></tr><?php endif; ?></tbody></table></div></section></div>

            <?php elseif ($page === 'payroll'): ?>
                <?php $payrollPeriods = rows($pdo, 'SELECT p.*, COUNT(r.id) AS employee_count, COALESCE(SUM(r.net_salary), 0) AS total_net, COALESCE(SUM(r.deductions), 0) AS total_deductions FROM payroll_periods p LEFT JOIN payroll_records r ON r.payroll_period_id = p.id GROUP BY p.id ORDER BY p.created_at DESC LIMIT 30'); $payrollDeductions = rows($pdo, 'SELECT d.deduction_date, d.deduction_type, d.amount, d.notes, p.period_start, p.period_end, e.first_name, e.last_name FROM payroll_deductions d JOIN payroll_records r ON r.id = d.payroll_record_id JOIN payroll_periods p ON p.id = r.payroll_period_id JOIN employees e ON e.id = r.employee_id ORDER BY d.deduction_date DESC, e.first_name, e.last_name LIMIT 100'); ?>
                <div class="page-heading"><div><p class="eyebrow">Compensation</p><h1>Payroll</h1><p class="muted">Create payroll with day-wise deductions for absent and half-day attendance, then export it as Tally-compatible XML.</p></div></div><div class="management-grid"><section class="panel" id="payroll-form"><div class="panel-heading"><div><h2>Run payroll</h2><p class="muted">Daily rate is basic salary divided by the calendar days in the selected period.</p></div></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="run_payroll"><input type="hidden" name="return_page" value="payroll"><label>Period start *<input required type="date" name="period_start" value="<?= e(date('Y-m-01')) ?>"></label><label>Period end *<input required type="date" name="period_end" value="<?= e(date('Y-m-t')) ?>"></label><label>Payment date<input type="date" name="payment_date" value="<?= e(date('Y-m-t')) ?>"></label><button class="primary-button" type="submit" data-confirm="Create a payroll draft for all active employees?">Create payroll draft</button></form></section><section class="panel"><div class="panel-heading"><div><h2>Payroll history</h2><p class="muted">Most recent 30 periods</p></div></div><div class="responsive-table"><table><thead><tr><th>Period</th><th>Payment</th><th>Employees</th><th>Deductions</th><th>Net pay</th><th>Status</th><th>Tally</th></tr></thead><tbody><?php foreach ($payrollPeriods as $period): ?><tr><td><?= e(displayDate($period['period_start']) . ' – ' . displayDate($period['period_end'])) ?></td><td><?= e(displayDate($period['payment_date'])) ?></td><td><?= (int) $period['employee_count'] ?></td><td>₹<?= e(number_format((float) $period['total_deductions'], 2)) ?></td><td>₹<?= e(number_format((float) $period['total_net'], 2)) ?></td><td><span class="status <?= e(statusClass($period['status'])) ?>"><?= e(ucfirst($period['status'])) ?></span></td><td><a class="text-link" href="index.php?page=payroll&amp;tally_export=<?= (int) $period['id'] ?>">Export XML</a></td></tr><?php endforeach; ?><?php if (!$payrollPeriods): ?><tr><td colspan="7" class="no-results">No payroll periods yet.</td></tr><?php endif; ?></tbody></table></div></section></div><section class="panel"><div class="panel-heading"><div><h2>Day-wise salary deductions</h2><p class="muted">Absent days deduct one daily rate; half days deduct half of the daily rate.</p></div></div><div class="responsive-table"><table><thead><tr><th>Employee</th><th>Date</th><th>Period</th><th>Reason</th><th>Deduction</th></tr></thead><tbody><?php foreach ($payrollDeductions as $deduction): ?><tr><td><?= e($deduction['first_name'] . ' ' . $deduction['last_name']) ?></td><td><?= e(displayDate($deduction['deduction_date'])) ?></td><td><?= e(displayDate($deduction['period_start']) . ' – ' . displayDate($deduction['period_end'])) ?></td><td><?= e($deduction['notes']) ?></td><td>₹<?= e(number_format((float) $deduction['amount'], 2)) ?></td></tr><?php endforeach; ?><?php if (!$payrollDeductions): ?><tr><td colspan="5" class="no-results">No day-wise deductions recorded yet.</td></tr><?php endif; ?></tbody></table></div></section>

            <?php elseif ($page === 'training'): ?>
                <?php $programs = rows($pdo, 'SELECT t.*, COUNT(e.id) AS enrolled FROM training_programs t LEFT JOIN training_enrollments e ON e.training_program_id = t.id GROUP BY t.id ORDER BY t.start_date DESC, t.id DESC'); ?>
                <div class="page-heading"><div><p class="eyebrow">Learning and development</p><h1>Training</h1><p class="muted">Plan training and enroll employees in development programs.</p></div></div><div class="split-panels"><section class="panel"><div class="panel-heading"><div><h2>New program</h2><p class="muted">Programs start as planned.</p></div></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_training"><input type="hidden" name="return_page" value="training"><label>Program name *<input required name="name"></label><label>Provider<input name="provider"></label><label>Start date<input type="date" name="start_date"></label><label>End date<input type="date" name="end_date"></label><label class="full-field">Description<textarea name="description" rows="3"></textarea></label><button class="primary-button" type="submit">Create program</button></form></section><section class="panel"><div class="panel-heading"><div><h2>Enroll employee</h2><p class="muted">Add an employee to a planned or active program.</p></div></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="enroll_training"><input type="hidden" name="return_page" value="training"><label>Program *<select required name="training_program_id"><option value="">Choose a program</option><?php foreach ($trainingPrograms as $program): ?><option value="<?= (int) $program['id'] ?>"><?= e($program['name']) ?></option><?php endforeach; ?></select></label><label>Employee *<select required name="employee_id"><option value="">Choose an employee</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>"><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></option><?php endforeach; ?></select></label><button class="primary-button" type="submit">Enroll employee</button></form></section></div><section class="panel"><div class="panel-heading"><div><h2>Training programs</h2><p class="muted">Enrollment totals update as people are added.</p></div></div><div class="responsive-table"><table><thead><tr><th>Program</th><th>Provider</th><th>Dates</th><th>Enrolled</th><th>Status</th></tr></thead><tbody><?php foreach ($programs as $program): ?><tr><td><strong><?= e($program['name']) ?></strong><small><?= e($program['description'] ?? '') ?></small></td><td><?= e($program['provider'] ?? '—') ?></td><td><?= e(displayDate($program['start_date']) . ' – ' . displayDate($program['end_date'])) ?></td><td><?= (int) $program['enrolled'] ?></td><td><span class="status <?= e(statusClass($program['status'])) ?>"><?= e(ucfirst($program['status'])) ?></span></td></tr><?php endforeach; ?><?php if (!$programs): ?><tr><td colspan="5" class="no-results">No training programs yet.</td></tr><?php endif; ?></tbody></table></div></section>

            <?php elseif ($page === 'performance'): ?>
                <?php $reviews = rows($pdo, 'SELECT r.*, e.first_name, e.last_name FROM performance_reviews r JOIN employees e ON e.id = r.employee_id ORDER BY r.review_date DESC LIMIT 100'); ?>
                <div class="page-heading"><div><p class="eyebrow">Growth and feedback</p><h1>Performance</h1><p class="muted">Record structured reviews using a one-to-five rating.</p></div></div><div class="management-grid"><section class="panel"><div class="panel-heading"><div><h2>Add review</h2><p class="muted">Reviews are saved to the employee history.</p></div></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_review"><input type="hidden" name="return_page" value="performance"><label>Employee *<select required name="employee_id"><option value="">Choose an employee</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>"><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></option><?php endforeach; ?></select></label><label>Review date *<input required type="date" name="review_date" value="<?= e($today) ?>"></label><label>Reviewer *<input required name="reviewer_name" value="<?= e($authName) ?>"></label><label>Rating *<select name="rating"><?php for ($rating = 5; $rating >= 1; $rating--): ?><option value="<?= $rating ?>"><?= $rating ?> / 5</option><?php endfor; ?></select></label><label class="full-field">Comments<textarea name="comments" rows="3"></textarea></label><button class="primary-button" type="submit">Save review</button></form></section><section class="panel"><div class="panel-heading"><div><h2>Review history</h2><p class="muted">Latest 100 reviews</p></div></div><div class="responsive-table"><table><thead><tr><th>Employee</th><th>Date</th><th>Reviewer</th><th>Rating</th></tr></thead><tbody><?php foreach ($reviews as $review): ?><tr><td><?= e($review['first_name'] . ' ' . $review['last_name']) ?></td><td><?= e(displayDate($review['review_date'])) ?></td><td><?= e($review['reviewer_name']) ?></td><td><strong><?= e((string) $review['rating']) ?> / 5</strong></td></tr><?php endforeach; ?><?php if (!$reviews): ?><tr><td colspan="4" class="no-results">No performance reviews yet.</td></tr><?php endif; ?></tbody></table></div></section></div>

            <?php elseif ($page === 'benefits'): ?>
                <?php $plans = rows($pdo, 'SELECT b.*, COUNT(e.id) AS enrolled FROM benefit_plans b LEFT JOIN employee_benefits e ON e.benefit_plan_id = b.id AND e.status = "active" GROUP BY b.id ORDER BY b.name'); ?>
                <div class="page-heading"><div><p class="eyebrow">Employee wellbeing</p><h1>Benefits</h1><p class="muted">Create benefit plans and enroll employees.</p></div></div><div class="split-panels"><section class="panel"><div class="panel-heading"><div><h2>New benefit plan</h2><p class="muted">Plans are active when created.</p></div></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_benefit"><input type="hidden" name="return_page" value="benefits"><label>Plan name *<input required name="name"></label><label>Provider<input name="provider"></label><label>Monthly cost *<input required min="0" step="0.01" type="number" name="monthly_cost" value="0"></label><label class="full-field">Description<textarea name="description" rows="3"></textarea></label><button class="primary-button" type="submit">Create plan</button></form></section><section class="panel"><div class="panel-heading"><div><h2>Enroll employee</h2><p class="muted">Re-enrolling an employee restores their active enrollment.</p></div></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="enroll_benefit"><input type="hidden" name="return_page" value="benefits"><label>Employee *<select required name="employee_id"><option value="">Choose an employee</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>"><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></option><?php endforeach; ?></select></label><label>Benefit plan *<select required name="benefit_plan_id"><option value="">Choose a benefit plan</option><?php foreach ($benefitPlans as $benefit): ?><option value="<?= (int) $benefit['id'] ?>"><?= e($benefit['name']) ?></option><?php endforeach; ?></select></label><label>Enrollment date *<input required type="date" name="enrolled_on" value="<?= e($today) ?>"></label><button class="primary-button" type="submit">Enroll employee</button></form></section></div><section class="panel"><div class="panel-heading"><div><h2>Benefit plans</h2><p class="muted">Active enrollments by plan</p></div></div><div class="responsive-table"><table><thead><tr><th>Plan</th><th>Provider</th><th>Monthly cost</th><th>Enrolled</th><th>Status</th></tr></thead><tbody><?php foreach ($plans as $plan): ?><tr><td><strong><?= e($plan['name']) ?></strong><small><?= e($plan['description'] ?? '') ?></small></td><td><?= e($plan['provider'] ?? '—') ?></td><td>₹<?= e(number_format((float) $plan['monthly_cost'], 2)) ?></td><td><?= (int) $plan['enrolled'] ?></td><td><span class="status <?= (int) $plan['active'] === 1 ? 'active' : 'inactive' ?>"><?= (int) $plan['active'] === 1 ? 'Active' : 'Inactive' ?></span></td></tr><?php endforeach; ?><?php if (!$plans): ?><tr><td colspan="5" class="no-results">No benefit plans yet.</td></tr><?php endif; ?></tbody></table></div></section>

            <?php elseif ($page === 'assets'): ?>
                <?php $assets = rows($pdo, 'SELECT a.*, e.first_name, e.last_name FROM company_assets a LEFT JOIN asset_assignments aa ON aa.asset_id = a.id AND aa.returned_on IS NULL LEFT JOIN employees e ON e.id = aa.employee_id ORDER BY a.created_at DESC'); ?>
                <div class="page-heading"><div><p class="eyebrow">Equipment register</p><h1>Assets</h1><p class="muted">Register company property and record who currently holds it.</p></div></div><div class="split-panels"><section class="panel"><div class="panel-heading"><div><h2>Register asset</h2><p class="muted">Each asset tag must be unique.</p></div></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_asset"><input type="hidden" name="return_page" value="assets"><label>Asset tag *<input required name="asset_tag" placeholder="e.g. LAP-001"></label><label>Asset name *<input required name="name" placeholder="e.g. MacBook Pro"></label><label>Category *<input required name="category" placeholder="e.g. Laptop"></label><label>Serial number<input name="serial_number"></label><label>Purchase date<input type="date" name="purchase_date"></label><button class="primary-button" type="submit">Register asset</button></form></section><section class="panel"><div class="panel-heading"><div><h2>Assign asset</h2><p class="muted">Only available assets can be assigned.</p></div></div><form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="assign_asset"><input type="hidden" name="return_page" value="assets"><label>Available asset *<select required name="asset_id"><option value="">Choose an asset</option><?php foreach ($availableAssets as $asset): ?><option value="<?= (int) $asset['id'] ?>"><?= e($asset['asset_tag'] . ' · ' . $asset['name']) ?></option><?php endforeach; ?></select></label><label>Employee *<select required name="employee_id"><option value="">Choose an employee</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>"><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></option><?php endforeach; ?></select></label><label>Assigned date *<input required type="date" name="assigned_on" value="<?= e($today) ?>"></label><label class="full-field">Notes<textarea name="notes" rows="3"></textarea></label><button class="primary-button" type="submit">Assign asset</button></form></section></div><section class="panel"><div class="panel-heading"><div><h2>Asset register</h2><p class="muted">Current holder is shown for assigned assets.</p></div></div><div class="responsive-table"><table><thead><tr><th>Asset</th><th>Category</th><th>Serial no.</th><th>Holder</th><th>Status</th></tr></thead><tbody><?php foreach ($assets as $asset): ?><tr><td><strong><?= e($asset['asset_tag']) ?></strong><small><?= e($asset['name']) ?></small></td><td><?= e($asset['category']) ?></td><td><?= e($asset['serial_number'] ?? '—') ?></td><td><?= $asset['first_name'] ? e($asset['first_name'] . ' ' . $asset['last_name']) : '—' ?></td><td><span class="status <?= e(statusClass($asset['status'])) ?>"><?= e(ucfirst($asset['status'])) ?></span></td></tr><?php endforeach; ?><?php if (!$assets): ?><tr><td colspan="5" class="no-results">No assets registered yet.</td></tr><?php endif; ?></tbody></table></div></section>

            <?php elseif ($page === 'reports'): ?>
                <?php $departmentStats = rows($pdo, "SELECT department, COUNT(*) AS employees, SUM(employment_status = 'active') AS active FROM employees GROUP BY department ORDER BY employees DESC"); $leaveStats = rows($pdo, 'SELECT t.name, COUNT(l.id) AS requests, SUM(l.status = "approved") AS approved FROM leave_types t LEFT JOIN leave_requests l ON l.leave_type_id = t.id GROUP BY t.id ORDER BY requests DESC'); ?>
                <div class="page-heading"><div><p class="eyebrow">Organization insights</p><h1>Reports</h1><p class="muted">Live summaries drawn from the records saved in PeopleHub.</p></div></div><section class="metric-grid"><article class="metric-card"><div class="metric-top"><span class="metric-icon blue"><span class="icon" data-icon="users"></span></span></div><strong class="metric-value"><?= number_format(dashboardCount($pdo, 'employees')) ?></strong><span class="metric-label">Employees on record</span></article><article class="metric-card"><div class="metric-top"><span class="metric-icon violet"><span class="icon" data-icon="briefcase"></span></span></div><strong class="metric-value"><?= number_format(dashboardCount($pdo, 'job_applications')) ?></strong><span class="metric-label">Job applications</span></article><article class="metric-card"><div class="metric-top"><span class="metric-icon orange"><span class="icon" data-icon="calendar"></span></span></div><strong class="metric-value"><?= number_format(dashboardCount($pdo, 'leave_requests')) ?></strong><span class="metric-label">Leave requests</span></article><article class="metric-card"><div class="metric-top"><span class="metric-icon green"><span class="icon" data-icon="book"></span></span></div><strong class="metric-value"><?= number_format(dashboardCount($pdo, 'training_enrollments')) ?></strong><span class="metric-label">Training enrollments</span></article></section><div class="dashboard-grid bottom-grid"><section class="panel"><div class="panel-heading"><div><h2>Employees by department</h2><p class="muted">Active count compared with all employee records.</p></div></div><div class="responsive-table"><table><thead><tr><th>Department</th><th>Employees</th><th>Active</th></tr></thead><tbody><?php foreach ($departmentStats as $stat): ?><tr><td><?= e($stat['department']) ?></td><td><?= (int) $stat['employees'] ?></td><td><?= (int) $stat['active'] ?></td></tr><?php endforeach; ?><?php if (!$departmentStats): ?><tr><td colspan="3" class="no-results">No employee data yet.</td></tr><?php endif; ?></tbody></table></div></section><section class="panel"><div class="panel-heading"><div><h2>Leave by type</h2><p class="muted">Request and approval counts.</p></div></div><div class="responsive-table"><table><thead><tr><th>Leave type</th><th>Requests</th><th>Approved</th></tr></thead><tbody><?php foreach ($leaveStats as $stat): ?><tr><td><?= e($stat['name']) ?></td><td><?= (int) $stat['requests'] ?></td><td><?= (int) $stat['approved'] ?></td></tr><?php endforeach; ?><?php if (!$leaveStats): ?><tr><td colspan="3" class="no-results">No leave data yet.</td></tr><?php endif; ?></tbody></table></div></section></div>

            <?php elseif ($page === 'users'): ?>
                <?php
                ensureUsersTable($pdo);
                $accountRows = rows($pdo, 'SELECT u.id, u.full_name, u.email, u.role, u.last_login_at, e.first_name, e.last_name, e.employee_code FROM app_users u LEFT JOIN employees e ON e.id = u.employee_id ORDER BY FIELD(u.role, \'admin\', \'hr\', \'employee\'), u.full_name');
                $unlinkedEmployees = rows($pdo, 'SELECT e.id, e.first_name, e.last_name, e.employee_code FROM employees e WHERE e.id NOT IN (SELECT employee_id FROM app_users WHERE employee_id IS NOT NULL) ORDER BY e.first_name, e.last_name');
                ?>
                <div class="page-heading"><div><p class="eyebrow">Access control</p><h1>User accounts</h1><p class="muted"><?= $isAdmin ? 'Create Admin, HR and Employee logins.' : 'Create and manage Employee logins.' ?> Employees only see their own attendance and payment history.</p></div></div>
                <div class="management-grid">
                    <section class="panel"><div class="panel-heading"><div><h2>New account</h2><p class="muted">Employee logins must be linked to an employee record.</p></div></div>
                        <form method="post" class="data-form" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="create_user"><input type="hidden" name="return_page" value="users">
                            <label class="full-field">Full name *<input required name="full_name"></label>
                            <label class="full-field">Email (used to sign in) *<input required type="email" name="email"></label>
                            <label>Login type *<select name="role" id="newRole"><?php foreach (ROLES as $roleKey => $roleLabel): ?><?php if ($roleKey === 'employee' || $isAdmin): ?><option value="<?= e($roleKey) ?>" <?= $roleKey === 'employee' ? 'selected' : '' ?>><?= e($roleLabel) ?></option><?php endif; ?><?php endforeach; ?></select></label>
                            <label id="employeeLinkField">Employee record<select name="employee_id"><option value="">Choose an employee</option><?php foreach ($unlinkedEmployees as $person): ?><option value="<?= (int) $person['id'] ?>"><?= e($person['first_name'] . ' ' . $person['last_name'] . ' (' . $person['employee_code'] . ')') ?></option><?php endforeach; ?></select></label>
                            <label class="full-field">Temporary password * (8+ characters)<input required type="password" name="password" minlength="8" autocomplete="new-password"></label>
                            <button class="primary-button" type="submit">Create account</button>
                        </form>
                    </section>
                    <section class="panel"><div class="panel-heading"><div><h2>All accounts</h2><p class="muted"><?= number_format(count($accountRows)) ?> account(s)</p></div></div>
                        <div class="responsive-table"><table><thead><tr><th>Account</th><th>Type</th><th>Last sign-in</th><th>Manage</th></tr></thead><tbody>
                        <?php foreach ($accountRows as $account): $canManage = $account['role'] === 'employee' || $isAdmin; ?>
                            <tr><td><strong><?= e($account['full_name']) ?></strong><small><?= e($account['email']) ?><?= $account['first_name'] ? ' · ' . e($account['first_name'] . ' ' . $account['last_name']) : '' ?></small></td>
                            <td><span class="status <?= $account['role'] === 'employee' ? 'enrolled' : ($account['role'] === 'hr' ? 'planned' : 'active') ?>"><?= e(ROLES[$account['role']] ?? ucfirst($account['role'])) ?></span></td>
                            <td><?= $account['last_login_at'] ? e(date('M j, Y g:i A', strtotime($account['last_login_at']))) : 'Never' ?></td>
                            <td><?php if ($canManage): ?>
                                <form method="post" class="decision-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="reset_user_password"><input type="hidden" name="return_page" value="users"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><input type="password" name="password" minlength="8" placeholder="New password" aria-label="New password" class="mini-input" autocomplete="new-password"><button type="submit">Reset</button></form>
                                <?php if ((int) $account['id'] !== (int) $authUser['id']): ?><form method="post" style="margin-top:6px"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="return_page" value="users"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><button class="link-button" data-confirm="Remove this account? The person will no longer be able to sign in.">Remove</button></form><?php endif; ?>
                            <?php else: ?>—<?php endif; ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$accountRows): ?><tr><td colspan="4" class="no-results">No accounts yet.</td></tr><?php endif; ?>
                        </tbody></table></div>
                    </section>
                </div>
                <script>(function(){var r=document.getElementById('newRole'),f=document.getElementById('employeeLinkField');function t(){f.style.display=r.value==='employee'?'':'none'}r.addEventListener('change',t);t()})();</script>

            <?php elseif ($page === 'settings'): ?>
                <div class="page-heading"><div><p class="eyebrow">System configuration</p><h1>Settings</h1><p class="muted">Connection status and deployment guidance for this installation.</p></div></div><section class="panel detail-panel"><h2>Database connection</h2><p class="muted">Status: <strong class="<?= $pdo ? 'connection-ok' : 'connection-error' ?>"><?= $pdo ? 'Connected' : 'Unavailable' ?></strong></p><dl><div><dt>Host</dt><dd><?= e($databaseConfig['host']) ?>:<?= e($databaseConfig['port']) ?></dd></div><div><dt>Database</dt><dd><?= e($databaseConfig['database']) ?></dd></div><div><dt>Character set</dt><dd><?= e($databaseConfig['charset']) ?></dd></div></dl><p class="muted">Use the HRMS_DB_HOST, HRMS_DB_PORT, HRMS_DB_NAME, HRMS_DB_USER, and HRMS_DB_PASSWORD environment variables to override the defaults without editing source files.</p></section>

            <?php else: ?>
                <div class="page-heading"><div><p class="eyebrow">PeopleHub guide</p><h1>Help center</h1><p class="muted">Everything needed to get this local HR system running.</p></div></div><section class="panel detail-panel"><h2>First-time setup</h2><ol><li>Start Apache and MySQL from WAMP.</li><li>Open phpMyAdmin and import <code>database/schema.sql</code> from this project.</li><li>Open this folder through your local web server, then create your first employee.</li></ol><h2>What each area does</h2><p class="muted">Employees, attendance, leave, recruitment, payroll, training, performance, benefits, assets, and reports are connected to the database. Forms validate their input, prevent cross-site request forgery, and show a confirmation after each successful save.</p></section>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>