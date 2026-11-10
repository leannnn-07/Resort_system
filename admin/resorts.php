<?php
require __DIR__ . '/../includes/resort_admin.php';

$staffMember = require_login($pdo, true);   // super admin only

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = $_POST;
    try {
        $d = resort_read_post($_POST);
        $mName = trim((string)($_POST['mgr_name'] ?? ''));
        $mEmail = strtolower(trim((string)($_POST['mgr_email'] ?? '')));
        $mPass = (string)($_POST['mgr_password'] ?? '');
        if ($mName !== '' || $mEmail !== '' || $mPass !== '') {
            if ($mName === '' || !filter_var($mEmail, FILTER_VALIDATE_EMAIL) || strlen($mPass) < 8) {
                throw new RuntimeException('For a resort admin account give a name, a valid email and a password of at least 8 characters.');
            }
        }
        $slug = unique_slug($pdo, (string)$d['name']);
        $cover = resort_save_cover($slug);
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO resort (slug, name, tagline, description, location, theme, hero_image, contact_email, contact_phone,
                                           checkin_time, checkout_time, free_cancel_days, exclusive_rate, is_active, sort_order)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(r.sort_order), 0) + 1 FROM resort r))')
            ->execute([$slug, $d['name'], $d['tagline'], $d['description'], $d['location'], $d['theme'], $cover, $d['contact_email'],
                       $d['contact_phone'], $d['checkin_time'], $d['checkout_time'], $d['free_cancel_days'], $d['exclusive_rate'], $d['is_active']]);
        $newId = (int)$pdo->lastInsertId();
        if ($mEmail !== '') {
            $pdo->prepare('INSERT INTO staff (resort_id, full_name, role, access, email, password_hash) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$newId, $mName, 'Resort admin', 'Admin', $mEmail, password_hash($mPass, PASSWORD_DEFAULT)]);
        }
        $pdo->commit();
        $_SESSION['admin_resort'] = $newId;
        flash('Resort added. Now add its buildings, rooms, pools and prices.');
        redirect('settings.php');
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash(($e->errorInfo[1] ?? 0) === 1062 ? 'That email is already used by another staff account.' : 'The database rejected that change.', 'error');
    }
}

$rows = $pdo->query(
    "SELECT r.*,
            (SELECT COUNT(*) FROM room x WHERE x.resort_id = r.resort_id) AS rooms,
            (SELECT COUNT(*) FROM pool p WHERE p.resort_id = r.resort_id) AS pools,
            (SELECT COUNT(*) FROM building b WHERE b.resort_id = r.resort_id) AS buildings,
            (SELECT COUNT(*) FROM reservation v WHERE v.resort_id = r.resort_id AND v.status NOT IN ('Cancelled','Checked-out')) AS open_bookings
       FROM resort r ORDER BY r.sort_order, r.name"
)->fetchAll();

admin_start('All resorts', 'resorts');
?>
<?php admin_head('All resorts', plural(count($rows), 'resort') . ' on the platform'); ?>

<section class="panel flush">
  <div class="table-scroll">
    <table>
      <thead><tr><th>Resort</th><th>Location</th><th class="num">Buildings</th><th class="num">Rooms</th><th class="num">Pools</th><th class="num" title="Pending, confirmed and checked-in bookings">Bookings</th><th>Status</th><th class="right"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['name']) ?></strong><small class="muted-line"><?= h($r['slug']) ?></small></td>
          <td class="wrap"><?= h($r['location']) ?></td>
          <td class="num"><?= (int)$r['buildings'] ?></td><td class="num"><?= (int)$r['rooms'] ?></td><td class="num"><?= (int)$r['pools'] ?></td><td class="num"><?= (int)$r['open_bookings'] ?></td>
          <td><?= badge($r['is_active'] ? 'Open' : 'Hidden') ?></td>
          <td class="row-actions">
            <a class="btn sm" href="index.php?resort=<?= (int)$r['resort_id'] ?>">Manage</a>
            <a class="btn sm" href="../landing.php?r=<?= h(rawurlencode($r['slug'])) ?>" target="_blank" rel="noopener">View site</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>Add a resort</h2></div>
  <p class="panel-note">The new resort gets its own rooms, buildings, pools, prices, staff and bookings, with the same booking system. After saving, add those pieces from the menu.</p>
  <form method="post" enctype="multipart/form-data" class="grid-form">
    <?= csrf_field() ?>
    <?php resort_form_fields($form ?? []); ?>
    <h3 class="wide form-section">First resort admin account <small>(optional, they run this resort)</small></h3>
    <label><span>Name</span><input name="mgr_name" maxlength="80" value="<?= h($form['mgr_name'] ?? '') ?>"></label>
    <label><span>Email (sign-in)</span><input type="email" name="mgr_email" maxlength="120" value="<?= h($form['mgr_email'] ?? '') ?>"></label>
    <label><span>Password</span><input type="password" name="mgr_password" autocomplete="new-password" minlength="8" placeholder="At least 8 characters"></label>
    <div class="actions wide"><button class="btn primary" type="submit">Add resort</button></div>
  </form>
</section>
<?php admin_end();
