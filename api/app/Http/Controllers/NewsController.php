<?php

namespace App\Http\Controllers;

use App\Models\IgnoredEmail;
use App\Models\News;
use App\Services\EmailService;
use App\Services\ImageService;
use App\Services\OpenAIService;
use App\Services\WordPressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use DOMDocument;

class NewsController extends Controller
{
    private const EMAIL_RESULT_PROCESSED = 'processed';
    private const EMAIL_RESULT_FAILED = 'failed';
    private const EMAIL_RESULT_SKIPPED = 'skipped';

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
            // Keep a reasonable cap for HTTP, but allow long runs for artisan.
            if (app()->runningInConsole()) {
                @ini_set('max_execution_time', '0');
                @set_time_limit(0);
            } else {
                @ini_set('max_execution_time', '300');
                @set_time_limit(300);
            }

            $emails = $this->emailService->getUnreadEmails();

            if (empty($emails)) {
                return response()->json([
                    'success' => true,
                    'message' => 'No emails to process for current IMAP criteria',
                    'processed' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                ]);
            }

            $processedCount = 0;
            $failedCount = 0;
            $skippedCount = 0;
            $summary = [
                'processed' => [],
                'failed' => [],
                'skipped' => [],
            ];

            foreach ($emails as $mail) {
                try {
                    $result = $this->processSingleEmail($mail, $summary);

                    if ($result === self::EMAIL_RESULT_PROCESSED) {
                        $processedCount++;
                    } elseif ($result === self::EMAIL_RESULT_SKIPPED) {
                        $skippedCount++;
                    } else {
                        $failedCount++;
                    }
                } catch (\Throwable $e) {
                    Log::error("Error processing email: " . $e->getMessage());
                    $failedCount++;
                }
            }

            $this->sendProcessingSummaryEmail($summary);

            $response = [
                'success' => true,
                'message' => 'Email processing completed',
                'processed' => $processedCount,
                'failed' => $failedCount,
                'skipped' => $skippedCount,

            ];

            if (config('app.debug')) {
                $response['summary'] = $summary;
            }

            return response()->json($response);
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
    private function processSingleEmail($mail, array &$summary): string
    {
        $emailContent = [
            'subject' => 'no-subject',
            'from' => '',
            'message_id' => '',
        ];

        try {
            // Extract email content
            $emailContent = $this->emailService->extractEmailContent($mail);

            $contentBrut = json_encode($emailContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($contentBrut)) {
                $contentBrut = '';
            } 
            
            // Check if email is duplicate
            $existingLangs = [];
            if (!empty($emailContent['message_id'])) {
                $existingLangs = News::where('email_message_id', $emailContent['message_id'])
                    ->pluck('lang')
                    ->toArray();
            }

            // If both languages already exist, skip early to avoid wasting OpenAI calls.
            if (
                !empty($emailContent['message_id'])
                && in_array('FR', $existingLangs, true)
                && in_array('EN', $existingLangs, true)
            ) {
                $summary['skipped'][] = [
                    'subject' => $emailContent['subject'] ?? 'no-subject',
                    'from' => $emailContent['from'] ?? '',
                    'reason' => 'already_processed',
                ];
                $this->emailService->markAsRead($mail->id);
                return self::EMAIL_RESULT_SKIPPED;
            }

            // Extract or download image
            $bodyContent = !empty(trim((string) $emailContent['html_body']))
                ? $emailContent['html_body']
                : $emailContent['text_body'];
            $normalizedBodyContent = $this->normalizeEmailBody($bodyContent);
            $languageDetectionText = $this->normalizePlainTextContent(strip_tags($normalizedBodyContent));

            if (!$this->openaiService->isAviationRelevant($languageDetectionText !== '' ? $languageDetectionText : $normalizedBodyContent)) {
                $this->storeIgnoredEmail($emailContent, $languageDetectionText !== '' ? $languageDetectionText : $normalizedBodyContent, 'not_relevant');
                $summary['skipped'][] = [
                    'subject' => $emailContent['subject'] ?? 'no-subject',
                    'from' => $emailContent['from'] ?? '',
                    'reason' => 'not_relevant',
                ];
                Log::info('Skipping non-aviation email: ' . ($emailContent['subject'] ?? 'no-subject'));
                $this->emailService->markAsRead($mail->id);
                return self::EMAIL_RESULT_SKIPPED;
            }

            $hasAttachments = $this->emailService->hasAttachments($mail);
            $imageUrl = null;
            if ($hasAttachments) {
                $inlineCidFilenames = $this->extractInlineCidFilenames((string) ($emailContent['html_body'] ?? ''));
                $bestAttachment = $this->selectBestImageAttachment($mail->getAttachments(), $inlineCidFilenames);
                if ($bestAttachment) {
                    $filePath = $bestAttachment->filePath ?? null;
                    if ($filePath) {
                        $imageUrl = $this->imageService->processAttachmentImage(
                            $filePath,
                            $emailContent['subject']
                        );
                    }
                }
            }

            Log::info("Email subject: " . $emailContent['subject']);
            Log::info("Has attachment: " . ($hasAttachments ? 'yes' : 'no'));

            // If no usable attachment image was found, fall back to HTML image candidates.
            if (!$hasAttachments || $imageUrl === null) {
                $imageSelectionContent = $normalizedBodyContent;

                $imageCandidates = $this->emailService->extractImageCandidatesFromHtml($imageSelectionContent);
                $imageCandidates = array_values(array_filter($imageCandidates, fn ($url) => !$this->isForbiddenSignatureImageUrl((string) $url)));

                // Never let OpenAI pick a forbidden signature image.
                if (!empty($imageCandidates)) {
                    $foundImageUrl = $this->openaiService->chooseRelevantImageUrl(
                        $languageDetectionText !== '' ? $languageDetectionText : $imageSelectionContent,
                        $imageCandidates
                    );

                    if ($foundImageUrl
                        && !$this->isForbiddenSignatureImageUrl($foundImageUrl)
                        && $this->imageService->isValidImageUrl($foundImageUrl)
                    ) {
                        $imageUrl = $this->imageService->downloadAndOptimizeImage(
                            $foundImageUrl,
                            $emailContent['subject']
                        );
                    }
                }
            }

            $createdAny = false;

            // IMPORTANT: As requested, we pass the raw $emailContent at the end of the prompt.
            // No PHP-side formatting/cleanup is applied to content fields.
            $newsPayload = $this->openaiService->extractWordPressNewsJson($emailContent);

            if (!is_array($newsPayload)) {
                Log::warning('No structured JSON returned by WordPress prompt for email: ' . ($emailContent['message_id'] ?: 'no-message-id'));
                $summary['failed'][] = [
                    'subject' => $emailContent['subject'] ?? 'no-subject',
                    'from' => $emailContent['from'] ?? '',
                    'error' => 'OpenAI did not return a valid WordPress JSON payload (check OpenAI quota/billing and OPENAI_API_KEY)',
                ];
                return self::EMAIL_RESULT_FAILED;
            }

            if (($newsPayload['FR'] ?? '') !== '') {
                if (in_array('FR', $existingLangs, true)) {
                    Log::info("French news already exists for email: " . $emailContent['message_id']);
                } else {
                    $createdAny = $this->processFrenchNews(
                        $newsPayload,
                        (string) ($emailContent['subject'] ?? ''),
                        (string) ($emailContent['message_id'] ?? ''),
                        $imageUrl,
                        $contentBrut
                    ) || $createdAny;
                }
            }

            if (($newsPayload['EN'] ?? '') !== '') {
                if (in_array('EN', $existingLangs, true)) {
                    Log::info("English news already exists for email: " . $emailContent['message_id']);
                } else {
                    $createdAny = $this->processEnglishNews(
                        $newsPayload,
                        (string) ($emailContent['subject'] ?? ''),
                        (string) ($emailContent['message_id'] ?? ''),
                        $imageUrl,
                        $contentBrut
                    ) || $createdAny;
                }
            }

            if (!$createdAny && !empty($existingLangs)) {
                $summary['skipped'][] = [
                    'subject' => $emailContent['subject'] ?? 'no-subject',
                    'from' => $emailContent['from'] ?? '',
                    'reason' => 'already_processed',
                ];
                return self::EMAIL_RESULT_SKIPPED;
            }

            if (!$createdAny) {
                Log::warning('No news generated for email: ' . ($emailContent['message_id'] ?: 'no-message-id'));
                $summary['failed'][] = [
                    'subject' => $emailContent['subject'] ?? 'no-subject',
                    'from' => $emailContent['from'] ?? '',
                    'error' => 'No news generated from WordPress prompt payload',
                ];
                return self::EMAIL_RESULT_FAILED;
            }

            // Mark email as read
            $this->emailService->markAsRead($mail->id);

            $summary['processed'][] = [
                'subject' => $emailContent['subject'] ?? 'no-subject',
                'from' => $emailContent['from'] ?? '',
            ];

            return self::EMAIL_RESULT_PROCESSED;
        } catch (\Throwable $e) {
            Log::error("Error processing single email: " . $e->getMessage());
            $summary['failed'][] = [
                'subject' => $emailContent['subject'] ?? 'no-subject',
                'from' => $emailContent['from'] ?? '',
                'error' => $e->getMessage(),
            ];
            return self::EMAIL_RESULT_FAILED;
        }
    }

    private function storeIgnoredEmail(array $emailContent, string $excerpt, string $reason): void
    {
        try {
            IgnoredEmail::create([
                'message_id' => (string) ($emailContent['message_id'] ?? ''),
                'subject' => (string) ($emailContent['subject'] ?? ''),
                'sender' => (string) ($emailContent['from'] ?? ''),
                'reason' => $reason,
                'excerpt' => mb_substr(trim($excerpt), 0, 5000),
                'processed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unable to persist ignored email: ' . $e->getMessage());
        }
    }

    private function sendProcessingSummaryEmail(array $summary): void
    {
        $to = config('services.notify.email', env('NOTIFY_EMAIL', 'rado.rakotoarivelo@amws.space'));
        $processedCount = count($summary['processed']);
        $failedCount = count($summary['failed']);
        $skippedCount = count($summary['skipped']);

        $body  = "Résumé traitement emails news — " . now()->format('d/m/Y H:i') . "\n";
        $body .= str_repeat('=', 60) . "\n\n";
        $body .= "Traités : {$processedCount}\n";
        $body .= "Ignorés : {$skippedCount}\n";
        $body .= "Échecs  : {$failedCount}\n\n";

        if (!empty($summary['processed'])) {
            $body .= str_repeat('-', 60) . "\n";
            $body .= "MAILS TRAITÉS\n";
            $body .= str_repeat('-', 60) . "\n";
            foreach ($summary['processed'] as $item) {
                $body .= "- {$item['subject']}";
                if (($item['from'] ?? '') !== '') {
                    $body .= " | {$item['from']}";
                }
                $body .= "\n";
            }
            $body .= "\n";
        }

        if (!empty($summary['skipped'])) {
            $body .= str_repeat('-', 60) . "\n";
            $body .= "MAILS IGNORÉS (non pertinents / déjà traités)\n";
            $body .= str_repeat('-', 60) . "\n";
            foreach ($summary['skipped'] as $item) {
                $body .= "- {$item['subject']}";
                if (($item['from'] ?? '') !== '') {
                    $body .= " | {$item['from']}";
                }
                if (($item['reason'] ?? '') !== '') {
                    $body .= " | raison: {$item['reason']}";
                }
                $body .= "\n";
            }
            $body .= "\n";
        }

        if (!empty($summary['failed'])) {
            $body .= str_repeat('-', 60) . "\n";
            $body .= "MAILS EN ÉCHEC\n";
            $body .= str_repeat('-', 60) . "\n";
            foreach ($summary['failed'] as $item) {
                $body .= "- {$item['subject']}";
                if (($item['from'] ?? '') !== '') {
                    $body .= " | {$item['from']}";
                }
                if (($item['error'] ?? '') !== '') {
                    $body .= " | erreur: {$item['error']}";
                }
                $body .= "\n";
            }
            $body .= "\n";
        }

        try {
            Mail::raw($body, function ($message) use ($to) {
                $message->to($to)
                    ->subject('Résumé traitement emails news — ' . now()->format('d/m/Y H:i'));
            });
            Log::info("Processing summary email sent to {$to}");
        } catch (\Throwable $e) {
            Log::error('Failed to send processing summary email: ' . $e->getMessage());
        }
    }

    /**
     * Process French news
     */
    private function processFrenchNews(
        array $newsPayload,
        string $subject,
        string $messageId,
        ?string $imageUrl,
        string $contentBrut
    ): bool
    {
        return $this->processNewsFromWordPressPromptPayload('FR', $newsPayload, $subject, $messageId, $imageUrl, $contentBrut);
    }

    /**
     * Process English news
     */
    private function processEnglishNews(
        array $newsPayload,
        string $subject,
        string $messageId,
        ?string $imageUrl,
        string $contentBrut
    ): bool
    {
        return $this->processNewsFromWordPressPromptPayload('EN', $newsPayload, $subject, $messageId, $imageUrl, $contentBrut);
    }

    private function processNewsFromWordPressPromptPayload(
        string $lang,
        array $newsPayload,
        string $subject,
        string $messageId,
        ?string $imageUrl,
        string $contentBrut
    ): bool {
        try {
            $titleKey = $lang === 'FR' ? 'shorttitleFR' : 'shorttitleEN';
            $fallbackTitleKey = $lang === 'FR' ? 'titleFR' : 'titleEN';
            $contentKey = $lang;
            $metaKey = $lang === 'FR' ? 'metadescriptionFR' : 'metadescriptionEN';
            $focusKey = $lang === 'FR' ? 'focuskeyphraseFR' : 'focuskeyphraseEN';

            // IMPORTANT: no PHP-side cleanup/formatting; payload is assumed conform.
            $title = is_string($newsPayload[$titleKey] ?? null) ? $newsPayload[$titleKey] : '';
            if ($title === '') {
                $title = is_string($newsPayload[$fallbackTitleKey] ?? null) ? $newsPayload[$fallbackTitleKey] : '';
            }
            if ($title === '') {
                // No rewriting/cleanup in PHP; keep the original email subject as-is.
                $title = $subject;
            }

            $content = is_string($newsPayload[$contentKey] ?? null) ? $newsPayload[$contentKey] : '';
            if ($content === '') {
                Log::warning("Empty {$lang} content from WordPress prompt for email: {$messageId}");
                return false;
            }

            $meta = is_string($newsPayload[$metaKey] ?? null) ? $newsPayload[$metaKey] : '';
            $focus = is_string($newsPayload[$focusKey] ?? null) ? $newsPayload[$focusKey] : '';

            // Categories & tags remain computed from the final HTML content.
            $categories = $this->wordpressService->getCategoriesForClassification($lang);
            $categoriesString = $this->openaiService->classifyCategories($content, $categories, $lang);

            $tags = $this->wordpressService->getTagsForClassification($lang);
            $tagsString = $this->openaiService->classifyTags($content, $tags, $lang);
            $tagsString = $this->reorderSelectedTags($tagsString, $tags, $categories, $categoriesString, $content, $title);

            News::create([
                'lang' => $lang,
                'title' => $title,
                'content' => $content,
                'content_brut' => $contentBrut,
                'metadescription' => $meta,
                'focuskeyphrase' => $focus,
                'categories' => $categoriesString ?? '',
                'tags' => $tagsString ?? '',
                'image_url' => $imageUrl,
                'status' => News::STATUS_PENDING,
                'email_message_id' => $messageId,
            ]);

            Log::info("{$lang} news created successfully for email: {$messageId}");
            return true;
        } catch (\Throwable $e) {
            Log::error("Error processing {$lang} news from WordPress payload: " . $e->getMessage());
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

    private function fallbackTitle(string $subject, string $originalTitle, string $lang): string
    {
        $candidates = [
            $this->normalizeFallbackTitleCandidate($originalTitle),
            $this->normalizeFallbackTitleCandidate($subject),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if (mb_strlen($candidate) <= 62) {
                return $candidate;
            }

            $context = trim($subject . "\n" . $originalTitle);
            $rewritten = $this->openaiService->rewriteTitleToFitPublic(
                $candidate,
                $context !== '' ? $context : $candidate,
                $lang
            );

            if ($rewritten !== '' && mb_strlen($rewritten) <= 62) {
                return $rewritten;
            }

            // As a last resort: keep the full title (no truncation/cropping in PHP).
            return $candidate;
        }

        return '';
    }

    private function normalizeFallbackTitleCandidate(string $value): string
    {
        $clean = trim((string) preg_replace('/^(RE|FW|FWD|TR|CP)\s*:\s*/i', '', $value));
        $clean = preg_replace('/\s*[-|:]\s*(version|v)\s*\d+$/i', '', $clean) ?? $clean;
        return trim((string) $clean, " ,;:-");
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

        // No PHP truncation: prefer a short sentence if available, otherwise return as-is.
        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
        foreach ($sentences as $sentence) {
            $sentence = trim((string) $sentence);
            if ($sentence !== '' && mb_strlen($sentence) <= 141) {
                return $sentence;
            }
        }

        return $text;
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
            $plain = $this->normalizePlainTextContent($content);
            if ($plain === '') {
                return '';
            }

            return $this->convertPlainTextToHtmlFragment($plain);
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

        $body = '';
        $bodyNode = $dom->getElementsByTagName('body')->item(0);
        if ($bodyNode) {
            foreach ($bodyNode->childNodes as $child) {
                $body .= $dom->saveHTML($child);
            }
        }

        if ($body === '') {
            $body = $dom->saveHTML() ?: $clean;
        }

        // Keep original HTML structure (div/span/styles) so formatting is not destroyed.
        // Only minimal cleanup is applied above to remove unsafe/noisy blocks.
        $body = preg_replace('/<p>\s*<\/p>/i', '', $body) ?? $body;

        return trim($body);
    }

    private function convertPlainTextToHtmlFragment(string $plain): string
    {
        $paragraphs = preg_split('/\n{2,}/', $plain) ?: [];
        $html = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $lines = preg_split('/\n+/', $paragraph) ?: [];
            $listType = null;
            $buffer = [];

            $flushParagraph = function () use (&$html, &$buffer): void {
                if (!empty($buffer)) {
                    $html .= '<p>' . implode('<br>', $buffer) . '</p>';
                    $buffer = [];
                }
            };

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                if (preg_match('/^(?:[-*•])\s+(.+)$/u', $line, $matches)) {
                    $flushParagraph();
                    if ($listType !== 'ul') {
                        if ($listType !== null) {
                            $html .= "</{$listType}>";
                        }
                        $html .= '<ul>';
                        $listType = 'ul';
                    }
                    $html .= '<li>' . htmlspecialchars(trim($matches[1]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
                    continue;
                }

                if (preg_match('/^\d+[.)]\s+(.+)$/u', $line, $matches)) {
                    $flushParagraph();
                    if ($listType !== 'ol') {
                        if ($listType !== null) {
                            $html .= "</{$listType}>";
                        }
                        $html .= '<ol>';
                        $listType = 'ol';
                    }
                    $html .= '<li>' . htmlspecialchars(trim($matches[1]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
                    continue;
                }

                if ($listType !== null) {
                    $html .= "</{$listType}>";
                    $listType = null;
                }

                $buffer[] = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            $flushParagraph();

            if ($listType !== null) {
                $html .= "</{$listType}>";
            }
        }

        return $html;
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
            '/^top of form$/i',
            '/^bottom of form$/i',
            '/^posted by\s*:/i',
            '/^related articles$/i',
            '/^be the first to comment/i',
            '/^leave a comment$/i',
            '/^additional links$/i',
            '/^topics\s*:/i',
            '/^rechercher\s*:/i',
            '/^copyright\s+/i',
            '/^dernieres news$/i',
            '/^derni[eè]res news$/iu',
            '/^mots cl[eé]$/iu',
            '/^[éè]v[eè]nements [àa] venir$/iu',
            '/^flash news$/i',
            '/^facebook$/i',
            '/^twitter$/i',
            '/^linkedin$/i',
            '/^youtube$/i',
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
        $plainContent = $this->normalizePlainTextContent(strip_tags($content));
        $lines = preg_split('/\n+/', trim($plainContent)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (
                $line === ''
                || str_starts_with($line, '•')
                || $this->isInvalidTitleCandidate($line)
                || preg_match('/^aeromorning\b/i', $line)
                || preg_match('/^[0-9]+[.)]/', $line)
                || preg_match('/^(version|source)\b/i', $line)
                || $this->looksLikeAddress($line)
            ) {
                continue;
            }

            return trim(preg_replace('/\s+/', ' ', $line) ?? $line);
        }

        return $this->fallbackTitle($subject, '', $lang);
    }

    private function isInvalidTitleCandidate(string $line): bool
    {
        $candidate = trim($line);
        if ($candidate === '') {
            return true;
        }

        if ($this->isBoilerplateLine($candidate)) {
            return true;
        }

        $invalidPatterns = [
            '/^(top|bottom) of form$/i',
            '/^posted by\s*:/i',
            '/^related articles$/i',
            '/^source\s*:/i',
            '/^flash news$/i',
            '/^additional links$/i',
        ];

        foreach ($invalidPatterns as $pattern) {
            if (preg_match($pattern, $candidate) === 1) {
                return true;
            }
        }

        return mb_strlen($candidate) < 12;
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

        // 2. If content contains an explicit organization acronym (e.g., "... (VNH)"), prefer it.
        $firstChunk = (string) mb_substr($content, 0, 900);
        $stopAcronyms = [
            'CEO', 'CFO', 'CTO', 'COO', 'VP', 'SVP', 'EVP',
            'FAA', 'EASA', 'IATA', 'ICAO', 'EU', 'UK', 'US', 'UAE', 'NATO',
        ];

        if (preg_match_all('/\b([A-Z][A-Za-z&\'\".-]{1,}(?:\s+[A-Z][A-Za-z&\'\".-]{1,}){1,8})\s*\(([A-Z]{2,6})\)(?=\W|$)/u', $firstChunk, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $org = trim((string) ($match[1] ?? ''));
                $acro = trim((string) ($match[2] ?? ''));
                if ($acro !== '' && !in_array($acro, $stopAcronyms, true)) {
                    // Keep short acronyms as Source when they look like a real org handle.
                    return $acro;
                }

                if ($org !== '' && mb_strlen($org) >= 3 && mb_strlen($org) <= 50) {
                    return $org;
                }
            }
        }

        // 3. Scan content (first 1500 chars) for known organizations
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

        // 4. Fall back to clean domain from the email address
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

        $structured = $this->normalizeStructuredArticleHtml($base, $originalTitle, $lang);
        if ($structured === '') {
            $plain = trim(strip_tags($base));
            if ($plain === '') {
                $plain = trim($rawContent);
            }

            $structured = $this->convertPlainTextToStructuredHtml($plain, $originalTitle);
        }

        // Always enforce <h2>Original Title</h2> at the very top.
        // This prevents model output from using a different heading/title.
        $structured = preg_replace('/^\s*<h[1-6]\b[^>]*>.*?<\/h[1-6]>\s*/is', '', $structured) ?? $structured;
        $structured = '<h2>' . htmlspecialchars($originalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>' . $structured;

        $structured = $this->removeDuplicatedTitleInBody($structured, $originalTitle);

        $sourceLabel = 'Source: ';
        $hasSource = preg_match('/\bsource\s*:/i', $structured) === 1;
        if (!$hasSource) {
            $structured .= '<p>' . htmlspecialchars($sourceLabel . $sourceHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        return $structured;
    }

    private function removeDuplicatedTitleInBody(string $html, string $title): string
    {
        $normalizedTitle = mb_strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($title)) ?? ''));
        if ($normalizedTitle === '') {
            return $html;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $dom->getElementById('root');
        if (!$root) {
            return $html;
        }

        $seenH2 = false;
        $toRemove = [];
        foreach ($root->childNodes as $node) {
            if (!($node instanceof \DOMElement)) {
                continue;
            }

            if (strtolower($node->tagName) === 'h2' && !$seenH2) {
                $seenH2 = true;
                continue;
            }

            if (!$seenH2) {
                continue;
            }

            $rawNodeText = trim((string) (preg_replace('/\s+/', ' ', $node->textContent) ?? ''));
            $nodeText = mb_strtolower($rawNodeText);
            if (in_array(strtolower($node->tagName), ['p', 'h3', 'h4', 'strong'], true) && $nodeText === $normalizedTitle) {
                $toRemove[] = $node;
                continue;
            }

            if (in_array(strtolower($node->tagName), ['p', 'h3', 'h4'], true) && str_starts_with($nodeText, $normalizedTitle)) {
                $remainder = $rawNodeText;
                $titlePattern = preg_quote(trim(preg_replace('/\s+/', ' ', strip_tags($title)) ?? ''), '/');
                $titlePattern = preg_replace('/\s+/', '\\s+', $titlePattern) ?? $titlePattern;

                while (str_starts_with(mb_strtolower(trim((string) (preg_replace('/\s+/', ' ', $remainder) ?? ''))), $normalizedTitle)) {
                    $remainder = trim((string) preg_replace(
                        '/^' . $titlePattern . '\s*/iu',
                        '',
                        $remainder
                    ));
                }

                if ($remainder === '') {
                    $toRemove[] = $node;
                } else {
                    while ($node->firstChild) {
                        $node->removeChild($node->firstChild);
                    }
                    $node->appendChild($dom->createTextNode($remainder));
                }
                break;
            }

            break;
        }

        foreach ($toRemove as $node) {
            $node->parentNode?->removeChild($node);
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return trim($result);
    }

    private function looksLikeAddress(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        if (preg_match('/\b\d{3,}\b/', $text) === 1) {
            return true;
        }

        if (preg_match('/\b(route|rue|avenue|street|road|pointe|maurice|ile|island|po box)\b/i', $text) === 1) {
            return true;
        }

        $parts = array_filter(array_map('trim', explode(',', $text)), static fn ($part) => $part !== '');
        return count($parts) >= 3;
    }

    private function buildMetaDescriptionFromArticle(string $contentHtml, string $lang): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($contentHtml)) ?? '');
        $text = trim(preg_replace('/\bsource\s*:\s*[^\n.]+$/i', '', $text) ?? $text);

        if ($text === '') {
            return '';
        }

        // Target: 107–142 characters
        // If short text, return all (cannot expand without fabrication)
        if (mb_strlen($text) >= 107 && mb_strlen($text) <= 142) {
            return $text;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
        $meta = '';

        foreach ($sentences as $sentence) {
            $candidate = trim($meta === '' ? $sentence : ($meta . ' ' . $sentence));
            $candidateLen = mb_strlen($candidate);

            if ($candidateLen > 142) {
                if ($meta === '') {
                    $meta = $this->smartWordLimit($sentence, 142);
                }
                break;
            }

            $meta = $candidate;

            if ($candidateLen >= 107) {
                break;
            }
        }

        if (mb_strlen($meta) < 107 && mb_strlen($text) >= 107) {
            $meta = $this->smartWordLimit($text, 142);
        }

        if (mb_strlen($meta) < 107) {
            $suffix = $lang === 'FR'
                ? ' Les enjeux du secteur restent a suivre.'
                : ' The wider aviation impact remains worth watching.';
            if (!str_ends_with($meta, '.')) {
                $meta .= '.';
            }
            if (mb_strlen($meta . $suffix) <= 142) {
                $meta .= $suffix;
            }
        }

        return trim($meta);
    }

    private function buildFocusKeyphraseFromTitle(string $title, string $lang): string
    {
        $clean = trim(strip_tags($title));
        $clean = str_replace(',', ' ', $clean);
        $clean = preg_replace('/[;:.!?\/\\|]+/', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = preg_replace('/[;:!?]+$/', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(and|or|the|a|an|in|on|at|to|for|from|of|by)\b/i', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', trim($clean)) ?? trim($clean);

        if ($clean === '') {
            return $this->fallbackKeyphrase($title);
        }

        return $this->smartWordLimit($clean, 80, 5);
    }

    private function normalizeStructuredArticleHtml(string $html, string $title, string $lang): string
    {
        $html = trim($html);
        if ($html === '' || strpos($html, '<') === false) {
            return '';
        }

        // Minimal safety cleanup only (avoid stripping div/span/attributes which breaks formatting).
        $html = preg_replace('/<(html|head|body|style|script|table|tbody|thead|tfoot|tr|td|th)[^>]*>/i', '', $html) ?? $html;
        $html = preg_replace('/<\/(html|head|body|style|script|table|tbody|thead|tfoot|tr|td|th)>/i', '', $html) ?? $html;
        $html = preg_replace('/<p>\s*[-•*]\s*(.*?)<\/p>/iu', '<ul><li>$1</li></ul>', $html) ?? $html;
        $html = preg_replace('/<p>\s*\d+[.)]\s*(.*?)<\/p>/iu', '<ol><li>$1</li></ol>', $html) ?? $html;
        $html = preg_replace('/(?:<ul>\s*){2,}/i', '<ul>', $html) ?? $html;
        $html = preg_replace('/(?:<\/ul>\s*){2,}/i', '</ul>', $html) ?? $html;
        $html = preg_replace('/(?:<ol>\s*){2,}/i', '<ol>', $html) ?? $html;
        $html = preg_replace('/(?:<\/ol>\s*){2,}/i', '</ol>', $html) ?? $html;
        $html = trim($html);

        return $html;
    }

    private function convertPlainTextToStructuredHtml(string $plainText, string $title): string
    {
        $lines = preg_split('/\n+/', str_replace(["\r\n", "\r"], "\n", $plainText)) ?: [];
        $html = '';
        $listType = null;
        $headingAdded = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                if ($listType !== null) {
                    $html .= "</{$listType}>";
                    $listType = null;
                }
                continue;
            }

            if (preg_match('/^[•*\-]\s+(.+)$/u', $line, $matches)) {
                if ($listType !== 'ul') {
                    if ($listType !== null) {
                        $html .= "</{$listType}>";
                    }
                    $html .= '<ul>';
                    $listType = 'ul';
                }
                $html .= '<li>' . htmlspecialchars($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
                continue;
            }

            if (preg_match('/^\d+[.)]\s+(.+)$/u', $line, $matches)) {
                if ($listType !== 'ol') {
                    if ($listType !== null) {
                        $html .= "</{$listType}>";
                    }
                    $html .= '<ol>';
                    $listType = 'ol';
                }
                $html .= '<li>' . htmlspecialchars($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
                continue;
            }

            if ($listType !== null) {
                $html .= "</{$listType}>";
                $listType = null;
            }

            if (!$headingAdded) {
                $html .= '<h2>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
                $headingAdded = true;
            }

            if ($this->looksLikeSectionHeading($line)) {
                $html .= '<h3>' . htmlspecialchars(rtrim($line, ':'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>';
                continue;
            }

            $html .= '<p>' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        if ($listType !== null) {
            $html .= "</{$listType}>";
        }

        return $html;
    }

    private function looksLikeSectionHeading(string $line): bool
    {
        return mb_strlen($line) <= 90
            && preg_match('/^[A-Z0-9][^.!?]{3,90}$/u', $line) === 1
            && str_word_count($line) <= 10;
    }

    private function reorderSelectedTags(?string $tagIds, array $allTags, array $allCategories, ?string $categoryIds, string $contentHtml, string $title): string
    {
        $selectedIds = array_values(array_filter(explode(',', (string) $tagIds), static fn ($id) => $id !== ''));
        if (empty($selectedIds)) {
            return '';
        }

        $tagMap = [];
        foreach ($allTags as $tag) {
            $tagMap[(string) $tag['wp_id']] = $tag;
        }

        $selectedCategoryIds = array_values(array_filter(explode(',', (string) $categoryIds), static fn ($id) => $id !== ''));
        $selectedCategoryNames = [];
        foreach ($allCategories as $category) {
            if (in_array((string) $category['wp_id'], $selectedCategoryIds, true)) {
                $selectedCategoryNames[] = $this->normalizeSearchText((string) $category['categ_name']);
            }
        }

        $articleText = $this->normalizeSearchText($title . ' ' . strip_tags($contentHtml));
        $ordered = [];
        $seenTagNames = [];

        foreach ($selectedIds as $index => $tagId) {
            if (!isset($tagMap[$tagId])) {
                continue;
            }

            $tagName = (string) $tagMap[$tagId]['tag_name'];
            $normalizedTag = $this->normalizeSearchText($tagName);
            $canonicalTag = $this->canonicalizeTagName($normalizedTag);

            if ($canonicalTag === '' || isset($seenTagNames[$canonicalTag])) {
                continue;
            }

            $priority = 3;
            foreach ($selectedCategoryNames as $categoryName) {
                if ($categoryName !== '' && ($normalizedTag === $categoryName || str_contains($normalizedTag, $categoryName) || str_contains($categoryName, $normalizedTag))) {
                    $priority = 0;
                    break;
                }
            }

            if ($priority > 0 && $this->looksLikeLocationTag($normalizedTag, $articleText)) {
                $priority = 1;
            }

            if ($priority > 1 && $this->looksLikeOrganizationTag($normalizedTag, $articleText)) {
                $priority = 2;
            }

            $ordered[] = [
                'id' => $tagId,
                'priority' => $priority,
                'position' => $index,
            ];

            $seenTagNames[$canonicalTag] = true;
        }

        usort($ordered, static function (array $left, array $right): int {
            return [$left['priority'], $left['position']] <=> [$right['priority'], $right['position']];
        });

        return implode(',', array_map(static fn (array $item) => $item['id'], $ordered));
    }

    private function canonicalizeTagName(string $tagName): string
    {
        $tagName = trim($tagName);
        if ($tagName === '') {
            return '';
        }

        $tagName = preg_replace('/\b(news|actualites|actualite)\b/iu', '', $tagName) ?? $tagName;
        $tagName = preg_replace('/\s+/', ' ', trim($tagName)) ?? trim($tagName);
        $tagName = preg_replace('/s\b/u', '', $tagName) ?? $tagName;

        return trim($tagName);
    }

    private function normalizeSearchText(string $text): string
    {
        $text = Str::lower(trim(strip_tags($text)));
        $text = preg_replace('/[^\pL\pN]+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function looksLikeLocationTag(string $tagName, string $articleText): bool
    {
        if ($tagName === '' || !str_contains(' ' . $articleText . ' ', ' ' . $tagName . ' ')) {
            return false;
        }

        if (preg_match('/\b(airport|aeroport|aéroport|city|ville|country|pays|region|région|hub)\b/u', $tagName) === 1) {
            return true;
        }

        return preg_match('/\b(in|at|from|to|near|via|en|a|au|aux|dans|depuis|vers)\s+' . preg_quote($tagName, '/') . '\b/u', $articleText) === 1;
    }

    private function looksLikeOrganizationTag(string $tagName, string $articleText): bool
    {
        if ($tagName === '' || !str_contains(' ' . $articleText . ' ', ' ' . $tagName . ' ')) {
            return false;
        }

        return preg_match('/\b(airlines?|airways?|airbus|boeing|embraer|atr|faa|easa|iata|nasa|group|groupe|company|compagnie|agency|agence|corporation|corp|inc|ltd|sa|sas|plc)\b/u', $tagName) === 1;
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

    private function isForbiddenSignatureImageUrl(string $url): bool
    {
        $url = strtolower($url);
        return str_contains($url, 'cid:')
            || str_contains($url, 'amws')
            || str_contains($url, 'amltd');
    }

    private function extractInlineCidFilenames(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $matches = [];
        preg_match_all('/\bcid:([^"\'\s>]+)/i', $html, $matches);
        $values = $matches[1] ?? [];

        $filenames = [];
        foreach ($values as $value) {
            $value = (string) $value;
            $value = explode('@', $value, 2)[0];
            $value = trim($value);
            if ($value !== '') {
                $filenames[strtolower($value)] = true;
            }
        }

        return array_keys($filenames);
    }

    private function selectBestImageAttachment(array $attachments, array $inlineCidFilenames = []): ?object
    {
        $bestAttachment = null;
        $bestScore = -1;

        $inlineCidLookup = [];
        foreach ($inlineCidFilenames as $name) {
            $inlineCidLookup[strtolower((string) $name)] = true;
        }

        foreach ($attachments as $attachment) {
            $mimeType = strtolower((string) ($attachment->mimeType ?? $attachment->mime ?? ''));
            $filePath = $attachment->filePath ?? null;

            if (!$filePath || !str_contains($mimeType, 'image')) {
                continue;
            }

            $name = strtolower((string) ($attachment->name ?? basename((string) $filePath)));

            // Signature/inline images are typically referenced as cid:... in the HTML.
            if ($name !== '' && isset($inlineCidLookup[$name])) {
                continue;
            }

            if (str_contains($name, 'amws') || str_contains($name, 'amltd')) {
                continue;
            }

            $size = (int) ($attachment->sizeInBytes ?? @filesize($filePath) ?: 0);
            $score = $size;

            if (preg_match('/logo|icon|signature|linkedin|facebook|twitter|instagram|header|footer/', $name)) {
                $score -= 500000;
            }

            if (preg_match('/amws|endless|possibilities|constellation/', $name)) {
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
