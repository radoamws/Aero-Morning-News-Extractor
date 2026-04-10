<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Services\ProcessLogService;
use App\Services\WordPressPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WordPressPostingController extends Controller
{
    /**
     * Post news to WordPress
     */
    public function postNews(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'username' => 'required|string',
                'password' => 'required|string'
            ]);

            $news = News::findOrFail($id);

            // Update status to syncing
            $news->status = News::STATUS_SYNCING;
            $news->save();

            // Create posting service for the appropriate language
            $postingService = new WordPressPostingService($news->lang);

            // Post to WordPress
            $postId = $postingService->postToWordPress(
                $news,
                $validated['username'],
                $validated['password']
            );

            if ($postId) {
                // Mark as synced
                $news->status = News::STATUS_SYNCED;
                $news->save();

                return response()->json([
                    'success' => true,
                    'message' => 'News posted to WordPress successfully',
                    'wordpress_post_id' => $postId,
                    'data' => $news
                ]);
            } else {
                $news->status = News::STATUS_PENDING;
                $news->save();

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to post news to WordPress'
                ], 500);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Error posting news to WordPress: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk post news to WordPress
     */
    public function bulkPostNews(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'news_ids' => 'required|array',
                'username' => 'required|string',
                'password' => 'required|string'
            ]);

            $successCount = 0;
            $failedCount = 0;

            foreach ($validated['news_ids'] as $newsId) {
                try {
                    $news = News::findOrFail($newsId);
                    $postingService = new WordPressPostingService($news->lang);
                    
                    $postId = $postingService->postToWordPress(
                        $news,
                        $validated['username'],
                        $validated['password']
                    );

                    if ($postId) {
                        $news->status = News::STATUS_SYNCED;
                        $news->save();
                        $successCount++;
                    } else {
                        $failedCount++;
                    }
                } catch (\Exception $e) {
                    Log::error("Error posting news $newsId: " . $e->getMessage());
                    $failedCount++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Bulk posting completed',
                'success_count' => $successCount,
                'failed_count' => $failedCount
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Error in bulk posting: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Preview news before posting
     */
    public function previewNews($id): JsonResponse
    {
        try {
            $news = News::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'title' => $news->title,
                    'excerpt' => $news->metadescription,
                    'content' => $news->content,
                    'featured_image' => $news->image_url,
                    'categories' => $news->getCategoriesArray(),
                    'tags' => $news->getTagsArray(),
                    'focus_keyphrase' => $news->focuskeyphrase,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'News not found'
            ], 404);
        }
    }

    /**
     * Get news statistics
     */
    public function newsStats(): JsonResponse
    {
        try {
            $stats = [
                'total' => News::count(),
                'by_status' => [
                    'pending' => News::where('status', News::STATUS_PENDING)->count(),
                    'syncing' => News::where('status', News::STATUS_SYNCING)->count(),
                    'synced' => News::where('status', News::STATUS_SYNCED)->count(),
                ],
                'by_language' => [
                    'FR' => News::where('lang', 'FR')->count(),
                    'EN' => News::where('lang', 'EN')->count(),
                ],
                'by_status_and_language' => [
                    'FR_pending' => News::where('lang', 'FR')->where('status', News::STATUS_PENDING)->count(),
                    'FR_synced' => News::where('lang', 'FR')->where('status', News::STATUS_SYNCED)->count(),
                    'EN_pending' => News::where('lang', 'EN')->where('status', News::STATUS_PENDING)->count(),
                    'EN_synced' => News::where('lang', 'EN')->where('status', News::STATUS_SYNCED)->count(),
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Publish all pending (status=0) news to WordPress automatically.
     * Uses the Application Password stored in .env — no credentials in the request.
     * Sends an email summary at the end.
     */
    public function publishPendingNews(): JsonResponse
    {
        $processLog = null;
        $processLogService = app(ProcessLogService::class);

        try {
            @ini_set('max_execution_time', '300');
            @set_time_limit(300);

            $processLog = $processLogService->startRun('publish_pending', [
                'source' => 'api',
            ]);

            $pendingNews = News::where('status', News::STATUS_PENDING)->get();

            if ($pendingNews->isEmpty()) {
                if ($processLog) {
                    $processLogService->finishRun($processLog, ProcessLogService::STATUS_SUCCESS, [
                        'published' => 0,
                        'failed' => 0,
                        'note' => 'No pending news to publish',
                    ], 'No pending news to publish.');
                }
                return response()->json([
                    'success' => true,
                    'message' => 'No pending news to publish.',
                    'published' => 0,
                    'failed'    => 0,
                ]);
            }

            $results = ['success' => [], 'failed' => []];

            foreach ($pendingNews as $news) {
                // Mark as syncing
                $news->status = News::STATUS_SYNCING;
                $news->save();

                try {
                    $service = new WordPressPostingService($news->lang);
                    $result  = $service->publishToWordPress($news);

                    if ($result['success']) {
                        $news->status = News::STATUS_SYNCED;
                        $news->save();

                        $results['success'][] = [
                            'id'         => $news->id,
                            'lang'       => $news->lang,
                            'title'      => $news->title,
                            'wp_post_id' => $result['wp_post_id'],
                        ];
                    } else {
                        $results['failed'][] = [
                            'id'    => $news->id,
                            'lang'  => $news->lang,
                            'title' => $news->title,
                            'error' => $result['error'] ?? 'Unknown error',
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::error("Error publishing news #{$news->id}: " . $e->getMessage());
                    $results['failed'][] = [
                        'id'    => $news->id,
                        'lang'  => $news->lang,
                        'title' => $news->title,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // Send summary email
            $this->sendPublishSummaryEmail($results);

            if ($processLog) {
                $failedCount = count($results['failed']);
                $status = $failedCount > 0
                    ? ProcessLogService::STATUS_PARTIAL
                    : ProcessLogService::STATUS_SUCCESS;

                $processLogService->finishRun($processLog, $status, [
                    'published' => count($results['success']),
                    'failed' => $failedCount,
                    'failed_items' => $results['failed'],
                ], 'Publishing completed.');
            }

            return response()->json([
                'success'   => true,
                'message'   => 'Publishing completed.',
                'published' => count($results['success']),
                'failed'    => count($results['failed']),
                'details'   => $results,
            ]);

        } catch (\Throwable $e) {
            Log::error("Fatal error in publishPendingNews: " . $e->getMessage());

            if ($processLog) {
                $processLogService->failRun($processLog, $e, [
                    'note' => 'Fatal error in publishPendingNews',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // -------------------------------------------------------------------------

    private function sendPublishSummaryEmail(array $results): void
    {
        $to           = config('services.notify.email', env('NOTIFY_EMAIL', 'rado.rakotoarivelo@amws.space'));
        $successCount = count($results['success']);
        $failedCount  = count($results['failed']);
        $total        = $successCount + $failedCount;
        $remainingPending = News::where('status', News::STATUS_PENDING)->count();

        $body  = "Résumé de publication WordPress — " . now()->format('d/m/Y H:i') . "\n";
        $body .= str_repeat('=', 60) . "\n\n";
        $body .= "Total traité dans ce batch : {$total}\n";
        $body .= "  ✓ Publiées avec succès (status 2) : {$successCount}\n";
        $body .= "  ✗ En échec            (status 1) : {$failedCount}\n";

        if ($remainingPending > 0) {
            $body .= "  ⚠ Non traitées         (status 0) : {$remainingPending}\n";
        }

        $body .= "\n";

        if (!empty($results['success'])) {
            $body .= str_repeat('-', 60) . "\n";
            $body .= "NEWS PUBLIÉES (status 2)\n";
            $body .= str_repeat('-', 60) . "\n";
            foreach ($results['success'] as $item) {
                $body .= "  [#{$item['id']}] [{$item['lang']}] {$item['title']}\n";
                $body .= "        → WP post ID : {$item['wp_post_id']}\n";
            }
            $body .= "\n";
        }

        if (!empty($results['failed'])) {
            $body .= str_repeat('-', 60) . "\n";
            $body .= "NEWS EN ÉCHEC (status 1)\n";
            $body .= str_repeat('-', 60) . "\n";
            foreach ($results['failed'] as $item) {
                $body .= "  [#{$item['id']}] [{$item['lang']}] {$item['title']}\n";
                $body .= "        → Erreur : {$item['error']}\n";
            }
        }

        try {
            Mail::raw($body, function ($message) use ($to) {
                $message->to($to)
                        ->subject('Résumé publication WordPress — ' . now()->format('d/m/Y H:i'));
            });
            Log::info("Publish summary email sent to {$to}");
        } catch (\Throwable $e) {
            Log::error("Failed to send publish summary email: " . $e->getMessage());
        }
    }
}
