<?php
/**
 * moosh - Moodle Shell
 *
 * Keep the `version` file of one or more locally-tracked Frankenstyle
 * plugin directories in sync with the latest version available from
 * moodle.org that is compatible with a given Moodle release.
 *
 * This targets the "declarative plugin list" layout used elsewhere in this
 * toolkit (see moodle_plugins_lib.rc): one subdirectory per plugin, named
 * after its Frankenstyle component (eg. block_fastnav/), holding a
 * `version` file that pins the version to install.
 *
 * moosh plugin-list-update [-d <directory>] [-v <release>] [-n] [component ...]
 *
 * Update every plugin directory found in the current directory
 * @example moosh plugin-list-update
 *
 * Only update block_fastnav and mod_board, against Moodle 4.3
 * @example moosh plugin-list-update -v 4.3 block_fastnav mod_board
 *
 * Report what would change without writing anything
 * @example moosh plugin-list-update -n
 *
 * For each candidate directory:
 *   - no version file yet            -> write the latest compatible version
 *   - version file older than latest -> update it to the latest compatible version
 *   - version file already latest    -> left untouched
 *   - version file contains "0"      -> left untouched (pinned/marked for
 *                                        uninstall, matches the convention
 *                                        used by install_requested_version()
 *                                        in moodle_plugins_lib.rc)
 *   - no compatible version exists   -> a `support_status` file is written
 *                                        next to `version`, matching
 *                                        get_support_status()'s
 *                                        "not supported for moodle core" marker
 *
 * package_* directories are a special case: their download/install (and
 * now version-lookup) is overridden by scripts under bin/ rather than
 * moodle.org. For those, bin/get_latest_plugin_version.sh is invoked
 * (with the working directory and __config_plugin_directory /
 * __moodle_root_directory environment set up the same way
 * moodle_plugins_lib.rc's get_latest_plugin_version() does) instead of
 * consulting plugins.json.
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh\Command\Generic\Plugin;

use Moosh\MooshCommand;

class PluginListUpdate extends MooshCommand
{
    /** @var string|null resolved Moodle release to match plugin compatibility against, eg. '4.3' */
    private $moodlerelease;

    /** @var \stdClass|null decoded plugins.json, cached for the duration of one execute() */
    private $pluginsdata;

    public function __construct()
    {
        parent::__construct('list-update', 'plugin');

        // Zero or more Frankenstyle component names. None given -> every
        // subdirectory of --directory is treated as a candidate.
        $this->addArgument('plugin_name');
        $this->minArguments = 0;
        $this->maxArguments = 255;

        $this->addOption('d|directory:', 'Directory to scan for plugin subdirectories.', '.');
        $this->addOption('v|version:', 'Moodle major version to match plugin compatibility against (eg. 4.3). Defaults to the current site version.');
        $this->addOption('m|moodle-root:', "Working directory used when invoking a package_*'s bin/get_latest_plugin_version.sh. Defaults to the parent directory of --directory.");
        $this->addOption('p|path:', 'path to plugins.json file', home_dir() . '/.moosh/plugins.json');
        $this->addOption('n|dry-run', "Report what would change, don't write any files.");
        $this->addOption('r|proxy:', 'Proxy URI scheme. Example: tcp://user:pass@host:port. You may also use env var http_proxy.');
        $this->addOption('t|token:', 'Moodle Marketplace API token, used as a Bearer token for the download request. You may also use env var MOODLE_MARKETPLACE_TOKEN.');
    }

    public function bootstrapLevel()
    {
        // A full Moodle bootstrap is only needed to auto-detect the current
        // release when -v wasn't given explicitly.
        $argv = $_SERVER['argv'];
        if (in_array('-v', $argv, true) || in_array('--version', $argv, true)) {
            return self::$BOOTSTRAP_NONE;
        }
        if (in_array('-h', $argv, true) || in_array('--help', $argv, true)) {
            return self::$BOOTSTRAP_NONE;
        }
        return self::$BOOTSTRAP_FULL;
    }

    public function requireHomeWriteable()
    {
        // Reads/writes the shared plugins.json cache under home_dir().
        return true;
    }

    private function setupRelease()
    {
        if (!empty($this->expandedOptions['version'])) {
            $this->moodlerelease = $this->expandedOptions['version'];
            return;
        }

        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->libdir . '/environmentlib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $this->moodlerelease = moodle_major_version();
    }

    public function execute()
    {
        $this->setupRelease();
        $this->refreshPluginsJson($this->expandedOptions['path']);

        $basedir = rtrim($this->expandedOptions['directory'], '/');
        if ($basedir === '') {
            $basedir = '/';
        }
        if (!is_dir($basedir)) {
            cli_error("Directory not found: $basedir");
        }
        $basedir = realpath($basedir);

        $moodleroot = !empty($this->expandedOptions['moodle-root'])
            ? $this->expandedOptions['moodle-root']
            : dirname($basedir);

        $components = $this->arguments;
        if (empty($components)) {
            $components = $this->discoverComponents($basedir);
        }

        if (empty($components)) {
            echo "No plugin directories found in $basedir.\n";
            return;
        }

        $dryrun = !empty($this->expandedOptions['dry-run']);
        $exitcode = 0;

        foreach ($components as $component) {
            $componentdir = $basedir . '/' . $component;
            if (!is_dir($componentdir)) {
                echo "SKIP   $component: directory not found ($componentdir)\n";
                $exitcode = 1;
                continue;
            }

            try {
                if (strpos($component, 'package_') === 0) {
                    $message = $this->updatePackageComponent($component, $componentdir, $moodleroot, $dryrun);
                } else {
                    $message = $this->updateStandardComponent($component, $componentdir, $dryrun);
                }
                echo $message . "\n";
            } catch (\RuntimeException $e) {
                echo "ERROR  $component: " . $e->getMessage() . "\n";
                $exitcode = 1;
            }
        }

        if ($exitcode) {
            exit($exitcode);
        }
    }

    /**
     * @param string $basedir
     * @return string[] non-hidden subdirectory names of $basedir, sorted
     */
    protected function discoverComponents($basedir)
    {
        $components = array();
        foreach (scandir($basedir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;
            }
            if (!is_dir($basedir . '/' . $entry)) {
                continue;
            }
            $components[] = $entry;
        }
        sort($components);
        return $components;
    }

    /**
     * Handle a regular (non package_*) component: look its latest
     * compatible version up in plugins.json and reconcile it with the
     * component's version file.
     *
     * @param string $component
     * @param string $componentdir
     * @param bool   $dryrun
     * @return string a one-line human readable report of what happened
     * @throws \RuntimeException if $component can't be found in plugins.json
     */
    protected function updateStandardComponent($component, $componentdir, $dryrun)
    {
        $versionfile = $componentdir . '/version';
        $currentversion = $this->readVersionFile($versionfile);

        if ($currentversion === '0') {
            return "SKIP   $component: pinned to version 0 (marked for uninstall)";
        }

        $latest = $this->findLatestCompatibleVersion($component);

        if ($latest === null) {
            if (!$dryrun) {
                $this->writeSupportStatus($componentdir, 'not supported for moodle core ' . $this->moodlerelease);
            }
            return "SKIP   $component: no version supports Moodle {$this->moodlerelease}";
        }

        if (!$dryrun) {
            $this->clearSupportStatus($componentdir);
        }

        return $this->applyVersion($component, $versionfile, $currentversion, (string) $latest->version, $dryrun);
    }

    /**
     * Handle a package_* component: defer to its own
     * bin/get_latest_plugin_version.sh, mirroring the calling convention
     * used by get_latest_plugin_version() in moodle_plugins_lib.rc (no
     * arguments, cwd set to the Moodle root, called via its full path).
     *
     * @param string $component
     * @param string $componentdir
     * @param string $moodleroot
     * @param bool   $dryrun
     * @return string
     * @throws \RuntimeException
     */
    protected function updatePackageComponent($component, $componentdir, $moodleroot, $dryrun)
    {
        $script = $componentdir . '/bin/get_latest_plugin_version.sh';
        if (!is_file($script)) {
            throw new \RuntimeException("could not find $component/bin/get_latest_plugin_version.sh");
        }
        if (!is_executable($script)) {
            throw new \RuntimeException("$component/bin/get_latest_plugin_version.sh is not executable");
        }

        $versionfile = $componentdir . '/version';
        $currentversion = $this->readVersionFile($versionfile);

        if ($currentversion === '0') {
            return "SKIP   $component: pinned to version 0 (marked for uninstall)";
        }

        $latest = $this->runGetLatestPluginVersionScript($script, $moodleroot);

        return $this->applyVersion($component, $versionfile, $currentversion, $latest, $dryrun);
    }

    /**
     * @param string $script absolute path to bin/get_latest_plugin_version.sh
     * @param string $moodleroot
     * @return string the reported version, as a validated integer string
     * @throws \RuntimeException
     */
    protected function runGetLatestPluginVersionScript($script, $moodleroot)
    {
        // __config_plugin_directory / __moodle_root_directory match the
        // variables moodle_plugins_lib.rc exports into the environment a
        // package_* bin/ script runs in.
        putenv('__config_plugin_directory=' . dirname(dirname($script)));
        putenv('__moodle_root_directory=' . $moodleroot);

        $cwd = getcwd();
        chdir($moodleroot);
        exec(escapeshellarg($script) . ' 2>&1', $output, $exitcode);
        chdir($cwd);

        if ($exitcode !== 0) {
            throw new \RuntimeException(
                'bin/get_latest_plugin_version.sh exited with status ' . $exitcode . ': ' . implode("\n", $output)
            );
        }

        $latest = trim(implode("\n", $output));
        if (!preg_match('/^-?[0-9]+$/', $latest)) {
            throw new \RuntimeException(
                "bin/get_latest_plugin_version.sh did not report a valid integer version: '$latest'"
            );
        }

        return $latest;
    }

    /**
     * Reconcile a component's on-disk version file with a newly-resolved
     * latest version, writing the file only when something actually needs
     * to change (and never downgrading a locally newer/pinned version).
     *
     * @param string      $component
     * @param string      $versionfile
     * @param string|null $currentversion null if the version file doesn't exist (or is empty)
     * @param string      $latestversion
     * @param bool        $dryrun
     * @return string
     */
    protected function applyVersion($component, $versionfile, $currentversion, $latestversion, $dryrun)
    {
        if ($currentversion === null) {
            if ($dryrun) {
                return "WOULD CREATE $component: version file missing -> would write $latestversion";
            }
            $this->writeVersionFile($versionfile, $latestversion);
            return "CREATE $component: version file missing -> $latestversion";
        }

        if ($currentversion === $latestversion) {
            return "OK     $component: already at latest ($currentversion)";
        }

        if ((int) $currentversion > (int) $latestversion) {
            return "SKIP   $component: local version $currentversion is newer than latest available $latestversion";
        }

        if ($dryrun) {
            return "WOULD UPDATE $component: $currentversion -> $latestversion";
        }
        $this->writeVersionFile($versionfile, $latestversion);
        return "UPDATE $component: $currentversion -> $latestversion";
    }

    /**
     * @param string $versionfile
     * @return string|null trimmed file content, or null if missing/empty
     */
    protected function readVersionFile($versionfile)
    {
        if (!is_file($versionfile)) {
            return null;
        }
        $contents = trim(file_get_contents($versionfile));
        return $contents === '' ? null : $contents;
    }

    protected function writeVersionFile($versionfile, $version)
    {
        file_put_contents($versionfile, $version . "\n");
    }

    protected function writeSupportStatus($componentdir, $status)
    {
        file_put_contents($componentdir . '/support_status', $status . "\n");
    }

    protected function clearSupportStatus($componentdir)
    {
        $path = $componentdir . '/support_status';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Find the highest version of $component in plugins.json that
     * supports $this->moodlerelease.
     *
     * @param string $component
     * @return \stdClass|null the matched version entry (has ->version),
     *   or null if the plugin exists but has no version for this release
     * @throws \RuntimeException if $component isn't in plugins.json at all
     */
    protected function findLatestCompatibleVersion($component)
    {
        $data = $this->getPluginsData();
        foreach ($data->plugins as $plugin) {
            if (empty($plugin->component) || $plugin->component !== $component) {
                continue;
            }

            $best = null;
            foreach ($plugin->versions as $version) {
                if (!$this->isSupportedByMoodle($version)) {
                    continue;
                }
                if ($best === null || $version->version > $best->version) {
                    $best = $version;
                }
            }
            return $best;
        }

        throw new \RuntimeException(
            "component not found in plugins.json - check the frankenstyle name, or the plugin may no longer be listed on moodle.org"
        );
    }

    protected function isSupportedByMoodle($version)
    {
        foreach ($version->supportedmoodles as $supported) {
            if ((string) $this->moodlerelease === (string) $supported->release) {
                return true;
            }
        }
        return false;
    }

    /**
     * Refresh ~/.moosh/plugins.json if missing/empty/older than a day,
     * mirroring plugin-list's own refresh logic exactly (same TTL, same
     * source URL) so this command is self-sufficient and never requires a
     * separate `moosh plugin-list` run first.
     *
     * @param string $filepath
     */
    protected function refreshPluginsJson($filepath)
    {
        $stat = file_exists($filepath) ? stat($filepath) : null;
        if (!$stat || time() - $stat['mtime'] > 60 * 60 * 24 || !$stat['size']) {
            @unlink($filepath);
            $contents = file_get_contents(
                PluginList::$APIURL,
                false,
                PluginDownload::createProxyContext($this->expandedOptions, PluginList::$APIURL)
            );
            if ($contents === false) {
                cli_error('Failed to download plugins list from ' . PluginList::$APIURL);
            }
            file_put_contents($filepath, $contents);
        }
    }

    /**
     * @return \stdClass decoded plugins.json, cached for this execute() call
     */
    protected function getPluginsData()
    {
        if ($this->pluginsdata !== null) {
            return $this->pluginsdata;
        }

        $filepath = $this->expandedOptions['path'];
        $jsonfile = file_get_contents($filepath);
        if ($jsonfile === false) {
            cli_error("Can't read json file $filepath");
        }

        $data = json_decode($jsonfile);
        if (!$data) {
            unlink($filepath);
            cli_error("Invalid JSON file, deleted $filepath. Run command again.");
        }

        $this->pluginsdata = $data;
        return $data;
    }
}
