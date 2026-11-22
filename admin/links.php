<?php
// Linked accounts: lets one person switch between their user account and their staff/admin account without logging out.
// Resort admins can allow "user account -> staff account" for staff of their own resort.
// Only the super admin can allow "staff/admin account -> user account".
require __DIR__ . '/../includes/layout.php';

$staffMember = require_login($pdo, false, 'admin');
$self = 'links.php';
$isSuper = staff_can($staffMember, 'super');
if ($isSuper) {
    redirect('accounts.php');
}
$RID = $resort ? (int)$resort['resort_id'] : 0;
// Staff this person may link: everyone for the super admin (but not other super admins' resorts logic: they have no resort), own resort otherwise.
$scope = $isSuper ? '1=1' : 's.resort_id = ' . $RID;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $failed = false;
    try {
        if ($action === 'add') {
            $sid = (int)($_POST['staff_id'] ?? 0);
            $email = mb_strtolower(trim((string)($_POST['user_email'] ?? '')));
            $st = $pdo->prepare("SELECT s.staff_id FROM staff s WHERE s.staff_id = ? AND s.archived_at IS NULL AND $scope");
            $st->execute([$sid]);
            if (!$st->fetchColumn()) { throw new RuntimeException('Choose a staff or admin account from the list.'); }
            $g = $pdo->prepare('SELECT guest_id FROM guest WHERE email = ? AND email_verified_at IS NOT NULL AND ' . GUEST_CAN_LOGIN);
            $g->execute([$email]);
            $gid = (int)$g->fetchColumn();
            if (!$gid) { throw new RuntimeException('No active user account with a verified email matches that. They need to create an account (or be restored from Archive) first.'); }
            $pdo->prepare('INSERT INTO account_link (staff_id, guest_id, user_to_staff, staff_to_user, created_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$sid, $gid, isset($_POST['user_to_staff']) ? 1 : 0, ($isSuper && isset($_POST['staff_to_user'])) ? 1 : 0, (int)$staffMember['staff_id']]);
            flash('Accounts linked.');
        } elseif (in_array($action, ['toggle_u2s', 'toggle_s2u', 'unlink'], true)) {
            $lid = (int)($_POST['link_id'] ?? 0);
            if ($action === 'toggle_s2u' && !$isSuper) { throw new RuntimeException('Only the super admin can allow switching from a staff account to a user account.'); }
            $where = "link_id = ? AND staff_id IN (SELECT s.staff_id FROM staff s WHERE $scope)";
            if ($action === 'unlink') {
                $pdo->prepare("DELETE FROM account_link WHERE $where")->execute([$lid]);
                flash('Link removed. Both accounts are kept.');
            } else {
                $col = $action === 'toggle_u2s' ? 'user_to_staff' : 'staff_to_user';
                $pdo->prepare("UPDATE account_link SET $col = 1 - $col WHERE $where")->execute([$lid]);
                flash('Switching updated.');
            }
        }
    } catch (PDOException $e) {   // before RuntimeException, which it is a kind of
        flash((int)($e->errorInfo[1] ?? 0) === 1062
            ? 'One of those accounts is already linked. Remove its link first.'
            : 'The database rejected that change.', 'error');
        $failed = true;
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        $failed = true;
    }
    if ($failed && $action === 'add') {
        $_SESSION['crud_old'][$self] = ['id' => 0, 'values' => ['staff_id' => (string)($_POST['staff_id'] ?? ''), 'user_email' => (string)($_POST['user_email'] ?? '')]];
    }
    redirect($self);
}

$q = trim((string)($_GET['q'] ?? ''));
$rows = $pdo->query(
    "SELECT l.link_id, l.user_to_staff, l.staff_to_user, l.created_at,
            s.full_name, s.email AS staff_email, s.archived_at AS s_arch, s.login_enabled AS s_on, s.resort_id,
            CASE WHEN s.resort_id IS NULL THEN 'Super admin' WHEN s.access = 'Admin' THEN 'Resort admin' ELSE 'Staff' END AS level,
            COALESCE(r.name, 'All resorts') AS resort_name,
            CONCAT(g.first_name, ' ', g.last_name) AS user_name, g.email AS user_email, g.archived_at AS g_arch, g.login_enabled AS g_on
       FROM account_link l
       JOIN staff s ON s.staff_id = l.staff_id LEFT JOIN resort r ON r.resort_id = s.resort_id
       JOIN guest g ON g.guest_id = l.guest_id
      WHERE $scope ORDER BY s.full_name"
)->fetchAll();
if ($q !== '') {
    $rows = array_values(array_filter($rows, fn($r) => stripos($r['full_name'] . ' ' . $r['staff_email'] . ' ' . $r['user_name'] . ' ' . $r['user_email'] . ' ' . $r['resort_name'], $q) !== false));
}
$free = $pdo->query(
    "SELECT s.staff_id, CONCAT(s.full_name, ' (', s.email, ')', IF(s.resort_id IS NULL, ' · super admin', '')) FROM staff s
      WHERE $scope AND s.archived_at IS NULL AND s.staff_id NOT IN (SELECT staff_id FROM account_link) ORDER BY s.full_name"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$autoOpen = $_SESSION['crud_old'][$self] ?? null;
unset($_SESSION['crud_old'][$self]);

admin_start('Linked accounts', 'links');
admin_head('Linked accounts', '',
    '<button type="button" class="btn primary" data-form-open="link-modal">Link accounts</button>');
?>
<form method="get" class="filters" role="search">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search linked accounts" aria-label="Search linked accounts" maxlength="80" autocomplete="off">
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== ''): ?><a class="btn" href="<?= h($self) ?>">Clear</a><?php endif; ?>
</form>

<section class="panel flush">
  <?php if (!$rows): ?>
    <p class="empty"><?= $q !== '' ? 'No linked accounts match “' . h($q) . '”.' : 'No accounts are linked yet. <button type="button" class="link-btn" data-form-open="link-modal">Link the first pair</button>.' ?></p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead><tr>
        <th>Staff account</th><th>User account</th><th>User &rarr; staff</th><th>Staff &rarr; user</th><th class="right">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $note = [];
          if ($r['s_arch'] !== null) { $note[] = 'staff account archived'; } elseif (!$r['s_on']) { $note[] = 'staff login off'; }
          if ($r['g_arch'] !== null) { $note[] = 'user account archived'; } elseif (!$r['g_on']) { $note[] = 'user login off'; } ?>
        <tr>
          <td class="col-primary"><strong><?= h($r['full_name']) ?></strong><small class="muted-line"><?= h($r['staff_email']) ?> · <?= h($r['level']) ?> · <?= h($r['resort_name']) ?></small></td>
          <td class="col-primary"><strong><?= h($r['user_name']) ?></strong><small class="muted-line"><?= h($r['user_email']) ?><?= $note ? ' · ' . h(implode(', ', $note)) : '' ?></small></td>
          <?php foreach ([['toggle_u2s', 'user_to_staff', true], ['toggle_s2u', 'staff_to_user', $isSuper]] as [$act, $col, $canEdit]): ?>
          <td class="nowrap">
            <form method="post" class="switch-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= h($act) ?>">
              <input type="hidden" name="link_id" value="<?= (int)$r['link_id'] ?>">
              <button type="submit" class="switch" role="switch" aria-checked="<?= $r[$col] ? 'true' : 'false' ?>"
                      aria-label="<?= $col === 'user_to_staff' ? 'Let the user switch to the staff account' : 'Let the staff account switch to the user account' ?>"
                      <?= $canEdit ? '' : 'disabled title="Only the super admin can change this"' ?>><span class="knob"></span></button>
              <span class="switch-label"><?= $r[$col] ? 'Allowed' : 'Off' ?></span>
            </form>
          </td>
          <?php endforeach; ?>
          <td class="row-actions">
            <div class="act-group">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="unlink">
                <input type="hidden" name="link_id" value="<?= (int)$r['link_id'] ?>">
                <button class="btn sm danger" type="submit" data-confirm="Remove this link? Both accounts are kept, but they can no longer switch between them." data-confirm-title="Remove link" data-confirm-ok="Yes, remove it" data-confirm-tone="danger">Unlink</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<div class="modal" id="link-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="link-title"
     data-title-add="Link accounts" data-title-edit="Link accounts"
     <?= $autoOpen ? 'data-autoopen="' . h(json_encode($autoOpen, JSON_UNESCAPED_UNICODE)) . '"' : '' ?>>
  <div class="modal-card form-card">
    <div class="modal-head">
      <h2 id="link-title">Link accounts</h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="post" class="grid-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <label>
        <span>Staff account</span>
        <select name="staff_id" required>
          <option value="">Choose an account</option>
          <?php foreach ($free as $id => $label): ?><option value="<?= (int)$id ?>"><?= h($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>
        <span>User account email</span>
        <input type="email" name="user_email" placeholder="e.g. maria@example.com" required autocomplete="off">
      </label>
      <label class="check wide"><input type="checkbox" name="user_to_staff" value="1" checked> <span>Let the user account switch to the staff account</span></label>
      <?php if ($isSuper): ?>
      <label class="check wide"><input type="checkbox" name="staff_to_user" value="1"> <span>Let the staff account switch to the user account</span></label>
      <?php endif; ?>
      <div class="modal-actions wide">
        <button class="btn" type="button" data-modal-close>Cancel</button>
        <button class="btn primary" type="submit">Link accounts</button>
      </div>
    </form>
  </div>
</div>
<script type="application/json" id="crud-records"><?= json_encode(new stdClass) ?></script>
<?php admin_end();
