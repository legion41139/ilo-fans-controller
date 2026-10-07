<?php
/**
 * Shared iLO fans controller helpers (Redfish + SSH).
 * Used by the web UI and the CLI auto-control script.
 */

require_once __DIR__ . '/config.inc.php';

function get_presets() {
	if (!file_exists(__DIR__ . '/presets.json'))  // Return default presets if the file doesn't exist
		return [
			[
				'name' => 'Silent Mode',
				'speeds' => [ 15 ],
			],
			[
				'name' => 'Normal Mode',
				'speeds' => [ 50 ],
			],
			[
				'name' => 'Turbo Mode',
				'speeds' => [ 100 ],
			]
		];
	else
		return json_decode(file_get_contents(__DIR__ . '/presets.json'), true);
}

/**
 * Fetch Thermal resource from iLO Redfish (fans + temperatures).
 */
function get_thermal() {
	global $ILO_HOST, $ILO_USERNAME, $ILO_PASSWORD;

	$curl_handle = curl_init("https://$ILO_HOST/redfish/v1/chassis/1/Thermal");

	curl_setopt($curl_handle, CURLOPT_USERPWD, "$ILO_USERNAME:$ILO_PASSWORD");
	curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);

	$raw_ilo_data = curl_exec($curl_handle);
	curl_close($curl_handle);

	if (!$raw_ilo_data)
		return [ 'fans' => [], 'temperatures' => [] ];

	$data = json_decode($raw_ilo_data, true);
	$fans = [];
	$temperatures = [];

	foreach ($data['Fans'] ?? [] as $fan) {
		$name = $fan['FanName'] ?? $fan['Name'] ?? null;
		$reading = $fan['CurrentReading'] ?? $fan['Reading'] ?? null;
		if ($name !== null && $reading !== null)
			$fans[$name] = $reading;
	}

	foreach ($data['Temperatures'] ?? [] as $sensor) {
		$name = $sensor['Name'] ?? $sensor['PhysicalContext'] ?? null;
		$reading = $sensor['CurrentReading'] ?? $sensor['ReadingCelsius'] ?? null;
		if ($name === null || $reading === null || $reading < 0)
			continue;

		$temperatures[$name] = [
			'reading' => $reading,
			'context' => $sensor['PhysicalContext'] ?? $sensor['Context'] ?? '',
		];
	}

	return [ 'fans' => $fans, 'temperatures' => $temperatures ];
}

function get_fans() {
	return get_thermal()['fans'];
}

/**
 * Return CPU temperature sensors (keyed by name => °C).
 * Matches PhysicalContext/Context "CPU" or names like "02-CPU 1".
 */
function get_cpu_temperatures($temperatures = null) {
	if ($temperatures === null)
		$temperatures = get_thermal()['temperatures'];

	$cpus = [];
	foreach ($temperatures as $name => $info) {
		$context = strtoupper($info['context'] ?? '');
		$is_cpu = $context === 'CPU'
			|| preg_match('/\bCPU\s*\d+\b/i', $name)
			|| preg_match('/^0?\d+-CPU\s*\d+/i', $name);

		if ($is_cpu)
			$cpus[$name] = $info['reading'];
	}

	// Prefer numbered CPU sensors if we matched too broadly
	$numbered = array_filter($cpus, function ($name) {
		return preg_match('/CPU\s*\d+/i', $name);
	}, ARRAY_FILTER_USE_KEY);

	return count($numbered) > 0 ? $numbered : $cpus;
}

/**
 * Set fan speeds via patched iLO SSH. $speeds is fan-name => percent, or a single int for all fans.
 * Returns the current fan readings after applying.
 */
function set_fan_speeds($speeds) {
	global $ILO_HOST, $ILO_USERNAME, $ILO_PASSWORD, $MINIMUM_FAN_SPEED;

	$FANS = get_fans();

	if (is_int($speeds) || (is_numeric($speeds) && !is_array($speeds)))
		$speeds = array_fill_keys(array_keys($FANS), (int) $speeds);

	$updated = 0;
	$connected = false;
	$ssh_handle = null;

	foreach ($speeds as $fan => $speed) {
		if (!array_key_exists($fan, $FANS))
			throw new InvalidArgumentException("Invalid fan name: $fan");

		$fan_index = array_search($fan, array_keys($FANS));
		$speed = (int) $speed;

		if (($speed >= $MINIMUM_FAN_SPEED && $speed <= 100) && $speed != $FANS[$fan]) {
			if (!$connected) {
				$ssh_handle = ssh2_connect($ILO_HOST, 22);
				ssh2_auth_password($ssh_handle, $ILO_USERNAME, $ILO_PASSWORD);
				$connected = true;
			}

			$stream = ssh2_exec($ssh_handle, "fan p $fan_index max " . ceil($speed / 100 * 255));
			stream_set_blocking($stream, true);
			stream_get_contents($stream);

			$stream = ssh2_exec($ssh_handle, "fan p $fan_index min 255");
			stream_set_blocking($stream, true);
			stream_get_contents($stream);

			$updated++;
		}
	}

	if ($updated > 0)
		do
			$FANS = get_fans();
		while ($FANS !== array_merge($FANS, $speeds));

	return $FANS;
}

/**
 * Apply temperature-based fan curve:
 * avg(CPU temps) > threshold  => warm speed (default 25%)
 * otherwise                   => cool speed (default 15%)
 */
function apply_temperature_control() {
	global $AUTO_TEMP_THRESHOLD, $AUTO_FAN_SPEED_COOL, $AUTO_FAN_SPEED_WARM, $MINIMUM_FAN_SPEED;

	$threshold = isset($AUTO_TEMP_THRESHOLD) ? (int) $AUTO_TEMP_THRESHOLD : 50;
	$cool_speed = isset($AUTO_FAN_SPEED_COOL) ? (int) $AUTO_FAN_SPEED_COOL : 15;
	$warm_speed = isset($AUTO_FAN_SPEED_WARM) ? (int) $AUTO_FAN_SPEED_WARM : 25;

	$cool_speed = max($cool_speed, (int) $MINIMUM_FAN_SPEED);
	$warm_speed = max($warm_speed, (int) $MINIMUM_FAN_SPEED);

	$thermal = get_thermal();
	$cpu_temps = get_cpu_temperatures($thermal['temperatures']);

	if (count($cpu_temps) === 0) {
		return [
			'ok' => false,
			'error' => 'No CPU temperature sensors found',
			'fans' => $thermal['fans'],
			'cpu_temps' => [],
			'average_temp' => null,
			'target_speed' => null,
		];
	}

	$average_temp = array_sum($cpu_temps) / count($cpu_temps);
	$target_speed = $average_temp > $threshold ? $warm_speed : $cool_speed;

	$fans = set_fan_speeds($target_speed);

	return [
		'ok' => true,
		'cpu_temps' => $cpu_temps,
		'average_temp' => round($average_temp, 1),
		'threshold' => $threshold,
		'target_speed' => $target_speed,
		'fans' => $fans,
	];
}

function get_auto_status() {
	global $AUTO_TEMP_THRESHOLD, $AUTO_FAN_SPEED_COOL, $AUTO_FAN_SPEED_WARM, $AUTO_POLL_INTERVAL;

	$thermal = get_thermal();
	$cpu_temps = get_cpu_temperatures($thermal['temperatures']);
	$average_temp = count($cpu_temps) > 0
		? round(array_sum($cpu_temps) / count($cpu_temps), 1)
		: null;

	$threshold = isset($AUTO_TEMP_THRESHOLD) ? (int) $AUTO_TEMP_THRESHOLD : 50;
	$cool_speed = isset($AUTO_FAN_SPEED_COOL) ? (int) $AUTO_FAN_SPEED_COOL : 15;
	$warm_speed = isset($AUTO_FAN_SPEED_WARM) ? (int) $AUTO_FAN_SPEED_WARM : 25;
	$poll_interval = isset($AUTO_POLL_INTERVAL) ? (int) $AUTO_POLL_INTERVAL : 30;

	$target_speed = null;
	if ($average_temp !== null)
		$target_speed = $average_temp > $threshold ? $warm_speed : $cool_speed;

	return [
		'cpu_temps' => $cpu_temps,
		'average_temp' => $average_temp,
		'threshold' => $threshold,
		'cool_speed' => $cool_speed,
		'warm_speed' => $warm_speed,
		'target_speed' => $target_speed,
		'poll_interval' => $poll_interval,
		'fans' => $thermal['fans'],
	];
}
?>
