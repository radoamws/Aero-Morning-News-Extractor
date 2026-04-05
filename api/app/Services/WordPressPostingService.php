<?php

namespace App\Services;

use App\Models\News;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WordPressPostingService
{
    private string $wpUrl;

    public function __construct(string $lang = 'FR')
    {
        if ($lang === 'EN') {
            $this->wpUrl = rtrim(config('services.wordpress.en_url', env('WORDPRESS_EN_URL')), '/');
        } else {
            $this->wpUrl = rtrim(config('services.wordpress.fr_url', env('WORDPRESS_FR_URL')), '/');
        }
    }

    /**
     * Post news to WordPress
     */
    public function postToWordPress(News $news, ?string $username = null, ?string $password = null): ?int
    {
        try {
            if (!$username || !$password) {
                Log::warning("WordPress credentials not provided");
                return null;
            }

            // Prepare post data
            $postData = [
                'title' => $news->title,
                'content' => $news->content,
                'excerpt' => $news->metadescription,
                'status' => 'publish',
                'meta' => [
                    'focus_keyphrase' => $news->focuskeyphrase,
                ]
            ];

            // Add categories
            if (!empty($news->categories)) {
                $postData['categories'] = $news->getCategoriesArray();
            }

            // Add tags
            if (!empty($news->tags)) {
                $postData['tags'] = $news->getTagsArray();
            }

            // Add featured image if available
            if ($news->image_url && strpos($news->image_url, 'http') === false) {
                // Upload image first
                $imageId = $this->uploadImage($news->image_url, $username, $password);
                if ($imageId) {
                    $postData['featured_media'] = $imageId;
                }
            }

            // Create post via WordPress REST API
            $response = Http::withBasicAuth($username, $password)
                ->timeout(30)
                ->post("{$this->wpUrl}/wp-json/wp/v2/posts", $postData);

            if ($response->successful()) {
                $postId = $response->json()['id'];
                Log::info("News Posted to WordPress with ID: $postId");
                return $postId;
            } else {
                Log::error("WordPress API Error: " . $response->status() . " - " . $response->body());
                return null;
            }
        } catch (\Exception $e) {
            Log::error("Error posting to WordPress: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload image to WordPress Media Library
     */
    private function uploadImage(string $imagePath, string $username, string $password): ?int
    {
        try {
            $fullPath = storage_path("app/public" . $imagePath);

            if (!file_exists($fullPath)) {
                Log::warning("Image file not found: $fullPath");
                return null;
            }

            $fileContent = file_get_contents($fullPath);
            $fileName = basename($imagePath);

            $response = Http::withBasicAuth($username, $password)
                ->withHeader('Content-Disposition', "attachment; filename=\"$fileName\"")
                ->withHeader('Content-Type', 'image/jpeg')
                ->timeout(30)
                ->post("{$this->wpUrl}/wp-json/wp/v2/media", $fileContent);

            if ($response->successful()) {
                $mediaId = $response->json()['id'];
                Log::info("Image uploaded to WordPress with ID: $mediaId");
                return $mediaId;
            } else {
                Log::error("WordPress Media Upload Error: " . $response->status());
                return null;
            }
        } catch (\Exception $e) {
            Log::error("Error uploading image to WordPress: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update WordPress post
     */
    public function updateWordPressPost(int $postId, News $news, ?string $username = null, ?string $password = null): bool
    {
        try {
            if (!$username || !$password) {
                return false;
            }

            $postData = [
                'title' => $news->title,
                'content' => $news->content,
                'excerpt' => $news->metadescription,
            ];

            if (!empty($news->categories)) {
                $postData['categories'] = $news->getCategoriesArray();
            }

            if (!empty($news->tags)) {
                $postData['tags'] = $news->getTagsArray();
            }

            $response = Http::withBasicAuth($username, $password)
                ->timeout(30)
                ->post("{$this->wpUrl}/wp-json/wp/v2/posts/$postId", $postData);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("Error updating WordPress post: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete WordPress post
     */
    public function deleteWordPressPost(int $postId, ?string $username = null, ?string $password = null): bool
    {
        try {
            if (!$username || !$password) {
                return false;
            }

            $response = Http::withBasicAuth($username, $password)
                ->timeout(30)
                ->delete("{$this->wpUrl}/wp-json/wp/v2/posts/$postId");

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("Error deleting WordPress post: " . $e->getMessage());
            return false;
        }
    }
}
