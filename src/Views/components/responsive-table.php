<?php
/**
 * Responsive Data Table Component
 *
 * Features:
 * - Mobile-first responsive design
 * - Sorting, filtering, pagination
 * - Bulk selection
 * - Real-time updates
 * - Accessibility compliant
 */

$columns = $columns ?? [];
$data = $data ?? [];
$sortable = $sortable ?? true;
$filterable = $filterable ?? true;
$selectable = $selectable ?? false;
$pagination = $pagination ?? true;
$itemsPerPage = $itemsPerPage ?? 10;
$tableId = $tableId ?? 'data-table-' . uniqid();
$searchPlaceholder = $searchPlaceholder ?? 'Search...';
$emptyMessage = $emptyMessage ?? 'No data available';
$loadingComponent = $loadingComponent ?? true;
?>

<div x-data="responsiveTable(<?= htmlspecialchars(json_encode($data)) ?>, {
        columns: <?= htmlspecialchars(json_encode($columns)) ?>,
        sortable: <?= $sortable ? 'true' : 'false' ?>,
        filterable: <?= $filterable ? 'true' : 'false' ?>,
        selectable: <?= $selectable ? 'true' : 'false' ?>,
        pagination: <?= $pagination ? 'true' : 'false' ?>,
        itemsPerPage: <?= $itemsPerPage ?>
     })"
     x-init="init()"
     class="responsive-table-container"
     id="<?= $tableId ?>">

    <!-- Table Header Controls -->
    <div class="table-controls mb-4 space-y-4 lg:space-y-0 lg:flex lg:items-center lg:justify-between">
        <!-- Search and Filters -->
        <div class="flex-1 max-w-lg">
            <?php if ($filterable): ?>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    <input x-model="searchTerm"
                           @input.debounce.300ms="performSearch()"
                           type="text"
                           placeholder="<?= htmlspecialchars($searchPlaceholder) ?>"
                           class="block w-full pl-10 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md leading-5 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                </div>
            <?php endif; ?>
        </div>

        <!-- Table Actions -->
        <div class="flex items-center space-x-3">
            <!-- Bulk Actions -->
            <?php if ($selectable): ?>
                <div x-show="selectedRows.length > 0" x-transition class="flex items-center space-x-2">
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        <span x-text="selectedRows.length"></span> selected
                    </span>
                    <button @click="$dispatch('bulk-action', { action: 'delete', items: getSelectedData() })"
                            class="btn btn-danger btn-sm">
                        Delete Selected
                    </button>
                </div>
            <?php endif; ?>

            <!-- View Toggle -->
            <div class="lg:hidden">
                <button @click="viewMode = viewMode === 'table' ? 'cards' : 'table'"
                        class="btn btn-ghost btn-sm">
                    <svg x-show="viewMode === 'cards'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                    </svg>
                    <svg x-show="viewMode === 'table'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                </button>
            </div>

            <!-- Refresh Button -->
            <button @click="$dispatch('table-refresh')"
                    class="btn btn-ghost btn-sm"
                    :disabled="loading">
                <svg class="w-4 h-4" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
            </button>
        </div>
    </div>

    <!-- Loading State -->
    <?php if ($loadingComponent): ?>
        <div x-show="loading" x-transition>
            <?php include __DIR__ . '/loading-state.php'; ?>
        </div>
    <?php endif; ?>

    <!-- Table Content -->
    <div x-show="!loading" x-transition>
        <!-- Desktop Table View -->
        <div x-show="viewMode === 'table'" class="hidden lg:block overflow-hidden shadow ring-1 ring-black ring-opacity-5 rounded-lg">
            <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-600">
                <!-- Table Header -->
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <?php if ($selectable): ?>
                            <th scope="col" class="relative w-12 px-6 sm:w-16 sm:px-8">
                                <input type="checkbox"
                                       @change="toggleSelectAll()"
                                       :checked="selectAll"
                                       :indeterminate="selectedRows.length > 0 && selectedRows.length < paginatedData.length"
                                       class="absolute left-4 top-1/2 -mt-2 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            </th>
                        <?php endif; ?>

                        <template x-for="column in columns" :key="column.key">
                            <th scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"
                                :class="{ 'cursor-pointer select-none hover:bg-gray-100 dark:hover:bg-gray-700': column.sortable }"
                                @click="column.sortable && sortBy(column.key)">
                                <div class="flex items-center space-x-1">
                                    <span x-text="column.label"></span>
                                    <template x-if="column.sortable">
                                        <div class="flex flex-col">
                                            <svg class="w-3 h-3" :class="sortColumn === column.key && sortDirection === 'asc' ? 'text-primary-600' : 'text-gray-400'" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd"></path>
                                            </svg>
                                            <svg class="w-3 h-3 -mt-1" :class="sortColumn === column.key && sortDirection === 'desc' ? 'text-primary-600' : 'text-gray-400'" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                    </template>
                                </div>
                            </th>
                        </template>

                        <!-- Actions Column -->
                        <th scope="col" class="relative px-6 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <!-- Table Body -->
                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                    <template x-for="(item, index) in paginatedData" :key="item.id || index">
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                            :class="{ 'bg-primary-50 dark:bg-primary-900/20': isRowSelected(index) }">

                            <?php if ($selectable): ?>
                                <td class="relative w-12 px-6 sm:w-16 sm:px-8">
                                    <input type="checkbox"
                                           @change="toggleRowSelection(index)"
                                           :checked="isRowSelected(index)"
                                           class="absolute left-4 top-1/2 -mt-2 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                                </td>
                            <?php endif; ?>

                            <template x-for="column in columns" :key="column.key">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <div x-html="formatCellValue(item, column)"></div>
                                </td>
                            </template>

                            <!-- Actions Cell -->
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end space-x-2">
                                    <button @click="$dispatch('row-action', { action: 'view', item: item })"
                                            class="text-primary-600 hover:text-primary-900 dark:text-primary-400 dark:hover:text-primary-200">
                                        View
                                    </button>
                                    <button @click="$dispatch('row-action', { action: 'edit', item: item })"
                                            class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-200">
                                        Edit
                                    </button>
                                    <button @click="$dispatch('row-action', { action: 'delete', item: item })"
                                            class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-200">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Mobile Cards View -->
        <div x-show="viewMode === 'cards'" class="lg:hidden space-y-4">
            <template x-for="(item, index) in paginatedData" :key="item.id || index">
                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4 shadow-sm">
                    <!-- Card Header -->
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center space-x-3">
                            <?php if ($selectable): ?>
                                <input type="checkbox"
                                       @change="toggleRowSelection(index)"
                                       :checked="isRowSelected(index)"
                                       class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            <?php endif; ?>
                            <div class="font-medium text-gray-900 dark:text-white" x-text="item[primaryColumn] || item.name || item.title"></div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button @click="$dispatch('row-action', { action: 'view', item: item })"
                                    class="btn btn-ghost btn-sm">
                                View
                            </button>
                            <div x-data="dropdown()" class="relative">
                                <button @click="toggle()" class="btn btn-ghost btn-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01"></path>
                                    </svg>
                                </button>
                                <div x-show="open" @click.away="close()" x-transition class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-md shadow-lg z-10 border border-gray-200 dark:border-gray-700">
                                    <div class="py-1">
                                        <button @click="$dispatch('row-action', { action: 'edit', item: item }); close()"
                                                class="block w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                                            Edit
                                        </button>
                                        <button @click="$dispatch('row-action', { action: 'delete', item: item }); close()"
                                                class="block w-full text-left px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-700">
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card Content -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <template x-for="column in columns.slice(1)" :key="column.key">
                            <div x-show="item[column.key] !== undefined && item[column.key] !== null && item[column.key] !== ''">
                                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider" x-text="column.label"></dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-white" x-html="formatCellValue(item, column)"></dd>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <!-- Empty State -->
        <div x-show="filteredData.length === 0" class="text-center py-12">
            <svg class="w-12 h-12 mx-auto text-gray-400 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($emptyMessage) ?></h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                <span x-show="searchTerm">No results match your search criteria.</span>
                <span x-show="!searchTerm">No data to display.</span>
            </p>
            <div x-show="searchTerm" class="mt-4">
                <button @click="clearSearch()" class="btn btn-primary btn-sm">
                    Clear Search
                </button>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($pagination): ?>
        <div x-show="totalPages > 1" x-transition class="mt-6">
            <?php include __DIR__ . '/pagination.php'; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Responsive Table Alpine.js Component
document.addEventListener('alpine:init', () => {
    Alpine.data('responsiveTable', (initialData = [], options = {}) => ({
        // Data properties
        data: initialData,
        filteredData: initialData,
        searchTerm: '',

        // Table configuration
        columns: options.columns || [],
        sortable: options.sortable !== false,
        filterable: options.filterable !== false,
        selectable: options.selectable === true,

        // View state
        viewMode: 'table', // 'table' or 'cards'
        loading: false,

        // Sorting
        sortColumn: null,
        sortDirection: 'asc',

        // Selection
        selectedRows: [],
        selectAll: false,

        // Pagination
        currentPage: 1,
        itemsPerPage: options.itemsPerPage || 10,

        init() {
            this.performSearch();
            this.setPrimaryColumn();

            // Watch for data changes
            this.$watch('data', () => {
                this.performSearch();
            });
        },

        setPrimaryColumn() {
            // Set primary column for mobile cards
            this.primaryColumn = this.columns[0]?.key || 'name';
        },

        // Search and filtering
        performSearch() {
            if (!this.searchTerm.trim()) {
                this.filteredData = [...this.data];
            } else {
                const term = this.searchTerm.toLowerCase();
                this.filteredData = this.data.filter(item => {
                    return this.columns.some(column => {
                        const value = this.getCellValue(item, column);
                        return value && value.toString().toLowerCase().includes(term);
                    });
                });
            }

            this.currentPage = 1;
            this.selectedRows = [];
            this.selectAll = false;
        },

        clearSearch() {
            this.searchTerm = '';
            this.performSearch();
        },

        // Sorting
        sortBy(column) {
            if (this.sortColumn === column) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortColumn = column;
                this.sortDirection = 'asc';
            }

            this.filteredData.sort((a, b) => {
                let aVal = this.getCellValue(a, { key: column });
                let bVal = this.getCellValue(b, { key: column });

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

        // Selection
        toggleSelectAll() {
            this.selectAll = !this.selectAll;
            if (this.selectAll) {
                this.selectedRows = this.paginatedData.map((_, index) => index);
            } else {
                this.selectedRows = [];
            }
        },

        toggleRowSelection(index) {
            if (this.selectedRows.includes(index)) {
                this.selectedRows = this.selectedRows.filter(i => i !== index);
            } else {
                this.selectedRows.push(index);
            }

            this.selectAll = this.selectedRows.length === this.paginatedData.length;
        },

        isRowSelected(index) {
            return this.selectedRows.includes(index);
        },

        getSelectedData() {
            return this.selectedRows.map(index => this.paginatedData[index]);
        },

        // Pagination
        get totalPages() {
            return Math.ceil(this.filteredData.length / this.itemsPerPage);
        },

        get paginatedData() {
            const start = (this.currentPage - 1) * this.itemsPerPage;
            const end = start + this.itemsPerPage;
            return this.filteredData.slice(start, end);
        },

        goToPage(page) {
            if (page >= 1 && page <= this.totalPages) {
                this.currentPage = page;
                this.selectedRows = [];
                this.selectAll = false;
            }
        },

        nextPage() {
            this.goToPage(this.currentPage + 1);
        },

        prevPage() {
            this.goToPage(this.currentPage - 1);
        },

        // Cell formatting
        getCellValue(item, column) {
            if (column.key.includes('.')) {
                // Handle nested properties
                return column.key.split('.').reduce((obj, key) => obj?.[key], item);
            }
            return item[column.key];
        },

        formatCellValue(item, column) {
            const value = this.getCellValue(item, column);

            if (value === null || value === undefined) {
                return '<span class="text-gray-400">—</span>';
            }

            // Apply column formatter if available
            if (column.formatter && typeof column.formatter === 'function') {
                return column.formatter(value, item);
            }

            // Apply built-in formatters
            switch (column.type) {
                case 'date':
                    return new Date(value).toLocaleDateString();

                case 'datetime':
                    return new Date(value).toLocaleString();

                case 'currency':
                    return new Intl.NumberFormat('en-US', {
                        style: 'currency',
                        currency: 'USD'
                    }).format(value);

                case 'number':
                    return new Intl.NumberFormat().format(value);

                case 'badge':
                    const badgeClass = column.badgeColors?.[value] || 'bg-gray-100 text-gray-800';
                    return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${badgeClass}">${value}</span>`;

                case 'link':
                    const href = column.href ? column.href(item) : value;
                    return `<a href="${href}" class="text-primary-600 hover:text-primary-900 dark:text-primary-400 dark:hover:text-primary-200">${value}</a>`;

                default:
                    return String(value);
            }
        },

        // Data management
        updateData(newData) {
            this.data = newData;
        },

        addRow(rowData) {
            this.data.push(rowData);
        },

        removeRow(predicate) {
            this.data = this.data.filter(item => !predicate(item));
        },

        updateRow(predicate, updates) {
            const index = this.data.findIndex(predicate);
            if (index !== -1) {
                this.data[index] = { ...this.data[index], ...updates };
            }
        }
    }));
});
</script>