<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Moosh\PluginZip;

final class PluginZipTest extends TestCase
{
    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    public function testPrefersShallowestVersionPhpDirectory(): void
    {
        $tempdir = sys_get_temp_dir() . '/moosh-pluginzip-test-' . uniqid('', true);
        $pluginroot = $tempdir . '/plugin';
        $nestedroot = $pluginroot . '/nested';

        mkdir($nestedroot, 0777, true);
        file_put_contents($pluginroot . '/version.php', '<?php');
        file_put_contents($nestedroot . '/version.php', '<?php');

        try {
            $this->assertSame($pluginroot, PluginZip::findPluginRootDir($tempdir));
        } finally {
            $this->removeDir($tempdir);
        }
    }

    public function testWorksWhenVersionPhpIsAtTheTopLevel(): void
    {
        $tempdir = sys_get_temp_dir() . '/moosh-pluginzip-test-' . uniqid('', true);
        mkdir($tempdir, 0777, true);
        file_put_contents($tempdir . '/version.php', '<?php');

        try {
            $this->assertSame($tempdir, PluginZip::findPluginRootDir($tempdir));
        } finally {
            $this->removeDir($tempdir);
        }
    }

    public function testThrowsWhenNoVersionPhpExistsAnywhere(): void
    {
        $tempdir = sys_get_temp_dir() . '/moosh-pluginzip-test-' . uniqid('', true);
        mkdir($tempdir, 0777, true);
        file_put_contents($tempdir . '/readme.txt', 'not a plugin');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/no version\.php found/');
            PluginZip::findPluginRootDir($tempdir);
        } finally {
            $this->removeDir($tempdir);
        }
    }
}
