<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Moosh\PluginChecksum;

final class PluginChecksumTest extends TestCase
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

    public function testSkippedWhenNoExpectedShaIsSet(): void
    {
        putenv('MOOSH_EXPECTED_SHA256'); // ensure unset
        $file = $this->makeTempZipLikeFile();

        try {
            // Should not throw, and the file must be left in place.
            PluginChecksum::verify($file, 'mod_example');
            $this->assertFileExists($file);
        } finally {
            @unlink($file);
        }
    }

    public function testSkippedWhenExpectedShaIsEmptyString(): void
    {
        putenv('MOOSH_EXPECTED_SHA256=');
        $file = $this->makeTempZipLikeFile();

        try {
            PluginChecksum::verify($file, 'mod_example');
            $this->assertFileExists($file);
        } finally {
            @unlink($file);
        }
    }

    public function testPassesWhenShaMatches(): void
    {
        $file = $this->makeTempZipLikeFile('exact bytes that must match the pin');
        putenv('MOOSH_EXPECTED_SHA256=' . hash_file('sha256', $file));

        try {
            PluginChecksum::verify($file, 'mod_example');
            // A match must not delete the file - it still needs to be used.
            $this->assertFileExists($file);
        } finally {
            @unlink($file);
        }
    }

    public function testIsCaseInsensitive(): void
    {
        $file = $this->makeTempZipLikeFile('case sensitivity check');
        $hash = hash_file('sha256', $file);
        putenv('MOOSH_EXPECTED_SHA256=' . strtoupper($hash));

        try {
            PluginChecksum::verify($file, 'mod_example');
            $this->assertFileExists($file);
        } finally {
            @unlink($file);
        }
    }

    public function testThrowsWhenShaMismatches(): void
    {
        $file = $this->makeTempZipLikeFile('the actual downloaded bytes');
        putenv('MOOSH_EXPECTED_SHA256=' . str_repeat('a', 64)); // sha256 that can't match

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to use/');

        try {
            PluginChecksum::verify($file, 'mod_example');
        } finally {
            // A mismatch must delete the file so it can never be used -
            // assert this regardless of how the exception propagates.
            $this->assertFileDoesNotExist($file);
        }
    }

    public function testMismatchMessageIncludesComponentName(): void
    {
        $file = $this->makeTempZipLikeFile('some content');
        putenv('MOOSH_EXPECTED_SHA256=' . str_repeat('b', 64));

        try {
            PluginChecksum::verify($file, 'mod_totallyfake');
            $this->fail('Expected a RuntimeException for a checksum mismatch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('mod_totallyfake', $e->getMessage());
        } finally {
            @unlink($file);
        }
    }
}
