<?php

declare(strict_types=1);

/**
 * Session-based authentication with three roles:
 *   admin    - everything, including all user accounts and settings
 *   hr       - all HR modules, can create employee accounts
 *   employee - self-service portal only (own attendance + payment history)
 * Uses the existing $pdo connection from bootstrap.php (the connection itself is not modified).
 */

const ROLES = ['admin' => 'Admin', 'hr' => 'HR', 'employee' => 'Employee'];

function ensureUsersTable(?PDO $pdo): bool
{
    if ($pdo === null) {
        return false;
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(120) NOT NULL,
                email VARCHAR(160) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(40) NOT NULL DEFAULT \'admin\',
                employee_id INT UNSIGNED NULL,
                last_login_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_employee (employee_id)
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        // Upgrade tables created by the earlier single-role version.
        $hasColumn = $pdo->query("SHOW COLUMNS FROM app_users LIKE 'employee_id'")->fetch();
        if (!$hasColumn) {
            $pdo->exec('ALTER TABLE app_users ADD COLUMN employee_id INT UNSIGNED NULL AFTER role');
            $pdo->exec('ALTER TABLE app_users ADD UNIQUE KEY uq_user_employee (employee_id)');
        }
        return true;
    } catch (PDOException $exception) {
        return false;
    }
}

function userCount(?PDO $pdo): int
{
    if ($pdo === null || !ensureUsersTable($pdo)) {
        return 0;
    }

    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn();
    } catch (PDOException $exception) {
        return 0;
    }
}

/** @return array{id:int,name:string,email:string,role:string,employee_id:?int}|null */
function currentUser(): ?array
{
    $user = $_SESSION['auth_user'] ?? null;
    return is_array($user) && isset($user['id']) ? $user : null;
}

function roleHome(string $role): string
{
    return $role === 'employee' ? 'employee.php' : 'index.php';
}

/**
 * Redirects to the login page when signed out. When a database is available the account is
 * re-checked on every request, so deleted accounts and role changes take effect immediately.
 */
function requireLogin(?PDO $pdo = null): void
{
    $user = currentUser();
    if ($user === null) {
        header('Location: login.php', true, 303);
        exit;
    }

    if ($pdo !== null) {
        try {
            $statement = $pdo->prepare('SELECT full_name, role, employee_id FROM app_users WHERE id = ?');
            $statement->execute([$user['id']]);
            $fresh = $statement->fetch();
            if (!$fresh) {
                logoutUser();
                session_start();
                header('Location: login.php', true, 303);
                exit;
            }
            $_SESSION['auth_user']['name'] = (string) $fresh['full_name'];
            $_SESSION['auth_user']['role'] = (string) $fresh['role'];
            $_SESSION['auth_user']['employee_id'] = $fresh['employee_id'] !== null ? (int) $fresh['employee_id'] : null;
        } catch (PDOException $exception) {
            // Keep the existing session if the check itself fails.
        }
    }
}

/** Sends people who open a page their role may not use back to their own home page. */
function requireRole(array $allowed): void
{
    $user = currentUser();
    $role = $user['role'] ?? '';
    if (!in_array($role, $allowed, true)) {
        header('Location: ' . roleHome($role), true, 303);
        exit;
    }
}

function loginUser(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'id' => (int) $row['id'],
        'name' => (string) $row['full_name'],
        'email' => (string) $row['email'],
        'role' => (string) $row['role'],
        'employee_id' => isset($row['employee_id']) && $row['employee_id'] !== null ? (int) $row['employee_id'] : null,
    ];
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

function userInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = $parts[0] ?? '';
    $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';
    $cut = static fn (string $text): string => function_exists('mb_substr') ? mb_substr($text, 0, 1) : substr($text, 0, 1);
    $value = strtoupper($cut($first) . $cut($last));
    return $value !== '' ? $value : 'U';
}

/** Very small login throttle: 5 failed attempts locks the session for 5 minutes. */
function loginLockedSeconds(): int
{
    $until = (int) ($_SESSION['login_locked_until'] ?? 0);
    return max(0, $until - time());
}

function registerFailedLogin(): void
{
    $_SESSION['login_failures'] = (int) ($_SESSION['login_failures'] ?? 0) + 1;
    if ($_SESSION['login_failures'] >= 5) {
        $_SESSION['login_locked_until'] = time() + 300;
        $_SESSION['login_failures'] = 0;
    }
}
