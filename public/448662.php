<?php
declare(strict_types=1);

/**
 * Public Web Root Front-Controller for Apache/Nginx Rewrites of /448662.
 * Direct requests to /448662.php are rejected by includes/admin-router.php with 404.
 */
require_once dirname(__DIR__) . '/includes/admin-router.php';
