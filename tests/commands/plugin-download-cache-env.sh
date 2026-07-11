#!/bin/bash
source functions.sh

install_db
install_data
cd $MOODLEDIR

CUSTOMCACHE=$(mktemp -d)
export MOOSH_CACHE_DIR="$CUSTOMCACHE"

rm -f assignfeedback_mahara.zip

$MOOSHCMD plugin-download -v 2.8 assignfeedback_mahara >/dev/null 2>&1

CACHEFILE=$(find "$CUSTOMCACHE" -maxdepth 1 -name 'assignfeedback_mahara-*.zip' | head -n1)

unset MOOSH_CACHE_DIR
rm -f assignfeedback_mahara.zip
rm -rf "$CUSTOMCACHE"

if [ -n "$CACHEFILE" ]; then
  exit 0
else
  echo "MOOSH_CACHE_DIR was not honoured"
  exit 1
fi
