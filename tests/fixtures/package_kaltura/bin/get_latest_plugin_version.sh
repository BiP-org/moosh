#!/bin/sh
__moodle_root_directory=$(pwd)
if [ ! -f "${__moodle_root_directory}"/config.php ]; then
    echo "[ERROR] This script must be run from the root directory of a Moodle installation." >&2
    exit 1
fi
# shellcheck disable=SC2016
MOODLE_BRANCH=$(grep '$branch' "${__moodle_root_directory}"/version.php | cut -d "'" -f 2)
curl -s https://api.github.com/repos/kaltura/moodle_plugin/releases | jq '.[] | select(.target_commitish | test("MOODLE_'"$MOODLE_BRANCH"'_")) | .assets[].browser_download_url'|head -1 | sed s/\.zip\"$//|rev|cut -d / -f1|cut -d _ -f1|rev
