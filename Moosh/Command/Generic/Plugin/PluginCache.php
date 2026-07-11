<?php
/**
 * Shared disk cache for plugin zip files downloaded by
 * `plugin-install` and `plugin-download`.
 *
 * Cache directory resolution order:
 *   1. $MOOSH_CACHE_DIR environment variable, if set and non-empty
 *   2. ~/.moosh/moodleplugins (default)
 *
 * Every cached file is validated before being reused: it must exist,
 * be larger than 0 bytes, and pass a zip integrity check.
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh\Command\Generic\Plugin;

class PluginCache
{
    /**
     * Resolve (and create if necessary) the cache directory.
     *
     * @return string absolute path to the cache dir, without trailing slash
     */
    public static function getCacheDir()
    {
        $envdir = getenv('MOOSH_CACHE_DIR');

        if ($envdir !== false && trim($envdir) !== '') {
            $dir = rtrim($envdir, '/');
        } else {
            $dir = rtrim(home_dir(), '/') . '/.moosh/moodleplugins';
        }

        if (!file_exists($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                cli_error("Failed to create cache directory $dir - check permissions.\n");
            }
        }

        return $dir;
    }

    /**
     * Build the cache file path for a given plugin component + version.
     *
     * @param string $component plugin component name, e.g. 'block_checklist'
     * @param string $version   plugin version, e.g. '2019010700'
     * @return string
     */
    public static function getCachePath($component, $version)
    {
        $safeversion = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$version);
        return self::getCacheDir() . '/' . $component . '-' . $safeversion . '.zip';
    }

    /**
     * Check that a file exists, is non-empty, and is a structurally valid zip.
     *
     * @param string $path
     * @return bool
     */
    public static function isValidZip($path)
    {
        if (!is_file($path)) {
            return false;
        }

        clearstatcache(true, $path);
        if (filesize($path) <= 0) {
            return false;
        }

        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            $result = $zip->open($path, \ZipArchive::CHECKCONS);
            if ($result !== true) {
                return false;
            }
            $zip->close();
            return true;
        }

        // Fall back to the unzip binary's own integrity test when the zip
        // extension isn't available.
        exec('unzip -tqq ' . escapeshellarg($path), $output, $returnvar);
        return $returnvar === 0;
    }

    /**
     * Try to serve $destination from the cache.
     *
     * @param string $component
     * @param string $version
     * @param string $destination path the caller wants the zip copied to
     * @return bool true if a valid cached copy was found and copied
     */
    public static function fetch($component, $version, $destination)
    {
        $cachepath = self::getCachePath($component, $version);

        if (self::isValidZip($cachepath)) {
            return copy($cachepath, $destination);
        }

        return false;
    }

    /**
     * Store a freshly downloaded file in the cache. Refuses to cache
     * anything that isn't a valid, non-empty zip.
     *
     * @param string $component
     * @param string $version
     * @param string $downloadedfile path to the file just downloaded
     * @return bool true if the file was cached
     */
    public static function store($component, $version, $downloadedfile)
    {
        if (!self::isValidZip($downloadedfile)) {
            return false;
        }

        return copy($downloadedfile, self::getCachePath($component, $version));
    }
}
