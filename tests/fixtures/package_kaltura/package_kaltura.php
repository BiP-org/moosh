<?php

namespace install_plugins;

use Exception;

/**
 * Package handler for Kaltura video plugin.
 * Downloads from GitHub: https://github.com/kaltura/moodle_plugin
 */
class package_kaltura extends package_base {
    private const GITHUB_API_URL = 'https://api.github.com/repos/kaltura/moodle_plugin/releases';

    public function get_plugin_paths(): array {
        return [
            'blocks/kalturamediagallery',
            'local/kaltura',
            'local/mymedia',
            'mod/kalvidres',
            'mod/kalvidassign',
            'mod/assign/submission/kalvid',
            'lib/editor/atto/plugins/kalturamedia',
            'lib/editor/tinymce/plugins/kalturamedia',
            'lib/editor/tiny/plugins/kalturamedia',
            'filter/kaltura',
            'local/kalturamediagallery',
        ];
    }

    public function get_installed_version(): int {
        $version_file = moodle::get_moodle_dirroot() . '/local/kaltura/version.php';
        if (!file_exists($version_file)) {
            return -1;
        }

        // Try to require the file, but Kaltura's version.php has code that
        // requires the database connection, so catch any errors
        try {
            $plugin = (object)[];
            require $version_file;
            return (int)($plugin->version ?? -1);
        } catch (\Throwable $e) {
            // Version is set before the database error in Kaltura's version.php
            if (!isset($plugin->version)) {
                throw new Exception("Failed to read Kaltura version: " . $e->getMessage());
            }
            return (int)$plugin->version;
        }
    }

    public function get_latest_version(): ?string {
        $releases = $this->fetch_releases();
        if (!$releases) {
            throw new Exception("Failed to fetch Kaltura releases from GitHub");
        }

        // The moodle branch is only reliably encoded in the file name of the asset
        // (Kaltura_Video_Package_moodle405_2024100705.zip), release names and tags are not.
        $branch = moodle::get_moodle_version()->branch;

        $latest = null;
        foreach ($releases as $release) {
            foreach ($release['assets'] ?? [] as $asset) {
                if (preg_match('!_moodle' . $branch . '_(\d+)\.zip$!', $asset['browser_download_url'] ?? '', $matches)) {
                    $latest = max($latest, (int)$matches[1]);
                }
            }
        }

        return $latest ? (string)$latest : null;
    }

    public function install(string $version): void {
        $releases = $this->fetch_releases();
        if (!$releases) {
            throw new Exception("Failed to fetch Kaltura releases from GitHub");
        }

        // Find download URL for requested version
        $download_url = null;
        foreach ($releases as $release) {
            foreach ($release['assets'] ?? [] as $asset) {
                $url = $asset['browser_download_url'] ?? '';
                if (str_contains($url, $version)) {
                    $download_url = $url;
                    break 2;
                }
            }
        }

        if (!$download_url) {
            throw new Exception("Could not find Kaltura release for version $version");
        }

        cli_output::line("Installing package: {$this->get_package_name()}, version: {$version}");

        $zip_file = helper::download_cached($download_url, "package_kaltura_{$version}.zip");
        if (!$zip_file) {
            throw new Exception("Failed to download Kaltura package");
        }

        $dirroot = moodle::get_moodle_dirroot();
        $allowed_paths = $this->get_plugin_paths();

        $zip = new \ZipArchive();
        if ($zip->open($zip_file) !== true) {
            throw new Exception("Failed to open Kaltura zip file");
        }

        // Extract only files within allowed paths
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Skip directory entries and junk files
            if (str_ends_with($name, '/') || str_ends_with($name, '.DS_Store')) {
                continue;
            }

            // Check if file is within allowed paths
            $allowed = false;
            foreach ($allowed_paths as $path) {
                if (str_starts_with($name, $path . '/')) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed) {
                $zip->close();
                throw new Exception("Unexpected file in Kaltura package: $name - update get_plugin_paths()");
            }

            if (!$zip->extractTo($dirroot, $name)) {
                $zip->close();
                throw new Exception("Failed to extract: $name");
            }
        }

        $zip->close();

        cli_output::info("Kaltura package installed");
    }

    public function finalize(): void {
        $dirroot = moodle::get_moodle_dirroot();

        // Remove tinymce kaltura support if tinymce is not installed
        if (!file_exists($dirroot . '/lib/editor/tinymce/version.php')) {
            $tinymce_kaltura = $dirroot . '/lib/editor/tinymce/plugins/kalturamedia';
            if (is_dir($tinymce_kaltura)) {
                cli_output::verbose("Removing tinymce kaltura support (tinymce not installed)");
                passthru('rm -rf ' . escapeshellarg($tinymce_kaltura));
            }

            // Remove empty parent directories
            foreach (['lib/editor/tinymce/plugins', 'lib/editor/tinymce'] as $subdir) {
                $dir = $dirroot . '/' . $subdir;
                if (is_dir($dir) && !glob($dir . '/*')) {
                    rmdir($dir);
                }
            }
        }
    }

    private function fetch_releases(): ?array {
        static $releases = null;
        if ($releases !== null) {
            return $releases;
        }

        $cache_file = helper::download_cached(self::GITHUB_API_URL, 'kaltura_releases.json', 3600);
        if (!$cache_file) {
            return null;
        }

        $releases = json_decode(file_get_contents($cache_file), true);
        return $releases;
    }
}
