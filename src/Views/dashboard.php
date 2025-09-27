<?php
$title = 'Dashboard - Security Scanner';
$metaDescription = 'Security scanner dashboard showing website monitoring status, scan results, and system health metrics';
$currentPage = 'dashboard';
?>

<!-- Real-time updates container -->
<div x-data="realTimeUpdates('/api/dashboard/updates', 30000)"
     x-init="$watch('data', (newData) => { if (newData) updateDashboardContent(newData); })"
     @data-updated="updateDashboardContent($event.detail.data)"
     @connection-failed="$store.notifications.add({ type: 'error', title: 'Connection Lost', message: 'Real-time updates are temporarily unavailable' })"
     data-page="dashboard">

<!-- Connection status indicator -->
<div class="fixed top-20 right-4 z-50" x-show="!isConnected" x-transition>
    <div class="bg-red-100 dark:bg-red-900/20 border border-red-300 dark:border-red-700 text-red-800 dark:text-red-200 px-3 py-2 rounded-lg shadow-lg">
        <div class="flex items-center space-x-2">
            <svg class="w-4 h-4 animate-pulse" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
            </svg>
            <span class="text-sm font-medium">Connection lost</span>
        </div>
    </div>
</div>

<!-- Dashboard Header Section -->
<div class="bg-white dark:bg-secondary-800 shadow-sm border-b border-secondary-200 dark:border-secondary-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
            <div>
                <h1 class="text-2xl lg:text-3xl font-bold text-secondary-900 dark:text-white">
                    Security Scanner Dashboard
                </h1>
                <p class="mt-2 text-sm lg:text-base text-secondary-600 dark:text-secondary-400">
                    Monitor your websites' security status and scan results
                </p>
            </div>
            <div class="flex flex-col sm:flex-row gap-3">
                <div x-data="autoRefresh({ interval: 60000, endpoint: '/api/dashboard/refresh' })"
                     @refresh-completed="updateDashboardContent($event.detail.data)"
                     class="flex items-center space-x-3">
                    <button @click="forceUpdate()"
                            class="btn btn-outline inline-flex items-center justify-center"
                            data-tooltip="Refresh dashboard data"
                            :disabled="isConnected === false">
                        <svg class="w-4 h-4 mr-2" :class="{ 'animate-spin': isConnected === false }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        <span x-text="isConnected === false ? 'Updating...' : 'Refresh'"></span>
                    </button>
                    <button @click="toggleAutoRefresh()"
                            class="btn btn-ghost btn-sm"
                            data-tooltip="Toggle auto-refresh">
                        <span x-show="isAutoRefreshing" class="flex items-center">
                            <svg class="w-4 h-4 mr-1 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                            Auto
                        </span>
                        <span x-show="!isAutoRefreshing" class="flex items-center">
                            <svg class="w-4 h-4 mr-1 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"></path>
                            </svg>
                            Manual
                        </span>
                    </button>
                </div>
                <a href="/websites/create" class="btn btn-primary">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add Website
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Dashboard Statistics Grid - Mobile First -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 lg:gap-6">
        <!-- Total Websites -->
        <div class="card bg-gradient-to-br from-primary-50 to-primary-100 dark:from-primary-900/20 dark:to-primary-800/20 border-primary-200 dark:border-primary-800"
             data-stat="total_websites">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-primary-600 dark:text-primary-400">Total Websites</p>
                    <p class="text-2xl lg:text-3xl font-bold text-primary-900 dark:text-primary-100 stat-value transition-all duration-300">
                        <?= htmlspecialchars($metrics['total_websites'] ?? '0') ?>
                    </p>
                    <?php if (isset($metrics['websites_change'])): ?>
                    <p class="text-xs text-primary-700 dark:text-primary-300 mt-1">
                        <span class="<?= $metrics['websites_change'] >= 0 ? 'text-success-600' : 'text-danger-600' ?>">
                            <?= $metrics['websites_change'] >= 0 ? '+' : '' ?><?= htmlspecialchars($metrics['websites_change']) ?>
                        </span>
                        from last month
                    </p>
                    <?php endif; ?>
                </div>
                <div class="p-3 bg-primary-500 dark:bg-primary-600 rounded-full">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9v-9m0-9v9"></path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Scans Today -->
        <div class="card bg-gradient-to-br from-success-50 to-success-100 dark:from-success-900/20 dark:to-success-800/20 border-success-200 dark:border-success-800"
             data-stat="scans_today">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-success-600 dark:text-success-400">Scans Today</p>
                    <p class="text-2xl lg:text-3xl font-bold text-success-900 dark:text-success-100 stat-value transition-all duration-300">
                        <?= htmlspecialchars($metrics['scans_today'] ?? '0') ?>
                    </p>
                    <p class="text-xs text-success-700 dark:text-success-300 mt-1">Automated security scans</p>
                </div>
                <div class="p-3 bg-success-500 dark:bg-success-600 rounded-full">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Success Rate -->
        <div class="card bg-gradient-to-br from-info-50 to-info-100 dark:from-info-900/20 dark:to-info-800/20 border-info-200 dark:border-info-800"
             data-stat="success_rate">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-info-600 dark:text-info-400">Success Rate</p>
                    <p class="text-2xl lg:text-3xl font-bold text-info-900 dark:text-info-100 stat-value transition-all duration-300">
                        <?= htmlspecialchars(number_format($metrics['success_rate'] ?? 0, 1)) ?>%
                    </p>
                    <p class="text-xs text-info-700 dark:text-info-300 mt-1">Last 7 days</p>
                </div>
                <div class="p-3 bg-info-500 dark:bg-info-600 rounded-full">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Active Issues -->
        <div class="card bg-gradient-to-br from-warning-50 to-warning-100 dark:from-warning-900/20 dark:to-warning-800/20 border-warning-200 dark:border-warning-800"
             data-stat="active_issues">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-warning-600 dark:text-warning-400">Active Issues</p>
                    <p class="text-2xl lg:text-3xl font-bold text-warning-900 dark:text-warning-100 stat-value transition-all duration-300">
                        <?= htmlspecialchars($metrics['active_issues'] ?? '0') ?>
                    </p>
                    <p class="text-xs text-warning-700 dark:text-warning-300 mt-1">Require attention</p>
                </div>
                <div class="p-3 bg-warning-500 dark:bg-warning-600 rounded-full">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Dashboard Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-8">
        <!-- Recent Activity Section -->
        <div class="lg:col-span-2">
            <div class="card">
                <div class="flex items-center justify-between p-6 border-b border-secondary-200 dark:border-secondary-700">
                    <h3 class="text-lg font-semibold text-secondary-900 dark:text-white">Recent Activity</h3>
                    <button class="btn btn-ghost btn-sm" onclick="refreshActivity()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                    </button>
                </div>
                <div class="p-6" id="recent-tests-container">
                    <?php if (isset($recent_activity) && !empty($recent_activity)): ?>
                    <div class="space-y-4">
                        <?php foreach ($recent_activity as $activity): ?>
                        <div class="flex items-start space-x-4 p-4 bg-secondary-50 dark:bg-secondary-700/50 rounded-lg">
                            <div class="flex-shrink-0 mt-0.5">
                                <div class="w-2 h-2 rounded-full
                                    <?php
                                    switch($activity['status']) {
                                        case 'success': echo 'bg-success-500'; break;
                                        case 'warning': echo 'bg-warning-500'; break;
                                        case 'error': echo 'bg-danger-500'; break;
                                        default: echo 'bg-info-500';
                                    }
                                    ?>"></div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-secondary-900 dark:text-white">
                                    <?= htmlspecialchars($activity['title']) ?>
                                </p>
                                <p class="text-xs text-secondary-500 dark:text-secondary-400 mt-1">
                                    <?= htmlspecialchars($activity['time']) ?>
                                </p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-12">
                        <svg class="w-12 h-12 mx-auto text-secondary-400 dark:text-secondary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                        <h4 class="mt-4 text-sm font-medium text-secondary-900 dark:text-white">No recent activity</h4>
                        <p class="mt-1 text-sm text-secondary-500 dark:text-secondary-400">
                            Activity will appear here when scans are performed
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- System Health & Alerts Section -->
        <div class="space-y-6">
            <!-- System Health -->
            <div class="card">
                <div class="p-6 border-b border-secondary-200 dark:border-secondary-700">
                    <h3 class="text-lg font-semibold text-secondary-900 dark:text-white">System Health</h3>
                </div>
                <div class="p-6">
                    <?php if (isset($system_health) && !empty($system_health)): ?>
                    <div class="space-y-4">
                        <?php foreach ($system_health as $component => $status): ?>
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-secondary-700 dark:text-secondary-300">
                                <?= htmlspecialchars(ucfirst($component)) ?>
                            </span>
                            <span class="status-badge status-<?= htmlspecialchars($status) ?>">
                                <?= htmlspecialchars(ucfirst($status)) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-8">
                        <svg class="w-8 h-8 mx-auto text-secondary-400 dark:text-secondary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                        </svg>
                        <p class="mt-2 text-sm text-secondary-500 dark:text-secondary-400">
                            System health data unavailable
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Alerts -->
            <div class="card">
                <div class="p-6 border-b border-secondary-200 dark:border-secondary-700">
                    <h3 class="text-lg font-semibold text-secondary-900 dark:text-white">Recent Alerts</h3>
                </div>
                <div class="p-6" id="alerts-container">
                    <?php if (isset($recent_alerts) && !empty($recent_alerts)): ?>
                    <div class="space-y-3">
                        <?php foreach ($recent_alerts as $alert): ?>
                        <div class="p-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-<?= htmlspecialchars($alert['type']) ?>-50 dark:bg-<?= htmlspecialchars($alert['type']) ?>-900/20">
                            <div class="flex items-start">
                                <div class="flex-1">
                                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?= htmlspecialchars($alert['title']) ?>
                                    </h4>
                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                                        <?= htmlspecialchars($alert['message']) ?>
                                    </p>
                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-500">
                                        <?= htmlspecialchars($alert['time']) ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-6">
                        <svg class="w-8 h-8 mx-auto text-gray-400 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5 5v-5zM4 16h6v2H4v-2zm0-4h6v2H4v-2zm0-4h6v2H4V8z"></path>
                        </svg>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No recent alerts</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Websites Overview Table -->
    <div class="mt-8">
        <div class="card">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-6 border-b border-secondary-200 dark:border-secondary-700">
                <h3 class="text-lg font-semibold text-secondary-900 dark:text-white">Websites Overview</h3>
                <div class="mt-4 sm:mt-0 flex flex-col sm:flex-row gap-3">
                    <button onclick="bulkAction('scan')"
                            class="btn btn-outline btn-sm"
                            id="bulk-scan-btn"
                            style="display: none;">
                        Scan Selected
                    </button>
                    <a href="/websites/create" class="btn btn-primary btn-sm">Add Website</a>
                </div>
            </div>
            <div class="overflow-hidden">
                <?php if (isset($websites_overview) && !empty($websites_overview)): ?>
                <!-- Mobile Card View -->
                <div class="block lg:hidden">
                    <div class="divide-y divide-secondary-200 dark:divide-secondary-700">
                        <?php foreach ($websites_overview as $website): ?>
                        <div class="p-6 space-y-3">
                            <div class="flex items-start justify-between">
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-sm font-medium text-secondary-900 dark:text-white truncate">
                                        <?= htmlspecialchars($website['name']) ?>
                                    </h4>
                                    <p class="text-xs text-secondary-500 dark:text-secondary-400 truncate">
                                        <?= htmlspecialchars($website['url']) ?>
                                    </p>
                                </div>
                                <span class="status-badge status-<?= htmlspecialchars($website['status']) ?>">
                                    <?= htmlspecialchars(ucfirst($website['status'])) ?>
                                </span>
                            </div>
                            <div class="grid grid-cols-2 gap-4 text-xs">
                                <div>
                                    <span class="text-secondary-500 dark:text-secondary-400">Last Scan:</span>
                                    <span class="text-secondary-900 dark:text-white">
                                        <?= htmlspecialchars($website['last_scan'] ?? 'Never') ?>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-secondary-500 dark:text-secondary-400">Success Rate:</span>
                                    <span class="text-secondary-900 dark:text-white">
                                        <?= htmlspecialchars(number_format($website['success_rate'] ?? 0, 1)) ?>%
                                    </span>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <a href="/websites/<?= htmlspecialchars($website['id']) ?>"
                                   class="btn btn-outline btn-sm flex-1">View</a>
                                <button onclick="startScan(<?= htmlspecialchars($website['id']) ?>)"
                                        class="btn btn-primary btn-sm flex-1">
                                    Scan Now
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Desktop Table View -->
                <div class="hidden lg:block">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="w-4">
                                    <input type="checkbox"
                                           data-select-all
                                           class="form-checkbox h-4 w-4 text-primary-600 border-secondary-300 rounded">
                                </th>
                                <th data-sortable>Website</th>
                                <th data-sortable>Status</th>
                                <th data-sortable>Last Scan</th>
                                <th data-sortable>Success Rate</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($websites_overview as $website): ?>
                            <tr>
                                <td>
                                    <input type="checkbox"
                                           data-row-select
                                           value="<?= htmlspecialchars($website['id']) ?>"
                                           class="form-checkbox h-4 w-4 text-primary-600 border-secondary-300 rounded">
                                </td>
                                <td>
                                    <div>
                                        <div class="font-medium text-secondary-900 dark:text-white">
                                            <?= htmlspecialchars($website['name']) ?>
                                        </div>
                                        <div class="text-sm text-secondary-500 dark:text-secondary-400">
                                            <?= htmlspecialchars($website['url']) ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= htmlspecialchars($website['status']) ?>">
                                        <?= htmlspecialchars(ucfirst($website['status'])) ?>
                                    </span>
                                </td>
                                <td class="text-sm text-secondary-500 dark:text-secondary-400">
                                    <?= htmlspecialchars($website['last_scan'] ?? 'Never') ?>
                                </td>
                                <td class="text-sm text-secondary-900 dark:text-white">
                                    <?= htmlspecialchars(number_format($website['success_rate'] ?? 0, 1)) ?>%
                                </td>
                                <td>
                                    <div class="flex gap-2">
                                        <a href="/websites/<?= htmlspecialchars($website['id']) ?>"
                                           class="btn btn-outline btn-sm">View</a>
                                        <button onclick="startScan(<?= htmlspecialchars($website['id']) ?>)"
                                                class="btn btn-primary btn-sm">
                                            Scan Now
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <svg class="w-12 h-12 mx-auto text-secondary-400 dark:text-secondary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9v-9m0-9v9"></path>
                    </svg>
                    <h4 class="mt-4 text-lg font-medium text-secondary-900 dark:text-white">No websites configured</h4>
                    <p class="mt-2 text-sm text-secondary-500 dark:text-secondary-400">
                        Get started by adding your first website to monitor
                    </p>
                    <div class="mt-6">
                        <a href="/websites/create" class="btn btn-primary">Add Your First Website</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Dashboard functionality using modern JavaScript and the notification system

let refreshInterval;

function startScan(websiteId) {
    if (!confirm('Start a new security scan for this website?')) {
        return;
    }

    window.ajax.post(`/api/websites/${websiteId}/scan`, {}, { showLoading: true })
        .then(data => {
            if (data.success) {
                window.notify.success('Scan started successfully!');
                refreshDashboard();
            } else {
                window.notify.error('Failed to start scan: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            window.notify.error('Failed to start scan due to a network error.');
        });
}

function refreshDashboard() {
    window.ajax.get('/api/dashboard/refresh', { showLoading: false })
        .then(data => {
            if (data.success) {
                updateDashboardData(data.data);
                window.notify.success('Dashboard refreshed');
            }
        })
        .catch(error => {
            console.error('Dashboard refresh failed:', error);
            window.notify.warning('Failed to refresh dashboard data');
        });
}

function refreshActivity() {
    window.ajax.get('/api/dashboard/activity', { showLoading: false })
        .then(data => {
            if (data.success && data.activity) {
                updateActivitySection(data.activity);
            }
        })
        .catch(error => {
            console.error('Activity refresh failed:', error);
        });
}

function updateDashboardData(data) {
    // Update metrics if provided
    if (data.metrics) {
        updateMetrics(data.metrics);
    }

    // Update activity if provided
    if (data.recent_activity) {
        updateActivitySection(data.recent_activity);
    }

    // Update websites table if provided
    if (data.websites_overview) {
        updateWebsitesTable(data.websites_overview);
    }
}

function updateMetrics(metrics) {
    // Update each metric card
    Object.keys(metrics).forEach(key => {
        const element = document.querySelector(`[data-metric="${key}"]`);
        if (element) {
            element.textContent = metrics[key];
        }
    });
}

function updateActivitySection(activities) {
    const container = document.getElementById('activity-container');
    if (!container) return;

    if (activities.length === 0) {
        container.innerHTML = `
            <div class="text-center py-12">
                <svg class="w-12 h-12 mx-auto text-secondary-400 dark:text-secondary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                <h4 class="mt-4 text-sm font-medium text-secondary-900 dark:text-white">No recent activity</h4>
                <p class="mt-1 text-sm text-secondary-500 dark:text-secondary-400">
                    Activity will appear here when scans are performed
                </p>
            </div>
        `;
        return;
    }

    const activitiesHTML = activities.map(activity => {
        const statusColor = {
            success: 'bg-success-500',
            warning: 'bg-warning-500',
            error: 'bg-danger-500'
        }[activity.status] || 'bg-info-500';

        return `
            <div class="flex items-start space-x-4 p-4 bg-secondary-50 dark:bg-secondary-700/50 rounded-lg">
                <div class="flex-shrink-0 mt-0.5">
                    <div class="w-2 h-2 rounded-full ${statusColor}"></div>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-secondary-900 dark:text-white">
                        ${activity.title}
                    </p>
                    <p class="text-xs text-secondary-500 dark:text-secondary-400 mt-1">
                        ${activity.time}
                    </p>
                </div>
            </div>
        `;
    }).join('');

    container.innerHTML = `<div class="space-y-4">${activitiesHTML}</div>`;
}

function bulkAction(action) {
    const selectedIds = Array.from(document.querySelectorAll('input[data-row-select]:checked'))
        .map(checkbox => checkbox.value);

    if (selectedIds.length === 0) {
        window.notify.warning('Please select at least one website');
        return;
    }

    if (action === 'scan') {
        if (!confirm(`Start security scans for ${selectedIds.length} selected website(s)?`)) {
            return;
        }

        window.ajax.post('/api/websites/bulk-scan', { website_ids: selectedIds })
            .then(data => {
                if (data.success) {
                    window.notify.success(`Started scans for ${selectedIds.length} website(s)`);
                    refreshDashboard();
                } else {
                    window.notify.error('Failed to start bulk scans: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Bulk scan error:', error);
                window.notify.error('Failed to start bulk scans due to a network error');
            });
    }
}

// Auto-refresh functionality with pause on user interaction
function initializeAutoRefresh() {
    refreshInterval = setInterval(refreshDashboard, 60000); // Refresh every minute

    // Pause auto-refresh when user is interacting
    let userInteractionTimeout;

    ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
        document.addEventListener(event, () => {
            clearInterval(refreshInterval);
            clearTimeout(userInteractionTimeout);

            userInteractionTimeout = setTimeout(() => {
                refreshInterval = setInterval(refreshDashboard, 60000);
            }, 5000); // Resume after 5 seconds of inactivity
        }, true);
    });
}

// Handle bulk selection UI
document.addEventListener('DOMContentLoaded', function() {
    initializeAutoRefresh();

    // Show/hide bulk action button based on selections
    const selectAllCheckbox = document.querySelector('input[data-select-all]');
    const rowCheckboxes = document.querySelectorAll('input[data-row-select]');
    const bulkButton = document.getElementById('bulk-scan-btn');

    function updateBulkButtonVisibility() {
        const selectedCount = Array.from(rowCheckboxes).filter(cb => cb.checked).length;
        if (bulkButton) {
            bulkButton.style.display = selectedCount > 0 ? 'block' : 'none';
        }
    }

    // Update bulk button visibility when checkboxes change
    rowCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkButtonVisibility);
    });

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', updateBulkButtonVisibility);
    }
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
});

// Enhanced real-time dashboard functionality
function updateDashboardContent(data) {
    if (data.stats) {
        updateDashboardStats(data.stats);
    }
    if (data.recentTests) {
        updateRecentTests(data.recentTests);
    }
    if (data.alerts) {
        updateAlerts(data.alerts);
    }
    if (data.newAlerts && data.newAlerts.length > 0) {
        data.newAlerts.forEach(alert => {
            window.notify[alert.type](alert.message);
        });
    }
}
</script>

</div> <!-- End real-time updates container -->