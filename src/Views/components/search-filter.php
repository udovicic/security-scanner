<?php
// Reusable Search/Filter Alpine.js Component Template
// Usage: include this in views where search functionality is needed
?>

<!-- Search and Filter Component -->
<div x-data="searchFilter(<?= htmlspecialchars(json_encode($data ?? [])) ?>)" class="mb-6">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <!-- Search Input -->
        <div class="relative flex-1">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg class="h-5 w-5 text-secondary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>
            <input type="text"
                   x-model="searchTerm"
                   placeholder="<?= htmlspecialchars($searchPlaceholder ?? 'Search...') ?>"
                   class="form-input pl-10 pr-4 py-2 w-full"
                   aria-label="Search">
        </div>

        <!-- Filter Controls -->
        <?php if (isset($filterOptions) && !empty($filterOptions)): ?>
        <div class="flex flex-wrap gap-3">
            <?php foreach ($filterOptions as $filter): ?>
            <div x-data="{ filterOpen: false }" class="relative">
                <button @click="filterOpen = !filterOpen"
                        class="btn btn-outline flex items-center"
                        :aria-expanded="filterOpen">
                    <span><?= htmlspecialchars($filter['label']) ?></span>
                    <svg class="ml-2 h-4 w-4 transition-transform" :class="{ 'rotate-180': filterOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <div x-show="filterOpen"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     @click.away="filterOpen = false"
                     class="absolute right-0 mt-2 w-48 bg-white dark:bg-secondary-800 rounded-lg shadow-lg border border-secondary-200 dark:border-secondary-700 z-20">
                    <div class="p-3">
                        <?php foreach ($filter['options'] as $option): ?>
                        <label class="flex items-center py-2 cursor-pointer">
                            <input type="checkbox"
                                   value="<?= htmlspecialchars($option['value']) ?>"
                                   class="form-checkbox h-4 w-4 text-primary-600 border-secondary-300 rounded mr-3">
                            <span class="text-sm text-secondary-900 dark:text-white"><?= htmlspecialchars($option['label']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Results Count -->
        <div class="text-sm text-secondary-500 dark:text-secondary-400">
            <span x-text="filteredData.length"></span> of <span x-text="originalData.length"></span> results
        </div>
    </div>
</div>