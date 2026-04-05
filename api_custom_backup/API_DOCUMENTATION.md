# API Documentation

Complete reference for all available endpoints.

## Base URL

```
http://localhost:8000/api
```

## Authentication

Most endpoints are public. For WordPress posting, provide credentials in the request body.

---

## 1. Sync WordPress Data

### Endpoint
```
POST /api/sync-wordpress
```

### Description
Fetches latest categories and tags from WordPress and updates local database.

### Request
```bash
curl -X POST http://localhost:8000/api/sync-wordpress
```

### Response
```json
{
    "success": true,
    "message": "WordPress data synced successfully",
    "data": {
        "categories_fr": true,
        "categories_en": true,
        "tags_fr": true,
        "tags_en": true
    }
}
```

### Status Codes
- `200`: Successfully synced
- `500`: Error during sync

---

## 2. Process Unread Emails

### Endpoint
```
POST /api/process-emails
```

### Description
Fetches unread emails from IMAP, extracts content, generates metadata via OpenAI, and saves to database.

### Request
```bash
curl -X POST http://localhost:8000/api/process-emails
```

### Response
```json
{
    "success": true,
    "message": "Email processing completed",
    "processed": 5,
    "failed": 0
}
```

### What Happens
1. Connects to IMAP server
2. Fetches unread emails
3. Extracts/downloads images
4. Detects language (FR/EN)
5. Generates SEO metadata via OpenAI
6. Classifies into categories and tags
7. Saves to `t_news` table
8. Marks emails as read

### Status Codes
- `200`: Processing complete
- `500`: IMAP or processing error

---

## 3. Get Pending News

### Endpoint
```
GET /api/news/pending
```

### Description
Returns paginated list of news awaiting publication (status = 0).

### Request
```bash
curl http://localhost:8000/api/news/pending
```

### Query Parameters
- `page` (optional): Page number for pagination (default: 1)
- `per_page` (optional): Items per page (default: 15)

### Response
```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "lang": "FR",
                "title": "Airbus A350 livraison confirmée",
                "content": "<p>Airbus a confirmé la livraison...</p>",
                "metadescription": "Découvrez les détails de la livraison de l'A350...",
                "focuskeyphrase": "Airbus A350 livraison",
                "categories": "1,5,8",
                "tags": "3,7,12",
                "image_url": "/storage/images/airbus-a350-xxx.jpg",
                "status": 0,
                "email_message_id": "abc123@mail.com",
                "created_at": "2024-01-15T10:30:00Z",
                "updated_at": "2024-01-15T10:30:00Z"
            }
        ],
        "from": 1,
        "last_page": 3,
        "per_page": 15,
        "to": 15,
        "total": 42
    }
}
```

### Status Codes
- `200`: Success
- `500`: Database error

---

## 4. Get News by ID

### Endpoint
```
GET /api/news/{id}
```

### Description
Returns a single news article with all details.

### Request
```bash
curl http://localhost:8000/api/news/1
```

### Response
```json
{
    "success": true,
    "data": {
        "id": 1,
        "lang": "FR",
        "title": "Airbus A350 livraison confirmée",
        "content": "<p>Full HTML content...</p>",
        "metadescription": "Meta description for SEO",
        "focuskeyphrase": "Airbus A350",
        "categories": "1,5",
        "tags": "3,7",
        "image_url": "/storage/images/image.jpg",
        "status": 0,
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2024-01-15T10:30:00Z"
    }
}
```

### Status Codes
- `200`: News found
- `404`: News not found
- `500`: Server error

---

## 5. Update News Status

### Endpoint
```
PATCH /api/news/{id}/status/{status}
```

### Description
Update the publication status of a news article.

### Request
```bash
curl -X PATCH http://localhost:8000/api/news/1/status/2
```

### Parameters
- `id`: News ID
- `status`: New status
  - `0`: Pending
  - `1`: Syncing to WordPress
  - `2`: Synced successfully

### Response
```json
{
    "success": true,
    "message": "News status updated",
    "data": {
        "id": 1,
        "status": 2,
        "updated_at": "2024-01-15T11:45:00Z"
    }
}
```

### Status Codes
- `200`: Status updated
- `400`: Invalid status value
- `404`: News not found
- `500`: Database error

---

## 6. Preview News

### Endpoint
```
GET /api/news/{id}/preview
```

### Description
Get formatted preview of news as it will appear in WordPress.

### Request
```bash
curl http://localhost:8000/api/news/1/preview
```

### Response
```json
{
    "success": true,
    "data": {
        "title": "Airbus A350 livraison confirmée",
        "excerpt": "Découvrez les détails de la livraison...",
        "content": "<p>Full HTML content...</p>",
        "featured_image": "/storage/images/image.jpg",
        "categories": [1, 5, 8],
        "tags": [3, 7],
        "focus_keyphrase": "Airbus A350 livraison"
    }
}
```

---

## 7. Post News to WordPress

### Endpoint
```
POST /api/news/{id}/post-to-wordpress
```

### Description
Publish a news article to WordPress with authentication.

### Request
```bash
curl -X POST http://localhost:8000/api/news/1/post-to-wordpress \
  -H "Content-Type: application/json" \
  -d '{
    "username": "wordpress_user",
    "password": "wordpress_password"
  }'
```

### Request Body
```json
{
    "username": "wordpress_admin_username",
    "password": "wordpress_app_password"
}
```

### Response - Success
```json
{
    "success": true,
    "message": "News posted to WordPress successfully",
    "wordpress_post_id": 1234,
    "data": {
        "id": 1,
        "status": 2,
        "updated_at": "2024-01-15T12:00:00Z"
    }
}
```

### Response - Error
```json
{
    "success": false,
    "message": "Failed to post news to WordPress"
}
```

### Status Codes
- `200`: Posted successfully
- `422`: Validation error (missing credentials)
- `500`: WordPress API error

### How It Works
1. Validates WordPress credentials
2. Uploads featured image to WordPress Media Library
3. Creates post with:
   - Title
   - Content (HTML)
   - Excerpt (meta description)
   - Categories
   - Tags
   - Featured image
   - Meta description (Yoast compatible)
4. Updates local status to "Synced"

---

## 8. Bulk Post News

### Endpoint
```
POST /api/news/bulk-post-to-wordpress
```

### Description
Publish multiple news articles at once.

### Request
```bash
curl -X POST http://localhost:8000/api/news/bulk-post-to-wordpress \
  -H "Content-Type: application/json" \
  -d '{
    "news_ids": [1, 2, 3, 5],
    "username": "wordpress_user",
    "password": "wordpress_password"
  }'
```

### Request Body
```json
{
    "news_ids": [1, 2, 3, 5],
    "username": "wordpress_admin_username",
    "password": "wordpress_app_password"
}
```

### Response
```json
{
    "success": true,
    "message": "Bulk posting completed",
    "success_count": 3,
    "failed_count": 1
}
```

### Status Codes
- `200`: Bulk posting complete
- `422`: Validation error
- `500`: Server error

---

## 9. Get Statistics

### Endpoint
```
GET /api/stats
```

### Description
Get overview statistics of all processed news.

### Request
```bash
curl http://localhost:8000/api/stats
```

### Response
```json
{
    "success": true,
    "data": {
        "total": 147,
        "by_status": {
            "pending": 45,
            "syncing": 2,
            "synced": 100
        },
        "by_language": {
            "FR": 78,
            "EN": 69
        },
        "by_status_and_language": {
            "FR_pending": 20,
            "FR_synced": 58,
            "EN_pending": 25,
            "EN_synced": 44
        }
    }
}
```

---

## Data Models

### News Article Object

```json
{
    "id": 1,
    "lang": "FR",
    "title": "string (max 53 chars)",
    "content": "string (HTML, WordPress-ready)",
    "metadescription": "string (106-141 chars)",
    "focuskeyphrase": "string (2-5 words)",
    "categories": "comma-separated wp_ids",
    "tags": "comma-separated wp_ids",
    "image_url": "/storage/images/filename.jpg",
    "status": 0,
    "email_message_id": "unique_identifier",
    "created_at": "ISO 8601 timestamp",
    "updated_at": "ISO 8601 timestamp"
}
```

### Status Values

| Value | Meaning |
|-------|---------|
| 0 | Pending - awaiting review/approval |
| 1 | Syncing - being posted to WordPress |
| 2 | Synced - successfully published |

### Language Values

| Value | Language |
|-------|----------|
| FR | French |
| EN | English |

---

## Error Responses

All error responses follow this format:

```json
{
    "success": false,
    "message": "Error description"
}
```

### Common HTTP Status Codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 400 | Bad request (invalid data) |
| 404 | Resource not found |
| 422 | Validation error |
| 500 | Server error |

---

## Examples

### Complete Workflow

#### 1. Sync WordPress categories/tags
```bash
curl -X POST http://localhost:8000/api/sync-wordpress
```

#### 2. Process emails
```bash
curl -X POST http://localhost:8000/api/process-emails
```

#### 3. View pending news
```bash
curl http://localhost:8000/api/news/pending
```

#### 4. Preview specific news
```bash
curl http://localhost:8000/api/news/1/preview
```

#### 5. Post to WordPress
```bash
curl -X POST http://localhost:8000/api/news/1/post-to-wordpress \
  -H "Content-Type: application/json" \
  -d '{
    "username": "admin",
    "password": "app_password"
  }'
```

#### 6. Check statistics
```bash
curl http://localhost:8000/api/stats
```

---

## Rate Limiting

No rate limiting is currently implemented. For production, add:

```php
// In Kernel.php
protected $middlewareGroups = [
    'api' => [
        // ... other middleware
        'throttle:60,1', // 60 requests per minute
    ],
];
```

---

## CORS Headers

Add to `routes/api.php` if accessing from different domain:

```php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
```

---

## Webhook Integration

To integrate with external systems, setup webhooks in `app/Events/NewsCreated.php`:

```php
public function handle(NewsCreated $event)
{
    Http::post('https://your-app.com/webhook/news', [
        'news' => $event->news
    ]);
}
```

---

## Performance Tips

1. **Batch Operations**: Use bulk posting endpoint for multiple articles
2. **Caching**: Cache WordPress categories/tags to reduce API calls
3. **Filtering**: Use query parameters to reduce response size
4. **Indexing**: Database queries are optimized with indexes
5. **Pagination**: Always paginate large result sets

---

## Troubleshooting

### 401 Unauthorized
**Cause**: Invalid WordPress credentials
**Solution**: Verify username/password in WordPress admin

### 422 Validation Error
**Cause**: Missing required fields
**Solution**: Check request body has all required fields

### 500 Server Error
**Cause**: Unexpected server error
**Solution**: Check `storage/logs/laravel.log` for details

### Connection Timeout
**Cause**: WordPress server unreachable
**Solution**: Check `WORDPRESS_FR_URL` and `WORDPRESS_EN_URL` in `.env`

For more support, check the main `README.md`.
