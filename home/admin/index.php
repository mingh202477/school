<?php
require_once __DIR__ . '/../../app/models/auth.php';
if (auth_is_logged_in()) {
    header('Location: ' . (auth_is_admin() ? 'dashboard.php' : '../index.php'));
} else {
    header('Location: ../auth/login.php');
}
exit;