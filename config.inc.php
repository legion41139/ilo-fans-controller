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
  Fan % is chosen from average CPU temp using
  the curve below (each step: avg < max_temp).
  The last step (max_temp null) is the catch-all.
*/

$AUTO_FAN_CURVE = [
	[ 'max_temp' => 50, 'speed' => 15 ],   // < 50°C
	[ 'max_temp' => 60, 'speed' => 25 ],   // 50–60°C
	[ 'max_temp' => 70, 'speed' => 50 ],   // 60–70°C
	[ 'max_temp' => 80, 'speed' => 50 ],   // 70–80°C (hold)
	[ 'max_temp' => 90, 'speed' => 75 ],   // 80–90°C
	[ 'max_temp' => null, 'speed' => 100 ], // ≥ 90°C
];

// How often auto control re-checks temperatures (Docker loop + UI poll), seconds
$AUTO_POLL_INTERVAL = 30;

// Docker only: run the background auto-control loop (true/false)
$AUTO_CONTROL_ENABLED = true;

?>
