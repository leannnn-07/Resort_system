<?php
require __DIR__ . '/../includes/crud.php';

crud_page($pdo, [
    'title' => 'Staff', 'singular' => 'Staff member', 'active' => 'staff', 'scoped' => true, 'protect_self' => true,
    'table' => 'staff', 'pk' => 'staff_id', 'order' => 'full_name',
    'fields' => [
        ['name' => 'full_name',     'label' => 'Full name', 'type' => 'text',     'required' => true],
        ['name' => 'role',          'label' => 'Job title', 'type' => 'text',     'required' => true],
        ['name' => 'access',        'label' => 'Access',    'type' => 'select',   'required' => true, 'options' => ['Staff' => 'Staff (reservations, calendar, guests, room status)', 'Admin' => 'Resort admin (everything for this resort)']],
        ['name' => 'email',         'label' => 'Email (sign-in)', 'type' => 'email', 'required' => true],
        ['name' => 'password_hash', 'label' => 'Password',  'type' => 'password', 'hint' => 'At least 8 characters. Leave empty on edit to keep the current one.'],
    ],
    'list_sql' => "SELECT staff_id, full_name, role, IF(access = 'Admin', 'Resort admin', 'Staff') AS access_label, email, (password_hash IS NOT NULL) AS can_login
                     FROM staff WHERE resort_id = :rid ORDER BY full_name",
    'columns' => [
        ['label' => 'Name',      'key' => 'full_name'],
        ['label' => 'Job title', 'key' => 'role'],
        ['label' => 'Access',    'key' => 'access_label', 'format' => 'badge'],
        ['label' => 'Email',     'key' => 'email'],
        ['label' => 'Can sign in', 'key' => 'can_login', 'format' => 'yesno'],
    ],
]);
