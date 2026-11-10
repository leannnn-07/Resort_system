<?php
require __DIR__ . '/includes/layout.php';
require_resort($pdo);

$types = $pdo->query('SELECT * FROM room_type WHERE resort_id = ' . resort_id() . ' ORDER BY base_rate')->fetchAll();

site_start('Rooms and cottages', 'rooms');
?>
<div class="page-head"><h1>Rooms and cottages</h1></div>
<p class="muted lede">Open any stay to see its photos and full description, then scroll to check dates and book it.</p>

<div class="room-cards">
  <?php foreach ($types as $t): ?>
    <?= room_card($t) ?>
  <?php endforeach; ?>
</div>
<?php site_end();
