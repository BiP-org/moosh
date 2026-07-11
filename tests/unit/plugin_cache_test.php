#!/usr/bin/env php
<?php
/**
 * Standalone tests for Moosh\Command\Generic\Plugin\PluginCache.
 *
 * Unlike the rest of the suite in tests/commands, these do not need a
 * Moodle install or database - PluginCache is pure filesystem/env logic,
 * so it's exercised directly here.
 *
 * Run with: php tests/unit/plugin_cache_test.php
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/functions.php'; // defines home_dir()
require_once $root . '/Moosh/Command/Generic/Plugin/PluginCache.php';

use Moosh\Command\Generic\Plugin\PluginCache;

$failures = 0;
$passes = 0;

function check($description, $condition)
{
    global $failures, $passes;
    if ($condition) {
        echo "PASS: $description\n";
        $passes++;
    } else {
        echo "FAIL: $description\n";
        $failures++;
    }
}

function make_valid_zip($path, $content = 'hello')
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('hello.txt', $content);
    $zip->close();
}

// --- getCacheDir() falls back to ~/.moosh/moodleplugins --------------------
putenv('MOOSH_CACHE_DIR'); // ensure unset
$fakehome = sys_get_temp_dir() . '/moosh-fakehome-' . uniqid();
mkdir($fakehome, 0755, true);
putenv("HOME=$fakehome");
$default = PluginCache::getCacheDir();
check(
    'getCacheDir() falls back to ~/.moosh/moodleplugins when MOOSH_CACHE_DIR is unset',
    $default === $fakehome . '/.moosh/moodleplugins'
);
check('getCacheDir() creates the fallback directory', is_dir($default));
exec('rm -rf ' . escapeshellarg($fakehome));

// --- getCacheDir() honours MOOSH_CACHE_DIR ---------------------------------
$customdir = sys_get_temp_dir() . '/moosh-cache-test-' . uniqid();
putenv("MOOSH_CACHE_DIR=$customdir");
$resolved = PluginCache::getCacheDir();
check('getCacheDir() honours MOOSH_CACHE_DIR', $resolved === rtrim($customdir, '/'));
check('getCacheDir() creates the directory', is_dir($customdir));

// --- getCachePath() is namespaced by component + version -------------------
$path1 = PluginCache::getCachePath('block_foo', '2020010100');
$path2 = PluginCache::getCachePath('block_foo', '2020010200');
check('getCachePath() differs per version', $path1 !== $path2);
check('getCachePath() lives inside the cache dir', strpos($path1, $customdir) === 0);

// --- isValidZip() rejects missing files -------------------------------------
check(
    'isValidZip() rejects a non-existent file',
    PluginCache::isValidZip($customdir . '/nope.zip') === false
);

// --- isValidZip() rejects 0-byte files ---------------------------------------
$emptyfile = $customdir . '/empty.zip';
touch($emptyfile);
check('isValidZip() rejects a 0-byte file', PluginCache::isValidZip($emptyfile) === false);

// --- isValidZip() rejects garbage that merely has a .zip extension ---------
$garbage = $customdir . '/garbage.zip';
file_put_contents($garbage, 'this is not a zip file');
check('isValidZip() rejects non-zip content', PluginCache::isValidZip($garbage) === false);

// --- isValidZip() accepts a real zip -----------------------------------------
$goodzip = $customdir . '/good.zip';
make_valid_zip($goodzip);
check('isValidZip() accepts a well-formed zip', PluginCache::isValidZip($goodzip) === true);

// --- store() refuses to cache invalid downloads ------------------------------
$stored = PluginCache::store('block_foo', '2020010100', $garbage);
check('store() refuses a corrupt download', $stored === false);
check('store() does not create a cache entry for a corrupt download', !file_exists($path1));

$stored = PluginCache::store('block_foo', '2020010100', $emptyfile);
check('store() refuses a 0-byte download', $stored === false);

// --- store() + fetch() round-trip on a valid download ------------------------
$stored = PluginCache::store('block_foo', '2020010100', $goodzip);
check('store() accepts a valid download', $stored === true);
check('store() writes the cache file', file_exists($path1));

$destination = $customdir . '/fetched.zip';
$fetched = PluginCache::fetch('block_foo', '2020010100', $destination);
check('fetch() reports a cache hit', $fetched === true);
check(
    'fetch() copies a valid, non-empty file to the destination',
    is_file($destination) && filesize($destination) > 0
);

// --- fetch() is a clean miss when nothing is cached --------------------------
$missdestination = $customdir . '/miss.zip';
$missed = PluginCache::fetch('block_bar', '2020010100', $missdestination);
check('fetch() reports a miss for an uncached plugin', $missed === false);
check('fetch() does not create a destination file on a miss', !file_exists($missdestination));

// --- fetch() treats a corrupted cache entry as a miss, not a hit -------------
file_put_contents($path2, ''); // simulate a leftover 0-byte file from an interrupted run
$missed = PluginCache::fetch('block_foo', '2020010200', $customdir . '/should-not-exist.zip');
check('fetch() rejects a 0-byte cache entry instead of serving it', $missed === false);
check(
    'fetch() does not create a destination file for a corrupt cache entry',
    !file_exists($customdir . '/should-not-exist.zip')
);

// --- cleanup ------------------------------------------------------------------
exec('rm -rf ' . escapeshellarg($customdir));
putenv('MOOSH_CACHE_DIR');
putenv('HOME');

echo "\n$passes passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
