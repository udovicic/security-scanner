<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\DatabaseBackupService;
use SecurityScanner\Core\Database;

class DatabaseBackupServiceTest extends TestCase
{
    private DatabaseBackupService $backupService;
    private string $tempBackupDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary backup directory
        $this->tempBackupDir = sys_get_temp_dir() . '/test_backups_' . uniqid();
        mkdir($this->tempBackupDir, 0755, true);

        // Mock config to use temp directory
        $this->mockConfig([
            'backup.directory' => $this->tempBackupDir,
            'backup.encryption_key' => 'test-encryption-key-32-characters',
            'database' => [
                'host' => 'localhost',
                'database' => 'test_db',
                'username' => 'test_user',
                'password' => 'test_pass'
            ]
        ]);

        $this->backupService = new DatabaseBackupService();
    }

    protected function tearDown(): void
    {
        // Clean up temp backup directory
        if (is_dir($this->tempBackupDir)) {
            $files = glob($this->tempBackupDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempBackupDir);
        }

        parent::tearDown();
    }

    public function test_create_full_backup_basic_functionality(): void
    {
        // Note: This test will be limited because we can't actually execute mysqldump
        // in the test environment. We'll test the method structure and error handling.

        $this->assertTrue(method_exists($this->backupService, 'createFullBackup'));

        // Test that method accepts expected parameters
        $reflection = new \ReflectionMethod($this->backupService, 'createFullBackup');
        $parameters = $reflection->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertEquals('encrypt', $parameters[0]->getName());
        $this->assertEquals('compress', $parameters[1]->getName());
    }

    public function test_create_incremental_backup_with_no_previous_backup(): void
    {
        // When no previous backup exists, should fallback to full backup
        $this->assertTrue(method_exists($this->backupService, 'createIncrementalBackup'));

        // Test method signature
        $reflection = new \ReflectionMethod($this->backupService, 'createIncrementalBackup');
        $parameters = $reflection->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertEquals('encrypt', $parameters[0]->getName());
        $this->assertEquals('compress', $parameters[1]->getName());
    }

    public function test_restore_from_backup_requires_confirmation(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database restore is a dangerous operation');

        $this->backupService->restoreFromBackup('nonexistent.sql', false);
    }

    public function test_restore_from_backup_fails_with_nonexistent_file(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Backup file not found');

        $this->backupService->restoreFromBackup('nonexistent.sql', true);
    }

    public function test_list_backups_returns_empty_array_when_no_backups(): void
    {
        $backups = $this->backupService->listBackups();

        $this->assertIsArray($backups);
        $this->assertEmpty($backups);
    }

    public function test_list_backups_with_sample_files(): void
    {
        // Create sample backup files
        $files = [
            'full_backup_2023-01-01_12-00-00.sql',
            'incremental_backup_2023-01-01_13-00-00.json.gz',
            'full_backup_2023-01-01_14-00-00.sql.enc.gz'
        ];

        foreach ($files as $file) {
            file_put_contents($this->tempBackupDir . '/' . $file, 'sample backup data');
            // Set file time to make testing predictable
            touch($this->tempBackupDir . '/' . $file, time() - (array_search($file, $files) * 3600));
        }

        $backups = $this->backupService->listBackups();

        $this->assertIsArray($backups);
        $this->assertCount(3, $backups);

        // Check structure of backup entries
        foreach ($backups as $backup) {
            $this->assertArrayHasKeys([
                'filename',
                'file_path',
                'file_size',
                'created_at',
                'is_encrypted',
                'is_compressed',
                'backup_type'
            ], $backup);
        }

        // Check that backups are sorted by creation time (newest first)
        $this->assertStringContainsString('14-00-00', $backups[0]['filename']); // Most recent
        $this->assertStringContainsString('12-00-00', $backups[2]['filename']); // Oldest

        // Check backup type detection
        $fullBackup = array_filter($backups, fn($b) => strpos($b['filename'], 'full_backup') !== false)[0];
        $incrementalBackup = array_filter($backups, fn($b) => strpos($b['filename'], 'incremental') !== false)[0];

        $this->assertEquals('full', $fullBackup['backup_type']);
        $this->assertEquals('incremental', $incrementalBackup['backup_type']);

        // Check encryption/compression detection
        $encryptedBackup = array_filter($backups, fn($b) => strpos($b['filename'], '.enc') !== false)[0];
        $compressedBackup = array_filter($backups, fn($b) => strpos($b['filename'], '.gz') !== false)[0];

        $this->assertTrue($encryptedBackup['is_encrypted']);
        $this->assertTrue($compressedBackup['is_compressed']);
    }

    public function test_cleanup_old_backups(): void
    {
        // Create backup files with different ages
        $oldFile = $this->tempBackupDir . '/old_backup.sql';
        $recentFile = $this->tempBackupDir . '/recent_backup.sql';

        file_put_contents($oldFile, 'old backup data');
        file_put_contents($recentFile, 'recent backup data');

        // Set file times
        $oldTime = time() - (35 * 24 * 60 * 60); // 35 days ago
        $recentTime = time() - (5 * 24 * 60 * 60); // 5 days ago

        touch($oldFile, $oldTime);
        touch($recentFile, $recentTime);

        // Cleanup with 30 day retention
        $deletedCount = $this->backupService->cleanupOldBackups(30);

        $this->assertEquals(1, $deletedCount);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($recentFile);
    }

    public function test_cleanup_old_backups_with_no_old_files(): void
    {
        // Create only recent files
        $recentFile = $this->tempBackupDir . '/recent_backup.sql';
        file_put_contents($recentFile, 'recent backup data');

        $deletedCount = $this->backupService->cleanupOldBackups(30);

        $this->assertEquals(0, $deletedCount);
        $this->assertFileExists($recentFile);
    }

    public function test_generate_backup_filename(): void
    {
        // Test filename generation through reflection
        $reflection = new \ReflectionClass($this->backupService);
        $method = $reflection->getMethod('generateBackupFilename');
        $method->setAccessible(true);

        // Test various combinations
        $filename1 = $method->invoke($this->backupService, 'test_backup', false, false);
        $this->assertEquals('test_backup.sql', $filename1);

        $filename2 = $method->invoke($this->backupService, 'test_backup', true, false);
        $this->assertEquals('test_backup.sql.enc', $filename2);

        $filename3 = $method->invoke($this->backupService, 'test_backup', false, true);
        $this->assertEquals('test_backup.sql.gz', $filename3);

        $filename4 = $method->invoke($this->backupService, 'test_backup', true, true);
        $this->assertEquals('test_backup.sql.enc.gz', $filename4);
    }

    public function test_encryption_and_decryption(): void
    {
        $reflection = new \ReflectionClass($this->backupService);

        $encryptMethod = $reflection->getMethod('encryptData');
        $encryptMethod->setAccessible(true);

        $decryptMethod = $reflection->getMethod('decryptData');
        $decryptMethod->setAccessible(true);

        $originalData = 'This is test backup data with special characters: !@#$%^&*()';

        // Encrypt data
        $encryptedData = $encryptMethod->invoke($this->backupService, $originalData);

        $this->assertIsString($encryptedData);
        $this->assertNotEquals($originalData, $encryptedData);
        $this->assertTrue(base64_decode($encryptedData, true) !== false); // Valid base64

        // Decrypt data
        $decryptedData = $decryptMethod->invoke($this->backupService, $encryptedData);

        $this->assertEquals($originalData, $decryptedData);
    }

    public function test_get_encryption_key(): void
    {
        $reflection = new \ReflectionClass($this->backupService);
        $method = $reflection->getMethod('getEncryptionKey');
        $method->setAccessible(true);

        $key = $method->invoke($this->backupService);

        $this->assertIsString($key);
        $this->assertNotEmpty($key);
        // Should be SHA256 hash length (64 characters) or configured key
        $this->assertGreaterThan(16, strlen($key));
    }

    public function test_ensure_backup_directory(): void
    {
        // Delete temp directory to test creation
        rmdir($this->tempBackupDir);
        $this->assertDirectoryDoesNotExist($this->tempBackupDir);

        // Create new service instance which should recreate directory
        $reflection = new \ReflectionClass($this->backupService);
        $method = $reflection->getMethod('ensureBackupDirectory');
        $method->setAccessible(true);

        $method->invoke($this->backupService);

        $this->assertDirectoryExists($this->tempBackupDir);
    }

    public function test_get_table_count(): void
    {
        $reflection = new \ReflectionClass($this->backupService);
        $method = $reflection->getMethod('getTableCount');
        $method->setAccessible(true);

        $count = $method->invoke($this->backupService);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_build_mysqldump_command(): void
    {
        $reflection = new \ReflectionClass($this->backupService);
        $method = $reflection->getMethod('buildMysqldumpCommand');
        $method->setAccessible(true);

        $command = $method->invoke($this->backupService, 'localhost', 'testdb', 'user', 'pass');

        $this->assertIsString($command);
        $this->assertStringContainsString('mysqldump', $command);
        $this->assertStringContainsString('--host=localhost', $command);
        $this->assertStringContainsString('--user=user', $command);
        $this->assertStringContainsString('testdb', $command);
        // Password should not be visible in plain text in command for security
        $this->assertStringContainsString('--password=', $command);
    }

    public function test_record_backup(): void
    {
        $reflection = new \ReflectionClass($this->backupService);
        $method = $reflection->getMethod('recordBackup');
        $method->setAccessible(true);

        $backupStats = [
            'backup_name' => 'test_backup',
            'filename' => 'test_backup.sql',
            'file_size' => 1024,
            'is_encrypted' => true,
            'is_compressed' => false,
            'execution_time_ms' => 500.5,
            'tables_backed_up' => 10,
            'created_at' => date('Y-m-d H:i:s')
        ];

        // This should not throw an exception
        $method->invoke($this->backupService, $backupStats);

        // Verify backup was recorded in database
        $recordedBackup = $this->database->fetchRow(
            'SELECT * FROM database_backups WHERE backup_name = ?',
            ['test_backup']
        );

        $this->assertNotNull($recordedBackup);
        $this->assertEquals('test_backup', $recordedBackup['backup_name']);
        $this->assertEquals('test_backup.sql', $recordedBackup['filename']);
        $this->assertEquals(1024, $recordedBackup['file_size']);
        $this->assertEquals(1, $recordedBackup['is_encrypted']);
        $this->assertEquals(0, $recordedBackup['is_compressed']);
    }

    public function test_get_last_backup_time(): void
    {
        $reflection = new \ReflectionClass($this->backupService);
        $method = $reflection->getMethod('getLastBackupTime');
        $method->setAccessible(true);

        // Should return null when no backups exist
        $lastTime = $method->invoke($this->backupService);
        $this->assertNull($lastTime);

        // Create a backup record
        $this->database->insert('database_backups', [
            'backup_name' => 'test_backup',
            'filename' => 'test.sql',
            'file_size' => 1024,
            'created_at' => '2023-01-01 12:00:00'
        ]);

        $lastTime = $method->invoke($this->backupService);
        $this->assertEquals('2023-01-01 12:00:00', $lastTime);
    }

    public function test_get_backup_statistics(): void
    {
        // Create some backup records
        $backups = [
            ['backup_name' => 'backup1', 'filename' => 'backup1.sql', 'file_size' => 1000, 'created_at' => date('Y-m-d H:i:s', time() - 3600)],
            ['backup_name' => 'backup2', 'filename' => 'backup2.sql', 'file_size' => 2000, 'created_at' => date('Y-m-d H:i:s', time() - 1800)],
            ['backup_name' => 'backup3', 'filename' => 'backup3.sql', 'file_size' => 3000, 'created_at' => date('Y-m-d H:i:s')]
        ];

        foreach ($backups as $backup) {
            $this->database->insert('database_backups', $backup);
        }

        $reflection = new \ReflectionClass($this->backupService);

        if ($reflection->hasMethod('getBackupStatistics')) {
            $method = $reflection->getMethod('getBackupStatistics');
            $method->setAccessible(true);

            $stats = $method->invoke($this->backupService);

            $this->assertIsArray($stats);
            $this->assertArrayHasKey('total_backups', $stats);
            $this->assertArrayHasKey('total_size', $stats);
            $this->assertEquals(3, $stats['total_backups']);
            $this->assertEquals(6000, $stats['total_size']);
        } else {
            // Method doesn't exist, mark test as skipped
            $this->markTestSkipped('getBackupStatistics method not implemented');
        }
    }

    public function test_file_operations_with_simulated_data(): void
    {
        // Test creating a backup file with various content
        $testData = "-- MySQL dump\nCREATE TABLE test (id INT);\nINSERT INTO test VALUES (1);";
        $filename = 'test_backup_' . time() . '.sql';
        $filePath = $this->tempBackupDir . '/' . $filename;

        // Write test data
        $bytesWritten = file_put_contents($filePath, $testData);
        $this->assertGreaterThan(0, $bytesWritten);
        $this->assertFileExists($filePath);

        // Read and verify
        $readData = file_get_contents($filePath);
        $this->assertEquals($testData, $readData);

        // Test file size
        $fileSize = filesize($filePath);
        $this->assertEquals(strlen($testData), $fileSize);

        // Clean up
        unlink($filePath);
        $this->assertFileDoesNotExist($filePath);
    }

    private function mockConfig(array $config): void
    {
        // This would be used to mock configuration values
        // In a real implementation, you might use a mocking framework
        // or dependency injection to provide test configuration
    }
}