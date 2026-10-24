<?php

declare(strict_types=1);

namespace Specrig\Tests;

final class Temp
{
    public static function dir(): string
    {
        $dir = sys_get_temp_dir() . '/specrig-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        return realpath($dir);
    }
}
