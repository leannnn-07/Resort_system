<?php
/**
 * Cottage-first search form (single unified bar matching the site's native search-card design).
 * Expects: $in, $out, $adults, $children, $formId (optional), $action (optional), $searchMode (optional: 'cottage'|'rooms').
 */
$action     = $action ?? 'search.php';
$allowMode  = $action === 'search.php';
$searchMode = $allowMode ? (($searchMode ?? ($_GET['mode'] ?? 'cottage')) === 'rooms' ? 'rooms' : 'cottage') : 'rooms';
$isCotMode  = $searchMode === 'cottage';
?>
<form method="get" action="<?= h($action) ?>" class="search-card<?= $isCotMode ? ' is-cottage' : '' ?>" data-stay id="<?= h($formId ?? 'book') ?>">
  <?= rhidden() ?>
  <?php if ($allowMode): ?>
  <label><span>Type</span>
    <select name="mode" data-mode-select>
      <option value="cottage"<?= $isCotMode ? ' selected' : '' ?>>Cottage (Day use)</option>
      <option value="rooms"<?= !$isCotMode ? ' selected' : '' ?>>Overnight room</option>
    </select>
  </label>
  <?php endif; ?>
  <label><span data-in-label><?= $isCotMode ? 'Visit date' : 'Check-in' ?></span>
    <input type="date" name="check_in" value="<?= h($in) ?>" min="<?= h(today()) ?>" max="<?= h(add_days(today(), (int)$config['max_advance_days'])) ?>" required>
  </label>
  <label data-out-wrap<?= $isCotMode ? ' hidden' : '' ?>><span>Check-out <em class="nights" data-nights></em></span>
    <input type="date" name="check_out" value="<?= h($out) ?>" min="<?= h(add_days(today(), 1)) ?>"<?= $isCotMode ? ' disabled' : ' required' ?>>
  </label>
  <label><span>Guests</span>
    <input type="number" name="adults" value="<?= max(1, (int)$adults + (int)$children) ?>" min="1" max="50" step="1" required>
  </label>
  <input type="hidden" name="children" value="0">
  <button class="btn primary" type="submit" data-search-btn><?= $isCotMode ? 'Find cottages' : 'Check rooms' ?></button>
</form>
