<?php $title = 'Dashboard - Security Scanner'; ?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2>Security Scanner Dashboard</h2>
        <p class="dashboard-subtitle">Monitor your websites' security status</p>
    </div>

    <div class="dashboard-stats">
        <div class="stat-card">
            <h3>Total Websites</h3>
            <div class="stat-value"><?= htmlspecialchars($metrics['total_websites'] ?? '0') ?></div>
            <div class="stat-change">
                <?php if (isset($metrics['websites_change'])): ?>
                <span class="change <?= $metrics['websites_change'] >= 0 ? 'positive' : 'negative' ?>">
                    <?= $metrics['websites_change'] >= 0 ? '+' : '' ?><?= htmlspecialchars($metrics['websites_change']) ?>
                </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="stat-card">
            <h3>Scans Today</h3>
            <div class="stat-value"><?= htmlspecialchars($metrics['scans_today'] ?? '0') ?></div>
            <div class="stat-description">Automated security scans</div>
        </div>

        <div class="stat-card">
            <h3>Success Rate</h3>
            <div class="stat-value"><?= htmlspecialchars(number_format($metrics['success_rate'] ?? 0, 1)) ?>%</div>
            <div class="stat-description">Last 7 days</div>
        </div>

        <div class="stat-card">
            <h3>Active Issues</h3>
            <div class="stat-value"><?= htmlspecialchars($metrics['active_issues'] ?? '0') ?></div>
            <div class="stat-description">Require attention</div>
        </div>
    </div>

    <div class="dashboard-content">
        <div class="dashboard-section">
            <h3>Recent Activity</h3>
            <div class="activity-list">
                <?php if (isset($recent_activity) && !empty($recent_activity)): ?>
                <?php foreach ($recent_activity as $activity): ?>
                <div class="activity-item">
                    <div class="activity-icon">
                        <span class="status-<?= htmlspecialchars($activity['status']) ?>">●</span>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title"><?= htmlspecialchars($activity['title']) ?></div>
                        <div class="activity-time"><?= htmlspecialchars($activity['time']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state">
                    <p>No recent activity to display.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="dashboard-section">
            <h3>System Health</h3>
            <div class="health-indicators">
                <?php if (isset($system_health)): ?>
                <?php foreach ($system_health as $component => $status): ?>
                <div class="health-item">
                    <span class="health-label"><?= htmlspecialchars(ucfirst($component)) ?></span>
                    <span class="health-status status-<?= htmlspecialchars($status) ?>">
                        <?= htmlspecialchars(ucfirst($status)) ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state">
                    <p>System health data unavailable.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="dashboard-section full-width">
            <h3>Websites Overview</h3>
            <div class="websites-overview">
                <?php if (isset($websites_overview) && !empty($websites_overview)): ?>
                <div class="websites-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Website</th>
                                <th>Status</th>
                                <th>Last Scan</th>
                                <th>Success Rate</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($websites_overview as $website): ?>
                            <tr>
                                <td>
                                    <div class="website-info">
                                        <strong><?= htmlspecialchars($website['name']) ?></strong>
                                        <small><?= htmlspecialchars($website['url']) ?></small>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= htmlspecialchars($website['status']) ?>">
                                        <?= htmlspecialchars(ucfirst($website['status'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($website['last_scan'] ?? 'Never') ?></td>
                                <td><?= htmlspecialchars(number_format($website['success_rate'] ?? 0, 1)) ?>%</td>
                                <td>
                                    <a href="/websites/<?= htmlspecialchars($website['id']) ?>" class="btn btn-sm">View</a>
                                    <button class="btn btn-sm btn-primary" onclick="startScan(<?= htmlspecialchars($website['id']) ?>)">
                                        Scan Now
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <p>No websites configured yet.</p>
                    <a href="/websites/create" class="btn btn-primary">Add Your First Website</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function startScan(websiteId) {
    if (!confirm('Start a new security scan for this website?')) {
        return;
    }

    fetch(`/api/websites/${websiteId}/scan`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.csrfToken
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Scan started successfully!');
            location.reload();
        } else {
            alert('Failed to start scan: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to start scan due to a network error.');
    });
}

// Auto-refresh dashboard every 30 seconds
setInterval(() => {
    location.reload();
}, 30000);
</script>