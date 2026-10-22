<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$pageTitle = $textbotlang['panel']['servicesTitle'];
$pageLede = $textbotlang['panel']['servicesSubtitle'];
$activeNav = 'service_other';

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
  $where[] = "(id_user LIKE ? OR COALESCE(username,'') LIKE ? OR COALESCE(type,'') LIKE ?)";
  $params = ["%$search%", "%$search%", "%$search%"];
}
if ($status !== '') {
  $where[] = "status = ?";
  $params[] = $status;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
  $total = db_count($pdo, "SELECT COUNT(*) FROM service_other $whereSQL", $params);
  $services = db_fetchAll($pdo, "SELECT * FROM service_other $whereSQL ORDER BY id DESC LIMIT $perPage OFFSET $offset", $params);
} catch (Exception $e) {
  $total = 0;
  $services = [];
  error_log('service.php error: ' . $e->getMessage());
}
$totalPages = max(1, (int) ceil($total / $perPage));

$typeMap = [
  'change_location' => $textbotlang['panel']['serviceChangeLocationLabel'],
  'extra_user' => $textbotlang['panel']['serviceExtraVolumeLabel'],
  'extra_time_user' => $textbotlang['panel']['serviceExtraTimeLabel'],
  'extends_not_user' => $textbotlang['panel']['serviceRenewLabel'],
  'extend_user' => $textbotlang['panel']['serviceRenewLabel2'],
  'transfertouser' => $textbotlang['panel']['serviceTransferOrderLabel']
];

$pageTitle = $textbotlang['panel']['servicesHeading'];
$pageLede = $textbotlang['panel']['servicesSubtitle2'];
$activeNav = 'service';
include __DIR__ . '/inc/layout_head.php';
?>

<div class="card fade-up">
  <div class="toolbar">
    <div class="toolbar-title"><?= $textbotlang['panel']['servicesPageHeading'] ?> <small>(<?= number_format($total) ?>)</small></div>
    <form method="GET" id="srvForm" class="toolbar-end">
      <select name="status" class="select" style="width:auto" onchange="document.getElementById('srvForm').submit()">
        <option value=""><?= $textbotlang['panel']['serviceColUser'] ?></option>
        <option value="done" <?= $status === 'done' ? 'selected' : '' ?>><?= $textbotlang['panel']['serviceColType'] ?></option>
        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>><?= $textbotlang['panel']['serviceColService'] ?></option>
        <option value="reject" <?= $status === 'reject' ? 'selected' : '' ?>><?= $textbotlang['panel']['serviceColStatus'] ?></option>
      </select>
      <div class="search-box" style="min-width:240px">
        <?= icon('search', 14) ?>
        <input type="text" name="q" placeholder="<?= htmlspecialchars($textbotlang['panel']['serviceSearchServicePlaceholder']) ?>" value="<?= htmlspecialchars($search) ?>"
          autocomplete="off">
        <button type="button" class="search-clear">✕</button>
        <button type="submit" class="search-btn"><?= $textbotlang['panel']['serviceColDate'] ?></button>
      </div>
      <?php if ($search || $status): ?>
        <a href="service.php" class="btn-link" style="font-size:.78rem"><?= $textbotlang['panel']['serviceColPanel'] ?></a>
      <?php endif; ?>
    </form>
  </div>

  <div class="tbl-wrap">
    <table class="tbl-lg">
      <thead>
        <tr>
          <th>#</th>
          <th><?= $textbotlang['panel']['serviceColProduct'] ?></th>
          <th><?= $textbotlang['panel']['serviceColAmount'] ?></th>
          <th><?= $textbotlang['panel']['serviceDetailTitle'] ?></th>
          <th><?= $textbotlang['panel']['serviceDetailUser'] ?></th>
          <th><?= $textbotlang['panel']['serviceDetailType'] ?></th>
          <th><?= $textbotlang['panel']['serviceDetailService'] ?></th>
          <th><?= $textbotlang['panel']['serviceDetailStatus'] ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($services)): ?>
          <tr>
            <td colspan="8">
              <div class="empty">
                <svg class="ill" viewBox="0 0 180 140" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <rect x="30" y="30" width="120" height="80" rx="10" fill="var(--sf3)" />
                  <rect x="50" y="50" width="40" height="40" rx="6" fill="var(--bds)" />
                  <rect x="100" y="55" width="35" height="8" rx="4" fill="var(--bd)" />
                  <rect x="100" y="70" width="25" height="8" rx="4" fill="var(--bd)" />
                  <rect x="100" y="85" width="30" height="8" rx="4" fill="var(--bd)" />
                  <path d="M60 65 l10 10 l20-20" stroke="var(--ac)" stroke-width="3" stroke-linecap="round" fill="none" />
                </svg>
                <p><?= $search ? $textbotlang['panel']['serviceNoServiceFound'] : $textbotlang['panel']['serviceNoManualServiceYet'] ?></p>
              </div>
            </td>
          </tr>
        <?php else:
          $i = $offset + 1;
          foreach ($services as $s):
            $stMap = [
              'done' => ['tag-ok', $textbotlang['panel']['serviceStatusDone']],
              'pending' => ['tag-warn', $textbotlang['panel']['serviceStatusWaiting']],
              'reject' => ['tag-no', $textbotlang['panel']['serviceStatusRejected']],
            ];
            [$cls, $lbl] = $stMap[$s['status'] ?? ''] ?? ['tag-plain', $s['status'] ?? '—'];
            $typeLabel = $typeMap[$s['type'] ?? ''] ?? ($s['type'] ?? '—');
            ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cm"><?= htmlspecialchars($s['id_user'] ?? '—') ?></td>
              <td>
                <?= !empty($s['username']) ? '<span class="cm" style="color:var(--ac)">@' . htmlspecialchars(trunc($s['username'], 18)) . '</span>' : '<span class="cf">—</span>' ?>
              </td>
              <td style="font-size:.82rem;color:var(--text2)"><?= htmlspecialchars($typeLabel) ?></td>
              <td class="cn" style="font-size:.82rem"><?= htmlspecialchars(trunc($s['value'] ?? '—', 20)) ?></td>
              <td class="cn cs"><?= number_format((int) ($s['price'] ?? 0)) ?> <span class="cf"><?= $textbotlang['panel']['serviceDetailDate'] ?></span></td>
              <td class="cf"><?= safe_date($s['time'] ?? null, 'Y/m/d') ?></td>
              <td><span class="tag <?= $cls ?>"><?= $lbl ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="tbl-foot">
    <span><?= number_format($total) ?> <?= $textbotlang['panel']['serviceDetailPanel'] ?> <?= $page ?> <?= $textbotlang['panel']['serviceCloseBtn'] ?> <?= $totalPages ?></span>
    <div class="pager">
      <?php $qs = fn($p) => '?q=' . urlencode($search) . '&status=' . urlencode($status) . '&page=' . $p; ?>
      <a class="<?= $page <= 1 ? 'dis' : '' ?>" href="<?= $qs(max(1, $page - 1)) ?>">‹</a>
      <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
        <a class="<?= $p === $page ? 'cur' : '' ?>" href="<?= $qs($p) ?>"><?= $p ?></a>
      <?php endfor; ?>
      <a class="<?= $page >= $totalPages ? 'dis' : '' ?>" href="<?= $qs(min($totalPages, $page + 1)) ?>">›</a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>