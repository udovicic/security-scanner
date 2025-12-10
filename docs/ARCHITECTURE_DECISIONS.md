# Security Scanner - Architecture Decision Records (ADRs)

## Table of Contents
1. [ADR-001: No Framework Approach](#adr-001-no-framework-approach)
2. [ADR-002: PHP 8.4 and MySQL Database](#adr-002-php-84-and-mysql-database)
3. [ADR-003: Plugin-Based Test Architecture](#adr-003-plugin-based-test-architecture)
4. [ADR-004: Single Entry Point Pattern](#adr-004-single-entry-point-pattern)
5. [ADR-005: Background Processing with Cron](#adr-005-background-processing-with-cron)
6. [ADR-006: Tailwind CSS and Alpine.js for Frontend](#adr-006-tailwind-css-and-alpinejs-for-frontend)
7. [ADR-007: Test Result Inversion Feature](#adr-007-test-result-inversion-feature)
8. [ADR-008: Layered Architecture Pattern](#adr-008-layered-architecture-pattern)
9. [ADR-009: Session-Based Authentication](#adr-009-session-based-authentication)
10. [ADR-010: File-Based Caching Strategy](#adr-010-file-based-caching-strategy)

---

## ADR-001: No Framework Approach

**Status:** Accepted

**Date:** 2025-01-01

### Context
The project requires a custom security scanner tool with specific requirements. We needed to decide whether to use an existing PHP framework (Laravel, Symfony, etc.) or build from scratch.

### Decision
Build the application from scratch without using an existing PHP framework.

### Rationale

**Pros:**
- **Learning Opportunity**: Deeper understanding of web application internals
- **Full Control**: Complete control over architecture and implementation
- **No Overhead**: Avoid framework bloat and unused features
- **Performance**: Optimized for specific use case without framework overhead
- **Customization**: Easy to tailor exactly to requirements
- **Simplicity**: Easier for new developers to understand the entire codebase

**Cons:**
- **Development Time**: Longer initial development compared to using a framework
- **Reinventing Wheels**: Need to implement features that frameworks provide out-of-the-box
- **Security Risks**: Must carefully implement security features ourselves
- **Maintenance**: More code to maintain and keep secure

### Consequences

**Positive:**
- Complete architectural freedom
- Optimized performance for our specific use case
- No framework update dependencies
- Smaller codebase overall
- Educational value for the team

**Negative:**
- Must implement routing, validation, ORM, etc. from scratch
- Longer time to market
- Need careful security review of custom components
- Less community support compared to popular frameworks

### Implementation Notes
- Created custom Router, Database, Request/Response classes
- Implemented PSR-4 autoloading
- Built dependency injection container
- Developed validation and security helpers

---

## ADR-002: PHP 8.4 and MySQL Database

**Status:** Accepted

**Date:** 2025-01-01

### Context
Need to select programming language and database technology for the application.

### Decision
Use PHP 8.4 as the backend language and MySQL 8.0 as the database.

### Rationale

**PHP 8.4 Benefits:**
- **Performance**: Significant performance improvements with JIT compilation
- **Type System**: Strong typing with union types, intersection types, readonly properties
- **Modern Features**: Enums, attributes, constructor property promotion
- **Wide Adoption**: Large community, extensive documentation
- **Hosting Support**: Available on most shared hosting platforms
- **Mature Ecosystem**: Vast library of packages and tools

**MySQL Benefits:**
- **Reliability**: Battle-tested, enterprise-grade database
- **Performance**: Excellent performance for read-heavy workloads
- **JSON Support**: Native JSON column type for flexible data storage
- **ACID Compliance**: Full transaction support
- **Tooling**: Rich ecosystem of management tools
- **Community**: Large community and extensive documentation

**Alternatives Considered:**
- **PostgreSQL**: Excellent but MySQL more widely available on hosting
- **SQLite**: Too limited for multi-user concurrent access
- **PHP 7.4**: Lacks modern type system features
- **Node.js**: Team more experienced with PHP

### Consequences

**Positive:**
- Fast development with familiar technologies
- Excellent hosting availability
- Strong type safety with PHP 8.4
- Reliable data persistence with MySQL
- Good performance characteristics

**Negative:**
- Locked into PHP ecosystem
- MySQL has some limitations compared to PostgreSQL
- Requires careful schema design for scalability

### Implementation Notes
- Leveraged PHP 8.4 strict types throughout
- Used MySQL JSON columns for flexible test result storage
- Implemented prepared statements for SQL injection prevention

---

## ADR-003: Plugin-Based Test Architecture

**Status:** Accepted

**Date:** 2025-01-02

### Context
The system needs to support multiple security tests that can be easily added or removed. Need an architecture that allows for easy extensibility.

### Decision
Implement a plugin-based architecture where each test is a standalone class implementing a common interface.

### Rationale

**Design:**
```php
abstract class AbstractTest {
    abstract public function execute(string $target): TestResult;
    abstract public function getName(): string;
    abstract public function getDescription(): string;
}
```

**Benefits:**
- **Extensibility**: New tests can be added without modifying core code
- **Isolation**: Each test is independent and self-contained
- **Maintainability**: Easy to update or remove individual tests
- **Testing**: Each test can be unit tested independently
- **Discovery**: Automatic test registration via directory scanning

**Alternatives Considered:**
- **Monolithic Test Service**: All tests in one class - rejected due to poor separation of concerns
- **Database-Driven Tests**: Test logic in database - rejected as it limits code reusability
- **External Plugins**: Downloadable plugins - rejected as overkill for current requirements

### Consequences

**Positive:**
- Easy to add new tests by creating new classes
- Tests can be distributed as standalone files
- Clear separation between test logic and execution engine
- Simple for developers to understand and contribute new tests

**Negative:**
- Slight overhead for test discovery and registration
- Need to ensure all tests follow the contract
- Version compatibility considerations for tests

### Implementation Notes
- Created `AbstractTest` base class with common functionality
- Implemented `TestRegistry` for automatic test discovery
- Added `PluginManager` for enable/disable functionality
- Tests located in `src/Tests/SecurityTests/` directory

---

## ADR-004: Single Entry Point Pattern

**Status:** Accepted

**Date:** 2025-01-02

### Context
Need to decide on the request handling architecture for the web application.

### Decision
Implement a Front Controller pattern with `public/index.php` as the sole entry point for all HTTP requests.

### Rationale

**Benefits:**
- **Security**: Single point to implement security checks
- **Consistency**: All requests go through the same initialization
- **Clean URLs**: Easy to implement URL rewriting
- **Middleware**: Centralized location for middleware processing
- **Limited Attack Surface**: Only one file exposed in document root

**Implementation:**
```
/public/index.php  (only public file)
    ↓
bootstrap.php (initialize)
    ↓
Router (match route)
    ↓
Controller (handle request)
    ↓
Response (send output)
```

**Alternatives Considered:**
- **Multiple Entry Points**: One PHP file per page - rejected as harder to secure and maintain
- **Direct File Access**: Allow direct access to PHP files - rejected due to security concerns

### Consequences

**Positive:**
- Enhanced security with single entry point
- Easier to implement global error handling
- Simplified dependency injection
- Clean URL structure
- Better performance with centralized initialization

**Negative:**
- Requires web server URL rewriting (`.htaccess` or nginx config)
- All requests go through PHP even for static files (mitigated with web server rules)

### Implementation Notes
- Created `public/index.php` as sole entry point
- Configured Apache `.htaccess` with rewrite rules
- Provided Nginx configuration example
- Implemented routing system to dispatch requests

---

## ADR-005: Background Processing with Cron

**Status:** Accepted

**Date:** 2025-01-03

### Context
The application needs to run scheduled scans in the background without user interaction.

### Decision
Use Linux cron jobs combined with a custom PHP scheduler for background processing.

### Rationale

**Approach:**
```
Cron Job (every minute)
    ↓
scheduler.php (check scheduled scans)
    ↓
Fork/Execute Tests
    ↓
Store Results in Database
```

**Benefits:**
- **Reliability**: Cron is battle-tested and reliable
- **Simple**: No additional infrastructure required
- **Standard**: Available on all Linux systems
- **Resource Efficient**: Only runs when needed
- **Easy Debugging**: Clear log files

**Alternatives Considered:**
- **Message Queue (Redis, RabbitMQ)**: Overkill for current scale, adds infrastructure complexity
- **Database Polling**: Less efficient, constant database queries
- **Continuous Daemon**: More complex, requires process management
- **Laravel Horizon/Queue**: Requires Laravel framework

### Consequences

**Positive:**
- Simple deployment (just add cron job)
- No additional services to manage
- Works on any Linux hosting
- Easy to monitor via log files
- Low resource usage

**Negative:**
- Minimum granularity of 1 minute
- No built-in retry mechanisms (must implement in PHP)
- Potential overlap if jobs run longer than interval
- Harder to scale horizontally

### Implementation Notes
- Created `cli/scheduler.php` for background processing
- Implemented database locking to prevent overlapping executions
- Added comprehensive logging
- Documented cron setup in deployment guide

---

## ADR-006: Tailwind CSS and Alpine.js for Frontend

**Status:** Accepted

**Date:** 2025-01-03

### Context
Need to select frontend technologies for building the user interface.

### Decision
Use Tailwind CSS for styling and Alpine.js for interactive components.

### Rationale

**Tailwind CSS Benefits:**
- **Utility-First**: Rapid development with utility classes
- **Consistency**: Enforced design system through configuration
- **Small Bundle**: PurgeCSS removes unused styles
- **Responsive**: Built-in responsive design utilities
- **Customizable**: Easy to theme and extend

**Alpine.js Benefits:**
- **Lightweight**: Only 15kb minified
- **Vue-like Syntax**: Familiar for Vue.js developers
- **No Build Step**: Works directly in HTML
- **Progressive Enhancement**: Degrades gracefully without JavaScript
- **Perfect for Sprinkles**: Ideal for adding interactivity to server-rendered HTML

**Alternatives Considered:**
- **Bootstrap**: Too opinionated, harder to customize
- **React/Vue**: Overkill for mostly server-rendered app
- **jQuery**: Outdated, larger bundle size
- **Vanilla JS**: More verbose, no reactive data binding

### Consequences

**Positive:**
- Fast development with utility classes
- Minimal JavaScript for interactivity
- Great performance (small bundle sizes)
- Works well with server-side rendering
- Easy to maintain and understand

**Negative:**
- HTML can look verbose with many utility classes
- Learning curve for Tailwind's utility-first approach
- Need build process for optimal production bundle

### Implementation Notes
- Configured Tailwind with custom color palette
- Used Alpine.js for dashboard updates, form validation
- Implemented progressive enhancement (works without JS)
- Created reusable component templates

---

## ADR-007: Test Result Inversion Feature

**Status:** Accepted

**Date:** 2025-01-04

### Context
Some use cases require treating a normally passing test as a failure and vice versa (e.g., ensuring a site is blocked).

### Decision
Implement a "test inversion" feature allowing users to flip the pass/fail logic of any test.

### Rationale

**Use Cases:**
1. **Blocked Sites**: Verify a site returns 403/404 (normally a failure)
2. **Maintenance Mode**: Ensure maintenance page is active
3. **Redirect Testing**: Verify redirects are working
4. **Access Control**: Confirm restricted areas are properly blocked

**Implementation:**
```php
if ($testConfig['inverted']) {
    $result->status = $result->status === 'passed' ? 'failed' : 'passed';
    $result->message = '[INVERTED] ' . $result->message;
}
```

**Benefits:**
- **Flexibility**: Single test serves multiple purposes
- **DRY Principle**: Don't duplicate tests for opposite scenarios
- **User Control**: Users can invert tests per website
- **Simple**: Easy to understand and implement

**Alternatives Considered:**
- **Separate Tests**: Create opposite tests (e.g., "HTTP Status is NOT 200") - rejected as code duplication
- **Custom Test Logic**: Let users write custom logic - rejected as too complex
- **No Feature**: Force users to interpret results manually - rejected as poor UX

### Consequences

**Positive:**
- Increased flexibility for various use cases
- Reduced code duplication
- User-friendly configuration
- Tests remain simple and focused

**Negative:**
- Slightly more complex result interpretation
- Need to clearly indicate inverted results in UI
- Could confuse users if not documented well

### Implementation Notes
- Added `inverted` boolean column to `website_test_config` table
- Implemented inversion in `ResultInverter` class
- UI clearly shows "[INVERTED]" badge on inverted tests
- Documentation includes inversion examples

---

## ADR-008: Layered Architecture Pattern

**Status:** Accepted

**Date:** 2025-01-04

### Context
Need to organize code in a maintainable, scalable manner with clear separation of concerns.

### Decision
Implement a layered architecture with the following layers:
1. **Presentation Layer**: Controllers and Views
2. **Application Layer**: Services and Business Logic
3. **Domain Layer**: Models and Entities
4. **Infrastructure Layer**: Database, Cache, External APIs

### Rationale

**Architecture:**
```
┌─────────────────────────────────┐
│  Presentation (Controllers)     │
├─────────────────────────────────┤
│  Application (Services)         │
├─────────────────────────────────┤
│  Domain (Models, Entities)      │
├─────────────────────────────────┤
│  Infrastructure (DB, Cache)     │
└─────────────────────────────────┘
```

**Benefits:**
- **Separation of Concerns**: Each layer has clear responsibility
- **Testability**: Easy to test layers independently
- **Maintainability**: Changes in one layer don't ripple to others
- **Scalability**: Can optimize individual layers
- **Clarity**: New developers understand structure quickly

**Layer Responsibilities:**
- **Presentation**: HTTP handling, input validation, response formatting
- **Application**: Business logic, workflow orchestration
- **Domain**: Core entities, business rules, validations
- **Infrastructure**: External dependencies, persistence

**Alternatives Considered:**
- **MVC Pattern**: Too rigid for our needs
- **Hexagonal Architecture**: Overkill for current scale
- **Flat Structure**: Would become unmaintainable

### Consequences

**Positive:**
- Clear code organization
- Easy to locate functionality
- Better testability
- Reduced coupling
- Easier onboarding for new developers

**Negative:**
- More initial setup
- Some boilerplate code
- Need discipline to maintain separation

### Implementation Notes
- Organized code into `Controllers/`, `Services/`, `Models/`, `Core/`
- Services contain business logic, controllers are thin
- Models handle data access, not business logic
- Core contains infrastructure concerns

---

## ADR-009: Session-Based Authentication

**Status:** Accepted

**Date:** 2025-01-05

### Context
Need to implement user authentication for accessing the dashboard and managing websites.

### Decision
Use traditional session-based authentication with PHP sessions.

### Rationale

**Approach:**
```
Login → Create Session → Store User ID → Validate on Each Request
```

**Benefits:**
- **Simple**: PHP has built-in session support
- **Secure**: Session ID only, no credentials in cookie
- **Server-Controlled**: Can invalidate sessions server-side
- **Standard**: Well-understood security model
- **No Tokens**: No need for JWT infrastructure

**Security Measures:**
- HttpOnly cookies (prevent XSS)
- Secure flag (HTTPS only)
- SameSite attribute (CSRF protection)
- Session regeneration on privilege change
- Session timeout

**Alternatives Considered:**
- **JWT Tokens**: More complex, harder to invalidate, better for APIs (future consideration)
- **OAuth**: Overkill for single-tenant application
- **Basic Auth**: Insecure, credentials sent with every request

### Consequences

**Positive:**
- Simple implementation
- Secure with proper configuration
- Works well for server-rendered pages
- Easy session management
- No token refresh complexity

**Negative:**
- Server must maintain session state
- Doesn't scale horizontally without session storage
- Not ideal for API-only clients (future API may use tokens)

### Implementation Notes
- Configured secure session parameters in `php.ini`
- Implemented CSRF protection for forms
- Added remember-me functionality
- Session storage in database for horizontal scaling (future)

---

## ADR-010: File-Based Caching Strategy

**Status:** Accepted

**Date:** 2025-01-05

### Context
Application needs caching to improve performance, particularly for dashboard metrics and test results.

### Decision
Implement file-based caching as the default caching mechanism.

### Rationale

**Benefits:**
- **Zero Dependencies**: No Redis or Memcached required
- **Simple Setup**: Works immediately, no configuration
- **Reliable**: File system is always available
- **Sufficient Performance**: Fast enough for current scale
- **Easy Debugging**: Cache entries are readable files

**Structure:**
```
cache/
├── metrics/
│   ├── dashboard-summary.cache
│   └── website-1-stats.cache
└── queries/
    └── query-hash-abc123.cache
```

**When to Use:**
- Dashboard summary statistics (5 min TTL)
- Website metrics (15 min TTL)
- Expensive database query results (10 min TTL)
- Available test list (until code deploy)

**Alternatives Considered:**
- **Redis**: Better performance but adds infrastructure dependency
- **Memcached**: Similar to Redis
- **No Caching**: Poor performance on dashboard
- **Database Caching**: Adds load to database

### Consequences

**Positive:**
- Simple deployment (no extra services)
- Works on any hosting environment
- Easy to implement and maintain
- Good enough performance for current scale
- Easy cache invalidation (delete files)

**Negative:**
- Slower than in-memory caching (Redis/Memcached)
- File system locks on high concurrency
- Need to clean old cache files
- Doesn't work across multiple servers without shared storage

**Future Considerations:**
- Switch to Redis if performance becomes an issue
- Implement cache tagging for easier invalidation
- Add cache warming for frequently accessed data

### Implementation Notes
- Created `Core/Cache.php` class
- Implemented automatic cache expiration
- Added cache clear command: `php cli/cache.php clear`
- Configured appropriate TTLs for different data types

---

## Summary

These architectural decisions provide the foundation for the Security Scanner application. They balance:

- **Simplicity** vs **Scalability**
- **Performance** vs **Maintainability**
- **Learning** vs **Productivity**
- **Control** vs **Convenience**

As the application grows, some decisions may be revisited (marked as "Superseded" with new ADRs), but the current architecture serves the project's needs well.

---

## Future ADRs to Consider

- **ADR-011**: API Authentication Strategy (JWT vs OAuth)
- **ADR-012**: Horizontal Scaling Approach
- **ADR-013**: Real-time Notifications Implementation
- **ADR-014**: Mobile App Architecture
- **ADR-015**: Multi-tenancy Design

---

## Changelog

| Date | ADR | Change |
|------|-----|--------|
| 2025-01-05 | ADR-010 | Initial version |
| 2025-01-05 | ADR-009 | Initial version |
| 2025-01-04 | ADR-008 | Initial version |
| 2025-01-04 | ADR-007 | Initial version |
| 2025-01-03 | ADR-006 | Initial version |
| 2025-01-03 | ADR-005 | Initial version |
| 2025-01-02 | ADR-004 | Initial version |
| 2025-01-02 | ADR-003 | Initial version |
| 2025-01-01 | ADR-002 | Initial version |
| 2025-01-01 | ADR-001 | Initial version |
