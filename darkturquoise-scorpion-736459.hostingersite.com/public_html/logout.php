<?php

declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';
require __DIR__ . '/config/auth.php';

// POST + CSRF so a stray link or image cannot sign people out.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    logoutUser();
    session_start();
    header('Location: login.php?logged_out=1', true, 303);
    exit;
}

header('Location: index.php', true, 303);
exit;
