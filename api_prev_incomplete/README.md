# Aero Morning News Extractor API

Laravel API for extracting aviation news from emails and syncing to WordPress.

## Project Structure

This API:
1. **Fetches unread emails** from IMAP inbox
2. **Extracts/downloads images** and optimizes them (700x400, <1MB, JPG format)
3. **Uses OpenAI** to analyze and format content in French and English
4. **Generates SEO metadata** (titles, descriptions, focus keyphrases)
5. **Classifies news** into WordPress categories and tags
6. **Stores data** in local database with status tracking
7. **Provides API endpoints** for WordPress integration

## Installation

### 1. Prerequisites
- PHP 8.1+
- Laravel 10
- MySQL 5.7+
- Node.js/npm (for asset compilation)
- Composer

### 2. Clone and Setup

```bash
cd c:\xampp8\htdocs\extract_news
composer install
cp .env.example .env
php artisan key:generate
```

### 3. Configure Environment

Edit `.env` with your settings:

```env
# Database
DB_DATABASE=extract_news
DB_USERNAME=root
DB_PASSWORD=

# IMAP Settings
IMAP_HOST=your-email-host.com
IMAP_PORT=993
IMAP_USERNAME=your-email@example.com
IMAP_PASSWORD=your-imap-password
IMAP_ENCRYPTION=ssl

# OpenAI
OPENAI_API_KEY=your-openai-api-key
OPENAI_MODEL=gpt-4-turbo-preview

# WordPress URLs
WORDPRESS_FR_URL=https://preprod.aeromorning.com
WORDPRESS_EN_URL=https://preprod.aeromorning.com/en

# Image Processing (optional - defaults provided)
IMAGE_WIDTH=700
IMAGE_HEIGHT=400
IMAGE_MAX_SIZE=1000000
IMAGE_BACKGROUND_COLOR=#005A8C
IMAGE_QUALITY=85
```

### 4. Database Setup

```bash
php artisan migrate
```

This creates:
- `t_categories_fr` - French WordPress categories
- `t_categories_en` - English WordPress categories
- `t_tags_fr` - French WordPress tags
- `t_tags_en` - English WordPress tags
- `t_news` - Processed news articles

## API Endpoints

### 1. Sync WordPress Data

**POST** `/api/sync-wordpress`

Fetches and updates categories and tags from WordPress.

```bash
curl -X POST http://localhost:8000/api/sync-wordpress
```

**Response:**
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

### 2. Process Emails

**POST** `/api/process-emails`

Fetches unread emails, extracts content, generates metadata, and saves to database.

```bash
curl -X POST http://localhost:8000/api/process-emails
```

**Response:**
```json
{
    "success": true,
    "message": "Email processing completed",
    "processed": 5,
    "failed": 0
}
```

### 3. Get Pending News

**GET** `/api/news/pending`

Returns paginated list of news waiting to be published.

```bash
curl http://localhost:8000/api/news/pending
```

**Response:**
```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "lang": "FR",
                "title": "Airbus A350 livraison",
                "content": "<p>...</p>",
                "metadescription": "Découvrez les détails de la nouvelle livraison...",
                "focuskeyphrase": "Airbus A350 livraison",
                "categories": "1,5,8",
                "tags": "3,7",
                "image_url": "/storage/images/airbus-a350-livraison-xxx.jpg",
                "status": 0,
                "created_at": "2024-01-15T10:30:00Z"
            }
        ],
        "per_page": 15,
        "total": 42
    }
}
```

### 4. Get News by ID

**GET** `/api/news/{id}`

```bash
curl http://localhost:8000/api/news/1
```

### 5. Update News Status

**PATCH** `/api/news/{id}/status/{status}`

Update publication status:
- `0` - Pending
- `1` - Syncing to WordPress
- `2` - Synced successfully

```bash
curl -X PATCH http://localhost:8000/api/news/1/status/2
```

## Console Commands

### Sync WordPress Data

```bash
php artisan wordpress:sync
```

### Process Emails

```bash
php artisan emails:process
```

## Setting Up Automated Processing

### Using Windows Task Scheduler

1. Create a batch file `process-news.bat`:
```batch
@echo off
cd C:\xampp8\htdocs\extract_news
php artisan emails:process
```

2. Schedule in Task Scheduler to run every 15 minutes

### Using Laravel Scheduler (Advanced)

Edit `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('emails:process')->everyFifteenMinutes();
    $schedule->command('wordpress:sync')->daily();
}

protected function commands()
{
    $this->call('schedule:run');
}
```

Then run:
```bash
php artisan schedule:work
```

## Email Format

Emails should contain:
- **Subject**: News headline
- **Body**: Article content (HTML or plain text)
- **Attachment OR embedded image**: Featured image (PNG, JPG, GIF, WebP)

Example:
```
From: news@aeromorning.com
Subject: Airbus Receives 100 Orders from Middle Eastern Carriers

Body:
<html>
<body>
<h1>Airbus Receives 100 Orders</h1>
<p>Airbus has announced today...</p>
<img src="https://example.com/image.jpg" />
</body>
</html>
```

## News Data Structure

Each processed news article contains:

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Database ID |
| `lang` | enum | 'FR' or 'EN' |
| `title` | text | SEO title (max 53 chars) |
| `content` | longtext | Clean HTML content |
| `metadescription` | text | SEO meta description (106-141 chars) |
| `focuskeyphrase` | text | SEO focus keyphrase (2-5 words) |
| `categories` | text | Comma-separated WordPress category IDs |
| `tags` | text | Comma-separated WordPress tag IDs |
| `image_url` | text | Local path to optimized image |
| `status` | tinyint | 0=pending, 1=syncing, 2=synced |
| `email_message_id` | string | Unique email identification |
| `created_at` | timestamp | Creation date |
| `updated_at` | timestamp | Last update date |

## Image Processing Details

The API automatically:
1. **Downloads** images from email attachments or embedded URLs
2. **Resizes** to fit within 700x400 dimensions
3. **Centers** image on solid background (#005A8C)
4. **Converts** to JPG format
5. **Optimizes** quality to keep file < 1MB
6. **Names** files as: `{title-slug}-{unique-id}.jpg`
7. **Stores** in: `/storage/images/`

## OpenAI Prompts

The API uses specialized prompts for:
- French/English title generation (SEO optimized, max 53 chars)
- French/English content extraction (clean HTML, WordPress-ready)
- French/English meta descriptions (106-141 chars, engaging)
- French/English focus keyphrases (2-5 words, with entities)
- Category classification (editorial rules, always including "News")
- Tag classification (aviation-specific entities)

## Troubleshooting

### IMAP Connection Error
```
Check IMAP settings in .env:
- Host, port, encryption type correct?
- Username/password valid?
- Account allows IMAP access?
```

### OpenAI API Errors
```
- Verify API key is valid
- Check API quota and billing
- Ensure model name is correct (gpt-4-turbo-preview)
```

### Image Processing Issues
```
- Check storage/ folder permissions
- Verify GD library installed: php -m | grep GD
- Check IMAGE_MAX_SIZE environment variable
```

### Email Processing Stuck
```
Check logs: tail -f storage/logs/laravel.log
Verify email count: check email_message_id column for duplicates
Reset stalled emails: UPDATE t_news SET status=0 WHERE status=1
```

## Database Maintenance

### Clear old data
```sql
DELETE FROM t_news WHERE status=2 AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

### Check statistics
```sql
SELECT lang, status, COUNT(*) as count FROM t_news GROUP BY lang, status;
```

## Deployment Notes

1. Create required directories:
   ```bash
   mkdir -p storage/app/attachments
   mkdir -p storage/app/public/images
   mkdir -p storage/app/temp
   chmod -R 775 storage/
   ```

2. Configure web server to serve `/storage/app/public` as `/storage`

3. Run migrations:
   ```bash
   php artisan migrate --force
   ```

4. Test API:
   ```bash
   php artisan serve
   # Then test endpoints in browser or Postman
   ```

## License

This project is proprietary. All rights reserved.
