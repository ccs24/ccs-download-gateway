<?php
if (!defined('YOURLS_ABSPATH')) die();

/**
 * Send failure alerts (admin + user)
 */
function ccs_send_failure_alerts($attempt_data, $reason, $details) {
    // Alert do admina
    ccs_send_admin_alert($attempt_data, $reason, $details);

    // Email do użytkownika (jeśli włączone)
    if (CCS_SEND_USER_HELP_EMAIL) {
        ccs_send_user_help_email($attempt_data, $reason, $details);
    }

    // Oznacz że alert wysłano
    global $ydb;
    $query = "UPDATE " . CCS_TABLE_ATTEMPTS . " 
              SET alert_sent = 1 
              WHERE email = :email 
              AND attempted_at = (SELECT MAX(attempted_at) FROM " . CCS_TABLE_ATTEMPTS . " WHERE email = :email2)";

    $ydb->fetchAffected($query, [
        'email' => $attempt_data['email'],
        'email2' => $attempt_data['email']
    ]);
}

/**
 * Send admin alert
 */
function ccs_send_admin_alert($attempt_data, $reason, $details) {
    $reason_map = [
        'not_on_list' => 'NIE MA NA LIŚCIE',
        'not_confirmed' => 'NIE POTWIERDZIŁ ZAPISU',
        'invalid_email' => 'BŁĘDNY EMAIL',
        'rate_limit' => 'PRZEKROCZONY LIMIT',
        'file_not_found' => 'PLIK NIE ISTNIEJE',
        'api_error' => 'BŁĄD API',
        's3_error' => 'BŁĄD S3'
    ];
    
    $subject = '⚠️ Nieudane pobranie pliku: ' . ($reason_map[$reason] ?? $reason);
    
    $message = "Ktoś próbował pobrać plik ale się nie udało.\n\n";
    $message .= "SZCZEGÓŁY PRÓBY:\n";
    $message .= "================\n";
    $message .= "Email: {$attempt_data['email']}\n";
    $message .= "Plik: " . ($attempt_data['file_title'] ?? 'N/A') . "\n";
    $message .= "File ID: {$attempt_data['file_id']}\n";
    $message .= "Data: {$attempt_data['timestamp']}\n";
    $message .= "IP: {$attempt_data['ip']}\n\n";
    
    $message .= "POWÓD NIEPOWODZENIA:\n";
    $message .= "===================\n";
    $message .= "$details\n\n";
    
    $message .= "AKCJE DO WYKONANIA:\n";
    $message .= "==================\n";
    
    switch ($reason) {
        case 'not_on_list':
            $message .= "□ Sprawdź czy osoba zapisała się na właściwą listę\n";
            $message .= "□ Sprawdź czy email nie ma literówki\n";
            $message .= "□ Rozważ dodanie ręcznie przez panel GetResponse\n";
            $message .= "□ Skontaktuj się: {$attempt_data['email']}\n";
            break;
            
        case 'not_confirmed':
            $message .= "□ Sprawdź skrzynkę SPAM użytkownika\n";
            $message .= "□ Wyślij ponownie email z potwierdzeniem\n";
            $message .= "□ Skontaktuj się: {$attempt_data['email']}\n";
            break;
            
        case 'api_error':
            $message .= "□ Sprawdź połączenie z GetResponse API\n";
            $message .= "□ Sprawdź ważność API key\n";
            $message .= "□ Sprawdź logi serwera\n";
            break;
    }
    
    $message .= "\nUSER AGENT:\n{$attempt_data['user_agent']}\n";
    $message .= "\nREFERER:\n{$attempt_data['referer']}\n";
    
    $headers = [
        'From: ' . CCS_ALERT_FROM,
        'Cc: ' . CCS_ALERT_CC,
        'Content-Type: text/plain; charset=UTF-8'
    ];
    
    mail(CCS_ALERT_EMAIL, $subject, $message, implode("\r\n", $headers));
}

/**
 * Send help email to user
 */
function ccs_send_user_help_email($attempt_data, $reason, $details) {
    $email = $attempt_data['email'];
    
    switch ($reason) {
        case 'not_on_list':
            $subject = 'Problem z pobraniem pliku - proszę o zapis na newsletter';
            $message = "Cześć!\n\n";
            $message .= "Próbowałeś/aś pobrać plik: " . ($attempt_data['file_title'] ?? 'N/A') . "\n\n";
            $message .= "Niestety Twój adres email nie znajduje się na naszej liście mailingowej.\n\n";
            $message .= "Aby pobrać plik, proszę zapisz się na newsletter:\n";
            $message .= CCS_REDIRECT_NOT_ON_LIST . "?file={$attempt_data['file_id']}\n\n";
            $message .= "Jeśli uważasz, że to błąd, odpowiedz na tego maila - chętnie pomogę!\n\n";
            $message .= "Pozdrawiam,\nGrzegorz Cieślik\nCustomerCentric Selling Poland";
            break;
            
        case 'not_confirmed':
            $subject = 'Proszę potwierdź zapis do newslettera';
            $message = "Cześć!\n\n";
            $message .= "Próbowałeś/aś pobrać plik: " . ($attempt_data['file_title'] ?? 'N/A') . "\n\n";
            $message .= "Widzę, że zapisałeś/aś się na newsletter, ale jeszcze nie potwierdziłeś/aś zapisu.\n\n";
            $message .= "KROK 1: Sprawdź swoją skrzynkę email (również SPAM!)\n";
            $message .= "KROK 2: Znajdź email od GetResponse / CustomerCentric Selling Poland\n";
            $message .= "KROK 3: Kliknij w link potwierdzający\n\n";
            $message .= "Jeśli nie możesz znaleźć emaila z potwierdzeniem:\n";
            $message .= "- Odpowiedz na tego maila\n";
            $message .= "- Wyślę Ci link ponownie\n\n";
            $message .= "Pozdrawiam,\nGrzegorz Cieślik\nCustomerCentric Selling Poland\nsupport@ccs.pl";
            break;
            
        default:
            return; // Nie wysyłaj dla innych powodów
    }
    
    $headers = [
        'From: Grzegorz Cieślik <grzegorz@ccs.pl>',
        'Reply-To: support@ccs.pl',
        'Content-Type: text/plain; charset=UTF-8'
    ];
    
    mail($email, $subject, $message, implode("\r\n", $headers));
}

/**
 * Send critical alert
 */
function ccs_send_critical_alert($attempt_data, $error_message) {
    $subject = '🚨 KRYTYCZNY BŁĄD - System pobierania plików';
    
    $message = "UWAGA! Wystąpił krytyczny błąd w systemie pobierania!\n\n";
    $message .= "ERROR:\n======\n$error_message\n\n";
    $message .= "USER:\n=====\n";
    $message .= "Email: {$attempt_data['email']}\n";
    $message .= "Plik: {$attempt_data['file_id']}\n";
    $message .= "Czas: {$attempt_data['timestamp']}\n\n";
    $message .= "NATYCHMIASTOWE DZIAŁANIE WYMAGANE!\n";
    
    mail(CCS_ALERT_EMAIL, $subject, $message, 'From: ' . CCS_ALERT_FROM);
}
