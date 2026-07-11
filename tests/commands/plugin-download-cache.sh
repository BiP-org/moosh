#!/bin/bash
source functions.sh

install_db
install_data
cd $MOODLEDIR

CACHEDIR="$HOME/.moosh/moodleplugins"
rm -rf "$CACHEDIR"
rm -f assignfeedback_mahara.zip

FIRST_RUN=$($MOOSHCMD plugin-download -v 2.8 assignfeedback_mahara 2>&1)
echo "$FIRST_RUN"

if echo "$FIRST_RUN" | grep -q "Using cached copy"; then
  echo "First download unexpectedly used the cache"
  exit 1
fi

rm -f assignfeedback_mahara.zip

SECOND_RUN=$($MOOSHCMD plugin-download -v 2.8 assignfeedback_mahara 2>&1)
echo "$SECOND_RUN"

if echo "$SECOND_RUN" | grep -q "Using cached copy" && [ -s assignfeedback_mahara.zip ]; then
  rm -f assignfeedback_mahara.zip
  exit 0
else
  echo "Second download did not reuse the cache"
  exit 1
fi
