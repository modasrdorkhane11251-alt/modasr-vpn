<?php
/**
 * MODASR account linking — one customer, several messengers, one wallet.
 *
 * Each messenger (telegram / bale / rubika / splus) creates its own `user`
 * row. Account linking ties secondary rows to ONE primary account:
 *
 *   - the wallet (user.Balance) always lives on the PRIMARY account;
 *     on linking, the secondary's balance is merged into the primary with
 *     an audit journal entry and the secondary wallet is zeroed;
 *   - service ownership checks accept every linked id, so a service bought
 *     on telegram can be managed from soroush and vice versa;
 *   - unlinking never moves money back (documented to the user upfront).
 *
 * Safety rules:
 *   - reseller/agent accounts (agent != 'f') can neither link nor be linked;
 *   - no chains: a primary cannot itself be a linked secondary, and an
 *     account that has links cannot become a secondary;
 *   - link codes: 6 digits, one-time, 5-minute TTL, max 3 active per user,
 *     redeem attempts rate-limited (5 failures / 10 minutes);
 *   - every redeem attempt is logged (account_link_attempt).
 *
 * All money movement happens inside one DB transaction with row locks and
 * is idempotent via the unique journal ref (linkmerge_{code}).
 */

require_once __DIR__ . '/../lib/reseller.php';

const ACCOUNT_LINK_CODE_TTL = 300;        // seconds
const ACCOUNT_LINK_MAX_ACTIVE_CODES = 3;
const ACCOUNT_LINK_MAX_FAILURES = 5;      // per window
const ACCOUNT_LINK_FAILURE_WINDOW = 600;  // seconds

/* ---------------------------------------------------------------------
 * Identity resolution
 * ------------------------------------------------------------------- */

/**
 * The primary account id for any platform-specific user id.
 * Returns $userId itself when it is not linked to anything.
 */
function modasr_primary_user_id($userId): string
{
    $userId = (string) $userId;
    if ($userId === '') {
        return $userId;
    }
    $cache = &$GLOBALS['__modasr_link_cache'];
    if (!is_array($cache)) {
        $cache = [];
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    global $pdo;
    $primary = $userId;
    try {
        $stmt = $pdo->prepare("SELECT primary_user_id FROM user_link WHERE linked_user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (string) $row['primary_user_id'] !== '') {
            $primary = (string) $row['primary_user_id'];
        }
    } catch (\Throwable $e) {
        $primary = $userId; // table missing / db hiccup: behave as unlinked
    }
    $cache[$userId] = $primary;
    return $primary;
}

/**
 * Drop the per-request link cache (called after link/unlink mutations).
 */
function modasr_account_link_cache_flush(): void
{
    $GLOBALS['__modasr_link_cache'] = [];
}

/**
 * All user ids belonging to the same account: [primary, ...linked ids].
 * A stand-alone user gets [$userId].
 */
function modasr_linked_user_ids($userId): array
{
    $userId = (string) $userId;
    if ($userId === '') {
        return [];
    }
    global $pdo;
    $primary = modasr_primary_user_id($userId);
    $ids = [$primary];
    try {
        $stmt = $pdo->prepare("SELECT linked_user_id FROM user_link WHERE primary_user_id = ?");
        $stmt->execute([$primary]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $linked) {
            $ids[] = (string) $linked;
        }
    } catch (\Throwable $e) {
        // behave as unlinked
    }
    return array_values(array_unique($ids));
}

/**
 * Overlay the wallet fields of a loaded $user row with the PRIMARY
 * account's values, so every downstream $user['Balance'] read (balance
 * checks, wallet page, purchase flow) automatically sees the shared wallet.
 */
function modasr_apply_primary_wallet(array $user): array
{
    $id = (string) ($user['id'] ?? '');
    if ($id === '') {
        return $user;
    }
    $primary = modasr_primary_user_id($id);
    if ($primary === $id) {
        return $user;
    }
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT Balance FROM user WHERE id = ? LIMIT 1");
        $stmt->execute([$primary]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $user['Balance'] = $row['Balance'];
            $user['linked_primary'] = $primary;
        }
    } catch (\Throwable $e) {
        // keep the row as-is
    }
    return $user;
}

/**
 * All user ids of the account as a quoted, comma-separated SQL fragment
 * ready for IN (…). Values come from our own tables and are quoted with
 * PDO::quote, so this is injection-safe.
 */
function modasr_linked_ids_sql($userId): string
{
    global $pdo;
    $ids = modasr_linked_user_ids($userId);
    if ($ids === []) {
        $ids = [(string) $userId];
    }
    return implode(',', array_map(static function ($id) use ($pdo) {
        return $pdo->quote((string) $id);
    }, $ids));
}

/* ---------------------------------------------------------------------
 * Link codes
 * ------------------------------------------------------------------- */

/**
 * Issue a one-time link code for the account that should become/stay the
 * PRIMARY. Returns [ok, code|errorKey].
 */
function accountLinkIssueCode($primaryUserId): array
{
    global $pdo;
    $primaryUserId = (string) $primaryUserId;

    if (modasr_primary_user_id($primaryUserId) !== $primaryUserId) {
        return [false, 'is_secondary'];
    }
    $user = select("user", "agent", "id", $primaryUserId, "select");
    if (!is_array($user) || ($user['agent'] ?? 'f') !== 'f') {
        return [false, 'agent_blocked'];
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM account_link_code WHERE primary_user_id = ? AND used = 0 AND expires_at > ?");
        $stmt->execute([$primaryUserId, time()]);
        if ((int) $stmt->fetchColumn() >= ACCOUNT_LINK_MAX_ACTIVE_CODES) {
            return [false, 'too_many_codes'];
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("INSERT INTO account_link_code (code, primary_user_id, expires_at, used, created_at) VALUES (?, ?, ?, 0, ?)");
        $stmt->execute([$code, $primaryUserId, time() + ACCOUNT_LINK_CODE_TTL, date('Y-m-d H:i:s')]);
        return [true, $code];
    } catch (\Throwable $e) {
        error_log('accountLinkIssueCode: ' . $e->getMessage());
        return [false, 'error'];
    }
}

/**
 * Whether this user is temporarily blocked from redeeming codes.
 */
function accountLinkRateLimited($userId): bool
{
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM account_link_attempt
             WHERE user_id = ? AND success = 0 AND created_at > ?"
        );
        $stmt->execute([(string) $userId, date('Y-m-d H:i:s', time() - ACCOUNT_LINK_FAILURE_WINDOW)]);
        return (int) $stmt->fetchColumn() >= ACCOUNT_LINK_MAX_FAILURES;
    } catch (\Throwable $e) {
        return false; // fail open on logging-table problems, never on money
    }
}

function accountLinkLogAttempt($userId, string $code, bool $success): void
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO account_link_attempt (user_id, code_tried, success, created_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([(string) $userId, $code, $success ? 1 : 0, date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        error_log('accountLinkLogAttempt: ' . $e->getMessage());
    }
}

/**
 * Redeem a link code: attach $secondaryId (on $secondaryPlatform) to the
 * code owner's primary account and merge the wallets.
 *
 * Returns [ok, errorKey, mergedAmount].
 * errorKey in: invalid_code, expired, rate_limited, self_link,
 *              agent_blocked, already_linked, primary_is_linked,
 *              has_own_links, error
 */
function accountLinkRedeemCode(string $code, $secondaryId, string $secondaryPlatform): array
{
    global $pdo;
    $code = trim($code);
    $secondaryId = (string) $secondaryId;

    if (!preg_match('/^\d{6}$/', $code)) {
        accountLinkLogAttempt($secondaryId, $code, false);
        return [false, 'invalid_code', 0];
    }
    if (accountLinkRateLimited($secondaryId)) {
        return [false, 'rate_limited', 0];
    }

    $pdo->beginTransaction();
    try {
        // Lock the code row.
        $stmt = $pdo->prepare("SELECT * FROM account_link_code WHERE code = ? FOR UPDATE");
        $stmt->execute([$code]);
        $codeRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$codeRow || (int) $codeRow['used'] === 1) {
            throw new \RuntimeException('invalid_code');
        }
        if ((int) $codeRow['expires_at'] < time()) {
            throw new \RuntimeException('expired');
        }
        $primaryId = (string) $codeRow['primary_user_id'];
        if ($primaryId === $secondaryId) {
            throw new \RuntimeException('self_link');
        }

        // Lock BOTH user rows (fixed order to avoid deadlocks).
        $ids = [$primaryId, $secondaryId];
        sort($ids);
        $users = [];
        foreach ($ids as $lockId) {
            $stmt = $pdo->prepare("SELECT id, agent, Balance FROM user WHERE id = ? FOR UPDATE");
            $stmt->execute([$lockId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \RuntimeException('error');
            }
            $users[(string) $row['id']] = $row;
        }
        $primary = $users[$primaryId];
        $secondary = $users[$secondaryId];

        // Agents are excluded from linking on both sides.
        if (($primary['agent'] ?? 'f') !== 'f' || ($secondary['agent'] ?? 'f') !== 'f') {
            throw new \RuntimeException('agent_blocked');
        }
        // No chains: primary must be a root account, secondary must be free.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_link WHERE linked_user_id = ?");
        $stmt->execute([$primaryId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new \RuntimeException('primary_is_linked');
        }
        $stmt->execute([$secondaryId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new \RuntimeException('already_linked');
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_link WHERE primary_user_id = ?");
        $stmt->execute([$secondaryId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new \RuntimeException('has_own_links');
        }

        // Merge the secondary wallet into the primary (audit-journalled,
        // idempotent via the unique ref).
        $merged = (int) $secondary['Balance'];
        if ($merged > 0) {
            $ref = 'linkmerge_' . $code;
            if (!resellerJournalExists($ref)) {
                $stmt = $pdo->prepare("UPDATE user SET Balance = Balance + ? WHERE id = ?");
                $stmt->execute([$merged, $primaryId]);
                $stmt = $pdo->prepare("UPDATE user SET Balance = 0 WHERE id = ?");
                $stmt->execute([$secondaryId]);
                $newBalance = (int) $pdo->query("SELECT Balance FROM user WHERE id = " . $pdo->quote($primaryId))->fetchColumn();
                resellerJournal($primaryId, 'link_merge', $merged, $ref, [
                    'secondary_id' => $secondaryId,
                    'platform' => $secondaryPlatform,
                ], $newBalance);
            }
        }

        // Create the link (UNIQUE(linked_user_id) makes this race-safe).
        $stmt = $pdo->prepare("INSERT INTO user_link (primary_user_id, linked_user_id, platform, linked_via, created_at) VALUES (?, ?, ?, 'code', ?)");
        $stmt->execute([$primaryId, $secondaryId, $secondaryPlatform, date('Y-m-d H:i:s')]);

        $stmt = $pdo->prepare("UPDATE account_link_code SET used = 1, used_by = ? WHERE code = ? AND used = 0");
        $stmt->execute([$secondaryId, $code]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('invalid_code');
        }

        $pdo->commit();
        accountLinkLogAttempt($secondaryId, $code, true);
        clearSelectCache('user');
        modasr_account_link_cache_flush();
        return [true, '', $merged];
    } catch (\RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        accountLinkLogAttempt($secondaryId, $code, false);
        return [false, $e->getMessage(), 0];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('accountLinkRedeemCode: ' . $e->getMessage());
        accountLinkLogAttempt($secondaryId, $code, false);
        return [false, 'error', 0];
    }
}

/* ---------------------------------------------------------------------
 * Listing / unlinking
 * ------------------------------------------------------------------- */

/**
 * Link overview for a user: ['primary' => id, 'is_secondary' => bool,
 * 'links' => [ ['linked_user_id'=>…, 'platform'=>…, 'created_at'=>…], … ]]
 */
function accountLinkList($userId): array
{
    global $pdo;
    $userId = (string) $userId;
    $primary = modasr_primary_user_id($userId);
    $links = [];
    try {
        $stmt = $pdo->prepare("SELECT linked_user_id, platform, created_at FROM user_link WHERE primary_user_id = ? ORDER BY id");
        $stmt->execute([$primary]);
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $links = [];
    }
    return [
        'primary' => $primary,
        'is_secondary' => $primary !== $userId,
        'links' => $links,
    ];
}

/**
 * Remove one link. Allowed from either side (the primary for any of its
 * links, or the secondary for its own link). Money is NOT moved back —
 * the merged balance stays on the primary.
 */
function accountLinkUnlink($requesterId, $linkedUserId): bool
{
    global $pdo;
    $requesterId = (string) $requesterId;
    $linkedUserId = (string) $linkedUserId;
    try {
        $stmt = $pdo->prepare("SELECT primary_user_id FROM user_link WHERE linked_user_id = ? LIMIT 1");
        $stmt->execute([$linkedUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $primaryId = (string) $row['primary_user_id'];
        if ($requesterId !== $linkedUserId && $requesterId !== $primaryId) {
            return false; // only the two parties may unlink
        }
        $stmt = $pdo->prepare("DELETE FROM user_link WHERE linked_user_id = ? LIMIT 1");
        $stmt->execute([$linkedUserId]);
        clearSelectCache('user');
        modasr_account_link_cache_flush();
        return $stmt->rowCount() === 1;
    } catch (\Throwable $e) {
        error_log('accountLinkUnlink: ' . $e->getMessage());
        return false;
    }
}
