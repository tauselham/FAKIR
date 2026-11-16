<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Server-side Supabase Storage client using PHP cURL.
 * Validates MIME types via finfo and enforces file size limits before upload.
 */
final class SupabaseStorage
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) env_value('SUPABASE_URL', ''), '/');
        $this->apiKey  = (string) env_value('SUPABASE_SECRET_KEY', env_value('SUPABASE_STORAGE_KEY', ''));
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== ''
            && $this->apiKey !== ''
            && !str_contains($this->baseUrl, 'YOUR_PROJECT_REF')
            && !str_starts_with($this->apiKey, 'CHANGE_ME_')
            && !str_contains($this->apiKey, 'YOUR_');
    }

    public function validateFile(array $file, array $allowedMimes, int $maxBytes): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Invalid upload source.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            $maxMb = round($maxBytes / (1024 * 1024), 1);
            throw new RuntimeException("File exceeds maximum allowed size of {$maxMb} MB.");
        }

        if (!class_exists('finfo')) {
            throw new RuntimeException('PHP fileinfo extension is required to verify uploaded file types.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->file($tmpPath);

        if (!isset($allowedMimes[$detectedMime])) {
            throw new RuntimeException('Unsupported or unsafe file type (' . $detectedMime . ').');
        }

        return [
            'tmp_path' => $tmpPath,
            'mime'     => $detectedMime,
            'ext'      => $allowedMimes[$detectedMime],
            'size'     => $size,
        ];
    }

    /**
     * Validates an uploaded $_FILES entry and pushes it to Supabase Storage.
     *
     * @param array<string, mixed> $file
     * @param array<string, string> $allowedMimes
     * @return array{bucket: string, path: string, url: string, mime: string, size: int}
     */
    public function upload(
        array $file,
        string $bucket,
        array $allowedMimes,
        int $maxBytes,
        string $prefix = ''
    ): array {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Supabase Storage credentials are not configured in .env.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for Supabase Storage uploads.');
        }

        $validated = $this->validateFile($file, $allowedMimes, $maxBytes);
        $tmpPath = $validated['tmp_path'];
        $detectedMime = $validated['mime'];
        $ext = $validated['ext'];
        $size = $validated['size'];

        $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
        $dateFolder = gmdate('Y/m');
        $cleanPrefix = trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix) ?? '', '/');
        $objectPath = ($cleanPrefix !== '' ? $cleanPrefix . '/' : '') . $dateFolder . '/' . $randomName;

        // Supabase Storage's standard upload endpoint expects a raw binary POST body.
        // CURLOPT_UPLOAD is intended for upload/PUT semantics, so use POSTFIELDS here.
        $encodedObjectPath = implode('/', array_map('rawurlencode', explode('/', $objectPath)));
        $endpoint = sprintf(
            '%s/storage/v1/object/%s/%s',
            $this->baseUrl,
            rawurlencode($bucket),
            $encodedObjectPath
        );

        $fileBody = file_get_contents($tmpPath);
        if ($fileBody === false) {
            throw new RuntimeException('Failed to read uploaded file.');
        }

        // New Supabase secret keys (sb_secret_...) are API keys, not JWTs.
        // They must be sent in the apikey header only, never as a Bearer token.
        // Legacy service_role JWT keys still require both headers.
        if (str_starts_with($this->apiKey, 'sb_publishable_')) {
            throw new RuntimeException(
                'SUPABASE_STORAGE_KEY must be a server-side Supabase Secret key or legacy service_role key, not a publishable key.'
            );
        }

        $headers = [
            'apikey: ' . $this->apiKey,
            'Content-Type: ' . $detectedMime,
            'x-upsert: false',
            'Expect:',
        ];
        if (!str_starts_with($this->apiKey, 'sb_secret_')) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize the Storage HTTP request.');
        }

        try {
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $fileBody,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => $headers,
            ]);

            $response = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
        } finally {
            curl_close($ch);
            unset($fileBody);
        }

        if ($response === false || $statusCode < 200 || $statusCode >= 300) {
            // Log the Storage response for local diagnosis, but never log request headers or keys.
            $responseForLog = is_string($response) ? substr($response, 0, 1500) : '';
            error_log("Supabase Storage upload failed ({$statusCode}): {$curlErr}; response={$responseForLog}");
            throw new RuntimeException('Failed to store uploaded file in Supabase Storage. Check php-error.log for the Storage status and reason.');
        }

        return [
            'bucket' => $bucket,
            'path'   => $objectPath,
            'url'    => $this->publicUrl($bucket, $objectPath),
            'mime'   => $detectedMime,
            'size'   => $size,
        ];
    }

    public function delete(string $bucket, string $objectPath): bool
    {
        if (!$this->isConfigured() || $objectPath === '' || str_starts_with($objectPath, 'starter/')) {
            return true;
        }
        if (!function_exists('curl_init')) {
            return false;
        }

        $endpoint = sprintf('%s/storage/v1/object/%s', $this->baseUrl, rawurlencode($bucket));
        $payload = json_encode(['prefixes' => [$objectPath]], JSON_THROW_ON_ERROR);

        // Supabase secret API keys are not JWTs: send them as apikey only.
        // Legacy service_role JWTs require both apikey and Bearer authorization.
        if (str_starts_with($this->apiKey, 'sb_publishable_')) {
            error_log('Supabase Storage delete blocked: a publishable key cannot be used for server-side deletion.');
            return false;
        }

        $headers = [
            'apikey: ' . $this->apiKey,
            'Content-Type: application/json',
        ];
        if (!str_starts_with($this->apiKey, 'sb_secret_')) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $statusCode < 200 || $statusCode >= 300) {
            error_log("Supabase Storage delete warning ({$statusCode}): {$response}");
            return false;
        }

        return true;
    }

    public function publicUrl(string $bucket, string $objectPath): string
    {
        return sprintf(
            '%s/storage/v1/object/public/%s/%s',
            $this->baseUrl,
            rawurlencode($bucket),
            ltrim($objectPath, '/')
        );
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds server upload size limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was selected for upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded file to disk.',
            UPLOAD_ERR_EXTENSION => 'File upload was stopped by a PHP extension.',
            default => 'An unexpected file upload error occurred.',
        };
    }
}
