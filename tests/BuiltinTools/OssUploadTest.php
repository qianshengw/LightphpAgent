<?php

declare(strict_types=1);

namespace LightAgent\Tests\BuiltinTools;

use LightAgent\BuiltinTools\OssUploadTool;
use PHPUnit\Framework\TestCase;

/**
 * OssUploadTool tests: parameter validation and error paths only
 * (network upload requires real credentials — not exercised here,
 * matching the Python suite which also tests offline paths).
 */
final class OssUploadTest extends TestCase
{
    public function test_missing_file_reports_error(): void
    {
        $result = OssUploadTool::uploadFileToOss('/nonexistent/file.bin');
        self::assertStringStartsWith('错误：文件', $result);
    }

    public function test_directory_reports_error(): void
    {
        $result = OssUploadTool::uploadFileToOss(sys_get_temp_dir());
        self::assertStringStartsWith('错误：', $result);
    }

    public function test_missing_credentials_report_error(): void
    {
        $file = sys_get_temp_dir() . '/la_oss_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($file, 'payload');
        try {
            // Placeholder credentials from DEFAULT_CONFIG trigger the guard
            putenv('ALIYUN_OSS_ACCESS_KEY_ID=');
            putenv('ALIYUN_OSS_ACCESS_KEY_SECRET=');
            $result = OssUploadTool::uploadFileToOss($file);
            self::assertStringContainsString('未设置阿里云AccessKey', $result);
        } finally {
            @unlink($file);
            putenv('ALIYUN_OSS_ACCESS_KEY_ID');
            putenv('ALIYUN_OSS_ACCESS_KEY_SECRET');
        }
    }

    public function test_tool_info_shape(): void
    {
        $tool = OssUploadTool::create();
        self::assertSame('upload_file_to_oss', $tool->toolInfo['tool_name']);
        self::assertCount(1, $tool->toolInfo['tool_params']);
        self::assertSame('file_path', $tool->toolInfo['tool_params'][0]['name']);
        self::assertTrue($tool->toolInfo['tool_params'][0]['required']);
    }

    public function test_md5_calculation(): void
    {
        $file = sys_get_temp_dir() . '/la_oss_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($file, 'hello');
        try {
            // md5("hello")
            self::assertSame('5d41402abc4b2a76b9719d911017c592', OssUploadTool::calculateMd5($file));
        } finally {
            @unlink($file);
        }
    }
}
