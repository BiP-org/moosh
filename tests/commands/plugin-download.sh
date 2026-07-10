#!/bin/bash
source functions.sh

install_db
install_data
cd $MOODLEDIR

# Ensure the plugin list is available for the download command.
$MOOSHCMD plugin-list > /dev/null

if $MOOSHCMD -v plugin-download -u assignfeedback_mahara | grep "download.php" ; then
  exit 0
else
  exit 1
fi
