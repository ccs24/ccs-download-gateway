<?php
if (!defined('YOURLS_ABSPATH')) die();

/**
 * Verify subscriber in GetResponse
 * Główna funkcja weryfikacji - używana przez download-handler.php
 * 
 * @return array ['valid' => bool, 'reason' => string, 'details' => string, 'contact' => array]
 */
function ccs_verify_getresponse_subscriber($email) {
    $api_key = CCS_GR_API_KEY;
    $campaign_id = CCS_GR_CAMPAIGN_ID;
    
    ccs_debug_log('Weryfikacja GetResponse', [
        'email' => $email,
        'campaign_id' => $campaign_id
    ]);
    
    // KROK 1: Sprawdź czy jest POTWIERDZONY (subscribed)
    $subscribed = ccs_check_contact_subscribed($email, $campaign_id, $api_key);
    
    if (!empty($subscribed)) {
        ccs_debug_log('Status: SUBSCRIBED', $subscribed[0]);
        return [
            'valid' => true,
            'reason' => 'success',
            'details' => 'Subskrybent zweryfikowany pomyślnie - status SUBSCRIBED',
            'contact' => $subscribed[0]
        ];
    }
    
    // KROK 2: Sprawdź czy jest NIEPOTWIERDZONY (unconfirmed)
    $unconfirmed = ccs_check_contact_unconfirmed($email, $campaign_id, $api_key);
    
    if (!empty($unconfirmed)) {
        ccs_debug_log('Status: UNCONFIRMED', $unconfirmed[0]);
        return [
            'valid' => false,
            'reason' => 'not_confirmed',
            'details' => 'Email jest na liście ale nie potwierdził zapisu (double opt-in). Sprawdź skrzynkę i kliknij link aktywacyjny.',
            'contact' => $unconfirmed[0]
        ];
    }
    
    // KROK 3: Nie znaleziono na żadnej liście
    ccs_debug_log('Status: NOT_FOUND', ['email' => $email]);
    return [
        'valid' => false,
        'reason' => 'not_on_list',
        'details' => 'Email nie znajduje się na liście mailingowej',
        'contact' => null
    ];
}

/**
 * Sprawdź czy kontakt jest POTWIERDZONY na liście
 * 
 * @param string $email
 * @param string $campaign_id
 * @param string $api_key
 * @return array Tablica kontaktów lub pusta tablica
 */
function ccs_check_contact_subscribed($email, $campaign_id, $api_key) {
    $payload = [
        'subscribersType' => ['subscribed'], // TYLKO potwierdzone
        'sectionLogicOperator' => 'or',
        'section' => [
            [
                'campaignIdsList' => [$campaign_id],
                'logicOperator' => 'or',
                'subscriberCycle' => [
                    'receiving_autoresponder',
                    'not_receiving_autoresponder'
                ],
                'subscriptionDate' => 'all_time',
                'conditions' => [
                    [
                        'conditionType' => 'email',
                        'operatorType' => 'string_operator',
                        'operator' => 'is',
                        'value' => $email
                    ]
                ]
            ]
        ]
    ];
    
    return ccs_call_getresponse_search_api($payload, $api_key, 'subscribed');
}

/**
 * Sprawdź czy kontakt jest NIEPOTWIERDZONY na liście
 * 
 * @param string $email
 * @param string $campaign_id
 * @param string $api_key
 * @return array Tablica kontaktów lub pusta tablica
 */
function ccs_check_contact_unconfirmed($email, $campaign_id, $api_key) {
    $payload = [
        'subscribersType' => ['unconfirmed'], // TYLKO niepotwierdzone
        'sectionLogicOperator' => 'or',
        'section' => [
            [
                'campaignIdsList' => [$campaign_id],
                'logicOperator' => 'or',
                'subscriberCycle' => [
                    'receiving_autoresponder',
                    'not_receiving_autoresponder'
                ],
                'subscriptionDate' => 'all_time',
                'conditions' => [
                    [
                        'conditionType' => 'email',
                        'operatorType' => 'string_operator',
                        'operator' => 'is',
                        'value' => $email
                    ]
                ]
            ]
        ]
    ];
    
    return ccs_call_getresponse_search_api($payload, $api_key, 'unconfirmed');
}

/**
 * Wywołanie Search API GetResponse
 * 
 * @param array $payload Payload do wysłania
 * @param string $api_key Klucz API
 * @param string $search_type Typ wyszukiwania (do logowania)
 * @return array Tablica kontaktów lub pusta tablica
 */
function ccs_call_getresponse_search_api($payload, $api_key, $search_type = '') {
    $url = 'https://api.getresponse.com/v3/search-contacts/contacts';
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
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
    
    // Log request details
    ccs_debug_log('GetResponse Search API Request', [
        'search_type' => $search_type,
        'url' => $url,
        'http_code' => $http_code
    ]);
    
    // Błąd połączenia
    if ($http_code === 0) {
        ccs_debug_log('GetResponse API Connection Error', [
            'curl_error' => $curl_error
        ]);
        return [];
    }
    
    // Błąd API
    if ($http_code !== 200) {
        ccs_debug_log('GetResponse API Error', [
            'http_code' => $http_code,
            'response' => $response
        ]);
        return [];
    }
    
    $data = json_decode($response, true);
    
    ccs_debug_log('GetResponse API Response', [
        'search_type' => $search_type,
        'found_contacts' => count($data),
        'data' => $data
    ]);
    
    return $data;
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
