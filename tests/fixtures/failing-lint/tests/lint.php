<?php

/**
 * Runtime failure. php -l still passes.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

fwrite(STDERR, "lint failed\n");
exit(1);
