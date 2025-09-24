#!/bin/bash

# Security Scanner Tool - Deployment Script Example
# Copy this to deploy.sh and customize for your environment

set -e  # Exit on any error

echo "🚀 Deploying Security Scanner Tool..."

# 1. Pull latest code
echo "📥 Pulling latest code from repository..."
git pull origin main

# 2. Install/update dependencies (if using Composer)
# echo "📦 Installing dependencies..."
# composer install --no-dev --optimize-autoloader

# 3. Copy environment configuration
echo "⚙️  Setting up environment configuration..."
if [ ! -f .env ]; then
    echo "⚠️  No .env file found. Copying from .env.production..."
    cp .env.production .env
    echo "✅ Please review and update .env file with your production settings"
fi

# 4. Create necessary directories
echo "📁 Creating necessary directories..."
mkdir -p logs
mkdir -p cache
mkdir -p public/build

# 5. Set proper permissions
echo "🔒 Setting file permissions..."
chmod -R 755 public/
chmod -R 777 logs/
chmod -R 777 cache/
chmod -R 777 public/build/

# 6. Build assets
echo "🎨 Building and optimizing assets..."
php build_assets.php --clear-cache

# 7. Database operations (if needed)
# echo "💾 Running database migrations..."
# php migrate.php

# 8. Clear application cache
echo "🧹 Clearing application cache..."
rm -rf cache/*

# 9. Verify deployment
echo "🔍 Verifying deployment..."
php -f public/index.php >/dev/null 2>&1 && echo "✅ Application is accessible" || echo "❌ Application check failed"

# 10. Restart services (if needed)
# echo "🔄 Restarting services..."
# sudo systemctl restart apache2  # or nginx
# sudo systemctl restart php8.4-fpm  # if using PHP-FPM

echo "🎉 Deployment completed successfully!"
echo ""
echo "📝 Post-deployment checklist:"
echo "   - Verify .env configuration"
echo "   - Test critical functionality"
echo "   - Check error logs: tail -f logs/error.log"
echo "   - Monitor application performance"
echo ""
echo "🌐 Application should be available at: $(grep APP_URL .env | cut -d'=' -f2)"