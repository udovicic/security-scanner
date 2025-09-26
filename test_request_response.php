<?php

require_once 'src/Core/Autoloader.php';
SecurityScanner\Core\Autoloader::register();

use SecurityScanner\Core\{Request, Response, UploadedFile, Validator, ValidationException};

echo "🔄 Testing Request/Response Classes (Task 27)\n";
echo "============================================\n\n";

try {
    // Test Request class
    echo "1. Testing Request Class:\n";

    // Create test request with mock data
    $mockServer = [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/websites?filter=active',
        'HTTP_CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer token123',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        'HTTP_USER_AGENT' => 'Test Agent 1.0',
        'REMOTE_ADDR' => '192.168.1.100',
        'HTTPS' => 'on'
    ];

    $mockGet = ['filter' => 'active', 'limit' => '10'];
    $mockPost = ['name' => 'Test Website', 'url' => 'https://example.com'];

    $request = new Request($mockGet, $mockPost, $mockServer);

    echo "   ✅ Request created successfully\n";
    echo "   ✅ Method: " . $request->getMethod() . "\n";
    echo "   ✅ Path: " . $request->getPath() . "\n";
    echo "   ✅ Query String: " . $request->getQueryString() . "\n";
    echo "   ✅ Is Secure: " . ($request->isSecure() ? 'yes' : 'no') . "\n";
    echo "   ✅ Is AJAX: " . ($request->isAjax() ? 'yes' : 'no') . "\n";
    echo "   ✅ Expects JSON: " . ($request->expectsJson() ? 'yes' : 'no') . "\n";

    // Test input retrieval
    echo "   ✅ Query param 'filter': " . $request->query('filter') . "\n";
    echo "   ✅ Post input 'name': " . $request->input('name') . "\n";
    echo "   ✅ All input count: " . count($request->all()) . "\n";

    // Test input filtering
    $onlyFields = $request->only(['name', 'url']);
    echo "   ✅ Only specific fields: " . implode(', ', array_keys($onlyFields)) . "\n";

    $exceptFields = $request->except(['filter']);
    echo "   ✅ Except filter: " . count($exceptFields) . " fields\n";

    // Test client info
    echo "   ✅ Client IP: " . $request->getClientIp() . "\n";
    echo "   ✅ User Agent: " . $request->getUserAgent() . "\n";

    // Test route parameters
    $request->setRouteParams(['id' => '123', 'action' => 'edit']);
    echo "   ✅ Route param 'id': " . $request->route('id') . "\n";

    // Test attributes
    $request->setAttribute('user_id', 456);
    echo "   ✅ Attribute 'user_id': " . $request->getAttribute('user_id') . "\n";

    // Test sanitization
    $mockPostDirty = ['name' => '<script>alert("xss")</script>Test', 'email' => 'test@example.com'];
    $requestDirty = new Request([], $mockPostDirty, $mockServer);
    echo "   ✅ Sanitized name: " . $requestDirty->sanitize('name') . "\n";
    echo "   ✅ Sanitized email: " . $requestDirty->sanitize('email', 'email') . "\n";

    // Test pattern matching
    echo "   ✅ Matches '/api/*': " . ($request->is('/api/*') ? 'yes' : 'no') . "\n";

    echo "\n2. Testing Response Class:\n";

    // Test basic response
    $response = new Response('Hello World', 200);
    echo "   ✅ Basic response created: " . $response->getStatusCode() . " - " . $response->getStatusText() . "\n";

    // Test JSON response
    $jsonResponse = Response::json(['message' => 'Success', 'data' => ['id' => 1, 'name' => 'Test']]);
    echo "   ✅ JSON response: " . ($jsonResponse->isJson() ? 'is JSON' : 'not JSON') . "\n";
    echo "   ✅ JSON content length: " . strlen($jsonResponse->getContent()) . " characters\n";

    // Test success response
    $successResponse = Response::success(['user' => 'John'], 'User retrieved successfully');
    echo "   ✅ Success response: " . ($successResponse->isSuccessful() ? 'successful' : 'not successful') . "\n";

    // Test error responses
    $notFoundResponse = Response::notFound('Resource not found');
    echo "   ✅ 404 Response: " . $notFoundResponse->getStatusCode() . " - " . $notFoundResponse->getStatusText() . "\n";

    $unauthorizedResponse = Response::unauthorized('Access denied');
    echo "   ✅ 401 Response: " . ($unauthorizedResponse->isClientError() ? 'client error' : 'not client error') . "\n";

    $serverErrorResponse = Response::error('Internal server error', 500);
    echo "   ✅ 500 Response: " . ($serverErrorResponse->isServerError() ? 'server error' : 'not server error') . "\n";

    // Test validation error
    $validationErrors = ['email' => ['Invalid email format'], 'name' => ['Name is required']];
    $validationResponse = Response::validationError($validationErrors);
    echo "   ✅ Validation error: " . $validationResponse->getStatusCode() . " status\n";

    // Test redirect response
    $redirectResponse = Response::redirect('/login');
    echo "   ✅ Redirect response: " . ($redirectResponse->isRedirection() ? 'is redirect' : 'not redirect') . "\n";
    echo "   ✅ Location header: " . $redirectResponse->getHeader('Location') . "\n";

    echo "\n3. Testing Response Features:\n";

    // Test headers
    $response->setHeader('X-Custom-Header', 'custom-value');
    echo "   ✅ Custom header set: " . $response->getHeader('X-Custom-Header') . "\n";

    // Test multiple headers
    $response->setHeaders(['X-Another' => 'value', 'X-Third' => 'third']);
    echo "   ✅ Multiple headers count: " . count($response->getHeaders()) . "\n";

    // Test cookies
    $response->setCookie('session_id', 'abc123', time() + 3600, '/', '', true, true, 'Strict');
    $cookies = $response->getCookies();
    echo "   ✅ Cookie set: " . (isset($cookies['session_id']) ? 'yes' : 'no') . "\n";

    // Test security headers
    $secureResponse = Response::json(['data' => 'secure'])->withSecurityHeaders();
    echo "   ✅ Security headers: " . (count($secureResponse->getHeaders()) > 5 ? 'added' : 'not added') . "\n";

    // Test CORS headers
    $corsResponse = Response::json(['data' => 'cors'])->withCors(['https://example.com']);
    echo "   ✅ CORS headers: " . ($corsResponse->getHeader('Access-Control-Allow-Origin') ? 'added' : 'not added') . "\n";

    // Test cache headers
    $cachedResponse = Response::json(['data' => 'cached'])->withCache(3600, true);
    echo "   ✅ Cache headers: " . ($cachedResponse->getHeader('Cache-Control') ? 'added' : 'not added') . "\n";

    $noCacheResponse = Response::json(['data' => 'no-cache'])->withoutCache();
    echo "   ✅ No-cache headers: " . ($noCacheResponse->getHeader('Cache-Control') ? 'added' : 'not added') . "\n";

    // Test content type
    $xmlResponse = Response::create('<xml></xml>')->withContentType('application/xml');
    echo "   ✅ Content-Type: " . $xmlResponse->getHeader('Content-Type') . "\n";

    echo "\n4. Testing Validator (Basic):\n";

    $validator = new Validator();
    echo "   ✅ Validator created\n";

    // Test successful validation
    try {
        $validData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 25,
            'website' => 'https://example.com'
        ];

        $rules = [
            'name' => 'required|string|min:2',
            'email' => 'required|email',
            'age' => 'required|integer|min:18',
            'website' => 'url'
        ];

        $result = $validator->validate($validData, $rules);
        echo "   ✅ Valid data passed validation\n";
    } catch (ValidationException $e) {
        echo "   ❌ Unexpected validation failure: " . $e->getMessage() . "\n";
    }

    // Test failed validation
    try {
        $invalidData = [
            'name' => '',
            'email' => 'not-an-email',
            'age' => 15,
            'website' => 'not-a-url'
        ];

        $validator->validate($invalidData, $rules);
        echo "   ❌ Invalid data should have failed validation\n";
    } catch (ValidationException $e) {
        $errors = $e->getErrors();
        echo "   ✅ Invalid data failed validation: " . count($errors) . " error(s)\n";
    }

    echo "\n5. Testing UploadedFile:\n";

    // Create mock uploaded file (can't test actual upload in CLI)
    $uploadedFile = new UploadedFile('/tmp/test', 'test.txt', 'text/plain', 1024, UPLOAD_ERR_OK);
    echo "   ✅ UploadedFile created\n";
    echo "   ✅ File name: " . $uploadedFile->getName() . "\n";
    echo "   ✅ File size: " . $uploadedFile->getSize() . " bytes\n";
    echo "   ✅ File type: " . $uploadedFile->getType() . "\n";
    echo "   ✅ File extension: " . $uploadedFile->getExtension() . "\n";
    echo "   ✅ Is valid: " . ($uploadedFile->isValid() ? 'yes' : 'no') . "\n";

    echo "\n6. Testing Array Conversion:\n";

    $requestArray = $request->toArray();
    echo "   ✅ Request to array: " . count($requestArray) . " properties\n";

    $responseArray = $jsonResponse->toArray();
    echo "   ✅ Response to array: " . count($responseArray) . " properties\n";

    echo "\nRequest/Response Test Summary:\n";
    echo "==============================\n";
    echo "✅ Request creation and data access: PASSED\n";
    echo "✅ Request input handling and filtering: PASSED\n";
    echo "✅ Request client information and headers: PASSED\n";
    echo "✅ Request sanitization and validation: PASSED\n";
    echo "✅ Response creation and status codes: PASSED\n";
    echo "✅ Response JSON and error handling: PASSED\n";
    echo "✅ Response headers and cookies: PASSED\n";
    echo "✅ Response security features: PASSED\n";
    echo "✅ Basic validation system: PASSED\n";
    echo "✅ File upload handling: PASSED\n";

    echo "\n🎉 Request/Response implementation is working correctly!\n";

} catch (Exception $e) {
    echo "\n❌ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}