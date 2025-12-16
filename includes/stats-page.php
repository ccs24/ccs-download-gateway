<?php
if (!defined('YOURLS_ABSPATH')) die();

/**
 * CCS Download Gateway - Statistics Page
 * Wersja poprawiona - czysty kod, pełna obsługa filtrów
 */

// Helper function for select
if (!function_exists('selected')) {
    function selected($selected, $current, $echo = true) {
        $result = ($selected == $current) ? ' selected="selected"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    ccs_export_stats_csv();
    exit;
}

/**
 * Export statistics to CSV
 */
function ccs_export_stats_csv() {
    global $ydb;
    
    // Get filters
    $filters = ccs_get_filters();
    list($where_sql, $params) = ccs_build_where_clause($filters);
    
    // Get data
    $stmt = $ydb->prepare("
        SELECT a.*, f.filename
        FROM " . CCS_TABLE_ATTEMPTS . " a
        LEFT JOIN " . CCS_TABLE_FILES . " f ON a.file_id = f.file_id
        WHERE $where_sql
        ORDER BY a.attempted_at DESC
    ");
    $stmt->execute($params);
    $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pobierania_' . date('Y-m-d_H-i') . '.csv"');
    
    // Output CSV
    $output = fopen('php://output', 'w');
    
    // BOM for Excel UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Headers
    fputcsv($output, ['Email', 'Plik ID', 'Nazwa pliku', 'Data', 'IP', 'Status', 'Powód', 'Szczegóły'], ';');
    
    // Data
    foreach ($attempts as $attempt) {
        fputcsv($output, [
            $attempt['email'],
            $attempt['file_id'],
            $attempt['filename'] ?: 'N/A',
            $attempt['attempted_at'],
            $attempt['ip_address'],
            $attempt['status'] == 'success' ? 'Sukces' : 'Niepowodzenie',
            $attempt['failure_reason'] ?: '',
            $attempt['details'] ?: ''
        ], ';');
    }
    
    fclose($output);
}

/**
 * Get filters from URL
 */
function ccs_get_filters() {
    return [
        'filter' => isset($_GET['filter']) ? $_GET['filter'] : 'all',
        'file_id' => isset($_GET['file_id']) ? yourls_sanitize_string($_GET['file_id']) : '',
        'days' => isset($_GET['days']) ? intval($_GET['days']) : 30
    ];
}

/**
 * Build WHERE clause based on filters
 * Returns [sql_string, params_array]
 */
function ccs_build_where_clause($filters) {
    $where = ["1=1"];
    $params = [];
    
    // Status filter
    switch ($filters['filter']) {
        case 'success':
            $where[] = "a.status = 'success'";
            break;
        case 'failed':
            $where[] = "a.status = 'failed'";
            break;
        case 'not_on_list':
            $where[] = "a.status = 'failed' AND a.failure_reason = 'not_on_list'";
            break;
        case 'not_confirmed':
            $where[] = "a.status = 'failed' AND a.failure_reason = 'not_confirmed'";
            break;
        case 'rate_limit':
            $where[] = "a.status = 'failed' AND a.failure_reason = 'rate_limit'";
            break;
        case 'api_error':
            $where[] = "a.status = 'failed' AND a.failure_reason = 'api_error'";
            break;
    }
    
    // Date filter
    if ($filters['days'] > 0) {
        $where[] = "a.attempted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params[] = $filters['days'];
    }
    
    // File filter
    if ($filters['file_id']) {
        $where[] = "a.file_id = ?";
        $params[] = $filters['file_id'];
    }
    
    return [implode(" AND ", $where), $params];
}

/**
 * Get main statistics
 */
function ccs_get_main_stats($where_sql, $params) {
    global $ydb;
    
    $stmt = $ydb->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(a.status = 'success') as successful,
            SUM(a.status = 'failed') as failed,
            COUNT(DISTINCT a.email) as unique_emails
        FROM " . CCS_TABLE_ATTEMPTS . " a
        WHERE $where_sql
    ");
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_OBJ);
}

/**
 * Get reason counts
 */
function ccs_get_reason_counts($filters) {
    global $ydb;
    
    $reasons = ['not_on_list', 'not_confirmed', 'rate_limit', 'api_error'];
    $counts = [];
    
    foreach ($reasons as $reason) {
        $where = ["a.failure_reason = ?"];
        $params = [$reason];
        
        if ($filters['days'] > 0) {
            $where[] = "a.attempted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params[] = $filters['days'];
        }
        
        if ($filters['file_id']) {
            $where[] = "a.file_id = ?";
            $params[] = $filters['file_id'];
        }
        
        $where_sql = implode(" AND ", $where);
        
        $stmt = $ydb->prepare("SELECT COUNT(*) FROM " . CCS_TABLE_ATTEMPTS . " a WHERE $where_sql");
        $stmt->execute($params);
        $counts[$reason] = $stmt->fetchColumn();
    }
    
    return $counts;
}

/**
 * Get filter name
 */
function ccs_get_filter_name($filter) {
    $names = [
        'success' => '✅ Pobrane pomyślnie',
        'failed' => '❌ Wszystkie odrzucone',
        'not_on_list' => '📧 Nie na liście GetResponse',
        'not_confirmed' => '⏳ Nie potwierdzili zapisu',
        'rate_limit' => '🚫 Rate limited',
        'api_error' => '⚠️ Błędy API'
    ];
    return $names[$filter] ?? $filter;
}

/**
 * Render clickable stat tile
 */
function ccs_render_stat_tile($url, $value, $label, $color, $is_active = false) {
    $bg_color = $is_active ? $color['active_bg'] : $color['bg'];
    $text_color = $color['text'];
    $border = $is_active ? "box-shadow: 0 0 0 3px {$color['border']};" : '';
    $font_weight = $is_active ? 'bold' : 'normal';
    
    echo '<a href="' . esc_url($url) . '" 
           style="background: ' . $bg_color . '; padding: 20px; border-radius: 8px; text-align: center; text-decoration: none; transition: transform 0.2s; display: block; ' . $border . '"
           onmouseover="this.style.transform=\'scale(1.05)\'" 
           onmouseout="this.style.transform=\'scale(1)\'"
           title="Kliknij aby zobaczyć szczegóły">
            <div style="font-size: 32px; font-weight: bold; color: ' . $text_color . ';">' . esc_html($value) . '</div>
            <div style="color: ' . $text_color . '; font-weight: ' . $font_weight . ';">' . $label . '</div>
          </a>';
}

/**
 * Main statistics page render
 */
function ccs_render_stats_page() {
    global $ydb;
    
    // Get filters
    $filters = ccs_get_filters();
    $filter = $filters['filter'];
    $file_filter = $filters['file_id'];
    $days = $filters['days'];
    
    // Build WHERE clause
    list($where_sql, $params) = ccs_build_where_clause($filters);
    
    // Get statistics
    $stats = ccs_get_main_stats($where_sql, $params);
    $reason_counts = ccs_get_reason_counts($filters);
    
    ?>
    <div class="wrap">
        <h1>📊 Statystyki Pobrań</h1>
        
        <!-- Breadcrumbs -->
        <div style="margin: 20px 0; padding: 10px; background: #f8f9fa; border-radius: 4px;">
            <a href="?page=ccs-stats" style="text-decoration: none;">📊 Wszystkie statystyki</a>
            <?php if ($filter !== 'all'): ?>
                → <strong><?php echo ccs_get_filter_name($filter); ?></strong>
            <?php endif; ?>
            <?php if ($file_filter): ?>
                → Plik: <code><?php echo yourls_esc_html($file_filter); ?></code>
            <?php endif; ?>
        </div>
        
        <!-- Filters Form -->
        <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <form method="get" action="">
                <input type="hidden" name="page" value="ccs-stats">
                
                <label>
                    <strong>Status:</strong>
                    <select name="filter" onchange="this.form.submit()">
                        <option value="all" <?php selected($filter, 'all'); ?>>Wszystkie</option>
                        <option value="success" <?php selected($filter, 'success'); ?>>✅ Pobrane pomyślnie</option>
                        <option value="failed" <?php selected($filter, 'failed'); ?>>❌ Wszystkie odrzucone</option>
                        <option value="not_on_list" <?php selected($filter, 'not_on_list'); ?>>📧 Nie na liście</option>
                        <option value="not_confirmed" <?php selected($filter, 'not_confirmed'); ?>>⏳ Nie potwierdzeni</option>
                        <option value="rate_limit" <?php selected($filter, 'rate_limit'); ?>>🚫 Rate limited</option>
                        <option value="api_error" <?php selected($filter, 'api_error'); ?>>⚠️ Błędy API</option>
                    </select>
                </label>
                
                <label style="margin-left: 20px;">
                    <strong>Okres:</strong>
                    <select name="days" onchange="this.form.submit()">
                        <option value="7" <?php selected($days, 7); ?>>Ostatnie 7 dni</option>
                        <option value="30" <?php selected($days, 30); ?>>Ostatnie 30 dni</option>
                        <option value="90" <?php selected($days, 90); ?>>Ostatnie 90 dni</option>
                        <option value="365" <?php selected($days, 365); ?>>Ostatni rok</option>
                        <option value="0" <?php selected($days, 0); ?>>Wszystkie</option>
                    </select>
                </label>
                
                <label style="margin-left: 20px;">
                    <strong>Plik:</strong>
                    <select name="file_id" onchange="this.form.submit()">
                        <option value="">Wszystkie pliki</option>
                        <?php
                        try {
                            $stmt = $ydb->prepare("SELECT file_id, filename FROM " . CCS_TABLE_FILES . " WHERE active = 1 ORDER BY filename");
                            $stmt->execute();
                            $files = $stmt->fetchAll(PDO::FETCH_OBJ);
                            
                            foreach ($files as $file) {
                                $is_selected = ($file_filter === $file->file_id) ? ' selected="selected"' : '';
                                echo '<option value="' . yourls_esc_attr($file->file_id) . '"' . $is_selected . '>' . 
                                     yourls_esc_html($file->filename) . '</option>';
                            }
                        } catch (Exception $e) {
                            echo '<option value="">Błąd: ' . esc_html($e->getMessage()) . '</option>';
                        }
                        ?>
                    </select>
                </label>
                
                <a href="?page=ccs-stats&export=csv&filter=<?php echo $filter; ?>&days=<?php echo $days; ?>&file_id=<?php echo $file_filter; ?>" 
                   class="button button-secondary" style="margin-left: 20px;">
                    📥 Eksport CSV
                </a>
            </form>
        </div>
        
        <!-- Main Stats - CLICKABLE TILES -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
            
            <?php
            // All attempts
            $url = "?page=ccs-stats&filter=all&days=$days&file_id=$file_filter";
            ccs_render_stat_tile($url, number_format($stats->total), '📊 Wszystkie próby', [
                'bg' => '#e7f3ff',
                'active_bg' => '#bee5eb',
                'text' => '#0066cc',
                'border' => '#17a2b8'
            ], $filter === 'all');
            
            // Successful
            $url = "?page=ccs-stats&filter=success&days=$days&file_id=$file_filter";
            ccs_render_stat_tile($url, '✅ ' . number_format($stats->successful), 'Pobrane pomyślnie', [
                'bg' => '#d4edda',
                'active_bg' => '#c3e6cb',
                'text' => '#28a745',
                'border' => '#28a745'
            ], $filter === 'success');
            
            // Failed
            $url = "?page=ccs-stats&filter=failed&days=$days&file_id=$file_filter";
            ccs_render_stat_tile($url, '❌ ' . number_format($stats->failed), 'Odrzucone', [
                'bg' => '#f8d7da',
                'active_bg' => '#f1b0b7',
                'text' => '#dc3545',
                'border' => '#dc3545'
            ], $filter === 'failed');
            
            // Unique emails - kliknięcie pokazuje wszystkie (to jest statystyka, nie filter)
            $url = "?page=ccs-stats&filter=all&days=$days&file_id=$file_filter";
            ccs_render_stat_tile($url, '👥 ' . number_format($stats->unique_emails), 'Unikalne emaile', [
                'bg' => '#d1ecf1',
                'active_bg' => '#bee5eb',
                'text' => '#0c5460',
                'border' => '#17a2b8'
            ], false);
            ?>
            
        </div>
        
        <!-- Detailed reason stats - CLICKABLE TILES -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
            
            <?php
            // Not on list
            $url = "?page=ccs-stats&filter=not_on_list&days=$days&file_id=$file_filter";
            ccs_render_stat_tile($url, '📧 ' . number_format($reason_counts['not_on_list']), 'Nie na liście GR', [
                'bg' => '#fff3cd',
                'active_bg' => '#ffeaa7',
                'text' => '#856404',
                'border' => '#ffc107'
            ], $filter === 'not_on_list');
            
            // Not confirmed
            $url = "?page=ccs-stats&filter=not_confirmed&days=$days&file_id=$file_filter";
            ccs_render_stat_tile($url, '⏳ ' . number_format($reason_counts['not_confirmed']), 'Nie potwierdzili', [
                'bg' => '#ffeaa7',
                'active_bg' => '#fdcb6e',
                'text' => '#d35400',
                'border' => '#f39c12'
            ], $filter === 'not_confirmed');
            
            // Rate limited
            $url = "?page=ccs-stats&filter=rate_limit&days=$days&file_id=$file_filter";
            ccs_render_stat_tile($url, '🚫 ' . number_format($reason_counts['rate_limit']), 'Rate limited', [
                'bg' => '#ffd7d7',
                'active_bg' => '#fab1a0',
                'text' => '#c0392b',
                'border' => '#e74c3c'
            ], $filter === 'rate_limit');
            
            // API errors
            $url = "?page=ccs-stats&filter=api_error&days=$days&file_id=$file_filter";
            ccs_render_stat_tile($url, '⚠️ ' . number_format($reason_counts['api_error']), 'Błędy API', [
                'bg' => '#ecf0f1',
                'active_bg' => '#dfe6e9',
                'text' => '#7f8c8d',
                'border' => '#95a5a6'
            ], $filter === 'api_error');
            ?>
            
        </div>
        
        <!-- Detailed Table -->
        <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow-x: auto;">
            <h2>Szczegóły Pobrań</h2>
            
            <?php
            // Get detailed attempts
            $stmt = $ydb->prepare("
                SELECT a.*, f.filename
                FROM " . CCS_TABLE_ATTEMPTS . " a
                LEFT JOIN " . CCS_TABLE_FILES . " f ON a.file_id = f.file_id
                WHERE $where_sql
                ORDER BY a.attempted_at DESC
                LIMIT 1000
            ");
            $stmt->execute($params);
            $attempts = $stmt->fetchAll(PDO::FETCH_OBJ);
            ?>
            
            <table class="wp-list-table widefat fixed striped" style="width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 40px;">Status</th>
                        <th>Email</th>
                        <th>Plik</th>
                        <th>Data</th>
                        <th>IP</th>
                        <th>Powód</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attempts)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: #999;">
                                📭 Brak danych dla wybranych filtrów
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attempts as $attempt): ?>
                            <tr>
                                <td style="text-align: center; font-size: 20px;">
                                    <?php echo $attempt->status == 'success' ? '✅' : '❌'; ?>
                                </td>
                                <td>
                                    <strong><?php echo yourls_esc_html($attempt->email); ?></strong>
                                </td>
                                <td>
                                    <code><?php echo yourls_esc_html($attempt->file_id); ?></code><br>
                                    <small style="color: #666;"><?php echo yourls_esc_html($attempt->filename ?: 'N/A'); ?></small>
                                </td>
                                <td>
                                    <?php echo date('Y-m-d H:i:s', strtotime($attempt->attempted_at)); ?><br>
                                    <small style="color: #999;"><?php echo human_time_diff(strtotime($attempt->attempted_at), current_time()); ?> temu</small>
                                </td>
                                <td>
                                    <small><?php echo yourls_esc_html($attempt->ip_address); ?></small>
                                </td>
                                <td>
                                    <?php if ($attempt->status == 'success'): ?>
                                        <span style="color: #28a745; font-weight: bold;">✓ Sukces</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;">
                                            <?php
                                            $reasons = [
                                                'not_on_list' => '📧 Nie na liście',
                                                'not_confirmed' => '⏳ Nie potwierdzony',
                                                'rate_limit' => '🚫 Rate limit',
                                                'api_error' => '⚠️ Błąd API',
                                                'file_not_found' => '📁 Plik nie istnieje'
                                            ];
                                            echo $reasons[$attempt->failure_reason] ?? yourls_esc_html($attempt->failure_reason);
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if (count($attempts) >= 1000): ?>
                <p style="color: #856404; margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 4px;">
                    ⚠️ Pokazano pierwsze 1000 wyników. Użyj filtrów aby zawęzić wyniki lub wyeksportuj do CSV.
                </p>
            <?php endif; ?>
        </div>
        
    </div>
    <?php
}