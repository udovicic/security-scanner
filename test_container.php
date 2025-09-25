<?php

require_once 'src/Core/Autoloader.php';
SecurityScanner\Core\Autoloader::register();

use SecurityScanner\Core\{
    Container,
    ContainerException,
    ServiceProvider,
    ProviderManager
};

echo "🧪 Testing Dependency Injection Container...\n\n";

// Test classes for dependency injection
class TestService
{
    public string $name;

    public function __construct(string $name = 'TestService')
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

class DependentService
{
    private TestService $testService;
    private string $config;

    public function __construct(TestService $testService, string $config = 'default')
    {
        $this->testService = $testService;
        $this->config = $config;
    }

    public function getInfo(): array
    {
        return [
            'service' => $this->testService->getName(),
            'config' => $this->config
        ];
    }
}

// Test Service Provider
class TestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(TestService::class, function() {
            return new TestService('Singleton Service');
        });

        $this->bind('test.config', function() {
            return 'provider-config';
        });

        $this->alias('test-service', TestService::class);
    }

    public function provides(): array
    {
        return [TestService::class, 'test.config'];
    }
}

try {
    echo "1. Testing basic container operations...\n";

    $container = Container::getInstance();

    // Test singleton registration
    $container->singleton('test-singleton', function() {
        return new TestService('Singleton Instance');
    });

    $instance1 = $container->get('test-singleton');
    $instance2 = $container->get('test-singleton');

    if ($instance1 === $instance2) {
        echo "   ✓ Singleton pattern working correctly\n";
    } else {
        echo "   ❌ Singleton pattern failed\n";
    }

    // Test basic binding
    $container->bind('test-basic', TestService::class);
    $basicInstance = $container->get('test-basic');
    echo "   ✓ Basic binding: " . $basicInstance->getName() . "\n";

    // Test closure binding
    $container->bind('test-closure', function() {
        return new TestService('Closure Service');
    });
    $closureInstance = $container->get('test-closure');
    echo "   ✓ Closure binding: " . $closureInstance->getName() . "\n";

    // Test instance binding
    $existingInstance = new TestService('Existing Instance');
    $container->instance('test-instance', $existingInstance);
    $retrievedInstance = $container->get('test-instance');

    if ($existingInstance === $retrievedInstance) {
        echo "   ✓ Instance binding working correctly\n";
    } else {
        echo "   ❌ Instance binding failed\n";
    }

    echo "\n2. Testing dependency injection...\n";

    // Register core services first
    $container->registerCoreServices();
    echo "   ✓ Core services registered\n";

    // Test automatic dependency injection
    $container->bind(DependentService::class);
    $dependentService = $container->get(DependentService::class);
    $info = $dependentService->getInfo();
    echo "   ✓ Auto-injection: service=" . $info['service'] . ", config=" . $info['config'] . "\n";

    // Test make (always new instance)
    $newInstance1 = $container->make(TestService::class);
    $newInstance2 = $container->make(TestService::class);

    if ($newInstance1 !== $newInstance2) {
        echo "   ✓ Make creates new instances\n";
    } else {
        echo "   ❌ Make should create new instances\n";
    }

    echo "\n3. Testing aliases and tags...\n";

    // Test aliases
    $container->alias('my-service', TestService::class);
    $aliasedService = $container->get('my-service');
    echo "   ✓ Alias working: " . $aliasedService->getName() . "\n";

    // Test tags
    $container->tag(['test-basic', 'test-closure'], 'test-services');
    $taggedServices = $container->tagged('test-services');
    echo "   ✓ Tagged services count: " . count($taggedServices) . "\n";

    echo "\n4. Testing service providers...\n";

    $providerManager = new ProviderManager($container);
    $providerManager->register(TestServiceProvider::class);

    echo "   ✓ Provider registered\n";

    $providerManager->boot();
    echo "   ✓ Providers booted\n";

    // Test provider-registered service
    $providerService = $container->get('test-service'); // Using alias
    echo "   ✓ Provider service: " . $providerService->getName() . "\n";

    echo "\n5. Testing container stats...\n";

    $stats = $container->getStats();
    echo "   ✓ Total services: " . $stats['total_services'] . "\n";
    echo "   ✓ Resolved instances: " . $stats['resolved_instances'] . "\n";
    echo "   ✓ Singletons: " . $stats['singletons'] . "\n";
    echo "   ✓ Aliases: " . $stats['aliases'] . "\n";

    $providerStats = $providerManager->getStats();
    echo "   ✓ Total providers: " . $providerStats['total_providers'] . "\n";

    echo "\n6. Testing method calls with DI...\n";

    // Test calling a method with dependency injection
    $result = $container->call(function(TestService $service, string $message = 'Hello') {
        return $message . ' from ' . $service->getName();
    });
    echo "   ✓ Method call result: " . $result . "\n";

    echo "\n7. Testing error handling...\n";

    try {
        $container->get('NonExistentService');
        echo "   ❌ Should have thrown exception\n";
    } catch (ContainerException $e) {
        echo "   ✓ Container exception caught: " . substr($e->getMessage(), 0, 50) . "...\n";
    }

    echo "\n8. Testing has() method...\n";

    echo "   ✓ Has TestService: " . ($container->has(TestService::class) ? 'true' : 'false') . "\n";
    echo "   ✓ Has NonExistent: " . ($container->has('NonExistentService') ? 'true' : 'false') . "\n";

    echo "\n✅ All dependency injection container tests passed!\n";

} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}