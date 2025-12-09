<?php
/**
 * Plugin Name: CCS Download Gateway
 * Plugin URI: https://ccs.pl
 * Description: Kompleksowy system zarządzania plikami do pobrania z weryfikacją GetResponse
 * Version: 1.0
 * Author: CustomerCentric Selling Poland
 */

if (!defined('YOURLS_ABSPATH')) die();

//ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

// Load configuration
require_once __DIR__ . '/config.php';

// Load includes
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/s3-helper.php';
require_once __DIR__ . '/includes/getresponse.php';
require_once __DIR__ . '/includes/alerts.php';
require_once __DIR__ . '/includes/download-handler.php';
require_once __DIR__ . '/includes/upload-handler.php';
require_once __DIR__ . '/includes/stats-page.php';

// Register admin page
yourls_add_action('plugins_loaded', 'ccs_register_admin_page');

function ccs_register_admin_page() {
    yourls_register_plugin_page('ccs-files', 'Zarządzanie plikami', 'ccs_admin_page');
}

// Admin page handler
function ccs_admin_page() {
    require_once __DIR__ . '/includes/admin-page.php';
}

// Handle file downloads (public endpoint)
yourls_add_filter('redirect_keyword_not_found', 'ccs_handle_download_request');


// Register stats page
yourls_add_action('plugins_loaded', 'ccs_register_stats_page');
function ccs_register_stats_page() {
    yourls_register_plugin_page('ccs-stats', 'Statystyki Pobrań', 'ccs_stats_page_handler');
}

function ccs_stats_page_handler() {
    ccs_render_stats_page();
}




// Add styles and scripts for admin
yourls_add_action('html_head', 'ccs_admin_assets');
function ccs_admin_assets() {
    if (isset($_GET['page']) && $_GET['page'] === 'ccs-files') {
        $base = preg_replace('#^https?://#', '//', YOURLS_PLUGINURL);
        echo '<link rel="stylesheet" href="' . $base . '/ccs-download-gateway/assets/style.css">';
        echo '<script src="' . $base . '/ccs-download-gateway/assets/script.js"></script>';
    }
}


// Definicja prefiksu
if (!defined('CCS_S3_PREFIX')) {
    define('CCS_S3_PREFIX', '++');
}

yourls_add_action('loader_failed', 'ccs_s3_redirect');

function ccs_s3_redirect($args) {
    ccs_debug_log('=== S3 Redirect Start ===', $args);

    $request = $args[0];
    $pattern = yourls_make_regexp_pattern(yourls_get_shorturl_charset());

    ccs_debug_log('Request:', $request);
//    ccs_debug_log('Pattern:', $pattern);

    // Sprawdź czy request zaczyna się od CCS_S3_PREFIX (++)
    $prefix_escaped = preg_quote(CCS_S3_PREFIX, '@');

    if (preg_match("@^{$prefix_escaped}([$pattern]+)$@", $request, $matches)) {
        $keyword = isset($matches[1]) ? $matches[1] : '';
        $keyword = yourls_sanitize_keyword($keyword);

// ccs_debug_log('Matched S3 prefix, keyword:', $keyword);

        // Sprawdź czy keyword istnieje w YOURLS (bez prefiksu)
        if (!yourls_is_shorturl($keyword)) {
// ccs_debug_log('Keyword not found in YOURLS:', $keyword);
            yourls_redirect(yourls_site_url() . '/404', 302);
            die();
        }

        // Pobierz s3_key z YOURLS long URL
        $s3_key = yourls_get_keyword_longurl($keyword);

 ccs_debug_log('Keyword:', $keyword);
 ccs_debug_log('S3 Key from YOURLS:', $s3_key);

        // Sprawdź czy to nasz plik S3
        $file = ccs_get_file_by_s3($s3_key);

        if (!$file) {
            ccs_debug_log('File not found in CCS_TABLE_FILES', [
                's3_key' => $s3_key,
                'ip' => yourls_get_IP()
            ]);
	    return;
            //yourls_redirect(yourls_site_url() . '/error?msg=file_not_found', 302);
            //die();
        }

 ccs_debug_log('File found:', [
            'id' => $file->id,
            'file_id' => $file->file_id,
            'filename' => $file->filename,
            'active' => $file->active
        ]);

        // === GENERUJ PRE-SIGNED URL ===
        try {
            $presigned_url = ccs_s3_get_presigned_url($file->s3_key, $file->filename);

 // ccs_debug_log('Pre-signed URL generated successfully');

            // Log download
 //ccs_debug_log($keyword, $file->file_id, $s3_key);

		yourls_update_clicks($keyword);

            // Redirect do S3
            yourls_redirect($presigned_url, 302);
            die();

        } catch (Exception $e) {
 ccs_debug_log('S3 Error:', $e->getMessage());
            //yourls_redirect(yourls_site_url() . '/error?msg=s3_error', 302);
            //die();
        }
    }

// Nie pasuje do wzorca ++ - pozwól YOURLS obsłużyć normalnie
ccs_debug_log('Not S3 request, passing to YOURLS');

}
