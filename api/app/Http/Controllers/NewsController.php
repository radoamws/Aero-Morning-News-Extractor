<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Services\EmailService;
use App\Services\ImageService;
use App\Services\OpenAIService;
use App\Services\WordPressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use DOMDocument;

class NewsController extends Controller
{
    private ?EmailService $emailService = null;
    private ?ImageService $imageService = null;
    private ?OpenAIService $openaiService = null;
    private WordPressService $wordpressService;

    public function __construct(WordPressService $wordpressService)
    {
        $this->wordpressService = $wordpressService;
    }

    /**
     * Resolve heavy dependencies only for email processing flow.
     */
    private function ensureEmailProcessingServices(): void
    {
        if ($this->emailService === null) {
            $this->emailService = app(EmailService::class);
        }

        if ($this->imageService === null) {
            $this->imageService = app(ImageService::class);
        }

        if ($this->openaiService === null) {
            $this->openaiService = app(OpenAIService::class);
        }
    }

    /**
     * Sync WordPress categories and tags
     */
    public function syncWordPressData(): JsonResponse
    {
        try {
            @ini_set('max_execution_time', '300');
            @set_time_limit(300);

            $results = [
                'categories_fr' => $this->wordpressService->syncCategoriesFr(),
                'categories_en' => $this->wordpressService->syncCategoriesEn(),
                'tags_fr' => $this->wordpressService->syncTagsFr(),
                'tags_en' => $this->wordpressService->syncTagsEn(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'WordPress data synced successfully',
                'data' => $results
            ]);
        } catch (\Throwable $e) {
            Log::error('Error syncing WordPress data: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process unread emails
     */
    public function processEmails(): JsonResponse
    {
        try {
            $this->ensureEmailProcessingServices();

            // Real mailbox processing can be slower on large multipart emails.
            @ini_set('max_execution_time', '300');
            @set_time_limit(300);

            $emails = $this->emailService->getUnreadEmails();

            if (empty($emails)) {
                return response()->json([
                    'success' => true,
                    'message' => 'No emails to process for current IMAP criteria',
                    'processed' => 0
                ]);
            }

            $processedCount = 0;
            $failedCount = 0;

            foreach ($emails as $mail) {
                try {
                    if ($this->processSingleEmail($mail)) {
                        $processedCount++;
                    } else {
                        $failedCount++;
                    }
                } catch (\Throwable $e) {
                    Log::error("Error processing email: " . $e->getMessage());
                    $failedCount++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Email processing completed',
                'processed' => $processedCount,
                'failed' => $failedCount
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in email processing: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process a single email
     */
    private function processSingleEmail($mail): bool
    {
        try {
            // Extract email content
            $emailContent = $this->emailService->extractEmailContent($mail);
            
            // Check if email is duplicate
            $existingLangs = [];
            if (!empty($emailContent['message_id'])) {
                $existingLangs = News::where('email_message_id', $emailContent['message_id'])
                    ->pluck('lang')
                    ->toArray();
            }

            // Extract or download image
            $bodyContent = !empty(trim((string) $emailContent['text_body']))
                ? $emailContent['text_body']
                : $emailContent['html_body'];
            $normalizedBodyContent = $this->normalizeEmailBody($bodyContent);
            $imageUrl = null;
            if ($this->emailService->hasAttachments($mail)) {
                $bestAttachment = $this->selectBestImageAttachment($mail->getAttachments());
                if ($bestAttachment) {
                    $filePath = $bestAttachment->filePath ?? null;
                    if ($filePath) {
                        $imageUrl = $this->imageService->processAttachmentImage(
                            $filePath,
                            $emailContent['subject']
                        );
                    }
                }
            } else {
                $foundImageUrl = $this->emailService->extractImageUrlFromHtml($normalizedBodyContent);
                if ($foundImageUrl && $this->imageService->isValidImageUrl($foundImageUrl)) {
                    $imageUrl = $this->imageService->downloadAndOptimizeImage(
                        $foundImageUrl,
                        $emailContent['subject']
                    );
                }
            }

            Log::info("Email subject: " . $emailContent['subject']);
            Log::info("Has attachment: " . ($this->emailService->hasAttachments($mail) ? 'yes' : 'no'));

            // Detect language from content
            $language = $this->detectLanguage($normalizedBodyContent);
            $createdAny = false;

            // Generate French news if content contains French
            if (in_array('FR', $language)) {
                if (in_array('FR', $existingLangs, true)) {
                    Log::info("French news already exists for email: " . $emailContent['message_id']);
                } else {
                    $frenchContent = $this->extractContentForLanguage($normalizedBodyContent, 'FR');
                    $frenchOriginalTitle = $this->extractOriginalArticleTitle($frenchContent, $emailContent['subject'], 'FR');
                    $frenchSource = $this->detectArticleSource($frenchContent, (string) ($emailContent['from'] ?? ''));
                    $createdAny = $this->processFrenchNews(
                        $frenchContent,
                        $emailContent['subject'],
                        $emailContent['message_id'],
                        $imageUrl,
                        $frenchOriginalTitle,
                        $frenchSource
                    ) || $createdAny;
                }
            }

            // Generate English news if content contains English
            if (in_array('EN', $language)) {
                if (in_array('EN', $existingLangs, true)) {
                    Log::info("English news already exists for email: " . $emailContent['message_id']);
                } else {
                    $englishContent = $this->extractContentForLanguage($normalizedBodyContent, 'EN');
                    $englishOriginalTitle = $this->extractOriginalArticleTitle($englishContent, $emailContent['subject'], 'EN');
                    $englishSource = $this->detectArticleSource($englishContent, (string) ($emailContent['from'] ?? ''));
                    $createdAny = $this->processEnglishNews(
                        $englishContent,
                        $emailContent['subject'],
                        $emailContent['message_id'],
                        $imageUrl,
                        $englishOriginalTitle,
                        $englishSource
                    ) || $createdAny;
                }
            }

            if (!$createdAny && !empty($existingLangs)) {
                return true;
            }

            if (!$createdAny) {
                Log::warning('No news generated for email: ' . ($emailContent['message_id'] ?: 'no-message-id'));
                return false;
            }

            // Mark email as read
            $this->emailService->markAsRead($mail->id);

            return true;
        } catch (\Throwable $e) {
            Log::error("Error processing single email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process French news
     */
    private function processFrenchNews(
        string $content,
        string $subject,
        string $messageId,
        ?string $imageUrl,
        string $originalTitle,
        string $sourceHint
    ): bool
    {
        try {
            // Generate French metadata
            $titleFr = $this->openaiService->generateFrenchTitle($content);
            if (!$titleFr) {
                $titleFr = $this->fallbackTitle($subject, 'FR');
                Log::warning("Failed to generate French title, using fallback title");
            }

            $contentFr = $this->openaiService->generateFrenchContent($content, $titleFr);
            if (!$contentFr) {
                $contentFr = $this->fallbackContent($content, $titleFr, 'FR');
                Log::warning("Failed to generate French content, using fallback content");
            }

            $contentFr = $this->finalizeArticleHtml($contentFr, $content, $originalTitle, $sourceHint, 'FR');

            $metaDescFr = $this->buildMetaDescriptionFromArticle($contentFr, 'FR');
            $keyPhraseFr = $this->buildFocusKeyphraseFromTitle($originalTitle, 'FR');

            // Get categories and tags
            $categoriesFr = $this->wordpressService->getCategoriesForClassification('FR');
            $categoriesString = $this->openaiService->classifyCategories($contentFr, $categoriesFr, 'FR');

            $tagsFr = $this->wordpressService->getTagsForClassification('FR');
            $tagsString = $this->openaiService->classifyTags($contentFr, $tagsFr, 'FR');

            // Save to database
            News::create([
                'lang' => 'FR',
                'title' => $this->shortenTitleForColumn($originalTitle),
                'content' => $contentFr,
                'metadescription' => $metaDescFr ?? '',
                'focuskeyphrase' => $keyPhraseFr ?? '',
                'categories' => $categoriesString ?? '',
                'tags' => $tagsString ?? '',
                'image_url' => $imageUrl,
                'status' => News::STATUS_PENDING,
                'email_message_id' => $messageId
            ]);

            Log::info("French news created successfully for email: $messageId");
            return true;
        } catch (\Exception $e) {
            Log::error("Error processing French news: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process English news
     */
    private function processEnglishNews(
        string $content,
        string $subject,
        string $messageId,
        ?string $imageUrl,
        string $originalTitle,
        string $sourceHint
    ): bool
    {
        try {
            // Generate English metadata
            $titleEn = $this->openaiService->generateEnglishTitle($content);
            if (!$titleEn) {
                $titleEn = $this->fallbackTitle($subject, 'EN');
                Log::warning("Failed to generate English title, using fallback title");
            }

            $contentEn = $this->openaiService->generateEnglishContent($content, $titleEn);
            if (!$contentEn) {
                $contentEn = $this->fallbackContent($content, $titleEn, 'EN');
                Log::warning("Failed to generate English content, using fallback content");
            }

            $contentEn = $this->finalizeArticleHtml($contentEn, $content, $originalTitle, $sourceHint, 'EN');

            $metaDescEn = $this->buildMetaDescriptionFromArticle($contentEn, 'EN');
            $keyPhraseEn = $this->buildFocusKeyphraseFromTitle($originalTitle, 'EN');

            // Get categories and tags
            $categoriesEn = $this->wordpressService->getCategoriesForClassification('EN');
            $categoriesString = $this->openaiService->classifyCategories($contentEn, $categoriesEn, 'EN');

            $tagsEn = $this->wordpressService->getTagsForClassification('EN');
            $tagsString = $this->openaiService->classifyTags($contentEn, $tagsEn, 'EN');

            // Save to database
            News::create([
                'lang' => 'EN',
                'title' => $this->shortenTitleForColumn($originalTitle),
                'content' => $contentEn,
                'metadescription' => $metaDescEn ?? '',
                'focuskeyphrase' => $keyPhraseEn ?? '',
                'categories' => $categoriesString ?? '',
                'tags' => $tagsString ?? '',
                'image_url' => $imageUrl,
                'status' => News::STATUS_PENDING,
                'email_message_id' => $messageId
            ]);

            Log::info("English news created successfully for email: $messageId");
            return true;
        } catch (\Exception $e) {
            Log::error("Error processing English news: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Detect language(s) in content
     */
    private function detectLanguage(string $content): array
    {
        $languages = [];
        
        // Simple heuristic: check for common French and English words
        $frenchWords = ['le', 'la', 'de', 'un', 'une', 'et', 'est', 'que', 'pour', 'sur'];
        $englishWords = ['the', 'a', 'and', 'of', 'to', 'in', 'is', 'that', 'for', 'on'];

        $contentLower = strtolower($content);
        $frenchCount = 0;
        $englishCount = 0;

        foreach ($frenchWords as $word) {
            if (preg_match('/\b' . $word . '\b/i', $contentLower)) {
                $frenchCount++;
            }
        }

        foreach ($englishWords as $word) {
            if (preg_match('/\b' . $word . '\b/i', $contentLower)) {
                $englishCount++;
            }
        }

        if ($frenchCount > 0) {
            $languages[] = 'FR';
        }
        if ($englishCount > 0) {
            $languages[] = 'EN';
        }

        // Default to French if no clear language detected
        if (empty($languages)) {
            $languages[] = 'FR';
        }

        return $languages;
    }

    private function fallbackTitle(string $subject, string $lang): string
    {
        $clean = trim((string) preg_replace('/^(RE|FW|FWD|TR|CP)\s*:\s*/i', '', $subject));
        if ($clean === '') {
            $clean = $lang === 'EN' ? 'Aviation News Update' : 'Actualite aviation';
        }

        return mb_substr($clean, 0, 53);
    }

    private function fallbackContent(string $rawContent, string $title, string $lang): string
    {
        $trimmed = trim($rawContent);

        if ($trimmed === '') {
            $message = $lang === 'EN'
                ? 'Content unavailable from source email.'
                : 'Contenu indisponible depuis le mail source.';

            return '<h2>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>'
                . '<p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        if (strpos($trimmed, '<') !== false && strpos($trimmed, '>') !== false) {
            return $trimmed;
        }

        $safeBody = nl2br(htmlspecialchars($trimmed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        return '<h2>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2><p>' . $safeBody . '</p>';
    }

    private function fallbackMetaDescription(string $content, string $lang): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($content)));
        if ($text === '') {
            $text = $lang === 'EN'
                ? 'Latest aviation update generated from incoming email.'
                : 'Derniere actualite aviation generee depuis un email entrant.';
        }

        return mb_substr($text, 0, 141);
    }

    private function fallbackKeyphrase(string $title): string
    {
        $words = preg_split('/\s+/', trim(strip_tags($title))) ?: [];
        $words = array_values(array_filter($words, static fn ($w) => $w !== ''));

        if (empty($words)) {
            return 'aviation news';
        }

        return implode(' ', array_slice($words, 0, 4));
    }

    private function normalizeEmailBody(string $bodyContent): string
    {
        $content = trim($bodyContent);
        if ($content === '') {
            return '';
        }

        if (strpos($content, '<') === false) {
            return $this->normalizePlainTextContent($content);
        }

        $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $content) ?? $content;
        $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $clean) ?? $clean;
        $clean = preg_replace('/<head\b[^>]*>.*?<\/head>/is', ' ', $clean) ?? $clean;
        $clean = preg_replace('/<!--.*?-->/s', ' ', $clean) ?? $clean;
        $clean = preg_replace('/<(meta|link|xml|o:p)\b[^>]*>.*?<\/\1>/is', ' ', $clean) ?? $clean;
        $clean = preg_replace('/<(meta|link)\b[^>]*\/?>/is', ' ', $clean) ?? $clean;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $clean, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $body = $dom->saveHTML() ?: $clean;
        return $this->normalizePlainTextContent($body);
    }

    private function normalizePlainTextContent(string $content): string
    {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/\x{00a0}/u', ' ', $content) ?? $content;
        $content = preg_replace('/[ \t]+/', ' ', $content) ?? $content;
        $content = preg_replace('/\n{3,}/', "\n\n", $content) ?? $content;

        $lines = preg_split('/\n/', strip_tags($content)) ?: [];
        $filtered = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($this->isBoilerplateLine($line)) {
                continue;
            }

            $filtered[] = $line;
        }

        return trim(implode("\n", $filtered));
    }

    private function extractContentForLanguage(string $content, string $lang): string
    {
        $sectionContent = $this->extractExplicitVersionSection($content, $lang);
        if ($sectionContent !== null) {
            return trim($sectionContent);
        }

        $lines = preg_split('/\n+/', $content) ?: [];
        $selected = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $lineLangs = $this->detectLanguage($line);
            if (in_array($lang, $lineLangs, true)) {
                $selected[] = $line;
                continue;
            }

            if (empty($lineLangs) || (count($lineLangs) === 1 && $lineLangs[0] === 'FR' && $lang === 'FR')) {
                if (!$this->looksLikeForeignOnlyLine($line, $lang)) {
                    $selected[] = $line;
                }
            }
        }

        if (empty($selected)) {
            return $content;
        }

        return implode("\n", $selected);
    }

    private function looksLikeForeignOnlyLine(string $line, string $lang): bool
    {
        $lineLangs = $this->detectLanguage($line);
        if (empty($lineLangs)) {
            return false;
        }

        return !in_array($lang, $lineLangs, true) && count($lineLangs) === 1;
    }

    private function isBoilerplateLine(string $line): bool
    {
        $patterns = [
            '/^niveau de confidentialite/i',
            '/^rado\s+rakotoarivelo/i',
            '/^a\s*:\s*route royale/i',
            '/^w\s*:\s*/i',
            '/^photo jointe/i',
            '/^texte uk et f/i',
            '/^aeromorning version/i',
            '/^________________________________/i',
            '/^from\s*:/i',
            '/^sent\s*:/i',
            '/^to\s*:/i',
            '/^subject\s*:/i',
            '/^cc\s*:/i',
            '/^de\s*:/i',
            '/^envoye\s*:/i',
            '/^objet\s*:/i',
            '/unsubscribe/i',
            '/view this email/i',
            '/www\./i',
            '/@/i',
            '/^tel\s*:/i',
            '/^phone\s*:/i',
            '/business wire/i',
            '/prnewswire/i',
            '/referent/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    private function extractExplicitVersionSection(string $content, string $lang): ?string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);

        $patterns = $lang === 'FR'
            ? ['/\n\s*(?:2|II)[^\n]{0,12}version\s*(?:f|fr)\b/i', '/\n[^\n]{0,12}version\s*(?:f|fr)\b/i']
            : ['/\n\s*(?:1|I)[^\n]{0,12}version\s*(?:uk|en|english)\b/i', '/\n[^\n]{0,12}version\s*(?:uk|en|english)\b/i'];

        $start = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches, PREG_OFFSET_CAPTURE)) {
                $start = $matches[0][1] + strlen($matches[0][0]);
                break;
            }
        }

        if ($start === null) {
            return null;
        }

        $remaining = substr($normalized, $start);
        if ($remaining === false) {
            return null;
        }

        $endPatterns = $lang === 'FR'
            ? ['/\n\s*(?:1|I)[^\n]{0,12}version\s*(?:uk|en|english)\b/i']
            : ['/\n\s*(?:2|II)[^\n]{0,12}version\s*(?:f|fr)\b/i'];

        $end = null;
        foreach ($endPatterns as $pattern) {
            if (preg_match($pattern, $remaining, $matches, PREG_OFFSET_CAPTURE)) {
                $end = $matches[0][1];
                break;
            }
        }

        if ($end !== null) {
            $remaining = substr($remaining, 0, $end) ?: $remaining;
        }

        return trim($remaining);
    }

    private function extractOriginalArticleTitle(string $content, string $subject, string $lang): string
    {
        $lines = preg_split('/\n+/', trim($content)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (
                $line === ''
                || str_starts_with($line, '•')
                || preg_match('/^aeromorning\b/i', $line)
                || preg_match('/^[0-9]+[.)]/', $line)
                || preg_match('/^(version|source)\b/i', $line)
            ) {
                continue;
            }

            return trim(preg_replace('/\s+/', ' ', $line) ?? $line);
        }

        return $this->fallbackTitle($subject, $lang);
    }

    private function detectArticleSource(string $content, string $from): string
    {
        // 1. Extract display name from "From" header (e.g. "Air Canada Press <pr@aircanada.com>")
        $displayName = '';
        if (preg_match('/^(.+?)\s*<[^>]+>/', trim($from), $m)) {
            $displayName = trim($m[1]);
        } elseif ($from !== '' && !str_contains($from, '@')) {
            $displayName = trim($from);
        }
        if ($displayName !== '') {
            $cleaned = preg_replace('/\s+(News|Press|Communications?|Media|PR|Relations?|Newsroom|Release|Alert|Update|Info|Information)$/i', '', $displayName);
            $cleaned = trim($cleaned ?? $displayName);
            if ($cleaned !== '' && mb_strlen($cleaned) >= 3 && mb_strlen($cleaned) <= 50) {
                return $cleaned;
            }
        }

        // 2. Scan content (first 1500 chars) for known organizations
        $haystack = mb_strtolower(mb_substr($content, 0, 1500) . ' ' . mb_strtolower($from));

        $sourceMap = [
            'air france' => 'Air France',
            'air canada' => 'Air Canada',
            'air algerie' => 'Air Algérie',
            'emirates' => 'Emirates',
            'lufthansa' => 'Lufthansa',
            'united airlines' => 'United Airlines',
            'american airlines' => 'American Airlines',
            'british airways' => 'British Airways',
            'delta air' => 'Delta Air Lines',
            'southwest airlines' => 'Southwest Airlines',
            'ryanair' => 'Ryanair',
            'easyjet' => 'easyJet',
            'turkish airlines' => 'Turkish Airlines',
            'volaris' => 'Volaris',
            'viva aerobus' => 'Viva Aerobus',
            'nasa' => 'NASA',
            'spacex' => 'SpaceX',
            'airbus' => 'Airbus',
            'boeing' => 'Boeing',
            'safran' => 'Safran',
            'thales' => 'Thales',
            'rolls-royce' => 'Rolls-Royce',
            'prnewswire' => 'PRNewswire',
            'business wire' => 'Business Wire',
            'reuters' => 'Reuters',
            'bloomberg' => 'Bloomberg',
            'afp' => 'AFP',
        ];

        foreach ($sourceMap as $needle => $source) {
            if (str_contains($haystack, $needle)) {
                return $source;
            }
        }

        // 3. Fall back to clean domain from the email address
        if ($from !== '' && preg_match('/@([\w.-]+)/', $from, $dm)) {
            $domain = strtolower($dm[1]);
            $domain = preg_replace('/\.(com|fr|net|org|de|uk|ca|us|au|io)$/', '', $domain) ?? $domain;
            $domain = preg_replace('/^(mail|news|press|noreply|info)\./', '', $domain) ?? $domain;
            if ($domain !== '' && mb_strlen($domain) >= 3) {
                return ucwords(str_replace(['.', '-', '_'], ' ', $domain));
            }
        }

        return 'Email source';
    }

    private function finalizeArticleHtml(
        string $generatedHtml,
        string $rawContent,
        string $originalTitle,
        string $sourceHint,
        string $lang
    ): string {
        $base = $generatedHtml !== '' ? $generatedHtml : $this->fallbackContent($rawContent, $originalTitle, $lang);

        $base = preg_replace('/\[\s*image\s+en\s+ligne\s*\]/iu', ' ', $base) ?? $base;
        $base = preg_replace('/<img\b[^>]*>/i', ' ', $base) ?? $base;
        $base = preg_replace('/<a\b[^>]*href="[^"]*\.(png|jpe?g|gif|webp)[^"]*"[^>]*>.*?<\/a>/is', ' ', $base) ?? $base;
        $base = preg_replace('/https?:\/\/\S+\.(png|jpe?g|gif|webp)\S*/i', ' ', $base) ?? $base;

        $plain = trim(strip_tags($base));
        if ($plain === '') {
            $plain = trim($rawContent);
        }

        $paragraphs = preg_split('/\n{2,}|\r\n\r\n/', str_replace(["\r\n", "\r"], "\n", $plain)) ?: [];
        $htmlParagraphs = [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '' || preg_match('/^\[\s*image\s+en\s+ligne\s*\]$/iu', $paragraph)) {
                continue;
            }

            $htmlParagraphs[] = '<p>' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        }

        $sourceLabel = $lang === 'FR' ? 'Source : ' : 'Source: ';
        $hasSource = preg_match('/\bsource\s*:/i', implode("\n", $htmlParagraphs)) === 1;
        if (!$hasSource) {
            $htmlParagraphs[] = '<p>' . htmlspecialchars($sourceLabel . $sourceHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        $h2 = '<h2>' . htmlspecialchars($originalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
        return $h2 . implode('', $htmlParagraphs);
    }

    private function buildMetaDescriptionFromArticle(string $contentHtml, string $lang): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($contentHtml)) ?? '');
        $text = trim(preg_replace('/\bsource\s*:\s*[^\n.]+$/i', '', $text) ?? $text);

        if ($text === '') {
            return '';
        }

        // Target: 106–141 characters
        // If short text, return all (cannot expand without fabrication)
        if (mb_strlen($text) <= 141) {
            return $text;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
        $meta = '';

        foreach ($sentences as $sentence) {
            $candidate = trim($meta === '' ? $sentence : ($meta . ' ' . $sentence));
            $candidateLen = mb_strlen($candidate);

            if ($candidateLen > 141) {
                if ($meta === '') {
                    // First sentence alone exceeds 141 — truncate it
                    $meta = $this->smartWordLimit($sentence, 141);
                }
                break;
            }

            $meta = $candidate;

            if ($candidateLen >= 106) {
                // In [106, 141] range — stop adding more
                break;
            }
        }

        // If still under 106, take a continuous word-bounded slice up to 141
        if (mb_strlen($meta) < 106 && mb_strlen($text) >= 106) {
            $meta = $this->smartWordLimit($text, 141);
        }

        return trim($meta);
    }

    private function shortenTitleForColumn(string $originalTitle, int $max = 53): string
    {
        $title = trim(strip_tags($originalTitle));
        if (mb_strlen($title) <= $max) {
            return $title;
        }
        // Break at a natural separator within budget
        foreach ([' : ', ' – ', ' - ', ' | '] as $sep) {
            $pos = mb_strpos($title, $sep);
            if ($pos !== false && $pos >= 8 && $pos <= $max) {
                return mb_substr($title, 0, $pos);
            }
        }
        // Word-boundary truncation
        $slice = mb_substr($title, 0, $max + 1);
        $lastSpace = mb_strrpos($slice, ' ');
        if ($lastSpace !== false && $lastSpace >= (int) floor($max * 0.6)) {
            return rtrim(mb_substr($slice, 0, $lastSpace));
        }
        return mb_substr($title, 0, $max);
    }

    private function buildFocusKeyphraseFromTitle(string $title, string $lang): string
    {
        $clean = trim(strip_tags($title));
        $clean = str_replace(',', ' ', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = preg_replace('/[;:!?]+$/', '', $clean) ?? $clean;

        if ($clean === '') {
            return $this->fallbackKeyphrase($title);
        }

        return $this->smartWordLimit($clean, 90, 12);
    }

    private function smartWordLimit(string $text, int $maxChars, int $maxWords = 999): string
    {
        $text = trim($text);
        $words = preg_split('/\s+/', $text) ?: [];
        if (count($words) > $maxWords) {
            $text = implode(' ', array_slice($words, 0, $maxWords));
        }

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $slice = mb_substr($text, 0, $maxChars + 1);
        $lastSpace = mb_strrpos($slice, ' ');
        if ($lastSpace !== false && $lastSpace > (int) floor($maxChars * 0.6)) {
            return rtrim(mb_substr($slice, 0, $lastSpace));
        }

        return rtrim(mb_substr($text, 0, $maxChars));
    }

    private function selectBestImageAttachment(array $attachments): ?object
    {
        $bestAttachment = null;
        $bestScore = -1;

        foreach ($attachments as $attachment) {
            $mimeType = strtolower((string) ($attachment->mimeType ?? $attachment->mime ?? ''));
            $filePath = $attachment->filePath ?? null;

            if (!$filePath || !str_contains($mimeType, 'image')) {
                continue;
            }

            $name = strtolower((string) ($attachment->name ?? basename((string) $filePath)));
            $size = (int) ($attachment->sizeInBytes ?? @filesize($filePath) ?: 0);
            $score = $size;

            if (preg_match('/logo|icon|signature|linkedin|facebook|twitter|instagram|header|footer/', $name)) {
                $score -= 500000;
            }

            if (preg_match('/blob|photo|image|cover|hero|artemis|volaris|viva/i', $name)) {
                $score += 200000;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAttachment = $attachment;
            }
        }

        return $bestAttachment;
    }

    /**
     * Get pending news
     */
    public function getPendingNews(): JsonResponse
    {
        try {
            $news = News::where('status', News::STATUS_PENDING)
                ->orderBy('created_at', 'desc')
                ->paginate(15);

            return response()->json([
                'success' => true,
                'data' => $news
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching pending news: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get news list with filters and sorting
     */
    public function getNewsList(Request $request): JsonResponse
    {
        try {
            $query = News::query();

            if ($request->filled('status')) {
                $query->where('status', (int) $request->input('status'));
            }

            if ($request->filled('lang')) {
                $query->where('lang', strtoupper((string) $request->input('lang')));
            }

            if ($request->filled('q')) {
                $search = (string) $request->input('q');
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('title', 'like', "%{$search}%")
                        ->orWhere('metadescription', 'like', "%{$search}%")
                        ->orWhere('focuskeyphrase', 'like', "%{$search}%");
                });
            }

            $allowedSortFields = ['id', 'created_at', 'updated_at', 'lang', 'status', 'title'];
            $sortBy = (string) $request->input('sort_by', 'created_at');
            $sortBy = in_array($sortBy, $allowedSortFields, true) ? $sortBy : 'created_at';

            $sortDir = strtolower((string) $request->input('sort_dir', 'desc'));
            $sortDir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

            $perPage = (int) $request->input('per_page', 20);
            $perPage = max(1, min($perPage, 100));

            $news = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $news,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching news list: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get news by ID
     */
    public function getNewsById($id): JsonResponse
    {
        try {
            $news = News::findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $news
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'News not found'
            ], 404);
        }
    }

    /**
     * Update news status
     */
    public function updateNewsStatus($id, string $status): JsonResponse
    {
        try {
            $validStatuses = [News::STATUS_PENDING, News::STATUS_SYNCING, News::STATUS_SYNCED];
            
            if (!in_array($status, $validStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid status'
                ], 400);
            }

            $news = News::findOrFail($id);
            $news->status = $status;
            $news->save();

            return response()->json([
                'success' => true,
                'message' => 'News status updated',
                'data' => $news
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update status by ids or by current status filter
     */
    public function bulkUpdateNewsStatus(Request $request, string $status): JsonResponse
    {
        try {
            $statusValue = (int) $status;
            $validStatuses = [News::STATUS_PENDING, News::STATUS_SYNCING, News::STATUS_SYNCED];

            if (!in_array($statusValue, $validStatuses, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid status',
                ], 400);
            }

            $query = News::query();
            $ids = $request->input('ids', []);

            if (is_array($ids) && !empty($ids)) {
                $query->whereIn('id', $ids);
            } elseif ($request->filled('status_filter')) {
                $query->where('status', (int) $request->input('status_filter'));
            }

            if ($request->filled('lang')) {
                $query->where('lang', strtoupper((string) $request->input('lang')));
            }

            $updated = $query->update([
                'status' => $statusValue,
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bulk status update completed',
                'updated_count' => $updated,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating bulk news status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
