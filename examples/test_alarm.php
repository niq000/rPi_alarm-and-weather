<?php

include_once(dirname(__DIR__) . '/include/alarm.php');

$alarm = new Alarm(1);
$alarm->triggerAlarm();

?>
