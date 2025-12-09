<?php
if (!defined('YOURLS_ABSPATH')) die();

require_once __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Get S3 Client
 */
function ccs_get_s3_client() {
    static $client = null;

    if ($client === null) {
        $client = new S3Client([
            'version' => 'latest',
            'region'  => CCS_AWS_S3_REGION,
            'credentials' => [
                'key'    => CCS_AWS_ACCESS_KEY,
                'secret' => CCS_AWS_SECRET_KEY,
            ]
        ]);
    }

    return $client;
}

/**
 * Upload file to S3
 */
function ccs_s3_upload($file_path, $s3_key, $filename, $content_type) {
    try {
        $s3 = ccs_get_s3_client();

        $result = $s3->putObject([
            'Bucket' => CCS_AWS_S3_BUCKET,
            'Key'    => $s3_key,
            'SourceFile' => $file_path,
            'ContentType' => $content_type,
            'ContentDisposition' => 'attachment; filename="' . $filename . '"',
        ]);

        return [
            'success' => true,
            'object_url' => $result['ObjectURL']
        ];

    } catch (AwsException $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Generate presigned URL
 */
function ccs_s3_get_presigned_url($s3_key, $filename, $expires = '+1 hour') {
    try {
        $s3 = ccs_get_s3_client();

        $cmd = $s3->getCommand('GetObject', [
            'Bucket' => CCS_AWS_S3_BUCKET,
            'Key'    => $s3_key,
            'ResponseContentDisposition' => 'attachment; filename="' . $filename . '"'
        ]);

        $request = $s3->createPresignedRequest($cmd, $expires);
        return (string) $request->getUri();

    } catch (AwsException $e) {
        ccs_log_error('S3 Presigned URL Error', $e->getMessage());
        return false;
    }
}

/**
 * Delete file from S3
 */
function ccs_s3_delete($s3_key) {
    try {
        $s3 = ccs_get_s3_client();
        
        $s3->deleteObject([
            'Bucket' => CCS_AWS_S3_BUCKET,
            'Key'    => $s3_key
        ]);
        
        return true;
        
    } catch (AwsException $e) {
        return false;
    }
}


