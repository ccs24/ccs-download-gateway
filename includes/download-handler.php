
<?php
if (!defined('YOURLS_ABSPATH')) die();

/**
 * Main download request handler
 */
function ccs_handle_download_request($args) {

ccs_debug_log('Download request', $args);
    // Sprawdź czy to nasze żądanie
    if (!isset($_GET['email'])) {
	ccs_debug_log('Nie ma e-maila', $_GET);
        return $args; // Nie nasze - przekaż dalej
    }
    $file_id = $args[0]; // keyword = nasz file_id
    $email = filter_var($_GET['email'], FILTER_SANITIZE_EMAIL);

    ccs_debug_log('Download request', [
        'file_id' => $file_id,
        'email' => $email,
        'ip' => yourls_get_IP()
    ]);


    // Sprawdź najpierw czy to nasz plik
    $file = ccs_get_file_by_id($file_id);

    ccs_debug_log('File data ' , $file);
    if (!$file) {
        // To nie jest nasz file_id - może to normalny YOURLS short link ale ponieważ ma e-mail, to może trzeba jakoś zareagować?
    	ccs_debug_log('Is Email - no FILE? ', [
        	'file_id' => $file_id,
        	'email' => $email,
        	'ip' => yourls_get_IP()
    	]);
        return $args;
    }

    // Collect attempt data
    $attempt_data = [
        'email' => $email,
        'file_id' => $file_id,
        'ip' => yourls_get_IP(),
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
        'referer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '',
        'timestamp' => date('Y-m-d H:i:s')
    ];



    // Check rate limit
 /*   if (!ccs_check_rate_limit($email)) {
        ccs_log_attempt($attempt_data, 'failed', 'rate_limit', 'Przekroczony limit pobrań');
        yourls_redirect(CCS_REDIRECT_ERROR . '?reason=rate_limit', 302);
        die("rate limit");
    } */


    $attempt_data['file_name'] = $file->filename;
    $attempt_data['file_title'] = $file->title;

ccs_debug_log('Attempt data: ', $attempt_data);

    // Verify subscriber in GetResponse
    $verification = ccs_verify_getresponse_subscriber($email);

    if (!$verification['valid']) {
        // Log failure
        ccs_log_attempt($attempt_data, 'failed', $verification['reason'], $verification['details']);

	ccs_debug_log('Failure',$verification);

        // Send alerts
        ccs_send_failure_alerts($attempt_data, $verification['reason'], $verification['details']);


	// Przygotuj parametry dla przekierowania
	$params = array(
    		'reason' => $verification['reason'],  // 'not_on_list', 'not_confirmed', 'api_error'
    		'file_id' => intval($file_id)
		);

	// Dodaj email jeśli dostępny (ważne dla not_confirmed)
	if (isset($email) && !empty($email)) {
    		$params['email'] = $email;
	}

	// Opcjonalnie: nazwa pliku
	 if (isset($file) && !empty($file->filename)) {
	     $params['file'] = $file->filename;
	 }

	// Zbuduj query string - CZYSTY PHP
	$query_string = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

	// Base URL (używasz już zdefiniowanej stałej)
	$redirect_url = CCS_REDIRECT_NOT_ON_LIST . '?' . $query_string;

	// Przekieruj używając funkcji YOURLS
	yourls_redirect($redirect_url, 302);
	die();

    }

    // SUCCESS - Generate presigned URL
    try {

        $download_url = ccs_s3_get_presigned_url($file->s3_key, $file->filename);
	ccs_debug_log('S3 Download url ',$download_url);

        if (!$download_url) {
            throw new Exception('Nie udało się wygenerować linku do pobrania');
        }

        // Log success
        ccs_log_attempt($attempt_data, 'success', null, 'Plik pobrany pomyślnie');

        // Redirect to S3
        yourls_redirect($download_url, 302);
        die();

    } catch (Exception $e) {
	ccs_debug_log('S3 Error',[$e->getMessage()]);
        ccs_log_attempt($attempt_data, 'failed', 's3_error', $e->getMessage());
        ccs_send_critical_alert($attempt_data, $e->getMessage());
        yourls_redirect(CCS_REDIRECT_ERROR . '?reason=api_error', 302);
        die();
    }
}

/**
 * Log download attempt
 */
function ccs_log_attempt($attempt_data, $status, $failure_reason = null, $details_message = '') {
    global $ydb;
    
    $details = json_encode([
        'reason_message' => $details_message,
        'file_name' => $attempt_data['file_name'] ?? '',
        'file_title' => $attempt_data['file_title'] ?? '',
        'user_agent' => $attempt_data['user_agent'],
        'referer' => $attempt_data['referer']
    ]);
    
    $query = "INSERT INTO " . CCS_TABLE_ATTEMPTS . " 
              (email, file_id, status, failure_reason, details, attempted_at, ip_address, user_agent, referer) 
              VALUES (:email, :file_id, :status, :reason, :details, NOW(), :ip, :ua, :ref)";
    
    $ydb->fetchAffected($query, [
        'email' => $attempt_data['email'],
        'file_id' => $attempt_data['file_id'],
        'status' => $status,
        'reason' => $failure_reason,
        'details' => $details,
        'ip' => $attempt_data['ip'],
        'ua' => $attempt_data['user_agent'],
        'ref' => $attempt_data['referer']
    ]);
}

