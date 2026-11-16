<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

if (!defined('ADMIN_ROUTED_ENTRY')) {
    http_response_code(404);
    exit('404 Not Found');
}

start_secure_session();

if (current_admin()) {
    redirect_to(admin_url('dashboard.php'));
}
redirect_to(admin_url('login.php'));
