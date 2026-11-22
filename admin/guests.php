<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Guests', 'singular' => 'Guest', 'active' => 'guests', 'level' => 'staff',
    // A resort sees only guests who booked with it (or that its own staff added).
    'scope_sql' => '(created_resort_id = {rid} OR EXISTS (SELECT 1 FROM reservation v WHERE v.guest_id = guest.guest_id AND v.resort_id = {rid}))',
    'insert_extra' => ['created_resort_id' => true],
    'table' => 'guest', 'pk' => 'guest_id', 'order' => 'last_name, first_name',
    // Guests with bookings can't be deleted, so "Delete" archives them (Archive page); their bookings stay as they were.
    'archive' => true,
    'fields' => [
        ['name' => 'last_name',  'label' => 'Last name',  'type' => 'text',  'required' => true],
        ['name' => 'first_name', 'label' => 'First name', 'type' => 'text',  'required' => true],
        ['name' => 'email',      'label' => 'Email',      'type' => 'email', 'required' => true],
        ['name' => 'phone',      'label' => 'Phone',      'type' => 'text', 'placeholder' => 'e.g. 0917 123 4567'],
        ['name' => 'id_type',    'label' => 'ID type',    'type' => 'select', 'options' => ID_TYPES, 'none_label' => 'Not recorded'],
        ['name' => 'id_number',  'label' => 'ID number',  'type' => 'text', 'secret' => true, 'placeholder' => 'e.g. N01-23-456789'],
    ],
    // ID numbers are encrypted. Front desk can record one but not read it back: a blank box on Edit keeps the stored number.
    'before_save' => function (array $data, array $post, array $staff, int $editId): array {
        $type = (string)($data['id_type'] ?? '');
        $num  = trim((string)($post['id_number'] ?? ''));
        if ($editId > 0 && $num === '' && $type !== ID_NONE && !staff_can($staff, 'admin')) {
            if ($type === '') { unset($data['id_type']); }
            return $data;
        }
        return array_merge($data, id_prepare($type, $num));
    },
    'columns' => [
        ['label' => 'Last name',  'key' => 'last_name'],
        ['label' => 'First name', 'key' => 'first_name'],
        ['label' => 'Email',      'key' => 'email'],
        ['label' => 'Phone',      'key' => 'phone'],
        ['label' => 'ID',         'key' => 'id_number', 'format' => 'secret', 'muted' => true],
    ],
]);
