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
 * moosh plugin-list-update [-d <directory>] [-v <release>] [-n] [--no-checksum] [component ...]
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
 * consulting plugins.json. Checksum pinning (see below) is not available
 * for package_* components: there's no generic download URL to fetch
 * from plugins.json for them.
 *
 * Checksum pinning: whenever a (non package_*) component's version file is
 * created/updated, or already up to date but missing a `checksum` file, the
 * resolved version's zip is downloaded once, verified via
 * Moosh\PluginChecksum::verify() (honours MOOSH_EXPECTED_SHA256 the same
 * way plugin-install/plugin-clamscan do - only meaningful when checking a
 * single named component, since it's one global value for the whole run),
 * and its sha256 is written to `<component>/checksum` next to `version` so
 * a later install can pin that exact value. Pass --no-checksum to skip
 * this (no downloads at all, `checksum` files are left untouched).
 *
 * Marketplace-subscription plugins: plugins.json lists some plugins (and
 * "latest" versions for them) that are only downloadable from
 * marketplace.moodle.com with a token entitled to a paid subscription;
 * without one, the download responds HTTP 401
 * ({"code":401,"message":"Not privileged to request the resource."}).
 * Before writing a new/updated `version` for a component, this command
 * confirms the resolved zip is actually downloadable with the configured
 * --token/MOODLE_MARKETPLACE_TOKEN. If that download 401s, the component is
 * left exactly as it was on disk (existing `version` untouched, no file
 * created if there wasn't one) and a warning is reported instead - either a
 * GitHub Actions `::warning::` annotation when running in CI (`$CI` set) or
 * a plain "WARNING" line otherwise. This check is skipped entirely when
 * --no-checksum is given, same as all other download activity.
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh\Command\Generic\Plugin;

use Moosh\MooshCommand;
use Moosh\PluginCache;
use Moosh\PluginChecksum;
use Moosh\MarketplaceUnauthorizedException;

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
        $this->addOption('no-checksum', "Don't download zips to pin a sha256 checksum next to version (skips Moosh\\PluginChecksum entirely).");
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

        $latestversion = (string) $latest->version;
        $checksumenabled = empty($this->expandedOptions['no-checksum']);

        // Before writing anything, confirm the resolved version is actually
        // downloadable with the configured Marketplace token. plugins.json
        // lists subscription-only plugins the same as any other, but their
        // download 401s without an entitled token - pinning a version we
        // can't ourselves fetch would silently break a later install for
        // it. Only relevant when this run would actually change `version`;
        // an already-up-to-date component has nothing to lose here (a
        // missing checksum backfill failing the same way is handled below,
        // once we know a version write is not on the table).
        if ($checksumenabled && !$dryrun && $this->willChangeVersion($currentversion, $latestversion)) {
            try {
                $this->downloadPluginZip($component, $latestversion, $latest->downloadurl);
            } catch (MarketplaceUnauthorizedException $e) {
                return $this->reportMarketplaceUnauthorized($component, $currentversion, $latestversion);
            }
        }

        if (!$dryrun) {
            $this->clearSupportStatus($componentdir);
        }

        $message = $this->applyVersion($component, $versionfile, $currentversion, $latestversion, $dryrun);

        if ($checksumenabled && !$dryrun && strpos($message, 'SKIP   ') !== 0) {
            // Recompute/pin whenever the version file actually changed just
            // now; otherwise (the "OK already at latest" case) only backfill
            // a checksum that isn't pinned yet - don't re-download on every
            // run just to re-confirm nothing changed. Either way, the zip
            // for a real version change was already proven downloadable
            // above (and is now cached), so only a checksum-only backfill
            // can still 401 here.
            $versionchanged = (strpos($message, 'CREATE ') === 0 || strpos($message, 'UPDATE ') === 0);
            try {
                $checksumline = $this->reconcileChecksum($component, $componentdir, $latestversion, $latest->downloadurl, $versionchanged);
            } catch (MarketplaceUnauthorizedException $e) {
                $checksumline = $this->marketplaceUnauthorizedAnnotation(
                    $component,
                    "not privileged to download version $latestversion from the Moodle Marketplace (HTTP 401) to pin its checksum - the plugin likely requires a subscription/entitlement the configured --token doesn't cover"
                );
            }
            if ($checksumline !== null) {
                $message .= "\n" . $checksumline;
            }
        }

        return $message;
    }

    /**
     * Whether applyVersion() would actually write $versionfile, given
     * $currentversion and $latestversion - without writing anything itself.
     * Mirrors applyVersion()'s own branching (pinned-to-0 is handled by the
     * caller before this is relevant).
     *
     * @param string|null $currentversion
     * @param string      $latestversion
     * @return bool
     */
    protected function willChangeVersion($currentversion, $latestversion)
    {
        if ($currentversion === null) {
            return true;
        }
        if ($currentversion === $latestversion) {
            return false;
        }
        if ((int) $currentversion > (int) $latestversion) {
            return false; // applyVersion() never downgrades a locally newer/pinned version.
        }
        return true;
    }

    /**
     * Build the report line for a component whose resolved zip 401s
     * (Marketplace subscription required) *before* any version write was
     * attempted - the version file (if any) is left exactly as it was.
     *
     * @param string      $component
     * @param string|null $currentversion
     * @param string      $latestversion
     * @return string
     */
    protected function reportMarketplaceUnauthorized($component, $currentversion, $latestversion)
    {
        $kept = $currentversion === null
            ? 'leaving version file unwritten'
            : "keeping local version $currentversion";
        $reason = "not privileged to download version $latestversion from the Moodle Marketplace (HTTP 401) - "
            . "the plugin likely requires a subscription/entitlement the configured --token doesn't cover";

        return "SKIP   $component: $reason, $kept\n" . $this->marketplaceUnauthorizedAnnotation($component, $reason);
    }

    /**
     * A single warning line about a Marketplace 401, either as a GitHub
     * Actions `::warning::` workflow command (when $CI is set - see
     * https://docs.github.com/en/actions/using-workflows/workflow-commands-for-github-actions)
     * or a plain "WARNING" line otherwise.
     *
     * @param string $component
     * @param string $reason
     * @return string
     */
    protected function marketplaceUnauthorizedAnnotation($component, $reason)
    {
        if ($this->isRunningInCi()) {
            return '::warning title=Moodle Marketplace subscription required::' . $component . ': ' . $reason;
        }
        return "WARNING $component: $reason";
    }

    /**
     * @return bool whether $CI looks set to a truthy value, as GitHub
     *   Actions (and most other CI providers) do.
     */
    protected function isRunningInCi()
    {
        $ci = getenv('CI');
        if ($ci === false || $ci === '') {
            return false;
        }
        return !in_array(strtolower($ci), array('false', '0'), true);
    }

    /**
     * Make sure `<componentdir>/checksum` holds the sha256 of $version's
     * zip, downloading it (via the shared PluginCache, same as
     * plugin-clamscan) only when that's not already the case.
     *
     * @param string $component
     * @param string $componentdir
     * @param string $version
     * @param string $downloadurl
     * @param bool   $forcerecompute true if $version was just written (so any
     *   existing checksum file is necessarily for the old, no-longer-current
     *   version and must be replaced regardless of its content)
     * @return string|null a report line, or null if nothing needed doing
     * @throws \RuntimeException if the zip can't be downloaded/verified
     */
    protected function reconcileChecksum($component, $componentdir, $version, $downloadurl, $forcerecompute)
    {
        $checksumfile = $componentdir . '/checksum';
        $existing = $this->readVersionFile($checksumfile);
        if (!$forcerecompute && $existing !== null) {
            return null;
        }

        $tempdir = null;
        try {
            list($downloadedfile, $tempdir) = $this->downloadPluginZip($component, $version, $downloadurl);
            PluginChecksum::verify($downloadedfile, $component);
            $sha256 = hash_file('sha256', $downloadedfile);
        } catch (MarketplaceUnauthorizedException $e) {
            // Let the caller distinguish "not privileged" from any other
            // download failure - don't wrap it into a generic RuntimeException.
            throw $e;
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("could not pin checksum for $version: " . $e->getMessage());
        } finally {
            if ($tempdir !== null && is_dir($tempdir)) {
                exec('rm -rf ' . escapeshellarg($tempdir));
            }
        }

        file_put_contents($checksumfile, $sha256 . "\n");

        return "PIN    $component: checksum $sha256 for $version";
    }

    /**
     * Download (or fetch from the shared cache) $component's zip for
     * $version, same cache-then-HTTP flow as
     * PluginClamscan::downloadAndExtractPlugin() - just without the unzip
     * step, since only the raw bytes are needed here.
     *
     * @param string $component
     * @param string $version
     * @param string $downloadurl
     * @return array [string $downloadedfile, string $tempdir] - caller must
     *   remove $tempdir once done with it.
     * @throws \RuntimeException
     */
    protected function downloadPluginZip($component, $version, $downloadurl)
    {
        $tempdir = rtrim(sys_get_temp_dir(), '/') . '/moosh-plugin-list-update-' . getmypid() . '-' . uniqid() . '/';
        if (!file_exists($tempdir) && !mkdir($tempdir, 0755, true) && !is_dir($tempdir)) {
            throw new \RuntimeException("Failed to create temp directory $tempdir.");
        }

        $downloadedfile = $tempdir . $component . '.zip';

        if (PluginCache::fetch($component, $version, $downloadedfile)) {
            return array($downloadedfile, $tempdir);
        }

        $contents = @file_get_contents(
            $downloadurl,
            false,
            PluginDownload::createProxyContext($this->expandedOptions, $downloadurl)
        );
        // PHP's http:// stream wrapper populates $http_response_header as a
        // side effect of the call above, in this same scope, even when the
        // request fails with an HTTP error status - capture it immediately,
        // before anything else can run.
        $responseheaders = isset($http_response_header) ? $http_response_header : null;

        if ($contents === false) {
            if ($this->isMarketplaceUnauthorizedResponse($responseheaders)) {
                throw new MarketplaceUnauthorizedException(
                    "Not privileged to download $downloadurl (HTTP 401) - this plugin appears to require a "
                    . "Moodle Marketplace subscription/entitlement the configured --token doesn't cover."
                );
            }
            throw new \RuntimeException("Failed to download plugin from $downloadurl.");
        }
        file_put_contents($downloadedfile, $contents);

        if (!PluginCache::isValidZip($downloadedfile)) {
            @unlink($downloadedfile);
            throw new \RuntimeException("Downloaded file from $downloadurl is not a valid, non-empty zip archive.");
        }

        PluginCache::store($component, $version, $downloadedfile);

        return array($downloadedfile, $tempdir);
    }

    /**
     * @param array|null $responseheaders as populated by PHP's http://
     *   stream wrapper into $http_response_header (a raw status line plus
     *   header lines, eg. "HTTP/1.1 401 Unauthorized"), or null if no HTTP
     *   response was received at all (eg. connection failure).
     * @return bool whether the response's status line was HTTP 401,
     *   matching the marketplace.moodle.com
     *   {"code":401,"message":"Not privileged to request the resource."}
     *   response for subscription-only plugins.
     */
    protected function isMarketplaceUnauthorizedResponse($responseheaders)
    {
        if (!is_array($responseheaders)) {
            return false;
        }
        foreach ($responseheaders as $header) {
            if (preg_match('#^HTTP/\S+\s+401(\s|$)#', (string) $header)) {
                return true;
            }
        }
        return false;
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
