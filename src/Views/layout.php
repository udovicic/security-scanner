<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Security Scanner Tool') ?></title>

    <!-- Security Headers -->
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="/build/app.css">

    <!-- Progressive Enhancement -->
    <script>
        document.documentElement.classList.add('js-enabled');
    </script>
</head>
<body>
    <header class="main-header">
        <nav class="main-navigation" role="navigation" aria-label="Main navigation">
            <div class="nav-brand">
                <h1><a href="/">Security Scanner</a></h1>
            </div>
            <ul class="nav-menu">
                <li><a href="/dashboard">Dashboard</a></li>
                <li><a href="/websites">Websites</a></li>
                <li><a href="/tests">Tests</a></li>
                <li><a href="/results">Results</a></li>
                <li><a href="/api/docs">API</a></li>
            </ul>
        </nav>
    </header>

    <main class="main-content" role="main">
        <?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <?php foreach ($breadcrumbs as $breadcrumb): ?>
                <li>
                    <?php if (isset($breadcrumb['url'])): ?>
                    <a href="<?= htmlspecialchars($breadcrumb['url']) ?>">
                        <?= htmlspecialchars($breadcrumb['title']) ?>
                    </a>
                    <?php else: ?>
                    <span><?= htmlspecialchars($breadcrumb['title']) ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </nav>
        <?php endif; ?>

        <?php if (isset($alerts) && !empty($alerts)): ?>
        <div class="alerts">
            <?php foreach ($alerts as $alert): ?>
            <div class="alert alert-<?= htmlspecialchars($alert['type']) ?>" role="alert">
                <?= htmlspecialchars($alert['message']) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="content">
            <?= $content ?>
        </div>
    </main>

    <footer class="main-footer">
        <div class="footer-content">
            <p>&copy; <?= date('Y') ?> Security Scanner Tool. Built for security monitoring and testing.</p>
            <ul class="footer-links">
                <li><a href="/api/docs">API Documentation</a></li>
                <li><a href="/health">System Health</a></li>
                <li><a href="/about">About</a></li>
            </ul>
        </div>
    </footer>

    <!-- JavaScript -->
    <script src="/build/app.js"></script>

    <!-- CSRF Token for AJAX requests -->
    <script>
        window.csrfToken = '<?= htmlspecialchars($csrfToken ?? '') ?>';
    </script>

    <?php if (isset($scripts) && !empty($scripts)): ?>
    <?php foreach ($scripts as $script): ?>
    <script src="<?= htmlspecialchars($script) ?>"></script>
    <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>