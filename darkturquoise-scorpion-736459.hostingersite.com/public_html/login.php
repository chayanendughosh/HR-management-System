<?php

declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';
require __DIR__ . '/config/auth.php';

if (currentUser() !== null) {
    header('Location: ' . roleHome((string) currentUser()['role']), true, 303);
    exit;
}

$error = null;
$notice = null;
$setupMode = $pdo !== null && userCount($pdo) === 0;
$loginAs = $_POST['login_as'] ?? ($_GET['as'] ?? 'employee');
$loginAs = is_string($loginAs) && isset(ROLES[$loginAs]) ? $loginAs : 'employee';
if ($setupMode) {
    $loginAs = 'admin';
}

if (isset($_GET['logged_out'])) {
    $notice = 'You have been signed out.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'login';
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if ($pdo === null) {
        $error = 'The database is unavailable right now. Please try again shortly.';
    } elseif ($action === 'setup' && $setupMode) {
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter your name and a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Choose a password with at least 8 characters.';
        } elseif (!hash_equals($password, $confirm)) {
            $error = 'The two passwords do not match.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO app_users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin']);
                $user = row($pdo, 'SELECT * FROM app_users WHERE email = ?', [$email]);
                if ($user) {
                    loginUser($user);
                    header('Location: index.php', true, 303);
                    exit;
                }
            } catch (PDOException $exception) {
                $error = 'The account could not be created.';
            }
        }
    } else {
        $locked = loginLockedSeconds();
        if ($locked > 0) {
            $error = 'Too many attempts. Try again in ' . (int) ceil($locked / 60) . ' minute(s).';
        } else {
            $user = row($pdo, 'SELECT * FROM app_users WHERE email = ?', [$email]);
            // Always run a hash check so response time does not reveal whether the email exists.
            $hash = $user['password_hash'] ?? password_hash('placeholder', PASSWORD_DEFAULT);
            $valid = password_verify($password, $hash) && $user !== null;
            // The account must belong to the login type that was chosen (Employee / HR / Admin).
            $roleMatches = $user !== null && $user['role'] === $loginAs;
            if ($valid && $roleMatches) {
                $_SESSION['login_failures'] = 0;
                try {
                    $pdo->prepare('UPDATE app_users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
                } catch (PDOException $exception) {
                }
                loginUser($user);
                header('Location: ' . roleHome((string) $user['role']), true, 303);
                exit;
            }
            registerFailedLogin();
            $error = $valid
                ? 'This account is not an ' . ROLES[$loginAs] . ' account. Choose the correct login type above.'
                : 'Incorrect email or password.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $setupMode ? 'Create admin account' : 'Sign in' ?> · PS Management System HR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
    <link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
</head>
<body class="auth-body">
<main class="auth-shell">
    <section class="auth-aside" aria-hidden="true">
        <div class="auth-brand"><span class="brand-mark">PS</span><span>PS Management System</span></div>
        <div class="auth-aside-copy">
            <p class="eyebrow">HR management</p>
            <h2>One calm place for your people operations.</h2>
            <p>Employees, attendance, leave, payroll and more, all in a single workspace.</p>
        </div>
        <ul class="auth-points">
            <li>Attendance and leave approvals</li>
            <li>Payroll drafts in one click</li>
            <li>Live reports from your records</li>
        </ul>
    </section>
    <section class="auth-panel">
        <div class="auth-card">
            <div class="auth-brand auth-brand-mobile"><span class="brand-mark">PS</span><span>PS Management System</span></div>
            <h1><?= $setupMode ? 'Create the admin account' : e(ROLES[$loginAs]) . ' login' ?></h1>
            <p class="muted auth-sub"><?= $setupMode ? 'No users exist yet. This first account will have full access.' : ['employee' => 'See your own attendance and payment history.', 'hr' => 'Manage employees, attendance, leave and payroll.', 'admin' => 'Full access, including user accounts and settings.'][$loginAs] ?></p>

            <?php if (!$setupMode): ?>
                <nav class="role-tabs" aria-label="Login type">
                    <?php foreach (ROLES as $roleKey => $roleLabel): ?>
                        <a href="login.php?as=<?= e($roleKey) ?>" class="<?= $loginAs === $roleKey ? 'active' : '' ?>" <?= $loginAs === $roleKey ? 'aria-current="page"' : '' ?>><?= e($roleLabel) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <?php if ($notice): ?><div class="flash success" role="status"><?= e($notice) ?></div><?php endif; ?>
            <?php if ($databaseError): ?><div class="database-alert"><?= e($databaseError) ?></div><?php endif; ?>

            <form method="post" class="auth-form" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="<?= $setupMode ? 'setup' : 'login' ?>">
                <input type="hidden" name="login_as" value="<?= e($loginAs) ?>">
                <?php if ($setupMode): ?>
                    <label>Full name<input required name="full_name" autocomplete="name" value="<?= e((string) ($_POST['full_name'] ?? '')) ?>"></label>
                <?php endif; ?>
                <label>Email<input required type="email" name="email" autocomplete="username" autofocus value="<?= e((string) ($_POST['email'] ?? '')) ?>"></label>
                <label>Password
                    <span class="password-field"><input required type="password" name="password" id="password" autocomplete="<?= $setupMode ? 'new-password' : 'current-password' ?>" minlength="<?= $setupMode ? 8 : 1 ?>"><button type="button" class="show-password" id="togglePassword" aria-label="Show password">Show</button></span>
                </label>
                <?php if ($setupMode): ?>
                    <label>Confirm password<input required type="password" name="password_confirm" autocomplete="new-password" minlength="8"></label>
                <?php endif; ?>
                <button class="primary-button auth-submit" type="submit" <?= $pdo === null ? 'disabled' : '' ?>><?= $setupMode ? 'Create account' : 'Sign in' ?></button>
            </form>
        </div>
        <p class="auth-footnote">© <?= e(date('Y')) ?> PeopleHub HR</p>
    </section>
</main>
<script>
document.getElementById('togglePassword')?.addEventListener('click', function () {
  var input = document.getElementById('password');
  var show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  this.textContent = show ? 'Hide' : 'Show';
  this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});
</script>
</body>
</html>
