<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Moosh\Command\Generic\Plugin\PluginListUpdate;

final class PluginListUpdateTest extends TestCase
{
    /**
     * Build a PluginListUpdate instance without running its constructor -
     * that would pull in GetOptionKit (not vendored in a plain checkout and
     * not needed for any of the logic under test here).
     */
    private function makeCommand(): PluginListUpdate
    {
        return (new \ReflectionClass(PluginListUpdate::class))->newInstanceWithoutConstructor();
    }

    private function callProtected(PluginListUpdate $command, string $methodName, array $args = [])
    {
        $method = new \ReflectionMethod($command, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($command, $args);
    }

    private function setProtected(PluginListUpdate $command, string $propertyName, $value): void
    {
        $property = new \ReflectionProperty($command, $propertyName);
        $property->setAccessible(true);
        $property->setValue($command, $value);
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/moosh-plugin-list-update-test-' . uniqid('', true);
        mkdir($dir, 0777, true);
        return $dir;
    }

    // --- readVersionFile() / writeVersionFile() -----------------------------

    public function testReadVersionFileReturnsNullWhenMissing(): void
    {
        $command = $this->makeCommand();
        $this->assertNull($this->callProtected($command, 'readVersionFile', ['/does/not/exist/version']));
    }

    public function testReadVersionFileReturnsNullWhenEmpty(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version', '   ');
            $command = $this->makeCommand();
            $this->assertNull($this->callProtected($command, 'readVersionFile', [$dir . '/version']));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testReadVersionFileTrimsContent(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version', "2024010100\r\n");
            $command = $this->makeCommand();
            $this->assertSame('2024010100', $this->callProtected($command, 'readVersionFile', [$dir . '/version']));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testWriteVersionFileWritesTrailingNewline(): void
    {
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $this->callProtected($command, 'writeVersionFile', [$dir . '/version', '2024010100']);
            $this->assertSame("2024010100\n", file_get_contents($dir . '/version'));
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- applyVersion() ------------------------------------------------------

    public function testApplyVersionCreatesMissingFile(): void
    {
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'applyVersion', ['mod_board', $dir . '/version', null, '2024010100', false]);

            $this->assertStringStartsWith('CREATE ', $result);
            $this->assertSame("2024010100\n", file_get_contents($dir . '/version'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyVersionDryRunDoesNotCreateFile(): void
    {
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'applyVersion', ['mod_board', $dir . '/version', null, '2024010100', true]);

            $this->assertStringStartsWith('WOULD CREATE ', $result);
            $this->assertFileDoesNotExist($dir . '/version');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyVersionUpdatesOlderVersion(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version', '2023010100');
            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'applyVersion', ['mod_board', $dir . '/version', '2023010100', '2024010100', false]);

            $this->assertStringStartsWith('UPDATE ', $result);
            $this->assertSame("2024010100\n", file_get_contents($dir . '/version'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyVersionDryRunDoesNotUpdateFile(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version', '2023010100');
            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'applyVersion', ['mod_board', $dir . '/version', '2023010100', '2024010100', true]);

            $this->assertStringStartsWith('WOULD UPDATE ', $result);
            $this->assertSame('2023010100', file_get_contents($dir . '/version'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyVersionLeavesFileAloneWhenAlreadyLatest(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version', '2024010100');
            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'applyVersion', ['mod_board', $dir . '/version', '2024010100', '2024010100', false]);

            $this->assertStringStartsWith('OK     ', $result);
            $this->assertSame('2024010100', file_get_contents($dir . '/version'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyVersionNeverDowngradesANewerLocalVersion(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version', '2025010100');
            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'applyVersion', ['mod_board', $dir . '/version', '2025010100', '2024010100', false]);

            $this->assertStringStartsWith('SKIP   ', $result);
            $this->assertSame('2025010100', file_get_contents($dir . '/version'));
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- updateStandardComponent() : pinned/support-status handling ----------

    public function testUpdateStandardComponentSkipsWhenPinnedToZero(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version', '0');
            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'updateStandardComponent', ['mod_board', $dir, false]);

            $this->assertStringContainsString('pinned to version 0', $result);
            $this->assertSame('0', file_get_contents($dir . '/version'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testUpdateStandardComponentWritesSupportStatusWhenUnsupported(): void
    {
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $this->setProtected($command, 'moodlerelease', '4.9');
            $this->setProtected($command, 'pluginsdata', $this->makePluginsData([
                ['component' => 'mod_board', 'versions' => [
                    ['version' => 2024010100, 'releases' => ['4.3']],
                ]],
            ]));

            $result = $this->callProtected($command, 'updateStandardComponent', ['mod_board', $dir, false]);

            $this->assertStringStartsWith('SKIP   ', $result);
            $this->assertStringContainsString('no version supports Moodle 4.9', $result);
            $this->assertSame(
                "not supported for moodle core 4.9\n",
                file_get_contents($dir . '/support_status')
            );
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testUpdateStandardComponentClearsStaleSupportStatusOnceSupportedAgain(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/support_status', "not supported for moodle core 4.2\n");

            $command = $this->makeCommand();
            $this->setProtected($command, 'moodlerelease', '4.3');
            $this->setProtected($command, 'pluginsdata', $this->makePluginsData([
                ['component' => 'mod_board', 'versions' => [
                    ['version' => 2024010100, 'releases' => ['4.3']],
                ]],
            ]));
            // Only support-status clearing is under test here - the fixture
            // above has no downloadurl, so skip checksum reconciliation
            // (which would otherwise attempt a real download).
            $this->setProtected($command, 'expandedOptions', ['no-checksum' => true]);

            $this->callProtected($command, 'updateStandardComponent', ['mod_board', $dir, false]);

            $this->assertFileDoesNotExist($dir . '/support_status');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testUpdateStandardComponentThrowsWhenComponentNotInPluginsJson(): void
    {
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $this->setProtected($command, 'moodlerelease', '4.3');
            $this->setProtected($command, 'pluginsdata', $this->makePluginsData([]));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/not found in plugins\.json/');
            $this->callProtected($command, 'updateStandardComponent', ['mod_missing', $dir, false]);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- findLatestCompatibleVersion() / isSupportedByMoodle() ---------------

    private function makePluginsData(array $plugins): \stdClass
    {
        $data = new \stdClass();
        $data->plugins = array_map(function ($plugin) {
            $obj = new \stdClass();
            $obj->component = $plugin['component'];
            $obj->versions = array_map(function ($version) {
                $vObj = new \stdClass();
                $vObj->version = $version['version'];
                $vObj->supportedmoodles = array_map(function ($release) {
                    $sObj = new \stdClass();
                    $sObj->release = $release;
                    return $sObj;
                }, $version['releases']);
                return $vObj;
            }, $plugin['versions']);
            return $obj;
        }, $plugins);
        return $data;
    }

    public function testFindLatestCompatibleVersionPicksHighestSupportedVersion(): void
    {
        $command = $this->makeCommand();
        $this->setProtected($command, 'moodlerelease', '4.3');
        $this->setProtected($command, 'pluginsdata', $this->makePluginsData([
            ['component' => 'mod_board', 'versions' => [
                ['version' => 2023010100, 'releases' => ['4.3', '4.2']],
                ['version' => 2024010100, 'releases' => ['4.3']],
                ['version' => 2025010100, 'releases' => ['4.4']],
            ]],
        ]));

        $result = $this->callProtected($command, 'findLatestCompatibleVersion', ['mod_board']);

        $this->assertSame(2024010100, $result->version);
    }

    public function testFindLatestCompatibleVersionReturnsNullWhenNoneSupported(): void
    {
        $command = $this->makeCommand();
        $this->setProtected($command, 'moodlerelease', '4.9');
        $this->setProtected($command, 'pluginsdata', $this->makePluginsData([
            ['component' => 'mod_board', 'versions' => [
                ['version' => 2024010100, 'releases' => ['4.3']],
            ]],
        ]));

        $this->assertNull($this->callProtected($command, 'findLatestCompatibleVersion', ['mod_board']));
    }

    public function testFindLatestCompatibleVersionThrowsWhenComponentMissing(): void
    {
        $command = $this->makeCommand();
        $this->setProtected($command, 'moodlerelease', '4.3');
        $this->setProtected($command, 'pluginsdata', $this->makePluginsData([]));

        $this->expectException(\RuntimeException::class);
        $this->callProtected($command, 'findLatestCompatibleVersion', ['mod_missing']);
    }

    // --- discoverComponents() -------------------------------------------------

    public function testDiscoverComponentsListsOnlySubdirectories(): void
    {
        $dir = $this->makeTempDir();
        try {
            mkdir($dir . '/mod_board');
            mkdir($dir . '/block_fastnav');
            mkdir($dir . '/.git');
            file_put_contents($dir . '/README.md', 'not a plugin dir');

            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'discoverComponents', [$dir]);

            $this->assertSame(['block_fastnav', 'mod_board'], $result);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDiscoverComponentsReturnsEmptyArrayForEmptyDirectory(): void
    {
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $this->assertSame([], $this->callProtected($command, 'discoverComponents', [$dir]));
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- updatePackageComponent() ---------------------------------------------

    public function testUpdatePackageComponentThrowsWhenScriptMissing(): void
    {
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/could not find package_foo\/bin\/get_latest_plugin_version\.sh/');
            $this->callProtected($command, 'updatePackageComponent', ['package_foo', $dir, $dir, false]);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testUpdatePackageComponentThrowsWhenScriptNotExecutable(): void
    {
        $dir = $this->makeTempDir();
        try {
            mkdir($dir . '/bin', 0777, true);
            file_put_contents($dir . '/bin/get_latest_plugin_version.sh', "#!/bin/sh\necho 1\n");
            chmod($dir . '/bin/get_latest_plugin_version.sh', 0644);

            $command = $this->makeCommand();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/is not executable/');
            $this->callProtected($command, 'updatePackageComponent', ['package_foo', $dir, $dir, false]);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testUpdatePackageComponentSkipsWhenPinnedToZero(): void
    {
        $dir = $this->makeTempDir();
        try {
            mkdir($dir . '/bin', 0777, true);
            file_put_contents($dir . '/bin/get_latest_plugin_version.sh', "#!/bin/sh\necho 999999999\n");
            chmod($dir . '/bin/get_latest_plugin_version.sh', 0755);
            file_put_contents($dir . '/version', '0');

            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'updatePackageComponent', ['package_foo', $dir, $dir, false]);

            $this->assertStringContainsString('pinned to version 0', $result);
            $this->assertSame('0', file_get_contents($dir . '/version'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testUpdatePackageComponentRunsScriptAndUpdatesVersion(): void
    {
        $dir = $this->makeTempDir();
        try {
            mkdir($dir . '/bin', 0777, true);
            file_put_contents($dir . '/bin/get_latest_plugin_version.sh', "#!/bin/sh\necho 2024010100\n");
            chmod($dir . '/bin/get_latest_plugin_version.sh', 0755);
            file_put_contents($dir . '/version', '2023010100');

            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'updatePackageComponent', ['package_foo', $dir, $dir, false]);

            $this->assertStringStartsWith('UPDATE ', $result);
            $this->assertSame("2024010100\n", file_get_contents($dir . '/version'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testUpdatePackageComponentThrowsOnNonIntegerOutput(): void
    {
        $dir = $this->makeTempDir();
        try {
            mkdir($dir . '/bin', 0777, true);
            file_put_contents($dir . '/bin/get_latest_plugin_version.sh', "#!/bin/sh\necho not-a-version\n");
            chmod($dir . '/bin/get_latest_plugin_version.sh', 0755);

            $command = $this->makeCommand();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/did not report a valid integer version/');
            $this->callProtected($command, 'updatePackageComponent', ['package_foo', $dir, $dir, false]);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- reconcileChecksum() ---------------------------------------------
    // Only the offline-safe branches are covered here: anything that would
    // genuinely need to download a zip is left to manual/integration
    // testing, since PluginCache/file_get_contents/PluginChecksum aren't
    // mockable through this reflection-based harness without touching real
    // network I/O.

    public function testReconcileChecksumSkipsDownloadWhenAlreadyPinnedAndNotForced(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/checksum', "abc123\n");

            $command = $this->makeCommand();
            // An unreachable/invalid URL - if this method attempted to
            // download it, we'd get a RuntimeException (or, on some setups,
            // a hang/warning) instead of a clean null return.
            $result = $this->callProtected($command, 'reconcileChecksum', [
                'mod_board', $dir, '2024010100', 'http://invalid.invalid/nope.zip', false,
            ]);

            $this->assertNull($result);
            $this->assertSame("abc123\n", file_get_contents($dir . '/checksum'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testReconcileChecksumRedownloadsWhenForcedEvenIfAlreadyPinned(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/checksum', "stale-checksum-for-old-version\n");

            $command = $this->makeCommand();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/could not pin checksum for 9999999999/');
            // forcerecompute=true -> must not short-circuit on the existing
            // (now-stale) checksum file, so this has to attempt (and fail) a
            // real download. Uses a made-up version number so a stale,
            // persistently-cached zip from an unrelated real run can't
            // accidentally short-circuit PluginCache::fetch() and make the
            // download "succeed".
            $this->callProtected($command, 'reconcileChecksum', [
                'mod_board', $dir, '9999999999', 'http://invalid.invalid/nope.zip', true,
            ]);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testUpdatePackageComponentThrowsOnNonZeroExit(): void
    {
        $dir = $this->makeTempDir();
        try {
            mkdir($dir . '/bin', 0777, true);
            file_put_contents($dir . '/bin/get_latest_plugin_version.sh', "#!/bin/sh\necho failure >&2\nexit 3\n");
            chmod($dir . '/bin/get_latest_plugin_version.sh', 0755);

            $command = $this->makeCommand();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/exited with status 3/');
            $this->callProtected($command, 'updatePackageComponent', ['package_foo', $dir, $dir, false]);
        } finally {
            $this->removeDir($dir);
        }
    }
}
