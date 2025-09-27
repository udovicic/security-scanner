<?php
$title = 'Scan Results - Security Scanner';
$metaDescription = 'Detailed security scan results with expandable test details and recommendations';
$currentPage = 'results';

// Sample scan result data (would come from database)
$scanResult = [
    'id' => 'scan_12345',
    'website_id' => 1,
    'website_name' => 'Example Website',
    'website_url' => 'https://example.com',
    'scan_date' => '2024-01-15 14:30:00',
    'status' => 'completed',
    'overall_score' => 78,
    'total_tests' => 8,
    'passed_tests' => 6,
    'failed_tests' => 1,
    'warning_tests' => 1,
    'execution_time' => 45.2,
    'scan_type' => 'full_security',
    'triggered_by' => 'manual'
];

$testResults = [
    [
        'id' => 'ssl_certificate',
        'name' => 'SSL Certificate Check',
        'status' => 'passed',
        'score' => 100,
        'execution_time' => 2.1,
        'description' => 'Validates SSL certificate validity, expiration, and security configuration',
        'category' => 'security',
        'severity' => 'high',
        'details' => [
            'certificate_valid' => true,
            'certificate_expires' => '2024-12-15',
            'days_until_expiry' => 334,
            'issuer' => 'Let\'s Encrypt Authority X3',
            'protocol_version' => 'TLSv1.3',
            'cipher_suite' => 'TLS_AES_256_GCM_SHA384',
            'certificate_chain_valid' => true,
            'certificate_transparency' => true
        ],
        'recommendations' => [
            'Certificate is valid and properly configured',
            'Consider setting up automated renewal notifications'
        ],
        'technical_details' => [
            'Subject' => 'CN=example.com',
            'Serial Number' => '03:A7:B2:4F:1D:E8:9C:2A',
            'Signature Algorithm' => 'SHA256withRSA',
            'Key Size' => '2048 bits'
        ]
    ],
    [
        'id' => 'security_headers',
        'name' => 'HTTP Security Headers',
        'status' => 'warning',
        'score' => 75,
        'execution_time' => 1.8,
        'description' => 'Checks for proper security headers implementation',
        'category' => 'security',
        'severity' => 'medium',
        'details' => [
            'headers_found' => [
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'X-XSS-Protection' => '1; mode=block'
            ],
            'headers_missing' => [
                'Strict-Transport-Security',
                'Content-Security-Policy',
                'Referrer-Policy'
            ],
            'headers_total' => 6,
            'headers_implemented' => 3
        ],
        'recommendations' => [
            'Implement missing security headers for better protection',
            'Add Content Security Policy to prevent XSS attacks',
            'Enable HSTS for HTTPS enforcement'
        ],
        'technical_details' => [
            'Missing HSTS' => 'Prevents HTTPS downgrade attacks',
            'Missing CSP' => 'Helps prevent XSS and data injection',
            'Missing Referrer-Policy' => 'Controls referrer information'
        ]
    ],
    [
        'id' => 'response_time',
        'name' => 'Response Time Check',
        'status' => 'passed',
        'score' => 85,
        'execution_time' => 1.2,
        'description' => 'Monitors page load performance and response times',
        'category' => 'performance',
        'severity' => 'low',
        'details' => [
            'average_response_time' => 245,
            'fastest_response' => 198,
            'slowest_response' => 312,
            'total_requests' => 5,
            'timeouts' => 0,
            'location_tested' => 'US East'
        ],
        'recommendations' => [
            'Response times are within acceptable range',
            'Consider implementing CDN for global performance'
        ],
        'technical_details' => [
            'DNS Lookup Time' => '15ms',
            'Connection Time' => '45ms',
            'SSL Handshake' => '78ms',
            'Server Response' => '107ms'
        ]
    ],
    [
        'id' => 'xss_detection',
        'name' => 'XSS Vulnerability Scanner',
        'status' => 'failed',
        'score' => 25,
        'execution_time' => 12.5,
        'description' => 'Detects potential Cross-Site Scripting vulnerabilities',
        'category' => 'security',
        'severity' => 'critical',
        'details' => [
            'vulnerabilities_found' => 2,
            'forms_tested' => 3,
            'input_fields_tested' => 8,
            'reflection_points' => [
                '/search?q=<script>',
                '/contact?name=<img src=x onerror=alert(1)>'
            ],
            'risk_level' => 'high'
        ],
        'recommendations' => [
            'URGENT: Fix XSS vulnerabilities immediately',
            'Implement proper input validation and sanitization',
            'Use Content Security Policy headers',
            'Consider using a Web Application Firewall'
        ],
        'technical_details' => [
            'Vulnerability Type' => 'Reflected XSS',
            'Attack Vector' => 'User input reflection',
            'Affected Parameters' => 'q, name',
            'OWASP Category' => 'A03:2021 – Injection'
        ]
    ]
];
?>

<!-- Page Header -->
<div class="bg-white dark:bg-secondary-800 shadow-sm border-b border-secondary-200 dark:border-secondary-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between space-y-4 lg:space-y-0">
            <div class="flex-1">
                <div class="flex items-center space-x-3 mb-2">
                    <h1 class="text-2xl lg:text-3xl font-bold text-secondary-900 dark:text-white">
                        Security Scan Results
                    </h1>
                    <span class="status-badge status-<?= $scanResult['status'] === 'completed' ? 'success' : ($scanResult['status'] === 'failed' ? 'danger' : 'warning') ?>">
                        <?= ucfirst($scanResult['status']) ?>
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-4 text-sm text-secondary-600 dark:text-secondary-400">
                    <span class="flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9v-9m0-9v9"></path>
                        </svg>
                        <?= htmlspecialchars($scanResult['website_name']) ?>
                    </span>
                    <span class="flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <?= date('M j, Y \a\t g:i A', strtotime($scanResult['scan_date'])) ?>
                    </span>
                    <span class="flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        <?= number_format($scanResult['execution_time'], 1) ?>s execution
                    </span>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-3">
                <button onclick="exportResults()" class="btn btn-outline">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Export Report
                </button>
                <button onclick="rescanWebsite()" class="btn btn-primary">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Run New Scan
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Results Overview -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Overall Score -->
        <div class="card bg-gradient-to-br from-primary-50 to-primary-100 dark:from-primary-900/20 dark:to-primary-800/20 border-primary-200 dark:border-primary-800">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-primary-600 dark:text-primary-400">Overall Score</p>
                    <p class="text-3xl font-bold text-primary-900 dark:text-primary-100">
                        <?= $scanResult['overall_score'] ?>%
                    </p>
                    <p class="text-xs text-primary-700 dark:text-primary-300 mt-1">
                        <?= $scanResult['overall_score'] >= 80 ? 'Excellent' : ($scanResult['overall_score'] >= 60 ? 'Good' : 'Needs Improvement') ?>
                    </p>
                </div>
                <div class="w-16 h-16 relative">
                    <svg class="w-16 h-16 transform -rotate-90" viewBox="0 0 36 36">
                        <path class="text-primary-200 dark:text-primary-800" stroke="currentColor" stroke-width="3" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"></path>
                        <path class="text-primary-600" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="<?= $scanResult['overall_score'] ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"></path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Tests Passed -->
        <div class="card bg-gradient-to-br from-success-50 to-success-100 dark:from-success-900/20 dark:to-success-800/20 border-success-200 dark:border-success-800">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-success-600 dark:text-success-400">Tests Passed</p>
                    <p class="text-3xl font-bold text-success-900 dark:text-success-100">
                        <?= $scanResult['passed_tests'] ?>
                    </p>
                    <p class="text-xs text-success-700 dark:text-success-300 mt-1">
                        out of <?= $scanResult['total_tests'] ?> tests
                    </p>
                </div>
                <div class="p-3 bg-success-500 dark:bg-success-600 rounded-full">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Tests Failed -->
        <div class="card bg-gradient-to-br from-danger-50 to-danger-100 dark:from-danger-900/20 dark:to-danger-800/20 border-danger-200 dark:border-danger-800">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-danger-600 dark:text-danger-400">Tests Failed</p>
                    <p class="text-3xl font-bold text-danger-900 dark:text-danger-100">
                        <?= $scanResult['failed_tests'] ?>
                    </p>
                    <p class="text-xs text-danger-700 dark:text-danger-300 mt-1">
                        require attention
                    </p>
                </div>
                <div class="p-3 bg-danger-500 dark:bg-danger-600 rounded-full">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Warnings -->
        <div class="card bg-gradient-to-br from-warning-50 to-warning-100 dark:from-warning-900/20 dark:to-warning-800/20 border-warning-200 dark:border-warning-800">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-warning-600 dark:text-warning-400">Warnings</p>
                    <p class="text-3xl font-bold text-warning-900 dark:text-warning-100">
                        <?= $scanResult['warning_tests'] ?>
                    </p>
                    <p class="text-xs text-warning-700 dark:text-warning-300 mt-1">
                        improvements suggested
                    </p>
                </div>
                <div class="p-3 bg-warning-500 dark:bg-warning-600 rounded-full">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Test Results -->
    <div x-data="resultDetails(<?= htmlspecialchars(json_encode($testResults)) ?>)" class="space-y-6">
        <!-- Filter and Sort Controls -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
            <div class="flex flex-wrap gap-3">
                <button @click="filterStatus = 'all'"
                        :class="filterStatus === 'all' ? 'btn-primary' : 'btn-outline'"
                        class="btn btn-sm">
                    All Tests (<?= count($testResults) ?>)
                </button>
                <button @click="filterStatus = 'failed'"
                        :class="filterStatus === 'failed' ? 'btn-danger' : 'btn-outline'"
                        class="btn btn-sm">
                    Failed (<?= $scanResult['failed_tests'] ?>)
                </button>
                <button @click="filterStatus = 'warning'"
                        :class="filterStatus === 'warning' ? 'btn-warning' : 'btn-outline'"
                        class="btn btn-sm">
                    Warnings (<?= $scanResult['warning_tests'] ?>)
                </button>
                <button @click="filterStatus = 'passed'"
                        :class="filterStatus === 'passed' ? 'btn-success' : 'btn-outline'"
                        class="btn btn-sm">
                    Passed (<?= $scanResult['passed_tests'] ?>)
                </button>
            </div>

            <div class="flex items-center gap-3">
                <label class="text-sm text-secondary-600 dark:text-secondary-400">Sort by:</label>
                <select x-model="sortBy" class="form-input py-1 text-sm">
                    <option value="severity">Severity</option>
                    <option value="status">Status</option>
                    <option value="score">Score</option>
                    <option value="name">Name</option>
                </select>
            </div>
        </div>

        <!-- Test Results List -->
        <div class="space-y-4">
            <template x-for="(test, index) in filteredResults" :key="test.id">
                <div class="card overflow-hidden">
                    <!-- Test Header -->
                    <div class="p-6 border-b border-secondary-200 dark:border-secondary-700 cursor-pointer"
                         @click="toggleDetails(test.id)"
                         :class="{
                             'bg-danger-50 dark:bg-danger-900/10': test.status === 'failed',
                             'bg-warning-50 dark:bg-warning-900/10': test.status === 'warning',
                             'bg-success-50 dark:bg-success-900/10': test.status === 'passed'
                         }">

                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-4">
                                <!-- Status Icon -->
                                <div class="flex-shrink-0">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center"
                                         :class="{
                                             'bg-danger-100 text-danger-600': test.status === 'failed',
                                             'bg-warning-100 text-warning-600': test.status === 'warning',
                                             'bg-success-100 text-success-600': test.status === 'passed'
                                         }">
                                        <!-- Failed Icon -->
                                        <svg x-show="test.status === 'failed'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                        <!-- Warning Icon -->
                                        <svg x-show="test.status === 'warning'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                        </svg>
                                        <!-- Success Icon -->
                                        <svg x-show="test.status === 'passed'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </div>
                                </div>

                                <!-- Test Info -->
                                <div class="flex-1">
                                    <h3 class="text-lg font-semibold text-secondary-900 dark:text-white" x-text="test.name"></h3>
                                    <p class="text-sm text-secondary-600 dark:text-secondary-400 mt-1" x-text="test.description"></p>

                                    <div class="flex items-center mt-2 space-x-4">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                                              :class="{
                                                  'bg-danger-100 text-danger-800': test.severity === 'critical',
                                                  'bg-warning-100 text-warning-800': test.severity === 'high',
                                                  'bg-info-100 text-info-800': test.severity === 'medium',
                                                  'bg-secondary-100 text-secondary-800': test.severity === 'low'
                                              }" x-text="test.severity + ' severity'"></span>

                                        <span class="text-xs text-secondary-500 dark:text-secondary-400" x-text="test.execution_time + 's'"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Score and Expand Button -->
                            <div class="flex items-center space-x-4">
                                <div class="text-right">
                                    <div class="text-2xl font-bold"
                                         :class="{
                                             'text-danger-600': test.score < 50,
                                             'text-warning-600': test.score >= 50 && test.score < 80,
                                             'text-success-600': test.score >= 80
                                         }" x-text="test.score + '%'"></div>
                                    <div class="text-xs text-secondary-500 dark:text-secondary-400">Score</div>
                                </div>

                                <button class="text-secondary-400 hover:text-secondary-600 dark:hover:text-secondary-300 transition-colors">
                                    <svg class="w-5 h-5 transition-transform" :class="{ 'rotate-180': expandedTests.includes(test.id) }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Expandable Details -->
                    <div x-show="expandedTests.includes(test.id)"
                         x-transition:enter="transition ease-out duration-300"
                         x-transition:enter-start="opacity-0 transform -translate-y-2"
                         x-transition:enter-end="opacity-100 transform translate-y-0"
                         x-transition:leave="transition ease-in duration-200"
                         x-transition:leave-start="opacity-100 transform translate-y-0"
                         x-transition:leave-end="opacity-0 transform -translate-y-2">

                        <div class="border-t border-secondary-200 dark:border-secondary-700">
                            <!-- Tab Navigation -->
                            <div x-data="{ activeTab: 'details' }" class="bg-secondary-50 dark:bg-secondary-800/50">
                                <nav class="flex space-x-8 px-6 py-3">
                                    <button @click="activeTab = 'details'"
                                            :class="activeTab === 'details' ? 'border-primary-500 text-primary-600' : 'border-transparent text-secondary-500 hover:text-secondary-700'"
                                            class="py-2 px-1 border-b-2 font-medium text-sm transition-colors">
                                        Test Details
                                    </button>
                                    <button @click="activeTab = 'recommendations'"
                                            :class="activeTab === 'recommendations' ? 'border-primary-500 text-primary-600' : 'border-transparent text-secondary-500 hover:text-secondary-700'"
                                            class="py-2 px-1 border-b-2 font-medium text-sm transition-colors">
                                        Recommendations
                                    </button>
                                    <button @click="activeTab = 'technical'"
                                            :class="activeTab === 'technical' ? 'border-primary-500 text-primary-600' : 'border-transparent text-secondary-500 hover:text-secondary-700'"
                                            class="py-2 px-1 border-b-2 font-medium text-sm transition-colors">
                                        Technical Details
                                    </button>
                                </nav>

                                <!-- Tab Content -->
                                <div class="p-6">
                                    <!-- Details Tab -->
                                    <div x-show="activeTab === 'details'" class="space-y-4">
                                        <template x-for="(value, key) in test.details" :key="key">
                                            <div class="flex justify-between py-2 border-b border-secondary-200 dark:border-secondary-700 last:border-0">
                                                <span class="text-sm font-medium text-secondary-700 dark:text-secondary-300" x-text="formatKey(key)"></span>
                                                <span class="text-sm text-secondary-900 dark:text-white" x-text="formatValue(value)"></span>
                                            </div>
                                        </template>
                                    </div>

                                    <!-- Recommendations Tab -->
                                    <div x-show="activeTab === 'recommendations'" class="space-y-3">
                                        <template x-for="(recommendation, recIndex) in test.recommendations" :key="recIndex">
                                            <div class="flex items-start space-x-3 p-3 rounded-lg"
                                                 :class="{
                                                     'bg-danger-50 dark:bg-danger-900/10': test.status === 'failed',
                                                     'bg-warning-50 dark:bg-warning-900/10': test.status === 'warning',
                                                     'bg-success-50 dark:bg-success-900/10': test.status === 'passed'
                                                 }">
                                                <svg class="w-5 h-5 mt-0.5 flex-shrink-0"
                                                     :class="{
                                                         'text-danger-500': test.status === 'failed',
                                                         'text-warning-500': test.status === 'warning',
                                                         'text-success-500': test.status === 'passed'
                                                     }"
                                                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <p class="text-sm text-secondary-900 dark:text-white" x-text="recommendation"></p>
                                            </div>
                                        </template>
                                    </div>

                                    <!-- Technical Details Tab -->
                                    <div x-show="activeTab === 'technical'" class="space-y-4">
                                        <template x-for="(value, key) in test.technical_details" :key="key">
                                            <div class="flex justify-between py-2 border-b border-secondary-200 dark:border-secondary-700 last:border-0">
                                                <span class="text-sm font-medium text-secondary-700 dark:text-secondary-300" x-text="key"></span>
                                                <span class="text-sm text-secondary-900 dark:text-white font-mono" x-text="value"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function resultDetails(testResults) {
    return {
        results: testResults,
        expandedTests: [],
        filterStatus: 'all',
        sortBy: 'severity',

        get filteredResults() {
            let filtered = this.results;

            // Apply status filter
            if (this.filterStatus !== 'all') {
                filtered = filtered.filter(test => test.status === this.filterStatus);
            }

            // Apply sorting
            return filtered.sort((a, b) => {
                switch (this.sortBy) {
                    case 'severity':
                        const severityOrder = { 'critical': 0, 'high': 1, 'medium': 2, 'low': 3 };
                        return severityOrder[a.severity] - severityOrder[b.severity];
                    case 'status':
                        const statusOrder = { 'failed': 0, 'warning': 1, 'passed': 2 };
                        return statusOrder[a.status] - statusOrder[b.status];
                    case 'score':
                        return a.score - b.score;
                    case 'name':
                        return a.name.localeCompare(b.name);
                    default:
                        return 0;
                }
            });
        },

        toggleDetails(testId) {
            if (this.expandedTests.includes(testId)) {
                this.expandedTests = this.expandedTests.filter(id => id !== testId);
            } else {
                this.expandedTests.push(testId);
            }
        },

        formatKey(key) {
            return key.replace(/_/g, ' ')
                     .replace(/\b\w/g, l => l.toUpperCase());
        },

        formatValue(value) {
            if (typeof value === 'boolean') {
                return value ? 'Yes' : 'No';
            }
            if (Array.isArray(value)) {
                return value.join(', ');
            }
            return value.toString();
        }
    };
}

function exportResults() {
    window.ajax.get('/api/results/<?= $scanResult['id'] ?>/export')
        .then(data => {
            if (data.success) {
                // Create and trigger download
                const link = document.createElement('a');
                link.href = data.download_url;
                link.download = `security-scan-${<?= $scanResult['id'] ?>}.pdf`;
                link.click();
                window.notify.success('Report exported successfully');
            } else {
                window.notify.error('Failed to export report: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Export error:', error);
            window.notify.error('Failed to export report due to a network error');
        });
}

function rescanWebsite() {
    if (!confirm('Start a new security scan for this website?')) {
        return;
    }

    window.ajax.post('/api/websites/<?= $scanResult['website_id'] ?>/scan')
        .then(data => {
            if (data.success) {
                window.notify.success('New scan started successfully!');
                setTimeout(() => {
                    window.location.href = '/scans/' + data.scan_id;
                }, 2000);
            } else {
                window.notify.error('Failed to start scan: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Rescan error:', error);
            window.notify.error('Failed to start scan due to a network error');
        });
}
</script>