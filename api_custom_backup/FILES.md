# Complete File Listing & Purpose

## Root Configuration Files

### .env.example
Template environment file with all required configuration variables. Copy to `.env` and fill in credentials.

### composer.json
PHP package manager configuration. Specifies all Laravel, OpenAI, IMAP, and image processing dependencies.

### postman_collection.json
Postman API collection for easy testing of all endpoints. Import into Postman application.

---

## Documentation Files

### QUICKSTART.md ⭐ START HERE
10-minute quick start guide. Fastest way to get the API running.

### INSTALLATION.md
Detailed step-by-step installation guide for XAMPP deployment with troubleshooting.

### README.md
Complete project documentation including features, architecture, and setup instructions.

### API_DOCUMENTATION.md
Complete reference for all API endpoints with examples and error codes.

### PROJECT_SUMMARY.md
Comprehensive project overview, architecture, and workflow documentation.

### This File (FILES.md)
Guide to all files in the project and their purposes.

---

## Application Code

### app/Models/

#### CategoryFr.php
Eloquent model for French WordPress categories table (`t_categories_fr`).
- Methods: Standard Eloquent CRUD operations
- Relationship: Linked to `wp_id` from WordPress

#### CategoryEn.php
Eloquent model for English WordPress categories table (`t_categories_en`).
- Methods: Standard Eloquent CRUD operations
- Relationship: Linked to `wp_id` from WordPress

#### TagFr.php
Eloquent model for French WordPress tags table (`t_tags_fr`).
- Methods: Standard Eloquent CRUD operations
- Relationship: Linked to `wp_id` from WordPress

#### TagEn.php
Eloquent model for English WordPress tags table (`t_tags_en`).
- Methods: Standard Eloquent CRUD operations
- Relationship: Linked to `wp_id` from WordPress

#### News.php
Main Eloquent model for news articles table (`t_news`).
- Methods:
  - `isPending()`, `isSyncing()`, `isSynced()` - Status checkers
  - `getCategoriesArray()` - Parse comma-separated categories to array
  - `getTagsArray()` - Parse comma-separated tags to array
- Constants: STATUS_PENDING, STATUS_SYNCING, STATUS_SYNCED
- Attributes: All news article fields including lang, title, content, image_url, etc.

---

### app/Services/

#### WordPressService.php
Handles synchronization with WordPress REST API for categories and tags.
- Key Methods:
  - `syncCategoriesFr()` - Fetch French categories from WordPress
  - `syncCategoriesEn()` - Fetch English categories from WordPress
  - `syncTagsFr()` - Fetch French tags from WordPress
  - `syncTagsEn()` - Fetch English tags from WordPress
  - `getCategoriesForClassification($lang)` - Get categories for AI analysis
  - `getTagsForClassification($lang)` - Get tags for AI analysis
- Usage: Fetches from WordPress REST API and stores in local database

#### EmailService.php
Handles IMAP email retrieval and processing.
- Key Methods:
  - `getUnreadEmails()` - Connect to IMAP and fetch unread emails
  - `extractEmailContent($mail)` - Extract subject, body, attachments from email
  - `hasAttachments($mail)` - Check for image attachments
  - `extractImageUrlFromHtml($html)` - Find image URLs in HTML content
  - `markAsRead($mailId)` - Mark email as read after processing
- Features: Handles both HTML and plain text emails

#### ImageService.php
Processes and optimizes images.
- Key Methods:
  - `downloadAndOptimizeImage($url, $title)` - Download from URL and optimize
  - `processAttachmentImage($path, $title)` - Process email attachment
  - `isValidImageUrl($url)` - Validate image URL
- Features:
  - Resizes to 700x400 pixels
  - Adds brand background color (#005A8C)
  - Converts to JPG format
  - Optimizes to <1MB
  - Names files with slug-based naming

#### OpenAIService.php
Integrates with OpenAI GPT-4 API for content analysis.
- French Methods:
  - `generateFrenchTitle($content)` - SEO title (max 53 chars)
  - `generateFrenchContent($content, $title)` - Clean HTML content
  - `generateFrenchMetaDescription($content)` - Meta description (106-141 chars)
  - `generateFrenchKeyphrase($content)` - Focus keyphrase (2-5 words)
- English Methods: Same as above but for English content
- Classification Methods:
  - `classifyCategories($content, $categories, $lang)` - AI category selection
  - `classifyTags($content, $tags, $lang)` - AI tag selection
- Helper Methods:
  - `callOpenAI($prompt, $maxTokens)` - Makes API call to OpenAI

#### WordPressPostingService.php
Handles posting news to WordPress.
- Key Methods:
  - `postToWordPress($news, $username, $password)` - Publish article
  - `uploadImage($path, $username, $password)` - Upload featured image
  - `updateWordPressPost($postId, $news, ...)` - Update existing post
  - `deleteWordPressPost($postId, $username, $password)` - Delete post
- Features: Uses WordPress REST API with basic authentication

---

### app/Http/Controllers/

#### NewsController.php
Main controller orchestrating the complete workflow.
- Endpoints:
  - `syncWordPressData()` - POST /api/sync-wordpress
  - `processEmails()` - POST /api/process-emails
  - `getPendingNews()` - GET /api/news/pending
  - `getNewsById($id)` - GET /api/news/{id}
  - `updateNewsStatus($id, $status)` - PATCH /api/news/{id}/status/{status}
- Helper Methods:
  - `processSingleEmail($mail)` - Process one email
  - `processFrenchNews()` - Handle French content
  - `processEnglishNews()` - Handle English content
  - `detectLanguage($content)` - Detect FR/EN

#### WordPressPostingController.php
Handles WordPress publishing operations.
- Endpoints:
  - `postNews($request, $id)` - POST /api/news/{id}/post-to-wordpress
  - `bulkPostNews($request)` - POST /api/news/bulk-post-to-wordpress
  - `previewNews($id)` - GET /api/news/{id}/preview
  - `newsStats()` - GET /api/stats
- Features: Shows statistics, validates credentials, handles bulk operations

---

### app/Console/Commands/

#### ProcessEmailsCommand.php
Console command for processing unread emails.
- Command: `php artisan emails:process`
- Usage: Run via Task Scheduler or Laravel Scheduler
- Calls: NewsController::processEmails()

#### SyncWordPressCommand.php
Console command for syncing WordPress data.
- Command: `php artisan wordpress:sync`
- Usage: Run daily to keep categories/tags updated
- Calls: NewsController::syncWordPressData()

---

### routes/

#### api.php
Defines all API routes and endpoints.
- Group: `/api` prefix
- Routes:
  - POST `/sync-wordpress` → NewsController::syncWordPressData
  - POST `/process-emails` → NewsController::processEmails
  - GET `/news/pending` → NewsController::getPendingNews
  - GET `/news/{id}` → NewsController::getNewsById
  - PATCH `/news/{id}/status/{status}` → NewsController::updateNewsStatus
  - POST `/news/{id}/post-to-wordpress` → WordPressPostingController::postNews
  - POST `/news/bulk-post-to-wordpress` → WordPressPostingController::bulkPostNews
  - GET `/news/{id}/preview` → WordPressPostingController::previewNews
  - GET `/stats` → WordPressPostingController::newsStats

---

### config/

#### services.php
Service configuration file.
- Contains: WordPress URLs, IMAP settings, OpenAI settings, image settings
- Usage: Centralized configuration accessed via `config('services.xxx')`
- Environment Variables: All values loaded from .env

---

### database/migrations/

#### 2024_01_01_000001_create_categories_fr_table.php
Migration for French categories table.
- Table: `t_categories_fr`
- Columns: id, wp_id (unique), categ_name, timestamps

#### 2024_01_01_000002_create_categories_en_table.php
Migration for English categories table.
- Table: `t_categories_en`
- Columns: id, wp_id (unique), categ_name, timestamps

#### 2024_01_01_000003_create_tags_fr_table.php
Migration for French tags table.
- Table: `t_tags_fr`
- Columns: id, wp_id (unique), tag_name, timestamps

#### 2024_01_01_000004_create_tags_en_table.php
Migration for English tags table.
- Table: `t_tags_en`
- Columns: id, wp_id (unique), tag_name, timestamps

#### 2024_01_01_000005_create_news_table.php
Migration for main news table.
- Table: `t_news`
- Columns: 
  - id, lang, title, content
  - metadescription, focuskeyphrase
  - categories, tags, image_url
  - status, email_message_id
  - timestamps
- Indexes: status+lang, created_at

---

## Directory Structure (Auto-created)

### storage/app/
- `attachments/` - Temporary email attachments
- `public/images/` - Optimized images for WordPress
- `temp/` - Temporary files during processing

### storage/logs/
- `laravel.log` - Application logs

### bootstrap/
- `cache/` - Application cache files

---

## File Summary Table

| File | Type | Purpose |
|------|------|---------|
| .env.example | Config | Environment template |
| composer.json | Config | PHP dependencies |
| postman_collection.json | API | Postman collection |
| QUICKSTART.md | Doc | Quick start guide |
| INSTALLATION.md | Doc | Installation guide |
| README.md | Doc | Project overview |
| API_DOCUMENTATION.md | Doc | API reference |
| PROJECT_SUMMARY.md | Doc | Project summary |
| FILES.md | Doc | This file |
| CategoryFr.php | Code | French categories model |
| CategoryEn.php | Code | English categories model |
| TagFr.php | Code | French tags model |
| TagEn.php | Code | English tags model |
| News.php | Code | News articles model |
| WordPressService.php | Code | WP sync service |
| EmailService.php | Code | Email/IMAP service |
| ImageService.php | Code | Image processing service |
| OpenAIService.php | Code | AI content generation |
| WordPressPostingService.php | Code | WP posting service |
| NewsController.php | Code | Main API controller |
| WordPressPostingController.php | Code | Posting controller |
| ProcessEmailsCommand.php | Code | Email processing command |
| SyncWordPressCommand.php | Code | WP sync command |
| api.php | Code | Route definitions |
| services.php | Code | Service config |
| 5x Migration files | Code | Database schema |

---

## Getting Started

1. **Read**: [QUICKSTART.md](QUICKSTART.md) - 10 minutes
2. **Install**: Follow installation section
3. **Configure**: Edit .env file
4. **Test**: Use Postman collection or curl
5. **Automate**: Setup Task Scheduler

---

## Key File Dependencies

```
api.php (routes)
  ↓
NewsController + WordPressPostingController
  ↓
Services (WordPressService, EmailService, ImageService, OpenAIService)
  ↓
Models (News, CategoryFr, CategoryEn, TagFr, TagEn)
  ↓
Database (Migrations)
```

---

## For Developers

- **Adding features**: Edit files in `app/Http/Controllers/`
- **Modifying logic**: Edit files in `app/Services/`
- **Adding endpoints**: Update `routes/api.php`
- **Database changes**: Create new migration in `database/migrations/`
- **Error handling**: Check `storage/logs/laravel.log`

---

**Total Files**: 35+ (including this documentation)
**Total Lines of Code**: 3000+
**Languages**: PHP, JSON, Markdown
