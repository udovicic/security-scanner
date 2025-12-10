# Security Scanner Tool - Deployment Guide

## Table of Contents
1. [Server Requirements](#server-requirements)
2. [Installation Steps](#installation-steps)
3. [Configuration](#configuration)
4. [Database Setup](#database-setup)
5. [Web Server Configuration](#web-server-configuration)
6. [Scheduler Setup](#scheduler-setup)
7. [Security Hardening](#security-hardening)
8. [Performance Optimization](#performance-optimization)
9. [Monitoring & Maintenance](#monitoring--maintenance)
10. [Troubleshooting](#troubleshooting)

---

## Server Requirements

### Minimum Requirements

| Component | Requirement |
|-----------|-------------|
| **OS** | Linux (Ubuntu 20.04+, Debian 11+, CentOS 8+) |
| **PHP** | 8.4 or higher |
| **MySQL** | 8.0 or higher (or MariaDB 10.5+) |
| **Web Server** | Apache 2.4+ or Nginx 1.18+ |
| **Memory** | 512 MB RAM minimum, 2 GB recommended |
| **Storage** | 10 GB minimum, 50 GB recommended |
| **CPU** | 1 core minimum, 2+ cores recommended |

### PHP Extensions Required

```bash
php8.4-cli
php8.4-fpm
php8.4-mysql
php8.4-mbstring
php8.4-xml
php8.4-curl
php8.4-json
php8.4-zip
php8.4-gd
php8.4-opcache
```

### Additional Tools

```bash
curl
git
composer (optional, for development)
```

---

## Installation Steps

### Step 1: Prepare the Server

```bash
# Update system packages
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y \
    php8.4 \
    php8.4-cli \
    php8.4-fpm \
    php8.4-mysql \
    php8.4-mbstring \
    php8.4-xml \
    php8.4-curl \
    php8.4-json \
    php8.4-zip \
    php8.4-gd \
    php8.4-opcache \
    mysql-server \
    apache2 \
    git \
    curl

# Verify PHP version
php -v
# Should output: PHP 8.4.x
```

### Step 2: Install MySQL

```bash
# Secure MySQL installation
sudo mysql_secure_installation

# Create database and user
sudo mysql -u root -p
```

```sql
CREATE DATABASE security_scanner CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'scanner'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON security_scanner.* TO 'scanner'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Step 3: Clone/Upload Application

```bash
# Option A: Clone from Git
cd /var/www
sudo git clone https://github.com/yourusername/security-scanner.git html
cd html

# Option B: Upload files
# Upload your application files to /var/www/html

# Set ownership
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 755 /var/www/html
```

### Step 4: Configure Application

```bash
# Copy environment file
cp .env.example .env

# Edit configuration
nano .env
```

Update the following in `.env`:

```bash
# Application
APP_NAME="Security Scanner"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=security_scanner
DB_USERNAME=scanner
DB_PASSWORD=your_secure_password

# Scanning
SCAN_TIMEOUT=30
MAX_CONCURRENT_SCANS=5
SCAN_USER_AGENT="SecurityScanner/1.0"

# Paths
BACKUP_PATH=/var/www/html/backups
LOG_PATH=/var/www/html/logs

# Security
SESSION_LIFETIME=120
CSRF_TOKEN_NAME=csrf_token
```

### Step 5: Run Migrations

```bash
# Run database migrations
php cli/migrate.php migrate

# Verify tables were created
mysql -u scanner -p security_scanner -e "SHOW TABLES;"
```

### Step 6: Set Permissions

```bash
# Create necessary directories
sudo mkdir -p /var/www/html/logs
sudo mkdir -p /var/www/html/cache
sudo mkdir -p /var/www/html/backups

# Set permissions
sudo chown -R www-data:www-data /var/www/html/logs
sudo chown -R www-data:www-data /var/www/html/cache
sudo chown -R www-data:www-data /var/www/html/backups

sudo chmod -R 775 /var/www/html/logs
sudo chmod -R 775 /var/www/html/cache
sudo chmod -R 775 /var/www/html/backups

# Protect sensitive files
sudo chmod 600 /var/www/html/.env
```

---

## Web Server Configuration

### Apache Configuration

**Step 1: Enable Required Modules**

```bash
sudo a2enmod rewrite
sudo a2enmod ssl
sudo a2enmod headers
sudo systemctl restart apache2
```

**Step 2: Create Virtual Host**

```bash
sudo nano /etc/apache2/sites-available/scanner.conf
```

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    ServerAlias www.your-domain.com

    # Redirect all HTTP to HTTPS
    Redirect permanent / https://your-domain.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName your-domain.com
    ServerAlias www.your-domain.com

    DocumentRoot /var/www/html/public

    <Directory /var/www/html/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/scanner-error.log
    CustomLog ${APACHE_LOG_DIR}/scanner-access.log combined

    # SSL Configuration (Let's Encrypt)
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/your-domain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/your-domain.com/privkey.pem

    # Security Headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"

    # PHP Configuration
    php_value upload_max_filesize 10M
    php_value post_max_size 10M
    php_value max_execution_time 300
    php_value memory_limit 256M
</VirtualHost>
```

**Step 3: Enable Site**

```bash
sudo a2ensite scanner.conf
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

### Nginx Configuration

**Create Server Block**

```bash
sudo nano /etc/nginx/sites-available/scanner
```

```nginx
# Redirect HTTP to HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name your-domain.com www.your-domain.com;
    return 301 https://$server_name$request_uri;
}

# HTTPS Server
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;

    server_name your-domain.com www.your-domain.com;
    root /var/www/html/public;
    index index.php index.html;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # Logging
    access_log /var/log/nginx/scanner-access.log;
    error_log /var/log/nginx/scanner-error.log;

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }

    # Deny access to sensitive files
    location ~ ^/(\.env|\.git|logs|cache|backups) {
        deny all;
    }

    # PHP-FPM Configuration
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # URL Rewriting
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Static files caching
    location ~* \.(jpg|jpeg|gif|png|css|js|ico|xml)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
```

**Enable Site**

```bash
sudo ln -s /etc/nginx/sites-available/scanner /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### SSL Certificate (Let's Encrypt)

```bash
# Install Certbot
sudo apt install certbot python3-certbot-apache  # For Apache
# OR
sudo apt install certbot python3-certbot-nginx   # For Nginx

# Obtain certificate
sudo certbot --apache -d your-domain.com -d www.your-domain.com
# OR
sudo certbot --nginx -d your-domain.com -d www.your-domain.com

# Auto-renewal (already configured by Certbot)
sudo certbot renew --dry-run
```

---

## Scheduler Setup

The scheduler runs automated scans at configured intervals.

### Using Cron (Recommended)

**Step 1: Create Scheduler Script**

```bash
sudo nano /etc/cron.d/security-scanner
```

```cron
# Security Scanner - Run every minute to check for scheduled scans
* * * * * www-data php /var/www/html/cli/scheduler.php run >> /var/www/html/logs/scheduler.log 2>&1

# Cleanup old logs - Daily at 2 AM
0 2 * * * www-data php /var/www/html/cli/cleanup.php --days=30 >> /var/www/html/logs/cleanup.log 2>&1

# Database backup - Daily at 3 AM
0 3 * * * www-data php /var/www/html/cli/backup.php >> /var/www/html/logs/backup.log 2>&1
```

**Step 2: Verify Cron Job**

```bash
# Check cron is running
sudo systemctl status cron

# Monitor scheduler logs
tail -f /var/www/html/logs/scheduler.log
```

### Using Systemd Service (Alternative)

**Create Service File**

```bash
sudo nano /etc/systemd/system/scanner-scheduler.service
```

```ini
[Unit]
Description=Security Scanner Scheduler
After=network.target mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/bin/php /var/www/html/cli/scheduler.php daemon
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

**Enable and Start Service**

```bash
sudo systemctl enable scanner-scheduler
sudo systemctl start scanner-scheduler
sudo systemctl status scanner-scheduler
```

---

## Security Hardening

### 1. File Permissions

```bash
# Application files
sudo chown -R www-data:www-data /var/www/html
sudo find /var/www/html -type d -exec chmod 755 {} \;
sudo find /var/www/html -type f -exec chmod 644 {} \;

# Sensitive files
sudo chmod 600 /var/www/html/.env
sudo chmod 600 /var/www/html/config/*.php

# Writable directories
sudo chmod 775 /var/www/html/logs
sudo chmod 775 /var/www/html/cache
sudo chmod 775 /var/www/html/backups
```

### 2. PHP Security Settings

Edit `/etc/php/8.4/fpm/php.ini`:

```ini
; Disable dangerous functions
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source

; Hide PHP version
expose_php = Off

; Session security
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_strict_mode = 1

; File upload limits
upload_max_filesize = 10M
post_max_size = 10M
max_file_uploads = 5

; Resource limits
max_execution_time = 300
max_input_time = 300
memory_limit = 256M
```

### 3. MySQL Security

```sql
-- Remove anonymous users
DELETE FROM mysql.user WHERE User='';

-- Remove remote root access
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');

-- Remove test database
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';

FLUSH PRIVILEGES;
```

### 4. Firewall Configuration

```bash
# UFW (Ubuntu/Debian)
sudo ufw allow 22/tcp    # SSH
sudo ufw allow 80/tcp    # HTTP
sudo ufw allow 443/tcp   # HTTPS
sudo ufw enable

# Firewalld (CentOS/RHEL)
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --reload
```

### 5. Fail2Ban for Brute Force Protection

```bash
# Install Fail2Ban
sudo apt install fail2ban

# Create jail configuration
sudo nano /etc/fail2ban/jail.local
```

```ini
[apache-auth]
enabled = true
port = http,https
filter = apache-auth
logpath = /var/log/apache2/scanner-error.log
maxretry = 5
bantime = 3600
```

```bash
sudo systemctl restart fail2ban
```

---

## Performance Optimization

### 1. PHP OPcache

Edit `/etc/php/8.4/fpm/conf.d/10-opcache.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
opcache.fast_shutdown=1
```

### 2. MySQL Tuning

Edit `/etc/mysql/mysql.conf.d/mysqld.cnf`:

```ini
[mysqld]
# InnoDB Configuration
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT

# Query Cache (for MySQL 5.7, removed in 8.0)
# query_cache_type = 1
# query_cache_size = 64M

# Connection Limits
max_connections = 100
max_connect_errors = 10

# Slow Query Log
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 2
```

### 3. Application Caching

The application includes built-in caching. Verify it's enabled in `.env`:

```bash
CACHE_ENABLED=true
CACHE_DRIVER=file
CACHE_TTL=3600
```

---

## Monitoring & Maintenance

### 1. Log Rotation

```bash
sudo nano /etc/logrotate.d/security-scanner
```

```
/var/www/html/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0664 www-data www-data
    sharedscripts
    postrotate
        /usr/bin/systemctl reload apache2 > /dev/null 2>&1 || true
    endscript
}
```

### 2. Database Backups

Automated backups are configured in the cron job. Manual backup:

```bash
# Backup database
mysqldump -u scanner -p security_scanner > backup_$(date +%Y%m%d).sql

# Restore from backup
mysql -u scanner -p security_scanner < backup_20231210.sql
```

### 3. Health Monitoring

```bash
# Check application health
curl https://your-domain.com/api/health

# Check scheduler status
systemctl status scanner-scheduler

# Check disk space
df -h

# Check MySQL status
systemctl status mysql

# Check Apache/Nginx status
systemctl status apache2
# OR
systemctl status nginx
```

### 4. Performance Monitoring

```bash
# PHP-FPM status
curl http://localhost/php-fpm-status

# MySQL performance
mysql -u scanner -p -e "SHOW PROCESSLIST;"
mysql -u scanner -p -e "SHOW STATUS LIKE '%slow%';"

# Server resources
top
htop
free -h
```

---

## Troubleshooting

### Issue: 500 Internal Server Error

**Check:**
1. Apache/Nginx error logs
2. PHP error logs
3. File permissions
4. `.htaccess` syntax (Apache)

```bash
tail -f /var/log/apache2/scanner-error.log
tail -f /var/log/php8.4-fpm.log
```

### Issue: Database Connection Failed

**Check:**
1. MySQL is running: `systemctl status mysql`
2. Credentials in `.env` are correct
3. Database exists: `mysql -u scanner -p -e "SHOW DATABASES;"`
4. User has permissions

### Issue: Scans Not Running

**Check:**
1. Cron service: `systemctl status cron`
2. Scheduler logs: `tail -f logs/scheduler.log`
3. PHP CLI works: `php cli/scheduler.php test`

### Issue: Slow Performance

**Check:**
1. Server resources: `top`, `free -h`
2. MySQL slow queries: Check slow query log
3. PHP OPcache status
4. Concurrent scan limit in `.env`

---

## Upgrade Procedure

```bash
# 1. Backup current installation
cd /var/www/html
sudo tar -czf ../scanner-backup-$(date +%Y%m%d).tar.gz .
mysqldump -u scanner -p security_scanner > ../db-backup-$(date +%Y%m%d).sql

# 2. Put application in maintenance mode
touch maintenance.lock

# 3. Pull latest code
git pull origin main

# 4. Run migrations
php cli/migrate.php migrate

# 5. Clear cache
rm -rf cache/*

# 6. Restart services
sudo systemctl restart apache2  # or nginx
sudo systemctl restart php8.4-fpm
sudo systemctl restart scanner-scheduler

# 7. Remove maintenance mode
rm maintenance.lock

# 8. Verify deployment
curl https://your-domain.com/api/health
```

---

## Production Checklist

Before going live, verify:

- [ ] `.env` file configured with production values
- [ ] `APP_DEBUG=false` in `.env`
- [ ] SSL certificate installed and auto-renewal configured
- [ ] Database backups scheduled
- [ ] File permissions correctly set
- [ ] Firewall configured
- [ ] Fail2Ban installed and configured
- [ ] Log rotation configured
- [ ] Scheduler cron job running
- [ ] Health check endpoint working
- [ ] Error pages customized
- [ ] Monitoring/alerting set up
- [ ] Documentation reviewed
- [ ] Backup restoration tested

---

## Support

For deployment assistance:
- Documentation: `/docs/`
- GitHub Issues: [Create an issue](#)
- Email: support@example.com
