<?php
// Reusable Pagination Alpine.js Component Template
// Usage: include this in views where pagination is needed
?>

<!-- Pagination Component -->
<div x-data="pagination(<?= $itemsPerPage ?? 10 ?>)"
     x-init="setTotalItems(<?= $totalItems ?? 0 ?>)"
     class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

    <!-- Results Info -->
    <div class="text-sm text-secondary-700 dark:text-secondary-300">
        Showing <span class="font-medium" x-text="startIndex + 1"></span> to
        <span class="font-medium" x-text="endIndex"></span> of
        <span class="font-medium" x-text="totalItems"></span> results
    </div>

    <!-- Pagination Controls -->
    <div x-show="totalPages > 1" class="flex items-center gap-2">
        <!-- Previous Button -->
        <button @click="prevPage()"
                :disabled="currentPage === 1"
                class="btn btn-outline btn-sm"
                :class="{ 'opacity-50 cursor-not-allowed': currentPage === 1 }">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            Previous
        </button>

        <!-- Page Numbers -->
        <div class="flex items-center gap-1">
            <!-- First page (if not in visible range) -->
            <template x-if="visiblePages[0] > 1">
                <div class="flex items-center gap-1">
                    <button @click="goToPage(1)"
                            class="w-8 h-8 flex items-center justify-center text-sm rounded-lg border border-secondary-300 dark:border-secondary-600 hover:bg-secondary-100 dark:hover:bg-secondary-700 transition-colors">
                        1
                    </button>
                    <template x-if="visiblePages[0] > 2">
                        <span class="text-secondary-400 dark:text-secondary-500 px-1">...</span>
                    </template>
                </div>
            </template>

            <!-- Visible page numbers -->
            <template x-for="page in visiblePages" :key="page">
                <button @click="goToPage(page)"
                        class="w-8 h-8 flex items-center justify-center text-sm rounded-lg border transition-colors"
                        :class="{
                            'bg-primary-600 border-primary-600 text-white': page === currentPage,
                            'border-secondary-300 dark:border-secondary-600 hover:bg-secondary-100 dark:hover:bg-secondary-700': page !== currentPage
                        }"
                        x-text="page">
                </button>
            </template>

            <!-- Last page (if not in visible range) -->
            <template x-if="visiblePages[visiblePages.length - 1] < totalPages">
                <div class="flex items-center gap-1">
                    <template x-if="visiblePages[visiblePages.length - 1] < totalPages - 1">
                        <span class="text-secondary-400 dark:text-secondary-500 px-1">...</span>
                    </template>
                    <button @click="goToPage(totalPages)"
                            class="w-8 h-8 flex items-center justify-center text-sm rounded-lg border border-secondary-300 dark:border-secondary-600 hover:bg-secondary-100 dark:hover:bg-secondary-700 transition-colors"
                            x-text="totalPages">
                    </button>
                </div>
            </template>
        </div>

        <!-- Next Button -->
        <button @click="nextPage()"
                :disabled="currentPage === totalPages"
                class="btn btn-outline btn-sm"
                :class="{ 'opacity-50 cursor-not-allowed': currentPage === totalPages }">
            Next
            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </button>
    </div>

    <!-- Items Per Page Selector -->
    <?php if ($showPerPageSelector ?? false): ?>
    <div class="flex items-center gap-2 text-sm">
        <label for="items-per-page" class="text-secondary-700 dark:text-secondary-300">Show:</label>
        <select id="items-per-page"
                x-model="itemsPerPage"
                @change="currentPage = 1; setTotalItems(totalItems)"
                class="form-input py-1 text-sm">
            <?php foreach (($perPageOptions ?? [10, 25, 50, 100]) as $option): ?>
            <option value="<?= $option ?>"><?= $option ?></option>
            <?php endforeach; ?>
        </select>
        <span class="text-secondary-700 dark:text-secondary-300">per page</span>
    </div>
    <?php endif; ?>
</div>

<!-- Mobile-only simplified pagination -->
<div class="block sm:hidden mt-4" x-show="totalPages > 1">
    <div class="flex justify-between items-center">
        <button @click="prevPage()"
                :disabled="currentPage === 1"
                class="btn btn-outline btn-sm flex-1 mr-2"
                :class="{ 'opacity-50 cursor-not-allowed': currentPage === 1 }">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            Previous
        </button>

        <div class="px-4 py-2 text-sm text-secondary-700 dark:text-secondary-300 text-center min-w-0">
            Page <span x-text="currentPage"></span> of <span x-text="totalPages"></span>
        </div>

        <button @click="nextPage()"
                :disabled="currentPage === totalPages"
                class="btn btn-outline btn-sm flex-1 ml-2"
                :class="{ 'opacity-50 cursor-not-allowed': currentPage === totalPages }">
            Next
            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </button>
    </div>
</div>