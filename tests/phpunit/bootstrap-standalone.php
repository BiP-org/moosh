<?php
// Minimal bootstrap for the plugin-install / plugin-cache / plugin-clamscan
// unit tests. Deliberately does NOT require Moodle core or composer's
// vendor/autoload - these tests exercise standalone, Moodle-independent
// logic only.

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../Moosh/PluginCache.php';
require_once __DIR__ . '/../../Moosh/PluginChecksum.php';
require_once __DIR__ . '/../../Moosh/PluginZip.php';
require_once __DIR__ . '/../../Moosh/MooshCommand.php';
require_once __DIR__ . '/../../Moosh/Command/Generic/Plugin/PluginInstall.php';
require_once __DIR__ . '/../../Moosh/Command/Generic/Plugin/PluginDownload.php';
require_once __DIR__ . '/../../Moosh/Command/Generic/Plugin/PluginClamscan.php';
