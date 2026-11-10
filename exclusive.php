<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/booking.php';
require_resort($pdo);

$asked    = isset($_GET['check_in']);
$in       = $asked ? (string)($_GET['check_in'] ?? '') : add_days(today(), 1);
$out      = $asked ? (string)($_GET['check_out'] ?? '') : add_days(today(), 3);
$adults   = max(1, (int)($_GET['adults'] ?? 2));
$children = max(0, (int)($_GET['children'] ?? 0));

$problems = $asked ? validate_stay($in, $out, $adults, $children, 5000) : [];
$opts = [];
if (!$problems) {
    $opts = exclusive_options($pdo, $in, $out);
}
$stay = ($asked && !$problems) ? ['check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $children] : null;

site_start('Book the whole place', 'stay');
?>
<div class="page-head"><h1>Book the whole place</h1></div>
<?= booking_steps(1) ?>
<p class="muted lede">Reserve an entire pool, an entire building or the entire resort for your group only. Choose your dates and we show what is free.</p>
<?php $action = 'exclusive.php'; $formId = 'dates'; require __DIR__ . '/includes/search_form.php'; ?>

<?php if ($problems): ?>
  <div class="notice error" role="alert"><?= h($problems[0]) ?></div>
<?php elseif ($stay): ?>
  <p class="stay-line"><?= h(fdate($in)) ?> to <?= h(fdate($out)) ?>, <?= h(plural(nights($in, $out), 'night')) ?>, <?= h(plural($adults + $children, 'guest')) ?></p>
<?php endif; ?>

<?php $html = exclusive_cards($opts ?: exclusive_options($pdo, add_days(today(), 1), add_days(today(), 3)), $stay); echo $html !== '' ? $html : '<p class="empty">This resort has no whole-place bookings set up yet.</p>'; ?>
<p class="muted">Just need a room? <a href="<?= h(ru('book.php#book')) ?>">Book rooms and cottages</a>.</p>
<?php site_end();
