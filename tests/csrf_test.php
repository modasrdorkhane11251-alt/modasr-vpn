<?php
/** Panel CSRF helper tests (session-based, CLI-safe). */

section('panel csrf');

require_once __DIR__ . '/../panel/inc/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$token = csrf_token();
ok(is_string($token) && strlen($token) === 64, 'csrf_token returns a 64-char hex token');
ok(csrf_token() === $token, 'csrf_token is stable within the session');
ok(csrf_check_value($token) === true, 'correct token passes csrf_check_value');
ok(csrf_check_value('invalid') === false, 'wrong token fails csrf_check_value');
ok(csrf_check_value('') === false, 'empty token fails csrf_check_value');
