<?php
/**
 * Verifies a downloaded/cached plugin zip against a pinned sha256, shared by
 * plugin-install and plugin-clamscan so both enforce the exact same
 * integrity guarantee: the bytes a version number resolves to today must
 * match the bytes that were reviewed/scanned when the pin was set.
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh;

class PluginChecksum
{
    /**
     * Verify a file against a pinned sha256, if one is provided via the
     * MOOSH_EXPECTED_SHA256 environment variable.
     *
     * Throws rather than die()s so callers (and tests) can handle the
     * failure themselves - a CLI command typically catches this and
     * converts it into a die()/cli_error() with its own exit code.
     *
     * @param string $downloadedfile path to the zip about to be extracted
     * @param string $pluginname     plugin component name, for messages
     * @throws \RuntimeException if MOOSH_EXPECTED_SHA256 is set and doesn't match
     */
    public static function verify($downloadedfile, $pluginname)
    {
        $actualsha256 = hash_file('sha256', $downloadedfile);
        echo "downloaded-sha256: $actualsha256\n";

        $expectedsha256 = getenv('MOOSH_EXPECTED_SHA256');
        if ($expectedsha256 === false || trim($expectedsha256) === '') {
            echo "No pinned sha256 for $pluginname (MOOSH_EXPECTED_SHA256 not set) - skipping verification.\n";
            return;
        }

        if (!hash_equals(strtolower(trim($expectedsha256)), strtolower($actualsha256))) {
            @unlink($downloadedfile);
            throw new \RuntimeException(
                "Refusing to use $pluginname: downloaded zip sha256 ($actualsha256) " .
                "does not match pinned checksum ($expectedsha256) for this version. " .
                "The file served for this version differs from what was scanned/reviewed - " .
                "aborting."
            );
        }

        echo "sha256 checksum verified for $pluginname.\n";
    }
}
