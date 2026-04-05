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

class NewsController extends Controller
{
    private EmailService $emailService;
    private ImageService $imageService;
    private OpenAIService $openaiService;
    private WordPressService $wordpressService;

    public function __construct(
        EmailService $emailService,
        ImageService $imageService,
        OpenAIService $openaiService,
        WordPressService $wordpressService
    ) {
        $this->emailService = $emailService;
        $this->imageService = $imageService;
        $this->openaiService = $openaiService;
        $this->wordpressService = $wordpressService;
    }

    /**
     * Sync WordPress categories and tags
     */
    public function syncWordPressData(): JsonResponse
    {
        try {
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
        } catch (\Exception $e) {
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
            $unreadEmails = $this->emailService->getUnreadEmails();

            if (empty($unreadEmails)) {
                return response()->json([
                    'success' => true,
                    'message' => 'No unread emails to process',
                    'processed' => 0
                ]);
            }

            $processedCount = 0;
            $failedCount = 0;

            foreach ($unreadEmails as $mail) {
                try {
                    if ($this->processSingleEmail($mail)) {
                        $processedCount++;
                    } else {
                        $failedCount++;
                    }
                } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
            if (News::where('email_message_id', $emailContent['message_id'])->exists()) {
                Log::info("Email already processed: " . $emailContent['message_id']);
                return true;
            }

            // Extract or download image
            $imageUrl = null;
            if ($this->emailService->hasAttachments($mail)) {
                $attachments = $mail->getAttachments();
                foreach ($attachments as $attachment) {
                    if (strpos($attachment->mimeType, 'image') !== false) {
                        $imageUrl = $this->imageService->processAttachmentImage(
                            $attachment->filePath,
                            $emailContent['subject']
                        );
                        break;
                    }
                }
            } else {
                $foundImageUrl = $this->emailService->extractImageUrlFromHtml($emailContent['html_body']);
                if ($foundImageUrl && $this->imageService->isValidImageUrl($foundImageUrl)) {
                    $imageUrl = $this->imageService->downloadAndOptimizeImage(
                        $foundImageUrl,
                        $emailContent['subject']
                    );
                }
            }

            Log::info("Email subject: " . $emailContent['subject']);
            Log::info("Has attachment: " . ($this->emailService->hasAttachments($mail) ? 'yes' : 'no'));

            // Prepare content for OpenAI
            $bodyContent = !empty($emailContent['html_body']) 
                ? $emailContent['html_body'] 
                : $emailContent['text_body'];

            // Detect language from content
            $language = $this->detectLanguage($bodyContent);

            // Generate French news if content contains French
            if (in_array('FR', $language)) {
                $this->processFrenchNews(
                    $bodyContent,
                    $emailContent['subject'],
                    $emailContent['message_id'],
                    $imageUrl
                );
            }

            // Generate English news if content contains English
            if (in_array('EN', $language)) {
                $this->processEnglishNews(
                    $bodyContent,
                    $emailContent['subject'],
                    $emailContent['message_id'],
                    $imageUrl
                );
            }

            // Mark email as read
            $this->emailService->markAsRead($mail->id);

            return true;
        } catch (\Exception $e) {
            Log::error("Error processing single email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process French news
     */
    private function processFrenchNews(string $content, string $subject, string $messageId, ?string $imageUrl): void
    {
        try {
            // Generate French metadata
            $titleFr = $this->openaiService->generateFrenchTitle($content);
            if (!$titleFr) {
                Log::warning("Failed to generate French title");
                return;
            }

            $contentFr = $this->openaiService->generateFrenchContent($content, $titleFr);
            if (!$contentFr) {
                Log::warning("Failed to generate French content");
                return;
            }

            $metaDescFr = $this->openaiService->generateFrenchMetaDescription($contentFr);
            $keyPhraseFr = $this->openaiService->generateFrenchKeyphrase($contentFr);

            // Get categories and tags
            $categoriesFr = $this->wordpressService->getCategoriesForClassification('FR');
            $categoriesString = $this->openaiService->classifyCategories($contentFr, $categoriesFr, 'FR');

            $tagsFr = $this->wordpressService->getTagsForClassification('FR');
            $tagsString = $this->openaiService->classifyTags($contentFr, $tagsFr, 'FR');

            // Save to database
            News::create([
                'lang' => 'FR',
                'title' => $titleFr,
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
        } catch (\Exception $e) {
            Log::error("Error processing French news: " . $e->getMessage());
        }
    }

    /**
     * Process English news
     */
    private function processEnglishNews(string $content, string $subject, string $messageId, ?string $imageUrl): void
    {
        try {
            // Generate English metadata
            $titleEn = $this->openaiService->generateEnglishTitle($content);
            if (!$titleEn) {
                Log::warning("Failed to generate English title");
                return;
            }

            $contentEn = $this->openaiService->generateEnglishContent($content, $titleEn);
            if (!$contentEn) {
                Log::warning("Failed to generate English content");
                return;
            }

            $metaDescEn = $this->openaiService->generateEnglishMetaDescription($contentEn);
            $keyPhraseEn = $this->openaiService->generateEnglishKeyphrase($contentEn);

            // Get categories and tags
            $categoriesEn = $this->wordpressService->getCategoriesForClassification('EN');
            $categoriesString = $this->openaiService->classifyCategories($contentEn, $categoriesEn, 'EN');

            $tagsEn = $this->wordpressService->getTagsForClassification('EN');
            $tagsString = $this->openaiService->classifyTags($contentEn, $tagsEn, 'EN');

            // Save to database
            News::create([
                'lang' => 'EN',
                'title' => $titleEn,
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
        } catch (\Exception $e) {
            Log::error("Error processing English news: " . $e->getMessage());
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
