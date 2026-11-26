<?php

declare(strict_types=1);

/** Shared application bootstrap and small, framework-free helpers. */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$databaseConfig = require __DIR__ . '/database.php';
$pdo = null;
$databaseError = null;

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $databaseConfig['host'],
        $databaseConfig['port'],
        $databaseConfig['database'],
        $databaseConfig['charset']
    );

    $pdo = new PDO($dsn, $databaseConfig['username'], $databaseConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $exception) {
    $databaseError = 'Database connection unavailable. Start MySQL in WAMP, import database/schema.sql, then refresh this page.';
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        http_response_code(419);
        exit('Your session has expired. Please go back and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return array<int, array{type:string,message:string}> */
function consumeFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($flashes) ? $flashes : [];
}

function redirectTo(string $page, array $parameters = []): never
{
    $query = http_build_query(array_merge(['page' => $page], $parameters));
    header('Location: index.php?' . $query, true, 303);
    exit;
}

function dashboardCount(?PDO $pdo, string $table, string $where = ''): int
{
    if ($pdo === null) {
        return 0;
    }

    $allowedTables = ['employees', 'leave_requests', 'attendance_records', 'training_enrollments', 'job_applications'];
    if (!in_array($table, $allowedTables, true)) {
        return 0;
    }

    try {
        $statement = $pdo->query("SELECT COUNT(*) FROM `{$table}` {$where}");
        return (int) $statement->fetchColumn();
    } catch (PDOException $exception) {
        return 0;
    }
}

/** @return array<int, array<string, mixed>> */
function rows(?PDO $pdo, string $sql, array $parameters = []): array
{
    if ($pdo === null) {
        return [];
    }

    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    } catch (PDOException $exception) {
        return [];
    }
}

/** @return array<string, mixed>|null */
function row(?PDO $pdo, string $sql, array $parameters = []): ?array
{
    $result = rows($pdo, $sql, $parameters);
    return $result[0] ?? null;
}

function isValidDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function initials(string $firstName, string $lastName): string
{
    return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
}
