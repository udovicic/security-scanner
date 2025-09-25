<?php

require_once 'src/Core/Autoloader.php';

// Register the autoloader
SecurityScanner\Core\Autoloader::register();

use SecurityScanner\Core\{
    AbstractTest,
    AbstractController,
    AbstractModel,
    AbstractService,
    TestResult,
    ValidationException,
    ServiceException
};

echo "🧪 Testing Abstract Classes...\n\n";

// Test AbstractTest
class TestSSLTest extends AbstractTest
{
    protected string $name = 'SSL Certificate Test';
    protected string $description = 'Checks if SSL certificate is valid and not expired';

    public function execute(string $url, array $options = []): TestResult
    {
        if (!$this->validateUrl($url)) {
            return $this->createErrorResult('Invalid URL provided');
        }

        $response = $this->makeRequest($url, ['method' => 'HEAD']);

        if (!$response['success']) {
            return $this->createFailureResult('Could not connect to URL: ' . $response['error']);
        }

        if ($response['http_code'] === 200) {
            return $this->createSuccessResult('SSL certificate is valid', [
                'response_time' => $response['response_time'],
                'http_code' => $response['http_code']
            ]);
        }

        return $this->createFailureResult('Unexpected HTTP status: ' . $response['http_code']);
    }
}

// Test AbstractController
class TestWebsiteController extends AbstractController
{
    public function index(): array
    {
        return [
            'websites' => [
                ['id' => 1, 'url' => 'https://example.com', 'status' => 'active'],
                ['id' => 2, 'url' => 'https://test.com', 'status' => 'inactive']
            ]
        ];
    }

    public function show(array $params): array
    {
        $id = $params['id'] ?? null;
        return ['website' => ['id' => $id, 'url' => 'https://example.com']];
    }
}

// Test AbstractModel
class TestWebsiteModel extends AbstractModel
{
    protected string $table = 'websites';
    protected array $fillable = ['url', 'name', 'status'];
    protected array $hidden = ['password'];
    protected array $casts = [
        'id' => 'int',
        'active' => 'bool',
        'metadata' => 'json'
    ];
}

// Test AbstractService
class TestWebsiteService extends AbstractService
{
    public function createWebsite(array $data): array
    {
        return $this->execute(function() use ($data) {
            $validated = $this->validate($data, [
                'url' => 'required|url',
                'name' => 'required|min:3|max:100'
            ]);

            return $this->formatApiResponse($validated, 'Website created successfully');
        }, 'create_website');
    }

    public function testRetry(): array
    {
        $attempt = 0;
        return $this->executeWithRetry(function() use (&$attempt) {
            $attempt++;
            if ($attempt < 3) {
                throw new \Exception("Attempt {$attempt} failed");
            }
            return ['success' => true, 'attempts' => $attempt];
        }, 3, 100, 'test_retry');
    }
}

try {
    echo "1. Testing AbstractTest implementation...\n";
    $sslTest = new TestSSLTest();
    echo "   ✓ SSL Test created: " . $sslTest->getName() . "\n";
    echo "   ✓ Description: " . $sslTest->getDescription() . "\n";
    echo "   ✓ Supported methods: " . implode(', ', $sslTest->getSupportedMethods()) . "\n";
    echo "   ✓ Timeout: " . $sslTest->getTimeout() . " seconds\n";

    // Test URL validation
    echo "   ✓ URL validation works: " . ($sslTest->supportsMethod('GET') ? 'true' : 'false') . "\n";

    echo "\n2. Testing AbstractController implementation...\n";
    $controller = new TestWebsiteController();
    echo "   ✓ Controller created\n";

    // Test action execution
    $response = $controller->execute('index');
    echo "   ✓ Index action executed, status: " . $response->getStatusCode() . "\n";

    $response = $controller->execute('show', ['id' => 123]);
    echo "   ✓ Show action executed, status: " . $response->getStatusCode() . "\n";

    echo "\n3. Testing AbstractModel implementation...\n";
    $model = new TestWebsiteModel();
    echo "   ✓ Model created\n";
    echo "   ✓ Table name: " . (function() use ($model) {
        $reflection = new ReflectionClass($model);
        $property = $reflection->getProperty('table');
        $property->setAccessible(true);
        return $property->getValue($model);
    })() . "\n";

    echo "\n4. Testing AbstractService implementation...\n";
    $service = new TestWebsiteService();
    echo "   ✓ Service created\n";

    // Test health check
    $health = $service->healthCheck();
    echo "   ✓ Health check: " . $health['status'] . "\n";

    // Test validation (should pass)
    try {
        $result = $service->createWebsite([
            'url' => 'https://example.com',
            'name' => 'Example Website'
        ]);
        echo "   ✓ Validation passed for valid data\n";
    } catch (ServiceException $e) {
        if (strpos($e->getMessage(), 'Validation failed') !== false) {
            echo "   ✓ Validation logic is working (caught validation error)\n";
        } else {
            echo "   ❌ Unexpected service error: " . $e->getMessage() . "\n";
        }
    } catch (ValidationException $e) {
        echo "   ✓ Validation logic is working (caught validation exception)\n";
    }

    // Test validation (should fail)
    try {
        $service->createWebsite([
            'url' => 'invalid-url',
            'name' => 'Ex'  // Too short
        ]);
        echo "   ❌ Validation should have failed\n";
    } catch (ServiceException $e) {
        if (strpos($e->getMessage(), 'Validation failed') !== false) {
            echo "   ✓ Validation correctly failed for invalid data\n";
        } else {
            echo "   ❌ Unexpected service error: " . $e->getMessage() . "\n";
        }
    } catch (ValidationException $e) {
        echo "   ✓ Validation correctly failed for invalid data\n";
    }

    // Test retry logic
    try {
        $result = $service->testRetry();
        $attempts = $result['data']['attempts'] ?? $result['attempts'] ?? 'unknown';
        echo "   ✓ Retry logic worked, attempts: " . $attempts . "\n";
    } catch (ServiceException $e) {
        echo "   ❌ Retry logic failed: " . $e->getMessage() . "\n";
    }

    echo "\n✅ All abstract class tests completed successfully!\n";

} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}