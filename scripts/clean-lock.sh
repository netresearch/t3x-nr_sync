#!/bin/bash
# Autor: Tobias.Hein <tobias.hein@netresearch.de>
#
# the script checks for lock files created by nr_sync and removes them
#

# life time in minutes
LIFE_TIME_MIN=5

BIN_FIND=$(which find);
FIND_OPT=" -maxdepth 1 -mmin +"${LIFE_TIME_MIN}" -type f -delete"

SCRIPT=$(readlink -f $0)
CURRENT_DIR=`dirname $SCRIPT`
TARGET=${CURRENT_DIR}/../../../../db/tmp/


${BIN_FIND} ${TARGET} ${FIND_OPT}