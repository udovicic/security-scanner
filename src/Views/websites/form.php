<?php
$title = ($mode === 'edit' ? 'Edit Website' : 'Add Website') . ' - Security Scanner';
$metaDescription = 'Add or edit website configuration for security scanning';
$currentPage = 'websites';

// Default values for new websites
$website = $website ?? [
    'name' => '',
    'url' => '',
    'category' => 'other',
    'priority' => 'medium',
    'scan_frequency' => 'daily',
    'description' => '',
    'active' => 1,
    'max_retries' => 3,
    'scan_timeout' => 300,
    'notification_email' => '',
    'custom_headers' => '',
    'auth_type' => 'none',
    'auth_username' => '',
    'auth_password' => '',
    'ssl_check_enabled' => 1,
    'follow_redirects' => 1,
    'check_security_headers' => 1,
    'check_ssl_certificate' => 1,
    'check_response_time' => 1
];

$isEdit = $mode === 'edit';
$formAction = $isEdit ? "/websites/{$website['id']}" : "/websites";
$submitMethod = $isEdit ? 'PUT' : 'POST';
?>

<!-- Page Header -->
<div class="bg-white dark:bg-secondary-800 shadow-sm border-b border-secondary-200 dark:border-secondary-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
            <div>
                <h1 class="text-2xl lg:text-3xl font-bold text-secondary-900 dark:text-white">
                    <?= $isEdit ? 'Edit Website' : 'Add New Website' ?>
                </h1>
                <p class="mt-2 text-sm lg:text-base text-secondary-600 dark:text-secondary-400">
                    <?= $isEdit ? 'Update website configuration and security scan settings' : 'Configure a new website for security monitoring' ?>
                </p>
            </div>
            <div class="flex flex-col sm:flex-row gap-3">
                <a href="/websites" class="btn btn-outline">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    Back to Websites
                </a>
                <?php if ($isEdit): ?>
                <button type="button" onclick="testWebsite()" class="btn btn-secondary">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Test Connection
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Form Container -->
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <form id="website-form"
          action="<?= htmlspecialchars($formAction) ?>"
          method="POST"
          data-validate
          data-loading
          class="space-y-8">

        <?php if ($isEdit): ?>
        <input type="hidden" name="_method" value="PUT">
        <?php endif; ?>

        <!-- Basic Information Card -->
        <div class="card">
            <div class="p-6 border-b border-secondary-200 dark:border-secondary-700">
                <h3 class="text-lg font-semibold text-secondary-900 dark:text-white">Basic Information</h3>
                <p class="mt-1 text-sm text-secondary-600 dark:text-secondary-400">
                    Basic details about the website to monitor
                </p>
            </div>
            <div class="p-6 space-y-6">
                <!-- Website Name -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div>
                        <label for="name" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                            Website Name *
                        </label>
                        <input type="text"
                               id="name"
                               name="name"
                               value="<?= htmlspecialchars($website['name']) ?>"
                               data-validate="required|min:2|max:100"
                               placeholder="My Company Website"
                               class="form-input">
                        <p class="mt-1 text-xs text-secondary-500 dark:text-secondary-400">
                            A friendly name to identify this website
                        </p>
                    </div>

                    <!-- Website URL -->
                    <div>
                        <label for="url" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                            Website URL *
                        </label>
                        <input type="url"
                               id="url"
                               name="url"
                               value="<?= htmlspecialchars($website['url']) ?>"
                               data-validate="required|url"
                               placeholder="https://example.com"
                               class="form-input">
                        <p class="mt-1 text-xs text-secondary-500 dark:text-secondary-400">
                            Full URL including protocol (https:// or http://)
                        </p>
                    </div>
                </div>

                <!-- Description -->
                <div>
                    <label for="description" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                        Description
                    </label>
                    <textarea id="description"
                              name="description"
                              rows="3"
                              data-validate="max:500"
                              placeholder="Optional description of this website..."
                              class="form-input"><?= htmlspecialchars($website['description']) ?></textarea>
                    <p class="mt-1 text-xs text-secondary-500 dark:text-secondary-400">
                        Optional description to help identify this website's purpose
                    </p>
                </div>

                <!-- Category and Priority -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div>
                        <label for="category" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                            Category
                        </label>
                        <select id="category" name="category" class="form-input" onchange="updateCategoryDefaults()">
                            <?php
                            $categories = [
                                'ecommerce' => 'E-commerce',
                                'government' => 'Government',
                                'healthcare' => 'Healthcare',
                                'finance' => 'Finance',
                                'education' => 'Education',
                                'news' => 'News & Media',
                                'blog' => 'Blog',
                                'portfolio' => 'Portfolio',
                                'corporate' => 'Corporate',
                                'other' => 'Other'
                            ];
                            foreach ($categories as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>"
                                        <?= $website['category'] === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-xs text-secondary-500 dark:text-secondary-400">
                            Category affects default scan settings and priority
                        </p>
                    </div>

                    <div>
                        <label for="priority" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                            Priority Level
                        </label>
                        <select id="priority" name="priority" class="form-input">
                            <?php
                            $priorities = [
                                'critical' => 'Critical',
                                'high' => 'High',
                                'medium' => 'Medium',
                                'low' => 'Low',
                                'maintenance' => 'Maintenance'
                            ];
                            foreach ($priorities as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>"
                                        <?= $website['priority'] === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-xs text-secondary-500 dark:text-secondary-400">
                            Higher priority websites are scanned more frequently
                        </p>
                    </div>
                </div>

                <!-- Status and Notification -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox"
                                   name="active"
                                   value="1"
                                   <?= $website['active'] ? 'checked' : '' ?>
                                   class="form-checkbox h-4 w-4 text-primary-600 border-secondary-300 rounded mr-3">
                            <span class="text-sm font-medium text-secondary-700 dark:text-secondary-300">
                                Active Monitoring
                            </span>
                        </label>
                        <p class="mt-1 ml-7 text-xs text-secondary-500 dark:text-secondary-400">
                            Enable automatic security scans for this website
                        </p>
                    </div>

                    <div>
                        <label for="notification_email" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                            Notification Email
                        </label>
                        <input type="email"
                               id="notification_email"
                               name="notification_email"
                               value="<?= htmlspecialchars($website['notification_email']) ?>"
                               data-validate="email"
                               placeholder="admin@example.com"
                               class="form-input">
                        <p class="mt-1 text-xs text-secondary-500 dark:text-secondary-400">
                            Email address for scan failure notifications
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scan Configuration Card -->
        <div class="card">
            <div class="p-6 border-b border-secondary-200 dark:border-secondary-700">
                <h3 class="text-lg font-semibold text-secondary-900 dark:text-white">Scan Configuration</h3>
                <p class="mt-1 text-sm text-secondary-600 dark:text-secondary-400">
                    Configure how and when security scans are performed
                </p>
            </div>
            <div class="p-6 space-y-6">
                <!-- Scan Frequency and Timeout -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div>
                        <label for="scan_frequency" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                            Scan Frequency
                        </label>
                        <select id="scan_frequency" name="scan_frequency" class="form-input">
                            <?php
                            $frequencies = [
                                'immediate' => 'Immediate',
                                'hourly' => 'Every Hour',
                                'bi_hourly' => 'Every 2 Hours',
                                'quarter_daily' => 'Every 6 Hours',
                                'daily' => 'Daily',
                                'weekly' => 'Weekly',
                                'monthly' => 'Monthly'
                            ];
                            foreach ($frequencies as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>"
                                        <?= $website['scan_frequency'] === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="scan_timeout" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                            Timeout (seconds)
                        </label>
                        <input type="number"
                               id="scan_timeout"
                               name="scan_timeout"
                               value="<?= htmlspecialchars($website['scan_timeout']) ?>"
                               min="30"
                               max="3600"
                               data-validate="required|min:30|max:3600"
                               class="form-input">
                    </div>

                    <div>
                        <label for="max_retries" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                            Max Retries
                        </label>
                        <input type="number"
                               id="max_retries"
                               name="max_retries"
                               value="<?= htmlspecialchars($website['max_retries']) ?>"
                               min="0"
                               max="10"
                               data-validate="required|min:0|max:10"
                               class="form-input">
                    </div>
                </div>

                <!-- Test Selection -->
                <div>
                    <label class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-4">
                        Security Tests to Perform
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <label class="flex items-center p-3 border border-secondary-200 dark:border-secondary-700 rounded-lg hover:bg-secondary-50 dark:hover:bg-secondary-700/50 transition-colors cursor-pointer">
                            <input type="checkbox"
                                   name="ssl_check_enabled"
                                   value="1"
                                   <?= $website['ssl_check_enabled'] ? 'checked' : '' ?>
                                   class="form-checkbox h-4 w-4 text-primary-600 border-secondary-300 rounded mr-3">
                            <div>
                                <span class="text-sm font-medium text-secondary-900 dark:text-white">SSL Certificate</span>
                                <p class="text-xs text-secondary-500 dark:text-secondary-400">Check SSL certificate validity</p>
                            </div>
                        </label>

                        <label class="flex items-center p-3 border border-secondary-200 dark:border-secondary-700 rounded-lg hover:bg-secondary-50 dark:hover:bg-secondary-700/50 transition-colors cursor-pointer">
                            <input type="checkbox"
                                   name="check_security_headers"
                                   value="1"
                                   <?= $website['check_security_headers'] ? 'checked' : '' ?>
                                   class="form-checkbox h-4 w-4 text-primary-600 border-secondary-300 rounded mr-3">
                            <div>
                                <span class="text-sm font-medium text-secondary-900 dark:text-white">Security Headers</span>
                                <p class="text-xs text-secondary-500 dark:text-secondary-400">Verify HTTP security headers</p>
                            </div>
                        </label>

                        <label class="flex items-center p-3 border border-secondary-200 dark:border-secondary-700 rounded-lg hover:bg-secondary-50 dark:hover:bg-secondary-700/50 transition-colors cursor-pointer">
                            <input type="checkbox"
                                   name="check_response_time"
                                   value="1"
                                   <?= $website['check_response_time'] ? 'checked' : '' ?>
                                   class="form-checkbox h-4 w-4 text-primary-600 border-secondary-300 rounded mr-3">
                            <div>
                                <span class="text-sm font-medium text-secondary-900 dark:text-white">Response Time</span>
                                <p class="text-xs text-secondary-500 dark:text-secondary-400">Monitor page load performance</p>
                            </div>
                        </label>

                        <label class="flex items-center p-3 border border-secondary-200 dark:border-secondary-700 rounded-lg hover:bg-secondary-50 dark:hover:bg-secondary-700/50 transition-colors cursor-pointer">
                            <input type="checkbox"
                                   name="follow_redirects"
                                   value="1"
                                   <?= $website['follow_redirects'] ? 'checked' : '' ?>
                                   class="form-checkbox h-4 w-4 text-primary-600 border-secondary-300 rounded mr-3">
                            <div>
                                <span class="text-sm font-medium text-secondary-900 dark:text-white">Follow Redirects</span>
                                <p class="text-xs text-secondary-500 dark:text-secondary-400">Follow HTTP redirects during scan</p>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Advanced Settings Card (Collapsible) -->
        <div class="card" x-data="{ expanded: false }">
            <div class="p-6 border-b border-secondary-200 dark:border-secondary-700">
                <button type="button"
                        @click="expanded = !expanded"
                        class="flex items-center justify-between w-full text-left">
                    <div>
                        <h3 class="text-lg font-semibold text-secondary-900 dark:text-white">Advanced Settings</h3>
                        <p class="mt-1 text-sm text-secondary-600 dark:text-secondary-400">
                            Optional authentication and custom headers
                        </p>
                    </div>
                    <svg class="w-5 h-5 text-secondary-400 transition-transform"
                         :class="{ 'rotate-180': expanded }"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
            </div>
            <div x-show="expanded" x-transition class="p-6 space-y-6">
                <!-- Authentication Settings -->
                <div>
                    <label for="auth_type" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                        Authentication Type
                    </label>
                    <select id="auth_type" name="auth_type" class="form-input" onchange="toggleAuthFields()">
                        <option value="none" <?= $website['auth_type'] === 'none' ? 'selected' : '' ?>>No Authentication</option>
                        <option value="basic" <?= $website['auth_type'] === 'basic' ? 'selected' : '' ?>>Basic Auth</option>
                        <option value="bearer" <?= $website['auth_type'] === 'bearer' ? 'selected' : '' ?>>Bearer Token</option>
                    </select>
                </div>

                <div id="auth-fields" class="grid grid-cols-1 lg:grid-cols-2 gap-6" style="display: <?= $website['auth_type'] !== 'none' ? 'grid' : 'none' ?>">
                    <div>
                        <label for="auth_username" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                            Username / Token
                        </label>
                        <input type="text"
                               id="auth_username"
                               name="auth_username"
                               value="<?= htmlspecialchars($website['auth_username']) ?>"
                               class="form-input">
                    </div>

                    <div id="password-field">
                        <label for="auth_password" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                            Password
                        </label>
                        <input type="password"
                               id="auth_password"
                               name="auth_password"
                               value="<?= htmlspecialchars($website['auth_password']) ?>"
                               class="form-input">
                    </div>
                </div>

                <!-- Custom Headers -->
                <div>
                    <label for="custom_headers" class="block text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">
                        Custom Headers
                    </label>
                    <textarea id="custom_headers"
                              name="custom_headers"
                              rows="4"
                              placeholder="X-Custom-Header: value&#10;User-Agent: Custom Scanner/1.0"
                              class="form-input font-mono text-sm"><?= htmlspecialchars($website['custom_headers']) ?></textarea>
                    <p class="mt-1 text-xs text-secondary-500 dark:text-secondary-400">
                        One header per line in format: Header-Name: value
                    </p>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="flex flex-col sm:flex-row sm:justify-end gap-3 pt-6">
            <a href="/websites" class="btn btn-outline">
                Cancel
            </a>
            <button type="button" onclick="validateAndPreview()" class="btn btn-secondary">
                Preview Settings
            </button>
            <button type="submit" class="btn btn-primary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <?= $isEdit ? 'Update Website' : 'Create Website' ?>
            </button>
        </div>
    </form>
</div>

<!-- Preview Modal -->
<div id="preview-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-secondary-800 rounded-lg shadow-xl max-w-2xl w-full max-h-screen overflow-y-auto">
        <div class="p-6 border-b border-secondary-200 dark:border-secondary-700">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-secondary-900 dark:text-white">Website Configuration Preview</h3>
                <button onclick="closePreview()" class="text-secondary-400 hover:text-secondary-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        <div id="preview-content" class="p-6">
            <!-- Preview content will be populated by JavaScript -->
        </div>
        <div class="p-6 border-t border-secondary-200 dark:border-secondary-700 flex justify-end gap-3">
            <button onclick="closePreview()" class="btn btn-outline">Close</button>
            <button onclick="submitForm()" class="btn btn-primary">Looks Good - Save</button>
        </div>
    </div>
</div>

<script>
// Category default configurations
const categoryDefaults = {
    'ecommerce': { priority: 'high', scan_frequency: 'quarter_daily', scan_timeout: 300, max_retries: 3 },
    'government': { priority: 'critical', scan_frequency: 'daily', scan_timeout: 600, max_retries: 3 },
    'healthcare': { priority: 'critical', scan_frequency: 'bi_hourly', scan_timeout: 600, max_retries: 3 },
    'finance': { priority: 'critical', scan_frequency: 'hourly', scan_timeout: 300, max_retries: 5 },
    'education': { priority: 'medium', scan_frequency: 'daily', scan_timeout: 300, max_retries: 2 },
    'news': { priority: 'medium', scan_frequency: 'quarter_daily', scan_timeout: 180, max_retries: 2 },
    'blog': { priority: 'low', scan_frequency: 'weekly', scan_timeout: 120, max_retries: 1 },
    'portfolio': { priority: 'low', scan_frequency: 'weekly', scan_timeout: 120, max_retries: 1 },
    'corporate': { priority: 'medium', scan_frequency: 'daily', scan_timeout: 240, max_retries: 2 },
    'other': { priority: 'medium', scan_frequency: 'daily', scan_timeout: 180, max_retries: 2 }
};

// Update form defaults when category changes
function updateCategoryDefaults() {
    const category = document.getElementById('category').value;
    const defaults = categoryDefaults[category] || categoryDefaults['other'];

    // Only update if current values are defaults (to avoid overriding user changes)
    const prioritySelect = document.getElementById('priority');
    const frequencySelect = document.getElementById('scan_frequency');
    const timeoutInput = document.getElementById('scan_timeout');
    const retriesInput = document.getElementById('max_retries');

    // Show a confirmation if user has made changes
    const hasChanges = !isFormInDefaultState();

    if (hasChanges) {
        if (confirm('Update scan settings to match this category\'s recommended defaults?')) {
            applyDefaults(defaults);
        }
    } else {
        applyDefaults(defaults);
    }
}

function applyDefaults(defaults) {
    document.getElementById('priority').value = defaults.priority;
    document.getElementById('scan_frequency').value = defaults.scan_frequency;
    document.getElementById('scan_timeout').value = defaults.scan_timeout;
    document.getElementById('max_retries').value = defaults.max_retries;
}

function isFormInDefaultState() {
    // Check if form values differ from category defaults
    const category = document.getElementById('category').value;
    const defaults = categoryDefaults[category] || categoryDefaults['other'];

    return document.getElementById('priority').value === defaults.priority &&
           document.getElementById('scan_frequency').value === defaults.scan_frequency &&
           parseInt(document.getElementById('scan_timeout').value) === defaults.scan_timeout &&
           parseInt(document.getElementById('max_retries').value) === defaults.max_retries;
}

// Toggle authentication fields based on type
function toggleAuthFields() {
    const authType = document.getElementById('auth_type').value;
    const authFields = document.getElementById('auth-fields');
    const passwordField = document.getElementById('password-field');
    const usernameLabel = document.querySelector('label[for="auth_username"]');

    if (authType === 'none') {
        authFields.style.display = 'none';
    } else {
        authFields.style.display = 'grid';

        if (authType === 'bearer') {
            passwordField.style.display = 'none';
            usernameLabel.textContent = 'Bearer Token';
        } else {
            passwordField.style.display = 'block';
            usernameLabel.textContent = 'Username';
        }
    }
}

// URL validation and domain extraction
function validateUrl(url) {
    try {
        const urlObj = new URL(url);
        return {
            valid: true,
            protocol: urlObj.protocol,
            hostname: urlObj.hostname,
            port: urlObj.port || (urlObj.protocol === 'https:' ? '443' : '80'),
            isSecure: urlObj.protocol === 'https:'
        };
    } catch {
        return { valid: false };
    }
}

// Enhanced form validation
function validateForm() {
    const form = document.getElementById('website-form');
    const inputs = form.querySelectorAll('input, textarea, select');
    let isValid = true;
    let errors = [];

    inputs.forEach(input => {
        if (!validateField(input)) {
            isValid = false;
        }
    });

    // Additional custom validations
    const url = document.getElementById('url').value.trim();
    if (url) {
        const urlValidation = validateUrl(url);
        if (!urlValidation.valid) {
            errors.push('Please enter a valid URL with protocol (https:// or http://)');
            isValid = false;
        } else if (!urlValidation.isSecure) {
            // Warning for non-HTTPS URLs
            if (!confirm('Warning: This URL uses HTTP instead of HTTPS. Security scans may be limited. Continue anyway?')) {
                isValid = false;
            }
        }
    }

    // Custom headers validation
    const customHeaders = document.getElementById('custom_headers').value.trim();
    if (customHeaders) {
        const lines = customHeaders.split('\n').filter(line => line.trim());
        for (const line of lines) {
            if (!line.includes(':')) {
                errors.push('Invalid header format. Use "Header-Name: value" format.');
                document.getElementById('custom_headers').classList.add('form-error');
                isValid = false;
                break;
            }
        }
    }

    // Show validation errors
    if (errors.length > 0) {
        window.notify.error('Please fix the following errors:\n' + errors.join('\n'));
    }

    return isValid;
}

// Preview functionality
function validateAndPreview() {
    if (!validateForm()) {
        return;
    }

    const formData = new FormData(document.getElementById('website-form'));
    const data = Object.fromEntries(formData.entries());

    // Add unchecked checkboxes as false
    const checkboxes = ['active', 'ssl_check_enabled', 'check_security_headers', 'check_response_time', 'follow_redirects'];
    checkboxes.forEach(name => {
        if (!data[name]) data[name] = false;
    });

    showPreview(data);
}

function showPreview(data) {
    const urlInfo = validateUrl(data.url);
    const modal = document.getElementById('preview-modal');
    const content = document.getElementById('preview-content');

    const enabledTests = [];
    if (data.ssl_check_enabled) enabledTests.push('SSL Certificate Check');
    if (data.check_security_headers) enabledTests.push('Security Headers Check');
    if (data.check_response_time) enabledTests.push('Response Time Check');
    if (data.follow_redirects) enabledTests.push('Follow Redirects');

    content.innerHTML = `
        <div class="space-y-6">
            <div>
                <h4 class="font-semibold text-secondary-900 dark:text-white mb-3">Website Details</h4>
                <dl class="grid grid-cols-1 gap-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-secondary-500 dark:text-secondary-400">Name:</dt>
                        <dd class="text-secondary-900 dark:text-white font-medium">${data.name}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-secondary-500 dark:text-secondary-400">URL:</dt>
                        <dd class="text-secondary-900 dark:text-white font-mono">${data.url}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-secondary-500 dark:text-secondary-400">Category:</dt>
                        <dd class="text-secondary-900 dark:text-white">${data.category}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-secondary-500 dark:text-secondary-400">Priority:</dt>
                        <dd><span class="status-badge status-${data.priority === 'critical' || data.priority === 'high' ? 'danger' : data.priority === 'medium' ? 'warning' : 'info'}">${data.priority}</span></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-secondary-500 dark:text-secondary-400">Status:</dt>
                        <dd><span class="status-badge ${data.active ? 'status-success' : 'status-warning'}">${data.active ? 'Active' : 'Inactive'}</span></dd>
                    </div>
                </dl>
            </div>

            <div>
                <h4 class="font-semibold text-secondary-900 dark:text-white mb-3">Scan Configuration</h4>
                <dl class="grid grid-cols-1 gap-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-secondary-500 dark:text-secondary-400">Frequency:</dt>
                        <dd class="text-secondary-900 dark:text-white">${data.scan_frequency}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-secondary-500 dark:text-secondary-400">Timeout:</dt>
                        <dd class="text-secondary-900 dark:text-white">${data.scan_timeout} seconds</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-secondary-500 dark:text-secondary-400">Max Retries:</dt>
                        <dd class="text-secondary-900 dark:text-white">${data.max_retries}</dd>
                    </div>
                </dl>
            </div>

            ${enabledTests.length > 0 ? `
            <div>
                <h4 class="font-semibold text-secondary-900 dark:text-white mb-3">Enabled Tests</h4>
                <ul class="text-sm space-y-1">
                    ${enabledTests.map(test => `<li class="flex items-center text-success-600"><svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>${test}</li>`).join('')}
                </ul>
            </div>
            ` : ''}

            ${urlInfo.valid && !urlInfo.isSecure ? `
            <div class="p-4 bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-800 rounded-lg">
                <div class="flex">
                    <svg class="w-5 h-5 text-warning-400 mr-2 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                    <div>
                        <h5 class="text-sm font-medium text-warning-800 dark:text-warning-200">Security Warning</h5>
                        <p class="text-sm text-warning-700 dark:text-warning-300 mt-1">This website uses HTTP instead of HTTPS. Some security tests may be limited.</p>
                    </div>
                </div>
            </div>
            ` : ''}

            ${data.auth_type !== 'none' ? `
            <div class="p-4 bg-info-50 dark:bg-info-900/20 border border-info-200 dark:border-info-800 rounded-lg">
                <h5 class="text-sm font-medium text-info-800 dark:text-info-200">Authentication Configured</h5>
                <p class="text-sm text-info-700 dark:text-info-300 mt-1">
                    ${data.auth_type === 'basic' ? 'Basic authentication' : 'Bearer token authentication'} will be used for this website.
                </p>
            </div>
            ` : ''}
        </div>
    `;

    modal.classList.remove('hidden');
}

function closePreview() {
    document.getElementById('preview-modal').classList.add('hidden');
}

function submitForm() {
    closePreview();
    if (validateForm()) {
        document.getElementById('website-form').submit();
    }
}

// Test website connection
function testWebsite() {
    const url = document.getElementById('url').value.trim();
    if (!url) {
        window.notify.warning('Please enter a website URL first');
        return;
    }

    const urlValidation = validateUrl(url);
    if (!urlValidation.valid) {
        window.notify.error('Please enter a valid URL');
        return;
    }

    window.ajax.post('/api/websites/test-connection', { url: url })
        .then(data => {
            if (data.success) {
                const result = data.result;
                let message = `Connection successful!\n`;
                message += `Response Time: ${result.response_time}ms\n`;
                message += `Status Code: ${result.status_code}\n`;
                if (result.ssl_info) {
                    message += `SSL: ${result.ssl_info.valid ? 'Valid' : 'Invalid'}\n`;
                }
                window.notify.success(message);
            } else {
                window.notify.error('Connection failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Test connection error:', error);
            window.notify.error('Failed to test connection');
        });
}

// Form submission handler
document.getElementById('website-form').addEventListener('submit', function(e) {
    e.preventDefault();

    if (!validateForm()) {
        return;
    }

    const formData = new FormData(this);
    const method = formData.get('_method') || 'POST';
    const url = this.action;

    // Convert FormData to regular object for JSON
    const data = Object.fromEntries(formData.entries());

    // Handle checkboxes that aren't submitted when unchecked
    const checkboxes = ['active', 'ssl_check_enabled', 'check_security_headers', 'check_response_time', 'follow_redirects'];
    checkboxes.forEach(name => {
        if (!data[name]) data[name] = '0';
    });

    window.ajax.request(method, url, data)
        .then(response => {
            if (response.success) {
                window.notify.success('Website saved successfully!');
                setTimeout(() => {
                    window.location.href = '/websites';
                }, 1500);
            } else {
                window.notify.error('Failed to save website: ' + (response.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Form submission error:', error);
            window.notify.error('Failed to save website due to a network error');
        });
});

// Initialize form
document.addEventListener('DOMContentLoaded', function() {
    toggleAuthFields();

    // Add real-time URL validation
    const urlInput = document.getElementById('url');
    let urlTimeout;

    urlInput.addEventListener('input', function() {
        clearTimeout(urlTimeout);
        urlTimeout = setTimeout(() => {
            const url = this.value.trim();
            if (url) {
                const validation = validateUrl(url);
                if (validation.valid) {
                    this.classList.remove('form-error');
                    // Auto-populate name if empty
                    const nameInput = document.getElementById('name');
                    if (!nameInput.value.trim()) {
                        nameInput.value = validation.hostname;
                    }
                }
            }
        }, 500);
    });
});
</script>