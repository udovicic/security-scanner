<?php

require_once 'src/Core/Autoloader.php';
SecurityScanner\Core\Autoloader::register();

use SecurityScanner\Core\{InputSanitizer, Request, Response};

echo "🛡️ Testing Input Sanitization and Security (Task 37)\n";
echo "===================================================\n\n";

try {
    $testsPassed = 0;
    $totalTests = 0;

    echo "1. Testing Basic HTML Sanitization:\n";

    $sanitizer = new InputSanitizer();

    // Test basic HTML encoding
    $totalTests++;
    $maliciousHtml = '<script>alert("XSS")</script><b>Hello</b>';
    $sanitized = $sanitizer->sanitizeHtml($maliciousHtml);

    if (!str_contains($sanitized, '<script>') &&
        str_contains($sanitized, '&lt;') &&
        str_contains($sanitized, 'Hello')) {
        echo "   ✅ HTML sanitization: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ HTML sanitization failed\n";
    }

    echo "\n2. Testing XSS Prevention:\n";

    // Test various XSS attack vectors
    $xssVectors = [
        '<script>alert("xss")</script>',
        '<img src="x" onerror="alert(1)">',
        'javascript:alert("xss")',
        '<iframe src="javascript:alert(1)"></iframe>',
        '<object data="data:text/html,<script>alert(1)</script>"></object>',
        '<style>body{background:url("javascript:alert(1)")}</style>'
    ];

    $totalTests++;
    $allXssBlocked = true;
    foreach ($xssVectors as $vector) {
        $sanitized = $sanitizer->sanitizeXss($vector);
        if (str_contains(strtolower($sanitized), 'script') ||
            str_contains(strtolower($sanitized), 'javascript') ||
            str_contains(strtolower($sanitized), 'onerror')) {
            $allXssBlocked = false;
            break;
        }
    }

    if ($allXssBlocked) {
        echo "   ✅ XSS prevention: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ XSS prevention failed\n";
    }

    echo "\n3. Testing SQL Injection Prevention:\n";

    // Test SQL injection patterns
    $sqlVectors = [
        "'; DROP TABLE users; --",
        "admin' OR '1'='1",
        "1 UNION SELECT password FROM users",
        "admin'/**/OR/**/1=1",
        "'; INSERT INTO users VALUES ('hacker', 'pass'); --"
    ];

    $totalTests++;
    $allSqlBlocked = true;
    foreach ($sqlVectors as $vector) {
        $sanitized = $sanitizer->sanitizeSql($vector);
        if (preg_match('/(DROP|UNION|INSERT|SELECT|OR.*=.*)/i', $sanitized)) {
            $allSqlBlocked = false;
            break;
        }
    }

    if ($allSqlBlocked) {
        echo "   ✅ SQL injection prevention: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ SQL injection prevention failed\n";
    }

    echo "\n4. Testing URL Sanitization:\n";

    // Test URL sanitization
    $urls = [
        'https://example.com/page?param=value' => 'https://example.com/page?param=value',
        'javascript:alert("xss")' => '',
        'data:text/html,<script>alert(1)</script>' => '',
        'ftp://files.example.com/file.txt' => 'ftp://files.example.com/file.txt',
        'invalid-url' => ''
    ];

    $totalTests++;
    $urlTestPassed = true;
    foreach ($urls as $input => $expected) {
        $result = $sanitizer->sanitizeUrl($input);
        if ($result !== $expected) {
            $urlTestPassed = false;
            break;
        }
    }

    if ($urlTestPassed) {
        echo "   ✅ URL sanitization: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ URL sanitization failed\n";
    }

    echo "\n5. Testing Email Sanitization:\n";

    // Test email sanitization
    $emails = [
        'user@example.com' => 'user@example.com',
        'user+tag@example.com' => 'user+tag@example.com',
        'invalid-email' => '',
        'user@' => '',
        'user<script>alert(1)</script>@example.com' => 'useralert1@example.com'
    ];

    $totalTests++;
    $emailTestPassed = true;
    foreach ($emails as $input => $expected) {
        $result = $sanitizer->sanitizeEmail($input);
        if ($result !== $expected) {
            $emailTestPassed = false;
            break;
        }
    }

    if ($emailTestPassed) {
        echo "   ✅ Email sanitization: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Email sanitization failed\n";
    }

    echo "\n6. Testing Filename Sanitization:\n";

    // Test filename sanitization
    $filenames = [
        '../../../etc/passwd' => 'etcpasswd',
        'normal-file.txt' => 'normal-file.txt',
        'file<>:"|?*.exe' => 'file.exe',
        "file\x00name.txt" => 'filename.txt'
    ];

    $totalTests++;
    $filenameTestPassed = true;
    foreach ($filenames as $input => $expected) {
        $result = $sanitizer->sanitizeFilename($input);
        if ($result !== $expected) {
            $filenameTestPassed = false;
            break;
        }
    }

    if ($filenameTestPassed) {
        echo "   ✅ Filename sanitization: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Filename sanitization failed\n";
    }

    echo "\n7. Testing Alphanumeric Sanitization:\n";

    // Test alphanumeric sanitization
    $totalTests++;
    $alphaNumeric = $sanitizer->sanitizeAlphaNumeric('Hello123!@#$%^&*()World456');
    if ($alphaNumeric === 'Hello123World456') {
        echo "   ✅ Alphanumeric sanitization: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Alphanumeric sanitization failed\n";
    }

    echo "\n8. Testing Numeric Sanitization:\n";

    // Test numeric sanitization
    $totalTests++;
    $numeric = $sanitizer->sanitizeNumeric('Price: $123.45 USD');
    if ($numeric === '123.45') {
        echo "   ✅ Numeric sanitization: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Numeric sanitization failed\n";
    }

    echo "\n9. Testing Recursive Array Sanitization:\n";

    // Test array sanitization
    $totalTests++;
    $dirtyArray = [
        'name' => '<script>alert("xss")</script>John',
        'email' => 'john@example.com',
        'comments' => [
            'comment1' => 'Hello <b>world</b>!',
            'comment2' => 'javascript:alert("xss")'
        ],
        'user<script>' => 'malicious_key'
    ];

    $rules = [
        'name' => ['filter' => 'xss'],
        'email' => ['filter' => 'email'],
        'comments' => [
            '*' => ['filter' => 'html']
        ]
    ];

    $sanitized = $sanitizer->sanitize($dirtyArray, $rules);

    if (!str_contains($sanitized['name'], '<script>') &&
        $sanitized['email'] === 'john@example.com' &&
        isset($sanitized['user_script_']) &&
        !str_contains($sanitized['comments']['comment2'], 'javascript')) {
        echo "   ✅ Recursive array sanitization: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Recursive array sanitization failed\n";
    }

    echo "\n10. Testing Custom Filters:\n";

    // Test custom filter registration
    $totalTests++;
    $sanitizer->addCustomFilter('uppercase', function($data, $options = []) {
        return strtoupper($data);
    });

    $result = $sanitizer->applyFilter('hello world', 'uppercase');
    if ($result === 'HELLO WORLD') {
        echo "   ✅ Custom filters: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Custom filters failed\n";
    }

    echo "\n11. Testing Safety Validation:\n";

    // Test safety validation
    $totalTests++;
    $safeData = 'This is safe data';
    $unsafeData = '<script>alert("xss")</script>';

    if ($sanitizer->isSafe($safeData) && !$sanitizer->isSafe($unsafeData)) {
        echo "   ✅ Safety validation: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Safety validation failed\n";
    }

    echo "\n12. Testing Middleware Integration:\n";

    // Test middleware integration
    $totalTests++;
    $middlewareExecuted = false;
    $middleware = $sanitizer->middleware([
        'post' => [
            'username' => ['filter' => 'alphanumeric'],
            'comment' => ['filter' => 'xss']
        ]
    ]);

    $request = new Request(
        [], // GET
        ['username' => 'user123!@#', 'comment' => '<script>alert("xss")</script>Hello'], // POST
        ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test']
    );

    $next = function($req) use (&$middlewareExecuted) {
        $middlewareExecuted = true;
        // Check if sanitization worked
        if ($req->post('username') === 'user123' &&
            !str_contains($req->post('comment'), '<script>')) {
            return Response::json(['status' => 'ok']);
        }
        return Response::json(['status' => 'failed']);
    };

    $response = $middleware($request, $next);

    if ($middlewareExecuted && $response->getContent() === '{"status":"ok"}') {
        echo "   ✅ Middleware integration: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Middleware integration failed\n";
    }

    echo "\n13. Testing Batch Sanitization:\n";

    // Test batch sanitization
    $totalTests++;
    $batchData = [
        'field1' => '<script>alert(1)</script>',
        'field2' => 'user@example.com',
        'field3' => '123.45abc'
    ];

    $batchRules = [
        'field1' => ['filter' => 'xss'],
        'field2' => ['filter' => 'email'],
        'field3' => ['filter' => 'numeric']
    ];

    $batchResult = $sanitizer->sanitizeMany($batchData, $batchRules);

    if (!str_contains($batchResult['field1'], '<script>') &&
        $batchResult['field2'] === 'user@example.com' &&
        $batchResult['field3'] === '123.45') {
        echo "   ✅ Batch sanitization: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Batch sanitization failed\n";
    }

    echo "\n14. Testing Static Clean Method:\n";

    // Test static convenience method
    $totalTests++;
    $quickClean = InputSanitizer::clean('<script>alert("xss")</script>Test', 'xss');

    if (!str_contains($quickClean, '<script>') && str_contains($quickClean, 'Test')) {
        echo "   ✅ Static clean method: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Static clean method failed\n";
    }

    echo "\n15. Testing Configuration Options:\n";

    // Test configuration options
    $totalTests++;
    $strictSanitizer = new InputSanitizer([
        'max_input_length' => 10,
        'strict_mode' => true,
        'allow_html_tags' => ['b', 'i']
    ]);

    $longString = str_repeat('a', 20);
    $truncated = $strictSanitizer->sanitize($longString);

    if (strlen($truncated) === 10) {
        echo "   ✅ Configuration options: PASSED\n";
        $testsPassed++;
    } else {
        echo "   ❌ Configuration options failed\n";
    }

    echo "\nInput Sanitization Test Summary:\n";
    echo "===============================\n";
    echo "Tests Passed: {$testsPassed}/{$totalTests}\n";
    echo "Success Rate: " . round(($testsPassed / $totalTests) * 100, 1) . "%\n\n";

    if ($testsPassed === $totalTests) {
        echo "🎉 All input sanitization tests passed! Security systems are working correctly.\n";
        echo "\nSecurity Features Implemented:\n";
        echo "- Comprehensive XSS prevention with multiple attack vector detection\n";
        echo "- SQL injection prevention with pattern matching and content filtering\n";
        echo "- HTML sanitization with configurable allowed tags\n";
        echo "- URL validation with protocol whitelisting\n";
        echo "- Email sanitization and validation\n";
        echo "- Filename sanitization preventing directory traversal\n";
        echo "- Input type-specific sanitization (alphanumeric, numeric, etc.)\n";
        echo "- Recursive array sanitization with field-specific rules\n";
        echo "- Custom filter registration and extensibility\n";
        echo "- Safety validation checks for sanitized content\n";
        echo "- Middleware integration for automatic request sanitization\n";
        echo "- Batch processing for multiple field sanitization\n";
        echo "- Configurable security policies and limits\n";
        echo "- Unicode normalization and encoding safety\n";
        echo "- Null byte removal and control character filtering\n";

        echo "\n🛡️ Security Scanner Tool Input Sanitization System Ready!\n";
    } else {
        echo "⚠️ Some input sanitization tests failed. Review implementation.\n";
    }

} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}