<?php

/**
 * MODASR BOT — reseller (نمایندگی) core.
 *
 * Rules enforced here:
 *  - a request is NOT an activation; states are independent
 *    (request: waiting → approved → paid → active | rejected | suspended)
 *  - all money movements are atomic (conditional UPDATE) and journaled in
 *    agent_transaction with a unique ref (idempotency)
 *  - tenant identity always comes from the authenticated session/token,
 *    never from user-supplied ids
 *  - wholesale price is read server-side only; agents can never submit it
 */

/* ----------------------------------------------------------------------- */
/*  Request lifecycle                                                       */
/* ----------------------------------------------------------------------- */

function resellerGetRequest(string $userId): ?array
{
    $row = select("Requestagent", "*", "id", $userId, "select");
    return is_array($row) && !empty($row['id']) ? $row : null;
}

/** Create a request without charging anything. Resubmission is allowed
 *  only after a rejection (the old row is recycled). */
function resellerSubmitRequest(array $user, string $description): bool
{
    global $pdo;
    $existing = resellerGetRequest((string) $user['id']);
    if ($existing !== null) {
        if (!in_array($existing['status'], ['rejected', 'reject'], true)) {
            return false; // one open request per user
        }
        $stmt = $pdo->prepare("UPDATE Requestagent SET status = 'waiting', username = :username, time = :time, Description = :description WHERE id = :id AND status IN ('rejected','reject')");
        $stmt->execute([
            ':username' => (string) ($user['username'] ?? ''),
            ':time' => time(),
            ':description' => $description,
            ':id' => (string) $user['id'],
        ]);
        clearSelectCache('Requestagent');
        return $stmt->rowCount() === 1;
    }
    $stmt = $pdo->prepare("INSERT INTO Requestagent (id, username, time, Description, status, type) VALUES (:id, :username, :time, :description, 'waiting', 'None')");
    return $stmt->execute([
        ':id' => (string) $user['id'],
        ':username' => (string) ($user['username'] ?? ''),
        ':time' => time(),
        ':description' => $description,
    ]);
}

/** Admin approves → user must now pay the activation fee. */
function resellerApproveRequest(string $userId): bool
{
    global $pdo;
    $stmt = $pdo->prepare("UPDATE Requestagent SET status = 'approved' WHERE id = ? AND status = 'waiting'");
    $stmt->execute([$userId]);
    clearSelectCache('Requestagent');
    return $stmt->rowCount() === 1;
}

function resellerRejectRequest(string $userId): bool
{
    global $pdo;
    $stmt = $pdo->prepare("UPDATE Requestagent SET status = 'rejected' WHERE id = ? AND status IN ('waiting','approved')");
    $stmt->execute([$userId]);
    clearSelectCache('Requestagent');
    return $stmt->rowCount() === 1;
}

/** Admin can suspend an active reseller at any time. */
function resellerSuspend(string $userId): bool
{
    global $pdo;
    $stmt = $pdo->prepare("UPDATE Requestagent SET status = 'suspended' WHERE id = ? AND status = 'active'");
    $stmt->execute([$userId]);
    clearSelectCache('Requestagent');
    $stmt = $pdo->prepare("UPDATE botsaz SET status = 'suspended' WHERE id_user = ?");
    $stmt->execute([$userId]);
    return true;
}

/* ----------------------------------------------------------------------- */
/*  Money: journal + atomic balance moves                                   */
/* ----------------------------------------------------------------------- */

/**
 * Append a journal row. Idempotent: the same ref is recorded only once.
 * Returns the inserted row id, or 0 when the ref already existed.
 */
function resellerJournal(string $agentId, string $type, int $amount, string $ref, array $meta = [], ?int $balanceAfter = null): int
{
    global $pdo;
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO agent_transaction (ref, agent_id, type, amount, balance_after, meta, created_at)
         VALUES (:ref, :agent_id, :type, :amount, :balance_after, :meta, :created_at)"
    );
    $stmt->execute([
        ':ref' => $ref,
        ':agent_id' => $agentId,
        ':type' => $type,
        ':amount' => $amount,
        ':balance_after' => $balanceAfter,
        ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ':created_at' => date('Y-m-d H:i:s'),
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Atomically charge an agent's wallet with journaling and idempotency.
 * Returns the new balance, or null when the charge was refused
 * (insufficient funds / over credit limit / duplicate ref).
 */
function resellerChargeWallet(array $agentUser, int $amount, string $type, string $ref, array $meta = []): ?int
{
    global $pdo;
    if ($amount <= 0) {
        return (int) $agentUser['Balance'];
    }
    if (resellerJournalExists($ref)) {
        return null; // duplicate operation
    }
    $agentId = (string) $agentUser['id'];
    // n2 agents may go negative up to their credit limit (maxbuyagent).
    $minBalance = ($agentUser['agent'] ?? 'f') === 'n2' && intval($agentUser['maxbuyagent'] ?? 0) !== 0
        ? -intval($agentUser['maxbuyagent'])
        : 0;

    $pdo->beginTransaction();
    try {
        // Conditional update = atomic balance guard, no race possible.
        $stmt = $pdo->prepare("UPDATE user SET Balance = Balance - ? WHERE id = ? AND Balance - ? >= ?");
        $stmt->execute([$amount, $agentId, $amount, $minBalance]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }
        $newBalance = (int) $pdo->query("SELECT Balance FROM user WHERE id = " . $pdo->quote($agentId))->fetchColumn();
        if (resellerJournal($agentId, $type, -$amount, $ref, $meta, $newBalance) === 0) {
            $pdo->rollBack(); // ref appeared between check and insert
            return null;
        }
        $pdo->commit();
        clearSelectCache('user');
        return $newBalance;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('resellerChargeWallet failed: ' . $e->getMessage());
        return null;
    }
}

/** Credit an agent wallet (charge/refund/adjustment), journaled + idempotent. */
function resellerCreditWallet(string $agentId, int $amount, string $type, string $ref, array $meta = []): ?int
{
    global $pdo;
    if ($amount <= 0 || resellerJournalExists($ref)) {
        return null;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE user SET Balance = Balance + ? WHERE id = ?");
        $stmt->execute([$amount, $agentId]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }
        $newBalance = (int) $pdo->query("SELECT Balance FROM user WHERE id = " . $pdo->quote($agentId))->fetchColumn();
        if (resellerJournal($agentId, $type, $amount, $ref, $meta, $newBalance) === 0) {
            $pdo->rollBack();
            return null;
        }
        $pdo->commit();
        clearSelectCache('user');
        return $newBalance;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('resellerCreditWallet failed: ' . $e->getMessage());
        return null;
    }
}

function resellerJournalExists(string $ref): bool
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT 1 FROM agent_transaction WHERE ref = ? LIMIT 1");
    $stmt->execute([$ref]);
    return (bool) $stmt->fetchColumn();
}

/* ----------------------------------------------------------------------- */
/*  Wallet charge + manual adjustment (Phase E, E-3)                        */
/* ----------------------------------------------------------------------- */

/** Is this user row an agent (wholesale wallet holder)? */
function resellerUserIsAgent($userRow): bool
{
    return is_array($userRow) && in_array($userRow['agent'] ?? 'f', ['n1', 'n2'], true);
}

/**
 * Journal a wallet top-up for an agent (idempotent per payment order).
 * Used when an agent's wholesale wallet is credited through a payment.
 * Returns the journal row id, or 0 when it already existed.
 */
function resellerJournalWalletCharge(string $agentId, int $amount, string $orderId, array $meta = []): int
{
    if ($amount <= 0) {
        return 0;
    }
    return resellerJournal($agentId, 'wallet_charge', $amount, 'wallet_charge_' . $orderId, $meta + ['order' => $orderId]);
}

/**
 * Admin manual adjustment of an agent wallet.
 * Reason AND admin identity are mandatory; the ref must be unique per
 * adjustment (derived from the Payment_report order id by the caller).
 * Positive amounts are journaled as wallet_charge, negative as
 * manual_adjustment. Returns the new balance or null when refused.
 */
function resellerWalletAdjust(string $agentId, int $amount, string $reason, string $adminId, string $ref): ?int
{
    global $pdo;
    if ($amount === 0 || trim($reason) === '' || trim($adminId) === '' || trim($ref) === '') {
        return null;
    }
    $meta = ['reason' => $reason, 'admin' => $adminId];
    if ($amount > 0) {
        return resellerCreditWallet($agentId, $amount, 'wallet_charge', $ref, $meta);
    }
    $stmt = $pdo->prepare("SELECT * FROM user WHERE id = ? LIMIT 1");
    $stmt->execute([$agentId]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($agent === false) {
        return null;
    }
    return resellerChargeWallet($agent, -$amount, 'manual_adjustment', $ref, $meta);
}

/* ----------------------------------------------------------------------- */
/*  Activation fee (one-time, separate from wholesale balance)              */
/* ----------------------------------------------------------------------- */

function resellerActivationFee(): int
{
    $setting = select("setting", "agentreqprice", null, null, "select");
    return max(0, intval($setting['agentreqprice'] ?? 0));
}

/**
 * Pay the activation fee from the user's wallet.
 * Only for approved-but-unpaid requests; idempotent via a fixed ref.
 * Returns: 'paid' | 'already' | 'no_balance' | 'bad_state'
 */
function resellerPayActivationFee(array $user): string
{
    $request = resellerGetRequest((string) $user['id']);
    if ($request === null || in_array($request['status'], ['active', 'accept'], true)) {
        return 'already';
    }
    if ($request['status'] !== 'approved') {
        return 'bad_state';
    }
    $fee = resellerActivationFee();
    if ($fee === 0) {
        return resellerMarkFeePaid((string) $user['id']) ? 'paid' : 'bad_state';
    }
    $ref = 'activation_' . $user['id'];
    if (resellerJournalExists($ref)) {
        return 'already';
    }
    $charged = resellerChargeWallet($user, $fee, 'activation_fee', $ref);
    if ($charged === null) {
        return 'no_balance';
    }
    return resellerMarkFeePaid((string) $user['id']) ? 'paid' : 'bad_state';
}

function resellerMarkFeePaid(string $userId): bool
{
    global $pdo;
    $stmt = $pdo->prepare("UPDATE Requestagent SET status = 'paid' WHERE id = ? AND status = 'approved'");
    $stmt->execute([$userId]);
    clearSelectCache('Requestagent');
    return $stmt->rowCount() === 1;
}

/* ----------------------------------------------------------------------- */
/*  Bot token registration (Telegram in phase D; platform column ready)     */
/* ----------------------------------------------------------------------- */

/**
 * Validate + register a reseller bot. Returns [ok, message_key, botUsername].
 */
function resellerRegisterBotToken(array $ownerUser, string $token, string $platform = 'telegram'): array
{
    global $pdo;
    $token = trim($token);
    if ($platform !== 'telegram') {
        return [false, 'platform_not_ready', ''];
    }
    if (!preg_match('/^\d{6,}:[A-Za-z0-9_-]{30,}$/', $token)) {
        return [false, 'invalid_token', ''];
    }
    $info = json_decode((string) @file_get_contents("https://api.telegram.org/bot$token/getme"), true);
    if (!is_array($info) || empty($info['ok'])) {
        return [false, 'invalid_token', ''];
    }
    if (select("botsaz", "*", "bot_token", $token, "count") != 0) {
        return [false, 'token_exists', ''];
    }
    if (select("botsaz", "*", "id_user", (string) $ownerUser['id'], "count") != 0) {
        return [false, 'already_has_bot', ''];
    }
    return [true, 'ok', (string) ($info['result']['username'] ?? '')];
}

/* ----------------------------------------------------------------------- */
/*  Pricing policy                                                          */
/* ----------------------------------------------------------------------- */

/**
 * Server-side wholesale price for an agent. The agent never submits this.
 * Priority: product.wholesale_price → product.price_product, then the
 * agent's personal discount percent (user.pricediscount) is applied.
 */
function resellerWholesalePrice(array $product, array $agentUser): int
{
    $base = $product['wholesale_price'] !== null && $product['wholesale_price'] !== ''
        ? intval($product['wholesale_price'])
        : intval($product['price_product'] ?? 0);
    $discount = intval($agentUser['pricediscount'] ?? 0);
    if ($discount > 0 && $discount < 100) {
        $base -= (int) floor($base * $discount / 100);
    }
    return max(0, $base);
}

/**
 * Enforce the admin's retail price corridor. NULL bounds = unrestricted.
 */
function resellerRetailPriceAllowed(array $product, int $retailPrice): bool
{
    $min = $product['min_retail_price'] ?? null;
    $max = $product['max_retail_price'] ?? null;
    if ($min !== null && $min !== '' && $retailPrice < intval($min)) {
        return false;
    }
    if ($max !== null && $max !== '' && $retailPrice > intval($max)) {
        return false;
    }
    return true;
}

/* ----------------------------------------------------------------------- */
/*  Tenant isolation                                                        */
/* ----------------------------------------------------------------------- */

/**
 * Resolve the reseller bot row from the *authenticated* context — the bot
 * token the update arrived on — never from user input.
 */
function resellerBotFromToken(string $apiToken): ?array
{
    $row = select("botsaz", "*", "bot_token", $apiToken, "select");
    return is_array($row) && !empty($row['bot_token']) ? $row : null;
}

/** The tenant owner (reseller) of the current bot, from its token. */
function resellerOwnerFromToken(string $apiToken): ?array
{
    $bot = resellerBotFromToken($apiToken);
    if ($bot === null) {
        return null;
    }
    $owner = select("user", "*", "id", $bot['id_user'], "select");
    return is_array($owner) ? $owner : null;
}

/** A bot row must be active to serve customers. */
function resellerBotIsActive(array $bot): bool
{
    return ($bot['status'] ?? 'active') === 'active';
}

/* ----------------------------------------------------------------------- */
/*  Activation + bot provisioning                                           */
/* ----------------------------------------------------------------------- */

/**
 * Final activation after the fee is paid (and, for self-service, after the
 * bot is provisioned). Sets the request to active and flips user.agent to
 * the type the admin chose at approval time.
 */
function resellerActivate(string $userId): bool
{
    global $pdo;
    $request = resellerGetRequest($userId);
    if ($request === null || $request['status'] !== 'paid') {
        return false;
    }
    $type = in_array($request['type'], ['n', 'n2'], true) ? $request['type'] : 'n';
    $stmt = $pdo->prepare("UPDATE Requestagent SET status = 'active' WHERE id = ? AND status = 'paid'");
    $stmt->execute([$userId]);
    clearSelectCache('Requestagent');
    if ($stmt->rowCount() !== 1) {
        return false;
    }
    update("user", "agent", $type, "id", $userId);
    update("user", "expire", null, "id", $userId);
    return true;
}

/**
 * Provision a reseller bot: copy the Default engine, inject the token,
 * register the webhook with a fresh secret and insert the botsaz row.
 * Shared by the admin flow (createbot_) and the self-service flow so both
 * produce identical bots. Returns [ok, errorKey].
 */
function resellerCreateAgentBot(string $ownerId, string $token, string $botUsername, array $extraAdminIds = [], string $platform = 'telegram'): array
{
    global $pdo, $textbotlang;
    if (!function_exists('copyDirectoryContents') || !function_exists('setAgentWebhook')) {
        return [false, 'internal'];
    }
    $destination = getcwd();
    $dirsource = "$destination/vpnbot/{$ownerId}{$botUsername}";
    if (is_dir($dirsource) && !deleteDirectory($dirsource)) {
        error_log('Failed to remove existing bot directory: ' . $dirsource);
    }
    if (!copyDirectoryContents($destination . '/vpnbot/Default', $dirsource)) {
        error_log('Failed to copy default bot files into: ' . $dirsource);
        return [false, 'copy_failed'];
    }
    $contentconfig = file_get_contents($dirsource . "/config.php");
    file_put_contents($dirsource . "/config.php", str_replace('BotTokenNew', $token, $contentconfig));
    $agent_secret = bin2hex(random_bytes(24));
    setAgentWebhook($token, $ownerId, $botUsername, $agent_secret);
    $admin_ids = json_encode(array_values(array_unique(array_merge([$ownerId], array_map('strval', $extraAdminIds)))));
    $datasetting = json_encode(array(
        "minpricetime" => 4000,
        "pricetime" => 4000,
        "minpricevolume" => 4000,
        "pricevolume" => 4000,
        "support_username" => "@support",
        "Channel_Report" => 0,
        "cart_info" => $textbotlang['users']['Balance']['cardInstructionAlt'] ?? '',
        'show_product' => true,
    ));
    $stmt = $pdo->prepare("INSERT INTO botsaz (id_user,bot_token,admin_ids,username,time,setting,hide_panel,webhook_secret) VALUES (:id_user,:bot_token,:admin_ids,:username,:time,:setting,:hide_panel,:webhook_secret)");
    $stmt->bindParam(':id_user', $ownerId, PDO::PARAM_STR);
    $stmt->bindParam(':bot_token', $token, PDO::PARAM_STR);
    $stmt->bindParam(':admin_ids', $admin_ids);
    $stmt->bindParam(':username', $botUsername, PDO::PARAM_STR);
    $time = date('Y/m/d H:i:s');
    $stmt->bindParam(':time', $time, PDO::PARAM_STR);
    $stmt->bindParam(':setting', $datasetting, PDO::PARAM_STR);
    $value = "{}";
    $stmt->bindParam(':hide_panel', $value, PDO::PARAM_STR);
    $stmt->bindParam(':webhook_secret', $agent_secret, PDO::PARAM_STR);
    $stmt->execute();
    return [true, 'ok'];
}

/** Admin toggle: suspend an active reseller bot or resume a suspended one. */
function resellerToggleBotStatus(string $ownerId): ?string
{
    global $pdo;
    $bot = select("botsaz", "*", "id_user", $ownerId, "select");
    if (!is_array($bot) || empty($bot['bot_token'])) {
        return null;
    }
    $current = $bot['status'] ?? 'active';
    $next = $current === 'active' ? 'suspended' : 'active';
    $stmt = $pdo->prepare("UPDATE botsaz SET status = ? WHERE id_user = ?");
    $stmt->execute([$next, $ownerId]);
    clearSelectCache('botsaz');
    return $next;
}

/* ----------------------------------------------------------------------- */
/*  Legacy compatibility (pre-Phase-D requests were charged upfront)        */
/* ----------------------------------------------------------------------- */

/**
 * Timestamp of the Phase-D rollout. Requests created BEFORE it already paid
 * the activation fee at request time (old flow), so approval must activate
 * them directly and rejection must refund them.
 */
function resellerFlowV2Timestamp(): int
{
    $flag = __DIR__ . '/../storage/cache/reseller_flow_v2';
    return is_file($flag) ? intval(file_get_contents($flag)) : 0;
}

function resellerIsLegacyRequest(array $request): bool
{
    $ts = resellerFlowV2Timestamp();
    return $ts > 0 && intval($request['time'] ?? 0) < $ts;
}
