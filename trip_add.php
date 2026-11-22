<?php
/**
 * "Add to trip": POST only. Saves the choice in the guest's trip, then goes back to the page they were on
 * so they can keep browsing (more rooms here, or another resort).
 */
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/trips.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('trip.php');
}
csrf_check();

$item = trip_item_from_input($_POST);
$allResorts = !empty($_POST['all_resorts']);
if ($item['scope'] === 'Cottage') {
    $params = ['mode' => 'cottage', 'check_in' => $item['check_in'], 'adults' => max(1, $item['adults']), 'children' => max(0, $item['children'])];
    if (!$allResorts && $item['slug'] !== '') {
        $params = ['r' => $item['slug']] + $params;
    }
    $back = 'search.php?' . http_build_query($params);
} elseif ($item['scope'] === 'Rooms') {
    $params = ['mode' => 'rooms', 'check_in' => $item['check_in'], 'check_out' => $item['check_out'], 'adults' => max(1, $item['adults']), 'children' => max(0, $item['children'])];
    if (!$allResorts && $item['slug'] !== '') {
        $params = ['r' => $item['slug']] + $params;
    }
    $back = 'search.php?' . http_build_query($params);
} else {
    $back = 'exclusive.php?' . http_build_query([
        'r' => $item['slug'], 'check_in' => $item['check_in'], 'check_out' => $item['check_out'],
        'adults' => max(1, $item['adults']), 'children' => max(0, $item['children']),
    ]);
}

require_guest_to_book($pdo, $back);   // adding to a trip needs an account

if ($item['slug'] === '' || !resort_find($pdo, $item['slug'])) {
    flash('Choose a resort first.', 'error');
    redirect('resorts.php');
}

[$ok, $message] = trip_add($pdo, $item);
if ($ok) {
    flash($message, 'ok', ['trip.php', 'View trip']);
} else {
    flash($message, 'error');
}
redirect($back);
