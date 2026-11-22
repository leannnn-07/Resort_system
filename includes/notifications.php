<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * In-app notification bell for Super Admin, Resort Admin, and Staff.
 *  - Super admin receives notifications from all resorts (clicking switches resort automatically).
 *  - Resort admin and staff receive notifications only for their own resort.
 *  - Read state is tracked per staff member (staff_notification_read).
 */

/** Record a notification for resort staff, resort admins, and super admins. Never throws. */
function add_staff_notification(
    PDO $pdo,
    int $resortId,
    ?int $reservationId,
    string $kind,
    string $title,
    string $body,
    string $link
): void {
    try {
        $pdo->prepare(
            'INSERT INTO staff_notification (resort_id, reservation_id, kind, title, body, link)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $resortId,
            $reservationId,
            mb_substr($kind, 0, 30),
            mb_substr($title, 0, 120),
            mb_substr($body, 0, 220),
            mb_substr($link, 0, 200),
        ]);
    } catch (Throwable $e) {
        error_log('[resort] add_staff_notification failed: ' . $e->getMessage());
    }
}

function notif_time_ago(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return (int)floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return (int)floor($diff / 3600) . 'h ago';
    }
    if ($diff < 172800) {
        return 'Yesterday';
    }
    return date('M j', $ts);
}

/** Build the safe relative admin link for a notification (adds resort=ID for super admin). */
function notif_href(array $row, bool $isPlatform): string
{
    $link = (string)($row['link'] ?? 'reservations.php');
    if (!$isPlatform) {
        return $link;
    }
    $frag = '';
    if (($pos = strpos($link, '#')) !== false) {
        $frag = substr($link, $pos);
        $link = substr($link, 0, $pos);
    }
    $sep = str_contains($link, '?') ? '&' : '?';
    return $link . $sep . 'resort=' . (int)$row['resort_id'] . $frag;
}

/** Recent notifications and unread count for the logged-in staff / admin / super admin. */
function staff_notifications_for(PDO $pdo, array $staffMember, int $limit = 15): array
{
    $sid        = (int)($staffMember['staff_id'] ?? 0);
    $isPlatform = !empty($staffMember['is_platform']);
    $rid        = (int)($staffMember['resort_id'] ?? 0);
    if ($sid <= 0) {
        return ['unread' => 0, 'items' => []];
    }

    try {
        $where  = $isPlatform ? '' : 'WHERE n.resort_id = :rid';
        $params = $isPlatform ? ['sid' => $sid] : ['sid' => $sid, 'rid' => $rid];

        $cntStmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM staff_notification n
               LEFT JOIN staff_notification_read r ON r.notif_id = n.notif_id AND r.staff_id = :sid
              $where" . ($where ? ' AND' : ' WHERE') . ' r.notif_id IS NULL'
        );
        $cntStmt->execute($params);
        $unread = (int)$cntStmt->fetchColumn();

        $limit = max(1, min(50, $limit));
        $listStmt = $pdo->prepare(
            "SELECT n.notif_id, n.resort_id, n.reservation_id, n.kind, n.title, n.body, n.link, n.created_at,
                    rs.name AS resort_name,
                    (r.notif_id IS NOT NULL) AS is_read
               FROM staff_notification n
               JOIN resort rs ON rs.resort_id = n.resort_id
               LEFT JOIN staff_notification_read r ON r.notif_id = n.notif_id AND r.staff_id = :sid
              $where
              ORDER BY n.notif_id DESC
              LIMIT $limit"
        );
        $listStmt->execute($params);
        $items = [];
        foreach ($listStmt->fetchAll() as $row) {
            $items[] = [
                'id'          => (int)$row['notif_id'],
                'kind'        => (string)$row['kind'],
                'title'       => (string)$row['title'],
                'body'        => (string)$row['body'],
                'resort_name' => $isPlatform ? (string)$row['resort_name'] : '',
                'href'        => notif_href($row, $isPlatform),
                'time'        => notif_time_ago((string)$row['created_at']),
                'is_read'     => (bool)$row['is_read'],
            ];
        }
        return ['unread' => $unread, 'items' => $items];
    } catch (Throwable $e) {
        error_log('[resort] staff_notifications_for failed: ' . $e->getMessage());
        return ['unread' => 0, 'items' => []];
    }
}

function staff_notification_mark_read(PDO $pdo, array $staffMember, int $notifId): void
{
    $sid = (int)($staffMember['staff_id'] ?? 0);
    if ($sid <= 0 || $notifId <= 0) {
        return;
    }
    try {
        if (empty($staffMember['is_platform'])) {
            $chk = $pdo->prepare('SELECT 1 FROM staff_notification WHERE notif_id = ? AND resort_id = ?');
            $chk->execute([$notifId, (int)$staffMember['resort_id']]);
            if (!$chk->fetchColumn()) {
                return;
            }
        }
        $pdo->prepare('INSERT IGNORE INTO staff_notification_read (staff_id, notif_id) VALUES (?, ?)')->execute([$sid, $notifId]);
    } catch (Throwable $e) {
    }
}

function staff_notification_mark_all_read(PDO $pdo, array $staffMember): void
{
    $sid = (int)($staffMember['staff_id'] ?? 0);
    if ($sid <= 0) {
        return;
    }
    try {
        if (!empty($staffMember['is_platform'])) {
            $pdo->prepare(
                'INSERT IGNORE INTO staff_notification_read (staff_id, notif_id)
                 SELECT ?, n.notif_id FROM staff_notification n
                  LEFT JOIN staff_notification_read r ON r.notif_id = n.notif_id AND r.staff_id = ?
                 WHERE r.notif_id IS NULL'
            )->execute([$sid, $sid]);
        } else {
            $pdo->prepare(
                'INSERT IGNORE INTO staff_notification_read (staff_id, notif_id)
                 SELECT ?, n.notif_id FROM staff_notification n
                  LEFT JOIN staff_notification_read r ON r.notif_id = n.notif_id AND r.staff_id = ?
                 WHERE n.resort_id = ? AND r.notif_id IS NULL'
            )->execute([$sid, $sid, (int)$staffMember['resort_id']]);
        }
    } catch (Throwable $e) {
    }
}

/** Auto-mark notifications for a reservation as read when the staff member opens it. */
function staff_notification_mark_reservation_read(PDO $pdo, int $staffId, int $reservationId): void
{
    if ($staffId <= 0 || $reservationId <= 0) {
        return;
    }
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO staff_notification_read (staff_id, notif_id)
             SELECT ?, notif_id FROM staff_notification WHERE reservation_id = ?'
        )->execute([$staffId, $reservationId]);
    } catch (Throwable $e) {
    }
}
