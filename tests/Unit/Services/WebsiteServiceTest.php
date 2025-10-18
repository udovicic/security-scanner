<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use SecurityScanner\Services\WebsiteService;
use SecurityScanner\Core\Database;
use SecurityScanner\Core\Validator;

class WebsiteServiceTest extends TestCase
{
    private WebsiteService $websiteService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->websiteService = new WebsiteService();
    }

    public function test_create_website_with_valid_data(): void
    {
        $websiteData = [
            'name' => 'Test Website',
            'url' => 'https://example.com',
            'description' => 'A test website',
            'scan_frequency' => 'daily',
            'notification_email' => 'test@example.com'
        ];

        $result = $this->websiteService->createWebsite($websiteData);

        $this->assertTrue($result['success']);
        $this->assertIsInt($result['website_id']);
        $this->assertArrayHasKey('website', $result);
        $this->assertEquals('Test Website', $result['website']['name']);
        $this->assertEquals('https://example.com', $result['website']['url']);
    }

    public function test_create_website_fails_with_invalid_data(): void
    {
        $websiteData = [
            'name' => '', // Empty name should fail
            'url' => 'invalid-url',
            'scan_frequency' => 'invalid-frequency'
        ];

        $result = $this->websiteService->createWebsite($websiteData);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('name', $result['errors']);
        $this->assertArrayHasKey('url', $result['errors']);
        $this->assertArrayHasKey('scan_frequency', $result['errors']);
    }

    public function test_create_website_prevents_duplicate_urls(): void
    {
        // Create first website
        $this->createTestWebsite([
            'name' => 'First Website',
            'url' => 'https://duplicate.com'
        ]);

        // Try to create second website with same URL
        $websiteData = [
            'name' => 'Second Website',
            'url' => 'https://duplicate.com',
            'scan_frequency' => 'daily'
        ];

        $result = $this->websiteService->createWebsite($websiteData);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('url', $result['errors']);
        $this->assertStringContainsString('already exists', $result['errors']['url'][0]);
    }

    public function test_update_website_with_valid_data(): void
    {
        $website = $this->createTestWebsite([
            'name' => 'Original Name',
            'url' => 'https://original.com'
        ]);

        $updateData = [
            'name' => 'Updated Name',
            'description' => 'Updated description'
        ];

        $result = $this->websiteService->updateWebsite($website['id'], $updateData);

        $this->assertTrue($result['success']);
        $this->assertEquals('Updated Name', $result['website']['name']);
        $this->assertEquals('Updated description', $result['website']['description']);
    }

    public function test_update_nonexistent_website_fails(): void
    {
        $result = $this->websiteService->updateWebsite(999999, ['name' => 'New Name']);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('website', $result['errors']);
    }

    public function test_delete_website(): void
    {
        $website = $this->createTestWebsite();

        $result = $this->websiteService->deleteWebsite($website['id']);

        $this->assertTrue($result['success']);

        // Verify website is marked as deleted or removed
        $deletedWebsite = $this->websiteService->getWebsiteById($website['id']);
        $this->assertNull($deletedWebsite);
    }

    public function test_delete_nonexistent_website_fails(): void
    {
        $result = $this->websiteService->deleteWebsite(999999);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
    }

    public function test_get_website_by_id(): void
    {
        $website = $this->createTestWebsite([
            'name' => 'Test Get Website',
            'url' => 'https://testget.com'
        ]);

        $retrieved = $this->websiteService->getWebsiteById($website['id']);

        $this->assertNotNull($retrieved);
        $this->assertEquals($website['id'], $retrieved['id']);
        $this->assertEquals('Test Get Website', $retrieved['name']);
        $this->assertEquals('https://testget.com', $retrieved['url']);
    }

    public function test_get_nonexistent_website_returns_null(): void
    {
        $website = $this->websiteService->getWebsiteById(999999);

        $this->assertNull($website);
    }

    public function test_get_all_websites(): void
    {
        // Create multiple test websites
        $this->createTestWebsite(['name' => 'Website 1', 'url' => 'https://test1.com']);
        $this->createTestWebsite(['name' => 'Website 2', 'url' => 'https://test2.com']);
        $this->createTestWebsite(['name' => 'Website 3', 'url' => 'https://test3.com']);

        $websites = $this->websiteService->getWebsites();

        $this->assertIsArray($websites);
        $this->assertCount(3, $websites);
        $this->assertArrayHasKeys(['id', 'name', 'url'], $websites[0]);
    }

    public function test_get_active_websites(): void
    {
        // Create active and inactive websites
        $this->createTestWebsite(['name' => 'Active Website', 'status' => 'active']);
        $this->createTestWebsite(['name' => 'Inactive Website', 'status' => 'inactive']);

        $activeWebsites = $this->websiteService->getActiveWebsites();

        $this->assertIsArray($activeWebsites);
        $this->assertCount(1, $activeWebsites);
        $this->assertEquals('Active Website', $activeWebsites[0]['name']);
    }

    public function test_search_websites(): void
    {
        $this->createTestWebsite(['name' => 'Google Website', 'url' => 'https://google.com']);
        $this->createTestWebsite(['name' => 'Facebook Website', 'url' => 'https://facebook.com']);
        $this->createTestWebsite(['name' => 'Twitter Website', 'url' => 'https://twitter.com']);

        $results = $this->websiteService->searchWebsites('Google');

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('Google Website', $results[0]['name']);
    }

    public function test_normalize_url(): void
    {
        $testCases = [
            'example.com' => 'https://example.com',
            'http://example.com' => 'https://example.com',
            'https://example.com/' => 'https://example.com',
            'HTTPS://EXAMPLE.COM/PATH' => 'https://example.com/path'
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->websiteService->normalizeUrl($input);
            $this->assertEquals($expected, $result, "Failed normalizing: {$input}");
        }
    }

    public function test_calculate_next_scan_time(): void
    {
        $baseTime = time();

        $testCases = [
            'hourly' => 3600,
            'daily' => 86400,
            'weekly' => 604800,
            'monthly' => 2592000
        ];

        foreach ($testCases as $frequency => $expectedSeconds) {
            $nextScan = $this->websiteService->calculateNextScanTime($frequency);
            $nextScanTime = strtotime($nextScan);

            $this->assertGreaterThanOrEqual($baseTime + $expectedSeconds - 60, $nextScanTime);
            $this->assertLessThanOrEqual($baseTime + $expectedSeconds + 60, $nextScanTime);
        }
    }

    public function test_validate_url_accessibility(): void
    {
        // Test with a known accessible URL (mocked)
        $this->assertTrue($this->websiteService->validateUrlAccessibility('https://httpbin.org/status/200'));

        // Test with invalid URL
        $this->assertFalse($this->websiteService->validateUrlAccessibility('https://invalid-domain-that-should-not-exist.com'));
    }

    public function test_get_website_statistics(): void
    {
        // Create websites with different statuses
        $this->createTestWebsite(['name' => 'Active 1', 'status' => 'active']);
        $this->createTestWebsite(['name' => 'Active 2', 'status' => 'active']);
        $this->createTestWebsite(['name' => 'Inactive 1', 'status' => 'inactive']);

        $stats = $this->websiteService->getWebsiteStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKeys(['total', 'active', 'inactive'], $stats);
        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(2, $stats['active']);
        $this->assertEquals(1, $stats['inactive']);
    }

    public function test_bulk_update_websites(): void
    {
        $website1 = $this->createTestWebsite(['name' => 'Website 1']);
        $website2 = $this->createTestWebsite(['name' => 'Website 2']);

        $updates = [
            $website1['id'] => ['scan_frequency' => 'weekly'],
            $website2['id'] => ['scan_frequency' => 'monthly']
        ];

        $result = $this->websiteService->bulkUpdateWebsites($updates);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['updated_count']);

        // Verify updates
        $updated1 = $this->websiteService->getWebsiteById($website1['id']);
        $updated2 = $this->websiteService->getWebsiteById($website2['id']);

        $this->assertEquals('weekly', $updated1['scan_frequency']);
        $this->assertEquals('monthly', $updated2['scan_frequency']);
    }

    public function test_export_websites(): void
    {
        $this->createTestWebsite(['name' => 'Export Test 1', 'url' => 'https://export1.com']);
        $this->createTestWebsite(['name' => 'Export Test 2', 'url' => 'https://export2.com']);

        $exportData = $this->websiteService->exportWebsites();

        $this->assertIsArray($exportData);
        $this->assertCount(2, $exportData);
        $this->assertArrayHasKeys(['name', 'url', 'scan_frequency'], $exportData[0]);
    }

    public function test_import_websites(): void
    {
        $importData = [
            [
                'name' => 'Imported Website 1',
                'url' => 'https://imported1.com',
                'scan_frequency' => 'daily'
            ],
            [
                'name' => 'Imported Website 2',
                'url' => 'https://imported2.com',
                'scan_frequency' => 'weekly'
            ]
        ];

        $result = $this->websiteService->importWebsites($importData);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['imported_count']);
        $this->assertEquals(0, $result['failed_count']);

        // Verify imports
        $websites = $this->websiteService->getWebsites();
        $this->assertCount(2, $websites);
    }

    public function test_import_websites_with_invalid_data(): void
    {
        $importData = [
            [
                'name' => 'Valid Website',
                'url' => 'https://valid.com',
                'scan_frequency' => 'daily'
            ],
            [
                'name' => '', // Invalid - empty name
                'url' => 'invalid-url',
                'scan_frequency' => 'invalid'
            ]
        ];

        $result = $this->websiteService->importWebsites($importData);

        $this->assertTrue($result['success']); // Overall success even with some failures
        $this->assertEquals(1, $result['imported_count']);
        $this->assertEquals(1, $result['failed_count']);
        $this->assertArrayHasKey('errors', $result);
    }

    public function test_website_url_health_check(): void
    {
        $website = $this->createTestWebsite(['url' => 'https://httpbin.org/status/200']);

        $healthCheck = $this->websiteService->performHealthCheck($website['id']);

        $this->assertIsArray($healthCheck);
        $this->assertArrayHasKeys(['accessible', 'response_time', 'status_code'], $healthCheck);
        $this->assertIsBool($healthCheck['accessible']);
        $this->assertIsNumeric($healthCheck['response_time']);
    }

    public function test_get_websites_due_for_scan(): void
    {
        // Create websites with next_scan_at in the past
        $pastTime = date('Y-m-d H:i:s', time() - 3600);
        $futureTime = date('Y-m-d H:i:s', time() + 3600);

        $this->createTestWebsite(['name' => 'Due for Scan', 'next_scan_at' => $pastTime]);
        $this->createTestWebsite(['name' => 'Not Due', 'next_scan_at' => $futureTime]);

        $dueWebsites = $this->websiteService->getWebsitesDueForScan();

        $this->assertIsArray($dueWebsites);
        $this->assertCount(1, $dueWebsites);
        $this->assertEquals('Due for Scan', $dueWebsites[0]['name']);
    }
}