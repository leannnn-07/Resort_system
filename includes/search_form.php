<?php
/**
 * Stay search form (dates + guests). Expects: $in, $out, $adults, $children, $formId (optional), $action.
 * Included from index.php and search.php.
 */
$action = $action ?? 'search.php';
?>
<form method="get" action="<?= h($action) ?>" class="search-card" data-stay id="<?= h($formId ?? 'book') ?>">
  <?= rhidden() ?>
  <label><span>Check-in</span>
    <input type="date" name="check_in" value="<?= h($in) ?>" min="<?= h(today()) ?>" max="<?= h(add_days(today(), (int)$config['max_advance_days'])) ?>" required>
  </label>
  <label><span>Check-out <em class="nights" data-nights></em></span>
    <input type="date" name="check_out" value="<?= h($out) ?>" min="<?= h(add_days(today(), 1)) ?>" required>
  </label>
  <label><span>Adults</span>
    <input type="number" name="adults" value="<?= (int)$adults ?>" min="1" max="20" step="1" required>
  </label>
  <label><span>Children</span>
    <input type="number" name="children" value="<?= (int)$children ?>" min="0" max="20" step="1">
  </label>
  <button class="btn primary" type="submit">Check availability</button>
</form>
