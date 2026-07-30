#!/bin/bash
__component_files=$(wget -qO- "$(curl -s https://api.github.com/repos/kaltura/moodle_plugin/releases | jq -r '.[0].assets[].browser_download_url'|cut -d / -f1-)"|busybox unzip -l -|awk '{print $4}'|grep -v /$)
for __component_file in ${__component_files}; do
    case ${__component_file} in
        " ")
            true
        ;;
        Name)
            true
        ;;
        .)
            true
        ;;
        ----)
            true
        ;;
        lib/editor/tinymce/*)
            if [ ! -f ./lib/editor/tinymce/version.php ]; then
# shellcheck disable=SC2154
                if [ "${enable_debug}" = "true" ]; then
                    echo DEBUG: tinymce editor is not supported, not adding "${__component_file}" >&2
                fi
            else
# shellcheck disable=SC2154,SC2028,SC2086,SC2046,SC2116
                __component_path=$(echo ${__component_path}\\n$(dirname ${__component_file}))
            fi
        ;;
        *)
# shellcheck disable=SC2154,SC2028,SC2086,SC2046,SC2116
            __component_path=$(echo ${__component_path}\\n$(dirname ${__component_file}))
        ;;
    esac
done
__uniq_component_path=$(echo -e "${__component_path}"|sort|uniq|sed '/^ *$/d')
echo -e "${__uniq_component_path}"
