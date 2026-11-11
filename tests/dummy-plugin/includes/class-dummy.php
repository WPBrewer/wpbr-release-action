<?php

/**
 * Dummy plugin class loaded by the self-test.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

final class Wpbr_Dummy
{
    public static function ping(): string
    {
        return 'ok';
    }
}
