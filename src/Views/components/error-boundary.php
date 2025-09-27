<?php
/**
 * Error Boundary Component
 *
 * Provides error handling and fallback UI for JavaScript errors
 * Works with Alpine.js errorHandler component
 */

$fallbackMessage = $fallbackMessage ?? 'An unexpected error occurred. Please refresh the page or try again.';
$showReload = $showReload ?? true;
$showDetails = $showDetails ?? false;
?>

<div x-data="errorHandler()"
     x-init="
        // Global error handler
        window.addEventListener('error', (event) => {
            addError(event.error || event.message, {
                filename: event.filename,
                lineno: event.lineno,
                colno: event.colno,
                stack: event.error?.stack
            });
        });

        // Unhandled promise rejection handler
        window.addEventListener('unhandledrejection', (event) => {
            addError(event.reason, {
                type: 'promise_rejection',
                promise: event.promise
            });
        });
     ">

    <!-- Error Display -->
    <div x-show="criticalErrors.length > 0"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 transform translate-y-4"
         x-transition:enter-end="opacity-100 transform translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 transform translate-y-0"
         x-transition:leave-end="opacity-0 transform translate-y-4"
         class="fixed top-4 right-4 max-w-md z-50 space-y-3">

        <template x-for="error in criticalErrors.slice(0, 3)" :key="error.id">
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 shadow-lg">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                    </div>

                    <div class="ml-3 flex-1">
                        <h3 class="text-sm font-medium text-red-800 dark:text-red-200">
                            Application Error
                        </h3>

                        <div class="mt-1 text-sm text-red-700 dark:text-red-300">
                            <p x-text="error.message"></p>
                        </div>

                        <?php if ($showDetails): ?>
                            <div x-show="error.context && (error.context.filename || error.context.stack)"
                                 class="mt-2 text-xs text-red-600 dark:text-red-400">
                                <details>
                                    <summary class="cursor-pointer hover:text-red-500">
                                        Show technical details
                                    </summary>
                                    <div class="mt-2 p-2 bg-red-100 dark:bg-red-900/40 rounded text-xs font-mono">
                                        <div x-show="error.context?.filename">
                                            <strong>File:</strong> <span x-text="error.context.filename"></span>
                                            <span x-show="error.context.lineno">
                                                (line <span x-text="error.context.lineno"></span>)
                                            </span>
                                        </div>
                                        <div x-show="error.context?.stack" class="mt-1">
                                            <strong>Stack:</strong>
                                            <pre x-text="error.context.stack" class="whitespace-pre-wrap text-xs"></pre>
                                        </div>
                                    </div>
                                </details>
                            </div>
                        <?php endif; ?>

                        <div class="mt-3 flex space-x-2">
                            <button @click="dismissError(error.id)"
                                    class="text-xs bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 px-2 py-1 rounded hover:bg-red-200 dark:hover:bg-red-900/60 transition-colors">
                                Dismiss
                            </button>

                            <?php if ($showReload): ?>
                                <button @click="window.location.reload()"
                                        class="text-xs bg-red-600 text-white px-2 py-1 rounded hover:bg-red-700 transition-colors">
                                    Reload Page
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ml-3 flex-shrink-0">
                        <button @click="dismissError(error.id)"
                                class="text-red-400 hover:text-red-500 transition-colors">
                            <span class="sr-only">Close</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- Show count if more errors exist -->
        <div x-show="criticalErrors.length > 3"
             class="text-center">
            <button @click="clearAllErrors()"
                    class="text-xs text-red-600 dark:text-red-400 hover:text-red-500 underline">
                <span x-text="`+${criticalErrors.length - 3} more errors`"></span> - Clear all
            </button>
        </div>
    </div>

    <!-- Fallback UI for when JavaScript fails -->
    <noscript>
        <div class="fixed inset-0 bg-gray-50 dark:bg-gray-900 flex items-center justify-center z-50">
            <div class="max-w-md mx-4 text-center">
                <svg class="w-16 h-16 mx-auto text-yellow-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>

                <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
                    JavaScript Required
                </h2>

                <p class="text-gray-600 dark:text-gray-400 mb-4">
                    This application requires JavaScript to function properly. Please enable JavaScript in your browser and refresh the page.
                </p>

                <button onclick="window.location.reload()"
                        class="btn btn-primary">
                    Reload Page
                </button>
            </div>
        </div>
    </noscript>
</div>

<script>
// Additional error handling utilities
window.handleAsyncError = function(promise, context = {}) {
    if (promise && typeof promise.catch === 'function') {
        promise.catch(error => {
            console.error('Async operation failed:', error, context);

            // Try to get Alpine error handler
            const errorHandler = Alpine?.store?.('errorHandler');
            if (errorHandler && errorHandler.addError) {
                errorHandler.addError(error, context);
            } else {
                // Fallback to notification system
                if (window.notify) {
                    window.notify.error(`Operation failed: ${error.message || error}`);
                }
            }
        });
    }
    return promise;
};

// Utility to wrap functions with error handling
window.withErrorHandling = function(fn, context = {}) {
    return function(...args) {
        try {
            const result = fn.apply(this, args);

            // Handle async functions
            if (result && typeof result.catch === 'function') {
                return window.handleAsyncError(result, context);
            }

            return result;
        } catch (error) {
            console.error('Function execution failed:', error, context);

            // Try to get Alpine error handler
            const errorHandler = Alpine?.store?.('errorHandler');
            if (errorHandler && errorHandler.addError) {
                errorHandler.addError(error, context);
            } else {
                // Fallback to notification system
                if (window.notify) {
                    window.notify.error(`Operation failed: ${error.message || error}`);
                }
            }

            throw error;
        }
    };
};
</script>