<?php
require_once __DIR__ . '/inc/config.php';
require_auth();
csrf_check_get();

$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

$allowed_back = ['users.php', 'user.php'];
$rawBack = $_GET['back'] ?? '';
$back = 'users.php'; 
foreach ($allowed_back as $allowed) {
    if (strpos($rawBack, $allowed) === 0) {
        
        $base = explode('?', $rawBack)[0];
        $back = $base . ($id ? "?id=$id" : '');
        break;
    }
}

if ($rawBack === 'users.php') $back = 'users.php';

if (!$id) {
    flash('error', $textbotlang['panel']['userActionInvalidUserId']);
    header('Location: users.php'); exit;
}

$user = db_fetch($pdo, "SELECT id, User_Status FROM user WHERE id = ?", [$id]);
if (!$user) {
    flash('error', $textbotlang['panel']['userActionUserNotFound']);
    header('Location: users.php'); exit;
}

switch ($action) {
    case 'block':
        if ($user['User_Status'] === 'block') {
            flash('warning', $textbotlang['panel']['userActionUserAlreadyBlocked']);
        } else {
            db_query($pdo, "UPDATE user SET User_Status = 'block' WHERE id = ?", [$id]);
            flash('success', sprintf($textbotlang['panel']['userActionUserBlockedSuccess'], $id));
            error_log("Admin {$_SESSION['admin_user']} blocked user $id");
        }
        break;

    case 'unblock':
        if ($user['User_Status'] !== 'block') {
            flash('warning', $textbotlang['panel']['userActionUserIsActive']);
        } else {
            db_query($pdo, "UPDATE user SET User_Status = 'active' WHERE id = ?", [$id]);
            flash('success', sprintf($textbotlang['panel']['userActionUserUnblockedSuccess'], $id));
            error_log("Admin {$_SESSION['admin_user']} unblocked user $id");
        }
        break;

    default:
        flash('error', $textbotlang['panel']['userActionInvalidOperation']);
}

header("Location: $back"); exit;
