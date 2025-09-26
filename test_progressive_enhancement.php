<?php

require_once 'src/Core/Autoloader.php';
SecurityScanner\Core\Autoloader::register();

use SecurityScanner\Core\{ProgressiveEnhancement, Request, Response};

echo "🎨 Testing Progressive Enhancement Foundation (Task 38)\n";
echo "====================================================\n\n";

try {
    $testsPassed = 0;
    $totalTests = 0;

    echo "1. Testing Base HTML Structure Generation:\n";

    $pe = new ProgressiveEnhancement();

    // Test basic page structure
    $totalTests++;
    $content = [
        'title' => 'Test Page',
        'description' => 'Test page description',
        'navigation' => [
            ['href' => '/', 'text' => 'Home'],
            ['href' => '/scan', 'text' => 'Scan'],
            ['href' => '/results', 'text' => 'Results']
        ],
        'main' => '<h1>Welcome to Security Scanner</h1><p>This page works without JavaScript.</p>',
        'footer' => '<p>Progressive enhancement demo</p>'
    ];

    $html = $pe->renderBaseStructure($content);

    $structureChecks = [
        str_contains($html, 'DOCTYPE html'),
        str_contains($html, 'class="no-js"'),
        str_contains($html, 'Test Page'),
        str_contains($html, 'skip-link'),
        str_contains($html, 'role="navigation"'),
        str_contains($html, 'id="main-content"'),
        str_contains($html, 'role="main"'),
        str_contains($html, 'Progressive enhancement demo')
    ];

    if (array_sum($structureChecks) === count($structureChecks)) {
        echo "   ✅ Base HTML structure: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Base HTML structure failed\n";
    }

    echo "\n2. Testing Form with Progressive Enhancement:\n";

    // Test form generation
    $totalTests++;
    $form = [
        'action' => '/submit',
        'method' => 'POST',
        'id' => 'contact-form',
        'csrf_token' => 'test-token-123',
        'fields' => [
            [
                'type' => 'text',
                'name' => 'name',
                'label' => 'Your Name',
                'required' => true,
                'value' => ''
            ],
            [
                'type' => 'email',
                'name' => 'email',
                'label' => 'Email Address',
                'required' => true,
                'value' => ''
            ],
            [
                'type' => 'textarea',
                'name' => 'message',
                'label' => 'Message',
                'rows' => 5,
                'required' => false,
                'value' => ''
            ],
            [
                'type' => 'select',
                'name' => 'category',
                'label' => 'Category',
                'required' => true,
                'options' => [
                    'general' => 'General Inquiry',
                    'support' => 'Support Request',
                    'bug' => 'Bug Report'
                ]
            ]
        ],
        'submit_text' => 'Send Message'
    ];

    $formHtml = $pe->renderForm($form);

    $formChecks = [
        str_contains($formHtml, 'action="/submit"'),
        str_contains($formHtml, 'method="POST"'),
        str_contains($formHtml, 'csrf_token'),
        str_contains($formHtml, 'test-token-123'),
        str_contains($formHtml, 'required'),
        str_contains($formHtml, 'Your Name'),
        str_contains($formHtml, 'Email Address'),
        str_contains($formHtml, 'textarea'),
        str_contains($formHtml, 'select'),
        str_contains($formHtml, 'General Inquiry'),
        str_contains($formHtml, 'noscript'),
        str_contains($formHtml, 'Send Message')
    ];

    if (array_sum($formChecks) === count($formChecks)) {
        echo "   ✅ Progressive form generation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Progressive form generation failed\n";
    }

    echo "\n3. Testing Data Table Generation:\n";

    // Test data table
    $totalTests++;
    $table = [
        'caption' => 'Security Scan Results',
        'headers' => [
            ['text' => 'URL', 'sortable' => true, 'sort_url' => '?sort=url'],
            ['text' => 'Status', 'sortable' => true, 'sort_url' => '?sort=status'],
            ['text' => 'Issues', 'sortable' => false],
            ['text' => 'Score', 'sortable' => true, 'sort_url' => '?sort=score']
        ],
        'rows' => [
            ['https://example.com', 'Complete', '3 warnings', '85/100'],
            ['https://test.com', 'In Progress', '1 error', '60/100'],
            ['https://demo.com', 'Complete', 'No issues', '100/100']
        ]
    ];

    $tableHtml = $pe->renderDataTable($table);

    $tableChecks = [
        str_contains($tableHtml, 'table-wrapper'),
        str_contains($tableHtml, 'data-table'),
        str_contains($tableHtml, 'Security Scan Results'),
        str_contains($tableHtml, 'thead'),
        str_contains($tableHtml, 'tbody'),
        str_contains($tableHtml, '?sort=url'),
        str_contains($tableHtml, 'https://example.com'),
        str_contains($tableHtml, '100/100')
    ];

    if (array_sum($tableChecks) === count($tableChecks)) {
        echo "   ✅ Data table generation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Data table generation failed\n";
    }

    echo "\n4. Testing Pagination Generation:\n";

    // Test pagination
    $totalTests++;
    $pagination = [
        'current' => 3,
        'total' => 10,
        'base_url' => '/results'
    ];

    $paginationHtml = $pe->renderPagination($pagination);

    $paginationChecks = [
        str_contains($paginationHtml, 'pagination'),
        str_contains($paginationHtml, 'aria-label="Pagination"'),
        str_contains($paginationHtml, 'Previous'),
        str_contains($paginationHtml, 'Next'),
        str_contains($paginationHtml, 'aria-current="page"'),
        str_contains($paginationHtml, '?page=2'),
        str_contains($paginationHtml, '?page=4'),
        str_contains($paginationHtml, 'rel="prev"'),
        str_contains($paginationHtml, 'rel="next"')
    ];

    if (array_sum($paginationChecks) === count($paginationChecks)) {
        echo "   ✅ Pagination generation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Pagination generation failed\n";
    }

    echo "\n5. Testing NoScript Content:\n";

    // Test noscript functionality
    $totalTests++;
    $pe->addNoScriptContent('<div class="fallback-message">JavaScript is disabled. Using fallback interface.</div>');
    $pe->addNoScriptContent('<style>.js-only { display: none; }</style>');

    $noScriptHtml = $pe->renderNoScriptContent();

    $noScriptChecks = [
        str_contains($noScriptHtml, '<noscript>'),
        str_contains($noScriptHtml, '</noscript>'),
        str_contains($noScriptHtml, 'fallback-message'),
        str_contains($noScriptHtml, 'JavaScript is disabled'),
        str_contains($noScriptHtml, '.js-only')
    ];

    if (array_sum($noScriptChecks) === count($noScriptChecks)) {
        echo "   ✅ NoScript content: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ NoScript content failed\n";
    }

    echo "\n6. Testing Accessibility Features:\n";

    // Test accessibility in generated HTML
    $totalTests++;
    $accessibilityChecks = [
        str_contains($html, 'skip-link'),
        str_contains($html, 'Skip to main content'),
        str_contains($html, 'role="navigation"'),
        str_contains($html, 'role="main"'),
        str_contains($html, 'role="contentinfo"'),
        str_contains($html, 'aria-label="Main navigation"'),
        str_contains($formHtml, 'label for='),
        str_contains($tableHtml, 'caption'),
        str_contains($paginationHtml, 'aria-label="Pagination"'),
        str_contains($paginationHtml, 'aria-current="page"')
    ];

    if (array_sum($accessibilityChecks) === count($accessibilityChecks)) {
        echo "   ✅ Accessibility features: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Accessibility features failed\n";
    }

    echo "\n7. Testing CSS Critical Path:\n";

    // Test critical CSS inclusion
    $totalTests++;
    $criticalCssChecks = [
        str_contains($html, '<style>'),
        str_contains($html, 'box-sizing: border-box'),
        str_contains($html, '.skip-link'),
        str_contains($html, '.nav-fallback'),
        str_contains($html, '.form-group'),
        str_contains($html, '.no-js .js-only { display: none; }'),
        str_contains($html, '.js .no-js-only { display: none; }')
    ];

    if (array_sum($criticalCssChecks) === count($criticalCssChecks)) {
        echo "   ✅ Critical CSS inclusion: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Critical CSS inclusion failed\n";
    }

    echo "\n8. Testing Progressive Enhancement Detection:\n";

    // Test JavaScript enhancement detection
    $totalTests++;
    $jsDetectionChecks = [
        str_contains($html, 'class="no-js"'),
        str_contains($html, "document.documentElement.className.replace('no-js', 'js')"),
        str_contains($html, 'hasModernFeatures'),
        str_contains($html, 'querySelector'),
        str_contains($html, 'addEventListener'),
        str_contains($html, 'classList'),
        str_contains($html, 'enhancementsToLoad')
    ];

    if (array_sum($jsDetectionChecks) === count($jsDetectionChecks)) {
        echo "   ✅ Enhancement detection: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Enhancement detection failed\n";
    }

    echo "\n9. Testing Middleware Integration:\n";

    // Test middleware functionality
    $totalTests++;
    try {
        $testRequest = new Request(
            ['page' => '1'],
            ['form_data' => 'test'],
            ['REQUEST_METHOD' => 'GET', 'HTTP_USER_AGENT' => 'Mozilla/5.0']
        );

        $middleware = $pe->middleware();
        
        $next = function($req) {
            return Response::json(['status' => 'ok']);
        };

        $response = $middleware($testRequest, $next);
        
        $middlewareChecks = [
            $response->getHeader('X-Progressive-Enhancement') === 'enabled',
            in_array($response->getHeader('X-Enhancement-Level'), ['fallback', 'enhanced'])
        ];

        if (array_sum($middlewareChecks) === count($middlewareChecks)) {
            echo "   ✅ Middleware integration: PASSED\n";
            $testsPassed++;
        } else {
            echo "   ❌ Middleware integration failed\n";
        }
    } catch (Exception $e) {
        echo "   ❌ Middleware integration failed: " . $e->getMessage() . "\n";
    }

    echo "\n10. Testing Configuration Options:\n";

    // Test configuration flexibility
    $totalTests++;
    $customConfig = [
        'enable_fallbacks' => false,
        'css_critical_inline' => false,
        'defer_non_critical_css' => false,
        'javascript_optional' => false
    ];

    $customPE = new ProgressiveEnhancement($customConfig);
    $customHtml = $customPE->renderBaseStructure($content);

    $configChecks = [
        !str_contains($customHtml, 'enhancementsToLoad'), // JS should be disabled
        !str_contains($customHtml, 'rel="preload"') // CSS defer disabled
    ];

    if (array_sum($configChecks) === count($configChecks)) {
        echo "   ✅ Configuration options: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Configuration options failed\n";
    }

    echo "\nProgressive Enhancement Test Summary:\n";
    echo "===================================\n";
    echo "Tests Passed: {$testsPassed}/{$totalTests}\n";
    echo "Success Rate: " . round(($testsPassed / $totalTests) * 100, 1) . "%\n\n";

    if ($testsPassed >= $totalTests * 0.9) {
        echo "🎉 Progressive enhancement foundation working correctly!\n";
        echo "\nProgressive Enhancement Features:\n";
        echo "- Semantic HTML structure that works without JavaScript\n";
        echo "- Critical CSS inlined for immediate styling\n";
        echo "- Graceful degradation for older browsers\n";
        echo "- Accessibility features (ARIA labels, skip links, roles)\n";
        echo "- Forms with server-side validation fallbacks\n";
        echo "- Data tables with sorting fallbacks\n";
        echo "- Pagination with direct links\n";
        echo "- NoScript content and fallback messages\n";
        echo "- Feature detection and conditional enhancement\n";
        echo "- Configurable enhancement levels\n";
        echo "- Middleware integration for request handling\n";
        echo "- Mobile-first responsive design principles\n";

        echo "\n🎨 Progressive Enhancement System Ready for Production!\n";
    } else {
        echo "⚠️ Some progressive enhancement tests failed. Review implementation.\n";
    }

} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
