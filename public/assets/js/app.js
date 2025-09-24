/**
 * Security Scanner Tool - Main JavaScript File
 */

(function() {
    'use strict';

    // App namespace
    window.SecurityScanner = window.SecurityScanner || {};

    // Configuration
    const config = {
        apiBaseUrl: '/api',
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        refreshInterval: 30000 // 30 seconds
    };

    // Utility functions
    const utils = {
        /**
         * Make AJAX request
         */
        ajax: function(url, options = {}) {
            const defaults = {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            };

            if (config.csrfToken) {
                defaults.headers['X-CSRF-Token'] = config.csrfToken;
            }

            const settings = Object.assign({}, defaults, options);

            return fetch(url, settings)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .catch(error => {
                    console.error('AJAX Error:', error);
                    throw error;
                });
        },

        /**
         * Show notification
         */
        notify: function(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type}`;
            notification.textContent = message;

            // Find or create notification container
            let container = document.querySelector('.notifications');
            if (!container) {
                container = document.createElement('div');
                container.className = 'notifications';
                container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 300px;';
                document.body.appendChild(container);
            }

            container.appendChild(notification);

            // Auto remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 5000);
        },

        /**
         * Format date
         */
        formatDate: function(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        },

        /**
         * Debounce function
         */
        debounce: function(func, delay) {
            let timeoutId;
            return function(...args) {
                clearTimeout(timeoutId);
                timeoutId = setTimeout(() => func.apply(this, args), delay);
            };
        }
    };

    // Dashboard functionality
    const dashboard = {
        init: function() {
            this.loadStats();
            this.setupAutoRefresh();
        },

        loadStats: function() {
            utils.ajax(`${config.apiBaseUrl}/dashboard/stats`)
                .then(data => {
                    this.updateStats(data);
                })
                .catch(error => {
                    console.error('Failed to load dashboard stats:', error);
                });
        },

        updateStats: function(stats) {
            // Update website count
            const websiteCount = document.querySelector('#website-count');
            if (websiteCount && stats.websites) {
                websiteCount.textContent = stats.websites;
            }

            // Update test results
            const passedTests = document.querySelector('#passed-tests');
            if (passedTests && stats.passed_tests) {
                passedTests.textContent = stats.passed_tests;
            }

            const failedTests = document.querySelector('#failed-tests');
            if (failedTests && stats.failed_tests) {
                failedTests.textContent = stats.failed_tests;
            }

            // Update last scan time
            const lastScan = document.querySelector('#last-scan');
            if (lastScan && stats.last_scan) {
                lastScan.textContent = utils.formatDate(stats.last_scan);
            }
        },

        setupAutoRefresh: function() {
            setInterval(() => {
                this.loadStats();
            }, config.refreshInterval);
        }
    };

    // Website management
    const websites = {
        init: function() {
            this.setupEventListeners();
            this.loadWebsites();
        },

        setupEventListeners: function() {
            // Delete website confirmation
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('delete-website')) {
                    e.preventDefault();
                    this.confirmDelete(e.target);
                }
            });

            // Run scan manually
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('run-scan')) {
                    e.preventDefault();
                    this.runScan(e.target.dataset.websiteId);
                }
            });
        },

        loadWebsites: function() {
            const websitesList = document.querySelector('#websites-list');
            if (!websitesList) return;

            utils.ajax(`${config.apiBaseUrl}/websites`)
                .then(data => {
                    this.renderWebsites(data.websites);
                })
                .catch(error => {
                    console.error('Failed to load websites:', error);
                    utils.notify('Failed to load websites', 'error');
                });
        },

        renderWebsites: function(websites) {
            const container = document.querySelector('#websites-list');
            if (!container) return;

            container.innerHTML = websites.map(website => `
                <tr>
                    <td>${website.name}</td>
                    <td><a href="${website.url}" target="_blank">${website.url}</a></td>
                    <td><span class="status status-${website.status}">${website.status}</span></td>
                    <td>${utils.formatDate(website.last_scan)}</td>
                    <td>
                        <button class="btn btn-primary btn-sm run-scan" data-website-id="${website.id}">
                            Run Scan
                        </button>
                        <a href="/websites/${website.id}" class="btn btn-secondary btn-sm">View</a>
                        <a href="/websites/${website.id}/edit" class="btn btn-secondary btn-sm">Edit</a>
                        <button class="btn btn-danger btn-sm delete-website" data-website-id="${website.id}">
                            Delete
                        </button>
                    </td>
                </tr>
            `).join('');
        },

        confirmDelete: function(button) {
            const websiteId = button.dataset.websiteId;
            if (confirm('Are you sure you want to delete this website?')) {
                this.deleteWebsite(websiteId);
            }
        },

        deleteWebsite: function(websiteId) {
            utils.ajax(`${config.apiBaseUrl}/websites/${websiteId}`, {
                method: 'DELETE'
            })
            .then(() => {
                utils.notify('Website deleted successfully', 'success');
                this.loadWebsites(); // Refresh list
            })
            .catch(error => {
                utils.notify('Failed to delete website', 'error');
            });
        },

        runScan: function(websiteId) {
            utils.ajax(`${config.apiBaseUrl}/websites/${websiteId}/scan`, {
                method: 'POST'
            })
            .then(() => {
                utils.notify('Scan started successfully', 'success');
            })
            .catch(error => {
                utils.notify('Failed to start scan', 'error');
            });
        }
    };

    // Form handling
    const forms = {
        init: function() {
            this.setupValidation();
        },

        setupValidation: function() {
            // Real-time validation
            document.addEventListener('blur', (e) => {
                if (e.target.classList.contains('form-control')) {
                    this.validateField(e.target);
                }
            }, true);

            // Form submission
            document.addEventListener('submit', (e) => {
                if (e.target.classList.contains('needs-validation')) {
                    if (!this.validateForm(e.target)) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                }
            });
        },

        validateField: function(field) {
            const value = field.value.trim();
            const rules = field.dataset.validation ? field.dataset.validation.split('|') : [];

            let isValid = true;
            let errorMessage = '';

            for (const rule of rules) {
                const result = this.applyValidationRule(value, rule);
                if (!result.valid) {
                    isValid = false;
                    errorMessage = result.message;
                    break;
                }
            }

            this.setFieldValidation(field, isValid, errorMessage);
            return isValid;
        },

        applyValidationRule: function(value, rule) {
            const [ruleName, ruleParam] = rule.split(':');

            switch (ruleName) {
                case 'required':
                    return {
                        valid: value.length > 0,
                        message: 'This field is required.'
                    };

                case 'email':
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    return {
                        valid: emailRegex.test(value),
                        message: 'Please enter a valid email address.'
                    };

                case 'url':
                    try {
                        new URL(value);
                        return { valid: true, message: '' };
                    } catch {
                        return {
                            valid: false,
                            message: 'Please enter a valid URL.'
                        };
                    }

                case 'min':
                    return {
                        valid: value.length >= parseInt(ruleParam),
                        message: `Minimum ${ruleParam} characters required.`
                    };

                case 'max':
                    return {
                        valid: value.length <= parseInt(ruleParam),
                        message: `Maximum ${ruleParam} characters allowed.`
                    };

                default:
                    return { valid: true, message: '' };
            }
        },

        setFieldValidation: function(field, isValid, message) {
            const feedback = field.parentNode.querySelector('.invalid-feedback');

            if (isValid) {
                field.classList.remove('is-invalid');
                if (feedback) {
                    feedback.style.display = 'none';
                }
            } else {
                field.classList.add('is-invalid');
                if (feedback) {
                    feedback.textContent = message;
                    feedback.style.display = 'block';
                }
            }
        },

        validateForm: function(form) {
            const fields = form.querySelectorAll('.form-control[data-validation]');
            let isValid = true;

            fields.forEach(field => {
                if (!this.validateField(field)) {
                    isValid = false;
                }
            });

            return isValid;
        }
    };

    // Initialize application
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize modules based on current page
        const body = document.body;

        if (body.classList.contains('dashboard-page')) {
            dashboard.init();
        }

        if (body.classList.contains('websites-page')) {
            websites.init();
        }

        // Always initialize forms
        forms.init();

        // Global initialization
        utils.notify('Application loaded successfully', 'success');
    });

    // Export to global scope
    window.SecurityScanner.utils = utils;
    window.SecurityScanner.dashboard = dashboard;
    window.SecurityScanner.websites = websites;
    window.SecurityScanner.forms = forms;

})();