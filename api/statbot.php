<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../botapi.php';
header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set('Asia/Tehran');
ini_set('default_charset', 'UTF-8');
ini_set('error_log', 'error_log');

$headers = getallheaders();
requireApiToken($headers);
logApiRequest($headers, [], 'statbot');

try {
    $count_user = select("user", "*", null, null, "count");
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user WHERE agent != 'f'");
    $stmt->execute();
    $count_agent = (int) $stmt->fetchColumn();
    $count_invoice = select("invoice", "*", null, null, "count");

    echo json_encode([
        'count_user' => $count_user,
        'count_invoice' => $count_invoice,
        'count_agent' => $count_agent
    ]);
} catch (Exception $e) {
    error_log("Database error in statbot: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'msg' => "Database error occurred"
    ]);
}
