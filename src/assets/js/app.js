// Security Scanner Tool - Main JavaScript Application
import Alpine from 'alpinejs';

// Initialize Alpine.js
window.Alpine = Alpine;

// Alpine.js Global Components and Functions
document.addEventListener('alpine:init', () => {
    // Theme Toggle Component
    Alpine.data('themeToggle', () => ({
        isDark: false,

        init() {
            // Check for saved theme preference or default to system preference
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            this.isDark = savedTheme ? savedTheme === 'dark' : prefersDark;
            this.applyTheme();

            // Listen for system theme changes
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                if (!localStorage.getItem('theme')) {
                    this.isDark = e.matches;
                    this.applyTheme();
                }
            });
        },

        toggleTheme() {
            this.isDark = !this.isDark;
            localStorage.setItem('theme', this.isDark ? 'dark' : 'light');
            this.applyTheme();
        },

        applyTheme() {
            if (this.isDark) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        }
    }));

    // Modal Component
    Alpine.data('modal', (initiallyOpen = false) => ({
        open: initiallyOpen,

        show() {
            this.open = true;
            document.body.style.overflow = 'hidden';
        },

        hide() {
            this.open = false;
            document.body.style.overflow = '';
        },

        toggle() {
            this.open ? this.hide() : this.show();
        }
    }));

    // Dropdown Component
    Alpine.data('dropdown', () => ({
        open: false,

        toggle() {
            this.open = !this.open;
        },

        close() {
            this.open = false;
        }
    }));

    // Accordion Component
    Alpine.data('accordion', (initiallyExpanded = false) => ({
        expanded: initiallyExpanded,

        toggle() {
            this.expanded = !this.expanded;
        }
    }));

    // Tabs Component
    Alpine.data('tabs', (defaultTab = 0) => ({
        activeTab: defaultTab,

        setActiveTab(index) {
            this.activeTab = index;
        },

        isActive(index) {
            return this.activeTab === index;
        }
    }));

    // Toast/Notification System
    Alpine.data('toastManager', () => ({
        toasts: [],

        addToast(message, type = 'info', duration = 5000) {
            const id = Date.now() + Math.random();
            const toast = { id, message, type, duration };
            this.toasts.push(toast);

            if (duration > 0) {
                setTimeout(() => {
                    this.removeToast(id);
                }, duration);
            }

            return id;
        },

        removeToast(id) {
            this.toasts = this.toasts.filter(toast => toast.id !== id);
        },

        success(message, duration = 5000) {
            return this.addToast(message, 'success', duration);
        },

        error(message, duration = 7000) {
            return this.addToast(message, 'error', duration);
        },

        warning(message, duration = 6000) {
            return this.addToast(message, 'warning', duration);
        },

        info(message, duration = 5000) {
            return this.addToast(message, 'info', duration);
        }
    }));

    // Form Validation Component
    Alpine.data('formValidator', (rules = {}) => ({
        errors: {},
        touched: {},

        validateField(field, value) {
            const fieldRules = rules[field];
            if (!fieldRules) return true;

            this.errors[field] = [];

            for (const rule of fieldRules) {
                const result = this.applyRule(rule, value, field);
                if (result !== true) {
                    this.errors[field].push(result);
                }
            }

            return this.errors[field].length === 0;
        },

        applyRule(rule, value, field) {
            if (typeof rule === 'function') {
                return rule(value, field);
            }

            if (typeof rule === 'object') {
                const { type, message, ...params } = rule;
                return this.validateByType(type, value, params, message);
            }

            return this.validateByType(rule, value, {}, null);
        },

        validateByType(type, value, params, customMessage) {
            switch (type) {
                case 'required':
                    return value && value.toString().trim() !== '' ? true : (customMessage || 'This field is required');

                case 'email':
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    return !value || emailRegex.test(value) ? true : (customMessage || 'Please enter a valid email address');

                case 'url':
                    try {
                        if (!value) return true;
                        new URL(value);
                        return true;
                    } catch {
                        return customMessage || 'Please enter a valid URL';
                    }

                case 'min':
                    return !value || value.length >= params.length ? true : (customMessage || `Must be at least ${params.length} characters`);

                case 'max':
                    return !value || value.length <= params.length ? true : (customMessage || `Must be no more than ${params.length} characters`);

                case 'numeric':
                    return !value || !isNaN(value) ? true : (customMessage || 'Must be a number');

                default:
                    return true;
            }
        },

        markTouched(field) {
            this.touched[field] = true;
        },

        hasError(field) {
            return this.touched[field] && this.errors[field] && this.errors[field].length > 0;
        },

        getFirstError(field) {
            return this.hasError(field) ? this.errors[field][0] : null;
        },

        isValid() {
            return Object.keys(this.errors).every(field => !this.errors[field] || this.errors[field].length === 0);
        }
    }));

    // Data Table Component
    Alpine.data('dataTable', (initialData = []) => ({
        data: initialData,
        sortColumn: null,
        sortDirection: 'asc',
        selectedRows: [],
        selectAll: false,

        sortBy(column) {
            if (this.sortColumn === column) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortColumn = column;
                this.sortDirection = 'asc';
            }

            this.data.sort((a, b) => {
                let aVal = a[column];
                let bVal = b[column];

                // Handle different data types
                if (typeof aVal === 'string') {
                    aVal = aVal.toLowerCase();
                    bVal = bVal.toLowerCase();
                }

                if (aVal < bVal) return this.sortDirection === 'asc' ? -1 : 1;
                if (aVal > bVal) return this.sortDirection === 'asc' ? 1 : -1;
                return 0;
            });
        },

        toggleSelectAll() {
            this.selectAll = !this.selectAll;
            this.selectedRows = this.selectAll ? this.data.map((_, index) => index) : [];
        },

        toggleRowSelection(index) {
            if (this.selectedRows.includes(index)) {
                this.selectedRows = this.selectedRows.filter(i => i !== index);
            } else {
                this.selectedRows.push(index);
            }

            this.selectAll = this.selectedRows.length === this.data.length;
        },

        isRowSelected(index) {
            return this.selectedRows.includes(index);
        },

        getSelectedData() {
            return this.selectedRows.map(index => this.data[index]);
        }
    }));

    // Search/Filter Component
    Alpine.data('searchFilter', (initialData = []) => ({
        searchTerm: '',
        filteredData: initialData,
        originalData: initialData,

        init() {
            this.$watch('searchTerm', () => this.performSearch());
        },

        performSearch() {
            if (!this.searchTerm.trim()) {
                this.filteredData = this.originalData;
                return;
            }

            const term = this.searchTerm.toLowerCase();
            this.filteredData = this.originalData.filter(item => {
                return Object.values(item).some(value =>
                    value && value.toString().toLowerCase().includes(term)
                );
            });
        },

        setData(data) {
            this.originalData = data;
            this.performSearch();
        }
    }));

    // Enhanced Loading State Component
    Alpine.data('loadingState', (initialLoading = false) => ({
        loading: initialLoading,
        error: null,
        success: false,
        progress: 0,
        message: '',

        setLoading(state, message = '') {
            this.loading = state;
            this.message = message;
            if (state) {
                this.error = null;
                this.success = false;
                this.progress = 0;
            }
        },

        setProgress(value) {
            this.progress = Math.max(0, Math.min(100, value));
        },

        setError(error) {
            this.loading = false;
            this.error = error;
            this.success = false;
            this.progress = 0;
        },

        setSuccess(message = '') {
            this.loading = false;
            this.error = null;
            this.success = true;
            this.message = message;
            this.progress = 100;

            // Auto-clear success state after 3 seconds
            setTimeout(() => {
                this.success = false;
                this.message = '';
                this.progress = 0;
            }, 3000);
        },

        clearState() {
            this.loading = false;
            this.error = null;
            this.success = false;
            this.progress = 0;
            this.message = '';
        },

        async withLoading(asyncFunction, progressCallback = null) {
            this.setLoading(true, 'Processing...');

            try {
                const result = await asyncFunction(progressCallback ? (progress) => {
                    this.setProgress(progress);
                    if (progressCallback) progressCallback(progress);
                } : null);

                this.setSuccess('Operation completed successfully');
                return result;
            } catch (error) {
                this.setError(this.formatError(error));
                throw error;
            }
        },

        formatError(error) {
            if (typeof error === 'string') return error;
            if (error.message) return error.message;
            if (error.response?.data?.error) return error.response.data.error;
            if (error.response?.statusText) return error.response.statusText;
            return 'An unexpected error occurred';
        },

        get isLoading() {
            return this.loading;
        },

        get hasError() {
            return this.error !== null;
        },

        get isSuccess() {
            return this.success;
        }
    }));

    // Pagination Component
    Alpine.data('pagination', (itemsPerPage = 10) => ({
        currentPage: 1,
        itemsPerPage: itemsPerPage,
        totalItems: 0,

        get totalPages() {
            return Math.ceil(this.totalItems / this.itemsPerPage);
        },

        get startIndex() {
            return (this.currentPage - 1) * this.itemsPerPage;
        },

        get endIndex() {
            return Math.min(this.startIndex + this.itemsPerPage, this.totalItems);
        },

        get visiblePages() {
            const pages = [];
            const maxVisible = 5;
            let start = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
            let end = Math.min(this.totalPages, start + maxVisible - 1);

            if (end - start + 1 < maxVisible) {
                start = Math.max(1, end - maxVisible + 1);
            }

            for (let i = start; i <= end; i++) {
                pages.push(i);
            }

            return pages;
        },

        goToPage(page) {
            if (page >= 1 && page <= this.totalPages) {
                this.currentPage = page;
            }
        },

        nextPage() {
            this.goToPage(this.currentPage + 1);
        },

        prevPage() {
            this.goToPage(this.currentPage - 1);
        },

        setTotalItems(total) {
            this.totalItems = total;
            if (this.currentPage > this.totalPages) {
                this.currentPage = Math.max(1, this.totalPages);
            }
        }
    }));

    // Error Handler Component
    Alpine.data('errorHandler', () => ({
        errors: [],
        maxErrors: 10,

        addError(error, context = {}) {
            const errorObj = {
                id: Date.now() + Math.random(),
                timestamp: new Date(),
                message: this.formatError(error),
                context: context,
                dismissed: false,
                retryable: this.isRetryable(error)
            };

            this.errors.unshift(errorObj);

            // Keep only the latest errors
            if (this.errors.length > this.maxErrors) {
                this.errors = this.errors.slice(0, this.maxErrors);
            }

            // Auto-dismiss after 10 seconds for non-critical errors
            if (!this.isCritical(error)) {
                setTimeout(() => {
                    this.dismissError(errorObj.id);
                }, 10000);
            }

            return errorObj.id;
        },

        dismissError(errorId) {
            const error = this.errors.find(e => e.id === errorId);
            if (error) {
                error.dismissed = true;
            }
        },

        removeError(errorId) {
            this.errors = this.errors.filter(e => e.id !== errorId);
        },

        clearAllErrors() {
            this.errors = [];
        },

        formatError(error) {
            if (typeof error === 'string') return error;
            if (error.message) return error.message;
            if (error.response?.data?.error) return error.response.data.error;
            if (error.response?.data?.message) return error.response.data.message;
            if (error.response?.statusText) return `${error.response.status}: ${error.response.statusText}`;
            return 'An unexpected error occurred';
        },

        isRetryable(error) {
            if (error.response) {
                const status = error.response.status;
                // Retry on 5xx server errors and 429 (rate limit)
                return status >= 500 || status === 429;
            }
            // Network errors are retryable
            return error.code === 'NETWORK_ERROR' || error.name === 'NetworkError';
        },

        isCritical(error) {
            if (error.response) {
                const status = error.response.status;
                // 401, 403, 404 are not critical
                return ![401, 403, 404].includes(status);
            }
            return true;
        },

        get activeErrors() {
            return this.errors.filter(e => !e.dismissed);
        },

        get criticalErrors() {
            return this.activeErrors.filter(e => this.isCritical(e));
        }
    }));

    // Retry Mechanism Component
    Alpine.data('retryHandler', () => ({
        retryAttempts: new Map(),
        maxRetries: 3,
        baseDelay: 1000,

        async withRetry(asyncFunction, options = {}) {
            const key = options.key || 'default';
            const maxRetries = options.maxRetries || this.maxRetries;
            const baseDelay = options.baseDelay || this.baseDelay;

            let attempts = this.retryAttempts.get(key) || 0;

            try {
                const result = await asyncFunction();
                this.retryAttempts.delete(key);
                return result;
            } catch (error) {
                attempts++;
                this.retryAttempts.set(key, attempts);

                if (attempts >= maxRetries || !this.shouldRetry(error)) {
                    this.retryAttempts.delete(key);
                    throw error;
                }

                // Exponential backoff with jitter
                const delay = baseDelay * Math.pow(2, attempts - 1);
                const jitter = Math.random() * 0.1 * delay;

                await this.sleep(delay + jitter);
                return this.withRetry(asyncFunction, options);
            }
        },

        shouldRetry(error) {
            if (error.response) {
                const status = error.response.status;
                // Retry on 5xx, 429, 408, 503, 504
                return [408, 429, 500, 502, 503, 504].includes(status);
            }
            // Retry on network errors
            return error.code === 'NETWORK_ERROR' || error.name === 'NetworkError';
        },

        sleep(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        },

        getRetryCount(key = 'default') {
            return this.retryAttempts.get(key) || 0;
        },

        clearRetries(key = null) {
            if (key) {
                this.retryAttempts.delete(key);
            } else {
                this.retryAttempts.clear();
            }
        }
    }));

    // Global notification functions for header notifications
    window.loadNotifications = function() {
        // This would typically load from an API endpoint
        // For now, we'll use sample data
        const notifications = [
            {
                id: 1,
                type: 'success',
                title: 'Scan Completed',
                message: 'Security scan for example.com completed successfully',
                time: '2 minutes ago'
            },
            {
                id: 2,
                type: 'warning',
                title: 'SSL Certificate Expiring',
                message: 'SSL certificate for mysite.com expires in 7 days',
                time: '1 hour ago'
            },
            {
                id: 3,
                type: 'error',
                title: 'Scan Failed',
                message: 'Unable to connect to testsite.org',
                time: '3 hours ago'
            }
        ];

        // Update the notifications in the Alpine component
        Alpine.store('notifications', {
            items: notifications,
            unreadCount: notifications.length
        });

        return notifications;
    };

    // Accessibility Helper Component
    Alpine.data('accessibility', () => ({
        highContrast: false,
        reducedMotion: false,
        screenReaderAnnouncements: [],
        focusTrap: null,
        lastFocusedElement: null,

        init() {
            this.detectPreferences();
            this.setupKeyboardNavigation();
            this.setupScreenReaderSupport();
            this.setupFocusManagement();

            // Watch for preference changes
            this.watchMediaQueries();
        },

        detectPreferences() {
            // Check for reduced motion preference
            const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
            this.reducedMotion = reducedMotionQuery.matches;

            // Check for high contrast preference
            const highContrastQuery = window.matchMedia('(prefers-contrast: high)');
            this.highContrast = highContrastQuery.matches;

            // Apply preferences to document
            this.applyPreferences();
        },

        watchMediaQueries() {
            // Listen for changes in motion preference
            window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', (e) => {
                this.reducedMotion = e.matches;
                this.applyPreferences();
            });

            // Listen for changes in contrast preference
            window.matchMedia('(prefers-contrast: high)').addEventListener('change', (e) => {
                this.highContrast = e.matches;
                this.applyPreferences();
            });
        },

        applyPreferences() {
            if (this.reducedMotion) {
                document.documentElement.classList.add('reduced-motion');
            } else {
                document.documentElement.classList.remove('reduced-motion');
            }

            if (this.highContrast) {
                document.documentElement.classList.add('high-contrast');
            } else {
                document.documentElement.classList.remove('high-contrast');
            }
        },

        setupKeyboardNavigation() {
            // Global keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                // Skip button with Alt+S
                if (e.altKey && e.key === 's') {
                    e.preventDefault();
                    this.skipToMain();
                }

                // Search with Alt+/
                if (e.altKey && e.key === '/') {
                    e.preventDefault();
                    this.focusSearch();
                }

                // Help with Alt+?
                if (e.altKey && (e.key === '?' || e.key === 'h')) {
                    e.preventDefault();
                    this.showKeyboardShortcuts();
                }

                // Escape to close modals/dropdowns
                if (e.key === 'Escape') {
                    this.handleEscape();
                }
            });

            // Roving tabindex for complex widgets
            this.setupRovingTabindex();
        },

        setupRovingTabindex() {
            // Setup roving tabindex for data tables
            document.querySelectorAll('[role="grid"], [role="listbox"], [role="menu"]').forEach(container => {
                this.initializeRovingTabindex(container);
            });
        },

        initializeRovingTabindex(container) {
            const items = container.querySelectorAll('[role="gridcell"], [role="option"], [role="menuitem"]');
            let currentIndex = 0;

            // Set initial tabindex
            items.forEach((item, index) => {
                item.tabIndex = index === 0 ? 0 : -1;
                item.addEventListener('keydown', (e) => {
                    switch (e.key) {
                        case 'ArrowDown':
                        case 'ArrowRight':
                            e.preventDefault();
                            currentIndex = (currentIndex + 1) % items.length;
                            this.updateFocus(items, currentIndex);
                            break;

                        case 'ArrowUp':
                        case 'ArrowLeft':
                            e.preventDefault();
                            currentIndex = currentIndex === 0 ? items.length - 1 : currentIndex - 1;
                            this.updateFocus(items, currentIndex);
                            break;

                        case 'Home':
                            e.preventDefault();
                            currentIndex = 0;
                            this.updateFocus(items, currentIndex);
                            break;

                        case 'End':
                            e.preventDefault();
                            currentIndex = items.length - 1;
                            this.updateFocus(items, currentIndex);
                            break;

                        case 'Enter':
                        case ' ':
                            e.preventDefault();
                            item.click();
                            break;
                    }
                });

                item.addEventListener('focus', () => {
                    currentIndex = Array.from(items).indexOf(item);
                });
            });
        },

        updateFocus(items, index) {
            items.forEach((item, i) => {
                item.tabIndex = i === index ? 0 : -1;
            });
            items[index].focus();
        },

        setupScreenReaderSupport() {
            // Create live region for announcements
            if (!document.getElementById('sr-live-region')) {
                const liveRegion = document.createElement('div');
                liveRegion.id = 'sr-live-region';
                liveRegion.setAttribute('aria-live', 'polite');
                liveRegion.setAttribute('aria-atomic', 'true');
                liveRegion.className = 'sr-only';
                document.body.appendChild(liveRegion);
            }

            // Setup aria-describedby for form validation
            this.setupFormValidationAnnouncements();
        },

        setupFormValidationAnnouncements() {
            document.querySelectorAll('input, textarea, select').forEach(field => {
                field.addEventListener('invalid', (e) => {
                    const message = field.validationMessage || 'This field is invalid';
                    this.announceToScreenReader(`Error: ${message}`);
                });

                field.addEventListener('input', () => {
                    if (field.validity.valid && field.getAttribute('aria-invalid') === 'true') {
                        field.removeAttribute('aria-invalid');
                        this.announceToScreenReader('Field is now valid');
                    }
                });
            });
        },

        setupFocusManagement() {
            // Manage focus on route changes
            window.addEventListener('popstate', () => {
                this.manageFocusOnPageChange();
            });
        },

        trapFocus(modalElement) {
            this.lastFocusedElement = document.activeElement;

            const focusableElements = modalElement.querySelectorAll(
                'a[href], button, textarea, input[type="text"], input[type="radio"], input[type="checkbox"], select, [tabindex]:not([tabindex="-1"])'
            );

            const firstElement = focusableElements[0];
            const lastElement = focusableElements[focusableElements.length - 1];

            // Focus first element
            if (firstElement) {
                firstElement.focus();
            }

            // Trap focus
            this.focusTrap = (e) => {
                if (e.key === 'Tab') {
                    if (e.shiftKey) {
                        if (document.activeElement === firstElement) {
                            e.preventDefault();
                            lastElement.focus();
                        }
                    } else {
                        if (document.activeElement === lastElement) {
                            e.preventDefault();
                            firstElement.focus();
                        }
                    }
                }
            };

            modalElement.addEventListener('keydown', this.focusTrap);
        },

        releaseFocus() {
            if (this.focusTrap) {
                document.removeEventListener('keydown', this.focusTrap);
                this.focusTrap = null;
            }

            if (this.lastFocusedElement) {
                this.lastFocusedElement.focus();
                this.lastFocusedElement = null;
            }
        },

        manageFocusOnPageChange() {
            // Focus the main heading when navigating
            const mainHeading = document.querySelector('h1');
            if (mainHeading) {
                mainHeading.tabIndex = -1;
                mainHeading.focus();
                this.announceToScreenReader(`Page changed: ${mainHeading.textContent}`);
            }
        },

        // Utility functions
        announceToScreenReader(message, priority = 'polite') {
            const liveRegion = document.getElementById('sr-live-region');
            if (liveRegion) {
                liveRegion.setAttribute('aria-live', priority);
                liveRegion.textContent = message;

                // Clear after announcement
                setTimeout(() => {
                    liveRegion.textContent = '';
                }, 1000);
            }
        },

        skipToMain() {
            const main = document.querySelector('main, [role="main"], #main-content');
            if (main) {
                main.tabIndex = -1;
                main.focus();
                this.announceToScreenReader('Skipped to main content');
            }
        },

        focusSearch() {
            const searchInput = document.querySelector('[type="search"], [placeholder*="search" i], #search');
            if (searchInput) {
                searchInput.focus();
                this.announceToScreenReader('Search field focused');
            }
        },

        showKeyboardShortcuts() {
            this.announceToScreenReader('Keyboard shortcuts: Alt+S to skip to main content, Alt+/ to focus search, Alt+? for help, Escape to close dialogs');
        },

        handleEscape() {
            // Close any open modals, dropdowns, or overlays
            const openElements = document.querySelectorAll('[aria-expanded="true"], .modal.show, .dropdown.show');
            openElements.forEach(element => {
                if (element.click) {
                    element.click();
                } else {
                    element.dispatchEvent(new Event('close'));
                }
            });
        }
    }));

    // Global Alpine store for notifications
    Alpine.store('notifications', {
        items: [],
        unreadCount: 0,

        add(notification) {
            notification.id = Date.now() + Math.random();
            this.items.unshift(notification);
            this.unreadCount++;
        },

        markAsRead(id) {
            const notification = this.items.find(n => n.id === id);
            if (notification && !notification.read) {
                notification.read = true;
                this.unreadCount = Math.max(0, this.unreadCount - 1);
            }
        },

        markAllAsRead() {
            this.items.forEach(n => n.read = true);
            this.unreadCount = 0;
        },

        remove(id) {
            const index = this.items.findIndex(n => n.id === id);
            if (index > -1) {
                const notification = this.items[index];
                if (!notification.read) {
                    this.unreadCount = Math.max(0, this.unreadCount - 1);
                }
                this.items.splice(index, 1);
            }
        }
    });
});

// Theme Management
document.addEventListener('DOMContentLoaded', function() {
  initializeTheme();
  initializeNavigation();
  initializeFormValidation();
  initializeTables();
  initializeTooltips();
  initializeProgressBars();
  initializeLoadingStates();
});

// Theme functionality
function initializeTheme() {
  const themeToggle = document.getElementById('theme-toggle');
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');

  // Check for saved theme preference or default to system preference
  const savedTheme = localStorage.getItem('theme');
  const currentTheme = savedTheme || (prefersDark.matches ? 'dark' : 'light');

  // Apply initial theme
  setTheme(currentTheme);

  // Theme toggle event listener
  if (themeToggle) {
    themeToggle.addEventListener('click', function() {
      const currentTheme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
      const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
      setTheme(newTheme);
      localStorage.setItem('theme', newTheme);
    });
  }

  // Listen for system theme changes
  prefersDark.addEventListener('change', function(e) {
    if (!localStorage.getItem('theme')) {
      setTheme(e.matches ? 'dark' : 'light');
    }
  });
}

function setTheme(theme) {
  if (theme === 'dark') {
    document.documentElement.classList.add('dark');
  } else {
    document.documentElement.classList.remove('dark');
  }

  // Update theme toggle icon
  const themeToggle = document.getElementById('theme-toggle');
  if (themeToggle) {
    const sunIcon = themeToggle.querySelector('.sun-icon');
    const moonIcon = themeToggle.querySelector('.moon-icon');

    if (theme === 'dark') {
      sunIcon?.classList.remove('hidden');
      moonIcon?.classList.add('hidden');
    } else {
      sunIcon?.classList.add('hidden');
      moonIcon?.classList.remove('hidden');
    }
  }
}

// Navigation functionality
function initializeNavigation() {
  const mobileMenuButton = document.getElementById('mobile-menu-button');
  const mobileMenu = document.getElementById('mobile-menu');

  if (mobileMenuButton && mobileMenu) {
    mobileMenuButton.addEventListener('click', function() {
      const isExpanded = mobileMenuButton.getAttribute('aria-expanded') === 'true';
      mobileMenuButton.setAttribute('aria-expanded', !isExpanded);
      mobileMenu.classList.toggle('hidden');

      // Update button icon
      const openIcon = mobileMenuButton.querySelector('.menu-open');
      const closeIcon = mobileMenuButton.querySelector('.menu-close');

      if (isExpanded) {
        openIcon?.classList.remove('hidden');
        closeIcon?.classList.add('hidden');
      } else {
        openIcon?.classList.add('hidden');
        closeIcon?.classList.remove('hidden');
      }
    });
  }

  // Close mobile menu when clicking outside
  document.addEventListener('click', function(event) {
    if (mobileMenu && mobileMenuButton &&
        !mobileMenu.contains(event.target) &&
        !mobileMenuButton.contains(event.target)) {
      mobileMenu.classList.add('hidden');
      mobileMenuButton.setAttribute('aria-expanded', 'false');
    }
  });
}

// Form validation
function initializeFormValidation() {
  const forms = document.querySelectorAll('form[data-validate]');

  forms.forEach(form => {
    const inputs = form.querySelectorAll('input, textarea, select');

    inputs.forEach(input => {
      input.addEventListener('blur', function() {
        validateField(this);
      });

      input.addEventListener('input', function() {
        if (this.classList.contains('form-error')) {
          validateField(this);
        }
      });
    });

    form.addEventListener('submit', function(e) {
      let isValid = true;

      inputs.forEach(input => {
        if (!validateField(input)) {
          isValid = false;
        }
      });

      if (!isValid) {
        e.preventDefault();
        const firstError = form.querySelector('.form-error');
        if (firstError) {
          firstError.focus();
        }
      }
    });
  });
}

function validateField(field) {
  const value = field.value.trim();
  const rules = field.dataset.validate ? field.dataset.validate.split('|') : [];
  let isValid = true;
  let errorMessage = '';

  // Clear previous error state
  field.classList.remove('form-error');
  const existingError = field.parentNode.querySelector('.error-message');
  if (existingError) {
    existingError.remove();
  }

  // Validate each rule
  for (const rule of rules) {
    const [ruleName, ruleValue] = rule.split(':');

    switch (ruleName) {
      case 'required':
        if (!value) {
          isValid = false;
          errorMessage = 'This field is required.';
        }
        break;

      case 'email':
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (value && !emailRegex.test(value)) {
          isValid = false;
          errorMessage = 'Please enter a valid email address.';
        }
        break;

      case 'url':
        try {
          if (value) {
            new URL(value);
          }
        } catch {
          isValid = false;
          errorMessage = 'Please enter a valid URL.';
        }
        break;

      case 'min':
        if (value.length < parseInt(ruleValue)) {
          isValid = false;
          errorMessage = `Must be at least ${ruleValue} characters.`;
        }
        break;

      case 'max':
        if (value.length > parseInt(ruleValue)) {
          isValid = false;
          errorMessage = `Must be no more than ${ruleValue} characters.`;
        }
        break;
    }

    if (!isValid) break;
  }

  // Show error if validation failed
  if (!isValid) {
    field.classList.add('form-error');
    const errorElement = document.createElement('div');
    errorElement.className = 'error-message';
    errorElement.textContent = errorMessage;
    field.parentNode.appendChild(errorElement);
  }

  return isValid;
}

// Enhanced table functionality
function initializeTables() {
  const tables = document.querySelectorAll('.data-table');

  tables.forEach(table => {
    // Add sortable functionality
    const headers = table.querySelectorAll('th[data-sortable]');
    headers.forEach(header => {
      header.style.cursor = 'pointer';
      header.addEventListener('click', function() {
        sortTable(table, this);
      });
    });

    // Add row selection
    const checkboxes = table.querySelectorAll('input[type="checkbox"][data-row-select]');
    const selectAll = table.querySelector('input[type="checkbox"][data-select-all]');

    if (selectAll) {
      selectAll.addEventListener('change', function() {
        checkboxes.forEach(checkbox => {
          checkbox.checked = this.checked;
          toggleRowSelection(checkbox.closest('tr'), this.checked);
        });
      });
    }

    checkboxes.forEach(checkbox => {
      checkbox.addEventListener('change', function() {
        toggleRowSelection(this.closest('tr'), this.checked);
        updateSelectAllState(table);
      });
    });
  });
}

function sortTable(table, header) {
  const column = Array.from(header.parentNode.children).indexOf(header);
  const rows = Array.from(table.querySelector('tbody').rows);
  const isAscending = header.classList.contains('sort-asc');

  // Clear all sort classes
  table.querySelectorAll('th').forEach(th => {
    th.classList.remove('sort-asc', 'sort-desc');
  });

  // Add appropriate sort class
  header.classList.add(isAscending ? 'sort-desc' : 'sort-asc');

  // Sort rows
  rows.sort((a, b) => {
    const aText = a.cells[column].textContent.trim();
    const bText = b.cells[column].textContent.trim();

    const aNum = parseFloat(aText);
    const bNum = parseFloat(bText);

    let comparison;
    if (!isNaN(aNum) && !isNaN(bNum)) {
      comparison = aNum - bNum;
    } else {
      comparison = aText.localeCompare(bText);
    }

    return isAscending ? -comparison : comparison;
  });

  // Reorder rows in table
  const tbody = table.querySelector('tbody');
  rows.forEach(row => tbody.appendChild(row));
}

function toggleRowSelection(row, selected) {
  if (selected) {
    row.classList.add('bg-primary-50');
  } else {
    row.classList.remove('bg-primary-50');
  }
}

function updateSelectAllState(table) {
  const selectAll = table.querySelector('input[type="checkbox"][data-select-all]');
  const checkboxes = table.querySelectorAll('input[type="checkbox"][data-row-select]');

  if (selectAll && checkboxes.length > 0) {
    const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
    selectAll.checked = checkedCount === checkboxes.length;
    selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
  }
}

// Tooltip functionality
function initializeTooltips() {
  const tooltipTriggers = document.querySelectorAll('[data-tooltip]');

  tooltipTriggers.forEach(trigger => {
    let tooltip = null;

    trigger.addEventListener('mouseenter', function() {
      tooltip = createTooltip(this.dataset.tooltip, this);
      document.body.appendChild(tooltip);
      positionTooltip(tooltip, this);
      setTimeout(() => tooltip.classList.add('show'), 10);
    });

    trigger.addEventListener('mouseleave', function() {
      if (tooltip) {
        tooltip.classList.remove('show');
        setTimeout(() => tooltip.remove(), 300);
      }
    });
  });
}

function createTooltip(text, trigger) {
  const tooltip = document.createElement('div');
  tooltip.className = 'tooltip';
  tooltip.textContent = text;
  return tooltip;
}

function positionTooltip(tooltip, trigger) {
  const triggerRect = trigger.getBoundingClientRect();
  const tooltipRect = tooltip.getBoundingClientRect();

  const top = triggerRect.top - tooltipRect.height - 8;
  const left = triggerRect.left + (triggerRect.width - tooltipRect.width) / 2;

  tooltip.style.top = `${top}px`;
  tooltip.style.left = `${left}px`;
}

// Progress bar animations
function initializeProgressBars() {
  const progressBars = document.querySelectorAll('.progress-fill[data-progress]');

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const progressFill = entry.target;
        const progress = parseInt(progressFill.dataset.progress);
        progressFill.style.width = `${progress}%`;
      }
    });
  });

  progressBars.forEach(bar => observer.observe(bar));
}

// Loading states
function initializeLoadingStates() {
  // Show loading overlay for forms
  const forms = document.querySelectorAll('form[data-loading]');

  forms.forEach(form => {
    form.addEventListener('submit', function() {
      showLoadingOverlay();
    });
  });

  // Show loading for AJAX requests
  window.showLoading = showLoadingOverlay;
  window.hideLoading = hideLoadingOverlay;
}

function showLoadingOverlay(message = 'Loading...', options = {}) {
  // Remove existing overlay if present
  hideLoadingOverlay();

  const overlay = document.createElement('div');
  overlay.id = 'loading-overlay';
  overlay.className = 'loading-overlay fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';

  const showProgress = options.progress !== undefined;
  const progressValue = options.progress || 0;

  overlay.innerHTML = `
    <div class="loading-content bg-white dark:bg-gray-800 rounded-lg p-6 max-w-sm mx-4 text-center shadow-lg">
      <div class="loading-spinner mx-auto mb-4 w-8 h-8 border-4 border-primary-200 border-t-primary-600 rounded-full animate-spin"></div>
      <p class="text-secondary-900 dark:text-white font-medium mb-2">${message}</p>
      ${showProgress ? `
        <div class="progress-container mt-4">
          <div class="progress-bar bg-gray-200 dark:bg-gray-700 rounded-full h-2 overflow-hidden">
            <div class="progress-fill bg-primary-600 h-full transition-all duration-300 ease-out"
                 style="width: ${progressValue}%"></div>
          </div>
          <p class="text-sm text-secondary-600 dark:text-secondary-400 mt-2">
            <span class="progress-percentage">${progressValue}</span>% complete
          </p>
        </div>
      ` : ''}
      ${options.cancellable ? `
        <button onclick="cancelLoading()"
                class="mt-4 btn btn-ghost btn-sm">
          Cancel
        </button>
      ` : ''}
    </div>
  `;

  document.body.appendChild(overlay);

  // Store cancellation handler if provided
  if (options.onCancel) {
    window.loadingCancelHandler = options.onCancel;
  }

  return overlay;
}

function updateLoadingProgress(percentage, message = null) {
  const overlay = document.getElementById('loading-overlay');
  if (overlay) {
    const progressFill = overlay.querySelector('.progress-fill');
    const progressPercentage = overlay.querySelector('.progress-percentage');
    const messageElement = overlay.querySelector('.loading-content p');

    if (progressFill) {
      progressFill.style.width = `${percentage}%`;
    }

    if (progressPercentage) {
      progressPercentage.textContent = Math.round(percentage);
    }

    if (message && messageElement) {
      messageElement.textContent = message;
    }
  }
}

function hideLoadingOverlay() {
  const overlay = document.getElementById('loading-overlay');
  if (overlay) {
    overlay.remove();
  }

  // Clear cancellation handler
  delete window.loadingCancelHandler;
}

function cancelLoading() {
  if (window.loadingCancelHandler) {
    window.loadingCancelHandler();
  }
  hideLoadingOverlay();
}

// Enhanced AJAX utilities with error handling and retry
window.ajax = {
  get: function(url, options = {}) {
    return this.request('GET', url, null, options);
  },

  post: function(url, data, options = {}) {
    return this.request('POST', url, data, options);
  },

  put: function(url, data, options = {}) {
    return this.request('PUT', url, data, options);
  },

  delete: function(url, options = {}) {
    return this.request('DELETE', url, null, options);
  },

  request: function(method, url, data, options = {}) {
    const defaultOptions = {
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      showLoading: true,
      showErrors: true,
      retry: false,
      retryOptions: {
        maxRetries: 3,
        baseDelay: 1000
      },
      timeout: 30000,
      onProgress: null,
      onUploadProgress: null
    };

    const finalOptions = { ...defaultOptions, ...options };
    const requestId = `${method}_${url}_${Date.now()}`;

    // Show loading overlay if requested
    if (finalOptions.showLoading) {
      showLoadingOverlay(finalOptions.loadingMessage || 'Loading...');
    }

    // Setup abort controller for timeout
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), finalOptions.timeout);

    const fetchOptions = {
      method: method,
      headers: finalOptions.headers,
      signal: controller.signal
    };

    // Add request body for non-GET requests
    if (data && method !== 'GET') {
      if (data instanceof FormData) {
        // Remove content-type header for FormData (let browser set it)
        delete fetchOptions.headers['Content-Type'];
        fetchOptions.body = data;
      } else {
        fetchOptions.body = JSON.stringify(data);
      }
    }

    const makeRequest = async () => {
      try {
        const response = await fetch(url, fetchOptions);

        // Clear timeout
        clearTimeout(timeoutId);

        // Handle different response types
        if (!response.ok) {
          const error = new Error(`HTTP ${response.status}: ${response.statusText}`);
          error.response = response;
          error.status = response.status;

          // Try to extract error message from response
          try {
            const errorData = await response.json();
            if (errorData.error) {
              error.message = errorData.error;
            } else if (errorData.message) {
              error.message = errorData.message;
            }
          } catch (parseError) {
            // Use default error message if can't parse response
          }

          throw error;
        }

        // Parse response
        const contentType = response.headers.get('content-type');
        let result;

        if (contentType && contentType.includes('application/json')) {
          result = await response.json();
        } else {
          result = await response.text();
        }

        // Handle progress for streaming responses
        if (finalOptions.onProgress && response.body) {
          const reader = response.body.getReader();
          const contentLength = +response.headers.get('Content-Length');
          let receivedLength = 0;

          while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            receivedLength += value.length;
            if (contentLength) {
              const progress = (receivedLength / contentLength) * 100;
              finalOptions.onProgress(progress);
            }
          }
        }

        return result;

      } catch (error) {
        clearTimeout(timeoutId);

        // Handle abort (timeout)
        if (error.name === 'AbortError') {
          const timeoutError = new Error('Request timeout');
          timeoutError.code = 'TIMEOUT';
          throw timeoutError;
        }

        // Handle network errors
        if (!error.response) {
          error.code = 'NETWORK_ERROR';
        }

        throw error;
      }
    };

    // Apply retry logic if enabled
    const executeRequest = async () => {
      if (finalOptions.retry) {
        const retryHandler = {
          async withRetry(asyncFunction, retryOptions) {
            let attempts = 0;
            const maxRetries = retryOptions.maxRetries || 3;
            const baseDelay = retryOptions.baseDelay || 1000;

            while (attempts <= maxRetries) {
              try {
                return await asyncFunction();
              } catch (error) {
                attempts++;

                // Don't retry on client errors (4xx) except 408, 429
                if (error.status && error.status >= 400 && error.status < 500 &&
                    ![408, 429].includes(error.status)) {
                  throw error;
                }

                if (attempts > maxRetries) {
                  throw error;
                }

                // Exponential backoff with jitter
                const delay = baseDelay * Math.pow(2, attempts - 1);
                const jitter = Math.random() * 0.1 * delay;
                await new Promise(resolve => setTimeout(resolve, delay + jitter));
              }
            }
          }
        };

        return retryHandler.withRetry(makeRequest, finalOptions.retryOptions);
      } else {
        return makeRequest();
      }
    };

    return executeRequest()
      .then(result => {
        // Show success notification if configured
        if (finalOptions.successMessage) {
          window.notify.success(finalOptions.successMessage);
        }
        return result;
      })
      .catch(error => {
        // Show error notification if configured
        if (finalOptions.showErrors) {
          const errorMessage = this.formatErrorMessage(error);
          window.notify.error(errorMessage);
        }

        // Log error for debugging
        console.error(`AJAX Request Failed [${requestId}]:`, error);

        throw error;
      })
      .finally(() => {
        // Hide loading overlay
        if (finalOptions.showLoading) {
          hideLoadingOverlay();
        }
      });
  },

  formatErrorMessage: function(error) {
    if (error.code === 'TIMEOUT') {
      return 'Request timed out. Please try again.';
    }
    if (error.code === 'NETWORK_ERROR') {
      return 'Network error. Please check your connection.';
    }
    if (error.status === 401) {
      return 'Authentication required. Please log in.';
    }
    if (error.status === 403) {
      return 'Access denied. You don\'t have permission for this action.';
    }
    if (error.status === 404) {
      return 'The requested resource was not found.';
    }
    if (error.status === 429) {
      return 'Too many requests. Please wait a moment and try again.';
    }
    if (error.status >= 500) {
      return 'Server error. Please try again later.';
    }

    return error.message || 'An unexpected error occurred.';
  },

  // Convenience method for file uploads with progress
  upload: function(url, formData, options = {}) {
    const uploadOptions = {
      ...options,
      showLoading: false, // We'll show custom progress instead
      retry: false // Don't retry file uploads
    };

    return this.request('POST', url, formData, uploadOptions);
  },

  // Batch requests with progress tracking
  batch: async function(requests, options = {}) {
    const { maxConcurrent = 3, onProgress } = options;
    const results = [];
    const errors = [];

    for (let i = 0; i < requests.length; i += maxConcurrent) {
      const batch = requests.slice(i, i + maxConcurrent);
      const batchPromises = batch.map(async (request, index) => {
        try {
          const result = await this.request(
            request.method || 'GET',
            request.url,
            request.data,
            { ...request.options, showLoading: false }
          );
          results[i + index] = result;
        } catch (error) {
          errors[i + index] = error;
        }

        if (onProgress) {
          onProgress(results.length + errors.length, requests.length);
        }
      });

      await Promise.all(batchPromises);
    }

    return { results, errors };
  }
};

// Notification system
window.notify = {
  success: function(message) {
    this.show(message, 'success');
  },

  error: function(message) {
    this.show(message, 'danger');
  },

  warning: function(message) {
    this.show(message, 'warning');
  },

  info: function(message) {
    this.show(message, 'info');
  },

  show: function(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} fixed top-4 right-4 z-50 max-w-sm animate-slide-in-down`;
    notification.innerHTML = `
      <div class="flex items-center justify-between">
        <span>${message}</span>
        <button type="button" class="ml-4 text-current opacity-50 hover:opacity-75" onclick="this.parentElement.parentElement.remove()">
          <span class="sr-only">Close</span>
          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
          </svg>
        </button>
      </div>
    `;

    document.body.appendChild(notification);

    // Auto-remove after 5 seconds
    setTimeout(() => {
      notification.remove();
    }, 5000);
  }
};

    // Real-time Updates Component
    Alpine.data('realTimeUpdates', (endpoint, interval = 30000) => ({
        data: null,
        lastUpdate: null,
        isConnected: true,
        updateInterval: null,
        retryCount: 0,
        maxRetries: 3,

        init() {
            this.startUpdates();
            this.setupVisibilityListener();
        },

        destroy() {
            this.stopUpdates();
        },

        startUpdates() {
            this.updateInterval = setInterval(() => {
                this.fetchUpdates();
            }, interval);

            // Initial fetch
            this.fetchUpdates();
        },

        stopUpdates() {
            if (this.updateInterval) {
                clearInterval(this.updateInterval);
                this.updateInterval = null;
            }
        },

        async fetchUpdates() {
            try {
                const response = await fetch(endpoint, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const newData = await response.json();

                // Check if data has changed
                if (JSON.stringify(newData) !== JSON.stringify(this.data)) {
                    this.data = newData;
                    this.lastUpdate = new Date();
                    this.$dispatch('data-updated', { data: newData });
                }

                this.isConnected = true;
                this.retryCount = 0;

            } catch (error) {
                console.error('Real-time update failed:', error);
                this.isConnected = false;
                this.retryCount++;

                if (this.retryCount >= this.maxRetries) {
                    this.stopUpdates();
                    this.$dispatch('connection-failed', { error });
                } else {
                    // Exponential backoff
                    setTimeout(() => {
                        this.fetchUpdates();
                    }, Math.pow(2, this.retryCount) * 1000);
                }
            }
        },

        setupVisibilityListener() {
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.stopUpdates();
                } else {
                    this.startUpdates();
                }
            });
        },

        forceUpdate() {
            this.fetchUpdates();
        }
    }));

    // Dashboard Real-time Component
    Alpine.data('dashboardUpdates', () => ({
        websites: [],
        stats: {},
        recentTests: [],
        alerts: [],

        init() {
            this.$watch('$store.realTimeData.dashboard', (newData) => {
                if (newData) {
                    this.updateDashboard(newData);
                }
            });
        },

        updateDashboard(data) {
            // Animate changes
            this.animateStatChanges(data.stats);
            this.websites = data.websites || [];
            this.stats = data.stats || {};
            this.recentTests = data.recentTests || [];
            this.alerts = data.alerts || [];

            // Show notification for new alerts
            if (data.newAlerts && data.newAlerts.length > 0) {
                data.newAlerts.forEach(alert => {
                    window.notify[alert.type](alert.message);
                });
            }
        },

        animateStatChanges(newStats) {
            Object.keys(newStats || {}).forEach(key => {
                const element = document.querySelector(`[data-stat="${key}"]`);
                if (element && this.stats[key] !== newStats[key]) {
                    element.classList.add('stat-updated');
                    setTimeout(() => {
                        element.classList.remove('stat-updated');
                    }, 1000);
                }
            });
        }
    }));

    // WebSocket Alternative for Real-time Updates
    Alpine.data('liveUpdates', (channels = []) => ({
        connections: new Map(),
        reconnectAttempts: new Map(),
        maxReconnectAttempts: 5,

        init() {
            channels.forEach(channel => {
                this.subscribe(channel);
            });
        },

        subscribe(channel) {
            // Use Server-Sent Events as WebSocket alternative
            const eventSource = new EventSource(`/api/live-updates/${channel}`);

            eventSource.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    this.$dispatch('live-update', { channel, data });
                } catch (error) {
                    console.error('Failed to parse live update:', error);
                }
            };

            eventSource.onerror = (error) => {
                console.error('Live update connection error:', error);
                this.handleReconnect(channel);
            };

            this.connections.set(channel, eventSource);
        },

        handleReconnect(channel) {
            const attempts = this.reconnectAttempts.get(channel) || 0;

            if (attempts < this.maxReconnectAttempts) {
                setTimeout(() => {
                    this.subscribe(channel);
                    this.reconnectAttempts.set(channel, attempts + 1);
                }, Math.pow(2, attempts) * 1000);
            }
        },

        unsubscribe(channel) {
            const connection = this.connections.get(channel);
            if (connection) {
                connection.close();
                this.connections.delete(channel);
            }
        },

        destroy() {
            this.connections.forEach((connection, channel) => {
                this.unsubscribe(channel);
            });
        }
    }));

    // Auto-refresh Component for Tables and Lists
    Alpine.data('autoRefresh', (options = {}) => ({
        refreshInterval: options.interval || 60000,
        isAutoRefreshing: options.autoStart !== false,
        intervalId: null,
        lastRefresh: null,

        init() {
            if (this.isAutoRefreshing) {
                this.startAutoRefresh();
            }
        },

        startAutoRefresh() {
            this.isAutoRefreshing = true;
            this.intervalId = setInterval(() => {
                this.refresh();
            }, this.refreshInterval);
        },

        stopAutoRefresh() {
            this.isAutoRefreshing = false;
            if (this.intervalId) {
                clearInterval(this.intervalId);
                this.intervalId = null;
            }
        },

        toggleAutoRefresh() {
            if (this.isAutoRefreshing) {
                this.stopAutoRefresh();
            } else {
                this.startAutoRefresh();
            }
        },

        async refresh() {
            this.lastRefresh = new Date();
            this.$dispatch('refresh-requested');

            // Refresh current page data
            if (options.endpoint) {
                try {
                    const response = await fetch(options.endpoint, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    if (response.ok) {
                        const data = await response.json();
                        this.$dispatch('refresh-completed', { data });
                    }
                } catch (error) {
                    console.error('Auto-refresh failed:', error);
                    this.$dispatch('refresh-failed', { error });
                }
            }
        },

        destroy() {
            this.stopAutoRefresh();
        }
    }));

    // Global stores for real-time data
    Alpine.store('realTimeData', {
        dashboard: null,
        notifications: [],
        testResults: new Map(),
        websiteStatuses: new Map(),

        updateDashboard(data) {
            this.dashboard = data;
        },

        addNotification(notification) {
            notification.id = Date.now() + Math.random();
            notification.timestamp = new Date();
            this.notifications.unshift(notification);

            // Keep only last 50 notifications
            if (this.notifications.length > 50) {
                this.notifications = this.notifications.slice(0, 50);
            }
        },

        updateTestResult(websiteId, testData) {
            this.testResults.set(websiteId, testData);
        },

        updateWebsiteStatus(websiteId, status) {
            this.websiteStatuses.set(websiteId, status);
        }
    });
});

// Enhanced AJAX utilities with real-time capabilities
window.ajaxRealTime = {
    // Polling mechanism for real-time updates
    poll: function(url, callback, interval = 30000) {
        const pollId = setInterval(async () => {
            try {
                const response = await fetch(url, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (response.ok) {
                    const data = await response.json();
                    callback(data);
                }
            } catch (error) {
                console.error('Polling failed:', error);
            }
        }, interval);

        return {
            stop: () => clearInterval(pollId),
            id: pollId
        };
    },

    // Long polling for immediate updates
    longPoll: async function(url, callback, timeout = 30000) {
        try {
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                signal: AbortSignal.timeout(timeout)
            });

            if (response.ok) {
                const data = await response.json();
                callback(data);

                // Continue long polling
                setTimeout(() => {
                    this.longPoll(url, callback, timeout);
                }, 1000);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Long polling failed:', error);
            }

            // Retry after delay
            setTimeout(() => {
                this.longPoll(url, callback, timeout);
            }, 5000);
        }
    },

    // Server-sent events for real-time updates
    events: function(url, callbacks = {}) {
        const eventSource = new EventSource(url);

        // Default event handlers
        eventSource.onmessage = function(event) {
            try {
                const data = JSON.parse(event.data);
                if (callbacks.message) {
                    callbacks.message(data);
                }
            } catch (error) {
                console.error('Failed to parse SSE data:', error);
            }
        };

        eventSource.onerror = function(error) {
            console.error('SSE connection error:', error);
            if (callbacks.error) {
                callbacks.error(error);
            }
        };

        eventSource.onopen = function() {
            if (callbacks.open) {
                callbacks.open();
            }
        };

        // Custom event handlers
        Object.keys(callbacks).forEach(eventType => {
            if (!['message', 'error', 'open'].includes(eventType)) {
                eventSource.addEventListener(eventType, callbacks[eventType]);
            }
        });

        return {
            close: () => eventSource.close(),
            source: eventSource
        };
    }
};

// Real-time dashboard updates
window.initializeDashboardUpdates = function() {
    // Poll for dashboard updates every 30 seconds
    const dashboardPoller = window.ajaxRealTime.poll('/api/dashboard/updates', (data) => {
        // Update Alpine store
        Alpine.store('realTimeData').updateDashboard(data);

        // Update page elements
        updateDashboardStats(data.stats);
        updateRecentTests(data.recentTests);
        updateAlerts(data.alerts);
    });

    // Store poller reference for cleanup
    window.dashboardPoller = dashboardPoller;
};

function updateDashboardStats(stats) {
    Object.keys(stats).forEach(key => {
        const element = document.querySelector(`[data-stat="${key}"] .stat-value`);
        if (element && element.textContent !== stats[key].toString()) {
            // Animate the change
            element.classList.add('animate-pulse');
            element.textContent = stats[key];

            setTimeout(() => {
                element.classList.remove('animate-pulse');
            }, 1000);
        }
    });
}

function updateRecentTests(tests) {
    const container = document.querySelector('#recent-tests-container');
    if (container && tests) {
        // Create new test elements
        const newContent = tests.map(test => `
            <div class="flex items-center justify-between p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-3">
                    <div class="w-3 h-3 rounded-full ${getStatusColor(test.status)}"></div>
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">${test.website_name}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">${test.test_name}</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-sm font-medium ${getStatusTextColor(test.status)}">${test.status}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">${formatTime(test.completed_at)}</p>
                </div>
            </div>
        `).join('');

        container.innerHTML = newContent;
    }
}

function updateAlerts(alerts) {
    const container = document.querySelector('#alerts-container');
    if (container && alerts) {
        const newContent = alerts.map(alert => `
            <div class="p-4 rounded-lg ${getAlertClass(alert.type)} border">
                <div class="flex items-start">
                    <div class="mr-3 mt-1">
                        ${getAlertIcon(alert.type)}
                    </div>
                    <div class="flex-1">
                        <h4 class="font-medium">${alert.title}</h4>
                        <p class="mt-1 text-sm">${alert.message}</p>
                        <p class="mt-2 text-xs opacity-75">${formatTime(alert.created_at)}</p>
                    </div>
                </div>
            </div>
        `).join('');

        container.innerHTML = newContent;
    }
}

// Helper functions for status colors and formatting
function getStatusColor(status) {
    const colors = {
        'passed': 'bg-green-500',
        'failed': 'bg-red-500',
        'warning': 'bg-yellow-500',
        'running': 'bg-blue-500 animate-pulse'
    };
    return colors[status] || 'bg-gray-500';
}

function getStatusTextColor(status) {
    const colors = {
        'passed': 'text-green-600 dark:text-green-400',
        'failed': 'text-red-600 dark:text-red-400',
        'warning': 'text-yellow-600 dark:text-yellow-400',
        'running': 'text-blue-600 dark:text-blue-400'
    };
    return colors[status] || 'text-gray-600 dark:text-gray-400';
}

function getAlertClass(type) {
    const classes = {
        'error': 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-200',
        'warning': 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800 text-yellow-800 dark:text-yellow-200',
        'info': 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-200',
        'success': 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-200'
    };
    return classes[type] || classes.info;
}

function getAlertIcon(type) {
    const icons = {
        'error': '<svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>',
        'warning': '<svg class="w-5 h-5 text-yellow-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>',
        'info': '<svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>',
        'success': '<svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>'
    };
    return icons[type] || icons.info;
}

function formatTime(timestamp) {
    const date = new Date(timestamp);
    const now = new Date();
    const diff = now - date;

    if (diff < 60000) return 'Just now';
    if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`;
    if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`;
    return date.toLocaleDateString();
}

// Initialize real-time updates when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Initialize dashboard updates if on dashboard page
    if (document.querySelector('[data-page="dashboard"]')) {
        window.initializeDashboardUpdates();
    }

    // Initialize result updates if on results page
    if (document.querySelector('[data-page="results"]')) {
        window.initializeResultUpdates();
    }
});

// Real-time result updates
window.initializeResultUpdates = function() {
    const resultsPoller = window.ajaxRealTime.poll('/api/test-results/updates', (data) => {
        updateTestResults(data);
    }, 15000); // Check every 15 seconds for result updates

    window.resultsPoller = resultsPoller;
};

function updateTestResults(data) {
    data.forEach(result => {
        const element = document.querySelector(`[data-test-result="${result.id}"]`);
        if (element) {
            // Update status indicator
            const statusIndicator = element.querySelector('.status-indicator');
            if (statusIndicator) {
                statusIndicator.className = `status-indicator w-3 h-3 rounded-full ${getStatusColor(result.status)}`;
            }

            // Update status text
            const statusText = element.querySelector('.status-text');
            if (statusText) {
                statusText.textContent = result.status;
                statusText.className = `status-text font-medium ${getStatusTextColor(result.status)}`;
            }

            // Update completion time
            const timeElement = element.querySelector('.completion-time');
            if (timeElement && result.completed_at) {
                timeElement.textContent = formatTime(result.completed_at);
            }

            // Add visual feedback for updates
            element.classList.add('animate-pulse');
            setTimeout(() => {
                element.classList.remove('animate-pulse');
            }, 1000);
        }
    });
}

// Cleanup function for when leaving pages
window.cleanupRealTimeUpdates = function() {
    if (window.dashboardPoller) {
        window.dashboardPoller.stop();
        window.dashboardPoller = null;
    }

    if (window.resultsPoller) {
        window.resultsPoller.stop();
        window.resultsPoller = null;
    }
};

// Cleanup on page unload
window.addEventListener('beforeunload', window.cleanupRealTimeUpdates);

// Start Alpine.js
Alpine.start();