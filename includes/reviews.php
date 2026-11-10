<?php
// Guest ratings and comments. Only guests with a finished stay can review, one review per stay.

/** Average rating, count and a 1..5 breakdown of the published reviews of one resort. */
function review_stats(PDO $pdo, int $resortId): array
{
    $stmt = $pdo->prepare("SELECT rating, COUNT(*) FROM review WHERE resort_id = ? AND status = 'Published' GROUP BY rating");
    $stmt->execute([$resortId]);
    $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $rating => $n) {
        $dist[(int)$rating] = (int)$n;
    }
    $count = array_sum($dist);
    $sum = 0;
    foreach ($dist as $r => $n) {
        $sum += $r * $n;
    }
    return ['count' => $count, 'avg' => $count ? $sum / $count : 0.0, 'dist' => $dist];
}

/** [resort_id => ['count', 'avg']] for every resort at once (resort list and platform overview). */
function review_stats_all(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT resort_id, COUNT(*) AS c, AVG(rating) AS a FROM review WHERE status = 'Published' GROUP BY resort_id") as $r) {
        $out[(int)$r['resort_id']] = ['count' => (int)$r['c'], 'avg' => (float)$r['a']];
    }
    return $out;
}

/** Star icons for a rating (partial stars are shown, e.g. 4.3). */
function stars(float $avg): string
{
    $pct = max(0, min(100, round($avg / 5 * 100)));
    return '<span class="stars" style="--pct:' . $pct . '%" role="img" aria-label="' . h(number_format($avg, 1)) . ' out of 5 stars"></span>';
}

/** "4.3 ★★★★☆ (12 reviews)" for a resort card or heading. */
function rating_line(array $st, string $href = ''): string
{
    if (!$st || $st['count'] < 1) {
        return '<span class="rating-line muted">No reviews yet</span>';
    }
    $text = '<strong>' . h(number_format($st['avg'], 1)) . '</strong> ' . stars($st['avg']) . ' <span class="muted">(' . h(plural($st['count'], 'review')) . ')</span>';
    return '<span class="rating-line">' . ($href !== '' ? '<a href="' . h($href) . '">' . $text . '</a>' : $text) . '</span>';
}

/** "Maria S." from a guest's name, so a full surname is never published. */
function review_author(string $first, string $last): string
{
    $initial = mb_strtoupper(mb_substr(trim($last), 0, 1));
    return trim(mb_substr(trim($first), 0, 40) . ($initial !== '' ? ' ' . $initial . '.' : ''));
}

/** One published review as a card. */
function review_card(array $r): string
{
    $html = '<article class="review"><header><div>' . stars((float)$r['rating'])
          . ($r['title'] ? ' <strong class="rv-title">' . h($r['title']) . '</strong>' : '') . '</div>'
          . '<p class="muted rv-meta">' . h($r['author']) . ' · ' . h(fdate(substr((string)$r['created_at'], 0, 10))) . ($r['reservation_id'] ? ' · Verified stay' : '') . '</p></header>';
    if (trim((string)$r['comment']) !== '') {
        $html .= '<p class="rv-text">' . nl2br(h($r['comment'])) . '</p>';
    }
    if (trim((string)$r['reply']) !== '') {
        $html .= '<div class="rv-reply"><strong>Reply from the resort</strong><p>' . nl2br(h($r['reply'])) . '</p></div>';
    }
    return $html . '</article>';
}

/** Check a posted rating form. Returns [rating, title|null, comment|null]; throws RuntimeException with a guest-friendly message. */
function parse_review(array $post): array
{
    $rating  = (string)($post['rating'] ?? '');
    $title   = trim((string)($post['title'] ?? ''));
    $comment = trim((string)($post['comment'] ?? ''));
    if (!ctype_digit($rating) || (int)$rating < 1 || (int)$rating > 5) {
        throw new RuntimeException('Choose a rating from 1 to 5 stars.');
    }
    if (mb_strlen($title) > 100) {
        throw new RuntimeException('Keep the headline under 100 characters.');
    }
    if (mb_strlen($comment) > 1000) {
        throw new RuntimeException('Keep your comment under 1000 characters.');
    }
    return [(int)$rating, $title === '' ? null : $title, $comment === '' ? null : $comment];
}

/** The review a stay already has, if any. */
function review_for(PDO $pdo, int $reservationId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM review WHERE reservation_id = ?');
    $stmt->execute([$reservationId]);
    return $stmt->fetch() ?: null;
}

/** Save (or update) the review of a finished stay. */
function save_review(PDO $pdo, array $res, array $post): void
{
    if ($res['status'] !== 'Checked-out') {
        throw new RuntimeException('You can review a stay after you have checked out.');
    }
    [$rating, $title, $comment] = parse_review($post);
    $author = review_author((string)$res['first_name'], (string)$res['last_name']);
    $pdo->prepare(
        'INSERT INTO review (resort_id, reservation_id, guest_id, author, rating, title, comment) VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating), title = VALUES(title), comment = VALUES(comment), updated_at = NOW()'
    )->execute([$res['resort_id'], $res['reservation_id'], $res['guest_id'], $author, $rating, $title, $comment]);
}

/** Radio stars + headline + comment: the form shown on a finished booking. */
function review_form(string $ref, ?array $existing): string
{
    $cur = (int)($existing['rating'] ?? 0);
    $html = '<form method="post" class="stack" id="review-form">' . csrf_field()
          . '<input type="hidden" name="ref" value="' . h($ref) . '"><input type="hidden" name="action" value="review">'
          . '<fieldset class="rate-pick"><legend>Your rating</legend><div class="rate-row">';
    for ($i = 5; $i >= 1; $i--) {
        $html .= '<input type="radio" name="rating" id="rate' . $i . '" value="' . $i . '"' . ($cur === $i ? ' checked' : '') . ' required>'
               . '<label for="rate' . $i . '" title="' . $i . ' star' . ($i > 1 ? 's' : '') . '"><span class="sr-only">' . $i . ' star' . ($i > 1 ? 's' : '') . '</span></label>';
    }
    $html .= '</div></fieldset>'
           . '<label><span>Headline (optional)</span><input name="title" maxlength="100" value="' . h((string)($existing['title'] ?? '')) . '" placeholder="Sum it up in a few words"></label>'
           . '<label><span>Your comment (optional)</span><textarea name="comment" rows="4" maxlength="1000" placeholder="What did you enjoy? What could be better?">' . h((string)($existing['comment'] ?? '')) . '</textarea></label>'
           . '<div class="actions"><button class="btn primary" type="submit">' . ($existing ? 'Update my review' : 'Post my review') . '</button></div></form>';
    return $html;
}
