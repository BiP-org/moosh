<?php
// Minimal bootstrap for the plugin-install / plugin-cache unit tests.
// Deliberately does NOT require Moodle core or composer's vendor/autoload -
// these tests exercise standalone, Moodle-independent logic only.

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../Moosh/PluginCache.php';
require_once __DIR__ . '/../../Moosh/MooshCommand.php';
require_once __DIR__ . '/../../Moosh/Command/Generic/Plugin/PluginInstall.php';
