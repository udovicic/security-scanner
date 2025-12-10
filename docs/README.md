# Security Scanner Documentation

Welcome to the Security Scanner Tool documentation! This directory contains comprehensive guides for using, deploying, and understanding the application.

## 📚 Documentation Index

### For Users

- **[User Manual](USER_MANUAL.md)** - Complete guide for using the Security Scanner
  - Getting started
  - Managing websites
  - Configuring tests
  - Understanding results
  - Troubleshooting

### For Developers

- **[API Documentation](API_DOCUMENTATION.md)** - Complete API reference
  - All endpoints documented
  - Request/response examples
  - Authentication guide
  - Usage examples in JavaScript, cURL, and PHP

- **[Architecture Decisions](ARCHITECTURE_DECISIONS.md)** - ADRs explaining key technical decisions
  - Why we built from scratch (no framework)
  - Technology choices (PHP 8.4, MySQL)
  - Design patterns used
  - Trade-offs and rationale

### For System Administrators

- **[Deployment Guide](DEPLOYMENT_GUIDE.md)** - Step-by-step deployment instructions
  - Server requirements
  - Installation steps
  - Web server configuration
  - Security hardening
  - Performance optimization
  - Monitoring and maintenance

## 🚀 Quick Start

**For New Users:**
1. Read [Getting Started](USER_MANUAL.md#getting-started) in the User Manual
2. Add your first website
3. View your scan results

**For Developers:**
1. Review [API Documentation](API_DOCUMENTATION.md)
2. Check [Architecture Decisions](ARCHITECTURE_DECISIONS.md)
3. Start making API calls

**For Deployment:**
1. Follow [Deployment Guide](DEPLOYMENT_GUIDE.md)
2. Complete the production checklist
3. Monitor application health

## 📖 Additional Resources

### Project Files

- `business-requirements.md` - Original business requirements
- `technical-documentation.md` - Detailed technical specifications
- `TODO.md` - Development roadmap and completed features
- `CLAUDE.md` - Development best practices and code standards

### Key Directories

```
/security-scanner/
├── docs/                    # 📚 Documentation (you are here)
├── public/                  # 🌐 Web accessible files
├── src/
│   ├── Controllers/        # 🎮 Request handlers
│   ├── Services/           # 💼 Business logic
│   ├── Tests/              # 🧪 Security tests
│   ├── Core/               # ⚙️ Framework components
│   └── Views/              # 👁️ HTML templates
├── config/                 # ⚙️ Configuration files
├── migrations/             # 🗄️ Database migrations
├── cli/                    # 🔧 Command-line tools
└── tests/                  # ✅ Unit & integration tests
```

## 🆘 Getting Help

### For Issues

1. **Check Documentation**: Most questions are answered here
2. **Search Logs**: Look in `/logs/` for error details
3. **Enable Debug Mode**: Set `APP_DEBUG=true` in `.env` (development only!)
4. **Create Issue**: Report bugs on GitHub

### For Questions

- **User Questions**: See [Troubleshooting](USER_MANUAL.md#troubleshooting)
- **API Questions**: See [API Documentation](API_DOCUMENTATION.md)
- **Deployment Questions**: See [Deployment Guide](DEPLOYMENT_GUIDE.md)

### Support Channels

- 📧 Email: support@example.com
- 💬 GitHub Issues: [Create an issue](#)
- 📖 Documentation: `/docs/` (you are here)

## 🔄 Documentation Updates

This documentation is continuously improved. Last updated: **2025-12-10**

### Recent Changes

- **2025-12-10**: Initial documentation release
  - ✅ API Documentation
  - ✅ User Manual
  - ✅ Deployment Guide
  - ✅ Architecture Decision Records

### Contributing to Docs

Found an error or want to improve the documentation?

1. Edit the relevant `.md` file
2. Follow the existing formatting
3. Submit a pull request
4. Documentation is written in Markdown

## 📋 Documentation Checklist

Before deploying, ensure you've read:

- [ ] Deployment Guide - Server setup
- [ ] Security Hardening section
- [ ] Production Checklist
- [ ] Performance Optimization
- [ ] Monitoring & Maintenance

## 🎯 Documentation Goals

Our documentation aims to be:

- **Complete**: Cover all features and use cases
- **Clear**: Easy to understand for all skill levels
- **Accurate**: Regularly updated and tested
- **Practical**: Include real-world examples
- **Searchable**: Well-organized with good structure

## 📞 Feedback

Help us improve! If you find:
- ❌ Errors or outdated information
- ❓ Missing information
- 💡 Suggestions for improvement

Please let us know via GitHub issues or email.

---

**Happy Scanning! 🔒**
