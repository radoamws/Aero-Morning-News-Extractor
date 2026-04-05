<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Services\WordPressPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
}
