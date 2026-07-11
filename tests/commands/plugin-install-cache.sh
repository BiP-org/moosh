#!/bin/bash
source functions.sh

install_db
install_data
cd $MOODLEDIR

CACHEDIR="$HOME/.moosh/moodleplugins"
rm -rf "$CACHEDIR"
rm -rf mod/assignment/feedback/mahara 2>/dev/null

FIRST_RUN=$($MOOSHCMD plugin-install assignfeedback_mahara 2.8 2>&1)
echo "$FIRST_RUN"

if echo "$FIRST_RUN" | grep -q "Using cached copy"; then
  echo "First install unexpectedly used the cache"
  exit 1
fi

if ! echo "$FIRST_RUN" | grep -q "Done"; then
  echo "First install did not complete"
  exit 1
fi

CACHEFILE=$(find "$CACHEDIR" -maxdepth 1 -name 'mahara-*.zip' | head -n1)
if [ -z "$CACHEFILE" ] || [ ! -s "$CACHEFILE" ]; then
  echo "Expected a non-empty cache file after the first install"
  exit 1
fi

SECOND_RUN=$($MOOSHCMD plugin-install -d assignfeedback_mahara 2.8 2>&1)
echo "$SECOND_RUN"

if echo "$SECOND_RUN" | grep -q "Using cached copy" && echo "$SECOND_RUN" | grep -q "Done"; then
  exit 0
else
  echo "Second install did not reuse the cache"
  exit 1
fi
