#!/bin/bash

# Security Scanner Tool - Build Script
# This script builds the frontend assets for production

set -e

echo "🔧 Installing dependencies..."
npm install

echo "🎨 Building CSS with Tailwind..."
npm run build-css-prod

echo "📦 Building JavaScript with esbuild..."
npm run build-js-prod

echo "🧹 Running linter..."
npm run lint

echo "✅ Build completed successfully!"

# Set proper permissions
chmod -R 644 public/assets/css/*
chmod -R 644 public/assets/js/*

echo "📁 Asset permissions updated"
echo "🚀 Ready for production!"