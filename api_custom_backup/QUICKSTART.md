# Quick Start Guide - 10 Minutes to Running API

## Step 1: Install (2 min)

```bash
cd c:\xampp8\htdocs\extract_news
composer install
```

## Step 2: Configure (3 min)

```bash
copy .env.example .env
```

Edit `.env` and change these:

```env
DB_DATABASE=extract_news
IMAP_HOST=imap.gmail.com
IMAP_USERNAME=your-email@gmail.com
IMAP_PASSWORD=your-app-specific-password
OPENAI_API_KEY=sk-your-api-key
WORDPRESS_FR_URL=https://preprod.aeromorning.com
WORDPRESS_EN_URL=https://preprod.aeromorning.com/en
```

## Step 3: Setup (3 min)

```bash
# Generate app key
php artisan key:generate

# Create database (MySQL GUI or command)
mysql -u root -e "CREATE DATABASE extract_news CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Create folders
mkdir storage/app/attachments
mkdir storage/app/public/images
mkdir storage/app/temp
```

## Step 4: Initialize (1 min)

```bash
# Run migrations
php artisan migrate

# Sync WordPress data
php artisan wordpress:sync
```

## Step 5: Test (1 min)

```bash
# Start server
php artisan serve

# In another terminal/browser
curl http://localhost:8000/api/stats
```

**Success!** You should see JSON response with statistics.

---

## Sending First Email

1. **Send email** to your IMAP account with:
   - Subject: News headline
   - Body: Article content (French or English)
   - Attachment: Image file (JPG, PNG, etc.)

2. **Process email:**
```bash
php artisan emails:process
```

3. **View results:**
```bash
curl http://localhost:8000/api/news/pending
```

---

## Publishing to WordPress

1. **Get WordPress credentials:**
   - Visit: `https://preprod.aeromorning.com/wp-admin/`
   - Create new user or use existing
   - Verify REST API access enabled

2. **Post article:**
```bash
curl -X POST http://localhost:8000/api/news/1/post-to-wordpress \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"your-app-password"}'
```

---

## Common Commands

```bash
# Process emails (check for new unread emails)
php artisan emails:process

# Sync WordPress categories/tags
php artisan wordpress:sync

# View pending news
curl http://localhost:8000/api/news/pending

# Get statistics
curl http://localhost:8000/api/stats

# View logs
tail -f storage/logs/laravel.log

# Start API server
php artisan serve --host 0.0.0.0 --port 8000
```

---

## Troubleshooting

### MySQL Connection Failed
```bash
# Check MySQL running
# Start: Start MySQL from XAMPP Control Panel
# Or verify credentials in .env
```

### IMAP Connection Error
```bash
# Verify Gmail app password created:
# https://myaccount.google.com/apppasswords
# Use 16-character password (remove spaces)
```

### OpenAI API Error
```bash
# Check API key valid at:
# https://platform.openai.com/account/api-keys
# Verify account has credits
```

### Image Not Processing
```bash
# Ensure GD extension enabled:
php -m | grep GD

# If missing, enable in php.ini:
# Uncomment: extension=gd
```

---

## Next: Production Setup

For automation, see **INSTALLATION.md** section on:
- Windows Task Scheduler
- Laravel Scheduler
- Cron jobs

---

## Need Help?

- API details: **API_DOCUMENTATION.md**
- Full setup: **INSTALLATION.md**
- Project info: **PROJECT_SUMMARY.md**
- General: **README.md**

---

**Ready to use! Start sending emails!** ✈️
