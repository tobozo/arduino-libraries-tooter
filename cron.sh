
# */10 * * * * /home/tobozo/src/arduino-libraries-tooter/cron.sh > /dev/null 2>&1

PIDFILE=arduino-library-announcer.pid

if [ -f $PIDFILE ]
then
  PID=$(cat $PIDFILE)
  ps -p $PID > /dev/null 2>&1
  if [ $? -eq 0 ]
  then
    echo "Process already running"
    exit 1
  else
    ## Process not found assume not running
    echo $$ > $PIDFILE
    if [ $? -ne 0 ]
    then
      echo "Could not create PID file"
      exit 1
    fi
  fi
else
  echo $$ > $PIDFILE
  if [ $? -ne 0 ]
  then
    echo "Could not create PID file"
    exit 1
  fi
fi

/usr/bin/php cron.php >> logfile.txt 2>&1

rm $PIDFILE
