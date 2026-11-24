<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/booking.php';

$staffMember = require_login($pdo, false, 'admin');
$isSuper     = staff_can($staffMember, 'super');
$RID         = resort_id();
$self        = 'discounts.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save') {
            $id      = (int)($_POST['discount_id'] ?? 0);
            $title   = trim((string)($_POST['title'] ?? ''));
            $pct     = (float)($_POST['percent_off'] ?? 0);
            $start   = trim((string)($_POST['start_date'] ?? ''));
            $end     = trim((string)($_POST['end_date'] ?? ''));
            $note    = trim((string)($_POST['note'] ?? ''));
            $scope   = (string)($_POST['scope'] ?? 'resort');
            $active  = !empty($_POST['is_active']) ? 1 : 0;

            if ($title === '' || mb_strlen($title) > 100) {
                throw new RuntimeException('Enter a discount offer title (up to 100 characters), e.g. December Special Offer.');
            }
            if ($pct <= 0 || $pct > 90) {
                throw new RuntimeException('Discount percentage must be between 1% and 90%.');
            }
            if ($start !== '' && !valid_date($start)) {
                throw new RuntimeException('Enter a valid start date.');
            }
            if ($end !== '' && !valid_date($end)) {
                throw new RuntimeException('Enter a valid end date.');
            }
            if ($start !== '' && $end !== '' && $end < $start) {
                throw new RuntimeException('End date must be on or after the start date.');
            }
            if (mb_strlen($note) > 200) {
                throw new RuntimeException('Keep the note under 200 characters.');
            }

            $targetResortId = ($isSuper && $scope === 'all') ? null : $RID;

            if ($id > 0) {
                $chk = $pdo->prepare('SELECT * FROM resort_discount WHERE discount_id = ? AND archived_at IS NULL');
                $chk->execute([$id]);
                $row = $chk->fetch();
                if (!$row) {
                    throw new RuntimeException('That discount offer no longer exists.');
                }
                if (!$isSuper && (int)($row['resort_id'] ?? 0) !== $RID) {
                    throw new RuntimeException('You can only edit special discounts belonging to your resort.');
                }
                $pdo->prepare(
                    'UPDATE resort_discount
                        SET resort_id = ?, title = ?, percent_off = ?, start_date = ?, end_date = ?, is_active = ?, note = ?
                      WHERE discount_id = ?'
                )->execute([
                    $targetResortId,
                    $title,
                    round($pct, 2),
                    $start !== '' ? $start : null,
                    $end !== '' ? $end : null,
                    $active,
                    $note !== '' ? $note : null,
                    $id,
                ]);
                flash('Special discount updated.');
            } else {
                $pdo->prepare(
                    'INSERT INTO resort_discount (resort_id, title, percent_off, start_date, end_date, is_active, note, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $targetResortId,
                    $title,
                    round($pct, 2),
                    $start !== '' ? $start : null,
                    $end !== '' ? $end : null,
                    $active,
                    $note !== '' ? $note : null,
                    (int)$staffMember['staff_id'],
                ]);
                flash('Special discount added.');
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['discount_id'] ?? 0);
            $cond = $isSuper ? '(resort_id = ? OR resort_id IS NULL)' : 'resort_id = ?';
            $pdo->prepare("UPDATE resort_discount SET is_active = 1 - is_active WHERE discount_id = ? AND $cond AND archived_at IS NULL")
                ->execute([$id, $RID]);
            flash('Special discount status updated.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['discount_id'] ?? 0);
            $cond = $isSuper ? '(resort_id = ? OR resort_id IS NULL)' : 'resort_id = ?';
            $pdo->prepare("UPDATE resort_discount SET archived_at = NOW() WHERE discount_id = ? AND $cond AND archived_at IS NULL")
                ->execute([$id, $RID]);
            flash('Special discount archived.');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect($self);
}

$stmt = $pdo->prepare(
    'SELECT d.*, r.name AS resort_name
       FROM resort_discount d
       LEFT JOIN resort r ON r.resort_id = d.resort_id
      WHERE d.archived_at IS NULL AND (d.resort_id = ? OR d.resort_id IS NULL)
      ORDER BY d.is_active DESC, d.resort_id IS NULL ASC, d.discount_id DESC'
);
$stmt->execute([$RID]);
$rows = $stmt->fetchAll();

$records = [];
foreach ($rows as $r) {
    $records[(int)$r['discount_id']] = [
        'title'       => (string)$r['title'],
        'percent_off' => rtrim(rtrim(number_format((float)$r['percent_off'], 2, '.', ''), '0'), '.'),
        'start_date'  => (string)($r['start_date'] ?? ''),
        'end_date'    => (string)($r['end_date'] ?? ''),
        'note'        => (string)($r['note'] ?? ''),
        'scope'       => $r['resort_id'] === null ? 'all' : 'resort',
        'is_active'   => (int)$r['is_active'] ? '1' : '0',
    ];
}

admin_start('Special discounts', 'discounts');
admin_head(
    'Special discounts',
    'Promotional discounts deducted from the original price (in addition to 20% Senior Citizen & PWD ID discounts)',
    '<button type="button" class="btn primary" data-form-open="crud-modal">Add special discount</button>'
);
?>
<nav class="seg" aria-label="Pricing and add-ons">
  <a href="pricelist.php">Cottages &amp; Entrance</a>
  <a href="services.php">Add-ons</a>
  <a href="discounts.php" aria-current="page">Special discounts</a>
</nav>

<section class="panel" style="margin-bottom:16px">
  <h2 style="margin:0 0 6px;font-size:16px">How discounts work</h2>
  <p class="muted" style="margin:0">
    All discounts are deducted directly from the original price—no separate Senior, PWD, or promo prices need to be added to the Price List.
    Guests can request a <strong>Senior Citizen (RA 9994 · 20% + VAT-exempt pro-rated share)</strong>, <strong>PWD (20% + VAT-exempt pro-rated share)</strong>, or any active <strong>Special discount</strong> below during booking without uploading any ID photo.
    Superadmin, Admin, and Staff verify and approve all discounts over the counter upon arrival.
  </p>
</section>

<section class="panel flush">
  <?php if (!$rows): ?>
    <p class="empty">No special discount offers yet. <button type="button" class="link-btn" data-form-open="crud-modal">Add the first special discount</button> (e.g. December Special Offer).</p>
  <?php else: ?>
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>Offer title</th>
          <th class="num">Discount %</th>
          <th>Valid dates</th>
          <th>Applies to</th>
          <th>Status</th>
          <th>Note</th>
          <th class="right">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
          $did = (int)$r['discount_id'];
          $isPlatformOffer = $r['resort_id'] === null;
          $canEdit = $isSuper || !$isPlatformOffer;
          $pctStr = rtrim(rtrim(number_format((float)$r['percent_off'], 2, '.', ''), '0'), '.') . '% off';
          $datesLabel = ($r['start_date'] || $r['end_date'])
              ? (($r['start_date'] ? fdate($r['start_date']) : 'Any') . ' – ' . ($r['end_date'] ? fdate($r['end_date']) : 'Ongoing'))
              : 'All dates';
      ?>
        <tr>
          <td><strong><?= h($r['title']) ?></strong></td>
          <td class="num"><strong><?= h($pctStr) ?></strong></td>
          <td class="nowrap"><?= h($datesLabel) ?></td>
          <td class="nowrap"><?= $isPlatformOffer ? '<span class="badge confirmed">All resorts</span>' : h($r['resort_name'] ?: $config['resort']) ?></td>
          <td class="nowrap"><span class="badge <?= (int)$r['is_active'] ? 'yes' : 'no' ?>"><?= (int)$r['is_active'] ? 'Active' : 'Paused' ?></span></td>
          <td class="sub"><?= $r['note'] ? h($r['note']) : '<span class="muted">–</span>' ?></td>
          <td class="row-actions">
            <?php if ($canEdit): ?>
            <div class="act-group">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="discount_id" value="<?= $did ?>">
                <button class="btn sm" type="submit"><?= (int)$r['is_active'] ? 'Pause' : 'Activate' ?></button>
              </form>
              <a class="btn sm" href="discounts.php?edit=<?= $did ?>" data-form-open="crud-modal" data-id="<?= $did ?>">Edit</a>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="discount_id" value="<?= $did ?>">
                <button class="btn sm danger" type="submit" data-confirm="Remove this special discount offer?" data-confirm-title="Remove discount" data-confirm-ok="Yes, remove" data-confirm-tone="danger">Remove</button>
              </form>
            </div>
            <?php else: ?>
              <span class="muted">Platform offer</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<div class="modal" id="crud-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="crud-title"
     data-title-add="Add special discount" data-title-edit="Edit special discount">
  <div class="modal-card form-card">
    <div class="modal-head">
      <h2 id="crud-title">Add special discount</h2>
      <button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="post" class="grid-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="discount_id" value="" data-record-id>

      <label class="wide"><span>Offer title</span>
        <input type="text" name="title" maxlength="100" placeholder="e.g. December Special Offer" required>
      </label>

      <label><span>Discount percentage (%)</span>
        <input type="number" name="percent_off" value="10" min="1" max="90" step="0.5" required>
      </label>

      <?php if ($isSuper): ?>
      <label><span>Applies to</span>
        <select name="scope">
          <option value="resort"><?= h($config['resort']) ?> only</option>
          <option value="all">All resorts (Platform-wide)</option>
        </select>
      </label>
      <?php else: ?>
      <label><span>Status</span>
        <select name="is_active">
          <option value="1">Active (guests can apply)</option>
          <option value="0">Paused</option>
        </select>
      </label>
      <?php endif; ?>

      <label><span>Start date <small class="muted">(optional)</small></span>
        <input type="date" name="start_date">
      </label>
      <label><span>End date <small class="muted">(optional)</small></span>
        <input type="date" name="end_date">
      </label>

      <?php if ($isSuper): ?>
      <label><span>Status</span>
        <select name="is_active">
          <option value="1">Active (guests can apply)</option>
          <option value="0">Paused</option>
        </select>
      </label>
      <?php endif; ?>

      <label class="wide"><span>Short note <small class="muted">(optional, shown to guests)</small></span>
        <input type="text" name="note" maxlength="200" placeholder="e.g. Holiday promo off original room & cottage prices">
      </label>

      <div class="modal-actions wide">
        <button class="btn" type="button" data-modal-close>Cancel</button>
        <button class="btn primary" type="submit">Save special discount</button>
      </div>
    </form>
  </div>
</div>
<script type="application/json" id="crud-records"><?= json_encode($records, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT) ?></script>
<?php admin_end();
