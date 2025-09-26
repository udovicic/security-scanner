<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1f2937">
    <meta name="description" content="<?= htmlspecialchars($metaDescription ?? 'Comprehensive security scanning tool for websites and web applications') ?>">
    <meta name="keywords" content="<?= htmlspecialchars($metaKeywords ?? 'security scanning, website security, SSL testing, vulnerability assessment') ?>">

    <title><?= htmlspecialchars($title ?? 'Security Scanner Tool') ?></title>

    <!-- Security Headers -->
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">

    <!-- Favicon and App Icons -->
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="icon" type="image/png" href="/assets/favicon.png">
    <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.json">

    <!-- Preload Critical Resources -->
    <link rel="preload" href="/assets/css/app.css" as="style">
    <link rel="preload" href="/assets/js/app.js" as="script">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="/assets/css/app.css">

    <!-- Theme Support -->
    <script>
        // Check for saved theme preference or default to system preference
        const theme = localStorage.getItem('theme') ||
                     (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.classList.add('js-enabled');
    </script>

    <?php if (isset($customCSS) && !empty($customCSS)): ?>
    <?php foreach ($customCSS as $css): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($css) ?>">
    <?php endforeach; ?>
    <?php endif; ?>
</head>
<body class="<?= htmlspecialchars($bodyClass ?? '') ?>">
    <!-- Skip to main content link for screen readers -->
    <a href="#main-content" class="skip-link sr-only focus:not-sr-only">Skip to main content</a>

    <div class="app-container">
        <header class="main-header" role="banner">
            <div class="header-container">
                <nav class="main-navigation" role="navigation" aria-label="Primary navigation">
                    <div class="nav-brand">
                        <a href="/" aria-label="Security Scanner Tool - Go to homepage" class="brand-link">
                            <svg class="brand-icon" width="32" height="32" viewBox="0 0 32 32" aria-hidden="true">
                                <path d="M16 2L3 7v10c0 8.3 5.7 16.1 13 18 7.3-1.9 13-9.7 13-18V7L16 2z" fill="currentColor"/>
                            </svg>
                            <span class="brand-text">Security Scanner</span>
                        </a>
                    </div>

                    <!-- Mobile menu button with Alpine.js -->
                    <div x-data="{ mobileMenuOpen: false }" class="lg:hidden">
                        <button type="button"
                                @click="mobileMenuOpen = !mobileMenuOpen"
                                class="mobile-menu-button"
                                :aria-expanded="mobileMenuOpen"
                                aria-controls="main-menu"
                                aria-label="Toggle main menu">
                            <span class="sr-only">Toggle main menu</span>
                            <svg x-show="!mobileMenuOpen" class="menu-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                            </svg>
                            <svg x-show="mobileMenuOpen" class="menu-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>

                        <!-- Mobile menu dropdown -->
                        <div x-show="mobileMenuOpen"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 scale-95"
                             x-transition:enter-end="opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100 scale-100"
                             x-transition:leave-end="opacity-0 scale-95"
                             @click.away="mobileMenuOpen = false"
                             class="absolute top-full left-0 right-0 bg-white dark:bg-secondary-800 shadow-lg border-t border-secondary-200 dark:border-secondary-700 z-50"
                             id="mobile-menu">
                            <nav class="px-4 py-2" role="navigation" aria-label="Mobile navigation">
                                <a href="/dashboard"
                                   class="block px-3 py-2 text-sm font-medium text-secondary-600 dark:text-secondary-300 hover:text-secondary-900 dark:hover:text-white hover:bg-secondary-100 dark:hover:bg-secondary-700 rounded-md transition-colors duration-200 <?= ($currentPage ?? '') === 'dashboard' ? 'bg-primary-100 dark:bg-primary-800 text-primary-700 dark:text-primary-200' : '' ?>"
                                   @click="mobileMenuOpen = false">
                                    Dashboard
                                </a>
                                <a href="/websites"
                                   class="block px-3 py-2 text-sm font-medium text-secondary-600 dark:text-secondary-300 hover:text-secondary-900 dark:hover:text-white hover:bg-secondary-100 dark:hover:bg-secondary-700 rounded-md transition-colors duration-200 <?= ($currentPage ?? '') === 'websites' ? 'bg-primary-100 dark:bg-primary-800 text-primary-700 dark:text-primary-200' : '' ?>"
                                   @click="mobileMenuOpen = false">
                                    Websites
                                </a>
                                <a href="/tests"
                                   class="block px-3 py-2 text-sm font-medium text-secondary-600 dark:text-secondary-300 hover:text-secondary-900 dark:hover:text-white hover:bg-secondary-100 dark:hover:bg-secondary-700 rounded-md transition-colors duration-200 <?= ($currentPage ?? '') === 'tests' ? 'bg-primary-100 dark:bg-primary-800 text-primary-700 dark:text-primary-200' : '' ?>"
                                   @click="mobileMenuOpen = false">
                                    Security Tests
                                </a>
                                <a href="/results"
                                   class="block px-3 py-2 text-sm font-medium text-secondary-600 dark:text-secondary-300 hover:text-secondary-900 dark:hover:text-white hover:bg-secondary-100 dark:hover:bg-secondary-700 rounded-md transition-colors duration-200 <?= ($currentPage ?? '') === 'results' ? 'bg-primary-100 dark:bg-primary-800 text-primary-700 dark:text-primary-200' : '' ?>"
                                   @click="mobileMenuOpen = false">
                                    Results
                                </a>
                                <a href="/settings"
                                   class="block px-3 py-2 text-sm font-medium text-secondary-600 dark:text-secondary-300 hover:text-secondary-900 dark:hover:text-white hover:bg-secondary-100 dark:hover:bg-secondary-700 rounded-md transition-colors duration-200 <?= ($currentPage ?? '') === 'settings' ? 'bg-primary-100 dark:bg-primary-800 text-primary-700 dark:text-primary-200' : '' ?>"
                                   @click="mobileMenuOpen = false">
                                    Settings
                                </a>
                            </nav>
                        </div>
                    </div>

                    <ul class="nav-menu" id="main-menu" role="menubar">
                        <li role="none">
                            <a href="/dashboard"
                               role="menuitem"
                               aria-current="<?= ($currentPage ?? '') === 'dashboard' ? 'page' : 'false' ?>"
                               class="nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>">
                                <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/>
                                </svg>
                                <span>Dashboard</span>
                            </a>
                        </li>
                        <li role="none">
                            <a href="/websites"
                               role="menuitem"
                               aria-current="<?= ($currentPage ?? '') === 'websites' ? 'page' : 'false' ?>"
                               class="nav-link <?= ($currentPage ?? '') === 'websites' ? 'active' : '' ?>">
                                <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M4.083 9h1.946c.089-1.546.383-2.97.837-4.118A6.004 6.004 0 004.083 9zM10 2a8 8 0 100 16 8 8 0 000-16zm0 2c-.076 0-.232.032-.465.262-.238.234-.497.623-.737 1.182-.389.907-.673 2.142-.766 3.556h3.936c-.093-1.414-.377-2.649-.766-3.556-.24-.56-.5-.948-.737-1.182C10.232 4.032 10.076 4 10 4zm3.971 5c-.089-1.546-.383-2.97-.837-4.118A6.004 6.004 0 0115.917 9h-1.946zm-2.003 2H8.032c.093 1.414.377 2.649.766 3.556.24.56.5.948.737 1.182.233.23.389.262.465.262.076 0 .232-.032.465-.262.238-.234.498-.623.737-1.182.389-.907.673-2.142.766-3.556zm1.166 4.118c.454-1.147.748-2.572.837-4.118h1.946a6.004 6.004 0 01-2.783 4.118zm-6.268 0C6.412 13.97 6.118 12.546 6.03 11H4.083a6.004 6.004 0 002.783 4.118z" clip-rule="evenodd"/>
                                </svg>
                                <span>Websites</span>
                            </a>
                        </li>
                        <li role="none">
                            <a href="/tests"
                               role="menuitem"
                               aria-current="<?= ($currentPage ?? '') === 'tests' ? 'page' : 'false' ?>"
                               class="nav-link <?= ($currentPage ?? '') === 'tests' ? 'active' : '' ?>">
                                <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>Tests</span>
                            </a>
                        </li>
                        <li role="none">
                            <a href="/results"
                               role="menuitem"
                               aria-current="<?= ($currentPage ?? '') === 'results' ? 'page' : 'false' ?>"
                               class="nav-link <?= ($currentPage ?? '') === 'results' ? 'active' : '' ?>">
                                <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/>
                                </svg>
                                <span>Results</span>
                            </a>
                        </li>
                        <li role="none">
                            <a href="/api/docs"
                               role="menuitem"
                               aria-current="<?= ($currentPage ?? '') === 'api' ? 'page' : 'false' ?>"
                               class="nav-link <?= ($currentPage ?? '') === 'api' ? 'active' : '' ?>">
                                <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M12.316 3.051a1 1 0 01.633 1.265l-4 12a1 1 0 11-1.898-.632l4-12a1 1 0 011.265-.633zM5.707 6.293a1 1 0 010 1.414L3.414 10l2.293 2.293a1 1 0 11-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0zm8.586 0a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 11-1.414-1.414L16.586 10l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                                <span>API</span>
                            </a>
                        </li>
                    </ul>

                    <!-- User menu and theme toggle with Alpine.js -->
                    <div class="header-actions" x-data="themeToggle()">
                        <!-- Notifications Dropdown -->
                        <div x-data="{ notificationsOpen: false }"
                             x-init="loadNotifications()"
                             class="relative">
                            <button type="button"
                                    @click="notificationsOpen = !notificationsOpen"
                                    class="relative p-2 text-secondary-400 hover:text-secondary-600 dark:hover:text-secondary-300 transition-colors duration-200"
                                    :aria-expanded="notificationsOpen"
                                    aria-label="View notifications">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5 5v-5z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path>
                                </svg>
                                <span x-show="$store.notifications.unreadCount > 0"
                                      x-text="$store.notifications.unreadCount"
                                      class="absolute -top-1 -right-1 bg-danger-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center"></span>
                            </button>

                            <div x-show="notificationsOpen"
                                 x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 x-transition:leave="transition ease-in duration-150"
                                 x-transition:leave-start="opacity-100 scale-100"
                                 x-transition:leave-end="opacity-0 scale-95"
                                 @click.away="notificationsOpen = false"
                                 class="absolute right-0 mt-2 w-80 bg-white dark:bg-secondary-800 rounded-lg shadow-lg border border-secondary-200 dark:border-secondary-700 z-50">
                                <div class="p-4 border-b border-secondary-200 dark:border-secondary-700">
                                    <h3 class="text-sm font-semibold text-secondary-900 dark:text-white">Notifications</h3>
                                </div>
                                <div class="max-h-64 overflow-y-auto">
                                    <template x-for="notification in $store.notifications.items" :key="notification.id">
                                        <div class="p-4 border-b border-secondary-100 dark:border-secondary-700 hover:bg-secondary-50 dark:hover:bg-secondary-700/50">
                                            <div class="flex items-start">
                                                <div class="flex-shrink-0">
                                                    <div class="w-2 h-2 rounded-full mt-2"
                                                         :class="{
                                                             'bg-success-500': notification.type === 'success',
                                                             'bg-warning-500': notification.type === 'warning',
                                                             'bg-danger-500': notification.type === 'error',
                                                             'bg-info-500': notification.type === 'info'
                                                         }"></div>
                                                </div>
                                                <div class="ml-3 flex-1">
                                                    <p class="text-sm font-medium text-secondary-900 dark:text-white" x-text="notification.title"></p>
                                                    <p class="text-xs text-secondary-500 dark:text-secondary-400 mt-1" x-text="notification.message"></p>
                                                    <p class="text-xs text-secondary-400 dark:text-secondary-500 mt-1" x-text="notification.time"></p>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                    <div x-show="$store.notifications.items.length === 0" class="p-8 text-center">
                                        <svg class="w-8 h-8 mx-auto text-secondary-400 dark:text-secondary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2M4 13h2m13-8V4a1 1 0 00-1-1H7a1 1 0 00-1 1v1m8 0V4.5M9 5v-.5"></path>
                                        </svg>
                                        <p class="mt-2 text-sm text-secondary-500 dark:text-secondary-400">No new notifications</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Theme Toggle -->
                        <button type="button"
                                @click="toggleTheme()"
                                class="theme-toggle p-2 text-secondary-400 hover:text-secondary-600 dark:hover:text-secondary-300 transition-colors duration-200"
                                :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                                data-tooltip="Toggle theme">
                            <svg x-show="!isDark" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd"/>
                            </svg>
                            <svg x-show="isDark" class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"/>
                            </svg>
                        </button>

                        <?php if (isset($user) && $user): ?>
                        <div class="user-menu" data-dropdown>
                            <button type="button"
                                    class="user-menu-button"
                                    aria-expanded="false"
                                    aria-haspopup="true"
                                    aria-label="User menu">
                                <img src="<?= htmlspecialchars($user['avatar'] ?? '/assets/default-avatar.png') ?>"
                                     alt="<?= htmlspecialchars($user['name'] ?? 'User') ?>"
                                     class="user-avatar">
                                <span class="user-name"><?= htmlspecialchars($user['name'] ?? 'User') ?></span>
                            </button>
                            <div class="user-menu-dropdown" role="menu">
                                <a href="/profile" role="menuitem">Profile</a>
                                <a href="/settings" role="menuitem">Settings</a>
                                <hr role="separator">
                                <form method="POST" action="/logout" class="logout-form">
                                    <button type="submit" role="menuitem">Logout</button>
                                </form>
                            </div>
                        </div>
                        <?php else: ?>
                        <a href="/login" class="login-link">Login</a>
                        <?php endif; ?>
                    </div>
                </nav>
            </div>
        </header>

        <main class="main-content" id="main-content" role="main" tabindex="-1">
            <div class="container">
                <?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
                <nav class="breadcrumbs" aria-label="Breadcrumb navigation">
                    <ol class="breadcrumb-list" vocab="https://schema.org/" typeof="BreadcrumbList">
                        <?php foreach ($breadcrumbs as $index => $breadcrumb): ?>
                        <li property="itemListElement" typeof="ListItem" class="breadcrumb-item">
                            <?php if (isset($breadcrumb['url']) && $index < count($breadcrumbs) - 1): ?>
                            <a href="<?= htmlspecialchars($breadcrumb['url']) ?>"
                               property="item" typeof="WebPage"
                               class="breadcrumb-link">
                                <span property="name"><?= htmlspecialchars($breadcrumb['title']) ?></span>
                            </a>
                            <meta property="position" content="<?= $index + 1 ?>">
                            <?php else: ?>
                            <span property="name" aria-current="page" class="breadcrumb-current">
                                <?= htmlspecialchars($breadcrumb['title']) ?>
                            </span>
                            <meta property="position" content="<?= $index + 1 ?>">
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
                <?php endif; ?>

                <?php if (isset($alerts) && !empty($alerts)): ?>
                <div class="alerts" role="region" aria-label="Notifications">
                    <?php foreach ($alerts as $alert): ?>
                    <div class="alert alert-<?= htmlspecialchars($alert['type']) ?>"
                         role="alert"
                         aria-live="<?= $alert['type'] === 'error' ? 'assertive' : 'polite' ?>">
                        <div class="alert-content">
                            <?php if ($alert['type'] === 'error'): ?>
                            <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <?php elseif ($alert['type'] === 'success'): ?>
                            <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <?php elseif ($alert['type'] === 'warning'): ?>
                            <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <?php else: ?>
                            <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                            </svg>
                            <?php endif; ?>
                            <div class="alert-message">
                                <?= htmlspecialchars($alert['message']) ?>
                            </div>
                            <?php if (isset($alert['dismissible']) && $alert['dismissible']): ?>
                            <button type="button" class="alert-dismiss" aria-label="Dismiss alert">
                                <svg viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="content">
                    <?= $content ?>
                </div>
            </div>
        </main>
    </div>

    <footer class="main-footer" role="contentinfo">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-section">
                    <h2 class="footer-title">Security Scanner Tool</h2>
                    <p class="footer-description">
                        Comprehensive security scanning and monitoring solution for websites and web applications.
                    </p>
                    <div class="footer-social">
                        <a href="#" aria-label="Follow us on GitHub" class="social-link">
                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M10 0C4.477 0 0 4.484 0 10.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0110 4.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.203 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.942.359.31.678.921.678 1.856 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0020 10.017C20 4.484 15.522 0 10 0z" clip-rule="evenodd"/>
                            </svg>
                        </a>
                        <a href="#" aria-label="Follow us on Twitter" class="social-link">
                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M6.29 18.251c7.547 0 11.675-6.253 11.675-11.675 0-.178 0-.355-.012-.53A8.348 8.348 0 0020 3.92a8.19 8.19 0 01-2.357.646 4.118 4.118 0 001.804-2.27 8.224 8.224 0 01-2.605.996 4.107 4.107 0 00-6.993 3.743 11.65 11.65 0 01-8.457-4.287 4.106 4.106 0 001.27 5.477A4.073 4.073 0 01.8 7.713v.052a4.105 4.105 0 003.292 4.022 4.095 4.095 0 01-1.853.07 4.108 4.108 0 003.834 2.85A8.233 8.233 0 010 16.407a11.616 11.616 0 006.29 1.84"/>
                            </svg>
                        </a>
                    </div>
                </div>

                <div class="footer-section">
                    <h3 class="footer-section-title">Product</h3>
                    <ul class="footer-links">
                        <li><a href="/features">Features</a></li>
                        <li><a href="/pricing">Pricing</a></li>
                        <li><a href="/security">Security</a></li>
                        <li><a href="/integrations">Integrations</a></li>
                    </ul>
                </div>

                <div class="footer-section">
                    <h3 class="footer-section-title">Resources</h3>
                    <ul class="footer-links">
                        <li><a href="/api/docs">API Documentation</a></li>
                        <li><a href="/help">Help Center</a></li>
                        <li><a href="/guides">Guides</a></li>
                        <li><a href="/changelog">Changelog</a></li>
                    </ul>
                </div>

                <div class="footer-section">
                    <h3 class="footer-section-title">Support</h3>
                    <ul class="footer-links">
                        <li><a href="/contact">Contact Us</a></li>
                        <li><a href="/support">Support</a></li>
                        <li><a href="/health">System Status</a></li>
                        <li><a href="/feedback">Feedback</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <div class="footer-bottom-content">
                    <p class="footer-copyright">
                        &copy; <?= date('Y') ?> Security Scanner Tool. All rights reserved.
                    </p>
                    <ul class="footer-legal">
                        <li><a href="/privacy">Privacy Policy</a></li>
                        <li><a href="/terms">Terms of Service</a></li>
                        <li><a href="/cookies">Cookie Policy</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

    <!-- Loading indicator -->
    <div id="loading-indicator" class="loading-indicator" aria-hidden="true" role="status">
        <div class="loading-spinner"></div>
        <span class="sr-only">Loading...</span>
    </div>

    <!-- Global Toast Notifications with Alpine.js -->
    <div x-data="toastManager()"
         class="fixed bottom-4 right-4 z-50 space-y-3"
         style="pointer-events: none;">
        <template x-for="toast in toasts" :key="toast.id">
            <div x-show="true"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 transform translate-x-full"
                 x-transition:enter-end="opacity-100 transform translate-x-0"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 transform translate-x-0"
                 x-transition:leave-end="opacity-0 transform translate-x-full"
                 class="max-w-sm w-full bg-white dark:bg-secondary-800 shadow-lg rounded-lg pointer-events-auto border"
                 :class="{
                     'border-success-200 dark:border-success-800': toast.type === 'success',
                     'border-warning-200 dark:border-warning-800': toast.type === 'warning',
                     'border-danger-200 dark:border-danger-800': toast.type === 'error',
                     'border-info-200 dark:border-info-800': toast.type === 'info'
                 }">
                <div class="p-4">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <!-- Success Icon -->
                            <svg x-show="toast.type === 'success'" class="w-5 h-5 text-success-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                            <!-- Warning Icon -->
                            <svg x-show="toast.type === 'warning'" class="w-5 h-5 text-warning-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                            </svg>
                            <!-- Error Icon -->
                            <svg x-show="toast.type === 'error'" class="w-5 h-5 text-danger-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                            </svg>
                            <!-- Info Icon -->
                            <svg x-show="toast.type === 'info'" class="w-5 h-5 text-info-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="ml-3 flex-1">
                            <p class="text-sm font-medium text-secondary-900 dark:text-white" x-text="toast.message"></p>
                        </div>
                        <div class="ml-4 flex-shrink-0 flex">
                            <button @click="removeToast(toast.id)"
                                    class="inline-flex text-secondary-400 hover:text-secondary-600 dark:hover:text-secondary-300 focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <span class="sr-only">Close</span>
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <!-- JavaScript -->
    <script src="/assets/js/app.js" defer></script>

    <!-- Configuration and CSRF Token -->
    <script>
        window.AppConfig = {
            csrfToken: '<?= htmlspecialchars($csrfToken ?? '') ?>',
            apiUrl: '<?= htmlspecialchars($apiUrl ?? '/api') ?>',
            currentUser: <?= json_encode($user ?? null) ?>,
            environment: '<?= htmlspecialchars($environment ?? 'production') ?>',
            version: '<?= htmlspecialchars($version ?? '1.0.0') ?>',
            features: <?= json_encode($features ?? []) ?>
        };

        // Service Worker registration
        if ('serviceWorker' in navigator && '<?= $environment ?? 'production' ?>' === 'production') {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js')
                    .then(function(registration) {
                        console.log('ServiceWorker registration successful');
                    })
                    .catch(function(err) {
                        console.log('ServiceWorker registration failed');
                    });
            });
        }
    </script>

    <?php if (isset($scripts) && !empty($scripts)): ?>
    <?php foreach ($scripts as $script): ?>
    <script src="<?= htmlspecialchars($script) ?>" defer></script>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (isset($inlineScripts) && !empty($inlineScripts)): ?>
    <script>
        <?= implode("\n", $inlineScripts) ?>
    </script>
    <?php endif; ?>
</body>
</html>