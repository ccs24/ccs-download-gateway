<?php
if (!defined('YOURLS_ABSPATH')) die();
//error_reporting(E_ALL);
//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);

/**
 * CCS Download Gateway - Statistics Page
 * Wersja używająca bezpośrednio PDO (bez YOURLS get_results)
 */

/**
 * Helper functions for YOURLS compatibility
 */
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
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    $file_filter = isset($_GET['file_id']) ? yourls_sanitize_string($_GET['file_id']) : '';
    $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
    
    // Build query
    $where = ["1=1"];
    $params = [];
    
    if ($filter === 'success') {
        $where[] = "success = 1";
    } elseif ($filter === 'failed') {
        $where[] = "success = 0";
    } elseif ($filter === 'not_on_list') {
        $where[] = "success = 0 AND reason = 'not_on_list'";
    } elseif ($filter === 'not_confirmed') {
        $where[] = "success = 0 AND reason = 'not_confirmed'";
    } elseif ($filter === 'rate_limit') {
        $where[] = "success = 0 AND reason = 'rate_limit'";
    } elseif ($filter === 'api_error') {
        $where[] = "success = 0 AND reason = 'api_error'";
    }
    
    if ($days > 0) {
        $where[] = "attempted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params[] = $days;
    }
    
    if ($file_filter) {
        $where[] = "file_id = ?";
        $params[] = $file_filter;
    }
    
    $where_sql = implode(" AND ", $where);
    
    // Get data using PDO
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
            $attempt['success'] ? 'Sukces' : 'Niepowodzenie',
            $attempt['reason'] ?: '',
            $attempt['details'] ?: ''
        ], ';');
    }
    
    fclose($output);
}

/**
 * Main statistics page render
 */
function ccs_render_stats_page() {
    global $ydb;
    
    // Get filters from URL
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
    $file_filter = isset($_GET['file_id']) ? yourls_sanitize_string($_GET['file_id']) : '';
    $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
    
    ?>
    <div class="wrap">
        <h1>📊 Statystyki Pobrań</h1>
_        <!-- Breadcrumbs -->
        <div style="margin: 20px 0; padding: 10px; background: #f8f9fa; border-radius: 4px;">
            <a href="?page=ccs-stats" style="text-decoration: none;">📊 Wszystkie statystyki</a>
            <?php if ($filter !== 'all'): ?>
                → <strong><?php 
                $filter_names = [
                    'success' => '✅ Pobrane pomyślnie',
                    'failed' => '❌ Wszystkie odrzucone',
                    'not_on_list' => '📧 Nie na liście GetResponse',
                    'not_confirmed' => '⏳ Nie potwierdzili zapisu',
                    'rate_limit' => '🚫 Rate limited',
                    'api_error' => '⚠️ Błędy API'
                ];
                echo $filter_names[$filter] ?? $filter;
                ?></strong>
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
                        <option value="all" <?php echo ($filter === 'all') ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="success" <?php echo ($filter === 'success') ? 'selected' : ''; ?>>✅ Pobrane pomyślnie</option>
                        <option value="failed" <?php echo ($filter === 'failed') ? 'selected' : ''; ?>>❌ Wszystkie odrzucone</option>
                        <option value="not_on_list" <?php echo ($filter === 'not_on_list') ? 'selected' : ''; ?>>📧 Nie na liście</option>
                        <option value="not_confirmed" <?php echo ($filter === 'not_confirmed') ? 'selected' : ''; ?>>⏳ Nie potwierdzeni</option>
                        <option value="rate_limit" <?php echo ($filter === 'rate_limit') ? 'selected' : ''; ?>>🚫 Rate limited</option>
                        <option value="api_error" <?php echo ($filter === 'api_error') ? 'selected' : ''; ?>>⚠️ Błędy API</option>
                    </select>
                </label>
                
                <label style="margin-left: 20px;">
                    <strong>Okres:</strong>
                    <select name="days" onchange="this.form.submit()">
                        <option value="7" <?php echo ($days === 7) ? 'selected' : ''; ?>>Ostatnie 7 dni</option>
                        <option value="30" <?php echo ($days === 30) ? 'selected' : ''; ?>>Ostatnie 30 dni</option>
                        <option value="90" <?php echo ($days === 90) ? 'selected' : ''; ?>>Ostatnie 90 dni</option>
                        <option value="365" <?php echo ($days === 365) ? 'selected' : ''; ?>>Ostatni rok</option>
                        <option value="0" <?php echo ($days === 0) ? 'selected' : ''; ?>>Wszystkie</option>
                    </select>
                </label>
                
                <label style="margin-left: 20px;">
                    <strong>Plik:</strong>
                    <select name="file_id" onchange="this.form.submit()">
                        <option value="">Wszystkie pliki</option>
                        <?php
                        // PDO: Get files
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
                            echo '<option value="">Błąd: ' . $e->getMessage() . '</option>';
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
        
        <?php
        // Build query
        $where = ["1=1"];
        $params = [];
        
        if ($filter === 'success') {
            $where[] = "status = 'success'";
        } elseif ($filter === 'failed') {
            $where[] = "status = 'failed'";
        } elseif ($filter === 'not_on_list') {
            $where[] = "status = 'failed' AND failure_reason = 'not_on_list'";
        } elseif ($filter === 'not_confirmed') {
            $where[] = "status = 'failed' AND failure_reason = 'not_confirmed'";
        } elseif ($filter === 'rate_limit') {
            $where[] = "status = 'failed' AND failure_reason = 'rate_limit'";
        } elseif ($filter === 'api_error') {
            $where[] = "status = 'failed' AND failure_reason = 'api_error'";
        }
        
        if ($days > 0) {
            $where[] = "attempted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params[] = $days;
        }
        
        if ($file_filter) {
            $where[] = "file_id = ?";
            $params[] = $file_filter;
        }
        
        $where_sql = implode(" AND ", $where);
        
        // PDO: Get main stats
        $stmt = $ydb->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(status = 'success') as successful,
                SUM(status = 'failed') as failed,
                COUNT(DISTINCT email) as unique_emails
            FROM " . CCS_TABLE_ATTEMPTS . "
            WHERE $where_sql
        ");
        $stmt->execute($params);
        $stats = $stmt->fetch(PDO::FETCH_OBJ);
        
        // PDO: Get detailed stats for each reason
        $reasons_to_count = ['not_on_list', 'not_confirmed', 'rate_limit', 'api_error'];
        $reason_counts = [];
        
        foreach ($reasons_to_count as $reason) {
            $reason_where = ["failure_reason = ?"];
            $reason_params = [$reason];
            
            if ($days > 0) {
                $reason_where[] = "attempted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
                $reason_params[] = $days;
            }
            
            if ($file_filter) {
                $reason_where[] = "file_id = ?";
                $reason_params[] = $file_filter;
            }
            
            $reason_where_sql = implode(" AND ", $reason_where);
            
            $stmt = $ydb->prepare("SELECT COUNT(*) FROM " . CCS_TABLE_ATTEMPTS . " WHERE $reason_where_sql");
            $stmt->execute($reason_params);
            $reason_counts[$reason] = $stmt->fetchColumn();
        }
        ?>
        
        <!-- Main Stats - CLICKABLE TILES -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
            
            <!-- All attempts -->
            <a href="?page=ccs-stats&filter=all&days=<?php echo $days; ?>&file_id=<?php echo $file_filter; ?>" 
               style="background: <?php echo $filter === 'all' ? '#bee5eb' : '#e7f3ff'; ?>; padding: 20px; border-radius: 8px; text-align: center; text-decoration: none; transition: transform 0.2s; display: block; <?php echo $filter === 'all' ? 'box-shadow: 0 0 0 3px #17a2b8;' : ''; ?>"
               onmouseover="this.style.transform='scale(1.05)'" 
               onmouseout="this.style.transform='scale(1)'"
               title="Kliknij aby zobaczyć wszystkie próby pobrania">
                <div style="font-size: 32px; font-weight: bold; color: #0066cc;"><?php echo number_format($stats->total); ?></div>
                <div style="color: #004080; font-weight: <?php echo $filter === 'all' ? 'bold' : 'normal'; ?>;">📊 Wszystkie próby</div>
            </a>
            
            <!-- Successful -->
            <a href="?page=ccs-stats&filter=success&days=<?php echo $days; ?>&file_id=<?php echo $file_filter; ?>" 
               style="background: <?php echo $filter === 'success' ? '#c3e6cb' : '#d4edda'; ?>; padding: 20px; border-radius: 8px; text-align: center; text-decoration: none; transition: transform 0.2s; display: block; <?php echo $filter === 'success' ? 'box-shadow: 0 0 0 3px #28a745;' : ''; ?>"
               onmouseover="this.style.transform='scale(1.05)'" 
               onmouseout="this.style.transform='scale(1)'"
               title="Kliknij aby zobaczyć pomyślne pobrania">
                <div style="font-size: 32px; font-weight: bold; color: #28a745;">✅ <?php echo number_format($stats->successful); ?></div>
                <div style="color: #155724; font-weight: <?php echo $filter === 'success' ? 'bold' : 'normal'; ?>;">Pobrane pomyślnie</div>
            </a>
            
            <!-- Failed -->
            <a href="?page=ccs-stats&filter=failed&days=<?php echo $days; ?>&file_id=<?php echo $file_filter; ?>" 
               style="background: <?php echo $filter === 'failed' ? '#f1b0b7' : '#f8d7da'; ?>; padding: 20px; border-radius: 8px; text-align: center; text-decoration: none; transition: transform 0.2s; display: block; <?php echo $filter === 'failed' ? 'box-shadow: 0 0 0 3px #dc3545;' : ''; ?>"
               onmouseover="this.style.transform='scale(1.05)'" 
               onmouseout="this.style.transform='scale(1)'"
               title="Kliknij aby zobaczyć odrzucone próby">
                <div style="font-size: 32px; font-weight: bold; color: #dc3545;">❌ <?php echo number_format($stats->failed); ?></div>
                <div style="color: #721c24; font-weight: <?php echo $filter === 'failed' ? 'bold' : 'normal'; ?>;">Odrzucone</div>
            </a>
            
            <!-- Unique emails -->
            <a href="?page=ccs-stats&filter=all&days=<?php echo $days; ?>&file_id=<?php echo $file_filter; ?>" 
               style="background: #d1ecf1; padding: 20px; border-radius: 8px; text-align: center; text-decoration: none; transition: transform 0.2s; display: block;"
               onmouseover="this.style.transform='scale(1.05)'" 
               onmouseout="this.style.transform='scale(1)'"
               title="Unikalne adresy email które próbowały pobrać">
                <div style="font-size: 32px; font-weight: bold; color: #0c5460;">👥 <?php echo number_format($stats->unique_emails); ?></div>
                <div style="color: #0c5460;">Unikalne emaile</div>
            </a>
            
        </div>
        
        <!-- Detailed reason stats - CLICKABLE TILES -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
            
            <!-- Not on list -->
            <a href="?page=ccs-stats&filter=not_on_list&days=<?php echo $days; ?>&file_id=<?php echo $file_filter; ?>" 
               style="background: <?php echo $filter === 'not_on_list' ? '#ffeaa7' : '#fff3cd'; ?>; padding: 20px; border-radius: 8px; text-align: center; text-decoration: none; transition: transform 0.2s; display: block; <?php echo $filter === 'not_on_list' ? 'box-shadow: 0 0 0 3px #ffc107;' : ''; ?>"
               onmouseover="this.style.transform='scale(1.05)'" 
               onmouseout="this.style.transform='scale(1)'"
               title="Emaile które nie są na liście GetResponse">
                <div style="font-size: 32px; font-weight: bold; color: #856404;">📧 <?php echo number_format($reason_counts['not_on_list']); ?></div>
                <div style="color: #856404; font-weight: <?php echo $filter === 'not_on_list' ? 'bold' : 'normal'; ?>;">Nie na liście GR</div>
            </a>
            
            <!-- Not confirmed -->
            <a href="?page=ccs-stats&filter=not_confirmed&days=<?php echo $days; ?>&file_id=<?php echo $file_filter; ?>" 
               style="background: <?php echo $filter === 'not_confirmed' ? '#fdcb6e' : '#ffeaa7'; ?>; padding: 20px; border-radius: 8px; text-align: center; text-decoration: none; transition: transform 0.2s; display: block; <?php echo $filter === 'not_confirmed' ? 'box-shadow: 0 0 0 3px #f39c12;' : ''; ?>"
               onmouseover="this.style.transform='scale(1.05)'" 
               onmouseout="this.style.transform='scale(1)'"
               title="Emaile które nie potwierdziły zapisu (double opt-in)">
                <div style="font-size: 32px; font-weight: bold; color: #d35400;">⏳ <?php echo number_format($reason_counts['not_confirmed']); ?></div>
                <div style="color: #d35400; font-weight: <?php echo $filter === 'not_confirmed' ? 'bold' : 'normal'; ?>;">Nie potwierdzili</div>
            </a>
            
            <!-- Rate limited -->
            <a href="?page=ccs-stats&filter=rate_limit&days=<?php echo $days; ?>&file_id=<?php echo $file_filter; ?>" 
               style="background: <?php echo $filter === 'rate_limit' ? '#fab1a0' : '#ffd7d7'; ?>; padding: 20px; border-radius: 8px; text-align: center; text-decoration: none; transition: transform 0.2s; display: block; <?php echo $filter === 'rate_limit' ? 'box-shadow: 0 0 0 3px #e74c3c;' : ''; ?>"
               onmouseover="this.style.transform='scale(1.05)'" 
               onmouseout="this.style.transform='scale(1)'"
               title="Próby pobrania zablokowane przez rate limiting">
                <div style="font-size: 32px; font-weight: bold; color: #c0392b;">🚫 <?php echo number_format($reason_counts['rate_limit']); ?></div>
                <div style="color: #c0392b; font-weight: <?php echo $filter === 'rate_limit' ? 'bold' : 'normal'; ?>;">Rate limited</div>
            </a>
            
            <!-- API errors -->
            <a href="?page=ccs-stats&filter=api_error&days=<?php echo $days; ?>&file_id=<?php echo $file_filter; ?>" 
               style="background: <?php echo $filter === 'api_error' ? '#dfe6e9' : '#ecf0f1'; ?>; padding: 20px; border-radius: 8px; text-align: center; text-decoration: none; transition: transform 0.2s; display: block; <?php echo $filter === 'api_error' ? 'box-shadow: 0 0 0 3px #95a5a6;' : ''; ?>"
               onmouseover="this.style.transform='scale(1.05)'" 
               onmouseout="this.style.transform='scale(1)'"
               title="Błędy komunikacji z GetResponse API">
                <div style="font-size: 32px; font-weight: bold; color: #7f8c8d;">⚠️ <?php echo number_format($reason_counts['api_error']); ?></div>
                <div style="color: #7f8c8d; font-weight: <?php echo $filter === 'api_error' ? 'bold' : 'normal'; ?>;">Błędy API</div>
            </a>
            
        </div>
        
        <!-- Detailed Table -->
        <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow-x: auto;">
            <h2>Szczegóły Pobrań</h2>
            
            <?php
            // PDO: Get detailed attempts
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
                                    <?php echo $attempt->status ? '✅' : '❌'; ?>
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
                                    <?php if ($attempt->status == 'success' ): ?>
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
                                            echo $reasons[$attempt->failure_reason] ?? yourls_esc_html($attempt->reason);
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
