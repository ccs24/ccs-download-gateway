<?php
if (!defined('YOURLS_ABSPATH')) die();

// Handle upload
$upload_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ccs_upload_file'])) {
    $upload_result = ccs_handle_upload();
}

// Handle delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['file_id'])) {
    $file_id = yourls_sanitize_string($_GET['file_id']);
    ccs_debug_log('Delete file', $file_id);
    $deleted = ccs_delete_file($file_id);
    ccs_debug_log('Deleted file', $deleted);
    if ($deleted) {
        yourls_add_notice('Plik został usunięty pomyślnie');
    } else {
        yourls_add_notice('Błąd podczas usuwania pliku', 'error');
    }
    // Redirect po usunięciu aby uniknąć ponownego wyświetlania komunikatu
    yourls_redirect(yourls_admin_url('plugins.php?page=ccs-files'), 302);
    exit;
}

// Get all files
global $ydb;
$files = $ydb->fetchObjects("SELECT * FROM " . CCS_TABLE_FILES . " ORDER BY created_at DESC");

// Get statistics
$stats = $ydb->fetchObject(
    "SELECT 
        COUNT(DISTINCT f.file_id) as total_files,
        COALESCE(COUNT(a.id), 0) as total_attempts,
        COALESCE(SUM(CASE WHEN a.status = 'success' THEN 1 ELSE 0 END), 0) as successful,
        COALESCE(SUM(CASE WHEN a.status = 'failed' THEN 1 ELSE 0 END), 0) as failed
    FROM " . CCS_TABLE_FILES . " f
    LEFT JOIN " . CCS_TABLE_ATTEMPTS . " a ON f.file_id = a.file_id
    WHERE f.active = 1"
);
?>

<div class="ccs-admin-wrapper">
    <div class="ccs-header">
        <h1>📁 Zarządzanie plikami do pobrania</h1>
        <p>System weryfikacji GetResponse + AWS S3</p>
    </div>
    
    <?php if ($upload_result): ?>
        <div class="notice notice-<?php echo $upload_result['status']; ?>">
            <?php if ($upload_result['status'] === 'success'): ?>
                <h3>✅ Plik został dodany pomyślnie!</h3>
                <p><strong>File ID:</strong> <code><?php echo htmlspecialchars($upload_result['file_id']); ?></code></p>
                <p><strong>File Keyword:</strong> <code><?php echo htmlspecialchars($upload_result['file_keyword']); ?></code></p>
                <p><strong>Link do autorespondera GetResponse:</strong></p>
                <input type="text" class="ccs-link-copy" readonly value="<?php echo htmlspecialchars($upload_result['gr_link']); ?>" 
				onclick="this.select(); document.execCommand('copy');">
                <p><strong>Link DirectAWS:</strong></p>
                <input type="text" class="ccs-link-copy" readonly value="<?php echo htmlspecialchars($upload_result['direct_link']); ?>" 
				onclick="this.select(); document.execCommand('copy');">
                <p><small>Kliknij w pole aby skopiować</small></p>
            <?php else: ?>
                <h3>❌ Błąd uploadu</h3>
                <p><?php echo htmlspecialchars($upload_result['message']); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Statistics -->
    <div class="ccs-stats">
        <div class="stat-card">
            <h3><?php echo $stats->total_files; ?></h3>
            <p>Plików</p>
        </div>
        <div class="stat-card">
            <h3><?php echo $stats->total_attempts; ?></h3>
            <p>Wszystkich prób</p>
        </div>
        <div class="stat-card success">
            <h3><?php echo $stats->successful; ?></h3>
            <p>Udanych</p>
        </div>
        <div class="stat-card error">
            <h3><?php echo $stats->failed; ?></h3>
            <p>Nieudanych</p>
        </div>
    </div>
    
    <!-- Upload Form -->
    <div class="ccs-card">
        <h2>➕ Dodaj nowy plik</h2>
        <form method="post" enctype="multipart/form-data" class="ccs-upload-form">
            <?php yourls_nonce_field('ccs_upload'); ?>
            
            <!-- 1. PLIK -->
            <div class="form-row">
                <label for="file_upload">Wybierz plik * (max 50MB)</label>
                <input type="file" name="file_upload" id="file_upload" required>
                <small>Dozwolone: <?php echo implode(', ', CCS_ALLOWED_EXTENSIONS); ?></small>
            </div>
            
            <!-- 2. FILE KEYWORD -->
            <div class="form-row">
                <label for="file_keyword">File Keyword * (bez spacji, małe litery, np: roi-calculator)</label>
                <input type="text" name="file_keyword" id="file_keyword" required
                       placeholder="np. roi-calculator-b2b"
                       pattern="[a-z0-9-]+"
                       title="Tylko małe litery, cyfry i myślniki (bez spacji)">
                <small>To będzie adres: <code><?php echo CCS_DOWNLOAD_DOMAIN; ?>/++<span id="keyword-preview">twoj-keyword</span></code></small>
            </div>
            
            <!-- 3. FOLDER -->
            <div class="form-row">
                <label for="s3_folder">Folder w S3</label>
                <select name="s3_folder" id="s3_folder">
                    <?php foreach (CCS_S3_FOLDERS as $folder => $label): ?>
                        <option value="<?php echo $folder; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" name="ccs_upload_file" class="button button-primary">
                📤 Upload i dodaj do systemu
            </button>
        </form>
    </div>
    
    <!-- Files List -->
    <div class="ccs-card">
        <h2>📋 Pliki w systemie (<?php echo count($files); ?>)</h2>
        
        <?php if (empty($files)): ?>
            <p class="empty-state">Brak plików. Dodaj pierwszy plik używając formularza powyżej.</p>
        <?php else: ?>
            <table class="ccs-files-table">
                <thead>
                    <tr>
                        <th style="width: 100px;">File ID</th>
                        <th>File Name</th>
                        <th style="width: 80px;">Size</th>
                        <th style="width: 100px;">Folder</th>
                        <th style="width: 200px;">Link GetResponse</th>
                        <th style="width: 200px;">Link DirectAWS</th>
                        <th style="width: 150px;">Akcje</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($files as $file): 
                    $gr_link = sprintf(CCS_LINK_TEMPLATE, $file->file_id);
                    $direct_link = CCS_DOWNLOAD_DOMAIN . '/++' . ($file->file_keyword ?: $file->file_id);
                    $folder = dirname($file->s3_key);
                    $size = $file->file_size > 0 ? round($file->file_size / 1024 / 1024, 2) . ' MB' : 'N/A';
                ?>
                    <tr>
                        <td><code class="file-id"><?php echo htmlspecialchars($file->file_id); ?></code></td>
                        <td><strong><?php echo htmlspecialchars($file->filename); ?></strong></td>
                        <td><?php echo $size; ?></td>
                        <td><span class="badge"><?php echo htmlspecialchars($folder); ?></span></td>
                        <td>
                            <input type="text" class="link-input" readonly 
                                   value="<?php echo htmlspecialchars($gr_link); ?>"
                                   onclick="this.select(); document.execCommand('copy');">
                        </td>
                        <td>
                            <input type="text" class="link-input" readonly 
                                   value="<?php echo htmlspecialchars($direct_link); ?>"
                                   onclick="this.select(); document.execCommand('copy');">
                        </td>
                        <td class="actions">
                            <a href="<?php echo CCS_DOWNLOAD_DOMAIN; ?>/<?php echo $file->file_id; ?>?email=test@test.pl" 
                               target="_blank" class="button-small">Test GR</a>
                            <?php if ($file->file_keyword): ?>
                            <a href="<?php echo CCS_DOWNLOAD_DOMAIN; ?>/++<?php echo $file->file_keyword; ?>" 
                               target="_blank" class="button-small" style="background: #27ae60;">Test AWS</a>
                            <?php endif; ?>
                            <a href="?page=ccs-files&action=delete&file_id=<?php echo $file->file_id; ?>" 
                               class="button-small button-danger"
                               onclick="return confirm('Czy na pewno usunąć: <?php echo htmlspecialchars($file->filename); ?>?')">
                                Usuń
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
// Live preview keyword
document.getElementById('file_keyword').addEventListener('input', function(e) {
    let keyword = e.target.value.toLowerCase()
        .replace(/\s+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .replace(/-+/g, '-');
    e.target.value = keyword;
    document.getElementById('keyword-preview').textContent = keyword || 'twoj-keyword';
});
</script>