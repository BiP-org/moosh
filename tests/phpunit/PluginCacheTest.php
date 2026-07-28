<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Moosh\PluginCache;

final class PluginCacheTest extends TestCase
{
    /** @var string */
    private $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/moosh-plugincache-test-' . uniqid('', true);
        putenv('MOOSH_CACHE_DIR=' . $this->cacheDir);
    }

    protected function tearDown(): void
    {
        putenv('MOOSH_CACHE_DIR');
        if (is_dir($this->cacheDir)) {
            $this->removeDir($this->cacheDir);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    /**
     * Build a real, structurally valid zip fixture using the `zip` binary,
     * matching how PluginCache::isValidZip() itself falls back to `unzip
     * -tqq` when the ZipArchive extension isn't available.
     */
    private function makeValidZip(string $contents = 'plugin file contents'): string
    {
        $srcDir = sys_get_temp_dir() . '/moosh-plugincache-src-' . uniqid('', true);
        mkdir($srcDir, 0777, true);
        file_put_contents($srcDir . '/version.php', $contents);

        $zipPath = sys_get_temp_dir() . '/moosh-plugincache-fixture-' . uniqid('', true) . '.zip';
        exec('cd ' . escapeshellarg($srcDir) . ' && zip -q ' . escapeshellarg($zipPath) . ' version.php', $out, $rc);
        $this->removeDir($srcDir);

        $this->assertSame(0, $rc, 'Failed to build a valid zip test fixture - is `zip` installed?');
        return $zipPath;
    }

    public function testGetCacheDirUsesEnvVarAndCreatesIt(): void
    {
        $this->assertDirectoryDoesNotExist($this->cacheDir);
        $dir = PluginCache::getCacheDir();
        $this->assertSame(rtrim($this->cacheDir, '/'), $dir);
        $this->assertDirectoryExists($dir);
    }

    public function testGetCachePathSanitizesVersion(): void
    {
        $path = PluginCache::getCachePath('mod_example', '2019010700');
        $this->assertSame($this->cacheDir . '/mod_example-2019010700.zip', $path);
    }

    public function testGetCachePathSanitizesUnexpectedCharacters(): void
    {
        $path = PluginCache::getCachePath('mod_example', '../../etc/passwd');
        // No path traversal or slashes may survive into the cache filename.
        $this->assertStringNotContainsString('/../', $path);
        $this->assertSame($this->cacheDir . '/mod_example-.._.._etc_passwd.zip', $path);
    }

    public function testIsValidZipReturnsFalseForMissingFile(): void
    {
        $this->assertFalse(PluginCache::isValidZip($this->cacheDir . '/does-not-exist.zip'));
    }

    public function testIsValidZipReturnsFalseForEmptyFile(): void
    {
        $path = sys_get_temp_dir() . '/moosh-empty-' . uniqid('', true) . '.zip';
        file_put_contents($path, '');
        try {
            $this->assertFalse(PluginCache::isValidZip($path));
        } finally {
            @unlink($path);
        }
    }

    public function testIsValidZipReturnsFalseForCorruptZip(): void
    {
        $path = sys_get_temp_dir() . '/moosh-corrupt-' . uniqid('', true) . '.zip';
        file_put_contents($path, 'this is not a zip file, just plain garbage bytes');
        try {
            $this->assertFalse(PluginCache::isValidZip($path));
        } finally {
            @unlink($path);
        }
    }

    public function testIsValidZipReturnsTrueForRealZip(): void
    {
        $zip = $this->makeValidZip();
        try {
            $this->assertTrue(PluginCache::isValidZip($zip));
        } finally {
            @unlink($zip);
        }
    }

    public function testStoreRefusesInvalidZip(): void
    {
        $path = sys_get_temp_dir() . '/moosh-bad-download-' . uniqid('', true) . '.zip';
        file_put_contents($path, 'garbage, not a zip');

        try {
            $stored = PluginCache::store('mod_example', '2019010700', $path);
            $this->assertFalse($stored);
            $this->assertFileDoesNotExist(PluginCache::getCachePath('mod_example', '2019010700'));
        } finally {
            @unlink($path);
        }
    }

    public function testFetchReturnsFalseWhenNothingCached(): void
    {
        $destination = sys_get_temp_dir() . '/moosh-fetch-dest-' . uniqid('', true) . '.zip';
        try {
            $this->assertFalse(PluginCache::fetch('mod_neverinstalled', '2019010700', $destination));
            $this->assertFileDoesNotExist($destination);
        } finally {
            @unlink($destination);
        }
    }

    public function testStoreThenFetchRoundTripsTheExactBytes(): void
    {
        $zip = $this->makeValidZip('bytes that must survive the round trip untouched');
        $originalHash = hash_file('sha256', $zip);
        $destination = sys_get_temp_dir() . '/moosh-fetch-dest-' . uniqid('', true) . '.zip';

        try {
            $this->assertTrue(PluginCache::store('mod_roundtrip', '2024011700', $zip));
            $this->assertTrue(PluginCache::fetch('mod_roundtrip', '2024011700', $destination));
            $this->assertFileExists($destination);
            $this->assertSame(
                $originalHash,
                hash_file('sha256', $destination),
                'A cache round trip must not alter a single byte of the cached zip - ' .
                'this is what a pinned checksum verification relies on downstream.'
            );
        } finally {
            @unlink($zip);
            @unlink($destination);
        }
    }
}
