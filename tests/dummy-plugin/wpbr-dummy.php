<?php

/**
 * Plugin Name: WPBR Dummy
 * Description: Fixture plugin for the release workflow self-test.
 * Version: 1.2.3
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Tested up to: 6.7
 * License: GPL-3.0-or-later
 * Text Domain: wpbr-dummy
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$wpbr_dummy_autoload = __DIR__ . '/vendor-prefixed/autoload.php';
if (is_file($wpbr_dummy_autoload)) {
    require_once $wpbr_dummy_autoload;
}

require_once __DIR__ . '/includes/class-dummy.php';
