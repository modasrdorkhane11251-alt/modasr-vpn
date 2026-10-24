<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/utils.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Tehran');
ini_set('default_charset', 'UTF-8');
ini_set('error_log', 'error_log');

function respond_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) !== 'HTTP_') {
                continue;
            }

            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$headerName] = $value;
        }

        return $headers;
    }
}

$rawInput = file_get_contents('php://input');

if ($rawInput === false) {
    respond_json(400, [
        'status' => false,
        'msg' => 'Failed to read request body',
        'token' => null,
    ]);
}

$rawInput = trim($rawInput);
$decodedJson = null;
if ($rawInput !== '') {
    $decodedJson = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $decodedJson = null;
    }
}

$headers = getallheaders();
$candidates = [];

$headerKeys = [
    'X-Telegram-Init-Data',
    'X-Telegram-Web-App-Init-Data',
    'Telegram-Init-Data',
];

foreach ($headerKeys as $headerKey) {
    $value = headerValue($headers, $headerKey);
    if ($value !== null && trim($value) !== '') {
        $candidates[] = trim($value);
    }
}

foreach ([
    $_POST['initData'] ?? null,
    $_POST['init_data'] ?? null,
    $_POST['initDataUnsafe'] ?? null,
    $_POST['init_data_unsafe'] ?? null,
    $_GET['initData'] ?? null,
    $_GET['init_data'] ?? null,
] as $value) {
    if ($value === null) {
        continue;
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            continue;
        }
    }

    $candidates[] = $value;
}

if ($decodedJson !== null) {
    foreach ([
        $decodedJson,
        $decodedJson['initData'] ?? null,
        $decodedJson['init_data'] ?? null,
        $decodedJson['initDataUnsafe'] ?? null,
        $decodedJson['init_data_unsafe'] ?? null,
    ] as $value) {
        if ($value === null) {
            continue;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
        }

        $candidates[] = $value;
    }
} elseif ($rawInput !== '') {
    $candidates[] = $rawInput;
}

if ($candidates === []) {
    respond_json(400, [
        'status' => false,
        'msg' => 'Telegram init data is missing or invalid',
        'token' => null,
    ]);
}

// Multi-platform: the same initData scheme is used by telegram, bale and
// splus webviews — each signs with ITS OWN bot token. Try every configured
// platform token; the one whose signature matches tells us the platform.
$identified = null;
$sawSignatureFailure = false;
foreach ($candidates as $candidate) {
    try {
        $identified = miniapp_identify_platform($candidate);
    } catch (Throwable $e) {
        $identified = null;
    }
    if ($identified !== null) {
        break;
    }
    if (is_string($candidate) && strpos($candidate, 'hash=') !== false) {
        $sawSignatureFailure = true;
    }
}

if ($identified === null) {
    respond_json($sawSignatureFailure ? 403 : 400, [
        'status' => false,
        'msg' => $sawSignatureFailure ? 'User verification failed' : 'Telegram init data is missing or invalid',
        'token' => null,
    ]);
}

$platform = $identified['platform'];
$userData = $identified['user'];
$userId = (string) $userData['id'];

$userRecord = select('user', '*', 'id', $userId, 'select');

if (!$userRecord) {
    respond_json(404, [
        'status' => false,
        'msg' => 'User not found',
        'token' => null,
    ]);
}

// The messenger id must belong to an account of the SAME platform the
// signature came from — otherwise a numeric id collision across platforms
// would let one platform's login open another platform's account.
$recordPlatform = isset($userRecord['platform']) ? (string) $userRecord['platform'] : '';
if ($recordPlatform !== '' && $recordPlatform !== $platform) {
    error_log("miniapp verify: platform mismatch for user (signed=$platform, stored=$recordPlatform)");
    respond_json(403, [
        'status' => false,
        'msg' => 'User verification failed',
        'token' => null,
    ]);
}

// Session is issued on the PRIMARY account (account linking), so the
// mini-app always shows the shared wallet/services.
try {
    $sessionToken = miniapp_issue_session($userId, $platform);
} catch (Throwable $exception) {
    error_log('Failed to generate session token: ' . $exception->getMessage());
    respond_json(500, [
        'status' => false,
        'msg' => 'Failed to generate session token',
        'token' => null,
    ]);
}

respond_json(200, [
    'status' => true,
    'msg' => 'User verified',
    'token' => $sessionToken,
]);
