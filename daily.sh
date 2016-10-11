#!/bin/bash
SCRIPT=$(readlink  "$0")
SCRIPTPATH=$(dirname "$SCRIPT")
cd $SCRIPTPATH
DATE=`date +%Y%m%d%H%M%S`
exec &> var/logs/daily.${DATE}.log
echo "Starting Dashboard Metrics analysis... "
echo ${DATE}

php php/daily.php
# install --mode=644 data/*.json /var/www/metrics

echo "Finished Dashboard Metrics analysis."

DATE=`date +%Y%m%d%H%M%S`
echo ${DATE}
