<?php
/**
 * moosh - Moodle Shell
 *
 * Scan a plugin for malware using a custom ClamAV/YARA ruleset, either the
 * plugin in the current directory (detected via version.php) or a plugin
 * downloaded from the Moodle plugins directory by frankenstyle name.
 *
 * moosh plugin-clamscan [<plugin_name>] [-r <version>] [--proxy <uri>]
 *                        [-d <db>]... [-i] [--log <path>]
 *
 * Scan the plugin in the current directory
 * @example moosh plugin-clamscan
 *
 * Download and scan a specific plugin/version with a custom YARA ruleset
 * @example moosh plugin-clamscan -d /path/to/yara-rules -i mod_board
 *
 * Exit codes (mirrors clamscan's own convention):
 *   0 - clean
 *   1 - malware/virus found
 *   2 - some other error occurred (including: clamscan not installed,
 *       plugin/version not found, download or checksum failure, no
 *       version.php found in the current directory)
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh\Command\Generic\Plugin;

use Moosh\MooshCommand;
use Moosh\PluginCache;
use Moosh\PluginChecksum;
use Moosh\PluginZip;

class PluginClamscan extends MooshCommand
{
    /** @var int see EXIT_* constants below */
    const EXIT_CLEAN = 0;
    const EXIT_MALWARE_FOUND = 1;
    const EXIT_ERROR = 2;

    public function __construct()
    {
        parent::__construct('clamscan', 'plugin');

        // The plugin name is optional: with none given, the plugin in the
        // current directory (detected via version.php) is scanned instead.
        $this->addArgument('plugin_name');
        $this->minArguments = 0;

        $this->addOption('r|release:', 'Specify exact version to scan e.g. 2019010700 (only used with a plugin name). Defaults to the newest available version.');
        $this->addOption('proxy:', 'Proxy URI scheme. Example: tcp://user:pass@host:port. You may also use env var http_proxy.');

        // --database can be given multiple times (clamscan -d/--database
        // itself accepts being repeated to load several databases/rulesets),
        // so this is registered directly against the option spec with
        // isa('array') rather than through the addOption() helper, which
        // only supports single-value options.
        $option = $this->spec->add('d|database:', 'ClamAV/YARA database or directory to load (clamscan -d/--database). May be given multiple times.');
        $option->isa('array');
        $this->options[$option->long] = array();

        $this->addOption('i|infected', 'Only print filenames that ARE infected (clamscan -i/--infected).');
        $this->addOption('log:', 'Save scan report to a file (clamscan --log=).');
    }

    public function bootstrapLevel()
    {
        // Scanning never needs a working Moodle site - not even to resolve a
        // plugin's compatible version, since clamscan doesn't care about
        // Moodle compatibility, only about what code it's being asked to scan.
        return self::$BOOTSTRAP_NONE;
    }

    public function requireHomeWriteable()
    {
        // Reads/writes the shared plugin download cache under home_dir().
        return true;
    }

    public function execute()
    {
        $tempdir = null;
        $exitcode = self::EXIT_CLEAN;

        try {
            $clamscanBinary = $this->findClamscanBinary();
            if ($clamscanBinary === null) {
                throw new \RuntimeException(
                    "clamscan was not found in PATH. Install ClamAV (e.g. 'apt-get install clamav') " .
                    "before running plugin-clamscan."
                );
            }

            $pluginname = (isset($this->arguments[0]) && $this->arguments[0] !== '')
                ? $this->arguments[0]
                : null;

            if ($pluginname !== null) {
                list($pluginroot, $tempdir) = $this->downloadAndExtractPlugin($pluginname);
            } else {
                $pluginroot = $this->resolvePluginRootFromCwd($this->cwd);
            }

            $exitcode = $this->runClamscan($clamscanBinary, $pluginroot);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            $exitcode = self::EXIT_ERROR;
        } finally {
            if ($tempdir !== null && is_dir($tempdir)) {
                exec('rm -rf ' . escapeshellarg($tempdir));
            }
        }

        exit($exitcode);
    }

    /**
     * Locate the clamscan binary. Extracted as resolveClamscanBinaryFromLookup()
     * for testability - this method itself is just the (untestable) OS call.
     *
     * @return string|null absolute path, or null if not found
     */
    protected function findClamscanBinary()
    {
        return $this->resolveClamscanBinaryFromLookup(shell_exec('command -v clamscan 2>/dev/null'));
    }

    /**
     * @param string|null $lookupOutput raw output of a `command -v clamscan`-style lookup
     * @return string|null
     */
    protected function resolveClamscanBinaryFromLookup($lookupOutput)
    {
        $path = trim((string) $lookupOutput);
        return $path !== '' ? $path : null;
    }

    /**
     * Confirm $cwd looks like a plugin root (i.e. contains version.php),
     * for the "no plugin name given" case.
     *
     * @param string $cwd
     * @return string
     * @throws \RuntimeException if $cwd doesn't look like a plugin
     */
    protected function resolvePluginRootFromCwd($cwd)
    {
        $cwd = rtrim($cwd, '/');
        if (!is_file($cwd . '/version.php')) {
            throw new \RuntimeException(
                "No plugin name given and no version.php found in $cwd - " .
                "run this from a plugin's root directory, or pass a plugin name to download and scan."
            );
        }
        return $cwd;
    }

    /**
     * Build the clamscan command line as an array of already shell-escaped
     * tokens (kept as an array rather than a single string so it's directly
     * assertable in tests without re-parsing shell-quoting).
     *
     * @param string $clamscanBinary
     * @param string $pluginroot
     * @param array  $options expandedOptions-shaped array (database/infected/log)
     * @return string[]
     */
    protected function buildClamscanArgs($clamscanBinary, $pluginroot, array $options)
    {
        $args = array(escapeshellarg($clamscanBinary), '-r');

        $databases = isset($options['database']) ? $options['database'] : array();
        if (!is_array($databases)) {
            $databases = ($databases === '' || $databases === null) ? array() : array($databases);
        }
        foreach ($databases as $database) {
            $args[] = '-d';
            $args[] = escapeshellarg($database);
        }

        if (!empty($options['infected'])) {
            $args[] = '-i';
        }

        if (!empty($options['log'])) {
            $args[] = '--log=' . escapeshellarg($options['log']);
        }

        $args[] = escapeshellarg($pluginroot);

        return $args;
    }

    /**
     * Run clamscan against $pluginroot and relay its exit code.
     *
     * clamscan's own exit codes already match the contract this command
     * promises (0 clean, 1 malware found, 2 error), so they're passed
     * through as-is; anything unexpected is normalized to 2.
     *
     * @param string $clamscanBinary
     * @param string $pluginroot
     * @return int
     */
    protected function runClamscan($clamscanBinary, $pluginroot)
    {
        $args = $this->buildClamscanArgs($clamscanBinary, $pluginroot, $this->expandedOptions);
        $command = implode(' ', $args) . ' 2>&1';

        exec($command, $output, $exitcode);
        echo implode("\n", $output) . "\n";

        if ($exitcode === self::EXIT_CLEAN || $exitcode === self::EXIT_MALWARE_FOUND) {
            return $exitcode;
        }
        return self::EXIT_ERROR;
    }

    /**
     * Load ~/.moosh/plugins.json (shared with plugin-download/plugin-install).
     *
     * @return \stdClass
     * @throws \RuntimeException
     */
    protected function getPluginsData()
    {
        $pluginsfile = home_dir() . '/.moosh/plugins.json';

        $stat = @stat($pluginsfile);
        if (!$stat || time() - $stat['mtime'] > 60 * 60 * 24 || !$stat['size']) {
            throw new \RuntimeException(
                "plugins.json file not found or too old. Run 'moosh plugin-list' to download the newest plugins.json file."
            );
        }

        $decoded = json_decode(file_get_contents($pluginsfile));
        if ($decoded === null) {
            throw new \RuntimeException("plugins.json is not valid JSON - run 'moosh plugin-list' again.");
        }

        return $decoded;
    }

    /**
     * Resolve which version of $pluginname to scan: the exact requested
     * version if given, otherwise the newest version listed. Unlike
     * plugin-install, this does NOT filter by Moodle compatibility -
     * clamscan doesn't care whether a version could actually be installed,
     * only about what bytes it's being asked to scan.
     *
     * @param \stdClass $pluginsdata   decoded plugins.json
     * @param string    $pluginname    frankenstyle component name
     * @param string|null $requestedversion exact version, or null for newest
     * @return \stdClass the matched version entry (has ->version, ->downloadurl)
     * @throws \RuntimeException if the plugin/version can't be found
     */
    protected function resolvePluginVersion($pluginsdata, $pluginname, $requestedversion)
    {
        foreach ((isset($pluginsdata->plugins) ? $pluginsdata->plugins : array()) as $plugin) {
            if (empty($plugin->component) || $plugin->component !== $pluginname) {
                continue;
            }

            if ($requestedversion !== null) {
                foreach ($plugin->versions as $version) {
                    if ((string) $version->version === (string) $requestedversion) {
                        return $version;
                    }
                }
                throw new \RuntimeException("Version $requestedversion of $pluginname not found in plugins.json.");
            }

            $latest = null;
            foreach ($plugin->versions as $version) {
                if ($latest === null || $version->version > $latest->version) {
                    $latest = $version;
                }
            }
            if ($latest === null) {
                throw new \RuntimeException("No versions found for $pluginname in plugins.json.");
            }
            return $latest;
        }

        throw new \RuntimeException("Couldn't find $pluginname in plugins.json.");
    }

    /**
     * Download (or fetch from cache) $pluginname, verify its pinned
     * checksum if any, and extract it to a fresh temp directory.
     *
     * @param string $pluginname
     * @return array [string $pluginroot, string $tempdir] - caller is
     *   responsible for deleting $tempdir once done with it.
     * @throws \RuntimeException on any download/verification/extraction failure
     */
    protected function downloadAndExtractPlugin($pluginname)
    {
        $requestedversion = !empty($this->expandedOptions['release']) ? $this->expandedOptions['release'] : null;

        $pluginsdata = $this->getPluginsData();
        $version = $this->resolvePluginVersion($pluginsdata, $pluginname, $requestedversion);
        $downloadurl = $version->downloadurl;

        $tempdir = rtrim(sys_get_temp_dir(), '/') . '/moosh-plugin-clamscan-' . getmypid() . '-' . uniqid() . '/';
        if (!file_exists($tempdir) && !mkdir($tempdir, 0755, true) && !is_dir($tempdir)) {
            throw new \RuntimeException("Failed to create temp directory $tempdir.");
        }

        $downloadedfile = $tempdir . $pluginname . '.zip';

        if (PluginCache::fetch($pluginname, $version->version, $downloadedfile)) {
            echo "Using cached copy of $pluginname ($version->version) from $downloadedfile\n";
        } else {
            $contents = @file_get_contents(
                $downloadurl,
                false,
                PluginDownload::createProxyContext($this->expandedOptions, $downloadurl)
            );
            if ($contents === false) {
                throw new \RuntimeException("Failed to download plugin from $downloadurl.");
            }
            file_put_contents($downloadedfile, $contents);

            if (!PluginCache::isValidZip($downloadedfile)) {
                @unlink($downloadedfile);
                throw new \RuntimeException("Downloaded file from $downloadurl is not a valid, non-empty zip archive.");
            }

            PluginCache::store($pluginname, $version->version, $downloadedfile);
        }

        // Same integrity guarantee as plugin-install: refuse to scan (and by
        // extension, refuse to let a caller trust a clean result for) bytes
        // that don't match what was pinned for this version.
        PluginChecksum::verify($downloadedfile, $pluginname);

        $unzipdir = $tempdir . 'extracted';
        mkdir($unzipdir, 0755, true);
        exec('unzip ' . escapeshellarg($downloadedfile) . ' -d ' . escapeshellarg($unzipdir) . ' 2>&1', $unzipoutput, $unzipstatus);
        if ($unzipstatus !== 0) {
            throw new \RuntimeException("Failed to extract $downloadedfile:\n" . implode("\n", $unzipoutput));
        }

        $pluginroot = PluginZip::findPluginRootDir($unzipdir);

        return array($pluginroot, $tempdir);
    }
}
