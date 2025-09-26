<?php
// Reusable Modal Alpine.js Component Template
// Usage: include this in views where modal functionality is needed
?>

<!-- Modal Component -->
<div x-data="modal(<?= $initiallyOpen ? 'true' : 'false' ?>)"
     x-show="open"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="hide()"
     class="fixed inset-0 z-50 overflow-y-auto"
     aria-labelledby="modal-title"
     role="dialog"
     aria-modal="true">

    <!-- Background overlay -->
    <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity"
         @click="hide()"></div>

    <!-- Modal panel -->
    <div class="flex min-h-full items-center justify-center p-4">
        <div x-show="open"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             class="relative bg-white dark:bg-secondary-800 rounded-lg shadow-xl transform transition-all max-w-<?= htmlspecialchars($modalSize ?? 'lg') ?> w-full">

            <!-- Modal Header -->
            <?php if (isset($modalTitle) || isset($showCloseButton)): ?>
            <div class="px-6 py-4 border-b border-secondary-200 dark:border-secondary-700">
                <div class="flex items-center justify-between">
                    <?php if (isset($modalTitle)): ?>
                    <h3 class="text-lg font-semibold text-secondary-900 dark:text-white" id="modal-title">
                        <?= htmlspecialchars($modalTitle) ?>
                    </h3>
                    <?php endif; ?>

                    <?php if ($showCloseButton ?? true): ?>
                    <button @click="hide()"
                            class="text-secondary-400 hover:text-secondary-600 dark:hover:text-secondary-300 transition-colors">
                        <span class="sr-only">Close</span>
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Modal Body -->
            <div class="<?= isset($modalPadding) ? htmlspecialchars($modalPadding) : 'px-6 py-4' ?>">
                <?= $modalContent ?? '' ?>
            </div>

            <!-- Modal Footer -->
            <?php if (isset($modalActions) && !empty($modalActions)): ?>
            <div class="px-6 py-4 border-t border-secondary-200 dark:border-secondary-700 flex justify-end gap-3">
                <?php foreach ($modalActions as $action): ?>
                <button @click="<?= htmlspecialchars($action['click'] ?? 'hide()') ?>"
                        class="btn btn-<?= htmlspecialchars($action['type'] ?? 'primary') ?>"
                        <?= ($action['disabled'] ?? false) ? 'disabled' : '' ?>>
                    <?= htmlspecialchars($action['label']) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal trigger button (if needed) -->
<?php if (isset($triggerButton)): ?>
<button @click="show()" class="btn btn-<?= htmlspecialchars($triggerButton['type'] ?? 'primary') ?>">
    <?= htmlspecialchars($triggerButton['label']) ?>
</button>
<?php endif; ?>