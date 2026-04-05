# Installation Guide - XAMPP Deployment

## Quick Start

### 1. Prerequisites Check
```bash
# Check PHP version (must be 8.1+)
php -v

# Check Composer installed
composer --version

# Check MySQL running
mysql -u root
```

### 2. Install Dependencies
```bash
cd c:\xampp8\htdocs\extract_news
composer install
```

### 3. Configure Environment
```bash
copy .env.example .env
# Edit .env with your settings (see section below)
```

### 4. Generate Laravel Key
```bash
php artisan key:generate
```

### 5. Run Migrations
```bash
php artisan migrate
```

### 6. Create Required Directories
```bash
mkdir storage/app/attachments
mkdir storage/app/public/images
mkdir storage/app/temp
# On Windows (PowerShell):
# New-Item -ItemType Directory -Force -Path storage\app\attachments
# New-Item -ItemType Directory -Force -Path storage\app\public\images
# New-Item -ItemType Directory -Force -Path storage\app\temp
```

### 7. Set Permissions
```bash
# Linux/Mac
chmod -R 775 storage/
chmod -R 775 bootstrap/cache/

# Windows (PowerShell as Admin)
# icacls "storage" /grant:r "IUSR:(OI)(CI)F" /T
# icacls "bootstrap\cache" /grant:r "IUSR:(OI)(CI)F" /T
```

### 8. Test the API
```bash
php artisan serve
# then visit http://localhost:8000/api/stats
```

## Environment Configuration

### Create `.env` file:

Essential settings:
```env
APP_NAME="Aero Morning News Extractor"
APP_ENV=local
APP_DEBUG=true
APP_KEY=base64:xxxxx...xxxxx=
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=extract_news
DB_USERNAME=root
DB_PASSWORD=

# IMAP Configuration (Your Email Server)
IMAP_HOST=imap.gmail.com
IMAP_PORT=993
IMAP_USERNAME=your-email@gmail.com
IMAP_PASSWORD=your-app-specific-password
IMAP_ENCRYPTION=ssl

# OpenAI API (Required for content generation)
OPENAI_API_KEY=sk-xxxxxxxxxxxxxxxxxxxxxxxx
OPENAI_MODEL=gpt-4-turbo-preview

# WordPress URLs (Where data will be posted)
WORDPRESS_FR_URL=https://preprod.aeromorning.com
WORDPRESS_EN_URL=https://preprod.aeromorning.com/en

# Optional: Image Processing Settings
IMAGE_WIDTH=700
IMAGE_HEIGHT=400
IMAGE_MAX_SIZE=1000000
IMAGE_BACKGROUND_COLOR=#005A8C
IMAGE_QUALITY=85
```

### Getting Required Credentials

**Gmail IMAP:**
1. Enable 2FA on your Gmail account
2. Create App Password: https://myaccount.google.com/apppasswords
3. Use 16-character password in `IMAP_PASSWORD`

**OpenAI API Key:**
1. Visit https://platform.openai.com/account/api-keys
2. Create new secret key
3. Copy to `OPENAI_API_KEY`

## Database Setup

### Create Database
```bash
mysql -u root
> CREATE DATABASE extract_news CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
> EXIT;
```

### Run Migrations
```bash
php artisan migrate
```

### Verify Tables Created
```sql
USE extract_news;
SHOW TABLES;
```

Expected tables:
- `t_categories_fr`
- `t_categories_en`
- `t_tags_fr`
- `t_tags_en`
- `t_news`
- `migrations`

## Initial Setup Commands

### 1. Sync WordPress Data
```bash
php artisan wordpress:sync
```

This fetches all categories and tags from your WordPress installation.

### 2. Test Email Processing
```bash
php artisan emails:process
```

This fetches unread emails from your IMAP inbox and processes them.

## Testing the API

### Using cURL

**Test API is running:**
```bash
curl http://localhost:8000/api/stats
```

**Get pending news:**
```bash
curl http://localhost:8000/api/news/pending
```

**Process emails:**
```bash
curl -X POST http://localhost:8000/api/process-emails
```

**Sync WordPress:**
```bash
curl -X POST http://localhost:8000/api/sync-wordpress
```

### Using Postman

1. Import these endpoints:
   - POST: `http://localhost:8000/api/sync-wordpress`
   - POST: `http://localhost:8000/api/process-emails`
   - GET: `http://localhost:8000/api/news/pending`
   - GET: `http://localhost:8000/api/stats`

2. Set Content-Type: `application/json`

## Automation Setup

### Option 1: Windows Task Scheduler

Create batch file `C:\update-news.bat`:
```batch
@echo off
cd C:\xampp8\htdocs\extract_news
php artisan emails:process
php artisan wordpress:sync
```

Schedule in Task Scheduler:
1. Open Task Scheduler
2. Create Basic Task: "Process Aero Morning News"
3. Trigger: Every 15 minutes
4. Action: Run `C:\update-news.bat`

### Option 2: Laravel Artisan Scheduler

Edit `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('emails:process')->everyFifteenMinutes();
    $schedule->command('wordpress:sync')->daily()->at('02:00');
}
```

Then run (keep running in background):
```bash
php artisan schedule:work
```

Or on Windows via Task Scheduler:
```batch
php artisan schedule:run
```

## Troubleshooting

### Issue: "SQLSTATE[HY000]: General error: 1030 Got error..."
**Solution:** Check database user has proper permissions:
```sql
GRANT ALL PRIVILEGES ON extract_news.* TO 'root'@'localhost';
FLUSH PRIVILEGES;
```

### Issue: "Allowed memory size exhausted"
**Solution:** Increase PHP memory in `php.ini`:
```ini
memory_limit = 512M
```

### Issue: "OpenAI API rate limit"
**Solution:** Add delay between requests in `.env`:
```env
OPENAI_TIMEOUT=60
```

Modify `app/Services/OpenAIService.php` to add delay between calls.

### Issue: "IMAP: Connection timeout"
**Solution:** Update IMAP settings:
```env
IMAP_HOST=imap.gmail.com
IMAP_PORT=993
IMAP_ENCRYPTION=ssl
```

Test connection:
```php
$imap = imap_open("{imap.gmail.com:993/imap/ssl}INBOX", "email@gmail.com", "password");
if ($imap) echo "Connected!";
else echo "Error: " . imap_last_error();
```

### Issue: "Image processing failed"
**Solution:** Verify GD extension:
```bash
php -m | grep GD
```

If missing, enable in `php.ini`:
```ini
extension=gd
```

## Performance Optimization

### 1. Database Indexing
The migrations include key indexes. For large datasets:
```sql
CREATE INDEX idx_news_status_lang ON t_news(status, lang);
CREATE INDEX idx_news_created ON t_news(created_at);
```

### 2. Archive Old Data
```sql
-- Delete synced news older than 60 days
DELETE FROM t_news 
WHERE status = 2 AND updated_at < DATE_SUB(NOW(), INTERVAL 60 DAY);
```

### 3. Optimize Images
Images are already optimized (JPG, <1MB). For additional compression:
```env
IMAGE_QUALITY=70
```

## Monitoring

### Check Logs
```bash
tail -f storage/logs/laravel.log
```

### Monitor Database
```sql
SELECT 
    lang, 
    status, 
    COUNT(*) as count,
    NOW() as last_check
FROM t_news 
GROUP BY lang, status;
```

### API Health Check
```bash
php artisan tinker
>>> DB::connection()->getPdo();
>>> response()->json(['status' => 'ok'])
```

## Security Notes

1. **Keep .env secrets**, don't commit to version control
2. **Use strong IMAP password** or app-specific passwords
3. **Restrict API access** behind authentication if exposed
4. **Keep Laravel updated**: `composer update`
5. **Validate all inputs** in custom modifications

## Next Steps

1. Configure WordPress REST API username/password for posting
2. Set up automated scheduling
3. Monitor first email processing for any issues
4. Configure WordPress theme to display custom SEO fields
5. Set up notification system for errors

For more details, see `README.md` in the project root.
