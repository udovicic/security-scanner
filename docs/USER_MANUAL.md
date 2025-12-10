# Security Scanner Tool - User Manual

## Table of Contents
1. [Getting Started](#getting-started)
2. [Dashboard Overview](#dashboard-overview)
3. [Managing Websites](#managing-websites)
4. [Configuring Tests](#configuring-tests)
5. [Understanding Results](#understanding-results)
6. [Advanced Features](#advanced-features)
7. [Troubleshooting](#troubleshooting)

---

## Getting Started

### First Time Setup

1. **Access the Application**
   - Open your web browser
   - Navigate to: `http://your-server-address/`
   - You'll see the dashboard

2. **Add Your First Website**
   - Click "Add Website" button
   - Fill in the required information
   - Click "Save"

---

## Dashboard Overview

The dashboard provides a quick overview of all your monitored websites.

### Dashboard Elements

**Summary Cards:**
- **Total Websites**: Count of all websites being monitored
- **Active Scans**: Number of scans currently running
- **Recent Failures**: Count of failed tests in the last 24 hours
- **Average Response Time**: Overall average response time

**Website List:**
- Each website shows:
  - Name and URL
  - Last scan time
  - Test pass/fail count
  - Status indicator (green = good, red = issues, yellow = warning)

**Quick Actions:**
- 🔄 **Refresh**: Update dashboard with latest data
- ➕ **Add Website**: Create new website entry
- 📊 **View Metrics**: See detailed metrics and trends

---

## Managing Websites

### Adding a New Website

1. Click the **"Add Website"** button
2. Fill in the form:

**Required Fields:**
- **Name**: Friendly name for the website (e.g., "Company Blog")
- **URL**: Full website URL including protocol
  - ✅ Correct: `https://example.com`
  - ❌ Incorrect: `example.com`, `www.example.com`
- **Scan Frequency**: How often to scan
  - Options: Hourly, Daily, Weekly, Monthly

3. Click **"Save"**

### Editing a Website

1. Click on a website from the dashboard
2. Click **"Edit"** button
3. Modify any fields
4. Click **"Save Changes"**

**Note:** Changing the scan frequency will reset the next scan time.

### Deleting a Website

1. Navigate to the website detail page
2. Click **"Delete"** button
3. Confirm the deletion

**⚠️ Warning:** This action cannot be undone. All test history for this website will be permanently deleted.

### Pausing Monitoring

To temporarily stop monitoring a website without deleting it:

1. Go to website detail page
2. Click **"Pause Monitoring"**
3. To resume, click **"Resume Monitoring"**

**Note:** Paused websites won't run scheduled scans, but you can still run manual scans.

---

## Configuring Tests

### Available Tests

The system includes 4 security tests:

#### 1. SSL Certificate Check
**What it does:** Validates that your website has a valid SSL certificate and it's not expiring soon.

**Pass Criteria:**
- ✅ Certificate is present
- ✅ Certificate is valid (not expired)
- ✅ Certificate expires in more than 7 days

**Common Failures:**
- Expired certificate
- Self-signed certificate
- Certificate expiring within 7 days

**Recommended for:** All HTTPS websites

---

#### 2. Security Headers
**What it does:** Checks if important security HTTP headers are present.

**Checked Headers:**
- `Strict-Transport-Security` (HSTS)
- `X-Frame-Options`
- `X-Content-Type-Options`
- `Content-Security-Policy`
- `X-XSS-Protection`

**Pass Criteria:**
- ✅ At least 3 out of 5 headers are present

**Common Failures:**
- Missing security headers
- Weak header configurations

**Recommended for:** All websites

---

#### 3. HTTP Status
**What it does:** Verifies the website is accessible and returns a successful status code.

**Pass Criteria:**
- ✅ HTTP status code is 200 (OK)

**Common Failures:**
- 404 Not Found
- 500 Server Error
- 503 Service Unavailable
- Connection timeout

**Recommended for:** All websites

---

#### 4. Response Time
**What it does:** Measures how quickly your website responds.

**Pass Criteria:**
- ✅ Response time under 3 seconds

**Common Failures:**
- Slow server response
- Database query issues
- Network latency

**Recommended for:** All websites

---

### Enabling/Disabling Tests

1. Navigate to website detail page
2. Scroll to **"Test Configuration"** section
3. Toggle switches to enable/disable tests
4. Click **"Save Configuration"**

**Tip:** Disable tests that aren't relevant to your website. For example, disable SSL Certificate test for HTTP-only sites.

### Test Inversion

**What is Test Inversion?**
Test inversion flips the pass/fail logic of a test. A test that would normally pass will fail, and vice versa.

**Use Cases:**
- Testing that a website is properly blocked
- Verifying that a redirect is working
- Ensuring a maintenance page is active

**How to Enable:**
1. Go to website test configuration
2. Check the **"Invert"** checkbox next to the test
3. Save configuration

**Example:**
- Normal: HTTP Status 200 = ✅ Pass, 404 = ❌ Fail
- Inverted: HTTP Status 200 = ❌ Fail, 404 = ✅ Pass

---

## Understanding Results

### Test Execution Page

After a scan completes, you'll see detailed results for each test.

### Result Status Icons

- ✅ **Passed**: Test completed successfully
- ❌ **Failed**: Test found an issue
- ⏳ **Running**: Test is currently executing
- ⏭️ **Skipped**: Test was disabled or skipped
- ⚠️ **Warning**: Test passed but with warnings

### Reading Test Results

Each test result includes:

**1. Status**: Pass/Fail indicator

**2. Message**: Human-readable explanation
```
Example: "SSL certificate is valid and expires in 89 days"
```

**3. Execution Time**: How long the test took
```
Example: 1.23 seconds
```

**4. Details**: Additional technical information
```json
{
  "issuer": "Let's Encrypt",
  "expires_at": "2026-03-10",
  "days_remaining": 89
}
```

**5. Next Action**: What to do if the test failed
```
Example: "Renew your SSL certificate before 2026-03-03"
```

### Test History

View historical test results:

1. Go to website detail page
2. Click **"History"** tab
3. See a timeline of all past scans

**Features:**
- Filter by date range
- Filter by test name
- View trends over time
- Export to CSV

---

## Advanced Features

### Manual Scans

To run a scan immediately (outside the schedule):

1. Go to website detail page
2. Click **"Run Scan Now"** button
3. Wait for results (usually 5-30 seconds)

**Note:** Manual scans don't affect the regular schedule.

### Bulk Operations

**Add Multiple Websites:**
1. Go to Websites page
2. Click **"Bulk Import"**
3. Upload CSV file or paste list
4. Configure default settings
5. Click **"Import"**

**CSV Format:**
```csv
name,url,frequency
Company Website,https://company.com,daily
Blog,https://blog.company.com,weekly
```

### Notifications (Future Feature)

Email and webhook notifications will be available in a future release:
- Instant alerts for test failures
- Daily summary reports
- Custom notification rules

### Scheduled Maintenance Windows

Prevent false alarms during planned maintenance:

1. Go to website detail page
2. Click **"Maintenance Mode"**
3. Set start and end time
4. Tests will be paused during this window

---

## Troubleshooting

### Common Issues

#### Website Shows "Connection Failed"

**Possible Causes:**
- URL is incorrect
- Website is down
- Firewall blocking the scanner
- DNS resolution failure

**Solutions:**
1. Verify the URL is correct and accessible in a browser
2. Check if website is online: `curl -I https://yoursite.com`
3. Ensure the server running the scanner can reach the website
4. Check firewall rules and whitelist scanner IP

---

#### SSL Certificate Test Always Fails

**Possible Causes:**
- Self-signed certificate
- Certificate expired
- Hostname mismatch
- Intermediate certificates missing

**Solutions:**
1. Verify certificate is valid: `openssl s_client -connect yoursite.com:443`
2. Ensure certificate matches the domain
3. Check certificate chain is complete
4. If using self-signed cert for testing, disable this test

---

#### Tests Take Too Long

**Possible Causes:**
- Network latency
- Slow website response
- Too many tests running simultaneously
- Server resources constrained

**Solutions:**
1. Increase test timeout in configuration
2. Reduce scan frequency for slow sites
3. Disable non-essential tests
4. Check server resources (CPU, memory, network)

---

#### Scheduled Scans Not Running

**Possible Causes:**
- Cron job not configured
- Scheduler service stopped
- Database connection issues

**Solutions:**
1. Verify cron job is active: `crontab -l`
2. Check scheduler logs: `tail -f logs/scheduler.log`
3. Test database connection
4. Restart scheduler: `php cli/scheduler.php restart`

---

### Getting Help

**Check Logs:**
```bash
# Application errors
tail -f logs/error.log

# Scheduler activity
tail -f logs/scheduler.log

# Access logs
tail -f logs/access.log
```

**Debug Mode:**
Enable debug mode in `.env`:
```
APP_DEBUG=true
APP_ENV=development
```

**⚠️ Warning:** Disable debug mode in production!

**Support Resources:**
- Documentation: `/docs/`
- GitHub Issues: Create a ticket
- Community Forum: [Link]

---

## Best Practices

### Scan Frequency Guidelines

| Website Type | Recommended Frequency |
|--------------|----------------------|
| Production websites | Daily |
| Development/Staging | Weekly |
| Personal blogs | Weekly |
| Critical services | Hourly |
| Static sites | Monthly |

### Test Selection Guidelines

**All Websites Should Have:**
- ✅ HTTP Status test
- ✅ Response Time test

**HTTPS Websites Should Add:**
- ✅ SSL Certificate test
- ✅ Security Headers test

### Performance Tips

1. **Don't Over-Monitor**: More frequent scans = more server load
2. **Stagger Scan Times**: If monitoring many sites, use different frequencies
3. **Disable Unused Tests**: Less tests = faster scans
4. **Archive Old Results**: Regularly clean up test history
5. **Monitor Scanner Health**: Keep an eye on scanner server resources

### Security Recommendations

1. **Use HTTPS**: Always prefer HTTPS over HTTP
2. **Whitelist Scanner IP**: Add scanner IP to website firewall
3. **Rotate Credentials**: Change passwords regularly
4. **Limit Access**: Restrict who can modify configurations
5. **Enable Audit Logging**: Track all configuration changes

---

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Ctrl + N` | Add new website |
| `Ctrl + R` | Refresh dashboard |
| `Ctrl + S` | Save current form |
| `Ctrl + F` | Search websites |
| `Esc` | Close modal/dialog |

---

## Glossary

**Execution**: A complete scan session for a website, including all enabled tests.

**Test Result**: The outcome of a single test within an execution.

**Test Inversion**: Flipping the pass/fail logic of a test.

**Scan Frequency**: How often automated scans run for a website.

**Manual Scan**: An on-demand scan triggered by the user.

**Test Timeout**: Maximum time allowed for a test to complete.

**Pass Rate**: Percentage of tests that passed in a given time period.

---

## Appendix

### Configuration File Reference

Location: `.env`

```bash
# Application
APP_NAME="Security Scanner"
APP_ENV=production
APP_DEBUG=false

# Database
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=security_scanner
DB_USERNAME=scanner
DB_PASSWORD=secure_password

# Scanning
SCAN_TIMEOUT=30
MAX_CONCURRENT_SCANS=5
RETRY_FAILED_TESTS=3

# Notifications (Future)
NOTIFY_EMAIL=admin@example.com
WEBHOOK_URL=https://hooks.example.com/scanner
```

### CLI Commands

```bash
# Run migrations
php cli/migrate.php migrate

# Start scheduler
php cli/scheduler.php start

# Stop scheduler
php cli/scheduler.php stop

# Run manual scan
php cli/scan.php --website=1

# Clear old results
php cli/cleanup.php --days=30
```

---

## Version History

**v1.0.0** (Current)
- Initial release
- 4 security tests
- Web dashboard
- Scheduled scanning
- Basic API

**Planned Features:**
- Email notifications
- Webhook support
- More security tests
- Mobile app
- Custom test plugins
