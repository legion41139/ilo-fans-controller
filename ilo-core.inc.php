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
 * Default multi-step fan curve (avg CPU °C → fan %).
 * Each step applies while avg < max_temp; the last step (null) is the catch-all.
 */
function get_default_fan_curve() {
	return [
		[ 'max_temp' => 50, 'speed' => 15 ],   // < 50°C (incl. 40–50)
		[ 'max_temp' => 60, 'speed' => 25 ],   // 50–60
		[ 'max_temp' => 70, 'speed' => 50 ],   // 60–70
		[ 'max_temp' => 80, 'speed' => 50 ],   // 70–80 (unspecified → hold 50%)
		[ 'max_temp' => 90, 'speed' => 75 ],   // 80–90
		[ 'max_temp' => null, 'speed' => 100 ], // ≥ 90
	];
}

/**
 * Resolve $AUTO_FAN_CURVE (or legacy two-step vars) into a sorted step list.
 */
function get_fan_curve() {
	global $AUTO_FAN_CURVE, $AUTO_TEMP_THRESHOLD, $AUTO_FAN_SPEED_COOL, $AUTO_FAN_SPEED_WARM, $MINIMUM_FAN_SPEED;

	$curve = null;

	if (isset($AUTO_FAN_CURVE) && is_array($AUTO_FAN_CURVE) && count($AUTO_FAN_CURVE) > 0)
		$curve = $AUTO_FAN_CURVE;
	else if (isset($AUTO_TEMP_THRESHOLD) || isset($AUTO_FAN_SPEED_COOL) || isset($AUTO_FAN_SPEED_WARM)) {
		// Legacy two-step config
		$threshold = isset($AUTO_TEMP_THRESHOLD) ? (int) $AUTO_TEMP_THRESHOLD : 50;
		$cool = isset($AUTO_FAN_SPEED_COOL) ? (int) $AUTO_FAN_SPEED_COOL : 15;
		$warm = isset($AUTO_FAN_SPEED_WARM) ? (int) $AUTO_FAN_SPEED_WARM : 25;
		$curve = [
			[ 'max_temp' => $threshold, 'speed' => $cool ],
			[ 'max_temp' => null, 'speed' => $warm ],
		];
	} else
		$curve = get_default_fan_curve();

	$normalized = [];
	foreach ($curve as $step) {
		$max = array_key_exists('max_temp', $step) ? $step['max_temp'] : ($step[0] ?? null);
		$speed = array_key_exists('speed', $step) ? $step['speed'] : ($step[1] ?? null);
		if ($speed === null)
			continue;
		$normalized[] = [
			'max_temp' => $max === null || $max === '' ? null : (int) $max,
			'speed' => max((int) $speed, (int) $MINIMUM_FAN_SPEED),
		];
	}

	usort($normalized, function ($a, $b) {
		if ($a['max_temp'] === null) return 1;
		if ($b['max_temp'] === null) return -1;
		return $a['max_temp'] <=> $b['max_temp'];
	});

	return count($normalized) > 0 ? $normalized : get_default_fan_curve();
}

/**
 * Pick fan % from the curve for a given average CPU temperature.
 */
function fan_speed_for_temp($average_temp) {
	foreach (get_fan_curve() as $step) {
		if ($step['max_temp'] === null || $average_temp < $step['max_temp'])
			return (int) $step['speed'];
	}
	return 100;
}

/**
 * Human-readable curve bands for the UI / API.
 */
function describe_fan_curve($curve = null) {
	$curve = $curve ?? get_fan_curve();
	$bands = [];
	$prev = null;

	foreach ($curve as $step) {
		$speed = (int) $step['speed'];
		if ($step['max_temp'] === null) {
			$bands[] = [
				'label' => $prev === null ? 'any' : "≥ {$prev}°C",
				'min_temp' => $prev,
				'max_temp' => null,
				'speed' => $speed,
			];
		} else {
			$max = (int) $step['max_temp'];
			$label = $prev === null ? "< {$max}°C" : "{$prev}–{$max}°C";
			$bands[] = [
				'label' => $label,
				'min_temp' => $prev,
				'max_temp' => $max,
				'speed' => $speed,
			];
			$prev = $max;
		}
	}

	return $bands;
}

/**
 * Apply temperature-based multi-step fan curve from average CPU temp.
 */
function apply_temperature_control() {
	$thermal = get_thermal();
	$cpu_temps = get_cpu_temperatures($thermal['temperatures']);
	$curve = get_fan_curve();

	if (count($cpu_temps) === 0) {
		return [
			'ok' => false,
			'error' => 'No CPU temperature sensors found',
			'fans' => $thermal['fans'],
			'cpu_temps' => [],
			'average_temp' => null,
			'target_speed' => null,
			'curve' => describe_fan_curve($curve),
		];
	}

	$average_temp = array_sum($cpu_temps) / count($cpu_temps);
	$target_speed = fan_speed_for_temp($average_temp);
	$fans = set_fan_speeds($target_speed);

	return [
		'ok' => true,
		'cpu_temps' => $cpu_temps,
		'average_temp' => round($average_temp, 1),
		'target_speed' => $target_speed,
		'curve' => describe_fan_curve($curve),
		'fans' => $fans,
	];
}

function get_auto_status() {
	global $AUTO_POLL_INTERVAL;

	$thermal = get_thermal();
	$cpu_temps = get_cpu_temperatures($thermal['temperatures']);
	$average_temp = count($cpu_temps) > 0
		? round(array_sum($cpu_temps) / count($cpu_temps), 1)
		: null;

	$curve = get_fan_curve();
	$poll_interval = isset($AUTO_POLL_INTERVAL) ? (int) $AUTO_POLL_INTERVAL : 30;

	$target_speed = null;
	if ($average_temp !== null)
		$target_speed = fan_speed_for_temp($average_temp);

	return [
		'cpu_temps' => $cpu_temps,
		'average_temp' => $average_temp,
		'target_speed' => $target_speed,
		'curve' => describe_fan_curve($curve),
		'poll_interval' => $poll_interval,
		'fans' => $thermal['fans'],
	];
}
?>
