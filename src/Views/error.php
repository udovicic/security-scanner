<?php $title = 'Error - Security Scanner'; ?>

<div class="error-page">
    <div class="error-content">
        <div class="error-code">
            <?= htmlspecialchars($error_code ?? '500') ?>
        </div>

        <div class="error-message">
            <h2><?= htmlspecialchars($error_title ?? 'An Error Occurred') ?></h2>
            <p><?= htmlspecialchars($error_message ?? 'Sorry, something went wrong. Please try again later.') ?></p>
        </div>

        <div class="error-actions">
            <a href="/" class="btn btn-primary">Go Home</a>
            <button onclick="history.back()" class="btn btn-secondary">Go Back</button>

            <?php if (isset($retry_url)): ?>
            <a href="<?= htmlspecialchars($retry_url) ?>" class="btn btn-outline">Try Again</a>
            <?php endif; ?>
        </div>

        <?php if (isset($debug_info) && !empty($debug_info) && ($_ENV['APP_DEBUG'] ?? false)): ?>
        <div class="error-debug">
            <h3>Debug Information</h3>
            <details>
                <summary>Error Details</summary>
                <pre><?= htmlspecialchars(print_r($debug_info, true)) ?></pre>
            </details>
        </div>
        <?php endif; ?>
    </div>

    <div class="error-help">
        <h3>What can you do?</h3>
        <ul>
            <li>Check the URL for typos</li>
            <li>Try refreshing the page</li>
            <li>Go back to the previous page</li>
            <li>Visit our <a href="/">homepage</a></li>
            <?php if (isset($contact_email)): ?>
            <li>Contact support at <a href="mailto:<?= htmlspecialchars($contact_email) ?>"><?= htmlspecialchars($contact_email) ?></a></li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<style>
.error-page {
    text-align: center;
    padding: 2rem;
    max-width: 800px;
    margin: 0 auto;
}

.error-code {
    font-size: 6rem;
    font-weight: bold;
    color: #dc3545;
    margin-bottom: 1rem;
}

.error-message h2 {
    color: #333;
    margin-bottom: 1rem;
}

.error-message p {
    font-size: 1.1rem;
    color: #666;
    margin-bottom: 2rem;
}

.error-actions {
    margin: 2rem 0;
}

.error-actions .btn {
    margin: 0 0.5rem;
}

.error-debug {
    text-align: left;
    margin-top: 2rem;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 4px;
}

.error-debug pre {
    background: #fff;
    padding: 1rem;
    border-radius: 4px;
    overflow-x: auto;
    max-height: 300px;
}

.error-help {
    text-align: left;
    margin-top: 2rem;
    padding: 1rem;
    background: #e9ecef;
    border-radius: 4px;
}

.error-help ul {
    list-style-type: disc;
    padding-left: 1.5rem;
}

.error-help li {
    margin-bottom: 0.5rem;
}
</style>