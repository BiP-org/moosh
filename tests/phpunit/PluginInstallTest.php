<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PluginInstallTest extends TestCase
{
    public function testFindPluginRootDirPrefersShallowestVersionPhpDirectory(): void
    {
        $tempdir = sys_get_temp_dir() . '/moosh-plugininstall-test-' . uniqid('', true);
        $pluginroot = $tempdir . '/plugin';
        $nestedroot = $pluginroot . '/nested';

        mkdir($pluginroot, 0777, true);
        mkdir($nestedroot, 0777, true);
        file_put_contents($pluginroot . '/version.php', '<?php');
        file_put_contents($nestedroot . '/version.php', '<?php');

        try {
            $command = new \Moosh\Command\Generic\Plugin\PluginInstall();
            $method = new \ReflectionMethod($command, 'find_plugin_root_dir');
            $method->setAccessible(true);

            $this->assertSame($pluginroot, $method->invoke($command, $tempdir));
        } finally {
            @unlink($pluginroot . '/version.php');
            @unlink($nestedroot . '/version.php');
            @rmdir($nestedroot);
            @rmdir($pluginroot);
            @rmdir($tempdir);
        }
    }
}
