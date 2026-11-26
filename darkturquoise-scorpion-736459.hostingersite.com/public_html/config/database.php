<?php

declare(strict_types=1);

return [
    'host' => getenv('HRMS_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('HRMS_DB_PORT') ?: '3306',
    'database' => getenv('HRMS_DB_NAME') ?: 'hrms',
    'username' => getenv('HRMS_DB_USER') ?: 'root',
    'password' => getenv('HRMS_DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
];
