<?php

namespace App\Services;

use App\Models\News;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WordPressPostingService
{
    private string $wpUrl;
    private string $authToken;

    public function __construct(string $lang = 'FR')
    {
        if ($lang === 'EN') {
            $this->wpUrl    = rtrim(config('services.wordpress.en_url', env('WORDPRESS_EN_URL')), '/');
            $this->authToken = config('services.wordpress.auth_en', env('WORDPRESS_AUTH_EN', ''));
        } else {
            $this->wpUrl    = rtrim(config('services.wordpress.fr_url', env('WORDPRESS_FR_URL')), '/');
            $this->authToken = config('services.wordpress.auth_fr', env('WORDPRESS_AUTH_FR', ''));
        }
    }

    // -------------------------------------------------------------------------
    // Automated publish flow (uses .env Application Password — no credentials
    // passed in the request body).
    // -------------------------------------------------------------------------

    /**
     * Publish a pending news item to WordPress.
     *
     * Steps:
     *   1. Upload the image via multipart/form-data → get media ID
     *   2. Create the WP post (JSON) with featured_media, categories, tags
     *   3. PATCH Yoast SEO meta (_yoast_wpseo_metadesc, _yoast_wpseo_focuskw)
     *
     * Returns ['success' => bool, 'wp_post_id' => int|null, 'error' => string|null]
     */
    public function publishToWordPress(News $news): array
    {
        try {
            // Step 1 — upload featured image
            $imageId = null;
            if (!empty($news->image_url)) {
                $imageId = $this->uploadImageMultipart($news->image_url);
            }

            // Step 2 — create post
            $postData = [
                'title'   => $news->title,
                'content' => $news->content,
                'excerpt' => $news->metadescription,
                'status'  => 'publish',
            ];

            if ($imageId !== null) {
                $postData['featured_media'] = $imageId;
            }

            if (!empty($news->categories)) {
                $postData['categories'] = $news->getCategoriesArray();
            }

            if (!empty($news->tags)) {
                $postData['tags'] = $news->getTagsArray();
            }

            $response = Http::withHeaders([
                    'Authorization' => 'Basic ' . $this->authToken,
                    'Content-Type'  => 'application/json',
                ])
                ->timeout(30)
                ->post("{$this->wpUrl}/wp-json/wp/v2/posts", $postData);

            if (!$response->successful()) {
                Log::error("WP post creation failed [{$response->status()}]: " . $response->body());
                return [
                    'success'    => false,
                    'wp_post_id' => null,
                    'error'      => "Post creation HTTP {$response->status()}",
                ];
            }

            $wpPostId = (int) $response->json('id');
            Log::info("News #{$news->id} published to WordPress — WP post ID: {$wpPostId}");

            // Step 3 — update Yoast SEO meta
            $this->updateYoastMeta($wpPostId, $news);

            return ['success' => true, 'wp_post_id' => $wpPostId, 'error' => null];

        } catch (\Throwable $e) {
            Log::error("Error publishing news #{$news->id} to WordPress: " . $e->getMessage());
            return ['success' => false, 'wp_post_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Upload the news image to the WordPress media library using
     * multipart/form-data with field key "file".
     */
    private function uploadImageMultipart(string $imageUrl): ?int
    {
        try {
            // Resolve the local file path.
            // image_url is stored as "/storage/images/filename.jpg"
            // actual disk path  : storage/app/public/images/filename.jpg
            $relativePath = str_replace('/storage', '', $imageUrl);
            $filePath     = storage_path('app/public' . $relativePath);

            if (!file_exists($filePath)) {
                Log::warning("Image not found for WP upload: {$filePath}");
                return null;
            }

            $fileName    = basename($filePath);
            $fileContent = file_get_contents($filePath);
            $mimeType    = mime_content_type($filePath) ?: 'image/jpeg';

            $response = Http::withHeaders([
                    'Authorization'       => 'Basic ' . $this->authToken,
                    'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
                ])
                ->timeout(60)
                ->attach('file', $fileContent, $fileName, ['Content-Type' => $mimeType])
                ->post("{$this->wpUrl}/wp-json/wp/v2/media");

            if ($response->successful()) {
                $mediaId = (int) $response->json('id');
                Log::info("Image uploaded to WP media library — ID: {$mediaId}");
                return $mediaId;
            }

            Log::error("WP media upload failed [{$response->status()}]: " . $response->body());
            return null;

        } catch (\Throwable $e) {
            Log::error("Error uploading image to WordPress: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update Yoast SEO meta on an existing WordPress post.
     */
    private function updateYoastMeta(int $wpPostId, News $news): void
    {
        try {
            $response = Http::withHeaders([
                    'Authorization' => 'Basic ' . $this->authToken,
                    'Content-Type'  => 'application/json',
                ])
                ->timeout(15)
                ->post("{$this->wpUrl}/wp-json/wp/v2/posts/{$wpPostId}", [
                    'meta' => [
                        '_yoast_wpseo_metadesc' => $news->metadescription,
                        '_yoast_wpseo_focuskw'  => $news->focuskeyphrase,
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning("Yoast meta update failed for WP post {$wpPostId} [{$response->status()}]");
            } else {
                Log::info("Yoast meta updated for WP post {$wpPostId}");
            }
        } catch (\Throwable $e) {
            Log::warning("Error updating Yoast meta for WP post {$wpPostId}: " . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Legacy methods kept for the manual posting controller endpoints.
    // -------------------------------------------------------------------------

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
