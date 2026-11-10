<?php
require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/reviews.php';
$staffMember = require_login($pdo, false, 'admin');
$RID = resort_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['review_id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');
    $stmt = $pdo->prepare('SELECT review_id FROM review WHERE review_id = ? AND resort_id = ?');
    $stmt->execute([$id, $RID]);
    try {
        if (!$stmt->fetch()) {
            throw new RuntimeException('That review no longer exists.');
        }
        if ($act === 'hide' || $act === 'show') {
            $pdo->prepare('UPDATE review SET status = ? WHERE review_id = ?')->execute([$act === 'hide' ? 'Hidden' : 'Published', $id]);
            flash($act === 'hide' ? 'Review hidden from the public page.' : 'Review is public again.');
        } elseif ($act === 'reply') {
            $text = trim((string)($_POST['reply'] ?? ''));
            if (mb_strlen($text) > 600) {
                throw new RuntimeException('Keep the reply under 600 characters.');
            }
            $pdo->prepare('UPDATE review SET reply = ?, replied_at = ? WHERE review_id = ?')->execute([$text === '' ? null : $text, $text === '' ? null : date('Y-m-d H:i:s'), $id]);
            flash($text === '' ? 'Reply removed.' : 'Reply saved. Guests can see it under the review.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('reviews.php' . (isset($_GET['show']) ? '?show=' . urlencode((string)$_GET['show']) : ''));
}

$show = (string)($_GET['show'] ?? 'all');
$show = in_array($show, ['all', 'Published', 'Hidden', 'unanswered'], true) ? $show : 'all';
$where = $show === 'Published' || $show === 'Hidden' ? ' AND status = ' . $pdo->quote($show) : ($show === 'unanswered' ? " AND (reply IS NULL OR reply = '')" : '');
$stmt = $pdo->prepare("SELECT * FROM review WHERE resort_id = ?$where ORDER BY created_at DESC, review_id DESC LIMIT 200");
$stmt->execute([$RID]);
$rows = $stmt->fetchAll();
$st = review_stats($pdo, $RID);
$hidden = (int)$pdo->query("SELECT COUNT(*) FROM review WHERE resort_id = $RID AND status = 'Hidden'")->fetchColumn();

admin_start('Reviews', 'reviews');
admin_head('Reviews', $st['count'] ? number_format($st['avg'], 1) . ' average from ' . plural($st['count'], 'public review') . ($hidden ? ', ' . $hidden . ' hidden' : '') : 'Guest ratings and comments for ' . $resort['name']);
?>
<div class="filter-tabs no-print" role="tablist">
  <?php foreach (['all' => 'All', 'Published' => 'Public', 'Hidden' => 'Hidden', 'unanswered' => 'No reply yet'] as $k => $label): ?>
    <a class="btn sm<?= $show === $k ? ' primary' : '' ?>" href="reviews.php?show=<?= h($k) ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <p class="empty">No reviews here yet. Guests can review their stay after they check out.</p>
<?php else: foreach ($rows as $r): ?>
<section class="panel review-admin<?= $r['status'] === 'Hidden' ? ' is-hidden' : '' ?>">
  <div class="panel-head">
    <div><?= stars((float)$r['rating']) ?> <strong><?= h($r['title'] ?: ($r['rating'] . ' stars')) ?></strong>
      <?= $r['status'] === 'Hidden' ? '<span class="badge cancelled">Hidden</span>' : '' ?></div>
    <p class="muted"><?= h($r['author']) ?> · <?= h(fdate(substr((string)$r['created_at'], 0, 10))) ?><?= $r['reservation_id'] ? ' · <a href="reservation_view.php?id=' . (int)$r['reservation_id'] . '">View stay</a>' : ' · Sample review, no stay attached' ?></p>
  </div>
  <?php if (trim((string)$r['comment']) !== ''): ?><p><?= nl2br(h($r['comment'])) ?></p><?php else: ?><p class="muted">No comment, rating only.</p><?php endif; ?>
  <form method="post" class="stack" style="margin-top:10px">
    <?= csrf_field() ?><input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>"><input type="hidden" name="action" value="reply">
    <label><span>Reply from the resort (public)</span><textarea name="reply" rows="2" maxlength="600" placeholder="Thank the guest or answer their feedback"><?= h((string)$r['reply']) ?></textarea></label>
    <div class="row-actions"><button class="btn sm primary" type="submit">Save reply</button></div>
  </form>
  <form method="post" class="row-actions" style="margin-top:8px" onsubmit="return confirm('<?= $r['status'] === 'Hidden' ? 'Show this review to the public again?' : 'Hide this review from the public page?' ?>')">
    <?= csrf_field() ?><input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>">
    <input type="hidden" name="action" value="<?= $r['status'] === 'Hidden' ? 'show' : 'hide' ?>">
    <button class="btn sm<?= $r['status'] === 'Hidden' ? '' : ' danger' ?>" type="submit"><?= $r['status'] === 'Hidden' ? 'Show publicly' : 'Hide from public' ?></button>
  </form>
</section>
<?php endforeach; endif; ?>
<?php admin_end();
