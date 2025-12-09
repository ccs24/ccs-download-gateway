<?php
if (!defined('YOURLS_ABSPATH')) die();

define("MINUTE_IN_SECONDS", 60);
define("HOUR_IN_SECONDS",60*60);
define("DAY_IN_SECONDS",24*60*60);
define("WEEK_IN_SECONDS", 7*24*60*60);
define("MONTH_IN_SECONDS",30*DAY_IN_SECONDS);
define("YEAR_IN_SECONDS",365*DAY_IN_SECONDS);

/**
 * Generate unique file ID
 */
function ccs_generate_file_id($length = 8) {
    $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';

    do {
        $file_id = '';
        for ($i = 0; $i < $length; $i++) {
            $file_id .= $characters[random_int(0, strlen($characters) - 1)];
        }

        // Check if exists in database
        $exists = ccs_file_id_exists($file_id);

    } while ($exists);

    return $file_id;
}

function selected($selected, $current, $echo = true) {
        $result = ($selected == $current) ? ' selected="selected"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }

function current_time() {
	return  time();
}

 
function _n( $single, $plural, $number, $domain = 'default' ) {
	if ($number >1 ){
		return $plural;
	} else {
		return $single;
	}
}


function human_time_diff( $from, $to = 0 ) {

	if ( empty( $to ) ) {
		$to = time();
	}

	$diff = (int) abs( $to - $from );

	if ( $diff < MINUTE_IN_SECONDS ) {
		$secs = $diff;
		if ( $secs <= 1 ) {
			$secs = 1;
		}
		/* translators: Time difference between two dates, in seconds. %s: Number of seconds. */
		$since = sprintf( _n( '%s second', '%s seconds', $secs ), $secs );
	} elseif ( $diff < HOUR_IN_SECONDS && $diff >= MINUTE_IN_SECONDS ) {
		$mins = round( $diff / MINUTE_IN_SECONDS );
		if ( $mins <= 1 ) {
			$mins = 1;
		}
		/* translators: Time difference between two dates, in minutes. %s: Number of minutes. */
		$since = sprintf( _n( '%s minute', '%s minutes', $mins ), $mins );
	} elseif ( $diff < DAY_IN_SECONDS && $diff >= HOUR_IN_SECONDS ) {
		$hours = round( $diff / HOUR_IN_SECONDS );
		if ( $hours <= 1 ) {
			$hours = 1;
		}
		/* translators: Time difference between two dates, in hours. %s: Number of hours. */
		$since = sprintf( _n( '%s hour', '%s hours', $hours ), $hours );
	} elseif ( $diff < WEEK_IN_SECONDS && $diff >= DAY_IN_SECONDS ) {
		$days = round( $diff / DAY_IN_SECONDS );
		if ( $days <= 1 ) {
			$days = 1;
		}
		/* translators: Time difference between two dates, in days. %s: Number of days. */
		$since = sprintf( _n( '%s day', '%s days', $days ), $days );
	} elseif ( $diff < MONTH_IN_SECONDS && $diff >= WEEK_IN_SECONDS ) {
		$weeks = round( $diff / WEEK_IN_SECONDS );
		if ( $weeks <= 1 ) {
			$weeks = 1;
		}
		/* translators: Time difference between two dates, in weeks. %s: Number of weeks. */
		$since = sprintf( _n( '%s week', '%s weeks', $weeks ), $weeks );
	} elseif ( $diff < YEAR_IN_SECONDS && $diff >= MONTH_IN_SECONDS ) {
		$months = round( $diff / MONTH_IN_SECONDS );
		if ( $months <= 1 ) {
			$months = 1;
		}
		/* translators: Time difference between two dates, in months. %s: Number of months. */
		$since = sprintf( _n( '%s month', '%s months', $months ), $months );
	} elseif ( $diff >= YEAR_IN_SECONDS ) {
		$years = round( $diff / YEAR_IN_SECONDS );
		if ( $years <= 1 ) {
			$years = 1;
		}
		/* translators: Time difference between two dates, in years. %s: Number of years. */
		$since = sprintf( _n( '%s year', '%s years', $years ), $years );
	}

	/**
	 * Filters the human-readable difference between two timestamps.
	 *
	 * @since 4.0.0
	 *
	 * @param string $since The difference in human-readable text.
	 * @param int    $diff  The difference in seconds.
	 * @param int    $from  Unix timestamp from which the difference begins.
	 * @param int    $to    Unix timestamp to end the time difference.
	 */
	return $since ; //, $diff, $from, $to );
}


if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Check if file_id exists
 */
function ccs_file_id_exists($file_id) {
    global $ydb;

    $stmt = $ydb->prepare("SELECT COUNT(*) FROM " . CCS_TABLE_FILES . " WHERE file_id = ?");
    $stmt->execute([$file_id]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Get file by ID
 */
function ccs_get_file_by_id($file_id) {
    global $ydb;

    $stmt = $ydb->prepare("SELECT * FROM " . CCS_TABLE_FILES . " WHERE file_id = ? LIMIT 1");
    $stmt->execute([$file_id]);
    return $stmt->fetch(PDO::FETCH_OBJ);
}

/**
 * Get file by S3
 */
function ccs_get_file_by_s3($s3_key) {
    global $ydb;

    $stmt = $ydb->prepare("SELECT * FROM " . CCS_TABLE_FILES . " WHERE s3_key  = ?  LIMIT 1");
    $stmt->execute([$s3_key ]);
    return $stmt->fetch(PDO::FETCH_OBJ);
}


/**
 * Debug logging to custom file
 */
function ccs_debug_log($message, $data = null) {

    $log_file = dirname(__DIR__) . '/debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message";

    if ($data !== null) {
        $log_entry .= "\n" . print_r($data, true);
    }

    $log_entry .= "\n" . str_repeat('-', 80) . "\n";

    file_put_contents($log_file, $log_entry, FILE_APPEND);
}
