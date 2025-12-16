<?php
if (!defined('YOURLS_ABSPATH')) die();

function ccs_handle_upload() {
    // DEBUG - zapisz logi do pliku
    $log_file = __DIR__ . '/../debug.log';
    $log = function($msg) use ($log_file) {
        file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
    };

    $log("=== UPLOAD START ===");
    $log("POST data: " . print_r($_POST, true));
    $log("FILES data: " . print_r($_FILES, true));

    // Verify nonce
    try {
        yourls_verify_nonce('ccs_upload');
        $log("Nonce verified OK");
    } catch (Exception $e) {
        $log("Nonce verification FAILED: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Błąd weryfikacji nonce'];
    }

    // Validate file upload
    if (!isset($_FILES['file_upload']) || $_FILES['file_upload']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = isset($_FILES['file_upload']) ? 
            'Upload error code: ' . $_FILES['file_upload']['error'] : 
            'No file uploaded';
        $log("File validation FAILED: " . $error_msg);
        return ['status' => 'error', 'message' => 'Błąd uploadu pliku: ' . $error_msg];
    }

    $file = $_FILES['file_upload'];
    $file_title = yourls_sanitize_string($_POST['file_title']);
    $s3_folder = yourls_sanitize_string($_POST['s3_folder']);

    $log("File title: $file_title");
    $log("S3 folder: $s3_folder");
    $log("File size: " . $file['size'] . " bytes");

    // Validate extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, CCS_ALLOWED_EXTENSIONS)) {
        $log("Extension validation FAILED: $extension");
        return ['status' => 'error', 'message' => 'Niedozwolony typ pliku: ' . $extension];
    }
    $log("Extension OK: $extension");

    // Validate size
    if ($file['size'] > CCS_MAX_FILE_SIZE) {
        $log("Size validation FAILED: " . $file['size'] . " > " . CCS_MAX_FILE_SIZE);
        return ['status' => 'error', 'message' => 'Plik jest za duży (max 50MB)'];
    }
    $log("Size OK");

    // Generate file_id
    $file_id = ccs_generate_file_id();
    $log("Generated file_id: $file_id");

    // Sanitize filename
    $original_filename = $file['name'];
    $clean_name = preg_replace('/[^a-zA-Z0-9-_.]/', '-', pathinfo($original_filename, PATHINFO_FILENAME));
    $clean_filename = $clean_name . '.' . $extension;
    $log("Clean filename: $clean_filename");

    // S3 key
    $s3_key = $s3_folder . '/' . $clean_filename;
    $log("S3 key: $s3_key");

    // Check if AWS SDK exists
    $aws_sdk_path = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($aws_sdk_path)) {
        $log("AWS SDK NOT FOUND at: $aws_sdk_path");
        return ['status' => 'error', 'message' => 'AWS SDK nie jest zainstalowany. Uruchom: composer require aws/aws-sdk-php'];
    }
    $log("AWS SDK found");

    // Upload to S3
    $log("Starting S3 upload...");
    $s3_result = ccs_s3_upload($file['tmp_name'], $s3_key, $original_filename, $file['type']);
    $log("S3 upload result: " . print_r($s3_result, true));

    if (!$s3_result['success']) {
        $log("S3 upload FAILED: " . $s3_result['error']);
        return ['status' => 'error', 'message' => 'Błąd S3: ' . $s3_result['error']];
    }
    $log("S3 upload SUCCESS");

    // Save to database
    $log("Saving to database...");
    global $ydb;

    try {
        $query = "INSERT INTO " . CCS_TABLE_FILES . " 
                  (file_id, s3_key, filename, title, file_size, created_at, active) 
                  VALUES (:file_id, :s3_key, :filename, :title, :size, NOW(), 1)";

        $stmt = $ydb->prepare($query);
        $result = $stmt->execute([
            ':file_id' => $file_id,
            ':s3_key' => $s3_key,
            ':filename' => $original_filename,
            ':title' => $file_title,
            ':size' => $file['size']
        ]);

        $log("Database save result: " . ($result ? "SUCCESS" : "FAILED"));

        if (!$result) {
            $log("DB Error: " . print_r($stmt->errorInfo(), true));
            throw new Exception("Database insert failed");
        }

    } catch (Exception $e) {
        $log("Database exception: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Błąd zapisu do bazy: ' . $e->getMessage()];
    }

    // Generate GetResponse link
    $gr_link = sprintf(CCS_LINK_TEMPLATE, $file_id);

    $log("Generated GR link: $gr_link");

    $log("=== UPLOAD SUCCESS ===");

    return [
        'status' => 'success',
        'file_id' => $file_id,
        'gr_link' => $gr_link,
        's3_url' => $s3_result['object_url']
    ];
}

/**
 * Delete file and all related statistics
 */
function ccs_delete_file($file_id) {
    global $ydb;

    ccs_debug_log("Delete file START", ['file_id' => $file_id]);
    
    // Get file data
    $file = ccs_get_file_by_id($file_id);
    if (!$file) {
        ccs_debug_log("Delete FAILED - file not found", ['file_id' => $file_id]);
        return false;
    }

    ccs_debug_log("File found", [
        'file_id' => $file->file_id,
        's3_key' => $file->s3_key,
        'filename' => $file->filename
    ]);

    try {
        // KROK 1: Usuń wszystkie statystyki tego pliku z tabeli attempts
        ccs_debug_log("Deleting attempts for file", ['file_id' => $file_id]);
        
        $stmt_attempts = $ydb->prepare("DELETE FROM " . CCS_TABLE_ATTEMPTS . " WHERE file_id = ?");
        $attempts_deleted = $stmt_attempts->execute([$file_id]);
        $attempts_count = $stmt_attempts->rowCount();
        
        ccs_debug_log("Attempts deleted", [
            'count' => $attempts_count,
            'success' => $attempts_deleted
        ]);

        // KROK 2: Usuń plik z S3
        ccs_debug_log("Deleting from S3", ['s3_key' => $file->s3_key]);
        $s3_deleted = ccs_s3_delete($file->s3_key);
        
        ccs_debug_log("S3 delete result", ['success' => $s3_deleted]);

        // KROK 3: Usuń rekord z tabeli files
        ccs_debug_log("Deleting from database", ['file_id' => $file_id]);
        
        $stmt_files = $ydb->prepare("DELETE FROM " . CCS_TABLE_FILES . " WHERE file_id = ?");
        $files_deleted = $stmt_files->execute([$file_id]);
        
        ccs_debug_log("Database delete result", ['success' => $files_deleted]);

        if ($files_deleted) {
            ccs_debug_log("Delete SUCCESS", [
                'file_id' => $file_id,
                'attempts_removed' => $attempts_count,
                's3_deleted' => $s3_deleted
            ]);
            return true;
        } else {
            ccs_debug_log("Delete FAILED - database error", ['file_id' => $file_id]);
            return false;
        }

    } catch (Exception $e) {
        ccs_debug_log("Delete EXCEPTION", [
            'file_id' => $file_id,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}