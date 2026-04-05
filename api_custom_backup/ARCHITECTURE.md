# System Architecture & Data Flow

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         EXTERNAL SERVICES                        │
├─────────────┬──────────────────────┬────────────────────────────┤
│  IMAP EMAIL │   OPENAI API         │   WORDPRESS REST API       │
│  (Gmail)    │   (GPT-4 Turbo)      │   (Categories/Tags/Posts)  │
└────────┬────┴──────────────────┬───┴────────────────┬───────────┘
         │                       │                    │
         │                       │                    │
┌────────▼──────────────────────▼────────────────────▼─────────────┐
│                    LARAVEL API (Core)                             │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              API Routes (/api/...)                       │   │
│  ├──────────────────────────────────────────────────────────┤   │
│  │ Controllers (NewsController, WordPressPostingController) │   │
│  ├──────────────────────────────────────────────────────────┤   │
│  │ Services:                                                │   │
│  │  • EmailService (IMAP)                                   │   │
│  │  • WordPressService (Sync)                               │   │
│  │  • ImageService (Processing)                             │   │
│  │  • OpenAIService (Content Generation)                    │   │
│  │  • WordPressPostingService (Publishing)                  │   │
│  ├──────────────────────────────────────────────────────────┤   │
│  │ Models (Eloquent ORM)                                    │   │
│  │  • News, CategoryFr, CategoryEn, TagFr, TagEn           │   │
│  └──────────────────────────────────────────────────────────┘   │
└────────┬─────────────────────────────────────────────────┬────────┘
         │                                                 │
         │                                                 │
      ┌──▼────────────────────┐            ┌─────────────▼──────┐
      │   LOCAL DATABASE      │            │  STORAGE DIRECTORY │
      │   (MySQL - t_news     │            │  /storage/app/     │
      │    t_categories_fr/en │            │  /public/images/   │
      │    t_tags_fr/en)      │            │  Optimized images  │
      └───────────────────────┘            └────────────────────┘
```

---

## Email Processing Pipeline

```
START: Unread Email in IMAP
  │
  ▼
┌─────────────────────────────────┐
│ EmailService                     │
│ • Connect via IMAP              │
│ • Fetch unread emails           │
└────────────────┬────────────────┘
                 │
                 ▼
         ┌──────────────────────────────┐
         │ Extract Email Content         │
         │ • Subject                     │
         │ • HTML/Text body              │
         │ • Attachments                 │
         │ • Message ID (dedup)          │
         └──────────────┬────────────────┘
                        │
                        ▼
         ┌──────────────────────────────────┐
         │ Check for Duplicates             │
         │ WHERE email_message_id EXISTS?   │
         └──────────────┬─────────────────┬─┘
                        │ YES              │ NO
                        │                  │
                 [SKIP]  │                  │ [CONTINUE]
                        │                  │
                        └──────────┬───────┘
                                   │
                                   ▼
                    ┌──────────────────────────────┐
                    │ ImageService                 │
                    │ • Extract/Download image     │
                    │ • Convert to JPG             │
                    │ • Resize to 700x400          │
                    │ • Add background color       │
                    │ • Optimize <1MB              │
                    │ • Save to storage            │
                    └──────────────┬────────────────┘
                                   │
                                   ▼
                    ┌──────────────────────────────┐
                    │ Language Detection           │
                    │ Detect: FR, EN, or both      │
                    └──────────────┬────────────────┘
                                   │
        ┌──────────────────────────┼──────────────────────────┐
        │                          │                          │
        ▼ (if FR detected)         ▼ (if EN detected)         ▼ (both)
   ┌─────────────┐             ┌─────────────┐              │
   │ Process FR  │             │ Process EN  │              │
   └─────────────┘             └─────────────┘              │
        │                           │                       │
        │                           │                       │
        └───────────────┬───────────┘                       │
                        │ (for each language)              │
                        ▼
         ┌────────────────────────────────────┐
         │ OpenAI Service - Content Analysis   │
         │                                     │
         │ 1. Generate French/English Title    │
         │    (max 53 chars, SEO optimized)   │
         │                                     │
         │ 2. Extract HTML Content             │
         │    (clean, WordPress-ready)         │
         │                                     │
         │ 3. Generate Meta Description        │
         │    (106-141 chars)                  │
         │                                     │
         │ 4. Generate Focus Keyphrase         │
         │    (2-5 words, with entities)       │
         └────────────────┬─────────────────────┘
                          │
                          ▼
         ┌────────────────────────────────────┐
         │ WordPress Category Selection        │
         │ AI Classification → wp_ids          │
         └────────────────┬─────────────────────┘
                          │
                          ▼
         ┌────────────────────────────────────┐
         │ WordPress Tag Selection             │
         │ AI Classification → wp_ids          │
         └────────────────┬─────────────────────┘
                          │
                          ▼
         ┌────────────────────────────────────┐
         │ Save to Database (t_news)           │
         │ • lang: FR or EN                    │
         │ • title, content                    │
         │ • metadescription, focuskeyphrase  │
         │ • categories, tags                  │
         │ • image_url                         │
         │ • status: 0 (PENDING)              │
         │ • email_message_id                  │
         └────────────────┬─────────────────────┘
                          │
                          ▼
         ┌────────────────────────────────────┐
         │ Mark Email as Read                  │
         │ IMAP: \Seen flag                    │
         └────────────────────────────────────┘

COMPLETE: News ready for WordPress posting

```

---

## WordPress Posting Pipeline

```
START: Pending News (status = 0)
  │
  ▼
┌────────────────────────────────┐
│ User provides credentials:      │
│ • WordPress username            │
│ • WordPress app-specific pwd    │
└────────────────┬────────────────┘
                 │
                 ▼
         ┌──────────────────┐
         │ Validate Input   │
         │ Valid?           │
         └────┬─────────┬───┘
              │ NO      │ YES
         [ERROR]        │
                        ▼
         ┌──────────────────────────────┐
         │ Update Status to 1 (SYNCING) │
         └──────────────┬───────────────┘
                        │
                        ▼
         ┌──────────────────────────────────┐
         │ WordPressPostingService          │
         │ • Take News object               │
         │ • Prepare post data:             │
         │   - title                        │
         │   - content (HTML)               │
         │   - excerpt (meta description)   │
         │   - categories (wp_ids)          │
         │   - tags (wp_ids)                │
         │   - featured_media (image ID)    │
         └──────────────┬───────────────────┘
                        │
                        ▼
         ┌──────────────────────────────────┐
         │ Check if Image Needed            │
         │ image_url exists?                │
         └────┬───────────────────────┬────┘
              │ YES                   │ NO
              │                       │
              ▼                       │
      ┌────────────────┐              │
      │ Upload Image   │              │
      │ to WordPress   │              │
      │ Media Library  │              │
      └────┬───────────┘              │
           │ (get media ID)           │
           │                          │
           └──────────┬───────────────┘
                      │
                      ▼
         ┌──────────────────────────────────┐
         │ POST to WordPress REST API       │
         │ /wp-json/wp/v2/posts             │
         │ + Basic Auth (username:pwd)      │
         │                                  │
         │ Status: 200 OK?                  │
         └────┬───────────────────────┬────┘
              │ NO                    │ YES
              │                       │
              ▼                       ▼
        [ERROR:                 ┌──────────────┐
         Revert to 0]           │ Get post ID  │
                                │ (wp_post_id) │
                                └──┬───────────┘
                                   │
                                   ▼
                        ┌──────────────────────┐
                        │ Update Metadata      │
                        │ (Yoast/RankMath):    │
                        │ • focus_keyphrase    │
                        │ • metadescription    │
                        └──────────────────────┘
                                   │
                                   ▼
                        ┌──────────────────────┐
                        │ Update Status to 2   │
                        │ (SYNCED)             │
                        └──────────────────────┘

COMPLETE: News published to WordPress
SUCCESS: Return wp_post_id

```

---

## Database Schema Diagram

```
t_categories_fr                t_tags_fr
┌──────────────────┐           ┌──────────────────┐
│ id (PK)          │           │ id (PK)          │
│ wp_id (unique)   │           │ wp_id (unique)   │
│ categ_name       │           │ tag_name         │
│ created_at       │           │ created_at       │
│ updated_at       │           │ updated_at       │
└──────────────────┘           └──────────────────┘

t_categories_en               t_tags_en
┌──────────────────┐           ┌──────────────────┐
│ id (PK)          │           │ id (PK)          │
│ wp_id (unique)   │           │ wp_id (unique)   │
│ categ_name       │           │ tag_name         │
│ created_at       │           │ created_at       │
│ updated_at       │           │ updated_at       │
└──────────────────┘           └──────────────────┘

                    t_news
        ┌───────────────────────────────────────┐
        │ id (PK, auto_increment)               │
        │ lang (FR/EN) [INDEX]                  │
        │ title (text)                          │
        │ content (longtext)                    │
        │ metadescription (text)                │
        │ focuskeyphrase (text)                 │
        │ categories (text - comma sep wp_ids)  │
        │ tags (text - comma sep wp_ids)        │
        │ image_url (text)                      │
        │ status (0/1/2) [INDEX]                │
        │ email_message_id (unique)             │
        │ created_at [INDEX]                    │
        │ updated_at                            │
        └───────────────────────────────────────┘
```

---

## Request/Response Flow

```
CLIENT REQUEST
  │
  ▼
┌─────────────────────────────────────┐
│ /api/news/pending (GET)             │
│ /api/process-emails (POST)          │
│ /api/news/{id}/post-to-wordpress    │
│ etc...                              │
└────────────────┬────────────────────┘
                 │
                 ▼
         ┌───────────────────────┐
         │ Router (routes/api.php)│
         │ Match URL pattern      │
         └────────────┬───────────┘
                      │
                      ▼
         ┌─────────────────────────────────┐
         │ Controller                      │
         │ • NewsController                │
         │ • WordPressPostingController    │
         └────────────────┬────────────────┘
                          │
                          ▼
         ┌──────────────────────────────┐
         │ Service Layer                │
         │ • EmailService               │
         │ • ImageService               │
         │ • OpenAIService              │
         │ • WordPressService           │
         │ • WordPressPostingService    │
         └────────────────┬─────────────┘
                          │
                          ▼
         ┌──────────────────────────────┐
         │ Database / External APIs     │
         │ • Eloquent (MySQL)           │
         │ • IMAP                       │
         │ • OpenAI                     │
         │ • WordPress REST API         │
         └────────────────┬─────────────┘
                          │
                          ▼
         ┌──────────────────────────────┐
         │ Response                     │
         │ JSON with status & data      │
         └──────────────┬────────────────┘
                        │
                        ▼
                    CLIENT

```

---

## Service Interactions

```
NewsController
├─ Calls: WordPressService
│   └─ Calls: HTTP Client (Guzzle)
│       └─ Calls: WordPress REST API
│
├─ Calls: EmailService
│   └─ Calls: IMAP Client
│       └─ Calls: IMAP Server (Gmail)
│
├─ Calls: ImageService
│   ├─ Calls: HTTP Client (download)
│   └─ Calls: Intervention Image (processing)
│
└─ Calls: OpenAIService
    └─ Calls: OpenAI API Client
        └─ Calls: OpenAI APIs

WordPressPostingController
├─ Calls: WordPressPostingService
│   └─ Calls: HTTP Client (Guzzle)
│       └─ Calls: WordPress REST API
│
└─ Calls: News Model (Eloquent)
    └─ Calls: MySQL Database

All Models
└─ Call: Database via Eloquent ORM
    └─ MySQL Database (t_news, t_categories_*, t_tags_*)

```

---

## Data Flow Example: Complete Workflow

```
1. USER ACTION
   └─ curl -X POST http://localhost:8000/api/process-emails

2. ROUTE MATCHING
   └─ NewsController::processEmails()

3. EMAIL RETRIEVAL
   └─ EmailService::getUnreadEmails()
       └─ IMAP Server (Gmail)
           └─ Returns: [IncomingMail, IncomingMail, ...]

4. FOR EACH EMAIL
   ├─ Extract content
   │  └─ EmailService::extractEmailContent()
   │      └─ Returns: [subject, body, attachments]
   │
   ├─ Process image
   │  └─ ImageService::downloadAndOptimizeImage()
   │      └─ /storage/images/news-slug-xyz.jpg
   │
   ├─ Detect language
   │  └─ Detect: FR
   │
   ├─ PROCESS FRENCH
   │  ├─ OpenAI: Generate title
   │  │  └─ Returns: "Airbus A350 livraison"
   │  ├─ OpenAI: Generate content
   │  │  └─ Returns: "<p>...</p>"
   │  ├─ OpenAI: Generate meta description
   │  │  └─ Returns: "Découvrez..."
   │  ├─ OpenAI: Generate keyphrase
   │  │  └─ Returns: "Airbus A350"
   │  ├─ WordPressService: Get categories
   │  │  └─ Database: SELECT FROM t_categories_fr
   │  ├─ OpenAI: Classify categories
   │  │  └─ Returns: "1,5,8"
   │  ├─ WordPressService: Get tags
   │  │  └─ Database: SELECT FROM t_tags_fr
   │  ├─ OpenAI: Classify tags
   │  │  └─ Returns: "3,7"
   │  └─ Save to database
   │     └─ News::create([...])
   │         └─ INSERT INTO t_news

5. RESPONSE
   └─ JSON: {"success": true, "processed": 1, "failed": 0}

```

---

## Technology Stack

```
┌─────────────────────────────────────┐
│         CLIENT LAYER                │
│ • REST API (HTTP)                   │
│ • JSON                              │
│ • Postman / cURL / Others           │
└─────────────────────────────────────┘
           ▲
           │
┌─────────────────────────────────────┐
│      APPLICATION LAYER              │
│ • Laravel 10                        │
│ • PHP 8.1+                          │
│ • Eloquent ORM                      │
│ • Middleware & Routing              │
└─────────────────────────────────────┘
           ▲
           │
┌─────────────────────────────────────┐
│      SERVICE LAYER                  │
│ • EmailService (IMAP)               │
│ • ImageService (GD/Intervention)    │
│ • OpenAIService (AI)                │
│ • WordPressService (API Sync)       │
│ • WordPressPostingService (API Post)│
└─────────────────────────────────────┘
           ▲
           │
┌─────────────────────────────────────┐
│      PERSISTENCE LAYER              │
│ • MySQL Database                    │
│ • File Storage (/storage)           │
└─────────────────────────────────────┘
           ▲
           │
┌─────────────────────────────────────┐
│      EXTERNAL SERVICES              │
│ • Gmail IMAP Server                 │
│ • OpenAI API                        │
│ • WordPress REST API                │
└─────────────────────────────────────┘
```

---

## Error Handling & Logging

```
Exception Occurs
  │
  ▼
┌──────────────────────────────┐
│ Log to storage/logs/laravel.log
│ • ERROR: [class] [message]
│ • Stack trace
│ • Request details
└──────────────────────────────┘
  │
  ▼
┌──────────────────────────────┐
│ Return JSON Error Response
│ {
│   "success": false,
│   "message": "Error description"
│ }
└──────────────────────────────┘

```

---

## Status Flow

```
[NO DATA]
  │
  ▼
[PENDING (0)]  ← News created from email
  │
  ├─ [Manual Review/Approval]
  │
  ▼
[SYNCING (1)]  ← User submits to WordPress
  │
  ├─ [POST to WordPress API]
  │
  ├─ Success? YES
  │  │
  │  ▼
  │ [SYNCED (2)]  ← Completed
  │
  └─ Success? NO
     │
     ▼
    [PENDING (0)]  ← Try again later
```

---

## Performance Considerations

```
OPTIMIZATION POINTS:

1. Database Queries
   • Indexed on: status, lang, created_at
   • Pagination on /api/news/pending
   • Use select() to limit columns

2. Image Processing
   • Resize & compress in parallel
   • Store in /storage/app/public/images/
   • Use CDN in production

3. API Calls
   • Batch categorization in OpenAI
   • Cache WordPress categories/tags
   • Rate limit OpenAI to avoid costs

4. IMAP Processing
   • Process emails in background jobs
   • Batch process multiple emails
   • Use Laravel Scheduler

5. WordPress Posting
   • Use bulk endpoints
   • Batch 10+ articles per request
   • Implement async posting
```

---

This architecture ensures:
✓ Scalability (service-based design)
✓ Maintainability (clear separation of concerns)
✓ Reliability (error handling & logging)
✓ Flexibility (easy to modify/extend)
✓ Performance (optimized queries & caching)
