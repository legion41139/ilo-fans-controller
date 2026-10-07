#!/usr/bin/env php
<?php
/**
 * CLI temperature-based fan control.
 *
 * Run periodically via cron so fans stay adjusted even when the web UI is closed:
 *
 *   * * * * * php /path/to/auto-control.php >/dev/null 2>&1
 *
 * Or every 30 seconds with two staggered minute entries, or a systemd timer.
 */

require __DIR__ . '/ilo-core.inc.php';

try {
	$result = apply_temperature_control();
	echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
	exit($result['ok'] ? 0 : 1);
} catch (Throwable $e) {
	fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
	exit(1);
}
