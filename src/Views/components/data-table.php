<?php
// Reusable Data Table Alpine.js Component Template
// Usage: include this in views where table functionality is needed
?>

<!-- Data Table Component -->
<div x-data="dataTable(<?= htmlspecialchars(json_encode($tableData ?? [])) ?>)" class="overflow-hidden">
    <!-- Desktop Table View -->
    <div class="hidden lg:block">
        <table class="data-table">
            <thead>
                <tr>
                    <?php if ($showCheckboxes ?? false): ?>
                    <th class="w-4">
                        <input type="checkbox"
                               @change="toggleSelectAll()"
                               :checked="selectAll"
                               class="form-checkbox h-4 w-4 text-primary-600 border-secondary-300 rounded">
                    </th>
                    <?php endif; ?>

                    <?php foreach ($columns as $column): ?>
                    <th <?= ($column['sortable'] ?? false) ? '@click="sortBy(\'' . htmlspecialchars($column['key']) . '\')" class="cursor-pointer select-none"' : '' ?>>
                        <div class="flex items-center">
                            <span><?= htmlspecialchars($column['label']) ?></span>
                            <?php if ($column['sortable'] ?? false): ?>
                            <div class="ml-2 flex flex-col">
                                <svg class="w-3 h-3 text-secondary-400"
                                     :class="{ 'text-primary-600': sortColumn === '<?= htmlspecialchars($column['key']) ?>' && sortDirection === 'asc' }"
                                     fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd"></path>
                                </svg>
                                <svg class="w-3 h-3 text-secondary-400 -mt-1"
                                     :class="{ 'text-primary-600': sortColumn === '<?= htmlspecialchars($column['key']) ?>' && sortDirection === 'desc' }"
                                     fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <?php endif; ?>
                        </div>
                    </th>
                    <?php endforeach; ?>

                    <?php if (isset($showActions) && $showActions): ?>
                    <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <template x-for="(item, index) in data" :key="item.id || index">
                    <tr :class="{ 'bg-primary-50 dark:bg-primary-900/20': isRowSelected(index) }">
                        <?php if ($showCheckboxes ?? false): ?>
                        <td>
                            <input type="checkbox"
                                   @change="toggleRowSelection(index)"
                                   :checked="isRowSelected(index)"
                                   class="form-checkbox h-4 w-4 text-primary-600 border-secondary-300 rounded">
                        </td>
                        <?php endif; ?>

                        <?php foreach ($columns as $column): ?>
                        <td>
                            <?php if (isset($column['template'])): ?>
                                <?= $column['template'] ?>
                            <?php else: ?>
                                <span x-text="item.<?= htmlspecialchars($column['key']) ?>"></span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>

                        <?php if (isset($showActions) && $showActions): ?>
                        <td>
                            <div class="flex gap-2">
                                <?php if (isset($actions)): ?>
                                    <?php foreach ($actions as $action): ?>
                                    <button @click="<?= htmlspecialchars($action['click']) ?>"
                                            class="btn btn-<?= htmlspecialchars($action['type'] ?? 'outline') ?> btn-sm"
                                            :disabled="<?= htmlspecialchars($action['disabled'] ?? 'false') ?>">
                                        <?= htmlspecialchars($action['label']) ?>
                                    </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Mobile Card View -->
    <div class="block lg:hidden">
        <div class="space-y-4">
            <template x-for="(item, index) in data" :key="item.id || index">
                <div class="card p-4" :class="{ 'ring-2 ring-primary-500': isRowSelected(index) }">
                    <?php if ($showCheckboxes ?? false): ?>
                    <div class="flex items-center justify-between mb-3">
                        <input type="checkbox"
                               @change="toggleRowSelection(index)"
                               :checked="isRowSelected(index)"
                               class="form-checkbox h-4 w-4 text-primary-600 border-secondary-300 rounded">
                    </div>
                    <?php endif; ?>

                    <?php foreach ($columns as $column): ?>
                    <div class="mb-3">
                        <dt class="text-sm font-medium text-secondary-500 dark:text-secondary-400"><?= htmlspecialchars($column['label']) ?>:</dt>
                        <dd class="mt-1 text-sm text-secondary-900 dark:text-white">
                            <?php if (isset($column['template'])): ?>
                                <?= $column['template'] ?>
                            <?php else: ?>
                                <span x-text="item.<?= htmlspecialchars($column['key']) ?>"></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php endforeach; ?>

                    <?php if (isset($showActions) && $showActions): ?>
                    <div class="mt-4 flex gap-2">
                        <?php if (isset($actions)): ?>
                            <?php foreach ($actions as $action): ?>
                            <button @click="<?= htmlspecialchars($action['click']) ?>"
                                    class="btn btn-<?= htmlspecialchars($action['type'] ?? 'outline') ?> btn-sm flex-1"
                                    :disabled="<?= htmlspecialchars($action['disabled'] ?? 'false') ?>">
                                <?= htmlspecialchars($action['label']) ?>
                            </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </template>
        </div>
    </div>

    <!-- Empty State -->
    <div x-show="data.length === 0" class="text-center py-12">
        <svg class="w-12 h-12 mx-auto text-secondary-400 dark:text-secondary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
        </svg>
        <h3 class="mt-4 text-lg font-medium text-secondary-900 dark:text-white"><?= htmlspecialchars($emptyStateTitle ?? 'No data found') ?></h3>
        <p class="mt-2 text-sm text-secondary-500 dark:text-secondary-400"><?= htmlspecialchars($emptyStateMessage ?? 'No items match your current filters') ?></p>
        <?php if (isset($emptyStateAction)): ?>
        <div class="mt-6">
            <a href="<?= htmlspecialchars($emptyStateAction['url']) ?>" class="btn btn-primary">
                <?= htmlspecialchars($emptyStateAction['label']) ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>