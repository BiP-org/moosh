#!/bin/bash
## get installed version from local/kaltura/version.php
__config_plugin_directory="$( cd -- "$(dirname "$0")/../.." >/dev/null 2>&1 ; pwd -P )"
. "${__config_plugin_directory}"/moodle_plugins_lib.rc
get_installed_version local_kaltura
