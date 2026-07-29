<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PluginInstall's own logic (checksum verification, plugin-root detection)
 * moved to Moosh\PluginChecksum / Moosh\PluginZip so plugin-clamscan can
 * reuse it too - see PluginChecksumTest.php / PluginZipTest.php.
 *
 * What's left in PluginInstall::execute() itself needs a full Moodle
 * bootstrap (core_component, upgrade_noncore, etc.) and isn't practical to
 * unit test in isolation, so this file just confirms the class wires up
 * without errors outside that context.
 */
final class PluginInstallTest extends TestCase
{
    public function testCanBeConstructedWithoutGetOptionKit(): void
    {
        // Constructing normally pulls in GetOptionKit (not needed for this
        // check and not vendored in a plain checkout), so this instantiates
        // via Reflection without running the constructor at all.
        $command = (new \ReflectionClass(\Moosh\Command\Generic\Plugin\PluginInstall::class))
            ->newInstanceWithoutConstructor();

        $this->assertInstanceOf(\Moosh\Command\Generic\Plugin\PluginInstall::class, $command);
    }
}
