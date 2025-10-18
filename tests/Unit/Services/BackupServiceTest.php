<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\BackupService;
use SecurityScanner\Core\Database;

class BackupServiceTest extends TestCase
{
    private BackupService $backupService;
    

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupService = new BackupService();
        
    }

    public function test_create_database_backup(): void
    {
        $result = $this->backupService->createDatabaseBackup();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('backup_file', $result);
        $this->assertArrayHasKey('backup_size', $result);
        $this->assertFileExists($result['backup_file']);

        // Cleanup
        if (file_exists($result['backup_file'])) {
            unlink($result['backup_file']);
        }
    }

    public function test_create_backup_with_custom_name(): void
    {
        $customName = 'test_backup_' . date('Y-m-d');

        $result = $this->backupService->createDatabaseBackup(['name' => $customName]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString($customName, $result['backup_file']);

        // Cleanup
        if (file_exists($result['backup_file'])) {
            unlink($result['backup_file']);
        }
    }

    public function test_list_backups(): void
    {
        // Create a test backup first
        $this->backupService->createDatabaseBackup();

        $backups = $this->backupService->listBackups();

        $this->assertIsArray($backups);
        $this->assertGreaterThan(0, count($backups));

        foreach ($backups as $backup) {
            $this->assertArrayHasKeys(['filename', 'size', 'created_at'], $backup);
        }

        // Cleanup
        foreach ($backups as $backup) {
            if (file_exists($backup['full_path'])) {
                unlink($backup['full_path']);
            }
        }
    }

    public function test_restore_database_backup(): void
    {
        // Create a backup first
        $backupResult = $this->backupService->createDatabaseBackup();
        $backupFile = $backupResult['backup_file'];

        $result = $this->backupService->restoreDatabaseBackup($backupFile);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('restored_tables', $result);

        // Cleanup
        if (file_exists($backupFile)) {
            unlink($backupFile);
        }
    }

    public function test_restore_nonexistent_backup_fails(): void
    {
        $result = $this->backupService->restoreDatabaseBackup('/nonexistent/backup.sql');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function test_delete_backup(): void
    {
        // Create a backup first
        $backupResult = $this->backupService->createDatabaseBackup();
        $backupFile = $backupResult['backup_file'];

        $result = $this->backupService->deleteBackup(basename($backupFile));

        $this->assertTrue($result['success']);
        $this->assertFileDoesNotExist($backupFile);
    }

    public function test_delete_nonexistent_backup_fails(): void
    {
        $result = $this->backupService->deleteBackup('nonexistent_backup.sql');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_backup_info(): void
    {
        // Create a backup first
        $backupResult = $this->backupService->createDatabaseBackup();
        $backupFile = basename($backupResult['backup_file']);

        $info = $this->backupService->getBackupInfo($backupFile);

        $this->assertIsArray($info);
        $this->assertArrayHasKeys(['filename', 'size', 'created_at', 'checksum'], $info);
        $this->assertEquals($backupFile, $info['filename']);

        // Cleanup
        if (file_exists($backupResult['backup_file'])) {
            unlink($backupResult['backup_file']);
        }
    }

    public function test_verify_backup_integrity(): void
    {
        // Create a backup first
        $backupResult = $this->backupService->createDatabaseBackup();
        $backupFile = $backupResult['backup_file'];

        $verification = $this->backupService->verifyBackupIntegrity($backupFile);

        $this->assertTrue($verification['valid']);
        $this->assertArrayHasKey('checksum', $verification);
        $this->assertArrayHasKey('size', $verification);

        // Cleanup
        if (file_exists($backupFile)) {
            unlink($backupFile);
        }
    }

    public function test_schedule_automatic_backup(): void
    {
        $schedule = [
            'frequency' => 'daily',
            'time' => '02:00',
            'retention_days' => 30,
            'compress' => true
        ];

        $result = $this->backupService->scheduleAutomaticBackup($schedule);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('schedule_id', $result);
    }

    public function test_get_backup_schedule(): void
    {
        // Schedule a backup first
        $schedule = [
            'frequency' => 'weekly',
            'time' => '03:00',
            'retention_days' => 60
        ];
        $this->backupService->scheduleAutomaticBackup($schedule);

        $currentSchedule = $this->backupService->getBackupSchedule();

        $this->assertIsArray($currentSchedule);
        $this->assertArrayHasKeys(['frequency', 'time', 'retention_days'], $currentSchedule);
        $this->assertEquals('weekly', $currentSchedule['frequency']);
    }

    public function test_cleanup_old_backups(): void
    {
        // Create multiple backups with different timestamps
        $this->backupService->createDatabaseBackup(['name' => 'old_backup_1']);
        $this->backupService->createDatabaseBackup(['name' => 'old_backup_2']);
        $this->backupService->createDatabaseBackup(['name' => 'recent_backup']);

        // Clean up backups older than 0 days (all should be cleaned)
        $result = $this->backupService->cleanupOldBackups(0);

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['deleted_count']);
    }

    public function test_export_backup_configuration(): void
    {
        // Set up backup configuration
        $this->backupService->scheduleAutomaticBackup([
            'frequency' => 'daily',
            'time' => '02:00',
            'retention_days' => 30
        ]);

        $config = $this->backupService->exportBackupConfiguration();

        $this->assertIsArray($config);
        $this->assertArrayHasKeys(['schedule', 'retention_policy', 'compression'], $config);
    }

    public function test_import_backup_configuration(): void
    {
        $config = [
            'schedule' => [
                'frequency' => 'weekly',
                'time' => '01:00'
            ],
            'retention_policy' => [
                'retention_days' => 90
            ],
            'compression' => [
                'enabled' => true,
                'level' => 6
            ]
        ];

        $result = $this->backupService->importBackupConfiguration($config);

        $this->assertTrue($result['success']);

        // Verify configuration was applied
        $currentConfig = $this->backupService->exportBackupConfiguration();
        $this->assertEquals('weekly', $currentConfig['schedule']['frequency']);
        $this->assertEquals(90, $currentConfig['retention_policy']['retention_days']);
    }

    public function test_get_backup_statistics(): void
    {
        // Create some backups
        $this->backupService->createDatabaseBackup();
        $this->backupService->createDatabaseBackup();

        $stats = $this->backupService->getBackupStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKeys([
            'total_backups',
            'total_size',
            'oldest_backup',
            'newest_backup',
            'average_size'
        ], $stats);

        $this->assertEquals(2, $stats['total_backups']);
        $this->assertIsNumeric($stats['total_size']);

        // Cleanup
        $backups = $this->backupService->listBackups();
        foreach ($backups as $backup) {
            if (file_exists($backup['full_path'])) {
                unlink($backup['full_path']);
            }
        }
    }

    public function test_compress_backup(): void
    {
        // Create an uncompressed backup first
        $backupResult = $this->backupService->createDatabaseBackup(['compress' => false]);
        $backupFile = $backupResult['backup_file'];

        $result = $this->backupService->compressBackup($backupFile);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('compressed_file', $result);
        $this->assertFileExists($result['compressed_file']);

        // Verify compression reduced file size
        $originalSize = filesize($backupFile);
        $compressedSize = filesize($result['compressed_file']);
        $this->assertLessThan($originalSize, $compressedSize);

        // Cleanup
        if (file_exists($backupFile)) {
            unlink($backupFile);
        }
        if (file_exists($result['compressed_file'])) {
            unlink($result['compressed_file']);
        }
    }

    public function test_decompress_backup(): void
    {
        // Create a compressed backup first
        $backupResult = $this->backupService->createDatabaseBackup(['compress' => true]);
        $compressedFile = $backupResult['backup_file'];

        $result = $this->backupService->decompressBackup($compressedFile);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('decompressed_file', $result);
        $this->assertFileExists($result['decompressed_file']);

        // Cleanup
        if (file_exists($compressedFile)) {
            unlink($compressedFile);
        }
        if (file_exists($result['decompressed_file'])) {
            unlink($result['decompressed_file']);
        }
    }

    public function test_validate_backup_file(): void
    {
        // Create a valid backup
        $backupResult = $this->backupService->createDatabaseBackup();
        $backupFile = $backupResult['backup_file'];

        $validation = $this->backupService->validateBackupFile($backupFile);

        $this->assertTrue($validation['valid']);
        $this->assertEmpty($validation['errors']);

        // Test with invalid file
        $invalidValidation = $this->backupService->validateBackupFile('/nonexistent/file.sql');
        $this->assertFalse($invalidValidation['valid']);
        $this->assertNotEmpty($invalidValidation['errors']);

        // Cleanup
        if (file_exists($backupFile)) {
            unlink($backupFile);
        }
    }

    public function test_get_restore_preview(): void
    {
        // Create a backup first
        $backupResult = $this->backupService->createDatabaseBackup();
        $backupFile = $backupResult['backup_file'];

        $preview = $this->backupService->getRestorePreview($backupFile);

        $this->assertIsArray($preview);
        $this->assertArrayHasKeys([
            'tables_to_restore',
            'estimated_records',
            'backup_size',
            'estimated_restore_time'
        ], $preview);

        $this->assertIsArray($preview['tables_to_restore']);
        $this->assertIsNumeric($preview['backup_size']);

        // Cleanup
        if (file_exists($backupFile)) {
            unlink($backupFile);
        }
    }

    public function test_backup_with_encryption(): void
    {
        $result = $this->backupService->createDatabaseBackup([
            'encrypt' => true,
            'encryption_key' => 'test-encryption-key-123'
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('encrypted', $result);
        $this->assertTrue($result['encrypted']);

        // Cleanup
        if (file_exists($result['backup_file'])) {
            unlink($result['backup_file']);
        }
    }

    public function test_get_backup_health_status(): void
    {
        // Create some backups
        $this->backupService->createDatabaseBackup();

        $health = $this->backupService->getBackupHealthStatus();

        $this->assertIsArray($health);
        $this->assertArrayHasKeys([
            'status',
            'last_backup_age',
            'backup_count',
            'storage_usage',
            'issues'
        ], $health);

        $this->assertContains($health['status'], ['healthy', 'warning', 'critical']);
        $this->assertIsArray($health['issues']);

        // Cleanup
        $backups = $this->backupService->listBackups();
        foreach ($backups as $backup) {
            if (file_exists($backup['full_path'])) {
                unlink($backup['full_path']);
            }
        }
    }
}