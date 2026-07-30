<?php
/**
 * moosh - Moodle Shell
 *
 * Apply a "declarative plugin list" (one subdirectory per Frankenstyle
 * component, each holding a `version` file - see moosh plugin-list-update,
 * which keeps those version files current) to an actual Moodle
 * installation: install, upgrade, uninstall, or remove-files-only, plus a
 * ClamAV scan of anything newly installed. This is a PHP port of
 * install_component_version.sh / the relevant parts of moodle_plugins_lib.rc.
 *
 * moosh plugin-list-apply [-d <directory>] [-m <moodle-root>] [-k]
 *                          [-r <proxy>] [-t <token>] [component ...]
 *
 * Apply every plugin directory found in the current directory
 * @example moosh plugin-list-apply
 *
 * Apply only mod_board, against a specific Moodle root
 * @example moosh plugin-list-apply -m /var/www/moodle mod_board
 *
 * `version` file sentinel values (must already be reconciled by
 * plugin-list-update or set by hand - this command only reads them):
 *   > 1  install/upgrade to this exact version
 *   0    uninstall completely, including its database tables
 *   -1   remove the plugin's files only, leave the database untouched
 *  (missing version file is an error - this command does not guess)
 *
 * package_* directories are a special case (no moodle.org presence): their
 * install/uninstall/path-resolution is delegated to fixed shell scripts
 * under <component>/bin/, called with cwd = the Moodle root, exactly as
 * moodle_plugins_lib.rc does. See docs/package-plugins.md-style comments
 * throughout this file for the exact calling convention of each script.
 *
 * Like the original install_component_version.sh, this aborts on the first
 * component that fails by default (--keep-going opts into aggregating
 * failures and processing the rest instead, matching plugin-list-update's
 * behaviour).
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh\Command\Generic\Plugin;

use Moosh\MooshCommand;

class PluginListApply extends MooshCommand
{
    const SENTINEL_REMOVE_FILES = '-1';
    const SENTINEL_UNINSTALL = '0';

    /** @var string absolute path to the declarative plugin list directory (--directory) */
    private $configPluginDirectory;

    /** @var \stdClass|null decoded plugins.json, cached for the duration of one execute() (only needed by the install_plugins.php fallback path / cannotdowngrade retries indirectly through getRequestedVersion) */
    private $pluginsdata;

    public function __construct()
    {
        parent::__construct('list-apply', 'plugin');

        // Zero or more Frankenstyle component names. None given -> every
        // subdirectory of --directory is treated as a candidate, exactly
        // like plugin-list-update.
        $this->addArgument('plugin_name');
        $this->minArguments = 0;
        $this->maxArguments = 255;

        $this->addOption('d|directory:', 'Directory holding one subdirectory per plugin (the declarative plugin list).', '.');
        $this->addOption('m|moodle-root:', 'Moodle root to install into / used as cwd for package_* bin/ scripts. Defaults to the parent directory of --directory.');
        $this->addOption('k|keep-going', "Don't abort on the first component that fails; process the rest and report every failure at the end. Default matches install_component_version.sh: abort immediately.");
        $this->addOption('r|proxy:', 'Proxy URI scheme. Example: tcp://user:pass@host:port. You may also use env var http_proxy.');
        $this->addOption('t|token:', 'Moodle Marketplace API token, used as a Bearer token for the download request. You may also use env var MOODLE_MARKETPLACE_TOKEN.');
    }

    public function bootstrapLevel()
    {
        // Actually installing/uninstalling always needs a working Moodle
        // site - unlike plugin-list-update, there's no "just report the
        // version" mode that could skip this.
        return self::$BOOTSTRAP_FULL;
    }

    public function requireHomeWriteable()
    {
        // Reads/writes the shared plugins.json cache under home_dir(), and
        // writes ~/.mooshrc.php (see ensureMooshUserAgentConfig()).
        return true;
    }

    public function execute()
    {
        $this->ensureMooshUserAgentConfig();

        $basedir = rtrim($this->expandedOptions['directory'], '/');
        if ($basedir === '') {
            $basedir = '/';
        }
        if (!is_dir($basedir)) {
            cli_error("Directory not found: $basedir");
        }
        $this->configPluginDirectory = realpath($basedir);

        $moodleroot = !empty($this->expandedOptions['moodle-root'])
            ? rtrim($this->expandedOptions['moodle-root'], '/')
            : dirname($this->configPluginDirectory);

        if (!is_file($moodleroot . '/config.php')) {
            cli_error("Moodle root does not look valid (no config.php found): $moodleroot");
        }

        $components = $this->arguments;
        if (empty($components)) {
            $components = $this->discoverComponents($this->configPluginDirectory);
        }

        if (empty($components)) {
            echo "No plugin directories found in {$this->configPluginDirectory}.\n";
            return;
        }

        $keepgoing = !empty($this->expandedOptions['keep-going']);
        $failed = array();

        foreach ($components as $component) {
            $componentdir = $this->configPluginDirectory . '/' . $component;
            if (!is_dir($componentdir)) {
                echo "SKIP    $component: directory not found ($componentdir)\n";
                $failed[] = $component;
                if (!$keepgoing) {
                    $this->abort($failed);
                }
                continue;
            }

            try {
                $this->applyComponent($component, $componentdir, $moodleroot);
            } catch (\RuntimeException $e) {
                echo "ERROR   $component: " . $e->getMessage() . "\n";
                $failed[] = $component;
                if (!$keepgoing) {
                    $this->abort($failed);
                }
            }
        }

        if (!empty($failed)) {
            $this->abort($failed);
        }
    }

    private function abort(array $failed)
    {
        fwrite(STDERR, "Failed component(s): " . implode(', ', $failed) . "\n");
        exit(1);
    }

    /**
     * Same discovery as PluginListUpdate::discoverComponents(): every
     * non-hidden subdirectory of $basedir.
     *
     * @param string $basedir
     * @return string[]
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
     * Mirrors moodle_plugins_lib.rc's setup_moosh_rc(): moodle.org's plugin
     * download endpoint has been known to reject the default PHP/curl user
     * agent with a 403; this pins a more typical one, once.
     */
    protected function ensureMooshUserAgentConfig()
    {
        $path = home_dir() . '/.mooshrc.php';
        if (is_file($path) && strpos(file_get_contents($path), 'user_agent') !== false) {
            return;
        }
        file_put_contents($path, "<?php\nini_set('user_agent', 'curl/7.81.0');\n");
    }

    // -------------------------------------------------------------------
    // Per-component state machine (mirrors install_component_version.sh)
    // -------------------------------------------------------------------

    /**
     * @throws \RuntimeException on any failure - caller decides whether to
     *   abort immediately or keep going, matching install_component_version.sh's
     *   own "exit 1 on any error" default.
     */
    protected function applyComponent($component, $componentdir, $moodleroot)
    {
        $componentpath = $this->getComponentPath($component, $componentdir, $moodleroot);
        $current = $this->getInstalledVersion($component, $componentdir, $moodleroot, $componentpath);
        $requested = $this->getRequestedVersion($component, $componentdir, $moodleroot);

        echo "-----\n";
        if ($requested === null || $requested === '') {
            throw new \RuntimeException("could not determine requested version, exiting");
        }
        echo "$component requested: $requested installed: $current\n";

        if ($current === $requested) {
            $this->runAlwaysRunHookIfPresent($component, $componentdir, $moodleroot);
            echo "OK      $component: already at $current\n";
            return;
        }

        if ($requested === self::SENTINEL_REMOVE_FILES) {
            $this->removePluginFiles($component, $componentdir, $moodleroot, $componentpath);
            echo "REMOVED $component: files removed (database left untouched)\n";
            return;
        }

        if ($requested === self::SENTINEL_UNINSTALL) {
            if ((int) $current <= -1) {
                $this->uninstallForce($component, $componentdir, $moodleroot);
                echo "OK      $component: already uninstalled, database cleaned up (best effort)\n";
            } else {
                $this->uninstall($component, $componentdir, $moodleroot, $componentpath);
                echo "REMOVED $component: uninstalled\n";
            }
            return;
        }

        // requested > 1: install or upgrade.
        $this->installRequestedVersion($component, $componentdir, $moodleroot, $requested);

        $updated = $this->getInstalledVersion($component, $componentdir, $moodleroot, $componentpath);
        if ($updated === null || $updated === '') {
            throw new \RuntimeException("requested: $requested could not be installed, exiting");
        }
        if ($updated !== $requested) {
            throw new \RuntimeException("requested: $requested could not be upgraded, $updated is still deployed, exiting");
        }

        $scanresult = $this->scanForMalware($component, $moodleroot, $componentpath);
        if ($scanresult !== PluginClamscan::EXIT_CLEAN) {
            throw new \RuntimeException(
                "malware scan " . ($scanresult === PluginClamscan::EXIT_MALWARE_FOUND ? "found malware" : "failed")
                . " (exit $scanresult) after installing - aborting before .gitignore updates. " .
                "See {$this->configPluginDirectory}/.clamav/report/clamav.log"
            );
        }

        $this->addIgnorePathsToGitignore($component, $componentdir, $moodleroot, $componentpath);
        echo "INSTALLED $component: $current -> $requested\n";
    }

    /**
     * @param string $component
     * @param string $componentdir
     * @param string $moodleroot
     * @throws \RuntimeException if the hook script exists but fails
     */
    protected function runAlwaysRunHookIfPresent($component, $componentdir, $moodleroot)
    {
        $script = $componentdir . '/bin/install_requested_always_run.sh';
        if (!is_file($script)) {
            return;
        }
        list($output, $exitcode) = $this->runScript($script, array(), $moodleroot);
        echo implode("\n", $output) . "\n";
        if ($exitcode !== 0) {
            throw new \RuntimeException("bin/install_requested_always_run.sh exited with status $exitcode");
        }
    }

    // -------------------------------------------------------------------
    // version file reading (requested) / version.php reading (installed)
    // -------------------------------------------------------------------

    /**
     * @return string the requested version as a validated integer string
     * @throws \RuntimeException if the version file/script exists but its
     *   content isn't a valid integer, or a package_* script fails
     */
    protected function getRequestedVersion($component, $componentdir, $moodleroot)
    {
        if (strpos($component, 'package_') === 0) {
            list($output, $exitcode) = $this->runScript(
                $componentdir . '/bin/get_requested_version.sh', array(), $moodleroot
            );
            if ($exitcode !== 0) {
                throw new \RuntimeException(
                    "bin/get_requested_version.sh exited with status $exitcode: " . implode("\n", $output)
                );
            }
            $requested = trim(implode("\n", $output));
        } else {
            $requested = $this->readVersionFile($componentdir . '/version');
            if ($requested === null) {
                $requested = self::SENTINEL_REMOVE_FILES;
            }
        }

        if (!preg_match('/^-?[0-9]+$/', $requested)) {
            throw new \RuntimeException("requested version is not a valid integer: '$requested'");
        }
        return $requested;
    }

    /**
     * @return string the installed version as a string, or "-1" if not installed
     * @throws \RuntimeException if version.php exists but $plugin->version
     *   couldn't be determined, or a package_* script fails
     */
    protected function getInstalledVersion($component, $componentdir, $moodleroot, $componentpath)
    {
        if (strpos($component, 'package_') === 0) {
            list($output, $exitcode) = $this->runScript(
                $componentdir . '/bin/get_installed_version.sh', array(), $moodleroot
            );
            if ($exitcode !== 0) {
                throw new \RuntimeException(
                    "bin/get_installed_version.sh exited with status $exitcode: " . implode("\n", $output)
                );
            }
            return trim(implode("\n", $output));
        }

        $versionphp = rtrim($moodleroot, '/') . '/' . $componentpath . '/version.php';
        if (!is_file($versionphp)) {
            return '-1';
        }

        $version = $this->evalVersionPhp($versionphp);
        if ($version === null) {
            throw new \RuntimeException("could not get \$plugin->version from $versionphp");
        }
        return (string) $version;
    }

    /**
     * @param string $versionfile
     * @return string|null trimmed content, or null if the file doesn't exist
     */
    protected function readVersionFile($versionfile)
    {
        if (!is_file($versionfile)) {
            return null;
        }
        return rtrim(file_get_contents($versionfile), "\r\n");
    }

    /**
     * Evaluate a plugin's version.php in the current process (rather than
     * shelling out to a second `php -r`, as moodle_plugins_lib.rc does) and
     * return $plugin->version (or $module->version - some plugin types use
     * that variable name instead).
     *
     * A handful of constants some version.php files reference without
     * actually needing a full Moodle bootstrap are pre-defined, matching
     * the fallback strategy get_installed_version() in moodle_plugins_lib.rc
     * already relies on.
     *
     * @param string $versionphp
     * @return int|null
     */
    protected function evalVersionPhp($versionphp)
    {
        foreach (array(
            'MOODLE_INTERNAL' => true,
            'MATURITY_ALPHA' => 50,
            'MATURITY_BETA' => 150,
            'MATURITY_RC' => 180,
            'MATURITY_STABLE' => 200,
            'ANY_VERSION' => 'any',
        ) as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }

        $plugin = new \stdClass();
        $module = new \stdClass();

        $loader = function ($__versionphp_path) use ($plugin, $module) {
            ob_start();
            try {
                include $__versionphp_path;
            } catch (\Throwable $e) {
                // Swallow: some version.php files reference things only a
                // real Moodle bootstrap provides. We only care whether
                // ->version got set before it blew up.
            } finally {
                ob_end_clean();
            }
        };
        $loader($versionphp);

        if (isset($plugin->version) && is_numeric($plugin->version)) {
            return (int) $plugin->version;
        }
        if (isset($module->version) && is_numeric($module->version)) {
            return (int) $module->version;
        }
        return null;
    }

    // -------------------------------------------------------------------
    // get_component_path() port
    // -------------------------------------------------------------------

    /**
     * @return string install path relative to the Moodle root (no leading/trailing slash)
     * @throws \RuntimeException if $component matches no known prefix, or a
     *   package_* script is missing/fails
     */
    protected function getComponentPath($component, $componentdir, $moodleroot)
    {
        if (strpos($component, 'package_') === 0) {
            list($output, $exitcode) = $this->runScript(
                $componentdir . '/bin/get_component_path.sh', array(), $moodleroot
            );
            if ($exitcode !== 0) {
                throw new \RuntimeException(
                    "bin/get_component_path.sh exited with status $exitcode: " . implode("\n", $output)
                );
            }
            $path = trim(implode("\n", $output));
            if ($path === '') {
                throw new \RuntimeException("bin/get_component_path.sh produced no output");
            }
            return $path;
        }

        return $this->mapComponentPath($component);
    }

    /**
     * get_component_ignore_path(): identical to getComponentPath() for
     * everything except package_*, which has its own script.
     *
     * @return string one or more (newline separated) paths relative to the
     *   Moodle root that should get a catch-all .gitignore entry, or '' if none
     */
    protected function getComponentIgnorePath($component, $componentdir, $moodleroot, $componentpath)
    {
        if (strpos($component, 'package_') === 0) {
            list($output, $exitcode) = $this->runScript(
                $componentdir . '/bin/get_component_ignore_path.sh', array(), $moodleroot
            );
            if ($exitcode !== 0) {
                throw new \RuntimeException(
                    "bin/get_component_ignore_path.sh exited with status $exitcode: " . implode("\n", $output)
                );
            }
            return trim(implode("\n", $output));
        }

        return $componentpath;
    }

    protected function addIgnorePathsToGitignore($component, $componentdir, $moodleroot, $componentpath)
    {
        $ignorepaths = $this->getComponentIgnorePath($component, $componentdir, $moodleroot, $componentpath);
        if ($ignorepaths === '' || $ignorepaths === null) {
            return;
        }

        foreach (preg_split('/\r\n|\r|\n/', $ignorepaths) as $ignorepath) {
            $ignorepath = trim($ignorepath);
            if ($ignorepath === '') {
                continue;
            }
            $gitignore = rtrim($moodleroot, '/') . '/' . $ignorepath . '/.gitignore';
            echo "adding $gitignore entry for $component\n";
            file_put_contents($gitignore, "\n*", FILE_APPEND);
            @chmod($gitignore, 0644 | (fileperms($gitignore) & 0777) | 0044);
        }
    }

    /**
     * Table-driven port of get_component_path()'s case statement. Order
     * only matters for the one genuine overlap in the original (the
     * "local_resort_courses" literal vs. the generic "local_*" prefix,
     * handled explicitly below) - every prefix here is otherwise mutually
     * exclusive with every other, so the table order is for readability /
     * fidelity to the original rather than correctness.
     *
     * @param string $component
     * @return string
     * @throws \RuntimeException if nothing matches (mirrors the original's
     *   `*)` case, which prints an error and exit 1)
     */
    protected function mapComponentPath($component)
    {
        if ($component === 'local_resort_courses') {
            return 'local/resort_courses';
        }
        if ($component === 'message_jabber') {
            return 'message/output/jabber';
        }

        // [bash prefix (as literally written in the case pattern), Moodle
        // path prefix, whether the remainder gets underscores->slashes]
        static $table = array(
            array('auth_', 'auth/', false),
            array('assign', 'mod/assign/', true),
            array('atto_', 'lib/editor/atto/plugins/', true),
            array('availability_', 'availability/condition/', true),
            array('block_', 'blocks/', false),
            array('booktool_', 'mod/book/tool/', true),
            array('editor_', 'lib/editor/', true),
            array('enrol_', 'enrol/', true),
            array('filter_', 'filter/', true),
            array('format_', 'course/format/', true),
            array('gradereport_', 'grade/report/', true),
            array('local_', 'local/', false),
            array('mod_', 'mod/', true),
            array('plagiarism_', 'plagiarism/', true),
            array('portfolio_', 'portfolio/', true),
            array('qbehaviour_', 'question/behaviour/', false),
            array('qformat_', 'question/format/', true),
            array('qtype_', 'question/type/', true),
            array('quiz_', 'mod/quiz/report/', true),
            array('report_', 'report/', true),
            array('repository_', 'repository/', true),
            array('theme_', 'theme/', false),
            array('tiny_', 'lib/editor/tiny/plugins/', false),
            array('tinymce_', 'lib/editor/tinymce/plugins/', true),
            array('tool_', 'admin/tool/', true),
            array('webservice_', 'webservice/', true),
        );

        foreach ($table as list($prefix, $pathprefix, $convertUnderscores)) {
            if (strncmp($component, $prefix, strlen($prefix)) === 0) {
                $remainder = substr($component, strlen($prefix));
                if ($convertUnderscores) {
                    $remainder = str_replace('_', '/', $remainder);
                }
                return rtrim($pathprefix, '/') . ($remainder !== '' ? '/' . $remainder : '');
            }
        }

        throw new \RuntimeException("unknown component $component (no path mapping rule matched)");
    }

    // -------------------------------------------------------------------
    // uninstall / remove-files
    // -------------------------------------------------------------------

    protected function removePluginFiles($component, $componentdir, $moodleroot, $componentpath)
    {
        if (strpos($component, 'package_') === 0) {
            $this->runPackageUninstallScript($component, $componentdir, $moodleroot);
            return;
        }

        if ($componentpath === '' || $componentpath === null) {
            throw new \RuntimeException("component_path was empty");
        }

        $fulltarget = rtrim($moodleroot, '/') . '/' . $componentpath;
        if (is_file($fulltarget . '/.git')) {
            echo "plugin $component is managed by git - leaving as is\n";
            return;
        }
        if (!is_file($fulltarget . '/version.php')) {
            // Nothing installed to remove.
            return;
        }

        echo "Deleting files for $component in $fulltarget\n";
        exec('rm -rf ' . escapeshellarg($fulltarget) . ' 2>&1', $output, $exitcode);
        echo implode("\n", $output) . "\n";
        if ($exitcode !== 0) {
            throw new \RuntimeException("rm -rf $fulltarget failed with exit code $exitcode");
        }

        $cwd = getcwd();
        chdir($moodleroot);
        exec('git submodule update --recursive --init 2>&1');
        chdir($cwd);
    }

    protected function uninstall($component, $componentdir, $moodleroot, $componentpath)
    {
        if (strpos($component, 'package_') === 0) {
            $this->runPackageUninstallScript($component, $componentdir, $moodleroot);
            return;
        }

        echo "Uninstalling $component\n";
        $moosh = $this->resolveMooshExecutable();
        exec(
            $moosh . ' -p ' . escapeshellarg($moodleroot) . ' -l -n plugin-uninstall ' . escapeshellarg($component) . ' 2>&1',
            $output,
            $exitcode
        );
        $text = implode("\n", $output);

        if (strpos($text, 'antivirus_clamav') !== false) {
            echo "WARN: plugin-uninstall for $component returned an antivirus_clamav false positive - continuing anyway\n";
        } elseif ($exitcode !== 0 && $exitcode !== 1) {
            // exit 1 from plugin-uninstall is tolerated here (matches the
            // bash __moosh_return==1 ? 0 special-case); anything else isn't.
            echo $text . "\n";
        }

        if ($componentpath === '' || $componentpath === null) {
            throw new \RuntimeException("component_path was empty");
        }

        $fulltarget = rtrim($moodleroot, '/') . '/' . $componentpath;
        if (is_file($fulltarget . '/.git')) {
            echo "plugin $component is managed by git - leaving as is\n";
            return;
        }
        if (is_file($fulltarget . '/version.php')) {
            echo "WARN: uninstall of $component left version.php in place - removing files directly\n";
            $this->removePluginFiles($component, $componentdir, $moodleroot, $componentpath);
        }
    }

    protected function uninstallForce($component, $componentdir, $moodleroot)
    {
        if (strpos($component, 'package_') === 0) {
            $this->runPackageUninstallScript($component, $componentdir, $moodleroot);
            return;
        }

        // Best effort, always considered a success - matches uninstall_force()
        // in moodle_plugins_lib.rc (`|| return 0`): this path means "already
        // gone, just make sure the database agrees", not "must succeed".
        $moosh = $this->resolveMooshExecutable();
        exec($moosh . ' -p ' . escapeshellarg($moodleroot) . ' -l -n plugin-uninstall ' . escapeshellarg($component) . ' >/dev/null 2>&1');
    }

    /**
     * bin/uninstall_requested_version.sh is used identically by uninstall(),
     * uninstallForce(), AND removePluginFiles() in the bash original. It's
     * also the one package_* script that commonly doesn't exist yet (see
     * package_kaltura), so this gives one clear, specific error for that.
     *
     * @throws \RuntimeException if the script is missing or fails
     */
    protected function runPackageUninstallScript($component, $componentdir, $moodleroot)
    {
        $script = $componentdir . '/bin/uninstall_requested_version.sh';
        if (!is_file($script)) {
            throw new \RuntimeException(
                "could not find $component/bin/uninstall_requested_version.sh - this package_* plugin " .
                "has no uninstall script yet; add one before requesting version 0 or -1 for it."
            );
        }
        list($output, $exitcode) = $this->runScript($script, array($component), $moodleroot);
        echo implode("\n", $output) . "\n";
        if ($exitcode !== 0) {
            throw new \RuntimeException("bin/uninstall_requested_version.sh exited with status $exitcode");
        }
    }

    // -------------------------------------------------------------------
    // install / upgrade (install_requested_version() port)
    // -------------------------------------------------------------------

    /**
     * @param string $component
     * @param string $componentdir
     * @param string $moodleroot
     * @param string $requestedversion
     * @param int    $depth internal recursion guard for 'requires'/cannotdowngrade resolution
     * @throws \RuntimeException
     */
    protected function installRequestedVersion($component, $componentdir, $moodleroot, $requestedversion, $depth = 0)
    {
        if ($depth > 5) {
            throw new \RuntimeException("dependency resolution recursion too deep for $component - possible circular 'requires'");
        }

        if (strpos($component, 'package_') === 0) {
            list($output, $exitcode) = $this->runScript(
                $componentdir . '/bin/install_requested_version.sh',
                array($component, $requestedversion),
                $moodleroot
            );
            echo implode("\n", $output) . "\n";
            if ($exitcode !== 0) {
                throw new \RuntimeException("bin/install_requested_version.sh exited with status $exitcode");
            }
            return;
        }

        $this->installRequiresFileDependencies($component, $componentdir, $moodleroot, $depth);

        $componentpath = $this->getComponentPath($component, $componentdir, $moodleroot);
        $moosh = $this->resolveMooshExecutable();

        $runInstall = function () use ($moosh, $moodleroot, $requestedversion, $component) {
            exec(
                $moosh . ' -p ' . escapeshellarg($moodleroot) . ' -l -n plugin-install -d -f -r '
                    . escapeshellarg($requestedversion) . ' ' . escapeshellarg($component) . ' 2>&1',
                $output,
                $exitcode
            );
            return array(implode("\n", $output), $exitcode);
        };

        list($text) = $runInstall();

        if (strpos($text, 'Failed to open stream: HTTP request failed! HTTP/1.1 403 Forbidden') !== false) {
            throw new \RuntimeException(
                "plugin-install for $component returned download error 403 - moodle.org may be blocking the default HTTP user agent"
            );
        }

        if (strpos($text, 'antivirus_clamav') !== false) {
            echo "WARN: plugin-install for $component returned an antivirus_clamav false positive - continuing anyway\n";
            return;
        }

        if (strpos($text, 'plugins.json file not found or too old') !== false) {
            echo "INFO: plugins.json stale, refreshing and retrying $component\n";
            $this->refreshPluginsJsonCache();
            $this->installRequestedVersion($component, $componentdir, $moodleroot, $requestedversion, $depth + 1);
            return;
        }

        if (strpos($text, 'detectedmisplacedplugin') !== false) {
            echo "WARN: plugin-install for $component reported a misplaced plugin - retrying via install_plugins.php\n";
            $this->reinstallViaInstallPluginsPhp($component, $moodleroot, $componentpath, $requestedversion);
            return;
        }

        if (strpos($text, 'PHP Parse error:') !== false) {
            throw new \RuntimeException("plugin-install for $component hit a fatal parse error:\n$text");
        }

        if (strpos($text, 'cannotdowngrade') !== false) {
            $this->resolveCannotDowngrade($component, $componentdir, $moodleroot, $text, $depth, $runInstall);
        }

        $updated = $this->getInstalledVersion($component, $componentdir, $moodleroot, $componentpath);
        if ($updated !== $requestedversion) {
            echo "WARN: installed version $updated does not match requested $requestedversion for $component - retrying via install_plugins.php\n";
            $this->reinstallViaInstallPluginsPhp($component, $moodleroot, $componentpath, $requestedversion);
        }
    }

    /**
     * Recursively installs each component listed in <componentdir>/requires
     * (one Frankenstyle name per line, '#' comments and blank lines ignored)
     * before the component itself gets installed.
     *
     * @throws \RuntimeException if a requirement is missing, marked for
     *   uninstall, or its own install fails
     */
    protected function installRequiresFileDependencies($component, $componentdir, $moodleroot, $depth)
    {
        $requiresfile = $componentdir . '/requires';
        if (!is_file($requiresfile)) {
            return;
        }

        foreach (file($requiresfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $requiredcomponent = trim($line);
            if ($requiredcomponent === '' || $requiredcomponent[0] === '#') {
                continue;
            }

            $requiredcomponentdir = dirname($componentdir) . '/' . $requiredcomponent;
            if (!is_dir($requiredcomponentdir)) {
                throw new \RuntimeException(
                    "$component requires $requiredcomponent but its directory was not found ($requiredcomponentdir)"
                );
            }

            $requiredpath = $this->getComponentPath($requiredcomponent, $requiredcomponentdir, $moodleroot);
            $requiredcurrent = $this->getInstalledVersion($requiredcomponent, $requiredcomponentdir, $moodleroot, $requiredpath);
            $requiredrequested = $this->getRequestedVersion($requiredcomponent, $requiredcomponentdir, $moodleroot);

            echo "Installing requirement $requiredcomponent for $component (requested: $requiredrequested, installed: $requiredcurrent)\n";

            if ($requiredcurrent !== $requiredrequested) {
                if ($requiredrequested === self::SENTINEL_UNINSTALL) {
                    throw new \RuntimeException("$component requires $requiredcomponent but $requiredcomponent is marked for uninstall");
                }
                $this->installRequestedVersion($requiredcomponent, $requiredcomponentdir, $moodleroot, $requiredrequested, $depth + 1);
            }
        }
    }

    /**
     * Handles plugin-install's "cannotdowngrade" failure: the filesystem
     * and database disagree about what's installed, usually because a
     * dependency needs upgrading first. Parses the blocking component's
     * name out of moosh's own exception output, installs it, then retries
     * the original install exactly once more.
     *
     * @param callable $runInstall () => [string $text, int $exitcode], reruns the original plugin-install call
     * @throws \RuntimeException if the blocking component can't be parsed/resolved, or the retry still fails
     */
    protected function resolveCannotDowngrade($component, $componentdir, $moodleroot, $text, $depth, callable $runInstall)
    {
        if (!preg_match('/Default exception handler: Cannot downgrade [\'"]?([a-z0-9_]+)[\'"]?/', $text, $m)) {
            throw new \RuntimeException(
                "plugin-install for $component reported cannotdowngrade but the blocking component name could not be parsed:\n$text"
            );
        }

        $blockingcomponent = $m[1];
        $blockingcomponentdir = dirname($componentdir) . '/' . $blockingcomponent;

        if (is_dir($blockingcomponentdir)) {
            $blockingpath = $this->getComponentPath($blockingcomponent, $blockingcomponentdir, $moodleroot);
            $blockingcurrent = $this->getInstalledVersion($blockingcomponent, $blockingcomponentdir, $moodleroot, $blockingpath);
            $blockingrequested = $this->getRequestedVersion($blockingcomponent, $blockingcomponentdir, $moodleroot);

            echo "WARN: $component install blocked by $blockingcomponent (cannot downgrade) - resolving it first\n";

            if ($blockingcurrent !== $blockingrequested) {
                if ($blockingrequested === self::SENTINEL_UNINSTALL) {
                    throw new \RuntimeException("$component requires $blockingcomponent but $blockingcomponent is marked for uninstall");
                }
                $this->installRequestedVersion($blockingcomponent, $blockingcomponentdir, $moodleroot, $blockingrequested, $depth + 1);
            }
        } else {
            echo "WARN: $component install blocked by $blockingcomponent, but no such directory was found to resolve it from - retrying anyway\n";
        }

        list($retrytext) = $runInstall();
        if (strpos($retrytext, 'cannotdowngrade') !== false) {
            throw new \RuntimeException("cannot downgrade plugin $component even after resolving $blockingcomponent:\n$retrytext");
        }
    }

    /**
     * Last-resort fallback used by both "detectedmisplacedplugin" and a
     * post-install version mismatch: wipe whatever's on disk for this
     * component and re-install via install_plugins.php (a script that lives
     * alongside the declarative plugin list, not part of moosh itself).
     *
     * @throws \RuntimeException if install_plugins.php isn't found, reports
     *   a mismatch, or exits non-zero
     */
    protected function reinstallViaInstallPluginsPhp($component, $moodleroot, $componentpath, $requestedversion)
    {
        $fulltarget = rtrim($moodleroot, '/') . '/' . $componentpath;
        if (is_file($fulltarget . '/version.php')) {
            exec('rm -rf ' . escapeshellarg($fulltarget));
        }

        $script = $this->configPluginDirectory . '/install_plugins.php';
        if (!is_file($script)) {
            throw new \RuntimeException("install_plugins.php fallback needed for $component but not found at $script");
        }

        $cwd = getcwd();
        chdir($moodleroot);
        exec(
            'php ' . escapeshellarg($script) . ' plugin-install ' . escapeshellarg($component) . ' ' . escapeshellarg($requestedversion) . ' 2>&1',
            $output,
            $exitcode
        );
        chdir($cwd);
        $text = implode("\n", $output);

        if (strpos($text, 'Component mismatch for plugin') !== false) {
            throw new \RuntimeException("install_plugins.php reported a misplaced plugin for $component - cannot continue:\n$text");
        }
        if ($exitcode !== 0) {
            throw new \RuntimeException("install_plugins.php plugin-install $component $requestedversion failed (exit $exitcode):\n$text");
        }
    }

    /**
     * Refreshes ~/.moosh/plugins.json unconditionally (used when
     * plugin-install itself reports it as missing/stale mid-run).
     */
    protected function refreshPluginsJsonCache()
    {
        $filepath = home_dir() . '/.moosh/plugins.json';
        @unlink($filepath);
        $contents = file_get_contents(
            PluginList::$APIURL,
            false,
            PluginDownload::createProxyContext($this->expandedOptions, PluginList::$APIURL)
        );
        if ($contents === false) {
            throw new \RuntimeException('Failed to download plugins list from ' . PluginList::$APIURL);
        }
        @mkdir(dirname($filepath), 0755, true);
        file_put_contents($filepath, $contents);
    }

    // -------------------------------------------------------------------
    // malware scan (scan_plugin_for_malware() port, reusing plugin-clamscan)
    // -------------------------------------------------------------------

    /**
     * @return int one of PluginClamscan::EXIT_CLEAN / EXIT_MALWARE_FOUND / EXIT_ERROR
     * @throws \RuntimeException if the target path doesn't exist at all
     *   (clamscan not being installed is a soft skip, not an error, matching
     *   scan_plugin_for_malware()'s own behaviour)
     */
    protected function scanForMalware($component, $moodleroot, $componentpath)
    {
        $clamscan = new PluginClamscan();
        $binary = $clamscan->findClamscanBinary();
        if ($binary === null) {
            echo "WARN: clamscan not found on system, skipping malware scan for $component\n";
            return PluginClamscan::EXIT_CLEAN;
        }

        $fulltarget = rtrim($moodleroot, '/') . '/' . $componentpath;
        if (!file_exists($fulltarget)) {
            throw new \RuntimeException("cannot scan $component: target path does not exist: $fulltarget");
        }

        $reportdir = $this->configPluginDirectory . '/.clamav/report';
        $rulesdir = $this->configPluginDirectory . '/.clamav/rules';
        $exceptionsdir = $this->configPluginDirectory . '/.clamav/exceptions';
        @mkdir($reportdir, 0755, true);
        @mkdir($rulesdir, 0755, true);
        @mkdir($exceptionsdir, 0755, true);

        $databases = array();
        if ($this->dirHasFilesMatching('/var/lib/clamav', array('cvd', 'cld', 'cud'))) {
            $databases[] = '/var/lib/clamav';
        }
        if ($this->dirHasFilesMatching($rulesdir, array('yar', 'yara'))) {
            $databases[] = $rulesdir;
        }
        if ($this->dirHasFilesMatching($exceptionsdir, array('ign2', 'fp', 'yar', 'yara', 'ndb', 'hdb'))) {
            $databases[] = $exceptionsdir;
        }

        echo "Starting malware scan for $component at $fulltarget\n";
        $options = array('database' => $databases, 'infected' => true, 'log' => $reportdir . '/clamav.log');
        return $clamscan->runClamscan($binary, $fulltarget, $options);
    }

    /**
     * Recursive equivalent of `find "$dir" -type f \( -name '*.ext1' -o ... \)`.
     *
     * @param string   $dir
     * @param string[] $extensions without the leading dot, lowercase
     * @return bool
     */
    protected function dirHasFilesMatching($dir, array $extensions)
    {
        if (!is_dir($dir)) {
            return false;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isFile() && in_array(strtolower($fileinfo->getExtension()), $extensions, true)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------
    // package_* bin/ script execution
    // -------------------------------------------------------------------

    /**
     * Run a package_ plugin's bin/ script with cwd = the Moodle root,
     * matching moodle_plugins_lib.rc's calling convention exactly
     * (pushd/popd around a direct, un-prefixed invocation - so the script
     * must be executable).
     *
     * @param string $script absolute path to the script
     * @param array  $args positional arguments to pass
     * @param string $moodleroot
     * @return array [string[] $outputLines, int $exitcode]
     * @throws \RuntimeException if the script doesn't exist or isn't executable
     */
    protected function runScript($script, array $args, $moodleroot)
    {
        if (!is_file($script)) {
            throw new \RuntimeException("could not find " . basename(dirname($script)) . '/bin/' . basename($script));
        }
        if (!is_executable($script)) {
            throw new \RuntimeException(
                basename(dirname($script)) . '/bin/' . basename($script) . ' is not executable ' .
                '(zip archives often lose the exec bit - chmod +x it)'
            );
        }

        $cmd = escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $cwd = getcwd();
        chdir($moodleroot);
        exec($cmd . ' 2>&1', $output, $exitcode);
        chdir($cwd);

        return array($output, $exitcode);
    }

    /**
     * Resolve the moosh executable to use for recursive subprocess calls
     * (plugin-install, plugin-uninstall) - mirrors moodle_plugins_lib.rc's
     * own `__moosh=$(which moosh)`, with an environment variable override
     * for tests/unusual installs.
     *
     * @return string
     * @throws \RuntimeException if no moosh executable can be found
     */
    protected function resolveMooshExecutable()
    {
        $override = getenv('MOOSH_BIN');
        if ($override !== false && trim($override) !== '') {
            return trim($override);
        }

        $found = trim((string) shell_exec('command -v moosh 2>/dev/null'));
        if ($found === '') {
            throw new \RuntimeException(
                "could not find a 'moosh' executable in PATH (needed to recursively invoke plugin-install/plugin-uninstall). " .
                "Set MOOSH_BIN to override."
            );
        }
        return $found;
    }
}
