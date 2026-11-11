<?php

/**
 * Passing test command for the dummy plugin.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

require dirname(__DIR__) . '/includes/class-dummy.php';

if (Wpbr_Dummy::ping() !== 'ok') {
    fwrite(STDERR, "ping failed\n");
    exit(1);
}

echo "tests ok\n";
