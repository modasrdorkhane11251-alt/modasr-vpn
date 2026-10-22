<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$role = $_GET['role'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(id LIKE ? OR COALESCE(username,'') LIKE ? OR COALESCE(namecustom,'') LIKE ? OR COALESCE(number,'') LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

if ($status !== '') {
    $where[] = "User_Status = ?";
    $params[] = $status;
}

if ($role !== '') {
    $where[] = "agent = ?";
    $params[] = $role;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $total = db_count($pdo, "SELECT COUNT(*) FROM user $whereSQL", $params);
    $users = db_fetchAll($pdo, "SELECT * FROM user $whereSQL ORDER BY register DESC LIMIT $perPage OFFSET $offset", $params);
} catch (Exception $e) {
    $total = 0;
    $users = [];
    error_log('users.php: ' . $e->getMessage());
}

$totalPages = max(1, (int) ceil($total / $perPage));

$blockedCount = 0;
$agentCount = 0;
$agentAdvCount = 0;

try {
    $blockedCount = db_count($pdo, "SELECT COUNT(*) FROM user WHERE User_Status='block'");
    $agentCount = db_count($pdo, "SELECT COUNT(*) FROM user WHERE agent='n'");
    $agentAdvCount = db_count($pdo, "SELECT COUNT(*) FROM user WHERE agent='n2'");
} catch (Exception $e) {
}

$pageTitle = $textbotlang['panel']['usersTitle'];
$pageLede = $textbotlang['panel']['usersSubtitle'];
$activeNav = 'users';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="card fade-up">
    <div class="toolbar">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <div class="toolbar-title"><?= $textbotlang['panel']['usersHeading'] ?> <small>(<?= number_format($total) ?>)</small></div>

            <?php if ($blockedCount > 0): ?>
                <a href="?status=block" class="tag tag-no" style="cursor:pointer"><?= $blockedCount ?> <?= $textbotlang['panel']['usersColId'] ?></a>
            <?php endif; ?>
            <?php if ($agentCount > 0): ?>
                <a href="?role=n" class="tag tag-info" style="cursor:pointer"><?= $agentCount ?> <?= $textbotlang['panel']['usersColName'] ?></a>
            <?php endif; ?>
            <?php if ($agentAdvCount > 0): ?>
                <a href="?role=n2" class="tag tag-warn" style="cursor:pointer"><?= $agentAdvCount ?> <?= $textbotlang['panel']['usersColUsername'] ?></a>
            <?php endif; ?>
        </div>

        <form method="GET" id="usersForm" class="toolbar-end">
            <select name="status" class="select" style="width:auto"
                onchange="document.getElementById('usersForm').submit()">
                <option value=""><?= $textbotlang['panel']['usersColBalance'] ?></option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>><?= $textbotlang['panel']['usersColGroup'] ?></option>
                <option value="block" <?= $status === 'block' ? 'selected' : '' ?>><?= $textbotlang['panel']['usersColStatus'] ?></option>
            </select>

            <select name="role" class="select" style="width:auto"
                onchange="document.getElementById('usersForm').submit()">
                <option value=""><?= $textbotlang['panel']['usersColActions'] ?></option>
                <option value="f" <?= $role === 'f' ? 'selected' : '' ?>><?= $textbotlang['panel']['usersColJoinDate'] ?></option>
                <option value="n" <?= $role === 'n' ? 'selected' : '' ?>><?= $textbotlang['panel']['usersColPhone'] ?></option>
                <option value="n2" <?= $role === 'n2' ? 'selected' : '' ?>><?= $textbotlang['panel']['usersColCustomName'] ?></option>
            </select>

            <div class="search-box" style="min-width:260px">
                <?= icon('search', 15) ?>
                <input type="text" name="q" placeholder="<?= $textbotlang['panel']['usersSearchUserPlaceholder'] ?>"
                    value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                <button type="button" class="search-clear">✕</button>
                <button type="submit" class="search-btn"><?= $textbotlang['panel']['usersAllGroups'] ?></button>
            </div>

            <?php if ($search || $status || $role): ?>
                <a href="users.php" class="btn-link" style="font-size:.78rem;white-space:nowrap"><?= $textbotlang['panel']['usersAllStatuses'] ?></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="tbl-wrap">
        <table class="tbl-xl">
            <thead>
                <tr>
                    <th style="width:36px">#</th>
                    <th><?= $textbotlang['panel']['usersSearchBtn'] ?></th>
                    <th><?= $textbotlang['panel']['usersClearBtn'] ?></th>
                    <th><?= $textbotlang['panel']['usersGroupFreeUser'] ?></th>
                    <th><?= $textbotlang['panel']['usersGroupNormalAgent'] ?></th>
                    <th><?= $textbotlang['panel']['usersGroupAdvancedAgent'] ?></th>
                    <th><?= $textbotlang['panel']['usersStatusActiveFilter'] ?></th>
                    <th><?= $textbotlang['panel']['usersStatusBlockedFilter'] ?></th>
                    <th><?= $textbotlang['panel']['usersPaginationPrev'] ?></th>
                    <th style="width:72px"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="10">
                            <div class="empty">
                                <svg class="ill" viewBox="0 0 200 160" fill="none">
                                    <circle cx="100" cy="60" r="40" fill="var(--sf3)" />
                                    <circle cx="100" cy="47" r="18" fill="var(--bds)" />
                                    <path d="M62 105 Q100 88 138 105" stroke="var(--bds)" stroke-width="8"
                                        stroke-linecap="round" fill="none" />
                                </svg>
                                <p><?= $search ? $textbotlang['panel']['usersNoResultFound'] : $textbotlang['panel']['usersNoUserYet'] ?></p>
                            </div>
                        </td>
                    </tr>
                <?php else:
                    $i = $offset + 1;
                    foreach ($users as $u):
                        $agent = $u['agent'] ?? 'f';
                        $isBlocked = ($u['User_Status'] ?? '') === 'block';
                        $name = $u['namecustom'] ?? '';
                        if ($name === 'none')
                            $name = '';
                        $uname = $u['username'] ?? '';
                        if ($uname === 'none')
                            $uname = '';
                        ?>
                        <tr>
                            <td class="cf"><?= $i++ ?></td>
                            <td class="cm"><?= htmlspecialchars($u['id']) ?></td>
                            <td>
                                <?php if ($uname): ?>
                                    <span class="cm" style="color:var(--ac)">@<?= htmlspecialchars($uname) ?></span>
                                <?php else: ?>
                                    <span class="cf">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="cs"><?= $name ? htmlspecialchars(trunc($name, 20)) : '<span class="cf">—</span>' ?></td>
                            <td class="cm cf">
                                <?= (!empty($u['number']) && $u['number'] !== 'none') ? htmlspecialchars($u['number']) : '—' ?>
                            </td>
                            <td class="cn cs" style="white-space:nowrap">
                                <?= number_format((int) ($u['Balance'] ?? 0)) ?> <span class="cf"><?= $textbotlang['panel']['usersPaginationNext'] ?></span>
                            </td>
                            <td class="cn">
                                <?= (int) ($u['score'] ?? 0) > 0
                                    ? '<span style="color:var(--warn)">⭐ ' . number_format((int) ($u['score'] ?? 0)) . '</span>'
                                    : '<span class="cf">—</span>' ?>
                            </td>
                            <td class="cf"><?= safe_date($u['register'] ?? null) ?></td>
                            <td>
                                <?php if ($isBlocked): ?>
                                    <span class="tag tag-no"><?= $textbotlang['panel']['usersTotalCountLabel'] ?></span>
                                <?php else: ?>
                                    <span class="tag <?= user_role_tag($agent) ?>">
                                        <?= user_role_label($agent) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:4px">
                                    <a href="user.php?id=<?= (int) $u['id'] ?>" class="btn btn-ghost btn-sm btn-icon"
                                        title="<?= htmlspecialchars($textbotlang['panel']['usersViewBtn']) ?>">
                                        <?= icon('eye', 14) ?>
                                    </a>
                                    <?php if ($isBlocked): ?>
                                        <a href="user_action.php?action=unblock&id=<?= (int) $u['id'] ?>&_csrf=<?= csrf_token() ?>&back=users.php"
                                            class="btn btn-ok btn-sm btn-icon" title="<?= htmlspecialchars($textbotlang['panel']['usersUnblockBtn']) ?>"
                                            data-confirm="<?= htmlspecialchars(sprintf($textbotlang['panel']['usersConfirmUnblockUser'], $name, $u['id'])) ?>">
                                            <?= icon('check', 13) ?>
                                        </a>
                                    <?php else: ?>
                                        <a href="user_action.php?action=block&id=<?= (int) $u['id'] ?>&_csrf=<?= csrf_token() ?>&back=users.php"
                                            class="btn btn-no btn-sm btn-icon" title="<?= htmlspecialchars($textbotlang['panel']['usersBlockBtn']) ?>"
                                            data-confirm="<?= htmlspecialchars(sprintf($textbotlang['panel']['usersConfirmBlockUser'], $name, $u['id'])) ?>">
                                            <?= icon('block', 13) ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="tbl-foot">
        <span><?= number_format($total) ?> <?= $textbotlang['panel']['usersColReferrer'] ?> <?= $page ?> <?= $textbotlang['panel']['usersColAffiliateCount'] ?> <?= $totalPages ?></span>
        <div class="pager">
            <?php
            $qs = fn($p) => '?q=' . urlencode($search)
                . '&status=' . urlencode($status)
                . '&role=' . urlencode($role)
                . '&page=' . $p;
            ?>
            <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                <a class="<?= $p === $page ? 'cur' : '' ?>" href="<?= $qs($p) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="<?= $page >= $totalPages ? 'dis' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
        </div>
    </div>
</div>

<script src="js/users.js"></script>
<?php include __DIR__ . '/inc/layout_foot.php'; ?>