<?php
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
$request_exec_timeout = null;
$dbhost = '{database_url}';
$dbname = '{database_name}';
$usernamedb = '{username_db}';
$passworddb = '{password_db}';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
];
$dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $usernamedb, $passworddb, $options);
} catch (\PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("error: database connection failed");
}
$APIKEY = '{API_KEY}';
// Active messenger platform: 'telegram' | 'bale' | 'rubika'
// For Bale use the token from @BotFather in Bale; for Rubika use the token
// from the Rubika bot panel. Webhooks for Bale/Rubika are configured in their
// respective bot panels, pointing to this same index.php endpoint.
$platform = '{platform}';
$adminnumber = '{admin_number}';
$domainhosts = '{domain_name}';
$usernamebot = '{username_bot}';

?>
