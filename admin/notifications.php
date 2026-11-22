<?php
/**
 * JSON & form endpoint for the staff / resort admin / super admin notification bell.
 *  GET  -> returns unread count and latest notifications for live polling.
 *  POST -> action=read (notif_id) or action=read_all.
 */
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/notifications.php';

$staffMember = require_login($pdo);
$isJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
       || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'read') {
        staff_notification_mark_read($pdo, $staffMember, (int)($_POST['notif_id'] ?? 0));
    } elseif ($action === 'read_all') {
        staff_notification_mark_all_read($pdo, $staffMember);
    }

    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        $data = staff_notifications_for($pdo, $staffMember);
        echo json_encode(['ok' => true, 'unread' => $data['unread'], 'items' => $data['items']]);
        exit;
    }
    $back = (string)($_SERVER['HTTP_REFERER'] ?? 'index.php');
    redirect(str_contains($back, '/admin/') ? $back : 'index.php');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(staff_notifications_for($pdo, $staffMember));
