<?php
/**
 * CCS Download Gateway - Configuration
 */

if (!defined('YOURLS_ABSPATH')) die();

if (defined('CCS_CONFIG_LOADED')) {
    return;
}
define('CCS_CONFIG_LOADED', true);


// ============================================
// AWS S3 Configuration
// ============================================
define('CCS_AWS_ACCESS_KEY', '');
define('CCS_AWS_SECRET_KEY', '');
define('CCS_AWS_S3_REGION', 'eu-central-1');
define('CCS_AWS_S3_BUCKET', '');
define('S3_URL_PREFIX', '' ); // change prefix as you want

// ============================================
// GetResponse Configuration
// ============================================
define('CCS_GR_API_KEY', '');
define('CCS_GR_CAMPAIGN_ID', ''); // ID listy mailingowej newsletter

// ============================================
// Download Link Configuration
// ============================================
define('CCS_DOWNLOAD_DOMAIN', '');
define('CCS_LINK_TEMPLATE', CCS_DOWNLOAD_DOMAIN . '/%s?email={{email}}');

// ============================================
// Redirect URLs
// ============================================
define('CCS_REDIRECT_NOT_ON_LIST', 'https://customercentric.com.pl/zasoby/newsletter/');
define('CCS_REDIRECT_NOT_CONFIRMED', 'https://customercentric.com.pl/zasoby/newsletter/');
define('CCS_REDIRECT_ERROR', 'https://customercentric.com.pl/zasoby/newsletter/');

// ============================================
// Email Alerts
// ============================================
define('CCS_ALERT_EMAIL', '');
define('CCS_ALERT_CC', '');
define('CCS_ALERT_FROM', '');
define('CCS_SEND_USER_HELP_EMAIL', false);

// ============================================
// Upload Settings
// ============================================
define('CCS_ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'ppt', 'pptx']);
define('CCS_MAX_FILE_SIZE', 52428800); // 50MB w bajtach

// ============================================
// Rate Limiting
// ============================================
define('CCS_RATE_LIMIT_ENABLED', true);
define('CCS_RATE_LIMIT_MAX', 5); // Max prób na godzinę
define('CCS_RATE_LIMIT_PERIOD', 3600); // Okres w sekundach

// ============================================
// Database Tables
// ============================================
define('CCS_TABLE_FILES', YOURLS_DB_PREFIX . 'ccs_files');
define('CCS_TABLE_ATTEMPTS', YOURLS_DB_PREFIX . 'ccs_download_attempts');

// ============================================
// S3 Folders
// ============================================
define('CCS_S3_FOLDERS', [
    'downloads' => 'Downloads',
    'guides' => 'Przewodniki',
    'templates' => 'Szablony',
    'case-studies' => 'Case Studies',
    'webinars' => 'Webinary'
]);

