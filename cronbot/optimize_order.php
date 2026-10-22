<?php
chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
$setting = select("setting", "*");
$textbotlang = languagechange();

$stmt = $pdo->prepare("DELETE FROM invoice WHERE Status IN ('unpaid', 'Unsuccessful') AND CAST(time_sell AS UNSIGNED) < :olderThan AND NOT EXISTS (SELECT 1 FROM Payment_report pr WHERE pr.id_order = invoice.id_payment AND pr.payment_Status IN ('Unpaid', 'waiting'))");
$stmt->execute([':olderThan' => time() - 86400]);
$deletedInvoices = $stmt->rowCount();

$stmt = $pdo->prepare("DELETE FROM service_other WHERE status = 'unpaid' AND time < :olderThan AND NOT EXISTS (SELECT 1 FROM Payment_report pr WHERE pr.id_order = service_other.id_payment AND pr.payment_Status IN ('Unpaid', 'waiting'))");
$stmt->execute([':olderThan' => date('Y/m/d H:i:s', time() - 86400)]);
$deletedInvoices += $stmt->rowCount();

$stmt = $pdo->prepare("DELETE FROM logs_api WHERE time < :olderThan");
$stmt->execute([':olderThan' => date('Y/m/d H:i:s', time() - 7 * 86400)]);
$deletedLogs = $stmt->rowCount();

if (($deletedInvoices > 0 || $deletedLogs > 0) && !isTelegramChatIdEmpty($setting['Channel_Report'] ?? '')) {
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => select("topicid", "idreport", "report", "otherreport", "select")['idreport'],
        'text' => sprintf($textbotlang['Admin']['report']['autoCleanup'], $deletedInvoices, $deletedLogs),
        'parse_mode' => "HTML",
    ]);
}
