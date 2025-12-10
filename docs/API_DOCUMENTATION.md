# Security Scanner API Documentation

## Table of Contents
- [Authentication](#authentication)
- [Response Format](#response-format)
- [Error Handling](#error-handling)
- [Endpoints](#endpoints)
  - [Websites](#websites)
  - [Tests](#tests)
  - [Results](#results)
  - [Dashboard](#dashboard)

## Authentication

Currently, the API uses session-based authentication. Future versions will support API tokens.

```http
POST /api/login
Content-Type: application/json

{
  "username": "admin",
  "password": "your-password"
}
```

## Response Format

All API responses follow a consistent JSON format:

**Success Response:**
```json
{
  "success": true,
  "data": { ... },
  "message": "Operation completed successfully"
}
```

**Error Response:**
```json
{
  "success": false,
  "error": "Error message",
  "code": "ERROR_CODE"
}
```

## Error Handling

### HTTP Status Codes

| Code | Meaning | Description |
|------|---------|-------------|
| 200 | OK | Request successful |
| 201 | Created | Resource created successfully |
| 400 | Bad Request | Invalid request parameters |
| 404 | Not Found | Resource not found |
| 422 | Unprocessable Entity | Validation failed |
| 500 | Internal Server Error | Server error occurred |

### Error Codes

| Code | Description |
|------|-------------|
| `VALIDATION_ERROR` | Request validation failed |
| `NOT_FOUND` | Resource does not exist |
| `DATABASE_ERROR` | Database operation failed |
| `TEST_EXECUTION_ERROR` | Test execution failed |

---

## Endpoints

### Websites

#### List All Websites

```http
GET /api/websites
```

**Query Parameters:**
- `page` (optional) - Page number (default: 1)
- `limit` (optional) - Items per page (default: 20)
- `search` (optional) - Search term for name/URL
- `status` (optional) - Filter by status: `active`, `paused`, `error`

**Response:**
```json
{
  "success": true,
  "data": {
    "websites": [
      {
        "id": 1,
        "name": "Example Website",
        "url": "https://example.com",
        "status": "active",
        "scan_frequency": "daily",
        "last_scan_at": "2025-12-10 10:30:00",
        "next_scan_at": "2025-12-11 10:30:00",
        "total_tests": 12,
        "failed_tests": 2,
        "passed_tests": 10
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 5,
      "total_items": 92,
      "per_page": 20
    }
  }
}
```

#### Get Website Details

```http
GET /api/websites/{id}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Example Website",
    "url": "https://example.com",
    "status": "active",
    "scan_frequency": "daily",
    "last_scan_at": "2025-12-10 10:30:00",
    "next_scan_at": "2025-12-11 10:30:00",
    "created_at": "2025-01-01 00:00:00",
    "updated_at": "2025-12-10 10:30:00",
    "test_config": [
      {
        "test_id": 1,
        "test_name": "ssl_certificate",
        "enabled": true,
        "inverted": false
      }
    ]
  }
}
```

#### Create Website

```http
POST /api/websites
Content-Type: application/json

{
  "name": "My Website",
  "url": "https://mywebsite.com",
  "scan_frequency": "daily",
  "enabled_tests": [1, 2, 3],
  "inverted_tests": [2]
}
```

**Request Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Website display name |
| `url` | string | Yes | Full URL including protocol |
| `scan_frequency` | string | Yes | One of: `hourly`, `daily`, `weekly`, `monthly` |
| `enabled_tests` | array | No | Array of test IDs to enable |
| `inverted_tests` | array | No | Array of test IDs to invert |

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 42,
    "name": "My Website",
    "url": "https://mywebsite.com",
    "status": "active",
    "scan_frequency": "daily",
    "next_scan_at": "2025-12-11 10:00:00"
  },
  "message": "Website created successfully"
}
```

#### Update Website

```http
PUT /api/websites/{id}
Content-Type: application/json

{
  "name": "Updated Website Name",
  "scan_frequency": "weekly"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 42,
    "name": "Updated Website Name",
    "url": "https://mywebsite.com",
    "scan_frequency": "weekly"
  },
  "message": "Website updated successfully"
}
```

#### Delete Website

```http
DELETE /api/websites/{id}
```

**Response:**
```json
{
  "success": true,
  "message": "Website deleted successfully"
}
```

#### Run Manual Scan

```http
POST /api/websites/{id}/scan
```

**Response:**
```json
{
  "success": true,
  "data": {
    "execution_id": 123,
    "status": "running",
    "started_at": "2025-12-10 15:30:00"
  },
  "message": "Scan started successfully"
}
```

---

### Tests

#### List Available Tests

```http
GET /api/tests
```

**Response:**
```json
{
  "success": true,
  "data": {
    "tests": [
      {
        "id": 1,
        "name": "ssl_certificate",
        "display_name": "SSL Certificate Check",
        "description": "Validates SSL certificate is present, valid, and not expiring soon",
        "category": "security",
        "supports_inversion": true
      },
      {
        "id": 2,
        "name": "security_headers",
        "display_name": "Security Headers",
        "description": "Checks for presence of important security headers",
        "category": "security",
        "supports_inversion": true
      },
      {
        "id": 3,
        "name": "http_status",
        "display_name": "HTTP Status",
        "description": "Verifies the website is accessible and returns 200 OK",
        "category": "availability",
        "supports_inversion": false
      },
      {
        "id": 4,
        "name": "response_time",
        "display_name": "Response Time",
        "description": "Measures website response time and alerts if too slow",
        "category": "performance",
        "supports_inversion": false
      }
    ]
  }
}
```

#### Get Test Configuration for Website

```http
GET /api/websites/{id}/tests
```

**Response:**
```json
{
  "success": true,
  "data": {
    "tests": [
      {
        "test_id": 1,
        "test_name": "ssl_certificate",
        "enabled": true,
        "inverted": false,
        "last_result": "passed",
        "last_run": "2025-12-10 10:30:00"
      }
    ]
  }
}
```

#### Update Test Configuration

```http
PUT /api/websites/{id}/tests
Content-Type: application/json

{
  "tests": [
    {
      "test_id": 1,
      "enabled": true,
      "inverted": false
    },
    {
      "test_id": 2,
      "enabled": true,
      "inverted": true
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Test configuration updated successfully"
}
```

---

### Results

#### Get Execution Results

```http
GET /api/executions/{execution_id}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 123,
    "website_id": 1,
    "website_name": "Example Website",
    "status": "completed",
    "started_at": "2025-12-10 10:30:00",
    "completed_at": "2025-12-10 10:30:45",
    "duration": 45,
    "total_tests": 4,
    "passed_tests": 3,
    "failed_tests": 1,
    "results": [
      {
        "test_name": "ssl_certificate",
        "status": "passed",
        "message": "SSL certificate is valid and expires in 89 days",
        "execution_time": 1.23,
        "details": {
          "issuer": "Let's Encrypt",
          "expires_at": "2026-03-10",
          "days_remaining": 89
        }
      },
      {
        "test_name": "security_headers",
        "status": "failed",
        "message": "Missing security headers: X-Frame-Options, X-Content-Type-Options",
        "execution_time": 0.45,
        "details": {
          "present_headers": ["Strict-Transport-Security"],
          "missing_headers": ["X-Frame-Options", "X-Content-Type-Options"]
        }
      }
    ]
  }
}
```

#### Get Website Execution History

```http
GET /api/websites/{id}/executions
```

**Query Parameters:**
- `limit` (optional) - Number of results (default: 10, max: 100)
- `offset` (optional) - Offset for pagination

**Response:**
```json
{
  "success": true,
  "data": {
    "executions": [
      {
        "id": 125,
        "status": "completed",
        "started_at": "2025-12-10 10:30:00",
        "duration": 45,
        "passed_tests": 3,
        "failed_tests": 1
      },
      {
        "id": 124,
        "status": "completed",
        "started_at": "2025-12-09 10:30:00",
        "duration": 42,
        "passed_tests": 4,
        "failed_tests": 0
      }
    ],
    "total": 150
  }
}
```

---

### Dashboard

#### Get Dashboard Summary

```http
GET /api/dashboard
```

**Response:**
```json
{
  "success": true,
  "data": {
    "summary": {
      "total_websites": 25,
      "active_websites": 22,
      "paused_websites": 3,
      "total_executions_today": 45,
      "failed_executions_today": 3,
      "average_response_time": 1.23
    },
    "recent_failures": [
      {
        "website_id": 5,
        "website_name": "Problem Site",
        "test_name": "ssl_certificate",
        "failed_at": "2025-12-10 14:30:00",
        "message": "SSL certificate expired"
      }
    ],
    "upcoming_scans": [
      {
        "website_id": 10,
        "website_name": "Next Site",
        "scheduled_at": "2025-12-10 16:00:00"
      }
    ]
  }
}
```

#### Get Metrics

```http
GET /api/metrics
```

**Query Parameters:**
- `period` (optional) - Time period: `day`, `week`, `month` (default: `week`)
- `website_id` (optional) - Filter by specific website

**Response:**
```json
{
  "success": true,
  "data": {
    "period": "week",
    "metrics": {
      "total_scans": 350,
      "success_rate": 94.5,
      "average_execution_time": 2.34,
      "by_test": [
        {
          "test_name": "ssl_certificate",
          "total_runs": 175,
          "pass_rate": 98.0
        }
      ],
      "by_day": [
        {
          "date": "2025-12-10",
          "scans": 45,
          "failures": 3
        }
      ]
    }
  }
}
```

---

## Usage Examples

### JavaScript/Fetch

```javascript
// Get all websites
async function getWebsites() {
  const response = await fetch('/api/websites');
  const data = await response.json();

  if (data.success) {
    console.log('Websites:', data.data.websites);
  } else {
    console.error('Error:', data.error);
  }
}

// Create a new website
async function createWebsite() {
  const response = await fetch('/api/websites', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      name: 'My Website',
      url: 'https://example.com',
      scan_frequency: 'daily',
      enabled_tests: [1, 2, 3, 4]
    })
  });

  const data = await response.json();

  if (data.success) {
    console.log('Created website:', data.data);
  } else {
    console.error('Error:', data.error);
  }
}

// Trigger manual scan
async function runScan(websiteId) {
  const response = await fetch(`/api/websites/${websiteId}/scan`, {
    method: 'POST'
  });

  const data = await response.json();

  if (data.success) {
    console.log('Scan started:', data.data.execution_id);
  }
}
```

### cURL

```bash
# List websites
curl -X GET http://localhost/api/websites

# Create website
curl -X POST http://localhost/api/websites \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My Website",
    "url": "https://example.com",
    "scan_frequency": "daily",
    "enabled_tests": [1, 2, 3, 4]
  }'

# Update website
curl -X PUT http://localhost/api/websites/1 \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Updated Name",
    "scan_frequency": "weekly"
  }'

# Delete website
curl -X DELETE http://localhost/api/websites/1

# Run manual scan
curl -X POST http://localhost/api/websites/1/scan

# Get execution results
curl -X GET http://localhost/api/executions/123

# Get dashboard summary
curl -X GET http://localhost/api/dashboard
```

### PHP

```php
<?php

// Initialize cURL
function apiRequest($endpoint, $method = 'GET', $data = null) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, 'http://localhost' . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
    }

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// Get all websites
$websites = apiRequest('/api/websites');
print_r($websites);

// Create website
$newWebsite = apiRequest('/api/websites', 'POST', [
    'name' => 'My Website',
    'url' => 'https://example.com',
    'scan_frequency' => 'daily',
    'enabled_tests' => [1, 2, 3, 4]
]);
print_r($newWebsite);

// Run scan
$scan = apiRequest('/api/websites/1/scan', 'POST');
print_r($scan);
```

---

## Rate Limiting

The API implements rate limiting to prevent abuse:

- **Default Limit:** 100 requests per minute per IP
- **Burst Limit:** 20 requests per second

Rate limit headers are included in responses:
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 95
X-RateLimit-Reset: 1702224000
```

## Webhooks (Future Feature)

Webhook support for real-time notifications is planned for a future release.

---

## Support

For issues or questions:
- GitHub Issues: [Create an issue](#)
- Documentation: [Full Documentation](#)
