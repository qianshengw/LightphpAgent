<?php

declare(strict_types=1);

namespace LightAgent\BuiltinTools;

use LightAgent\Support\Json;
use LightAgent\Tools\ToolFunction;

/**
 * Aliyun OSS upload tool (port of builtin_tools/nos.py).
 *
 * PORTING-DIFF: Python uses boto3's S3-compatible client; the PHP port
 * implements the S3 PUT with AWS SigV4 signing over ext-curl (no SDK
 * dependency).
 *
 * SECURITY (M6): the endpoint, the bucket and the credentials are NOT
 * tool arguments. They come from the ALIYUN_OSS_* environment variables
 * only. The OpenAI schema declares `file_path` alone, but the callable
 * used to accept eight parameters — and the dispatcher binds arguments by
 * NAME, so a model-supplied `endpoint` silently redirected the upload to
 * an attacker host, carrying a real SigV4 Authorization header with the
 * access-key id. Every argument outside the declared schema is now
 * ignored, and the configuration is server-side only.
 */
final class OssUploadTool
{
    /**
     * Maximum size of a file that may be uploaded (bytes).
     *
     * M9: the body used to be read into memory with file_get_contents()
     * and handed to curl as a string — the file was resident in PHP AND
     * copied into curl. A large file therefore exhausted the memory
     * limit. The body is now streamed and the size is capped.
     */
    public const MAX_UPLOAD_BYTES = 32 * 1024 * 1024;

    private const DEFAULT_CONFIG = [
        'access_key_id' => '**********',
        'access_key_secret' => '*****************',
        'endpoint' => 'https://oss-cn-shanghai.aliyuncs.com',
        'bucket_name' => 'wxuserfile',
        'region' => 'oss-cn-shanghai',
    ];

    private function __construct()
    {
    }

    public static function create(): ToolFunction
    {
        // Only `file_path` is bindable: an undeclared model argument
        // (endpoint, bucket, credentials, ...) has no parameter to bind to
        // and is therefore ignored.
        return new ToolFunction(
            callable: static fn (string $file_path): string => self::uploadFileToOss($file_path),
            toolInfo: [
                'tool_name' => 'upload_file_to_oss',
                'tool_title' => '上传文件到阿里云OSS',
                'tool_description' => '将本地文件上传到阿里云OSS，自动使用MD5重命名文件，支持设置公共读权限',
                'tool_params' => [
                    ['name' => 'file_path', 'description' => '要上传的本地文件路径', 'type' => 'string', 'required' => true],
                ],
            ],
        );
    }

    public static function calculateMd5(string $filePath): string
    {
        return md5_file($filePath) ?: '';
    }

    /**
     * Upload a local file to Aliyun OSS.
     *
     * The optional parameters are kept for backwards compatibility of
     * this public API and are IGNORED: the endpoint, bucket and
     * credentials come from the environment, and the object name, prefix
     * and ACL are server-side configuration as well (M6). A model can
     * only choose WHICH file is uploaded.
     *
     * @param string|null $bucketName ignored — use ALIYUN_OSS_BUCKET
     * @param string|null $accessKeyId ignored — use ALIYUN_OSS_ACCESS_KEY_ID
     * @param string|null $accessKeySecret ignored — use ALIYUN_OSS_ACCESS_KEY_SECRET
     * @param string|null $endpoint ignored — use ALIYUN_OSS_ENDPOINT
     * @param bool $preserveOriginalName ignored — use ALIYUN_OSS_PRESERVE_ORIGINAL_NAME
     * @param string $prefix ignored — use ALIYUN_OSS_PREFIX
     * @param bool $publicRead ignored — use ALIYUN_OSS_PUBLIC_READ
     */
    public static function uploadFileToOss(
        string $filePath,
        ?string $bucketName = null,
        ?string $accessKeyId = null,
        ?string $accessKeySecret = null,
        ?string $endpoint = null,
        bool $preserveOriginalName = false,
        string $prefix = '',
        bool $publicRead = false,
    ): string {
        try {
            if (!file_exists($filePath)) {
                return "错误：文件 '{$filePath}' 不存在";
            }
            if (!is_file($filePath)) {
                return "错误：'{$filePath}' 不是文件";
            }

            // M9: reject an oversized file BEFORE reading it.
            $size = (int) filesize($filePath);
            if ($size > self::MAX_UPLOAD_BYTES) {
                return '错误：文件超过上传大小限制（' . self::MAX_UPLOAD_BYTES . ' 字节）';
            }

            // Configuration comes from the environment only (M6).
            $bucket = self::envValue('ALIYUN_OSS_BUCKET', self::DEFAULT_CONFIG['bucket_name']);
            $ak = self::envValue('ALIYUN_OSS_ACCESS_KEY_ID', self::DEFAULT_CONFIG['access_key_id']);
            $sk = self::envValue('ALIYUN_OSS_ACCESS_KEY_SECRET', self::DEFAULT_CONFIG['access_key_secret']);
            $ep = self::envValue('ALIYUN_OSS_ENDPOINT', self::DEFAULT_CONFIG['endpoint']);
            $preserveName = self::envFlag('ALIYUN_OSS_PRESERVE_ORIGINAL_NAME');
            $keyPrefix = self::envValue('ALIYUN_OSS_PREFIX', '');
            $isPublic = self::envFlag('ALIYUN_OSS_PUBLIC_READ');

            $originalFilename = basename($filePath);
            $fileExt = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $fileExt = $fileExt !== '' ? '.' . $fileExt : '';

            if ($preserveName) {
                $ossFilename = $originalFilename;
            } else {
                $fileMd5 = self::calculateMd5($filePath);
                $ossFilename = "{$fileMd5}{$fileExt}";
            }

            if ($keyPrefix !== '' && !str_ends_with($keyPrefix, '/')) {
                $keyPrefix .= '/';
            }
            $ossKey = $keyPrefix !== '' ? "{$keyPrefix}{$ossFilename}" : $ossFilename;

            if ($ak === '' || $sk === '' || str_starts_with((string) $ak, '****')) {
                return '错误：未设置阿里云AccessKey，请通过参数传入或设置环境变量ALIYUN_OSS_ACCESS_KEY_ID和ALIYUN_OSS_ACCESS_KEY_SECRET';
            }

            $host = self::bucketHost((string) $ep, (string) $bucket);
            // M7: the region is part of the SigV4 credential scope; it
            // must follow the endpoint instead of being hard-coded.
            $region = self::resolveRegion((string) $ep, (string) $bucket);

            // M9: the body is hashed and uploaded as a STREAM — the file
            // never has to fit into memory.
            $payloadHash = hash_file('sha256', $filePath);
            if ($payloadHash === false) {
                return '错误：无法读取文件内容';
            }

            self::sigV4Put(
                $host,
                $ossKey,
                $filePath,
                $size,
                $payloadHash,
                (string) $ak,
                (string) $sk,
                $region,
                $isPublic,
            );

            $endpointClean = (string) $ep;
            if (str_starts_with($endpointClean, 'http://') || str_starts_with($endpointClean, 'https://')) {
                $baseUrl = rtrim($endpointClean, '/');
            } else {
                $baseUrl = "https://{$endpointClean}";
            }
            $fileUrl = "{$baseUrl}/{$bucket}/{$ossKey}";

            $result = [
                'success' => true,
                'file_url' => $fileUrl,
                'bucket' => $bucket,
                'key' => $ossKey,
                'original_filename' => $originalFilename,
                'oss_filename' => $ossFilename,
                'file_size' => $size,
                'md5' => $preserveName ? null : self::calculateMd5($filePath),
            ];
            return Json::encodeOrError($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            return '上传文件到OSS失败：' . $e->getMessage();
        }
    }

    /**
     * Read an environment variable, falling back to a default.
     */
    private static function envValue(string $name, string $default): string
    {
        $value = getenv($name);
        return (is_string($value) && $value !== '') ? $value : $default;
    }

    /**
     * Read a boolean environment flag ("1", "true", "yes", "on").
     */
    private static function envFlag(string $name): bool
    {
        $value = getenv($name);
        if (!is_string($value)) {
            return false;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function bucketHost(string $endpoint, string $bucket): string
    {
        $host = preg_replace('#^https?://#', '', $endpoint) ?? $endpoint;
        $host = rtrim($host, '/');
        return "{$bucket}.{$host}";
    }

    /**
     * Derive the signing region from the endpoint (M7).
     *
     * `oss-cn-beijing.aliyuncs.com` -> `oss-cn-beijing`. An explicit
     * ALIYUN_OSS_REGION wins; the hard-coded default is the fallback.
     */
    private static function resolveRegion(string $endpoint, string $bucket): string
    {
        $explicit = getenv('ALIYUN_OSS_REGION');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }
        $host = preg_replace('#^https?://#', '', $endpoint) ?? $endpoint;
        $host = rtrim($host, '/');
        $bucketPrefix = $bucket . '.';
        if (str_starts_with($host, $bucketPrefix)) {
            $host = substr($host, strlen($bucketPrefix));
        }
        foreach (explode('.', $host) as $label) {
            if (preg_match('/^oss-[a-z0-9-]+$/', $label) === 1) {
                return $label;
            }
        }
        return self::DEFAULT_CONFIG['region'];
    }

    /**
     * Build the SigV4 canonical request and its SignedHeaders list.
 *
 * The headers are sorted alphabetically, as the specification requires,
 * and every header that is sent must appear in the list.
 *
 * @param array<string, string> $canonicalHeaders
 * @return array{0: string, 1: string} canonical request, SignedHeaders
 */
    private static function buildCanonicalRequest(
        string $method,
        string $canonicalUri,
        array $canonicalHeaders,
        string $payloadHash,
    ): array {
        ksort($canonicalHeaders);
        $canonicalHeaderLines = [];
        foreach ($canonicalHeaders as $name => $value) {
            $canonicalHeaderLines[] = $name . ':' . $value;
        }
        $signedHeaders = implode(';', array_keys($canonicalHeaders));

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            '',
            implode("\n", $canonicalHeaderLines),
            '',
            $signedHeaders,
            $payloadHash,
        ]);

        return [$canonicalRequest, $signedHeaders];
    }

    /**
     * Minimal AWS SigV4 PUT for S3-compatible object storage (OSS).
     *
     * The body is streamed from disk (M9) — only its SHA-256 digest is
     * needed up front for the signature.
     */
    private static function sigV4Put(
        string $host,
        string $key,
        string $filePath,
        int $size,
        string $payloadHash,
        string $accessKey,
        string $secretKey,
        string $region,
        bool $publicRead,
    ): void {
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $service = 's3';

        $canonicalUri = '/' . str_replace('%2F', '/', rawurlencode($key));

        // M8: every `x-oss-*` header that is SENT must also be part of
        // the canonical request — Aliyun OSS V4 rejects a request whose
        // SignedHeaders do not cover the ACL header.
        $canonicalHeaders = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        if ($publicRead) {
            $canonicalHeaders['x-oss-object-acl'] = 'public-read';
        }
        [$canonicalRequest, $signedHeaders] = self::buildCanonicalRequest('PUT', $canonicalUri, $canonicalHeaders, $payloadHash);

        $scope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, "AWS4{$secretKey}", true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, "
            . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('cannot open the file for upload');
        }

        try {
            $ch = curl_init("https://{$host}{$canonicalUri}");
            if ($ch === false) {
                throw new \RuntimeException('curl_init failed');
            }
            $headers = [
                'Authorization: ' . $authorization,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $amzDate,
                'Content-Type: application/octet-stream',
                'Content-Length: ' . $size,
                // Some object stores reject the 100-continue handshake.
                'Expect:',
            ];
            if ($publicRead) {
                $headers[] = 'x-oss-object-acl: public-read';
            }
            curl_setopt_array($ch, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILESIZE => $size,
                CURLOPT_READFUNCTION => static function ($ch, $fd) use ($handle): string {
                    return (string) fread($handle, 65536);
                },
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
            ]);
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);

            if ($response === false) {
                throw new \RuntimeException("OSS request failed: {$error}");
            }
            if ($status >= 400) {
                $body = is_string($response) ? substr($response, 0, 300) : '';
                throw new \RuntimeException("OSS HTTP {$status}: {$body}");
            }
        } finally {
            fclose($handle);
        }
    }
}
