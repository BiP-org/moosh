<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Moosh\Command\Generic\Plugin\PluginClamscan;

final class PluginClamscanTest extends TestCase
{
    /**
     * Build a PluginClamscan instance without running its constructor -
     * that would pull in GetOptionKit (not vendored in a plain checkout and
     * not needed for any of the logic under test here).
     */
    private function makeCommand(): PluginClamscan
    {
        return (new \ReflectionClass(PluginClamscan::class))->newInstanceWithoutConstructor();
    }

    private function callProtected(PluginClamscan $command, string $methodName, array $args = [])
    {
        $method = new \ReflectionMethod($command, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($command, $args);
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    // --- resolveClamscanBinaryFromLookup() ----------------------------------

    public function testResolveClamscanBinaryFromLookupReturnsTrimmedPath(): void
    {
        $command = $this->makeCommand();
        $result = $this->callProtected($command, 'resolveClamscanBinaryFromLookup', ["/usr/bin/clamscan\n"]);
        $this->assertSame('/usr/bin/clamscan', $result);
    }

    public function testResolveClamscanBinaryFromLookupReturnsNullWhenEmpty(): void
    {
        $command = $this->makeCommand();
        $this->assertNull($this->callProtected($command, 'resolveClamscanBinaryFromLookup', ['']));
    }

    public function testResolveClamscanBinaryFromLookupReturnsNullWhenOnlyWhitespace(): void
    {
        $command = $this->makeCommand();
        $this->assertNull($this->callProtected($command, 'resolveClamscanBinaryFromLookup', ["   \n"]));
    }

    public function testResolveClamscanBinaryFromLookupReturnsNullWhenNull(): void
    {
        $command = $this->makeCommand();
        $this->assertNull($this->callProtected($command, 'resolveClamscanBinaryFromLookup', [null]));
    }

    // --- resolvePluginRootFromCwd() -----------------------------------------

    public function testResolvePluginRootFromCwdReturnsCwdWhenVersionPhpExists(): void
    {
        $tempdir = sys_get_temp_dir() . '/moosh-clamscan-cwd-test-' . uniqid('', true);
        mkdir($tempdir, 0777, true);
        file_put_contents($tempdir . '/version.php', '<?php');

        try {
            $command = $this->makeCommand();
            $this->assertSame($tempdir, $this->callProtected($command, 'resolvePluginRootFromCwd', [$tempdir]));
        } finally {
            $this->removeDir($tempdir);
        }
    }

    public function testResolvePluginRootFromCwdThrowsWhenNoVersionPhp(): void
    {
        $tempdir = sys_get_temp_dir() . '/moosh-clamscan-cwd-test-' . uniqid('', true);
        mkdir($tempdir, 0777, true);

        try {
            $command = $this->makeCommand();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/no version\.php found/');
            $this->callProtected($command, 'resolvePluginRootFromCwd', [$tempdir]);
        } finally {
            $this->removeDir($tempdir);
        }
    }

    public function testResolvePluginRootFromCwdHandlesTrailingSlash(): void
    {
        $tempdir = sys_get_temp_dir() . '/moosh-clamscan-cwd-test-' . uniqid('', true);
        mkdir($tempdir, 0777, true);
        file_put_contents($tempdir . '/version.php', '<?php');

        try {
            $command = $this->makeCommand();
            $this->assertSame($tempdir, $this->callProtected($command, 'resolvePluginRootFromCwd', [$tempdir . '/']));
        } finally {
            $this->removeDir($tempdir);
        }
    }

    // --- buildClamscanArgs() -------------------------------------------------

    public function testBuildClamscanArgsBaseCase(): void
    {
        $command = $this->makeCommand();
        $args = $this->callProtected($command, 'buildClamscanArgs', ['/usr/bin/clamscan', '/tmp/plugin', []]);

        $this->assertSame(
            [escapeshellarg('/usr/bin/clamscan'), '-r', escapeshellarg('/tmp/plugin')],
            $args
        );
    }

    public function testBuildClamscanArgsWithSingleDatabase(): void
    {
        $command = $this->makeCommand();
        $args = $this->callProtected($command, 'buildClamscanArgs', [
            '/usr/bin/clamscan', '/tmp/plugin', ['database' => ['/rules/yara']],
        ]);

        $this->assertSame(
            [escapeshellarg('/usr/bin/clamscan'), '-r', '-d', escapeshellarg('/rules/yara'), escapeshellarg('/tmp/plugin')],
            $args
        );
    }

    public function testBuildClamscanArgsWithMultipleDatabases(): void
    {
        $command = $this->makeCommand();
        $args = $this->callProtected($command, 'buildClamscanArgs', [
            '/usr/bin/clamscan', '/tmp/plugin', ['database' => ['/rules/a', '/rules/b']],
        ]);

        $this->assertSame(
            [
                escapeshellarg('/usr/bin/clamscan'), '-r',
                '-d', escapeshellarg('/rules/a'),
                '-d', escapeshellarg('/rules/b'),
                escapeshellarg('/tmp/plugin'),
            ],
            $args
        );
    }

    public function testBuildClamscanArgsWithInfectedFlag(): void
    {
        $command = $this->makeCommand();
        $args = $this->callProtected($command, 'buildClamscanArgs', [
            '/usr/bin/clamscan', '/tmp/plugin', ['infected' => true],
        ]);

        $this->assertContains('-i', $args);
    }

    public function testBuildClamscanArgsOmitsInfectedFlagWhenFalsy(): void
    {
        $command = $this->makeCommand();
        $args = $this->callProtected($command, 'buildClamscanArgs', [
            '/usr/bin/clamscan', '/tmp/plugin', ['infected' => false],
        ]);

        $this->assertNotContains('-i', $args);
    }

    public function testBuildClamscanArgsWithLogOption(): void
    {
        $command = $this->makeCommand();
        $args = $this->callProtected($command, 'buildClamscanArgs', [
            '/usr/bin/clamscan', '/tmp/plugin', ['log' => '/tmp/scan.log'],
        ]);

        $this->assertContains('--log=' . escapeshellarg('/tmp/scan.log'), $args);
    }

    public function testBuildClamscanArgsCombinesAllOptions(): void
    {
        $command = $this->makeCommand();
        $args = $this->callProtected($command, 'buildClamscanArgs', [
            '/usr/bin/clamscan',
            '/tmp/plugin',
            ['database' => ['/rules/a'], 'infected' => true, 'log' => '/tmp/scan.log'],
        ]);

        $this->assertSame(
            [
                escapeshellarg('/usr/bin/clamscan'), '-r',
                '-d', escapeshellarg('/rules/a'),
                '-i',
                '--log=' . escapeshellarg('/tmp/scan.log'),
                escapeshellarg('/tmp/plugin'),
            ],
            $args
        );
    }

    public function testBuildClamscanArgsEscapesSpecialCharactersInPluginRoot(): void
    {
        $command = $this->makeCommand();
        $dangerous = '/tmp/plugin; rm -rf /';
        $args = $this->callProtected($command, 'buildClamscanArgs', ['/usr/bin/clamscan', $dangerous, []]);

        $this->assertContains(escapeshellarg($dangerous), $args);
        // The raw, unescaped dangerous path must never appear verbatim.
        $this->assertNotContains($dangerous, $args);
    }

    public function testBuildClamscanArgsAcceptsLegacyStringDatabaseValue(): void
    {
        // Defensive: expandedOptions could plausibly hand back a bare string
        // rather than an array if only one -d was given and something
        // upstream didn't normalize it. Shouldn't crash or silently drop it.
        $command = $this->makeCommand();
        $args = $this->callProtected($command, 'buildClamscanArgs', [
            '/usr/bin/clamscan', '/tmp/plugin', ['database' => '/rules/single'],
        ]);

        $this->assertContains('-d', $args);
        $this->assertContains(escapeshellarg('/rules/single'), $args);
    }

    public function testBuildClamscanArgsIgnoresTokenOption(): void
    {
        // --token is only relevant to the download step (Marketplace auth),
        // never to the clamscan invocation itself.
        $command = $this->makeCommand();
        $args = $this->callProtected($command, 'buildClamscanArgs', [
            '/usr/bin/clamscan', '/tmp/plugin', ['token' => 'super-secret-token'],
        ]);

        $this->assertSame(
            [escapeshellarg('/usr/bin/clamscan'), '-r', escapeshellarg('/tmp/plugin')],
            $args
        );
        foreach ($args as $arg) {
            $this->assertStringNotContainsString('super-secret-token', $arg);
        }
    }

    // --- resolvePluginVersion() ----------------------------------------------

    private function makePluginsData(array $plugins): \stdClass
    {
        $data = new \stdClass();
        $data->plugins = array_map(function ($plugin) {
            $obj = new \stdClass();
            $obj->component = $plugin['component'];
            $obj->versions = array_map(function ($version) {
                $vObj = new \stdClass();
                $vObj->version = $version['version'];
                $vObj->downloadurl = $version['downloadurl'];
                return $vObj;
            }, $plugin['versions']);
            return $obj;
        }, $plugins);
        return $data;
    }

    public function testResolvePluginVersionReturnsExactRequestedVersion(): void
    {
        $data = $this->makePluginsData([
            ['component' => 'mod_board', 'versions' => [
                ['version' => 2023010100, 'downloadurl' => 'https://example.com/old.zip'],
                ['version' => 2024010100, 'downloadurl' => 'https://example.com/new.zip'],
            ]],
        ]);

        $command = $this->makeCommand();
        $version = $this->callProtected($command, 'resolvePluginVersion', [$data, 'mod_board', '2023010100']);

        $this->assertSame('https://example.com/old.zip', $version->downloadurl);
    }

    public function testResolvePluginVersionReturnsNewestWhenNoVersionRequested(): void
    {
        $data = $this->makePluginsData([
            ['component' => 'mod_board', 'versions' => [
                ['version' => 2023010100, 'downloadurl' => 'https://example.com/old.zip'],
                ['version' => 2024010100, 'downloadurl' => 'https://example.com/new.zip'],
            ]],
        ]);

        $command = $this->makeCommand();
        $version = $this->callProtected($command, 'resolvePluginVersion', [$data, 'mod_board', null]);

        $this->assertSame('https://example.com/new.zip', $version->downloadurl);
    }

    public function testResolvePluginVersionThrowsWhenComponentNotFound(): void
    {
        $data = $this->makePluginsData([
            ['component' => 'mod_board', 'versions' => [
                ['version' => 2024010100, 'downloadurl' => 'https://example.com/new.zip'],
            ]],
        ]);

        $command = $this->makeCommand();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Couldn\'t find mod_missing/');
        $this->callProtected($command, 'resolvePluginVersion', [$data, 'mod_missing', null]);
    }

    public function testResolvePluginVersionThrowsWhenRequestedVersionNotFound(): void
    {
        $data = $this->makePluginsData([
            ['component' => 'mod_board', 'versions' => [
                ['version' => 2024010100, 'downloadurl' => 'https://example.com/new.zip'],
            ]],
        ]);

        $command = $this->makeCommand();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/999999 of mod_board not found/');
        $this->callProtected($command, 'resolvePluginVersion', [$data, 'mod_board', '999999']);
    }
}