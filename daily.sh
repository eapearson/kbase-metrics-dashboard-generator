#!/bin/bash

# NOTE: please configure this variable to point to the destination directory
#       for the dashboard metrics json files.
#       By default it points to the location of the metrics export files.
DEST=/var/www/metrics

SCRIPT=$(readlink  "$0")
SCRIPTPATH=$(dirname "$SCRIPT")
cd $SCRIPTPATH
DATE=`date +%Y%m%d%H%M%S`
exec &> var/logs/daily.${DATE}.log
echo "Starting Dashboard Metrics analysis... "
echo ${DATE}

php php/daily.php
install -m 644 ./var/export/*.json ${DEST}

echo "Finished Dashboard Metrics analysis."

DATE=`date +%Y%m%d%H%M%S`
echo ${DATE}
