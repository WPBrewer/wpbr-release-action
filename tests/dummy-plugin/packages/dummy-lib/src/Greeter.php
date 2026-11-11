<?php

/**
 * Tiny library so Strauss has a namespace to prefix.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace WpbrDummyLib;

final class Greeter
{
    public static function hello(): string
    {
        return 'hello';
    }
}
