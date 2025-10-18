<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\ArchiveService;
use SecurityScanner\Core\Database;

class ArchiveServiceTest extends TestCase
{
    private ArchiveService $archiveService;
    
    private string $tempArchivePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempArchivePath = sys_get_temp_dir() . '/test_archives_' . uniqid();

        $config = [
            'retention_days' => [
                'scan_results' => 30,
                'test_executions' => 30,
                'scheduler_log' => 7,
                'notification_log' => 7,
                'scan_metrics' => 90
            ],
            'archive_batch_size' => 100,
            'compression_enabled' => true,
            'encryption_enabled' => false,
            'cleanup_enabled' => true,
            'archive_path' => $this->tempArchivePath
        ];

        $this->archiveService = new ArchiveService($config);
        
    }

    protected function tearDown(): void
    {
        // Clean up test archive directory
        if (is_dir($this->tempArchivePath)) {
            $files = glob($this->tempArchivePath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempArchivePath);
        }

        parent::tearDown();
    }

    public function test_perform_archive_runs_successfully(): void
    {
        // Create test data
        $website = $this->createTestWebsite();
        $execution = $this->createTestExecution(['website_id' => $website['id']]);

        // Create old scan results
        $oldDate = date('Y-m-d H:i:s', time() - (35 * 24 * 60 * 60)); // 35 days ago
        $this->database->insert('scan_results', [
            'website_id' => $website['id'],
            'execution_id' => $execution['id'],
            'status' => 'completed',
            'results' => json_encode(['ssl_test' => 'passed']),
            'created_at' => $oldDate
        ]);

        $result = $this->archiveService->performArchive();

        $this->assertTrue($result['success']);
        $this->assertIsNumeric($result['execution_time']);
        $this->assertGreaterThanOrEqual(0, $result['tables_processed']);
        $this->assertIsArray($result['errors']);
    }

    public function test_archive_scan_results_creates_archive_and_deletes_records(): void
    {
        $website = $this->createTestWebsite();
        $execution = $this->createTestExecution(['website_id' => $website['id']]);

        // Create old scan result
        $oldDate = date('Y-m-d H:i:s', time() - (35 * 24 * 60 * 60));
        $scanId = $this->database->insert('scan_results', [
            'website_id' => $website['id'],
            'execution_id' => $execution['id'],
            'status' => 'completed',
            'results' => json_encode(['ssl_test' => 'passed']),
            'created_at' => $oldDate
        ]);

        $result = $this->archiveService->archiveScanResults();

        $this->assertEquals('scan_results', $result['table']);
        $this->assertGreaterThan(0, $result['records_archived']);
        $this->assertGreaterThan(0, $result['records_deleted']);
        $this->assertEmpty($result['errors']);

        // Verify record was deleted
        $remaining = $this->database->fetchRow(
            'SELECT * FROM scan_results WHERE id = ?',
            [$scanId]
        );
        $this->assertNull($remaining);
    }

    public function test_archive_test_executions_handles_orphaned_records(): void
    {
        // Create orphaned test execution (no parent scan_result)
        $oldDate = date('Y-m-d H:i:s', time() - (35 * 24 * 60 * 60));
        $executionId = $this->database->insert('test_executions', [
            'scan_id' => 999999, // Non-existent scan
            'test_name' => 'ssl_test',
            'status' => 'completed',
            'started_at' => $oldDate,
            'created_at' => $oldDate
        ]);

        $result = $this->archiveService->archiveTestExecutions();

        $this->assertEquals('test_executions', $result['table']);
        $this->assertGreaterThan(0, $result['records_archived']);
        $this->assertGreaterThan(0, $result['records_deleted']);

        // Verify orphaned execution was deleted
        $remaining = $this->database->fetchRow(
            'SELECT * FROM test_executions WHERE id = ?',
            [$executionId]
        );
        $this->assertNull($remaining);
    }

    public function test_archive_scheduler_logs(): void
    {
        // Create old scheduler log
        $oldDate = date('Y-m-d H:i:s', time() - (10 * 24 * 60 * 60)); // 10 days ago
        $logId = $this->database->insert('scheduler_log', [
            'level' => 'info',
            'message' => 'Test log entry',
            'context' => json_encode(['test' => true]),
            'created_at' => $oldDate
        ]);

        $result = $this->archiveService->archiveSchedulerLogs();

        $this->assertEquals('scheduler_log', $result['table']);
        $this->assertGreaterThan(0, $result['records_archived']);
        $this->assertGreaterThan(0, $result['records_deleted']);

        // Verify log was deleted
        $remaining = $this->database->fetchRow(
            'SELECT * FROM scheduler_log WHERE id = ?',
            [$logId]
        );
        $this->assertNull($remaining);
    }

    public function test_archive_notification_logs(): void
    {
        // Create old notification log
        $oldDate = date('Y-m-d H:i:s', time() - (10 * 24 * 60 * 60));
        $logId = $this->database->insert('notification_log', [
            'type' => 'email',
            'recipient' => 'test@example.com',
            'subject' => 'Test notification',
            'status' => 'sent',
            'created_at' => $oldDate
        ]);

        $result = $this->archiveService->archiveNotificationLogs();

        $this->assertEquals('notification_log', $result['table']);
        $this->assertGreaterThan(0, $result['records_archived']);
        $this->assertGreaterThan(0, $result['records_deleted']);

        // Verify log was deleted
        $remaining = $this->database->fetchRow(
            'SELECT * FROM notification_log WHERE id = ?',
            [$logId]
        );
        $this->assertNull($remaining);
    }

    public function test_archive_scan_metrics(): void
    {
        $website = $this->createTestWebsite();

        // Create old scan metrics
        $oldDate = date('Y-m-d H:i:s', time() - (800 * 24 * 60 * 60)); // Very old
        $metricId = $this->database->insert('scan_metrics', [
            'website_id' => $website['id'],
            'total_tests' => 5,
            'passed_tests' => 4,
            'failed_tests' => 1,
            'success_rate' => 80.0,
            'average_execution_time' => 2.5,
            'created_at' => $oldDate
        ]);

        $result = $this->archiveService->archiveScanMetrics();

        $this->assertEquals('scan_metrics', $result['table']);
        $this->assertGreaterThan(0, $result['records_archived']);
        $this->assertGreaterThan(0, $result['records_deleted']);

        // Verify metric was deleted
        $remaining = $this->database->fetchRow(
            'SELECT * FROM scan_metrics WHERE id = ?',
            [$metricId]
        );
        $this->assertNull($remaining);
    }

    public function test_cleanup_orphaned_records(): void
    {
        // Create orphaned test execution
        $orphanedExecution = $this->database->insert('test_executions', [
            'scan_id' => 999999, // Non-existent scan
            'test_name' => 'ssl_test',
            'status' => 'completed',
            'started_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Create orphaned website test config
        $orphanedConfig = $this->database->insert('website_test_config', [
            'website_id' => 999999, // Non-existent website
            'test_name' => 'ssl_test',
            'enabled' => true,
            'configuration' => json_encode(['timeout' => 30]),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $result = $this->archiveService->cleanupOrphanedRecords();

        $this->assertEquals('cleanup_orphaned', $result['table']);
        $this->assertGreaterThan(0, $result['records_deleted']);
        $this->assertIsArray($result['cleanup_operations']);
        $this->assertCount(3, $result['cleanup_operations']); // 3 cleanup operations

        // Verify orphaned records were deleted
        $remainingExecution = $this->database->fetchRow(
            'SELECT * FROM test_executions WHERE id = ?',
            [$orphanedExecution]
        );
        $this->assertNull($remainingExecution);

        $remainingConfig = $this->database->fetchRow(
            'SELECT * FROM website_test_config WHERE id = ?',
            [$orphanedConfig]
        );
        $this->assertNull($remainingConfig);
    }

    public function test_restore_from_archive(): void
    {
        // Create test data and archive it
        $website = $this->createTestWebsite();
        $execution = $this->createTestExecution(['website_id' => $website['id']]);

        $oldDate = date('Y-m-d H:i:s', time() - (35 * 24 * 60 * 60));
        $scanId = $this->database->insert('scan_results', [
            'website_id' => $website['id'],
            'execution_id' => $execution['id'],
            'status' => 'completed',
            'results' => json_encode(['ssl_test' => 'passed']),
            'created_at' => $oldDate
        ]);

        // Archive the data
        $this->archiveService->archiveScanResults();

        // Get the archive file
        $archiveFiles = $this->archiveService->listArchiveFiles();
        $this->assertNotEmpty($archiveFiles);

        $archiveFile = $archiveFiles[0]['full_path'];

        // Restore from archive
        $result = $this->archiveService->restoreFromArchive($archiveFile, 'scan_results');

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['records_restored']);
        $this->assertEquals('scan_results', $result['table']);
    }

    public function test_restore_from_nonexistent_archive_fails(): void
    {
        $result = $this->archiveService->restoreFromArchive('/nonexistent/archive.json');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Archive file not found', $result['error']);
    }

    public function test_list_archive_files(): void
    {
        // Create some test data and archive it
        $website = $this->createTestWebsite();
        $execution = $this->createTestExecution(['website_id' => $website['id']]);

        $oldDate = date('Y-m-d H:i:s', time() - (35 * 24 * 60 * 60));
        $this->database->insert('scan_results', [
            'website_id' => $website['id'],
            'execution_id' => $execution['id'],
            'status' => 'completed',
            'results' => json_encode(['ssl_test' => 'passed']),
            'created_at' => $oldDate
        ]);

        // Archive to create files
        $this->archiveService->archiveScanResults();

        $files = $this->archiveService->listArchiveFiles();

        $this->assertIsArray($files);
        $this->assertGreaterThan(0, count($files));

        foreach ($files as $file) {
            $this->assertArrayHasKeys(['filename', 'full_path', 'size', 'created_at', 'table'], $file);
            $this->assertFileExists($file['full_path']);
        }
    }

    public function test_get_archive_statistics(): void
    {
        // Create and archive some data
        $website = $this->createTestWebsite();
        $execution = $this->createTestExecution(['website_id' => $website['id']]);

        $oldDate = date('Y-m-d H:i:s', time() - (35 * 24 * 60 * 60));
        $this->database->insert('scan_results', [
            'website_id' => $website['id'],
            'execution_id' => $execution['id'],
            'status' => 'completed',
            'results' => json_encode(['ssl_test' => 'passed']),
            'created_at' => $oldDate
        ]);

        $this->archiveService->archiveScanResults();

        $stats = $this->archiveService->getArchiveStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKeys([
            'total_files',
            'total_size',
            'oldest_archive',
            'newest_archive',
            'by_table',
            'total_size_mb'
        ], $stats);

        $this->assertGreaterThan(0, $stats['total_files']);
        $this->assertGreaterThan(0, $stats['total_size']);
        $this->assertIsArray($stats['by_table']);
    }

    public function test_cleanup_old_archives(): void
    {
        // Create archive files
        $website = $this->createTestWebsite();
        $execution = $this->createTestExecution(['website_id' => $website['id']]);

        $oldDate = date('Y-m-d H:i:s', time() - (35 * 24 * 60 * 60));
        $this->database->insert('scan_results', [
            'website_id' => $website['id'],
            'execution_id' => $execution['id'],
            'status' => 'completed',
            'results' => json_encode(['ssl_test' => 'passed']),
            'created_at' => $oldDate
        ]);

        $this->archiveService->archiveScanResults();

        // Get initial file count
        $initialFiles = $this->archiveService->listArchiveFiles();
        $initialCount = count($initialFiles);

        // Cleanup with 0 days retention (should delete all files)
        $result = $this->archiveService->cleanupOldArchives(0);

        $this->assertArrayHasKeys(['files_deleted', 'space_freed', 'errors'], $result);
        $this->assertEquals($initialCount, $result['files_deleted']);
        $this->assertGreaterThan(0, $result['space_freed']);

        // Verify files were deleted
        $remainingFiles = $this->archiveService->listArchiveFiles();
        $this->assertCount(0, $remainingFiles);
    }

    public function test_archive_with_compression_enabled(): void
    {
        $config = [
            'compression_enabled' => true,
            'archive_path' => $this->tempArchivePath
        ];

        $archiveService = new ArchiveService($config);

        $website = $this->createTestWebsite();
        $execution = $this->createTestExecution(['website_id' => $website['id']]);

        $oldDate = date('Y-m-d H:i:s', time() - (35 * 24 * 60 * 60));
        $this->database->insert('scan_results', [
            'website_id' => $website['id'],
            'execution_id' => $execution['id'],
            'status' => 'completed',
            'results' => json_encode(['ssl_test' => 'passed']),
            'created_at' => $oldDate
        ]);

        $result = $archiveService->archiveScanResults();

        $this->assertGreaterThan(0, $result['records_archived']);
        $this->assertNotEmpty($result['archive_files']);

        $archiveFile = $result['archive_files'][0];
        $this->assertTrue($archiveFile['success']);
        $this->assertStringContainsString('.gz', $archiveFile['file_path']);
        $this->assertGreaterThan(0, $archiveFile['compression_ratio']);
    }

    public function test_archive_with_encryption_enabled(): void
    {
        $config = [
            'encryption_enabled' => true,
            'encryption_key' => 'test-encryption-key-32-characters!',
            'compression_enabled' => false,
            'archive_path' => $this->tempArchivePath
        ];

        $archiveService = new ArchiveService($config);

        $website = $this->createTestWebsite();
        $execution = $this->createTestExecution(['website_id' => $website['id']]);

        $oldDate = date('Y-m-d H:i:s', time() - (35 * 24 * 60 * 60));
        $this->database->insert('scan_results', [
            'website_id' => $website['id'],
            'execution_id' => $execution['id'],
            'status' => 'completed',
            'results' => json_encode(['ssl_test' => 'passed']),
            'created_at' => $oldDate
        ]);

        $result = $archiveService->archiveScanResults();

        $this->assertGreaterThan(0, $result['records_archived']);
        $this->assertNotEmpty($result['archive_files']);

        $archiveFile = $result['archive_files'][0];
        $this->assertTrue($archiveFile['success']);
        $this->assertFileExists($archiveFile['file_path']);

        // Verify file content is encrypted (not readable as plain JSON)
        $content = file_get_contents($archiveFile['file_path']);
        $this->assertFalse(json_decode($content, true)); // Should not be valid JSON
    }

    public function test_archive_handles_empty_results_gracefully(): void
    {
        // Don't create any old data
        $result = $this->archiveService->archiveScanResults();

        $this->assertEquals('scan_results', $result['table']);
        $this->assertEquals(0, $result['records_archived']);
        $this->assertEquals(0, $result['records_deleted']);
        $this->assertEmpty($result['archive_files']);
        $this->assertEmpty($result['errors']);
    }

    public function test_archive_respects_batch_size_limit(): void
    {
        $config = [
            'archive_batch_size' => 2, // Small batch size
            'archive_path' => $this->tempArchivePath
        ];

        $archiveService = new ArchiveService($config);

        $website = $this->createTestWebsite();
        $execution = $this->createTestExecution(['website_id' => $website['id']]);

        // Create 5 old scan results
        $oldDate = date('Y-m-d H:i:s', time() - (35 * 24 * 60 * 60));
        for ($i = 0; $i < 5; $i++) {
            $this->database->insert('scan_results', [
                'website_id' => $website['id'],
                'execution_id' => $execution['id'],
                'status' => 'completed',
                'results' => json_encode(['ssl_test' => 'passed']),
                'created_at' => $oldDate
            ]);
        }

        $result = $archiveService->archiveScanResults();

        // Should only process batch_size records (2)
        $this->assertEquals(2, $result['records_archived']);
        $this->assertEquals(2, $result['records_deleted']);

        // Verify 3 records remain
        $remaining = $this->database->fetchAll(
            'SELECT * FROM scan_results WHERE created_at < ?',
            [$oldDate]
        );
        $this->assertCount(3, $remaining);
    }
}