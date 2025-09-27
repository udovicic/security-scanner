<?php
/**
 * Loading State Component
 *
 * Displays loading spinners, progress bars, and error states
 * Compatible with Alpine.js loadingState component
 */

$type = $type ?? 'spinner'; // spinner, progress, skeleton
$message = $message ?? 'Loading...';
$showMessage = $showMessage ?? true;
$size = $size ?? 'md'; // sm, md, lg
$center = $center ?? true;
?>

<div x-data="loadingState()"
     x-show="loading || hasError || isSuccess"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 scale-95"
     x-transition:enter-end="opacity-100 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 scale-100"
     x-transition:leave-end="opacity-0 scale-95"
     class="<?= $center ? 'flex items-center justify-center' : '' ?> loading-state-container">

    <!-- Loading Spinner -->
    <div x-show="loading && !hasError" class="text-center">
        <?php if ($type === 'spinner'): ?>
            <div class="<?= $center ? 'mx-auto' : '' ?> mb-4 inline-block
                        <?php
                        switch($size) {
                            case 'sm': echo 'w-4 h-4'; break;
                            case 'lg': echo 'w-12 h-12'; break;
                            default: echo 'w-8 h-8';
                        }
                        ?>
                        border-4 border-primary-200 border-t-primary-600 rounded-full animate-spin">
            </div>

        <?php elseif ($type === 'progress'): ?>
            <div class="w-full max-w-md mx-auto mb-4">
                <div class="bg-gray-200 dark:bg-gray-700 rounded-full h-2 overflow-hidden">
                    <div class="bg-primary-600 h-full transition-all duration-300 ease-out"
                         :style="`width: ${progress}%`"></div>
                </div>
                <div class="flex justify-between text-xs text-gray-600 dark:text-gray-400 mt-1">
                    <span x-text="message"></span>
                    <span x-text="`${Math.round(progress)}%`"></span>
                </div>
            </div>

        <?php elseif ($type === 'skeleton'): ?>
            <div class="animate-pulse space-y-4">
                <div class="h-4 bg-gray-300 dark:bg-gray-600 rounded w-3/4"></div>
                <div class="space-y-2">
                    <div class="h-3 bg-gray-300 dark:bg-gray-600 rounded"></div>
                    <div class="h-3 bg-gray-300 dark:bg-gray-600 rounded w-5/6"></div>
                </div>
                <div class="h-4 bg-gray-300 dark:bg-gray-600 rounded w-1/2"></div>
            </div>
        <?php endif; ?>

        <?php if ($showMessage): ?>
            <p class="text-gray-600 dark:text-gray-400
                     <?php
                     switch($size) {
                         case 'sm': echo 'text-sm'; break;
                         case 'lg': echo 'text-lg'; break;
                         default: echo 'text-base';
                     }
                     ?>"
               x-text="message || '<?= htmlspecialchars($message) ?>'">
            </p>
        <?php endif; ?>
    </div>

    <!-- Error State -->
    <div x-show="hasError" class="text-center" x-transition>
        <div class="mb-4">
            <svg class="<?= $center ? 'mx-auto' : '' ?>
                        <?php
                        switch($size) {
                            case 'sm': echo 'w-6 h-6'; break;
                            case 'lg': echo 'w-16 h-16'; break;
                            default: echo 'w-12 h-12';
                        }
                        ?>
                        text-red-500"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
            </svg>
        </div>

        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">
            Something went wrong
        </h3>

        <p class="text-gray-600 dark:text-gray-400 mb-4" x-text="error">
        </p>

        <div class="space-x-3">
            <button @click="clearState()"
                    class="btn btn-ghost btn-sm">
                Dismiss
            </button>

            <button @click="$dispatch('retry-requested')"
                    class="btn btn-primary btn-sm">
                Try Again
            </button>
        </div>
    </div>

    <!-- Success State -->
    <div x-show="isSuccess" class="text-center" x-transition>
        <div class="mb-4">
            <svg class="<?= $center ? 'mx-auto' : '' ?>
                        <?php
                        switch($size) {
                            case 'sm': echo 'w-6 h-6'; break;
                            case 'lg': echo 'w-16 h-16'; break;
                            default: echo 'w-12 h-12';
                        }
                        ?>
                        text-green-500"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>

        <p class="text-green-600 dark:text-green-400 font-medium" x-text="message">
            Operation completed successfully
        </p>
    </div>
</div>