#!/bin/bash
if [ ! -f ./lib/editor/tinymce/version.php ]; then
    #echo "DEBUG: tinymce editor is not supported, removing kaltura support" >&2
    rm -vrf ./lib/editor/tinymce/plugins/kalturamedia/ 2>/dev/null
    set +e
    find ./lib/editor/tinymce/plugins -type d -empty -exec rmdir -v {} \; 2>/dev/null
    find ./lib/editor/tinymce -type d -empty -exec rmdir -v {} \; 2>/dev/null
    find ./lib/editor -name tinymce -type d -empty -exec rmdir -v {} \; 2>/dev/null
    set -e
fi
