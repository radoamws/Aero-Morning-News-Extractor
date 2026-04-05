# Aero Morning News Extractor - Project Summary

## What Has Been Built

A complete Laravel 10 API system that automatically:
1. **Extracts aviation news from emails** via IMAP
2. **Processes images** and optimizes them for WordPress
3. **Generates SEO-optimized content** using OpenAI GPT-4
4. **Classifies articles** into WordPress categories and tags
5. **Stores data** in MySQL database with status tracking
6. **Posts to WordPress** via REST API with full metadata

---

## Project Structure

```
extract_news/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       ├── ProcessEmailsCommand.php      # Command to process emails
│   │       └── SyncWordPressCommand.php      # Command to sync WP data
│   ├── Http/
│   │   └── Controllers/
│   │       ├── NewsController.php            # Main workflow controller
│   │       └── WordPressPostingController.php # WordPress posting controller
│   ├── Models/
│   │   ├── CategoryFr.php                    # French categories model
│   │   ├── CategoryEn.php                    # English categories model
│   │   ├── TagFr.php                         # French tags model
│   │   ├── TagEn.php                         # English tags model
│   │   └── News.php                          # Main news model
│   └── Services/
│       ├── EmailService.php                  # IMAP email handling
│       ├── ImageService.php                  # Image processing
│       ├── OpenAIService.php                 # AI content generation
│       ├── WordPressService.php              # WP sync (fetch categories/tags)
│       └── WordPressPostingService.php       # WP posting (publish articles)
├── database/
│   └── migrations/
│       ├── 2024_01_01_000001_create_categories_fr_table.php
│       ├── 2024_01_01_000002_create_categories_en_table.php
│       ├── 2024_01_01_000003_create_tags_fr_table.php
│       ├── 2024_01_01_000004_create_tags_en_table.php
│       └── 2024_01_01_000005_create_news_table.php
├── routes/
│   └── api.php                               # API endpoint definitions
├── config/
│   └── services.php                          # Service configuration
├── .env.example                              # Environment template
├── composer.json                             # PHP dependencies
├── README.md                                 # Main documentation
├── INSTALLATION.md                           # Installation instructions
├── API_DOCUMENTATION.md                      # API reference
└── postman_collection.json                   # Postman API collection
```

---

## Database Schema

### t_categories_fr
```sql
id (PK, auto_increment)
wp_id (unique)
categ_name
timestamps
```

### t_categories_en
```sql
id (PK, auto_increment)
wp_id (unique)
categ_name
timestamps
```

### t_tags_fr
```sql
id (PK, auto_increment)
wp_id (unique)
tag_name
timestamps
```

### t_tags_en
```sql
id (PK, auto_increment)
wp_id (unique)
tag_name
timestamps
```

### t_news (Main Table)
```sql
id (PK, auto_increment)
lang (FR/EN)                    -- Language of article
title (text)                    -- SEO title (max 53 chars)
content (longtext)              -- HTML content
metadescription (text)          -- Meta description (106-141 chars)
focuskeyphrase (text)           -- Focus keyphrase (2-5 words)
categories (text)               -- Comma-separated category IDs
tags (text)                     -- Comma-separated tag IDs
image_url (text)                -- Local path to image
status (tinyint)                -- 0=pending, 1=syncing, 2=synced
email_message_id (string)       -- Unique email ID
created_at / updated_at         -- Timestamps
```

---

## API Endpoints

### 1. WordPress Synchronization
```
POST /api/sync-wordpress
```
Fetches latest categories and tags from WordPress

### 2. Email Processing
```
POST /api/process-emails
```
Processes unread emails from IMAP

### 3. News Management
```
GET  /api/news/pending           -- Get pending news (paginated)
GET  /api/news/{id}              -- Get single news
GET  /api/news/{id}/preview      -- Preview news formatting
PATCH /api/news/{id}/status/{st} -- Update status
```

### 4. WordPress Posting
```
POST /api/news/{id}/post-to-wordpress              -- Post single
POST /api/news/bulk-post-to-wordpress              -- Post multiple
```

### 5. Statistics
```
GET /api/stats
```

---

## How It Works

### Email Processing Flow

```
1. IMAP Connection
   └─> Fetch unread emails
   
2. Email Content Extraction
   ├─> Subject
   ├─> HTML/Text body
   ├─> Attachments
   └─> Message ID (for deduplication)

3. Image Processing
   ├─> Extract/Download image
   ├─> Convert to JPG
   ├─> Resize to 700x400
   ├─> Add background (#005A8C)
   ├─> Optimize (<1MB)
   └─> Save to /storage/images/

4. Language Detection
   └─> Detect FR and/or EN content

5. OpenAI Content Generation (per language)
   ├─> Generate title (max 53 chars)
   ├─> Generate content (clean HTML)
   ├─> Generate meta description (106-141 chars)
   └─> Generate focus keyphrase (2-5 words)

6. Category Classification
   └─> AI analysis of categories → returns wp_ids

7. Tag Classification
   └─> AI analysis of tags → returns wp_ids

8. Database Storage
   └─> Save to t_news table with status=0 (pending)

9. Mark Email as Read
   └─> Mark in IMAP as \Seen
```

### WordPress Posting Flow

```
1. Select news article (status = 0)

2. Provide WordPress credentials
   ├─> Username
   └─> Password (app-specific)

3. Update news status to 1 (syncing)

4. Upload image to WordPress Media Library
   └─> Get WordPress media ID

5. Create post via REST API with:
   ├─> Title
   ├─> Content (HTML)
   ├─> Excerpt (meta description)
   ├─> Featured media ID
   ├─> Categories
   └─> Tags

6. Update status to 2 (synced)

7. Return WordPress post ID
```

---

## Configuration Files

### .env Example

```env
APP_NAME="Aero Morning News Extractor"
APP_DEBUG=true
APP_KEY=base64:xxx...xxx=
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=extract_news
DB_USERNAME=root
DB_PASSWORD=

# IMAP (Your Email)
IMAP_HOST=imap.gmail.com
IMAP_PORT=993
IMAP_USERNAME=your-email@gmail.com
IMAP_PASSWORD=your-app-password
IMAP_ENCRYPTION=ssl

# OpenAI
OPENAI_API_KEY=sk-xxx...xxx
OPENAI_MODEL=gpt-4-turbo-preview

# WordPress
WORDPRESS_FR_URL=https://preprod.aeromorning.com
WORDPRESS_EN_URL=https://preprod.aeromorning.com/en

# Image Processing
IMAGE_WIDTH=700
IMAGE_HEIGHT=400
IMAGE_MAX_SIZE=1000000
IMAGE_BACKGROUND_COLOR=#005A8C
IMAGE_QUALITY=85
```

---

## Key Features

### 1. Dual-Language Support
- Automatically detects French and English content
- Creates separate news records for each language
- Maintains language-specific categories and tags

### 2. SEO Optimization
- Titles strictly limited to 53 characters
- Meta descriptions 106-141 characters
- Focus keyphrases 2-5 words
- Clean HTML without formatting artifacts
- WordPress/Yoast compatible

### 3. Image Processing
- Automatic download from attachments or embedded URLs
- Converts to JPG format
- Resizes to 700x400 with centered image
- Adds brand background color (#005A8C)
- Optimizes file size to <1MB
- Unique naming scheme (slug-based)

### 4. AI-Powered Content Analysis
- Uses OpenAI GPT-4 Turbo
- Extracts news titles with SEO optimization
- Cleans HTML content for WordPress
- Generates engaging meta descriptions
- Identifies key phrases for SEO
- Classifies into categories and tags

### 5. Status Tracking
- Pending (0): Awaiting review
- Syncing (1): Being posted to WordPress
- Synced (2): Successfully published

### 6. Deduplication
- Uses email Message-ID for uniqueness
- Prevents duplicate processing

### 7. Automation Ready
- Console commands for Laravel Scheduler
- Windows Task Scheduler integration
- Cron job support

---

## Console Commands

### Process Emails
```bash
php artisan emails:process
```
Usage: Schedule via Task Scheduler or Laravel Scheduler to run every 15 minutes

### Sync WordPress
```bash
php artisan wordpress:sync
```
Usage: Schedule to run daily at 2:00 AM

---

## Dependencies

### PHP Packages
```json
{
  "laravel/framework": "^10.0",
  "guzzlehttp/guzzle": "^7.2",
  "openai-php/client": "^0.8",
  "php-imap/php-imap": "^5.1",
  "intervention/image": "^3.0"
}
```

### External Services
- **IMAP Server**: Gmail, Office 365, custom mail server
- **OpenAI API**: GPT-4 Turbo model
- **WordPress REST API**: For category/tag sync and posting
- **MySQL Database**: For local data storage

---

## Installation Summary

```bash
# 1. Clone/navigate to folder
cd c:\xampp8\htdocs\extract_news

# 2. Install dependencies
composer install

# 3. Setup environment
copy .env.example .env
# Edit .env with your credentials

# 4. Generate app key
php artisan key:generate

# 5. Create database
mysql -u root
> CREATE DATABASE extract_news CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# 6. Run migrations
php artisan migrate

# 7. Create directories
mkdir storage/app/attachments
mkdir storage/app/public/images
mkdir storage/app/temp

# 8. Start server (for testing)
php artisan serve

# 9. Test endpoint
curl http://localhost:8000/api/stats
```

---

## Sample Workflow

### Day 1: Setup
```bash
# Install and configure
php artisan migrate
php artisan wordpress:sync
```

### Day 2: Send Test Email
- Send aviation news email to IMAP account
- Image attached or embedded

### Day 3: Process Email
```bash
# Process emails
php artisan emails:process

# Check results
curl http://localhost:8000/api/news/pending
```

### Day 4: Review & Post
```bash
# Preview news
curl http://localhost:8000/api/news/1/preview

# Post to WordPress
curl -X POST http://localhost:8000/api/news/1/post-to-wordpress \
  -d '{"username":"admin","password":"pwd"}'
```

---

## OpenAI Prompts Included

The system includes specialized prompts for:

1. **French Title** - SEO optimized, max 53 chars
2. **French Content** - Clean HTML extraction
3. **French Meta Description** - 106-141 chars
4. **French Focus Keyphrase** - 2-5 words
5. **English Title** - SEO optimized, max 53 chars
6. **English Content** - Clean HTML extraction
7. **English Meta Description** - 106-141 chars
8. **English Focus Keyphrase** - 2-5 words
9. **Category Classification** - Editorial analysis
10. **Tag Classification** - Entity recognition

---

## Documentation Files Included

1. **README.md** - Project overview and general guide
2. **INSTALLATION.md** - Step-by-step installation for XAMPP
3. **API_DOCUMENTATION.md** - Complete API reference
4. **postman_collection.json** - Postman import file
5. **This file** - Project summary

---

## Production Deployment Notes

### Security
- Use strong IMAP/WordPress passwords
- Store .env secrets securely
- Use app-specific passwords for Gmail
- Implement API authentication for production

### Performance
- Database indexing on status and language
- Image optimization included
- Batch processing via bulk endpoints
- Scheduled tasks to avoid peak hours

### Monitoring
- Logs in `storage/logs/laravel.log`
- Database statistics queries available
- API statistics endpoint (`/api/stats`)
- Email processing error tracking

### Maintenance
- Archive old news older than 60 days
- Monitor OpenAI API usage/costs
- Check disk space for images
- Verify IMAP connection regularly

---

## Support & Troubleshooting

Refer to:
- **INSTALLATION.md** - Installation issues
- **API_DOCUMENTATION.md** - API questions
- **README.md** - General information
- **storage/logs/laravel.log** - Error logs

---

## Next Steps

1. **Configure .env** with your credentials
2. **Run migrations** to create database
3. **Sync WordPress** categories and tags
4. **Send test email** to IMAP account
5. **Process emails** via API or command
6. **Test endpoints** with Postman collection
7. **Setup automation** via Task Scheduler
8. **Monitor** processing in logs

---

## License & Support

This project is proprietary. All rights reserved.

For issues or questions, check the documentation files or review code comments in the service classes.

---

**Project Built**: January 2024
**Framework**: Laravel 10
**Database**: MySQL 5.7+
**PHP**: 8.1+
