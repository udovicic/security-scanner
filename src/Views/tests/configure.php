<?php
$title = 'Configure Security Tests - Security Scanner';
$metaDescription = 'Configure and customize security tests for website monitoring';
$currentPage = 'tests';

// Available security tests with metadata
$availableTests = [
    [
        'id' => 'ssl_certificate',
        'name' => 'SSL Certificate Check',
        'description' => 'Validates SSL certificate validity, expiration, and security',
        'category' => 'security',
        'icon' => 'shield-check',
        'difficulty' => 'basic',
        'execution_time' => '2-5 seconds',
        'enabled' => true
    ],
    [
        'id' => 'security_headers',
        'name' => 'HTTP Security Headers',
        'description' => 'Checks for proper security headers (HSTS, CSP, X-Frame-Options)',
        'category' => 'security',
        'icon' => 'lock-closed',
        'difficulty' => 'basic',
        'execution_time' => '1-3 seconds',
        'enabled' => true
    ],
    [
        'id' => 'response_time',
        'name' => 'Response Time Check',
        'description' => 'Monitors page load performance and response times',
        'category' => 'performance',
        'icon' => 'clock',
        'difficulty' => 'basic',
        'execution_time' => '1-2 seconds',
        'enabled' => true
    ],
    [
        'id' => 'broken_links',
        'name' => 'Broken Links Scanner',
        'description' => 'Scans for broken internal and external links',
        'category' => 'content',
        'icon' => 'link',
        'difficulty' => 'intermediate',
        'execution_time' => '10-30 seconds',
        'enabled' => true
    ],
    [
        'id' => 'xss_detection',
        'name' => 'XSS Vulnerability Scanner',
        'description' => 'Detects potential Cross-Site Scripting vulnerabilities',
        'category' => 'security',
        'icon' => 'exclamation-triangle',
        'difficulty' => 'advanced',
        'execution_time' => '30-60 seconds',
        'enabled' => true
    ],
    [
        'id' => 'sql_injection',
        'name' => 'SQL Injection Scanner',
        'description' => 'Tests for SQL injection vulnerabilities in forms',
        'category' => 'security',
        'icon' => 'database',
        'difficulty' => 'advanced',
        'execution_time' => '45-90 seconds',
        'enabled' => true
    ],
    [
        'id' => 'directory_traversal',
        'name' => 'Directory Traversal Check',
        'description' => 'Tests for directory traversal vulnerabilities',
        'category' => 'security',
        'icon' => 'folder-open',
        'difficulty' => 'intermediate',
        'execution_time' => '15-30 seconds',
        'enabled' => true
    ],
    [
        'id' => 'content_security',
        'name' => 'Content Security Policy',
        'description' => 'Validates CSP headers and configuration',
        'category' => 'security',
        'icon' => 'document-text',
        'difficulty' => 'intermediate',
        'execution_time' => '3-8 seconds',
        'enabled' => true
    ]
];

// Current test configuration (would come from database)
$currentConfig = [
    'ssl_certificate',
    'security_headers',
    'response_time'
];
?>

<!-- Page Header -->
<div class="bg-white dark:bg-secondary-800 shadow-sm border-b border-secondary-200 dark:border-secondary-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
            <div>
                <h1 class="text-2xl lg:text-3xl font-bold text-secondary-900 dark:text-white">
                    Configure Security Tests
                </h1>
                <p class="mt-2 text-sm lg:text-base text-secondary-600 dark:text-secondary-400">
                    Drag and drop tests to customize your security scanning configuration
                </p>
            </div>
            <div class="flex flex-col sm:flex-row gap-3">
                <button onclick="previewConfiguration()" class="btn btn-secondary">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    Preview Config
                </button>
                <button onclick="saveConfiguration()" class="btn btn-primary">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Save Configuration
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Test Configuration Interface -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div x-data="testConfiguration(<?= htmlspecialchars(json_encode($availableTests)) ?>, <?= htmlspecialchars(json_encode($currentConfig)) ?>)" class="space-y-8">

        <!-- Filter and Search -->
        <div class="flex flex-col lg:flex-row lg:items-center gap-4">
            <div class="flex-1">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-secondary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    <input type="text"
                           x-model="searchTerm"
                           placeholder="Search security tests..."
                           class="form-input pl-10 pr-4 py-2 w-full">
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                <select x-model="filterCategory" class="form-input py-2">
                    <option value="">All Categories</option>
                    <option value="security">Security</option>
                    <option value="performance">Performance</option>
                    <option value="content">Content</option>
                </select>

                <select x-model="filterDifficulty" class="form-input py-2">
                    <option value="">All Levels</option>
                    <option value="basic">Basic</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                </select>

                <!-- Bulk Actions -->
                <div x-data="{ bulkOpen: false }" class="relative">
                    <button @click="bulkOpen = !bulkOpen"
                            class="btn btn-outline flex items-center"
                            :aria-expanded="bulkOpen">
                        <span>Quick Add</span>
                        <svg class="ml-2 h-4 w-4 transition-transform" :class="{ 'rotate-180': bulkOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>

                    <div x-show="bulkOpen"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         @click.away="bulkOpen = false"
                         class="absolute right-0 mt-2 w-48 bg-white dark:bg-secondary-800 rounded-lg shadow-lg border border-secondary-200 dark:border-secondary-700 z-20">
                        <div class="p-2">
                            <button @click="addAllBasicTests(); bulkOpen = false"
                                    class="w-full text-left px-3 py-2 text-sm text-secondary-700 dark:text-secondary-300 hover:bg-secondary-100 dark:hover:bg-secondary-700 rounded-md">
                                Add All Basic Tests
                            </button>
                            <button @click="addAllSecurityTests(); bulkOpen = false"
                                    class="w-full text-left px-3 py-2 text-sm text-secondary-700 dark:text-secondary-300 hover:bg-secondary-100 dark:hover:bg-secondary-700 rounded-md">
                                Add All Security Tests
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Drag and Drop Interface -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

            <!-- Available Tests -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-secondary-900 dark:text-white">Available Tests</h2>
                    <span class="text-sm text-secondary-500 dark:text-secondary-400" x-text="filteredAvailableTests.length + ' tests'"></span>
                </div>

                <div id="available-tests"
                     class="min-h-96 p-4 bg-secondary-50 dark:bg-secondary-800/50 border-2 border-dashed border-secondary-300 dark:border-secondary-600 rounded-lg">

                    <template x-for="test in filteredAvailableTests" :key="test.id">
                        <div :id="'test-' + test.id"
                             draggable="true"
                             tabindex="0"
                             role="button"
                             :aria-label="'Add ' + test.name + ' to configuration. ' + test.description"
                             @dragstart="dragStart($event, test)"
                             @dragend="dragEnd($event)"
                             @keydown="handleKeyDown($event, test, false)"
                             @click="addTest(test)"
                             class="test-card available-test mb-3 p-4 bg-white dark:bg-secondary-800 border border-secondary-200 dark:border-secondary-700 rounded-lg cursor-pointer hover:shadow-md focus:ring-2 focus:ring-primary-500 focus:outline-none transition-all duration-200">

                            <div class="flex items-start space-x-3">
                                <div class="flex-shrink-0">
                                    <div class="w-10 h-10 rounded-lg flex items-center justify-center"
                                         :class="{
                                             'bg-danger-100 text-danger-600': test.category === 'security',
                                             'bg-info-100 text-info-600': test.category === 'performance',
                                             'bg-warning-100 text-warning-600': test.category === 'content'
                                         }">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <!-- Dynamic icon based on test.icon -->
                                            <path x-show="test.icon === 'shield-check'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                            <path x-show="test.icon === 'lock-closed'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                            <path x-show="test.icon === 'clock'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            <path x-show="test.icon === 'link'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                                            <path x-show="test.icon === 'exclamation-triangle'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                            <path x-show="test.icon === 'database'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                            <path x-show="test.icon === 'folder-open'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"></path>
                                            <path x-show="test.icon === 'document-text'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                    </div>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <h3 class="text-sm font-medium text-secondary-900 dark:text-white" x-text="test.name"></h3>
                                    <p class="text-xs text-secondary-500 dark:text-secondary-400 mt-1" x-text="test.description"></p>

                                    <div class="flex items-center mt-2 space-x-4">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                                              :class="{
                                                  'bg-success-100 text-success-800': test.difficulty === 'basic',
                                                  'bg-warning-100 text-warning-800': test.difficulty === 'intermediate',
                                                  'bg-danger-100 text-danger-800': test.difficulty === 'advanced'
                                              }" x-text="test.difficulty"></span>

                                        <span class="text-xs text-secondary-500 dark:text-secondary-400" x-text="test.execution_time"></span>
                                    </div>
                                </div>

                                <div class="flex-shrink-0">
                                    <svg class="w-5 h-5 text-secondary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div x-show="filteredAvailableTests.length === 0" class="text-center py-8">
                        <svg class="w-12 h-12 mx-auto text-secondary-400 dark:text-secondary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <p class="mt-2 text-sm text-secondary-500 dark:text-secondary-400">No tests match your current filters</p>
                    </div>
                </div>
            </div>

            <!-- Selected Tests -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-secondary-900 dark:text-white">Selected Tests</h2>
                    <div class="flex items-center space-x-4">
                        <span class="text-sm text-secondary-500 dark:text-secondary-400" x-text="selectedTests.length + ' selected'"></span>
                        <button @click="clearAllTests()" class="text-sm text-danger-600 hover:text-danger-700">Clear All</button>
                    </div>
                </div>

                <div id="selected-tests"
                     @dragover.prevent
                     @drop="dropTest($event)"
                     class="min-h-96 p-4 bg-primary-50 dark:bg-primary-900/20 border-2 border-dashed border-primary-300 dark:border-primary-600 rounded-lg">

                    <template x-for="(test, index) in selectedTests" :key="test.id">
                        <div :id="'selected-test-' + test.id"
                             draggable="true"
                             tabindex="0"
                             role="button"
                             :aria-label="'Remove ' + test.name + ' from configuration. Currently at position ' + (index + 1)"
                             @dragstart="dragStart($event, test, true)"
                             @dragend="dragEnd($event)"
                             @keydown="handleKeyDown($event, test, true)"
                             class="test-card selected-test mb-3 p-4 bg-white dark:bg-secondary-800 border border-primary-200 dark:border-primary-700 rounded-lg cursor-move hover:shadow-md focus:ring-2 focus:ring-primary-500 focus:outline-none transition-all duration-200">

                            <div class="flex items-start space-x-3">
                                <div class="flex-shrink-0 flex items-center space-x-2">
                                    <span class="w-6 h-6 bg-primary-100 text-primary-600 dark:bg-primary-800 dark:text-primary-300 rounded-full flex items-center justify-center text-xs font-medium" x-text="index + 1"></span>

                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center"
                                         :class="{
                                             'bg-danger-100 text-danger-600': test.category === 'security',
                                             'bg-info-100 text-info-600': test.category === 'performance',
                                             'bg-warning-100 text-warning-600': test.category === 'content'
                                         }">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <!-- Same dynamic icons as above -->
                                            <path x-show="test.icon === 'shield-check'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                            <path x-show="test.icon === 'lock-closed'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                            <path x-show="test.icon === 'clock'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <h3 class="text-sm font-medium text-secondary-900 dark:text-white" x-text="test.name"></h3>
                                    <p class="text-xs text-secondary-500 dark:text-secondary-400 mt-1" x-text="test.description"></p>
                                </div>

                                <div class="flex-shrink-0 flex items-center space-x-2">
                                    <button @click="removeTest(test.id)" class="text-danger-600 hover:text-danger-700">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>

                                    <svg class="w-4 h-4 text-secondary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div x-show="selectedTests.length === 0" class="text-center py-12">
                        <svg class="w-12 h-12 mx-auto text-primary-400 dark:text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        <h3 class="mt-4 text-sm font-medium text-secondary-900 dark:text-white">No tests selected</h3>
                        <p class="mt-2 text-sm text-secondary-500 dark:text-secondary-400">Drag tests from the left panel to configure your security scanning</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Configuration Summary -->
        <div x-show="selectedTests.length > 0" class="card p-6">
            <h3 class="text-lg font-semibold text-secondary-900 dark:text-white mb-4">Configuration Summary</h3>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                <div class="text-center">
                    <div class="text-2xl font-bold text-primary-600 dark:text-primary-400" x-text="selectedTests.length"></div>
                    <div class="text-sm text-secondary-500 dark:text-secondary-400">Total Tests</div>
                </div>

                <div class="text-center">
                    <div class="text-2xl font-bold text-info-600 dark:text-info-400" x-text="estimatedTime"></div>
                    <div class="text-sm text-secondary-500 dark:text-secondary-400">Estimated Time</div>
                </div>

                <div class="text-center">
                    <div class="text-2xl font-bold text-success-600 dark:text-success-400" x-text="coverageScore + '%'"></div>
                    <div class="text-sm text-secondary-500 dark:text-secondary-400">Security Coverage</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function testConfiguration(availableTests, currentConfig) {
    return {
        availableTests: availableTests,
        selectedTests: [],
        searchTerm: '',
        filterCategory: '',
        filterDifficulty: '',
        draggedTest: null,
        isFromSelected: false,
        dragOverZone: null,

        init() {
            // Initialize with current configuration
            this.selectedTests = availableTests.filter(test => currentConfig.includes(test.id));
        },

        get filteredAvailableTests() {
            return this.availableTests.filter(test => {
                // Don't show already selected tests
                if (this.selectedTests.find(selected => selected.id === test.id)) {
                    return false;
                }

                // Apply search filter
                if (this.searchTerm && !test.name.toLowerCase().includes(this.searchTerm.toLowerCase()) &&
                    !test.description.toLowerCase().includes(this.searchTerm.toLowerCase())) {
                    return false;
                }

                // Apply category filter
                if (this.filterCategory && test.category !== this.filterCategory) {
                    return false;
                }

                // Apply difficulty filter
                if (this.filterDifficulty && test.difficulty !== this.filterDifficulty) {
                    return false;
                }

                return true;
            });
        },

        get estimatedTime() {
            if (this.selectedTests.length === 0) return '0 sec';

            let totalSeconds = 0;
            this.selectedTests.forEach(test => {
                // Parse execution time (e.g., "2-5 seconds" -> average 3.5)
                const timeStr = test.execution_time;
                const match = timeStr.match(/(\d+)-(\d+)\s*seconds?/);
                if (match) {
                    const min = parseInt(match[1]);
                    const max = parseInt(match[2]);
                    totalSeconds += (min + max) / 2;
                }
            });

            if (totalSeconds < 60) {
                return Math.round(totalSeconds) + ' sec';
            } else {
                return Math.round(totalSeconds / 60 * 10) / 10 + ' min';
            }
        },

        get coverageScore() {
            if (this.selectedTests.length === 0) return 0;

            const maxTests = this.availableTests.length;
            const securityTests = this.selectedTests.filter(test => test.category === 'security').length;
            const maxSecurityTests = this.availableTests.filter(test => test.category === 'security').length;

            // Weight security tests more heavily
            const securityWeight = 0.7;
            const generalWeight = 0.3;

            const securityScore = (securityTests / maxSecurityTests) * securityWeight * 100;
            const generalScore = (this.selectedTests.length / maxTests) * generalWeight * 100;

            return Math.round(securityScore + generalScore);
        },

        dragStart(event, test, fromSelected = false) {
            this.draggedTest = test;
            this.isFromSelected = fromSelected;
            event.dataTransfer.effectAllowed = 'move';
            event.target.classList.add('opacity-50');
        },

        dragEnd(event) {
            event.target.classList.remove('opacity-50');
            this.draggedTest = null;
            this.isFromSelected = false;
        },

        handleDragOver(event, zone) {
            event.preventDefault();
            this.dragOverZone = zone;
            event.dataTransfer.dropEffect = 'move';
        },

        handleDragLeave(event, zone) {
            // Only clear if we're leaving the zone completely
            if (!event.currentTarget.contains(event.relatedTarget)) {
                this.dragOverZone = null;
            }
        },

        handleDrop(event, zone) {
            event.preventDefault();
            this.dragOverZone = null;

            if (!this.draggedTest) return;

            if (zone === 'selected') {
                if (this.isFromSelected) {
                    // Reordering within selected tests
                    const draggedIndex = this.selectedTests.findIndex(test => test.id === this.draggedTest.id);
                    const dropTarget = event.target.closest('.selected-test');

                    if (dropTarget) {
                        const dropIndex = Array.from(dropTarget.parentNode.children).indexOf(dropTarget);
                        if (draggedIndex !== dropIndex && draggedIndex !== -1) {
                            // Reorder the array
                            this.selectedTests.splice(dropIndex, 0, this.selectedTests.splice(draggedIndex, 1)[0]);
                        }
                    }
                } else {
                    // Adding from available tests
                    if (!this.selectedTests.find(test => test.id === this.draggedTest.id)) {
                        this.selectedTests.push(this.draggedTest);
                        // Show success feedback
                        window.notify?.success(`Added "${this.draggedTest.name}" to configuration`);
                    }
                }
            } else if (zone === 'available' && this.isFromSelected) {
                // Removing from selected tests
                this.removeTest(this.draggedTest.id);
                window.notify?.info(`Removed "${this.draggedTest.name}" from configuration`);
            }
        },

        dropTest(event) {
            // Fallback for the selected tests area
            this.handleDrop(event, 'selected');
        },

        removeTest(testId) {
            this.selectedTests = this.selectedTests.filter(test => test.id !== testId);
        },

        clearAllTests() {
            if (confirm('Are you sure you want to remove all selected tests?')) {
                this.selectedTests = [];
            }
        },

        // Keyboard navigation support
        handleKeyDown(event, test, isSelected = false) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if (isSelected) {
                    this.removeTest(test.id);
                } else {
                    this.addTest(test);
                }
            } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                event.preventDefault();
                this.navigateTests(event.key === 'ArrowUp' ? -1 : 1, isSelected);
            }
        },

        navigateTests(direction, isSelected) {
            const cards = isSelected ?
                document.querySelectorAll('.selected-test') :
                document.querySelectorAll('.available-test');

            const currentIndex = Array.from(cards).findIndex(card => card === document.activeElement);
            const nextIndex = Math.max(0, Math.min(cards.length - 1, currentIndex + direction));

            if (cards[nextIndex]) {
                cards[nextIndex].focus();
            }
        },

        addTest(test) {
            if (!this.selectedTests.find(selected => selected.id === test.id)) {
                this.selectedTests.push(test);
                window.notify?.success(`Added "${test.name}" to configuration`);

                // Focus the newly added test in the selected area
                this.$nextTick(() => {
                    const selectedCards = document.querySelectorAll('.selected-test');
                    const lastCard = selectedCards[selectedCards.length - 1];
                    if (lastCard) lastCard.focus();
                });
            }
        },

        getConfiguration() {
            return {
                tests: this.selectedTests.map(test => test.id),
                estimatedTime: this.estimatedTime,
                coverageScore: this.coverageScore,
                summary: {
                    totalTests: this.selectedTests.length,
                    securityTests: this.selectedTests.filter(test => test.category === 'security').length,
                    performanceTests: this.selectedTests.filter(test => test.category === 'performance').length,
                    contentTests: this.selectedTests.filter(test => test.category === 'content').length
                }
            };
        },

        // Bulk operations
        addAllBasicTests() {
            const basicTests = this.availableTests.filter(test =>
                test.difficulty === 'basic' &&
                !this.selectedTests.find(selected => selected.id === test.id)
            );

            this.selectedTests.push(...basicTests);
            window.notify?.success(`Added ${basicTests.length} basic tests to configuration`);
        },

        addAllSecurityTests() {
            const securityTests = this.availableTests.filter(test =>
                test.category === 'security' &&
                !this.selectedTests.find(selected => selected.id === test.id)
            );

            this.selectedTests.push(...securityTests);
            window.notify?.success(`Added ${securityTests.length} security tests to configuration`);
        }
    };
}

function previewConfiguration() {
    const config = Alpine.store('testConfig') || window.testConfigData;
    if (!config) return;

    window.notify.info('Opening configuration preview...');

    // This would open a modal or navigate to a preview page
    console.log('Preview Configuration:', config.getConfiguration());
}

function saveConfiguration() {
    const config = Alpine.store('testConfig') || window.testConfigData;
    if (!config) return;

    const configuration = config.getConfiguration();

    if (configuration.tests.length === 0) {
        window.notify.warning('Please select at least one test before saving');
        return;
    }

    window.ajax.post('/api/tests/configuration', configuration)
        .then(response => {
            if (response.success) {
                window.notify.success('Test configuration saved successfully!');
            } else {
                window.notify.error('Failed to save configuration: ' + (response.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Save configuration error:', error);
            window.notify.error('Failed to save configuration due to a network error');
        });
}

// Store reference for global access
document.addEventListener('alpine:init', () => {
    window.testConfigData = null;

    document.addEventListener('DOMContentLoaded', function() {
        // Find the Alpine component and store reference
        setTimeout(() => {
            const configElement = document.querySelector('[x-data*="testConfiguration"]');
            if (configElement && configElement._x_dataStack) {
                window.testConfigData = configElement._x_dataStack[0];
            }
        }, 100);
    });
});
</script>