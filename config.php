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
// Active messenger platform: 'telegram' | 'bale' | 'rubika' | 'splus'
// For Bale use the token from @BotFather in Bale; for Rubika use the token
// from the Rubika bot panel; for Soroush Plus use the token from
// splus.ir/botfather. Webhooks for Bale/Rubika/Soroush Plus point to this
// same index.php endpoint with ?platform=... (and the webhook secret).
$platform = '{platform}';
// Multi-platform: tokens of the other messengers when running several
// platforms at once on one installation. The token of the main platform
// above ($APIKEY) can be left in its slot or here; both work.
$PLATFORM_TOKENS = [
    'telegram' => '{telegram_token}',
    'bale'     => '{bale_token}',
    'rubika'   => '{rubika_token}',
    'splus'    => '{splus_token}',
];
// Optional: Telegram bot token used ONLY for the reports group (forum topics).
// When the main platform is Bale/Rubika but you want the classic Telegram
// topic-based report group, create a Telegram bot via @BotFather, put its
// token here, add it to a Telegram supergroup with Topics enabled, then set
// the group id from the bot admin panel (Report group setting).
$REPORTS_BOT_TOKEN = '{reports_token}';
$adminnumber = '{admin_number}';
$domainhosts = '{domain_name}';
$usernamebot = '{username_bot}';

?>
