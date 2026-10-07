#!/bin/bash
set -euo pipefail

# Temperature-based fan control loop (runs alongside Apache).
# Disable with AUTO_CONTROL_ENABLED=0 / false / no / off
AUTO_CONTROL_ENABLED="${AUTO_CONTROL_ENABLED:-1}"
AUTO_POLL_INTERVAL="${AUTO_POLL_INTERVAL:-30}"

should_run_auto() {
	case "${AUTO_CONTROL_ENABLED,,}" in
		0|false|no|off) return 1 ;;
		*) return 0 ;;
	esac
}

if should_run_auto; then
	if ! [[ "$AUTO_POLL_INTERVAL" =~ ^[0-9]+$ ]] || [ "$AUTO_POLL_INTERVAL" -lt 5 ]; then
		AUTO_POLL_INTERVAL=30
	fi

	echo "Starting temperature-based auto fan control (every ${AUTO_POLL_INTERVAL}s)"
	(
		# Small delay so Apache/env are ready; then apply immediately and loop
		sleep 2
		while true; do
			if ! php /var/www/html/auto-control.php; then
				echo "auto-control.php failed; retrying in ${AUTO_POLL_INTERVAL}s" >&2
			fi
			sleep "$AUTO_POLL_INTERVAL"
		done
	) &
else
	echo "Temperature-based auto fan control disabled (AUTO_CONTROL_ENABLED=${AUTO_CONTROL_ENABLED})"
fi

# Hand off to the php:apache image entrypoint (starts Apache in the foreground)
exec docker-php-entrypoint apache2-foreground
