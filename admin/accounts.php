<?php
require __DIR__ . '/../includes/crud.php';

// Super admin only: every staff sign-in on the platform (guest accounts are on users.php). Leave "Resort" on "All resorts" to make another super admin.
crud_page($pdo, [
    'title' => 'Staff & admins', 'singular' => 'Account', 'active' => 'admins', 'platform_only' => true, 'protect_self' => true, 'archive' => true, 'archive_col' => 's.archived_at', 'login_toggle' => true,
    'table' => 'staff', 'pk' => 'staff_id', 'order' => 'full_name',
    'fields' => [
        ['name' => 'full_name',     'label' => 'Full name',  'type' => 'text',  'required' => true, 'placeholder' => 'e.g. Dan Flores'],
        ['name' => 'email',         'label' => 'Email (for logging in)', 'type' => 'email', 'required' => true, 'placeholder' => 'e.g. desk@yourresort.com'],
        ['name' => 'resort_id',     'label' => 'Resort',     'type' => 'select', 'none_label' => 'All resorts (super admin)',
         'options_sql' => 'SELECT resort_id, name FROM resort WHERE archived_at IS NULL ORDER BY name'],
        ['name' => 'access',        'label' => 'Access',     'type' => 'select', 'required' => true,
         'options' => ['Admin' => 'Resort admin', 'Staff' => 'Staff']],
        ['name' => 'role',          'label' => 'Job title',  'type' => 'text',  'required' => true, 'placeholder' => 'e.g. Front desk'],
        ['name' => 'password_hash', 'label' => 'Password',   'type' => 'password'],
    ],
    'list_sql' => "SELECT s.staff_id, s.full_name, s.role, s.email, COALESCE(r.name, 'All resorts') AS resort_name,
                          CASE WHEN s.resort_id IS NULL THEN 'Super admin' WHEN s.access = 'Admin' THEN 'Resort admin' ELSE 'Staff' END AS level,
                          s.login_enabled, (s.password_hash IS NOT NULL) AS has_password, s.archived_at
                     FROM staff s LEFT JOIN resort r ON r.resort_id = s.resort_id
                    WHERE {archived}
                    ORDER BY (s.resort_id IS NOT NULL), r.name, s.access, s.full_name",
    'search_keys' => ['role'],
    'columns' => [
        ['label' => 'Name',        'key' => 'full_name'],
        ['label' => 'Email',       'key' => 'email'],
        ['label' => 'Resort',      'key' => 'resort_name'],
        ['label' => 'Level',       'key' => 'level', 'format' => 'badge'],
        ['label' => 'Login',     'key' => 'login_enabled', 'format' => 'switch'],
    ],
]);
