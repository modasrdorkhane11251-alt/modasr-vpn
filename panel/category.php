<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
  csrf_check_post();
  $remark = trim($_POST['remark'] ?? '');
  if ($remark === '') {
    flash('error', $textbotlang['panel']['categoryNameRequired']);
    header('Location: category.php');
    exit;
  }
  if (db_count($pdo, "SELECT COUNT(*) FROM category WHERE remark = ?", [$remark])) {
    flash('error', $textbotlang['panel']['categoryNameExists']);
    header('Location: category.php');
    exit;
  }
  try {
    db_query($pdo, "INSERT INTO category (remark) VALUES (?)", [$remark]);
    db_query($pdo, "UPDATE category SET display_order = id WHERE id = ?", [$pdo->lastInsertId()]);
    flash('success', $textbotlang['panel']['categoryAdded']);
  } catch (Exception $e) {
    flash('error', $textbotlang['panel']['productDbError'] . $e->getMessage());
  }
  header('Location: category.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
  csrf_check_post();
  $cid = (int) ($_POST['edit_id'] ?? 0);
  $remark = trim($_POST['remark'] ?? '');
  if ($cid && $remark !== '') {
    try {
      $pdo->beginTransaction();
      db_query($pdo, "UPDATE category SET remark=? WHERE id=?", [$remark, $cid]);
      if (isset($_POST['position'])) {
        move_display_order($pdo, 'category', $cid, (int) $_POST['position']);
      }
      $pdo->commit();
      flash('success', $textbotlang['panel']['categoryEdited']);
    } catch (Exception $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      flash('error', $textbotlang['panel']['productErrorPrefix'] . $e->getMessage());
    }
  }
  header('Location: category.php');
  exit;
}

if (isset($_GET['delete'])) {
  csrf_check_get();
  db_query($pdo, "DELETE FROM category WHERE id = ?", [(int) $_GET['delete']]);
  flash('success', $textbotlang['panel']['categoryDeleted']);
  header('Location: category.php');
  exit;
}

$categories = db_fetchAll($pdo, "SELECT * FROM category ORDER BY display_order, id");

$pageTitle = $textbotlang['panel']['categoryPageTitle'];
$pageLede = $textbotlang['panel']['categoryPageLede'];
$activeNav = 'category';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px" class="fade-up">
  <div style="font-size:.85rem;color:var(--mute)"><?= count($categories) ?> <?= $textbotlang['panel']['categoryCount'] ?></div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 14) ?> <?= $textbotlang['panel']['categoryCreateBtn'] ?></button>
</div>

<div class="card fade-up d1">
  <?php if (empty($categories)): ?>
    <div class="empty" style="padding:60px 20px">
      <svg class="ill" viewBox="0 0 200 160" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="40" y="30" width="120" height="100" rx="12" fill="var(--surface-3)" />
        <rect x="56" y="50" width="88" height="12" rx="6" fill="var(--border-strong)" />
        <rect x="56" y="72" width="60" height="8" rx="4" fill="var(--border)" />
        <rect x="56" y="90" width="72" height="8" rx="4" fill="var(--border)" />
        <rect x="56" y="108" width="44" height="8" rx="4" fill="var(--border)" />
        <circle cx="155" cy="125" r="22" fill="var(--accent-s)" stroke="var(--accent)" stroke-width="2" />
        <path d="M147 125h16M155 117v16" stroke="var(--accent)" stroke-width="2.5" stroke-linecap="round" />
      </svg>
      <p><?= $textbotlang['panel']['categoryEmpty'] ?></p>
      <button class="btn btn-primary" style="margin-top:14px" onclick="openModal('addModal')"><?= icon('plus', 14) ?>
        <?= $textbotlang['panel']['categoryCreateBtn'] ?></button>
    </div>
  <?php else: ?>
    <div class="toolbar">
      <div class="toolbar-title"><?= $textbotlang['panel']['categoryPageTitle'] ?> <small>(<?= count($categories) ?>)</small></div>
      <div class="search-box" style="min-width:220px">
        <?= icon('search', 14) ?>
        <input type="text" placeholder="<?= htmlspecialchars($textbotlang['panel']['categorySearchPlaceholder']) ?>" data-filter="catTbl">
        <button type="button" class="search-clear">✕</button>
      </div>
    </div>
    <div class="tbl-wrap">
      <table id="catTbl" class="tbl-xl">
        <thead>
          <tr>
            <th style="width:70px">#</th>
            <th><?= $textbotlang['panel']['categoryColName'] ?></th>
            <th style="width:110px;text-align:left"><?= $textbotlang['panel']['categoryColActions'] ?></th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1;
          foreach ($categories as $c): ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cs"><?= htmlspecialchars($c['remark'] ?? '') ?></td>
              <td>
                <div style="display:flex;gap:5px;justify-content:flex-end">
                  <button class="btn btn-ghost btn-sm btn-icon" title="<?= htmlspecialchars($textbotlang['panel']['productEditBtn']) ?>"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($c + ['position' => $i - 1]), ENT_QUOTES) ?>)">
                    <?= icon('edit', 13) ?>
                  </button>
                  <a href="category.php?delete=<?= (int) $c['id'] ?>&_csrf=<?= csrf_token() ?>"
                    class="btn btn-no btn-sm btn-icon" title="<?= htmlspecialchars($textbotlang['panel']['productDeleteBtn']) ?>"
                    data-confirm="<?= htmlspecialchars($textbotlang['panel']['categoryDeleteConfirm']) ?>">
                    <?= icon('trash', 13) ?>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="modal-veil" id="addModal">
  <div class="modal">
    <div class="modal-head">
      <h3><?= $textbotlang['panel']['categoryCreateTitle'] ?></h3>
      <button class="modal-x" onclick="closeModal('addModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
          <div class="field full">
            <label><?= $textbotlang['panel']['categoryNameLabel'] ?></label>
            <input type="text" name="remark" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['categoryNamePlaceholder']) ?>" required>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> <?= $textbotlang['panel']['categorySaveBtn'] ?></button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')"><?= $textbotlang['panel']['categoryCancelBtn'] ?></button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="editModal">
  <div class="modal">
    <div class="modal-head">
      <h3><?= $textbotlang['panel']['categoryEditTitle'] ?></h3>
      <button class="modal-x" onclick="closeModal('editModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="edit_id" id="edit_id">
        <div class="form-grid">
          <div class="field full">
            <label><?= $textbotlang['panel']['categoryNameLabel'] ?></label>
            <input type="text" name="remark" id="edit_remark" class="input" required>
          </div>
          <div class="field full">
            <label><?= $textbotlang['panel']['categoryFieldPosition'] ?></label>
            <input type="number" name="position" id="edit_position" class="input" min="1" max="<?= count($categories) ?>" required>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> <?= $textbotlang['panel']['categorySaveChangeBtn'] ?></button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')"><?= $textbotlang['panel']['categoryCancelBtn'] ?></button>
      </div>
    </form>
  </div>
</div>

<script>
window.openEditModal = function(c) {
  document.getElementById('edit_id').value = c.id || '';
  document.getElementById('edit_remark').value = c.remark || '';
  document.getElementById('edit_position').value = c.position || '';
  openModal('editModal');
};
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
