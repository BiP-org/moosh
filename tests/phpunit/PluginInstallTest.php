<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PluginInstallTest extends TestCase
{
    protected function tearDown(): void
    {
        // Never let a test leak this env var into a sibling test.
        putenv('MOOSH_EXPECTED_SHA256');
    }

    private function makeTempZipLikeFile(string $contents = 'not a real zip, just needs stable bytes'): string
    {
        $path = sys_get_temp_dir() . '/moosh-checksum-test-' . uniqid('', true) . '.zip';
        file_put_contents($path, $contents);
        return $path;
    }

    private function invokeVerifyDownloadChecksum(string $downloadedfile, string $pluginname = 'mod_example')
    {
        $command = (new \ReflectionClass(\Moosh\Command\Generic\Plugin\PluginInstall::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($command, 'verify_download_checksum');
        $method->setAccessible(true);

        return $method->invoke($command, $downloadedfile, $pluginname);
    }

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
            $command = (new \ReflectionClass(\Moosh\Command\Generic\Plugin\PluginInstall::class))->newInstanceWithoutConstructor();
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

    public function testChecksumVerificationSkippedWhenNoExpectedShaIsSet(): void
    {
        putenv('MOOSH_EXPECTED_SHA256'); // ensure unset
        $file = $this->makeTempZipLikeFile();

        try {
            // Should not throw, and the file must be left in place.
            $this->invokeVerifyDownloadChecksum($file);
            $this->assertFileExists($file);
        } finally {
            @unlink($file);
        }
    }

    public function testChecksumVerificationSkippedWhenExpectedShaIsEmptyString(): void
    {
        putenv('MOOSH_EXPECTED_SHA256=');
        $file = $this->makeTempZipLikeFile();

        try {
            $this->invokeVerifyDownloadChecksum($file);
            $this->assertFileExists($file);
        } finally {
            @unlink($file);
        }
    }

    public function testChecksumVerificationPassesWhenShaMatches(): void
    {
        $file = $this->makeTempZipLikeFile('exact bytes that must match the pin');
        putenv('MOOSH_EXPECTED_SHA256=' . hash_file('sha256', $file));

        try {
            $this->invokeVerifyDownloadChecksum($file);
            // A match must not delete the file - it still needs to be extracted.
            $this->assertFileExists($file);
        } finally {
            @unlink($file);
        }
    }

    public function testChecksumVerificationIsCaseInsensitive(): void
    {
        $file = $this->makeTempZipLikeFile('case sensitivity check');
        $hash = hash_file('sha256', $file);
        putenv('MOOSH_EXPECTED_SHA256=' . strtoupper($hash));

        try {
            $this->invokeVerifyDownloadChecksum($file);
            $this->assertFileExists($file);
        } finally {
            @unlink($file);
        }
    }

    public function testChecksumVerificationThrowsWhenShaMismatches(): void
    {
        $file = $this->makeTempZipLikeFile('the actual downloaded bytes');
        putenv('MOOSH_EXPECTED_SHA256=' . str_repeat('a', 64)); // sha256 that can't match

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to install/');

        try {
            $this->invokeVerifyDownloadChecksum($file);
        } finally {
            // A mismatch must delete the file so it can never be extracted -
            // assert this regardless of how the exception propagates.
            $this->assertFileDoesNotExist($file);
        }
    }

    public function testChecksumVerificationMismatchMessageIncludesComponentName(): void
    {
        $file = $this->makeTempZipLikeFile('some content');
        putenv('MOOSH_EXPECTED_SHA256=' . str_repeat('b', 64));

        try {
            $this->invokeVerifyDownloadChecksum($file, 'mod_totallyfake');
            $this->fail('Expected a RuntimeException for a checksum mismatch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('mod_totallyfake', $e->getMessage());
        } finally {
            @unlink($file);
        }
    }
}

