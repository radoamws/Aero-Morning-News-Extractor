# Cheat Sheet - Common Tasks & Quick Reference

## Quick Commands

### Start Development Server
```bash
php artisan serve --host 0.0.0.0 --port 8000
# Access at http://localhost:8000
```

### Test if API is Running
```bash
curl http://localhost:8000/api/stats
```

### Check Logs
```bash
tail -f storage/logs/laravel.log
# or on Windows
type storage\logs\laravel.log
```

---

## Email Processing

### Process Unread Emails Manually
```bash
php artisan emails:process
```

### Sync WordPress Categories & Tags
```bash
php artisan wordpress:sync
```

### Process + Sync (Full workflow)
```bash
php artisan emails:process && php artisan wordpress:sync
```

---

## Database Operations

### Create Database
```sql
CREATE DATABASE extract_news CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Run Migrations
```bash
php artisan migrate
```

### Rollback Migrations
```bash
php artisan migrate:rollback
```

### Refresh Database (WARNING: Deletes all data)
```bash
php artisan migrate:refresh
```

### Check Migration Status
```bash
php artisan migrate:status
```

### View Database via MySQL
```bash
mysql -u root -p extract_news
> SELECT * FROM t_news;
> SELECT COUNT(*) FROM t_news WHERE status = 0;
> SELECT lang, COUNT(*) FROM t_news GROUP BY lang;
```

---

## API Testing

### Get Statistics
```bash
curl http://localhost:8000/api/stats
```

### Get Pending News (Paginated)
```bash
curl "http://localhost:8000/api/news/pending?page=1"
```

### Get Single News
```bash
curl http://localhost:8000/api/news/1
```

### Preview News Before Posting
```bash
curl http://localhost:8000/api/news/1/preview
```

### Post Single News to WordPress
```bash
curl -X POST http://localhost:8000/api/news/1/post-to-wordpress \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}'
```

### Bulk Post Multiple News
```bash
curl -X POST http://localhost:8000/api/news/bulk-post-to-wordpress \
  -H "Content-Type: application/json" \
  -d '{
    "news_ids": [1, 2, 3],
    "username": "admin",
    "password": "password"
  }'
```

### Update News Status
```bash
# Set to synced (2)
curl -X PATCH http://localhost:8000/api/news/1/status/2

# Set to pending (0)
curl -X PATCH http://localhost:8000/api/news/1/status/0
```

---

## Environment Configuration Quick Check

### Verify All Required Settings
```bash
grep -E "^(IMAP_|OPENAI_|WORDPRESS_)" .env
```

### Change Configuration
```bash
# Edit these in .env:
IMAP_HOST=
IMAP_USERNAME=
IMAP_PASSWORD=
OPENAI_API_KEY=
WORDPRESS_FR_URL=
WORDPRESS_EN_URL=
```

### Test IMAP Connection (via PHP)
```php
php -r "
\$imap = imap_open('{imap.gmail.com:993/imap/ssl}INBOX', 'email@gmail.com', 'password');
echo \$imap ? 'Connected!' : 'Error: ' . imap_last_error();
"
```

### Test OpenAI Connection (via PHP)
```php
php -r "
require 'vendor/autoload.php';
\$client = OpenAI::client('YOUR_API_KEY');
echo 'Connected to OpenAI';
"
```

---

## File & Directory Management

### Create Required Directories
```bash
mkdir -p storage/app/attachments
mkdir -p storage/app/public/images
mkdir -p storage/app/temp
```

### Set Permissions (Linux/Mac)
```bash
chmod -R 775 storage/
chmod -R 775 bootstrap/cache/
```

### View Processed Images
```bash
ls -lah storage/app/public/images/
```

### Clear Temp Files
```bash
rm -f storage/app/temp/*
```

---

## Debugging

### Enable Debug Mode
In .env:
```env
APP_DEBUG=true
```

### View Application Log
```bash
tail -100 storage/logs/laravel.log
```

### Clear Application Cache
```bash
php artisan cache:clear
```

### Check Database Connection
```bash
php artisan tinker
>>> DB::connection()->getPdo();
>>> echo "Connected!";
```

### Test OpenAI Service
```bash
php artisan tinker
>>> $service = app(App\Services\OpenAIService::class);
>>> $result = $service->generateFrenchTitle("Test content");
>>> echo $result;
```

### Test Email Service
```bash
php artisan tinker
>>> $service = app(App\Services\EmailService::class);
>>> $emails = $service->getUnreadEmails();
>>> echo count($emails);
```

---

## Database Queries

### Count News by Status
```sql
SELECT status, COUNT(*) as count FROM t_news GROUP BY status;
```

### Count News by Language
```sql
SELECT lang, COUNT(*) as count FROM t_news GROUP BY lang;
```

### Count News by Status and Language
```sql
SELECT lang, status, COUNT(*) as count FROM t_news GROUP BY lang, status;
```

### Find Duplicate Emails
```sql
SELECT email_message_id, COUNT(*) 
FROM t_news 
GROUP BY email_message_id 
HAVING COUNT(*) > 1;
```

### Remove Failed Processing
```sql
DELETE FROM t_news WHERE status = 1 AND updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

### Archive Old News
```sql
DELETE FROM t_news 
WHERE status = 2 
AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

### Check Recent Processing
```sql
SELECT id, lang, title, status, created_at 
FROM t_news 
ORDER BY created_at DESC 
LIMIT 10;
```

### View Unprocessed News
```sql
SELECT * FROM t_news WHERE status = 0 ORDER BY created_at DESC;
```

---

## Postman Usage

### Import Collection
1. Open Postman
2. Click "Import"
3. Select `postman_collection.json`
4. Endpoints appear in left sidebar

### Set Base URL Variable
1. Click "Collections" → "Variables"
2. Set `base_url` to `http://localhost:8000`
3. All endpoints use `{{base_url}}`

### Test Endpoints
1. Click any endpoint
2. Click "Send"
3. View response in "Body" tab

---

## Windows Task Scheduler Setup

### Create Batch File `C:\update-news.bat`
```batch
@echo off
cd C:\xampp8\htdocs\extract_news
php artisan emails:process >> storage/logs/scheduler.log 2>&1
php artisan wordpress:sync >> storage/logs/scheduler.log 2>&1
```

### Schedule with Task Scheduler
1. Open Task Scheduler
2. Create Basic Task
3. Name: "Aero Morning News"
4. Trigger: Daily at 02:00, repeat every 15 min
5. Action: Start program `C:\update-news.bat`
6. Finish

---

## Common Issues & Solutions

### Issue: "SQLSTATE[HY000]: Connection refused"
**Solution:**
```bash
# Start MySQL
# Check credentials in .env
# Verify database exists:
mysql -u root -e "SHOW DATABASES LIKE 'extract_news';"
```

### Issue: "IMAP connection timeout"
**Solution:**
```env
# Verify settings in .env
IMAP_HOST=imap.gmail.com
IMAP_PORT=993
IMAP_ENCRYPTION=ssl

# For Gmail, use App Password (not regular password)
# https://myaccount.google.com/apppasswords
```

### Issue: "OpenAI API rate limit exceeded"
**Solution:**
```bash
# Wait 60 seconds and retry
# Or upgrade OpenAI plan at platform.openai.com
```

### Issue: "Image processing failed"
**Solution:**
```bash
# Check GD extension
php -m | grep GD

# Enable in php.ini if missing
# Uncomment: extension=gd
# Restart Apache/PHP
```

### Issue: "Allowed memory size exhausted"
**Solution:**
```ini
# In php.ini
memory_limit = 512M
```

### Issue: "WordPress API authentication failed"
**Solution:**
```bash
# Verify WordPress user exists and has REST API access
# Use app-specific password if available
# Check WordPress debug logs
```

---

## Performance Tips

### Disable Debug in Production
```env
APP_DEBUG=false
```

### Optimize Images Aggressively
```env
IMAGE_QUALITY=70
```

### Batch Process Emails
```bash
# Process multiple emails in one command
php artisan emails:process  # Processes all unread
```

### Archive Old Data
```sql
DELETE FROM t_news WHERE status = 2 AND updated_at < DATE_SUB(NOW(), INTERVAL 60 DAY);
```

### Use Bulk Endpoints
```bash
# Instead of posting one by one:
curl -X POST http://localhost:8000/api/news/bulk-post-to-wordpress \
  -d '{"news_ids":[1,2,3,4,5],"username":"admin","password":"pwd"}'
```

---

## Monitoring Dashboard

### Get Overview Statistics
```bash
curl http://localhost:8000/api/stats | jq '.'
```

### Monitor Recent Processing (every 5 seconds)
```bash
while true; do 
  clear
  curl http://localhost:8000/api/stats | jq '.data'
  sleep 5
done
```

### Check Log in Real Time
```bash
tail -f storage/logs/laravel.log | grep -E "(ERROR|Processing|Synced)"
```

---

## Backup & Restore

### Backup Database
```bash
mysqldump -u root extract_news > backup_$(date +%Y%m%d_%H%M%S).sql
```

### Restore Database
```bash
mysql -u root extract_news < backup_20240115_120000.sql
```

### Backup Images
```bash
zip -r images_backup_$(date +%Y%m%d_%H%M%S).zip storage/app/public/images/
```

---

## Useful Artisan Commands

```bash
php artisan route:list                      # List all routes
php artisan route:list --path=api           # List API routes
php artisan migrate:status                  # Show migration status
php artisan tinker                          # Interactive PHP shell
php artisan cache:clear                     # Clear cache
php artisan config:cache                    # Cache configuration
php artisan make:model ModelName            # Create new model
php artisan make:migration create_table     # Create migration
php artisan make:controller ControllerName  # Create controller
php artisan make:command CommandName        # Create command
```

---

## Composer Commands

```bash
composer install                            # Install dependencies
composer update                             # Update dependencies
composer require vendor/package             # Add new package
composer remove vendor/package              # Remove package
composer dump-autoload                      # Regenerate autoload
composer show                               # List installed packages
```

---

## Testing with cURL

### Pretty Print JSON Response
```bash
curl http://localhost:8000/api/stats | jq '.'
```

### Save Response to File
```bash
curl http://localhost:8000/api/stats > response.json
```

### Include Headers in Response
```bash
curl -i http://localhost:8000/api/stats
```

### Test with Custom Headers
```bash
curl -H "X-Custom-Header: value" http://localhost:8000/api/stats
```

### POST with Data
```bash
curl -X POST http://localhost:8000/api/endpoint \
  -H "Content-Type: application/json" \
  -d '{"key":"value"}'
```

---

## Laravel Tinker (Interactive Shell)

```bash
php artisan tinker

# Query examples:
>>> App\Models\News::count()
>>> App\Models\News::where('status', 0)->count()
>>> App\Models\News::latest()->first()
>>> DB::table('t_news')->where('lang','FR')->get()
>>> exit()
```

---

This cheat sheet covers 90% of common tasks. For detailed information, refer to full documentation.

**Last Updated**: January 2024
