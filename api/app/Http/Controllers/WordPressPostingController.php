<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\ProcessLog;
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

            // Ensure we don't leave the item stuck in STATUS_SYNCING.
            try {
                if (isset($news) && $news instanceof News) {
                    $news->status = News::STATUS_PENDING;
                    $news->save();
                }
            } catch (\Throwable $_) {
                // ignore secondary persistence failures
            }

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
    public function publishPendingNews(Request $request): JsonResponse
    {
        $processLog = null;
        $processLogService = app(ProcessLogService::class);

        $debug = $request->boolean('debug');

        try {
            $startedAt = microtime(true);
            $httpMaxSeconds = (int) env('PUBLISH_PENDING_HTTP_MAX_SECONDS', 900);
            if (app()->runningInConsole()) {
                @ini_set('max_execution_time', '0');
                @set_time_limit(0);
                $deadline = PHP_FLOAT_MAX;
            } else {
                if ($httpMaxSeconds <= 0) {
                    @ini_set('max_execution_time', '0');
                    @set_time_limit(0);
                    $deadline = PHP_FLOAT_MAX;
                } else {
                    @ini_set('max_execution_time', (string) $httpMaxSeconds);
                    @set_time_limit($httpMaxSeconds);
                    // Stop a bit before the PHP time limit to avoid fatal errors.
                    $deadline = $startedAt + max(1, $httpMaxSeconds - 15);
                }
            }

            $abortedDueToTimeBudget = false;

            $processLog = $processLogService->startRun('publish_pending', [
                'source' => 'api',
            ]);

            // Auto-recover from previous interrupted runs: revert old "syncing" items to pending.
            // Status=SYNCING must be transient; leaving items at 1 would block future runs.
            $resetBeforeRun = News::where('status', News::STATUS_SYNCING)
                ->update(['status' => News::STATUS_PENDING]);

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
                if (!app()->runningInConsole() && microtime(true) >= $deadline) {
                    $abortedDueToTimeBudget = true;
                    Log::warning('publishPendingNews stopped early due to HTTP time budget', [
                        'published' => count($results['success']),
                        'failed' => count($results['failed']),
                        'elapsed_seconds' => round(microtime(true) - $startedAt, 2),
                        'http_max_seconds' => $httpMaxSeconds,
                    ]);
                    break;
                }

                // Mark as syncing
                $news->status = News::STATUS_SYNCING;
                $news->save();

                try {
                    $service = new WordPressPostingService($news->lang);
                    $result  = $service->publishToWordPress($news);

                    if ($result['success']) {
                        $news->status = News::STATUS_SYNCED;
                        $news->save();

                        $successItem = [
                            'id'         => $news->id,
                            'lang'       => $news->lang,
                            'title'      => $news->title,
                            'wp_post_id' => $result['wp_post_id'],
                        ];
                        if ($debug) {
                            $successItem['debug'] = $result['details'] ?? null;
                        }
                        $results['success'][] = $successItem;
                    } else {
                        // Revert to pending so it can be retried later.
                        $news->status = News::STATUS_PENDING;
                        $news->save();

                        $failedItem = [
                            'id'    => $news->id,
                            'lang'  => $news->lang,
                            'title' => $news->title,
                            'error' => $result['error'] ?? 'Unknown error',
                        ];
                        if ($debug) {
                            $failedItem['debug'] = $result['details'] ?? null;
                        }
                        $results['failed'][] = $failedItem;
                    }
                } catch (\Throwable $e) {
                    Log::error("Error publishing news #{$news->id}: " . $e->getMessage());

                    // Revert to pending so it can be retried later.
                    try {
                        $news->status = News::STATUS_PENDING;
                        $news->save();
                    } catch (\Throwable $_) {
                        // ignore secondary persistence failures
                    }

                    $failedItem = [
                        'id'    => $news->id,
                        'lang'  => $news->lang,
                        'title' => $news->title,
                        'error' => $e->getMessage(),
                    ];
                    if ($debug) {
                        $failedItem['debug'] = [
                            'exception' => [
                                'class' => get_class($e),
                                'message' => $e->getMessage(),
                            ],
                        ];
                    }
                    $results['failed'][] = $failedItem;
                }
            }

            // Send summary email
            $this->sendPublishSummaryEmail($results);

            // Purge Cloudflare homepage cache once, after all articles are processed
            $cfPurgeResult = null;
            if (count($results['success']) > 0) {
                $cfPurgeResult = app(\App\Services\CloudflareService::class)->purgeHomepage();
            }

            if ($processLog) {
                $failedCount = count($results['failed']);
                $status = $failedCount > 0
                    ? ProcessLogService::STATUS_PARTIAL
                    : ProcessLogService::STATUS_SUCCESS;

                if ($abortedDueToTimeBudget) {
                    $status = ProcessLogService::STATUS_PARTIAL;
                }

                $processLogService->finishRun($processLog, $status, [
                    'published' => count($results['success']),
                    'failed' => $failedCount,
                    'aborted_due_to_time_budget' => $abortedDueToTimeBudget,
                    'http_max_seconds' => app()->runningInConsole() ? null : $httpMaxSeconds,
                    'failed_items' => $results['failed'],
                ], 'Publishing completed.');
            }

            return response()->json([
                'success'   => true,
                'message'   => $abortedDueToTimeBudget ? 'Publishing stopped early (time budget reached).' : 'Publishing completed.',
                'published' => count($results['success']),
                'failed'    => count($results['failed']),
                'aborted_due_to_time_budget' => $abortedDueToTimeBudget,
                'details'   => $results,
                'cloudflare_purge' => $cfPurgeResult,
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

    /**
     * Manually repush SEO metadata for all news in descending ID order.
     * Uses a checkpoint so the next launch can resume after interruptions.
     */
    public function repushAllSeoMeta(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:1000',
            'resume_after_news_id' => 'nullable|integer|min:1',
        ]);

        $limit = (int) ($validated['limit'] ?? 200);
        $requestedResumeAfterId = isset($validated['resume_after_news_id'])
            ? (int) $validated['resume_after_news_id']
            : null;

        $processLogService = app(ProcessLogService::class);
        $checkpoint = $this->getSeoRepushCheckpoint();
        $resumeAfterId = $requestedResumeAfterId
            ?? ($checkpoint['next_resume_after_news_id'] ?? null);

        $processLog = $processLogService->startRun('repush_seo_meta', [
            'source' => 'api',
            'details' => [
                'resume_after_news_id' => $resumeAfterId,
                'checkpoint_log_id' => $checkpoint['log_id'] ?? null,
                'limit' => $limit,
            ],
        ]);

        $startedAt = microtime(true);
        $httpMaxSeconds = (int) env('SEO_META_REPUSH_HTTP_MAX_SECONDS', 120);
        if ($httpMaxSeconds <= 0) {
            @ini_set('max_execution_time', '0');
            @set_time_limit(0);
            $deadline = PHP_FLOAT_MAX;
        } else {
            @ini_set('max_execution_time', (string) $httpMaxSeconds);
            @set_time_limit($httpMaxSeconds);
            $deadline = $startedAt + max(1, $httpMaxSeconds - 10);
        }

        $query = News::query()->orderByDesc('id');
        if (is_int($resumeAfterId) && $resumeAfterId > 0) {
            $query->where('id', '<', $resumeAfterId);
        }

        $targets = $query->limit($limit)->get();

        if ($targets->isEmpty()) {
            $details = [
                'processed' => 0,
                'updated' => 0,
                'not_found' => 0,
                'failed' => 0,
                'next_resume_after_news_id' => null,
                'has_more' => false,
                'resume_after_news_id' => $resumeAfterId,
            ];

            $processLogService->finishRun(
                $processLog,
                ProcessLogService::STATUS_SUCCESS,
                $details,
                'No news left to repush SEO metadata.'
            );

            return response()->json([
                'success' => true,
                'message' => 'No news left to repush SEO metadata.',
                'data' => $details,
            ]);
        }

        $results = [
            'processed' => 0,
            'updated' => 0,
            'not_found' => 0,
            'failed' => 0,
            'items' => [],
        ];

        $abortedDueToTimeBudget = false;
        $lastProcessedNewsId = null;
        $lastCheckpointNewsId = null;

        foreach ($targets as $news) {
            if (microtime(true) >= $deadline) {
                $abortedDueToTimeBudget = true;
                break;
            }

            $service = new WordPressPostingService($news->lang);
            $repushResult = $service->repushSeoMetaForNews($news);

            $results['processed']++;
            $lastProcessedNewsId = (int) $news->id;

            if (($repushResult['success'] ?? false) === true) {
                $results['updated']++;
                $lastCheckpointNewsId = (int) $news->id;
            } elseif (($repushResult['error'] ?? null) === 'wordpress_post_not_found') {
                $results['not_found']++;
                // Consider not_found as processed for resume progression.
                $lastCheckpointNewsId = (int) $news->id;
            } else {
                $results['failed']++;
            }

            $results['items'][] = [
                'news_id' => $news->id,
                'lang' => $news->lang,
                'title' => $news->title,
                'wp_post_id' => $repushResult['wp_post_id'] ?? null,
                'success' => (bool) ($repushResult['success'] ?? false),
                'error' => $repushResult['error'] ?? null,
            ];
        }

        $hasMore = false;
        if (is_int($lastCheckpointNewsId) && $lastCheckpointNewsId > 0) {
            $hasMore = News::where('id', '<', $lastCheckpointNewsId)->exists();
        } elseif (is_int($lastProcessedNewsId) && $lastProcessedNewsId > 0) {
            // We processed items but none are checkpoint-safe (all failed): keep resumable state.
            $hasMore = true;
        }

        $nextResumeAfterNewsId = null;
        if ($hasMore || $abortedDueToTimeBudget) {
            if (is_int($lastCheckpointNewsId) && $lastCheckpointNewsId > 0) {
                $nextResumeAfterNewsId = $lastCheckpointNewsId;
            } elseif (is_int($resumeAfterId) && $resumeAfterId > 0) {
                $nextResumeAfterNewsId = $resumeAfterId;
            }
        }

        $status = ProcessLogService::STATUS_SUCCESS;
        if ($results['failed'] > 0 || $hasMore || $abortedDueToTimeBudget) {
            $status = ProcessLogService::STATUS_PARTIAL;
        }

        $details = [
            'processed' => $results['processed'],
            'updated' => $results['updated'],
            'not_found' => $results['not_found'],
            'failed' => $results['failed'],
            'aborted_due_to_time_budget' => $abortedDueToTimeBudget,
            'next_resume_after_news_id' => $nextResumeAfterNewsId,
            'resume_after_news_id' => $resumeAfterId,
            'has_more' => $hasMore,
            'limit' => $limit,
            'items' => $results['items'],
        ];

        $processLogService->finishRun(
            $processLog,
            $status,
            $details,
            $status === ProcessLogService::STATUS_SUCCESS
                ? 'SEO metadata repush completed.'
                : 'SEO metadata repush partially completed.'
        );

        return response()->json([
            'success' => true,
            'message' => $status === ProcessLogService::STATUS_SUCCESS
                ? 'SEO metadata repush completed.'
                : 'SEO metadata repush partially completed. You can resume from checkpoint.',
            'data' => $details,
        ]);
    }

    /**
     * Get latest repush status and resumable checkpoint.
     */
    public function repushSeoMetaStatus(): JsonResponse
    {
        $latest = ProcessLog::query()
            ->where('process_type', 'repush_seo_meta')
            ->orderByDesc('id')
            ->first();

        $checkpoint = $this->getSeoRepushCheckpoint();

        return response()->json([
            'success' => true,
            'data' => [
                'latest_run' => $latest,
                'checkpoint' => $checkpoint,
            ],
        ]);
    }

    /**
     * Batch reindex of Yoast scores via custom endpoint.
     */
    public function reindexYoastScores(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:1000',
            'resume_after_news_id' => 'nullable|integer|min:1',
        ]);

        $limit = (int) ($validated['limit'] ?? 200);
        $requestedResumeAfterId = isset($validated['resume_after_news_id'])
            ? (int) $validated['resume_after_news_id']
            : null;

        $processType = 'yoast_reindex_scores';
        $processLogService = app(ProcessLogService::class);
        $checkpoint = $this->getCheckpointByProcessType($processType);
        $resumeAfterId = $requestedResumeAfterId
            ?? ($checkpoint['next_resume_after_news_id'] ?? null);

        $processLog = $processLogService->startRun($processType, [
            'source' => 'api',
            'details' => [
                'resume_after_news_id' => $resumeAfterId,
                'checkpoint_log_id' => $checkpoint['log_id'] ?? null,
                'limit' => $limit,
            ],
        ]);

        $startedAt = microtime(true);
        $httpMaxSeconds = (int) env('YOAST_REINDEX_HTTP_MAX_SECONDS', 120);
        if ($httpMaxSeconds <= 0) {
            @ini_set('max_execution_time', '0');
            @set_time_limit(0);
            $deadline = PHP_FLOAT_MAX;
        } else {
            @ini_set('max_execution_time', (string) $httpMaxSeconds);
            @set_time_limit($httpMaxSeconds);
            $deadline = $startedAt + max(1, $httpMaxSeconds - 10);
        }

        $query = News::query()->orderByDesc('id');
        if (is_int($resumeAfterId) && $resumeAfterId > 0) {
            $query->where('id', '<', $resumeAfterId);
        }

        $targets = $query->limit($limit)->get();

        if ($targets->isEmpty()) {
            $details = [
                'processed' => 0,
                'updated' => 0,
                'not_found' => 0,
                'failed' => 0,
                'next_resume_after_news_id' => null,
                'has_more' => false,
                'resume_after_news_id' => $resumeAfterId,
            ];

            $processLogService->finishRun(
                $processLog,
                ProcessLogService::STATUS_SUCCESS,
                $details,
                'No news left to Yoast reindex.'
            );

            return response()->json([
                'success' => true,
                'message' => 'No news left to Yoast reindex.',
                'data' => $details,
            ]);
        }

        $results = [
            'processed' => 0,
            'updated' => 0,
            'not_found' => 0,
            'failed' => 0,
            'items' => [],
        ];

        $abortedDueToTimeBudget = false;
        $lastProcessedNewsId = null;
        $lastCheckpointNewsId = null;

        foreach ($targets as $news) {
            if (microtime(true) >= $deadline) {
                $abortedDueToTimeBudget = true;
                break;
            }

            $service = new WordPressPostingService($news->lang);
            $reindexResult = $service->reindexYoastForNews($news);

            $results['processed']++;
            $lastProcessedNewsId = (int) $news->id;

            if (($reindexResult['success'] ?? false) === true) {
                $results['updated']++;
                $lastCheckpointNewsId = (int) $news->id;
            } elseif (($reindexResult['error'] ?? null) === 'wordpress_post_not_found') {
                $results['not_found']++;
                $lastCheckpointNewsId = (int) $news->id;
            } else {
                $results['failed']++;
            }

            $results['items'][] = [
                'news_id' => $news->id,
                'lang' => $news->lang,
                'title' => $news->title,
                'wp_post_id' => $reindexResult['wp_post_id'] ?? null,
                'success' => (bool) ($reindexResult['success'] ?? false),
                'error' => $reindexResult['error'] ?? null,
            ];
        }

        $hasMore = false;
        if (is_int($lastCheckpointNewsId) && $lastCheckpointNewsId > 0) {
            $hasMore = News::where('id', '<', $lastCheckpointNewsId)->exists();
        } elseif (is_int($lastProcessedNewsId) && $lastProcessedNewsId > 0) {
            $hasMore = true;
        }

        $nextResumeAfterNewsId = null;
        if ($hasMore || $abortedDueToTimeBudget) {
            if (is_int($lastCheckpointNewsId) && $lastCheckpointNewsId > 0) {
                $nextResumeAfterNewsId = $lastCheckpointNewsId;
            } elseif (is_int($resumeAfterId) && $resumeAfterId > 0) {
                $nextResumeAfterNewsId = $resumeAfterId;
            }
        }

        $status = ProcessLogService::STATUS_SUCCESS;
        if ($results['failed'] > 0 || $hasMore || $abortedDueToTimeBudget) {
            $status = ProcessLogService::STATUS_PARTIAL;
        }

        $details = [
            'processed' => $results['processed'],
            'updated' => $results['updated'],
            'not_found' => $results['not_found'],
            'failed' => $results['failed'],
            'aborted_due_to_time_budget' => $abortedDueToTimeBudget,
            'next_resume_after_news_id' => $nextResumeAfterNewsId,
            'resume_after_news_id' => $resumeAfterId,
            'has_more' => $hasMore,
            'limit' => $limit,
            'items' => $results['items'],
        ];

        $processLogService->finishRun(
            $processLog,
            $status,
            $details,
            $status === ProcessLogService::STATUS_SUCCESS
                ? 'Yoast score reindex completed.'
                : 'Yoast score reindex partially completed.'
        );

        return response()->json([
            'success' => true,
            'message' => $status === ProcessLogService::STATUS_SUCCESS
                ? 'Yoast score reindex completed.'
                : 'Yoast score reindex partially completed. You can resume from checkpoint.',
            'data' => $details,
        ]);
    }

    public function reindexYoastScoresStatus(): JsonResponse
    {
        $processType = 'yoast_reindex_scores';
        $latest = ProcessLog::query()
            ->where('process_type', $processType)
            ->orderByDesc('id')
            ->first();

        $checkpoint = $this->getCheckpointByProcessType($processType);

        return response()->json([
            'success' => true,
            'data' => [
                'latest_run' => $latest,
                'checkpoint' => $checkpoint,
            ],
        ]);
    }

    private function getSeoRepushCheckpoint(): array
    {
        return $this->getCheckpointByProcessType('repush_seo_meta');
    }

    private function getCheckpointByProcessType(string $processType): array
    {
        $log = ProcessLog::query()
            ->where('process_type', $processType)
            ->whereIn('status', [ProcessLogService::STATUS_RUNNING, ProcessLogService::STATUS_PARTIAL])
            ->orderByDesc('id')
            ->first();

        if (!$log) {
            return [
                'has_checkpoint' => false,
                'log_id' => null,
                'status' => null,
                'next_resume_after_news_id' => null,
                'updated_at' => null,
            ];
        }

        $details = $this->decodeProcessLogDetails($log->details);
        $nextResume = isset($details['next_resume_after_news_id'])
            ? (int) $details['next_resume_after_news_id']
            : null;

        return [
            'has_checkpoint' => $nextResume !== null,
            'log_id' => $log->id,
            'status' => $log->status,
            'next_resume_after_news_id' => $nextResume,
            'updated_at' => optional($log->updated_at)->toIso8601String(),
        ];
    }

    private function decodeProcessLogDetails(?string $details): array
    {
        if (!is_string($details) || trim($details) === '') {
            return [];
        }

        $decoded = json_decode($details, true);
        return is_array($decoded) ? $decoded : [];
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
        $body .= "  ✗ En échec (restent en attente status 0) : {$failedCount}\n";

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
            $body .= "NEWS EN ÉCHEC (status 0 — à retenter)\n";
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
