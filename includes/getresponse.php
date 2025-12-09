<?php
if (!defined('YOURLS_ABSPATH')) die();

/**
 * Verify subscriber in GetResponse
 * 
 * @return array ['valid' => bool, 'reason' => string, 'details' => string, 'contact' => array]
 */
function ccs_verify_getresponse_subscriber($email) {
    $api_key = CCS_GR_API_KEY;
    $campaign_id = CCS_GR_CAMPAIGN_ID;

    $url = "https://api.getresponse.com/v3/contacts?" . http_build_query([
        'query[email]' => $email,
        'query[campaignId]' => $campaign_id
    ]);

	// DOBRZE - ręczne budowanie URL
    //$url = 'https://api.getresponse.com/v3/contacts?query[email]=' . urlencode($email) . '&query[campaignId]=' . CCS_GR_CAMPAIGN_ID;

    $url = 'https://api.getresponse.com/v3/campaigns/' . CCS_GR_CAMPAIGN_ID . '/contacts?query[email]=' . urlencode($email);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-Auth-Token: api-key ' . $api_key,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Błąd połączenia
    if ($http_code === 0) {
        return [
            'valid' => false,
            'reason' => 'api_error',
            'details' => 'Błąd połączenia z GetResponse API: ' . $curl_error,
            'contact' => null
        ];
    }

    // Błąd API
    if ($http_code !== 200) {
        return [
            'valid' => false,
            'reason' => 'api_error',
            'details' => 'GetResponse API zwróciło kod: ' . $http_code,
            'contact' => null
        ];
    }

    $data = json_decode($response, true);

ccs_debug_log("GetResponse API data ",[$data,$response]);

    // Nie ma na liście
    if (empty($data)) {
        return [
            'valid' => false,
            'reason' => 'not_on_list',
            'details' => 'Email nie znajduje się na liście mailingowej',
            'contact' => null
        ];
    }

    $contact = $data[0];

    // Sprawdź czy potwierdził (double opt-in)
    // GetResponse: kontakt potwierdzony ma wypełnione pole 'changedOn'
    if (!isset($contact['changedOn']) || empty($contact['changedOn'])) {
        return [
            'valid' => false,
            'reason' => 'not_confirmed',
            'details' => 'Email jest na liście ale nie potwierdził zapisu (double opt-in)',
            'contact' => $contact
        ];
    }

    // Wszystko OK
    return [
        'valid' => true,
        'reason' => 'success',
        'details' => 'Subskrybent zweryfikowany pomyślnie',
        'contact' => $contact
    ];
}

/**
 * Check rate limit
 */
function ccs_check_rate_limit($email) {
    if (!CCS_RATE_LIMIT_ENABLED) {
        return true;
    }

    global $ydb;

    $query = "SELECT COUNT(*) as cnt FROM " . CCS_TABLE_ATTEMPTS . " 
              WHERE email = :email 
              AND attempted_at > DATE_SUB(NOW(), INTERVAL :period SECOND)";

    $result = $ydb->fetchOne($query, [
        'email' => $email,
        'period' => CCS_RATE_LIMIT_PERIOD
    ]);

    return ($result < CCS_RATE_LIMIT_MAX);
}
