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

    // Loading State Component
    Alpine.data('loadingState', (initialLoading = false) => ({
        loading: initialLoading,

        setLoading(state) {
            this.loading = state;
        },

        async withLoading(asyncFunction) {
            this.loading = true;
            try {
                return await asyncFunction();
            } finally {
                this.loading = false;
            }
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

function showLoadingOverlay() {
  const overlay = document.createElement('div');
  overlay.id = 'loading-overlay';
  overlay.className = 'loading-overlay';
  overlay.innerHTML = `
    <div class="loading-content">
      <div class="loading-spinner mx-auto mb-4"></div>
      <p class="text-secondary-600">Loading...</p>
    </div>
  `;
  document.body.appendChild(overlay);
}

function hideLoadingOverlay() {
  const overlay = document.getElementById('loading-overlay');
  if (overlay) {
    overlay.remove();
  }
}

// AJAX utilities
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
        'X-Requested-With': 'XMLHttpRequest'
      },
      showLoading: true
    };

    const finalOptions = { ...defaultOptions, ...options };

    if (finalOptions.showLoading) {
      showLoadingOverlay();
    }

    const fetchOptions = {
      method: method,
      headers: finalOptions.headers
    };

    if (data && method !== 'GET') {
      fetchOptions.body = JSON.stringify(data);
    }

    return fetch(url, fetchOptions)
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .finally(() => {
        if (finalOptions.showLoading) {
          hideLoadingOverlay();
        }
      });
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

// Start Alpine.js
Alpine.start();