<?php
require __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/reviews.php';
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
        } elseif ($act === 'remove_media') {
            $removed = review_media_remove($pdo, $id, [(int)($_POST['media_id'] ?? 0)]);
            flash($removed ? 'Removed from the review.' : 'That photo or video no longer exists.', $removed ? 'ok' : 'error');
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
$media = review_media_map($pdo, $rows);
$st = review_stats($pdo, $RID);
$hidden = (int)$pdo->query("SELECT COUNT(*) FROM review WHERE resort_id = $RID AND status = 'Hidden'")->fetchColumn();

admin_start('Reviews', 'reviews');
admin_head('Reviews', $st['count'] ? number_format($st['avg'], 1) . ' average from ' . plural($st['count'], 'public review') . ($hidden ? ', ' . $hidden . ' hidden' : '') : '');
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
  <?php $items = $media[(int)$r['review_id']] ?? []; if ($items): ?>
  <div class="rv-media rv-media-admin">
    <?php foreach ($items as $m): $isVideo = $m['kind'] === 'video'; $url = h(review_media_url($m, false, '../')); ?>
      <figure class="rv-item">
        <?php if ($isVideo): ?>
          <video class="rv-video" controls preload="metadata" playsinline src="<?= $url ?>"></video>
        <?php else: ?>
          <a class="rv-photo" href="<?= $url ?>" target="_blank" rel="noopener"><img src="<?= h(review_media_url($m, true, '../')) ?>" alt="Photo from the guest" loading="lazy"></a>
        <?php endif; ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>"><input type="hidden" name="action" value="remove_media"><input type="hidden" name="media_id" value="<?= (int)$m['media_id'] ?>">
          <button class="btn sm danger" type="submit" data-confirm="Are you sure you want to remove this <?= $isVideo ? 'video' : 'photo' ?> from the review? This can’t be undone." data-confirm-title="Remove <?= $isVideo ? 'video' : 'photo' ?>" data-confirm-ok="Yes, remove it" data-confirm-tone="danger">Remove <?= $isVideo ? 'video' : 'photo' ?></button>
        </form>
      </figure>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if (trim((string)$r['reply']) !== ''): ?>
    <div class="review-reply"><small>Reply from the resort</small><?= nl2br(h($r['reply'])) ?></div>
  <?php endif; ?>
  <div class="row-actions" style="margin-top:10px">
  <button type="button" class="btn sm primary" data-form-open="reply-modal" data-fill="<?= h(json_encode(['review_id' => (string)$r['review_id'], 'reply' => (string)$r['reply']], JSON_UNESCAPED_UNICODE)) ?>"><?= trim((string)$r['reply']) !== '' ? 'Edit reply' : 'Reply' ?></button>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>">
    <input type="hidden" name="action" value="<?= $r['status'] === 'Hidden' ? 'show' : 'hide' ?>">
    <button class="btn sm<?= $r['status'] === 'Hidden' ? '' : ' danger' ?>" type="submit" data-confirm="<?= $r['status'] === 'Hidden' ? 'Are you sure you want to show this review to the public again?' : 'Are you sure you want to hide this review from the public page?' ?>" data-confirm-title="<?= $r['status'] === 'Hidden' ? 'Show review' : 'Hide review' ?>" data-confirm-ok="Yes, continue"><?= $r['status'] === 'Hidden' ? 'Show publicly' : 'Hide from public' ?></button>
  </form>
  </div>
</section>
<?php endforeach; endif; ?>
<div class="modal" id="reply-modal" data-form-modal role="dialog" aria-modal="true" aria-labelledby="reply-title">
  <div class="modal-card form-card narrow-card">
    <div class="modal-head"><h2 id="reply-title">Reply to review</h2><button type="button" class="modal-x" data-modal-close aria-label="Close">&times;</button></div>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="review_id" value=""><input type="hidden" name="action" value="reply">
      <label><span>Public reply</span><textarea name="reply" rows="4" maxlength="600" placeholder="Thank the guest or answer their feedback"></textarea></label>
      <div class="modal-actions"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save reply</button></div>
    </form>
  </div>
</div>
<?php admin_end();
