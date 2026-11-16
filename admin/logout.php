<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

if (!defined('ADMIN_ROUTED_ENTRY')) {
    http_response_code(404);
    exit('404 Not Found');
}

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    logout_admin(true);
    start_secure_session();
    set_flash('success', 'You have been signed out securely.');
    redirect_to(admin_url('login.php'));
}

redirect_to(admin_url('dashboard.php'));
