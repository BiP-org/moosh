#!/bin/bash
source functions.sh

install_db
install_data
cd $MOODLEDIR

CACHEDIR="$HOME/.moosh/moodleplugins"
rm -rf "$CACHEDIR"
rm -rf mod/assignment/feedback/mahara 2>/dev/null

# Prime the cache with a good copy first.
$MOOSHCMD plugin-install assignfeedback_mahara 2.8 >/dev/null 2>&1

CACHEFILE=$(find "$CACHEDIR" -maxdepth 1 -name 'mahara-*.zip' | head -n1)
if [ -z "$CACHEFILE" ]; then
  echo "Could not find a primed cache file to corrupt"
  exit 1
fi

# Corrupt it by truncating to 0 bytes, simulating an interrupted download.
: > "$CACHEFILE"

rm -rf mod/assignment/feedback/mahara

RUN_OUTPUT=$($MOOSHCMD plugin-install assignfeedback_mahara 2.8 2>&1)
echo "$RUN_OUTPUT"

if echo "$RUN_OUTPUT" | grep -q "Using cached copy"; then
  echo "moosh incorrectly reused a corrupt (0 byte) cache entry"
  exit 1
fi

if ! echo "$RUN_OUTPUT" | grep -q "Done"; then
  echo "Install did not recover from the corrupt cache entry"
  exit 1
fi

if [ ! -s "$CACHEFILE" ]; then
  echo "Cache entry was not repaired after a successful re-download"
  exit 1
fi

exit 0
