<?php
require __DIR__ . '/../includes/resort_admin.php';

$staffMember = require_login($pdo, false, 'admin');
$self = 'settings.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $d = resort_read_post($_POST);
        $cover = resort_save_cover($resort['slug']);
        if ($cover !== null) {
            $d['hero_image'] = $cover;
        }
        $d['resort_id'] = resort_id();
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_filter(array_keys($d), fn($k) => $k !== 'resort_id')));
        $pdo->prepare("UPDATE resort SET $set WHERE resort_id = :resort_id")->execute($d);
        flash('Resort settings saved.');
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect($self);
}

admin_start('Resort settings', 'settings');
?>
<?php admin_head('Settings', 'Details guests see for ' . $resort['name'], '<a class="btn" href="../' . h(ru('landing.php')) . '" target="_blank" rel="noopener">View public page</a>'); ?>
<section class="panel">
  <div class="panel-head"><h2>Resort details</h2></div>
  <p class="panel-note">Public address: <code><?= h('landing.php?r=' . $resort['slug']) ?></code>. Add room photos on the <a href="photos.php">Room photos</a> page (they are stored in <code>assets/rooms/<?= h($resort['slug']) ?>/</code> named like <code>deluxe-room-1.jpg</code>).</p>
  <form method="post" enctype="multipart/form-data" class="grid-form">
    <?= csrf_field() ?>
    <?php resort_form_fields($resort); ?>
    <div class="actions wide"><button class="btn primary" type="submit">Save settings</button></div>
  </form>
</section>
<?php admin_end();
