<?php
// Users: guests who created an online account (a guest row with a password). Super admin only.
// Staff sign-ins are on accounts.php. Archiving an account (or switching its login off) keeps the guest and their bookings.
require __DIR__ . '/../includes/layout.php';

$staffMember = require_login($pdo, true);
$self = 'users.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $gid    = (int)($_POST['guest_id'] ?? 0);
    $failed = false;
    try {
        $stmt = $pdo->prepare('SELECT guest_id FROM guest WHERE guest_id = ? AND password_hash IS NOT NULL');
        $stmt->execute([$gid]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('That account no longer exists.');
        }
        if ($action === 'save') {
            $live = $pdo->prepare('SELECT 1 FROM guest WHERE guest_id = ? AND archived_at IS NULL');
            $live->execute([$gid]);
            if (!$live->fetchColumn()) { throw new RuntimeException('Restore this account before editing it.'); }
            $first  = trim((string)($_POST['first_name'] ?? ''));
            $middle = trim((string)($_POST['middle_name'] ?? ''));
            $last   = trim((string)($_POST['last_name'] ?? ''));
            $email  = trim((string)($_POST['email'] ?? ''));
            $phone  = trim((string)($_POST['phone'] ?? ''));
            if ($first === '' || $last === '' || mb_strlen($first) > 60 || mb_strlen($last) > 60 || mb_strlen($middle) > 60) {
                throw new RuntimeException('First and last name are required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid email address.');
            }
            // Changing the email means it is no longer the one they proved with a code.
            $pdo->prepare(
                'UPDATE guest SET first_name = ?, middle_name = ?, last_name = ?, phone = ?,
                        email_verified_at = IF(email = ?, email_verified_at, NULL), email = ?
                  WHERE guest_id = ?'
            )->execute([$first, $middle === '' ? null : $middle, $last, $phone === '' ? null : $phone, $email, $email, $gid]);
            flash('Changes saved.');
        } elseif ($action === 'archive') {
            $pdo->prepare('UPDATE guest SET archived_at = NOW() WHERE guest_id = ? AND archived_at IS NULL')->execute([$gid]);
            flash('Account archived. The guest record and bookings are kept; restore it from Archive.');
        } elseif ($action === 'toggle_login') {
            $pdo->prepare('UPDATE guest SET login_enabled = 1 - login_enabled WHERE guest_id = ? AND archived_at IS NULL')->execute([$gid]);
            flash('Login access updated.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (PDOException $e) {
        flash((int)($e->errorInfo[1] ?? 0) === 1062 ? 'That email is already used by another guest.' : 'The database rejected that change.', 'error');
        $failed = true;
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        $failed = true;
    }
    if ($failed && $action === 'save') {   // reopen the pop-up with what was typed
        $_SESSION['crud_old'][$self] = ['id' => $gid, 'values' => [
            'first_name'  => (string)($_POST['first_name'] ?? ''),
            'middle_name' => (string)($_POST['middle_name'] ?? ''),
            'last_name'   => (string)($_POST['last_name'] ?? ''),
            'email'       => (string)($_POST['email'] ?? ''),
            'phone'       => (string)($_POST['phone'] ?? ''),
        ]];
    }
    redirect($self);
}

$q = trim((string)($_GET['q'] ?? ''));
$where  = 'g.password_hash IS NOT NULL AND g.archived_at IS NULL';
$params = [];
if ($q !== '') {
    $where .= " AND (CONCAT_WS(' ', g.first_name, g.middle_name, g.last_name) LIKE ? OR g.email LIKE ?)";
    $params = ["%$q%", "%$q%"];
}
$stmt = $pdo->prepare(
    "SELECT g.guest_id, g.first_name, g.middle_name, g.last_name, g.email, g.phone, g.email_verified_at, g.created_at, g.login_enabled, g.archived_at,
            (SELECT COUNT(*) FROM reservation v WHERE v.guest_id = g.guest_id) AS bookings
       FROM guest g WHERE $where ORDER BY g.created_at DESC, g.guest_id DESC LIMIT 500"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$records = [];
foreach ($rows as $r) {
    $records[(int)$r['guest_id']] = [
        'first_name'  => (string)$r['first_name'],
        'middle_name' => (string)($r['middle_name'] ?? ''),
        'last_name'   => (string)$r['last_name'],
        'email'       => (string)$r['email'],
        'phone'       => (string)($r['phone'] ?? ''),
    ];
}
$autoOpen = $_SESSION['crud_old'][$self] ?? null;
unset($_SESSION['crud_old'][$self]);

admin_start('Users', 'users');
admin_head('Users', $q !== '' ? plural(count($rows), 'user') . ' match' . (count($rows) === 1 ? 'es' : '') : '');
$qs = $q !== '' ? 'q=' . urlencode($q) : '';
?>
<form method="get" class="filters" role="search">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search name or email" aria-label="Search users">
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== ''): ?><a class="btn" href="<?= h($self) ?>">Clear</a><?php endif; ?>
</form>

<section class="panel flush">
  <?php if (!$rows): ?>
    <p class="empty"><?= $q !== '' ? 'No users match.' : 'No guest has created an account yet.' ?></p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Email verified</th><th class="num">Bookings</th><th>Joined</th><th>Login</th><th class="right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h(format_name_inv((string)$r['last_name'], (string)$r['first_name'], (string)($r['middle_name'] ?? ''))) ?></strong></td>
          <td><?= h($r['email']) ?></td>
          <td class="nowrap sub"><?= h((string)($r['phone'] ?? '')) !== '' ? h((string)$r['phone']) : '<span class="muted">–</span>' ?></td>
          <td class="nowrap"><span class="badge <?= $r['email_verified_at'] ? 'yes' : 'no' ?>"><?= $r['email_verified_at'] ? 'Yes' : 'No' ?></span></td>
          <td class="num"><?= (int)$r['bookings'] ?></td>
          <td class="nowrap"><?= h(fdate(substr((string)$r['created_at'], 0, 10))) ?></td>
          <td class="nowrap">
            <form method="post" class="switch-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_login">
              <input type="hidden" name="guest_id" value="<?= (int)$r['guest_id'] ?>">
              <button type="submit" class="switch" role="switch" aria-checked="<?= $r['login_enabled'] ? 'true' : 'false' ?>" aria-label="Login access for <?= h($r['first_name'] . ' ' . $r['last_name']) ?>"
                      <?= $r['login_enabled'] ? 'data-confirm="Turn off login for this user? They are signed out straight away and can’t log in until you turn it back on." data-confirm-title="Turn off login" data-confirm-ok="Yes, turn it off" data-confirm-tone="danger"' : '' ?>><span class="knob"></span></button>
              <span class="switch-label"><?= $r['login_enabled'] ? 'On' : 'Off' ?></span>
            </form>
          </td>
          <td class="row-actions">
            <div class="act-group">
              <a class="btn sm" href="<?= h($self) ?>" data-form-open="user-modal" data-id="<?= (int)$r['guest_id'] ?>">Edit</a>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="guest_id" value="<?= (int)$r['guest_id'] ?>">
                <button class="btn sm danger" type="submit" data-confirm="Archive this account? They can no longer sign in, but their details and bookings are kept, and it moves to Archive, where you can restore it." data-confirm-title="Archive account" data-confirm-ok="Yes, archive" data-confirm-tone="danger">Archive</button>
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

<div class="modal" id="user-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="user-title"
     data-title-add="Edit user" data-title-edit="Edit user"
     <?= $autoOpen ? 'data-autoopen="' . h(json_encode($autoOpen, JSON_UNESCAPED_UNICODE)) . '"' : '' ?>>
  <div class="modal-card form-card">
    <div class="modal-head"><h2 id="user-title">Edit user</h2><button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button></div>
    <form method="post" class="grid-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="guest_id" value="" data-record-id>
      <label><span>Last name</span><input type="text" name="last_name" placeholder="e.g. Santos" required></label>
      <label><span>First name</span><input type="text" name="first_name" placeholder="e.g. Maria" required></label>
      <label><span>Middle name <small class="muted">(optional)</small></span><input type="text" name="middle_name" placeholder="e.g. Cruz"></label>
      <label><span>Phone</span><input type="text" name="phone" placeholder="e.g. 0917 123 4567"></label>
      <label class="wide"><span>Email</span><input type="email" name="email" placeholder="e.g. maria@example.com" required></label>
      <div class="modal-actions wide"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save user</button></div>
    </form>
  </div>
</div>
<script type="application/json" id="crud-records"><?= json_encode($records, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT) ?></script>
<?php admin_end();
