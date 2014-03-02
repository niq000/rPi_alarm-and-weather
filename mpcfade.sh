#!/bin/bash
#https://gist.github.com/urcadox/5453502
VOL=$1
TO=$2
SLEEP=$3
echo "Fading from $VOL% to $TO% (step: $SLEEP second(s))"
mpc volume $VOL > /dev/null
if [ $VOL -lt $TO ]
then
  while [ $VOL -lt $TO ]
  do
    sleep $SLEEP > /dev/null
    VOL=$[$VOL+1]
    mpc volume $VOL > /dev/null
  done
else
  while [ $VOL -gt $TO ]
  do
    sleep $SLEEP > /dev/null
    VOL=$[$VOL-1]
    mpc volume $VOL > /dev/null
  done
fi
