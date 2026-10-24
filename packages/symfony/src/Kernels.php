<?php

declare(strict_types=1);

namespace Specrig\Symfony;

use Symfony\Component\HttpKernel\KernelInterface;

// PHP names a parent class only in code, so the service's kernel is subclassed in
// a file of the run's own.
final class Kernels
{
    /**
     * @param class-string<KernelInterface> $kernel
     * @return class-string<KernelInterface>
     */
    public static function rigged(string $kernel, string $directory): string
    {
        if (!class_exists($kernel)) {
            throw new \LogicException("{$kernel}, the kernel the harness boots, does not load: name the service's own in kernel()");
        }
        $class = 'SpecrigKernel_' . md5($kernel);
        if (!class_exists($class, false)) {
            $file = "{$directory}/{$class}.php";
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($file, "<?php\n\nfinal class {$class} extends \\" . ltrim($kernel, '\\') . "\n{\n    use \\" . Rigged::class . ";\n}\n");
            require $file;
        }
        return $class;
    }

    public static function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
