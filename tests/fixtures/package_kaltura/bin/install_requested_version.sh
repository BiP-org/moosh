#!/bin/bash
__release_url=$(curl -s https://api.github.com/repos/kaltura/moodle_plugin/releases | jq -r '.[].assets[].browser_download_url'|grep "${2}")
if [ -z ${__release_url+x} ]; then
    echo ERROR: could not find release "${2}"
    exit 1
else
    echo Downloading file "${__release_url}"
    echo "working directory: $(pwd)"
    # -o for overwrite existing files with new files
    wget -qO- "${__release_url}"|busybox unzip -o -

    # old code:
#    if [ ! -f ./lib/editor/tinymce/version.php ]; then
#        echo INFO: tinymce editor is not supported, removing kaltura support
#        rm -vrf ./lib/editor/tinymce/plugins/kalturamedia/ 2>/dev/null
#        set +e
#        find ./lib/editor/tinymce/plugins -type d -empty -exec rmdir -v {} \; 2>/dev/null
#        find ./lib/editor/tinymce -type d -empty -exec rmdir -v {} \; 2>/dev/null
#        find ./lib/editor -name tinymce -type d -empty -exec rmdir -v {} \; 2>/dev/null
#        set -e
#    fi
fi
