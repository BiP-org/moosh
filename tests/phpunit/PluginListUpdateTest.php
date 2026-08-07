<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Moosh\Command\Generic\Plugin\PluginListUpdate;
use Moosh\Command\Generic\Plugin\MarketplaceUnauthorizedException;

final class PluginListUpdateTest extends TestCase
{
    /**
     * @var resource|null process handle for the loopback 401 server started
     *   by startUnauthorizedServer(), so tests can tear it down again.
     */
    private static $unauthorizedServerProcess = null;

    /** @var string|null base URL of the running loopback 401 server, eg. 'http://127.0.0.1:51234' */
    private static $unauthorizedServerBaseUrl = null;

    public static function tearDownAfterClass(): void
    {
        self::stopUnauthorizedServer();
    }

    /**
     * Start (once, lazily, reused across tests) a PHP built-in web server on
     * 127.0.0.1 that answers every request with the real
     * marketplace.moodle.com 401 response - see
     * tests/fixtures/marketplace-401-server.php. Loopback-only, so this
     * doesn't require or touch the network.
     *
     * @return string base URL, eg. 'http://127.0.0.1:51234'
     */
    private static function startUnauthorizedServer(): string
    {
        if (self::$unauthorizedServerBaseUrl !== null) {
            return self::$unauthorizedServerBaseUrl;
        }

        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::markTestSkipped("Could not reserve a loopback port for the test HTTP server: $errstr");
        }
        $name = stream_socket_get_name($socket, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($socket);

        $router = __DIR__ . '/../fixtures/marketplace-401-server.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router);
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
        if (!is_resource($process)) {
            self::markTestSkipped('Could not start the PHP built-in web server for the test fixture.');
        }

        $baseurl = "http://127.0.0.1:$port";
        // Give the server a moment to start listening.
        for ($i = 0; $i < 50; $i++) {
            $conn = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 0.1);
            if ($conn) {
                fclose($conn);
                self::$unauthorizedServerProcess = $process;
                self::$unauthorizedServerBaseUrl = $baseurl;
                return $baseurl;
            }
            usleep(50000);
        }

        proc_terminate($process);
        self::markTestSkipped('The PHP built-in web server for the test fixture never started listening.');
    }

    private static function stopUnauthorizedServer(): void
    {
        if (self::$unauthorizedServerProcess !== null && is_resource(self::$unauthorizedServerProcess)) {
            proc_terminate(self::$unauthorizedServerProcess);
            proc_close(self::$unauthorizedServerProcess);
        }
        self::$unauthorizedServerProcess = null;
        self::$unauthorizedServerBaseUrl = null;
    }

    /** @var string|false original $CI value, saved/restored around tests that override it */
    private $originalCiEnv = false;
    private $originalCiEnvWasSet = false;

    /** @var string temp dir used as an isolated MOOSH_CACHE_DIR for this test, so a
     *   stale cached zip from an unrelated real run can never short-circuit
     *   PluginCache::fetch() and mask what a test is actually exercising. */
    private $cacheDir;
    private $originalCacheDirEnv = false;
    private $originalCacheDirEnvWasSet = false;

    protected function setUp(): void
    {
        $this->originalCiEnvWasSet = getenv('CI') !== false;
        $this->originalCiEnv = getenv('CI');

        $this->originalCacheDirEnvWasSet = getenv('MOOSH_CACHE_DIR') !== false;
        $this->originalCacheDirEnv = getenv('MOOSH_CACHE_DIR');
        $this->cacheDir = $this->makeTempDir();
        putenv('MOOSH_CACHE_DIR=' . $this->cacheDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalCiEnvWasSet) {
            putenv('CI=' . $this->originalCiEnv);
        } else {
            putenv('CI');
        }

        if ($this->originalCacheDirEnvWasSet) {
            putenv('MOOSH_CACHE_DIR=' . $this->originalCacheDirEnv);
        } else {
            putenv('MOOSH_CACHE_DIR');
        }
        $this->removeDir($this->cacheDir);
    }

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

    // --- willChangeVersion() ---------------------------------------------

    public function testWillChangeVersionTrueWhenVersionFileMissing(): void
    {
        $command = $this->makeCommand();
        $this->assertTrue($this->callProtected($command, 'willChangeVersion', [null, '2024010100']));
    }

    public function testWillChangeVersionFalseWhenAlreadyLatest(): void
    {
        $command = $this->makeCommand();
        $this->assertFalse($this->callProtected($command, 'willChangeVersion', ['2024010100', '2024010100']));
    }

    public function testWillChangeVersionFalseWhenLocalIsNewer(): void
    {
        $command = $this->makeCommand();
        $this->assertFalse($this->callProtected($command, 'willChangeVersion', ['2025010100', '2024010100']));
    }

    public function testWillChangeVersionTrueWhenLocalIsOlder(): void
    {
        $command = $this->makeCommand();
        $this->assertTrue($this->callProtected($command, 'willChangeVersion', ['2023010100', '2024010100']));
    }

    // --- isMarketplaceUnauthorizedResponse() ------------------------------

    public function testIsMarketplaceUnauthorizedResponseTrueOn401StatusLine(): void
    {
        $command = $this->makeCommand();
        $headers = ['HTTP/1.1 401 Unauthorized', 'Content-Type: application/json'];
        $this->assertTrue($this->callProtected($command, 'isMarketplaceUnauthorizedResponse', [$headers]));
    }

    public function testIsMarketplaceUnauthorizedResponseTrueOnHttp2StatusLineWithoutReasonPhrase(): void
    {
        $command = $this->makeCommand();
        // Some servers/proxies (notably HTTP/2 responses surfaced by PHP)
        // omit the reason phrase entirely.
        $headers = ['HTTP/2 401'];
        $this->assertTrue($this->callProtected($command, 'isMarketplaceUnauthorizedResponse', [$headers]));
    }

    public function testIsMarketplaceUnauthorizedResponseFalseOn200(): void
    {
        $command = $this->makeCommand();
        $headers = ['HTTP/1.1 200 OK', 'Content-Type: application/zip'];
        $this->assertFalse($this->callProtected($command, 'isMarketplaceUnauthorizedResponse', [$headers]));
    }

    public function testIsMarketplaceUnauthorizedResponseFalseOn404(): void
    {
        $command = $this->makeCommand();
        // Regression guard: must match 401 exactly, not just "contains 401"
        // anywhere - a naive substring check would misfire here.
        $headers = ['HTTP/1.1 404 Not Found', 'X-Debug: version 401 deprecated'];
        $this->assertFalse($this->callProtected($command, 'isMarketplaceUnauthorizedResponse', [$headers]));
    }

    public function testIsMarketplaceUnauthorizedResponseFalseWhenNull(): void
    {
        $command = $this->makeCommand();
        $this->assertFalse($this->callProtected($command, 'isMarketplaceUnauthorizedResponse', [null]));
    }

    public function testIsMarketplaceUnauthorizedResponseFalseOnEmptyArray(): void
    {
        $command = $this->makeCommand();
        $this->assertFalse($this->callProtected($command, 'isMarketplaceUnauthorizedResponse', [[]]));
    }

    // --- isRunningInCi() ---------------------------------------------------

    public function testIsRunningInCiFalseWhenUnset(): void
    {
        putenv('CI');
        $command = $this->makeCommand();
        $this->assertFalse($this->callProtected($command, 'isRunningInCi', []));
    }

    public function testIsRunningInCiTrueWhenSetToTrue(): void
    {
        putenv('CI=true');
        $command = $this->makeCommand();
        $this->assertTrue($this->callProtected($command, 'isRunningInCi', []));
    }

    public function testIsRunningInCiTrueWhenSetToOne(): void
    {
        // GitHub Actions itself sets CI=true, but be lenient about the
        // exact truthy spelling other CI providers might use.
        putenv('CI=1');
        $command = $this->makeCommand();
        $this->assertTrue($this->callProtected($command, 'isRunningInCi', []));
    }

    public function testIsRunningInCiFalseWhenSetToFalse(): void
    {
        putenv('CI=false');
        $command = $this->makeCommand();
        $this->assertFalse($this->callProtected($command, 'isRunningInCi', []));
    }

    public function testIsRunningInCiFalseWhenSetToZero(): void
    {
        putenv('CI=0');
        $command = $this->makeCommand();
        $this->assertFalse($this->callProtected($command, 'isRunningInCi', []));
    }

    // --- marketplaceUnauthorizedAnnotation() / reportMarketplaceUnauthorized() ---

    public function testMarketplaceUnauthorizedAnnotationIsPlainWarningOutsideCi(): void
    {
        putenv('CI');
        $command = $this->makeCommand();
        $result = $this->callProtected($command, 'marketplaceUnauthorizedAnnotation', ['mod_board', 'some reason']);

        $this->assertSame('WARNING mod_board: some reason', $result);
    }

    public function testMarketplaceUnauthorizedAnnotationIsGithubAnnotationUnderCi(): void
    {
        putenv('CI=true');
        $command = $this->makeCommand();
        $result = $this->callProtected($command, 'marketplaceUnauthorizedAnnotation', ['mod_board', 'some reason']);

        $this->assertStringStartsWith('::warning', $result);
        $this->assertStringContainsString('mod_board: some reason', $result);
    }

    public function testReportMarketplaceUnauthorizedKeepsExistingVersionAndWarns(): void
    {
        putenv('CI');
        $command = $this->makeCommand();
        $result = $this->callProtected($command, 'reportMarketplaceUnauthorized', ['tiny_fontfamily', '2026010100', '2026071400']);

        $this->assertStringStartsWith('SKIP   tiny_fontfamily: ', $result);
        $this->assertStringContainsString('keeping local version 2026010100', $result);
        $this->assertStringContainsString("\nWARNING tiny_fontfamily: ", $result);
    }

    public function testReportMarketplaceUnauthorizedReportsUnwrittenWhenNoExistingVersion(): void
    {
        putenv('CI=true');
        $command = $this->makeCommand();
        $result = $this->callProtected($command, 'reportMarketplaceUnauthorized', ['tiny_fontfamily', null, '2026071400']);

        $this->assertStringContainsString('leaving version file unwritten', $result);
        $this->assertStringContainsString('::warning', $result);
    }

    // --- downloadPluginZip() / reconcileChecksum(): real HTTP 401 ---------
    // These spin up a loopback-only PHP built-in web server (see
    // startUnauthorizedServer() / tests/fixtures/marketplace-401-server.php)
    // that answers with the exact status/body marketplace.moodle.com
    // returns for a subscription-only plugin, so the real
    // $http_response_header-based detection path is exercised end to end -
    // no network access required.

    public function testDownloadPluginZipThrowsMarketplaceUnauthorizedOn401(): void
    {
        $baseurl = self::startUnauthorizedServer();
        $command = $this->makeCommand();
        $this->setProtected($command, 'expandedOptions', []);

        $this->expectException(MarketplaceUnauthorizedException::class);
        $this->expectExceptionMessageMatches('/HTTP 401/');
        $this->callProtected($command, 'downloadPluginZip', ['tiny_fontfamily', '2026071400', $baseurl . '/api/plugins/tiny_fontfamily/versions/2026071400/download']);
    }

    public function testReconcileChecksumPropagatesMarketplaceUnauthorizedUnwrapped(): void
    {
        $baseurl = self::startUnauthorizedServer();
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $this->setProtected($command, 'expandedOptions', []);

            try {
                $this->callProtected($command, 'reconcileChecksum', [
                    'tiny_fontfamily', $dir, '2026071400', $baseurl . '/download', true,
                ]);
                $this->fail('Expected a MarketplaceUnauthorizedException.');
            } catch (MarketplaceUnauthorizedException $e) {
                // Must NOT have been rewrapped into a generic RuntimeException
                // with the "could not pin checksum for ..." prefix.
                $this->assertStringNotContainsString('could not pin checksum', $e->getMessage());
            }
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- updateStandardComponent(): end-to-end 401 handling ---------------

    public function testUpdateStandardComponentLeavesVersionUnwrittenOn401(): void
    {
        putenv('CI');
        $baseurl = self::startUnauthorizedServer();
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $this->setProtected($command, 'moodlerelease', '4.3');
            $this->setProtected($command, 'expandedOptions', []);
            $this->setProtected($command, 'pluginsdata', $this->makePluginsDataWithDownloadUrl([
                ['component' => 'tiny_fontfamily', 'downloadurl' => $baseurl . '/download', 'versions' => [
                    ['version' => 2026071400, 'releases' => ['4.3']],
                ]],
            ]));

            // No version file yet - a plain run would CREATE it.
            $result = $this->callProtected($command, 'updateStandardComponent', ['tiny_fontfamily', $dir, false]);

            $this->assertStringStartsWith('SKIP   tiny_fontfamily: ', $result);
            $this->assertStringContainsString('leaving version file unwritten', $result);
            $this->assertStringContainsString('WARNING tiny_fontfamily: ', $result);
            $this->assertFileDoesNotExist($dir . '/version');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testUpdateStandardComponentKeepsExistingVersionOn401(): void
    {
        putenv('CI');
        $baseurl = self::startUnauthorizedServer();
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version', '2025010100');

            $command = $this->makeCommand();
            $this->setProtected($command, 'moodlerelease', '4.3');
            $this->setProtected($command, 'expandedOptions', []);
            $this->setProtected($command, 'pluginsdata', $this->makePluginsDataWithDownloadUrl([
                ['component' => 'tiny_fontfamily', 'downloadurl' => $baseurl . '/download', 'versions' => [
                    ['version' => 2026071400, 'releases' => ['4.3']],
                ]],
            ]));

            $result = $this->callProtected($command, 'updateStandardComponent', ['tiny_fontfamily', $dir, false]);

            $this->assertStringStartsWith('SKIP   tiny_fontfamily: ', $result);
            $this->assertStringContainsString('keeping local version 2025010100', $result);
            $this->assertSame('2025010100', file_get_contents($dir . '/version'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testUpdateStandardComponentEmitsGithubAnnotationUnderCi(): void
    {
        putenv('CI=true');
        $baseurl = self::startUnauthorizedServer();
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $this->setProtected($command, 'moodlerelease', '4.3');
            $this->setProtected($command, 'expandedOptions', []);
            $this->setProtected($command, 'pluginsdata', $this->makePluginsDataWithDownloadUrl([
                ['component' => 'tiny_fontfamily', 'downloadurl' => $baseurl . '/download', 'versions' => [
                    ['version' => 2026071400, 'releases' => ['4.3']],
                ]],
            ]));

            $result = $this->callProtected($command, 'updateStandardComponent', ['tiny_fontfamily', $dir, false]);

            $this->assertStringContainsString('::warning', $result);
            $this->assertFileDoesNotExist($dir . '/version');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testUpdateStandardComponentDoesNotCheckReachabilityWithNoChecksum(): void
    {
        // --no-checksum means "no downloads at all" - a subscription-only
        // plugin's 401 can't be detected in this mode, so the version is
        // written as normal (matches the pre-existing --no-checksum contract).
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $this->setProtected($command, 'moodlerelease', '4.3');
            $this->setProtected($command, 'expandedOptions', ['no-checksum' => true]);
            $this->setProtected($command, 'pluginsdata', $this->makePluginsDataWithDownloadUrl([
                ['component' => 'tiny_fontfamily', 'downloadurl' => 'http://invalid.invalid/nope.zip', 'versions' => [
                    ['version' => 2026071400, 'releases' => ['4.3']],
                ]],
            ]));

            $result = $this->callProtected($command, 'updateStandardComponent', ['tiny_fontfamily', $dir, false]);

            $this->assertStringStartsWith('CREATE ', $result);
            $this->assertSame("2026071400\n", file_get_contents($dir . '/version'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testUpdateStandardComponentDryRunNeverDownloads(): void
    {
        // Dry-run must never touch the network, 401 or otherwise - an
        // unreachable/invalid URL here would throw if downloadPluginZip()
        // were reached at all.
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $this->setProtected($command, 'moodlerelease', '4.3');
            $this->setProtected($command, 'expandedOptions', []);
            $this->setProtected($command, 'pluginsdata', $this->makePluginsDataWithDownloadUrl([
                ['component' => 'tiny_fontfamily', 'downloadurl' => 'http://invalid.invalid/nope.zip', 'versions' => [
                    ['version' => 2026071400, 'releases' => ['4.3']],
                ]],
            ]));

            $result = $this->callProtected($command, 'updateStandardComponent', ['tiny_fontfamily', $dir, true]);

            $this->assertStringStartsWith('WOULD CREATE ', $result);
            $this->assertFileDoesNotExist($dir . '/version');
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * Same shape as makePluginsData(), but with a per-plugin downloadurl
     * attached to every version (needed for the checksum/download tests -
     * makePluginsData() intentionally leaves it unset).
     */
    private function makePluginsDataWithDownloadUrl(array $plugins): \stdClass
    {
        $data = new \stdClass();
        $data->plugins = array_map(function ($plugin) {
            $obj = new \stdClass();
            $obj->component = $plugin['component'];
            $obj->versions = array_map(function ($version) use ($plugin) {
                $vObj = new \stdClass();
                $vObj->version = $version['version'];
                $vObj->downloadurl = $plugin['downloadurl'];
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
}
