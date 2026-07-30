<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Moosh\Command\Generic\Plugin\PluginListApply;
use Moosh\Command\Generic\Plugin\PluginClamscan;

final class PluginListApplyTest extends TestCase
{
    private function makeCommand(): PluginListApply
    {
        return (new \ReflectionClass(PluginListApply::class))->newInstanceWithoutConstructor();
    }

    private function callProtected(PluginListApply $command, string $methodName, array $args = [])
    {
        $method = new \ReflectionMethod($command, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($command, $args);
    }

    private function setProtected(PluginListApply $command, string $propertyName, $value): void
    {
        $property = new \ReflectionProperty($command, $propertyName);
        $property->setAccessible(true);
        $property->setValue($command, $value);
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/moosh-plugin-list-apply-test-' . uniqid('', true);
        mkdir($dir, 0777, true);
        return $dir;
    }

    // --- mapComponentPath() ---------------------------------------------

    /** @dataProvider mapComponentPathProvider */
    public function testMapComponentPath(string $component, string $expected): void
    {
        $command = $this->makeCommand();
        $this->assertSame($expected, $this->callProtected($command, 'mapComponentPath', [$component]));
    }

    public function mapComponentPathProvider(): array
    {
        return [
            'auth_ (no tr)' => ['auth_ldap', 'auth/ldap'],
            'assign* (bare prefix + tr)' => ['assignsubmission_file', 'mod/assign/submission/file'],
            'assign* feedback subplugin' => ['assignfeedback_editpdf', 'mod/assign/feedback/editpdf'],
            'atto_' => ['atto_bold', 'lib/editor/atto/plugins/bold'],
            'availability_' => ['availability_group_condition', 'availability/condition/group/condition'],
            'block_ (no tr)' => ['block_html', 'blocks/html'],
            'booktool_' => ['booktool_print', 'mod/book/tool/print'],
            'editor_' => ['editor_tiny', 'lib/editor/tiny'],
            'enrol_' => ['enrol_manual', 'enrol/manual'],
            'filter_' => ['filter_multilang', 'filter/multilang'],
            'format_' => ['format_topics', 'course/format/topics'],
            'gradereport_' => ['gradereport_grader', 'grade/report/grader'],
            'local_resort_courses literal' => ['local_resort_courses', 'local/resort_courses'],
            'local_ generic (no tr)' => ['local_contact', 'local/contact'],
            'message_jabber literal' => ['message_jabber', 'message/output/jabber'],
            'mod_' => ['mod_board', 'mod/board'],
            'plagiarism_' => ['plagiarism_turnitin', 'plagiarism/turnitin'],
            'portfolio_' => ['portfolio_googledocs', 'portfolio/googledocs'],
            'qbehaviour_ (no tr)' => ['qbehaviour_deferredfeedback', 'question/behaviour/deferredfeedback'],
            'qformat_' => ['qformat_xml', 'question/format/xml'],
            'qtype_' => ['qtype_multichoice', 'question/type/multichoice'],
            'quiz_' => ['quiz_statistics', 'mod/quiz/report/statistics'],
            'report_' => ['report_log', 'report/log'],
            'repository_' => ['repository_dropbox', 'repository/dropbox'],
            'theme_ (no tr)' => ['theme_boost', 'theme/boost'],
            'tiny_ (no tr)' => ['tiny_html', 'lib/editor/tiny/plugins/html'],
            'tinymce_' => ['tinymce_spellchecker', 'lib/editor/tinymce/plugins/spellchecker'],
            'tool_' => ['tool_heartbeat', 'admin/tool/heartbeat'],
            'webservice_' => ['webservice_rest', 'webservice/rest'],
        ];
    }

    public function testMapComponentPathThrowsForUnknownComponent(): void
    {
        $command = $this->makeCommand();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown component/');
        $this->callProtected($command, 'mapComponentPath', ['totally_unmapped_thing']);
    }

    public function testMapComponentPathLocalResortCoursesTakesPriorityOverGenericLocalPrefix(): void
    {
        // The one genuine ordering dependency in the original case statement.
        $command = $this->makeCommand();
        $this->assertSame(
            'local/resort_courses',
            $this->callProtected($command, 'mapComponentPath', ['local_resort_courses'])
        );
    }

    // --- readVersionFile() ------------------------------------------------

    public function testReadVersionFileReturnsNullWhenMissing(): void
    {
        $command = $this->makeCommand();
        $this->assertNull($this->callProtected($command, 'readVersionFile', ['/does/not/exist/version']));
    }

    public function testReadVersionFileTrimsTrailingNewlineAndCarriageReturn(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version', "2024010100\r\n");
            $command = $this->makeCommand();
            $this->assertSame('2024010100', $this->callProtected($command, 'readVersionFile', [$dir . '/version']));
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- evalVersionPhp() ---------------------------------------------------

    public function testEvalVersionPhpReadsPluginVariable(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version.php', "<?php\n\$plugin->version = 2024010100;\n");
            $command = $this->makeCommand();
            $this->assertSame(2024010100, $this->callProtected($command, 'evalVersionPhp', [$dir . '/version.php']));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testEvalVersionPhpReadsModuleVariable(): void
    {
        // Some (older-style) plugin types use $module instead of $plugin.
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version.php', "<?php\n\$module->version = 2023010100;\n");
            $command = $this->makeCommand();
            $this->assertSame(2023010100, $this->callProtected($command, 'evalVersionPhp', [$dir . '/version.php']));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testEvalVersionPhpReturnsNullWhenNoVersionIsSet(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version.php', "<?php\n// no version set here\n");
            $command = $this->makeCommand();
            $this->assertNull($this->callProtected($command, 'evalVersionPhp', [$dir . '/version.php']));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testEvalVersionPhpSurvivesAReferenceToAnUndefinedFunction(): void
    {
        // version.php files occasionally reference Moodle-only helpers we
        // don't provide - the version that WAS set before the failure must
        // still come back rather than the whole thing blowing up.
        $dir = $this->makeTempDir();
        try {
            file_put_contents(
                $dir . '/version.php',
                "<?php\n\$plugin->version = 2024010100;\nsome_undefined_moodle_only_function();\n"
            );
            $command = $this->makeCommand();
            $this->assertSame(2024010100, $this->callProtected($command, 'evalVersionPhp', [$dir . '/version.php']));
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- getInstalledVersion() / getRequestedVersion() (standard components) ---

    public function testGetInstalledVersionReturnsMinusOneWhenVersionPhpMissing(): void
    {
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'getInstalledVersion', ['mod_board', $dir, $dir, 'mod/board']);
            $this->assertSame('-1', $result);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testGetRequestedVersionReturnsMinusOneWhenVersionFileMissing(): void
    {
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'getRequestedVersion', ['mod_board', $dir, $dir]);
            $this->assertSame('-1', $result);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testGetRequestedVersionThrowsOnNonIntegerContent(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version', "not-a-number\n");
            $command = $this->makeCommand();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/not a valid integer/');
            $this->callProtected($command, 'getRequestedVersion', ['mod_board', $dir, $dir]);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testGetRequestedVersionReadsIntegerFromFile(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/version', "2024010100\n");
            $command = $this->makeCommand();
            $result = $this->callProtected($command, 'getRequestedVersion', ['mod_board', $dir, $dir]);
            $this->assertSame('2024010100', $result);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- package_* dispatch, against the REAL package_kaltura bin/ scripts -----
    // (only the network-free ones: get_component_path.sh and
    // get_requested_version.sh - both are pure/deterministic.)

    private function kalturaDir(): string
    {
        return dirname(__DIR__, 2) . '/tests/fixtures/package_kaltura';
    }

    public function testGetComponentPathDelegatesToRealPackageScript(): void
    {
        $kaltura = $this->kalturaDir();
        if (!is_file($kaltura . '/bin/get_component_path.sh')) {
            $this->markTestSkipped('package_kaltura fixture not present');
        }
        chmod($kaltura . '/bin/get_component_path.sh', 0755);

        $command = $this->makeCommand();
        $result = $this->callProtected($command, 'getComponentPath', ['package_kaltura', $kaltura, sys_get_temp_dir()]);
        $this->assertSame('local/kaltura', $result);
    }

    public function testGetRequestedVersionDelegatesToRealPackageScript(): void
    {
        $kaltura = $this->kalturaDir();
        if (!is_file($kaltura . '/bin/get_requested_version.sh')) {
            $this->markTestSkipped('package_kaltura fixture not present');
        }
        chmod($kaltura . '/bin/get_requested_version.sh', 0755);

        $expected = trim((string) file_get_contents($kaltura . '/version'));

        $command = $this->makeCommand();
        $result = $this->callProtected($command, 'getRequestedVersion', ['package_kaltura', $kaltura, sys_get_temp_dir()]);
        $this->assertSame($expected, $result);
    }

    public function testUninstallRequestedVersionScriptMissingProducesClearError(): void
    {
        // package_kaltura genuinely has no uninstall script yet - this is
        // the specific scenario the spec calls out as needing a helpful error.
        $kaltura = $this->kalturaDir();
        if (!is_dir($kaltura)) {
            $this->markTestSkipped('package_kaltura fixture not present');
        }
        $this->assertFileDoesNotExist($kaltura . '/bin/uninstall_requested_version.sh');

        $command = $this->makeCommand();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/uninstall_requested_version\.sh/');
        $this->callProtected($command, 'runPackageUninstallScript', ['package_kaltura', $kaltura, sys_get_temp_dir()]);
    }

    // --- runScript() ---------------------------------------------------------

    public function testRunScriptThrowsWhenScriptMissing(): void
    {
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/could not find/');
            $this->callProtected($command, 'runScript', [$dir . '/bin/does_not_exist.sh', [], $dir]);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testRunScriptThrowsWhenScriptNotExecutable(): void
    {
        $dir = $this->makeTempDir();
        try {
            mkdir($dir . '/bin', 0777, true);
            file_put_contents($dir . '/bin/script.sh', "#!/bin/sh\necho hi\n");
            chmod($dir . '/bin/script.sh', 0644); // deliberately not executable

            $command = $this->makeCommand();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/not executable/');
            $this->callProtected($command, 'runScript', [$dir . '/bin/script.sh', [], $dir]);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testRunScriptPassesArgumentsAndReturnsOutputWithCwdSetToMoodleroot(): void
    {
        $dir = $this->makeTempDir();
        try {
            mkdir($dir . '/bin', 0777, true);
            mkdir($dir . '/moodleroot', 0777, true);
            file_put_contents($dir . '/bin/script.sh', "#!/bin/sh\necho \"arg1=\$1 arg2=\$2 cwd=\$(pwd)\"\n");
            chmod($dir . '/bin/script.sh', 0755);

            $command = $this->makeCommand();
            list($output, $exitcode) = $this->callProtected($command, 'runScript', [
                $dir . '/bin/script.sh', ['componentname', '2024010100'], $dir . '/moodleroot',
            ]);

            $this->assertSame(0, $exitcode);
            $this->assertStringContainsString('arg1=componentname arg2=2024010100', implode("\n", $output));
            $this->assertStringContainsString('cwd=' . realpath($dir . '/moodleroot'), implode("\n", $output));
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- removePluginFiles() (standard component, no subprocess needed) --------

    public function testRemovePluginFilesDeletesInstalledFiles(): void
    {
        $moodleroot = $this->makeTempDir();
        try {
            $target = $moodleroot . '/mod/board';
            mkdir($target, 0777, true);
            file_put_contents($target . '/version.php', "<?php\n\$plugin->version = 1;\n");

            $command = $this->makeCommand();
            $this->callProtected($command, 'removePluginFiles', ['mod_board', $moodleroot . '/config', $moodleroot, 'mod/board']);

            $this->assertDirectoryDoesNotExist($target);
        } finally {
            $this->removeDir($moodleroot);
        }
    }

    public function testRemovePluginFilesLeavesGitManagedDirectoryAlone(): void
    {
        $moodleroot = $this->makeTempDir();
        try {
            $target = $moodleroot . '/mod/board';
            mkdir($target, 0777, true);
            file_put_contents($target . '/version.php', "<?php\n\$plugin->version = 1;\n");
            file_put_contents($target . '/.git', "gitdir: ../../.git/modules/mod/board\n");

            $command = $this->makeCommand();
            $this->callProtected($command, 'removePluginFiles', ['mod_board', $moodleroot . '/config', $moodleroot, 'mod/board']);

            $this->assertDirectoryExists($target);
        } finally {
            $this->removeDir($moodleroot);
        }
    }

    public function testRemovePluginFilesIsANoOpWhenNothingIsInstalled(): void
    {
        $moodleroot = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            // Should not throw even though mod/board doesn't exist at all.
            $this->callProtected($command, 'removePluginFiles', ['mod_board', $moodleroot . '/config', $moodleroot, 'mod/board']);
            $this->assertTrue(true);
        } finally {
            $this->removeDir($moodleroot);
        }
    }

    // --- runAlwaysRunHookIfPresent() -----------------------------------------

    public function testAlwaysRunHookIsSkippedWhenAbsent(): void
    {
        $dir = $this->makeTempDir();
        try {
            $command = $this->makeCommand();
            // Should not throw.
            $this->callProtected($command, 'runAlwaysRunHookIfPresent', ['mod_board', $dir, $dir]);
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAlwaysRunHookRunsWithMoodlerootAsCwd(): void
    {
        $dir = $this->makeTempDir();
        try {
            mkdir($dir . '/bin', 0777, true);
            mkdir($dir . '/moodleroot', 0777, true);
            file_put_contents($dir . '/bin/install_requested_always_run.sh', "#!/bin/sh\ntouch marker\n");
            chmod($dir . '/bin/install_requested_always_run.sh', 0755);

            $command = $this->makeCommand();
            $this->callProtected($command, 'runAlwaysRunHookIfPresent', ['mod_board', $dir, $dir . '/moodleroot']);

            $this->assertFileExists($dir . '/moodleroot/marker');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAlwaysRunHookThrowsOnFailure(): void
    {
        $dir = $this->makeTempDir();
        try {
            mkdir($dir . '/bin', 0777, true);
            file_put_contents($dir . '/bin/install_requested_always_run.sh', "#!/bin/sh\nexit 3\n");
            chmod($dir . '/bin/install_requested_always_run.sh', 0755);

            $command = $this->makeCommand();
            $this->expectException(\RuntimeException::class);
            $this->callProtected($command, 'runAlwaysRunHookIfPresent', ['mod_board', $dir, $dir]);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- dirHasFilesMatching() ------------------------------------------------

    public function testDirHasFilesMatchingReturnsFalseForMissingDirectory(): void
    {
        $command = $this->makeCommand();
        $this->assertFalse($this->callProtected($command, 'dirHasFilesMatching', ['/does/not/exist', ['yar']]));
    }

    public function testDirHasFilesMatchingFindsFilesRecursively(): void
    {
        $dir = $this->makeTempDir();
        try {
            mkdir($dir . '/nested', 0777, true);
            file_put_contents($dir . '/nested/rule.yar', 'rule test {}');

            $command = $this->makeCommand();
            $this->assertTrue($this->callProtected($command, 'dirHasFilesMatching', [$dir, ['yar', 'yara']]));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDirHasFilesMatchingReturnsFalseWhenNoExtensionMatches(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/readme.txt', 'not a rule');

            $command = $this->makeCommand();
            $this->assertFalse($this->callProtected($command, 'dirHasFilesMatching', [$dir, ['yar', 'yara']]));
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- resolveMooshExecutable() ---------------------------------------------

    protected function tearDown(): void
    {
        putenv('MOOSH_BIN');
    }

    public function testResolveMooshExecutableUsesEnvOverride(): void
    {
        putenv('MOOSH_BIN=/custom/path/to/moosh');
        $command = $this->makeCommand();
        $this->assertSame('/custom/path/to/moosh', $this->callProtected($command, 'resolveMooshExecutable'));
    }

    // --- scanForMalware() skip-when-absent path --------------------------------

    public function testScanForMalwareReturnsCleanWhenClamscanNotAvailable(): void
    {
        // Can't reliably force "clamscan not found" without touching real
        // PATH, so this only runs the assertion when it's genuinely absent
        // in this environment - otherwise it's covered indirectly via
        // PluginClamscanTest's own resolveClamscanBinaryFromLookup tests.
        $clamscan = (new \ReflectionClass(PluginClamscan::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($clamscan, 'resolveClamscanBinaryFromLookup');
        $method->setAccessible(true);
        if ($method->invoke($clamscan, shell_exec('command -v clamscan 2>/dev/null')) !== null) {
            $this->markTestSkipped('clamscan is installed in this environment');
        }

        $dir = $this->makeTempDir();
        try {
            $target = $dir . '/mod/board';
            mkdir($target, 0777, true);

            $command = $this->makeCommand();
            $this->setProtected($command, 'configPluginDirectory', $dir);
            $result = $this->callProtected($command, 'scanForMalware', ['mod_board', $dir, 'mod/board']);

            $this->assertSame(PluginClamscan::EXIT_CLEAN, $result);
        } finally {
            $this->removeDir($dir);
        }
    }
}
