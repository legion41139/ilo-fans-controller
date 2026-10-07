<?php

/*
  ILO ACCESS CREDENTIALS
  --------------
  These are used to connect to the iLO
  interface and manage the fan speeds.
*/

$ILO_HOST = 'your-ilo-address';  // Ex. 192.168.1.69
$ILO_USERNAME = 'your-ilo-username';  // Ex. Administrator
$ILO_PASSWORD = 'your-ilo-password';  // Ex. AdministratorPassword1234

/*
  MISCELLANEOUS SETTINGS
  --------------
  These allows you to customize
  the behavior of the tool.
*/

// Minimum fan speed percentage, from 0% (DANGEROUS) to 100%
$MINIMUM_FAN_SPEED = 10;

/*
  TEMPERATURE-BASED AUTO CONTROL
  --------------
  When auto mode is enabled (UI toggle or
  auto-control.php via cron), fans follow:

    avg(CPU1, CPU2, ...) <= threshold  => cool speed
    avg(CPU1, CPU2, ...) >  threshold  => warm speed
*/

// Average CPU temperature (°C) above which the warm fan speed is used
$AUTO_TEMP_THRESHOLD = 50;

// Fan speed (%) when average CPU temp is at or below the threshold
$AUTO_FAN_SPEED_COOL = 15;

// Fan speed (%) when average CPU temp is above the threshold
$AUTO_FAN_SPEED_WARM = 25;

// How often the web UI re-checks temperatures when auto mode is on (seconds)
$AUTO_POLL_INTERVAL = 30;

?>
