<?php
/**
 * Helpers for working with an extracted plugin zip's directory structure,
 * shared by plugin-install and plugin-clamscan.
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh;

class PluginZip
{
    /**
     * Find the shallowest directory containing a version.php file inside an
     * extracted plugin zip. Plugin zips aren't guaranteed to have their
     * version.php at the top level (some wrap the plugin in an extra
     * directory), so this picks the least-nested match.
     *
     * @param string $unzipdir
     * @return string
     * @throws \RuntimeException if no version.php is found anywhere under $unzipdir
     */
    public static function findPluginRootDir($unzipdir)
    {
        $versionfiles = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($unzipdir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isFile() && $fileinfo->getFilename() === 'version.php') {
                $versionfiles[] = $fileinfo->getPath();
            }
        }

        if (empty($versionfiles)) {
            throw new \RuntimeException("The zipfile does not seem to be a valid plugin (no version.php found)");
        }

        usort($versionfiles, function ($a, $b) {
            $aDepth = substr_count(rtrim($a, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
            $bDepth = substr_count(rtrim($b, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
            return $aDepth <=> $bDepth;
        });

        $plugindir = $versionfiles[0];
        if (!file_exists($plugindir)) {
            throw new \RuntimeException("The zipfile does not seem to be a valid plugin (no version.php found)");
        }

        return $plugindir;
    }
}
