<?php

namespace App\Services;

use App\Models\News;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WordPressPostingService
{
    private string $wpUrl;
    private string $authToken;
    private string $yoastAuthToken;
    private bool $allowTitleFallback;

    public function __construct(string $lang = 'FR')
    {
        if ($lang === 'EN') {
            $this->wpUrl    = rtrim(config('services.wordpress.en_url', env('WORDPRESS_EN_URL')), '/');
            $this->authToken = config('services.wordpress.auth_en', env('WORDPRESS_AUTH_EN', ''));
            $this->yoastAuthToken = config('services.wordpress.yoast_auth_en', env('WORDPRESS_AUTH_EN', ''));
        } else {
            $this->wpUrl    = rtrim(config('services.wordpress.fr_url', env('WORDPRESS_FR_URL')), '/');
            $this->authToken = config('services.wordpress.auth_fr', env('WORDPRESS_AUTH_FR', ''));
            $this->yoastAuthToken = config('services.wordpress.yoast_auth_fr', env('WORDPRESS_AUTH_FR', ''));
        }

        $this->allowTitleFallback = (bool) config('services.wordpress.allow_title_fallback', false);
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
     * Returns ['success' => bool, 'wp_post_id' => int|null, 'error' => string|null, 'details' => array]
     */
    public function publishToWordPress(News $news): array
    {
        $details = [
            'wp_url' => $this->wpUrl,
            'lang' => $news->lang,
            'has_auth_token' => $this->authToken !== '',
            'auth_token_length' => strlen($this->authToken),
            'steps' => [],
        ];

        try {
            // Step 1 — upload featured image
            $imageId = null;
            if (!empty($news->image_url)) {
                $upload = $this->uploadImageMultipartDetailed($news->image_url);
                $imageId = $upload['media_id'];
                $details['steps']['media_upload'] = $upload;
            } else {
                $details['steps']['media_upload'] = [
                    'attempted' => false,
                    'media_id' => null,
                    'http_status' => null,
                    'response_excerpt' => null,
                    'note' => 'No image_url on news',
                ];
            }

            // Step 2 — create post using multipart/form-data
            // (same approach as the previously working Make.com modules).
            $multipart = [
                [
                    'name' => 'title',
                    'contents' => (string) $news->title,
                ],
                [
                    'name' => 'content',
                    'contents' => (string) $news->content,
                ],
                [
                    'name' => 'excerpt',
                    'contents' => (string) ($news->metadescription ?? ''),
                ],
                [
                    'name' => 'status',
                    'contents' => 'publish',
                ],
            ];

            if ($imageId !== null) {
                $multipart[] = [
                    'name' => 'featured_media',
                    'contents' => (string) $imageId,
                ];
            }

            if (!empty($news->categories)) {
                $multipart[] = [
                    'name' => 'categories',
                    'contents' => (string) $news->categories,
                ];
            }

            if (!empty($news->tags)) {
                $multipart[] = [
                    'name' => 'tags',
                    'contents' => (string) $news->tags,
                ];
            }

            $response = Http::withHeaders([
                    'Authorization' => 'Basic ' . $this->authToken,
                ])
                ->timeout(30)
                ->send('POST', "{$this->wpUrl}/wp-json/wp/v2/posts", [
                    'multipart' => $multipart,
                ]);

            $details['steps']['post_create'] = [
                'attempted' => true,
                'http_status' => $response->status(),
                'response_excerpt' => $this->excerptWpResponse($response->body()),
            ];

            if (!$response->successful()) {
                Log::error("WP post creation failed [{$response->status()}]: " . $response->body());
                return [
                    'success'    => false,
                    'wp_post_id' => null,
                    'error'      => "Post creation HTTP {$response->status()}",
                    'details'    => $details,
                ];
            }

            $wpPostId = (int) $response->json('id');
            Log::info("News #{$news->id} published to WordPress — WP post ID: {$wpPostId}");
            $this->persistWordPressPostMapping($news, $wpPostId);

            // Step 3 — update Yoast SEO meta
            $details['steps']['yoast_meta'] = $this->updateYoastMetaDetailed($wpPostId, $news);

            return ['success' => true, 'wp_post_id' => $wpPostId, 'error' => null, 'details' => $details];

        } catch (\Throwable $e) {
            Log::error("Error publishing news #{$news->id} to WordPress: " . $e->getMessage());
            $details['exception'] = [
                'message' => $e->getMessage(),
                'class' => get_class($e),
            ];
            return ['success' => false, 'wp_post_id' => null, 'error' => $e->getMessage(), 'details' => $details];
        }
    }

    /**
     * Repush only SEO metadata (meta description + focus keyphrase)
     * to an existing WordPress post resolved from the local news title.
     */
    public function repushSeoMetaForNews(News $news): array
    {
        try {
            $wpPostId = $this->resolveWordPressPostIdForNews($news);
            if ($wpPostId === null) {
                return [
                    'success' => false,
                    'wp_post_id' => null,
                    'error' => 'wordpress_post_not_found',
                    'details' => [
                        'title' => $news->title,
                        'lang' => $news->lang,
                    ],
                ];
            }

            $yoast = $this->updateYoastMetaDetailed($wpPostId, $news);
            $httpStatus = $yoast['http_status'] ?? null;
            $isOk = is_int($httpStatus) && $httpStatus >= 200 && $httpStatus < 300;

            return [
                'success' => $isOk,
                'wp_post_id' => $wpPostId,
                'error' => $isOk ? null : 'yoast_meta_update_failed',
                'details' => [
                    'yoast_meta' => $yoast,
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning("Error repushing SEO meta for news #{$news->id}: " . $e->getMessage());
            return [
                'success' => false,
                'wp_post_id' => null,
                'error' => $e->getMessage(),
                'details' => [
                    'exception' => [
                        'message' => $e->getMessage(),
                        'class' => get_class($e),
                    ],
                ],
            ];
        }
    }

    /**
     * Ask the custom Yoast endpoint to reindex SEO scores server-side.
     */
    public function reindexYoastForNews(News $news): array
    {
        try {
            $wpPostId = $this->resolveWordPressPostIdForNews($news);
            if ($wpPostId === null) {
                return [
                    'success' => false,
                    'wp_post_id' => null,
                    'error' => 'wordpress_post_not_found',
                    'details' => [
                        'title' => $news->title,
                        'lang' => $news->lang,
                    ],
                ];
            }

            // Mirror an editor save cycle: touch the WP post first, then rebuild Yoast.
            $postTouch = $this->refreshWordPressPostForYoastDetailed($wpPostId, $news);
            $yoast = $this->updateYoastMetaDetailed($wpPostId, $news, true);
            $touchHttpStatus = $postTouch['http_status'] ?? null;
            $isTouchOk = is_int($touchHttpStatus) && $touchHttpStatus >= 200 && $touchHttpStatus < 300;
            $httpStatus = $yoast['http_status'] ?? null;
            $isYoastOk = is_int($httpStatus) && $httpStatus >= 200 && $httpStatus < 300;
            $isOk = $isTouchOk && $isYoastOk;

            return [
                'success' => $isOk,
                'wp_post_id' => $wpPostId,
                'error' => $isOk ? null : ($isTouchOk ? 'yoast_reindex_failed' : 'wordpress_post_touch_failed'),
                'details' => [
                    'post_touch' => $postTouch,
                    'yoast_meta' => $yoast,
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning("Error reindexing Yoast for news #{$news->id}: " . $e->getMessage());
            return [
                'success' => false,
                'wp_post_id' => null,
                'error' => $e->getMessage(),
                'details' => [
                    'exception' => [
                        'message' => $e->getMessage(),
                        'class' => get_class($e),
                    ],
                ],
            ];
        }
    }

    /**
     * Upload the news image to the WordPress media library using
     * multipart/form-data with field key "file".
     */
    private function uploadImageMultipartDetailed(string $imageUrl): array
    {
        try {
            // Resolve the local file path.
            // image_url is stored as "/storage/images/filename.jpg"
            // actual disk path  : storage/app/public/images/filename.jpg
            $relativePath = str_replace('/storage', '', $imageUrl);
            $filePath     = storage_path('app/public' . $relativePath);

            if (!file_exists($filePath)) {
                Log::warning("Image not found for WP upload: {$filePath}");
                return [
                    'attempted' => true,
                    'media_id' => null,
                    'http_status' => null,
                    'response_excerpt' => null,
                    'error' => 'image_not_found',
                    'file_path' => $filePath,
                ];
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
                return [
                    'attempted' => true,
                    'media_id' => $mediaId,
                    'http_status' => $response->status(),
                    'response_excerpt' => $this->excerptWpResponse($response->body()),
                ];
            }

            Log::error("WP media upload failed [{$response->status()}]: " . $response->body());
            return [
                'attempted' => true,
                'media_id' => null,
                'http_status' => $response->status(),
                'response_excerpt' => $this->excerptWpResponse($response->body()),
            ];

        } catch (\Throwable $e) {
            Log::error("Error uploading image to WordPress: " . $e->getMessage());
            return [
                'attempted' => true,
                'media_id' => null,
                'http_status' => null,
                'response_excerpt' => null,
                'exception' => [
                    'message' => $e->getMessage(),
                    'class' => get_class($e),
                ],
            ];
        }
    }

    /**
     * Update Yoast SEO meta on an existing WordPress post.
     */
    private function updateYoastMetaDetailed(int $wpPostId, News $news, bool $requestReindex = false): array
    {
        try {
            $metaPayload = $this->buildSeoMetaPayload($news, $requestReindex);
            $yoastAuthToken = $this->yoastAuthToken !== '' ? $this->yoastAuthToken : $this->authToken;
            $response = Http::withHeaders([
                    'Authorization' => 'Basic ' . $yoastAuthToken,
                    'Content-Type'  => 'application/json',
                ])
                ->timeout(15)
                ->withBody(
                    json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'application/json'
                )
                ->post($this->buildYoastEndpointUrl($wpPostId));

            if (!$response->successful()) {
                Log::warning("Yoast meta update failed for WP post {$wpPostId} [{$response->status()}]");
                return [
                    'attempted' => true,
                    'http_status' => $response->status(),
                    'response_excerpt' => $this->excerptWpResponse($response->body()),
                ];
            }

            Log::info("Yoast meta updated for WP post {$wpPostId}");
            return [
                'attempted' => true,
                'http_status' => $response->status(),
                'response_excerpt' => $this->excerptWpResponse($response->body()),
            ];
        } catch (\Throwable $e) {
            Log::warning("Error updating Yoast meta for WP post {$wpPostId}: " . $e->getMessage());
            return [
                'attempted' => true,
                'http_status' => null,
                'response_excerpt' => null,
                'exception' => [
                    'message' => $e->getMessage(),
                    'class' => get_class($e),
                ],
            ];
        }
    }

    private function refreshWordPressPostForYoastDetailed(int $wpPostId, News $news): array
    {
        try {
            $response = Http::withHeaders([
                    'Authorization' => 'Basic ' . $this->authToken,
                ])
                ->timeout(20)
                ->post("{$this->wpUrl}/wp-json/wp/v2/posts/{$wpPostId}", [
                    'title' => (string) $news->title,
                    'content' => (string) $news->content,
                    'excerpt' => (string) ($news->metadescription ?? ''),
                    'status' => 'publish',
                ]);

            if (!$response->successful()) {
                Log::warning("WP post touch failed for WP post {$wpPostId} [{$response->status()}]");
                return [
                    'attempted' => true,
                    'http_status' => $response->status(),
                    'response_excerpt' => $this->excerptWpResponse($response->body()),
                ];
            }

            return [
                'attempted' => true,
                'http_status' => $response->status(),
                'response_excerpt' => $this->excerptWpResponse($response->body()),
            ];
        } catch (\Throwable $e) {
            Log::warning("Error touching WP post {$wpPostId} before Yoast reindex: " . $e->getMessage());
            return [
                'attempted' => true,
                'http_status' => null,
                'response_excerpt' => null,
                'exception' => [
                    'message' => $e->getMessage(),
                    'class' => get_class($e),
                ],
            ];
        }
    }

    private function updateYoastMetaBasicAuthDetailed(int $wpPostId, News $news, string $username, string $password, bool $requestReindex = false): array
    {
        try {
            $metaPayload = $this->buildSeoMetaPayload($news, $requestReindex);
            $response = Http::withBasicAuth($username, $password)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->timeout(15)
                ->withBody(
                    json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'application/json'
                )
                ->post($this->buildYoastEndpointUrl($wpPostId));

            if (!$response->successful()) {
                Log::warning("Yoast meta update (basic auth) failed for WP post {$wpPostId} [{$response->status()}]");
                return [
                    'attempted' => true,
                    'http_status' => $response->status(),
                    'response_excerpt' => $this->excerptWpResponse($response->body()),
                ];
            }

            Log::info("Yoast meta updated (basic auth) for WP post {$wpPostId}");
            return [
                'attempted' => true,
                'http_status' => $response->status(),
                'response_excerpt' => $this->excerptWpResponse($response->body()),
            ];
        } catch (\Throwable $e) {
            Log::warning("Error updating Yoast meta (basic auth) for WP post {$wpPostId}: " . $e->getMessage());
            return [
                'attempted' => true,
                'http_status' => null,
                'response_excerpt' => null,
                'exception' => [
                    'message' => $e->getMessage(),
                    'class' => get_class($e),
                ],
            ];
        }
    }

    private function excerptWpResponse(?string $body): ?string
    {
        if (!is_string($body)) {
            return null;
        }

        $body = trim($body);
        if ($body === '') {
            return null;
        }

        // Avoid huge logs; keep the beginning which usually contains WP error code/message.
        $max = 1200;
        return mb_strlen($body) > $max ? (mb_substr($body, 0, $max) . '...<truncated>') : $body;
    }

    /**
     * Build a WordPress REST meta payload that covers the supported SEO plugins.
     */
    private function buildSeoMetaPayload(News $news, bool $requestReindex = false): array
    {
        $metaDescription = (string) ($news->metadescription ?? '');
        $focusKeyphrase = (string) ($news->focuskeyphrase ?? '');

        $payload = [
            'metadesc' => $metaDescription,
            'focuskw' => $focusKeyphrase,
            'post_title' => (string) $news->title,
            'post_excerpt' => $metaDescription,
            'post_content' => (string) $news->content,
        ];

        if ($requestReindex) {
            $payload['reindex'] = true;
            $payload['touch_post'] = true;
        }

        return $payload;
    }

    private function buildYoastEndpointUrl(int $wpPostId): string
    {
        return rtrim($this->wpUrl, '/') . '/wp-json/aeromorning/v1/yoast/' . $wpPostId;
    }

    /**
     * Backfill local wp_post_id mapping for already published news.
     */
    public function backfillWordPressPostIdForNews(News $news): array
    {
        try {
            $mappedId = (int) ($news->wp_post_id ?? 0);
            if ($mappedId > 0 && $this->wordPressPostExists($mappedId)) {
                return [
                    'success' => true,
                    'wp_post_id' => $mappedId,
                    'error' => null,
                    'details' => [
                        'mapping_source' => 'existing',
                    ],
                ];
            }

            $resolvedByTitle = $this->findWordPressPostIdByTitle((string) $news->title);
            if ($resolvedByTitle === null) {
                return [
                    'success' => false,
                    'wp_post_id' => null,
                    'error' => 'wordpress_post_not_found',
                    'details' => [
                        'mapping_source' => 'title_search',
                    ],
                ];
            }

            $this->persistWordPressPostMapping($news, $resolvedByTitle);

            return [
                'success' => true,
                'wp_post_id' => $resolvedByTitle,
                'error' => null,
                'details' => [
                    'mapping_source' => 'title_search',
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning("Error backfilling WP post mapping for news #{$news->id}: " . $e->getMessage());
            return [
                'success' => false,
                'wp_post_id' => null,
                'error' => $e->getMessage(),
                'details' => [
                    'exception' => [
                        'message' => $e->getMessage(),
                        'class' => get_class($e),
                    ],
                ],
            ];
        }
    }

    private function resolveWordPressPostIdForNews(News $news): ?int
    {
        $mappedId = (int) ($news->wp_post_id ?? 0);
        if ($mappedId > 0) {
            if ($this->wordPressPostExists($mappedId)) {
                return $mappedId;
            }

            Log::warning("Mapped WP post ID {$mappedId} no longer exists for news #{$news->id}; trying title fallback");
        }

        if ($this->allowTitleFallback) {
            $resolvedByTitle = $this->findWordPressPostIdByTitle((string) $news->title);
            if ($resolvedByTitle !== null) {
                $this->persistWordPressPostMapping($news, $resolvedByTitle);
                return $resolvedByTitle;
            }
        }

        return null;
    }

    private function wordPressPostExists(int $wpPostId): bool
    {
        try {
            $response = Http::withHeaders([
                    'Authorization' => 'Basic ' . $this->authToken,
                ])
                ->timeout(15)
                ->get("{$this->wpUrl}/wp-json/wp/v2/posts/{$wpPostId}", [
                    'context' => 'edit',
                ]);

            if ($response->status() === 404) {
                return false;
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("Unable to verify WP post ID {$wpPostId}: " . $e->getMessage());
            // Be permissive on transient network errors; keep current mapping.
            return true;
        }
    }

    private function persistWordPressPostMapping(News $news, int $wpPostId): void
    {
        if ($wpPostId <= 0) {
            return;
        }

        if ((int) ($news->wp_post_id ?? 0) === $wpPostId) {
            return;
        }

        try {
            $news->wp_post_id = $wpPostId;
            $news->save();
        } catch (\Throwable $e) {
            Log::warning("Unable to persist WP post mapping for news #{$news->id}: " . $e->getMessage());
        }
    }

    private function findWordPressPostIdByTitle(string $title): ?int
    {
        $normalizedTitle = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($normalizedTitle === '') {
            return null;
        }

        $slug = Str::slug($normalizedTitle);
        if ($slug !== '') {
            $slugResponse = Http::withHeaders([
                    'Authorization' => 'Basic ' . $this->authToken,
                ])
                ->timeout(20)
                ->get("{$this->wpUrl}/wp-json/wp/v2/posts", [
                    'slug' => $slug,
                    'per_page' => 5,
                ]);

            if ($slugResponse->successful()) {
                $slugPosts = $slugResponse->json();
                if (is_array($slugPosts) && !empty($slugPosts)) {
                    $id = (int) data_get($slugPosts, '0.id', 0);
                    if ($id > 0) {
                        return $id;
                    }
                }
            }
        }

        $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->authToken,
            ])
            ->timeout(20)
            ->get("{$this->wpUrl}/wp-json/wp/v2/posts", [
                'search' => $normalizedTitle,
                'per_page' => 20,
                'orderby' => 'date',
                'order' => 'desc',
                'status' => 'publish',
            ]);

        if (!$response->successful()) {
            Log::warning("Unable to search WordPress post by title [{$response->status()}]");
            return null;
        }

        $posts = $response->json();
        if (!is_array($posts) || empty($posts)) {
            return null;
        }

        foreach ($posts as $post) {
            $rendered = (string) data_get($post, 'title.rendered', '');
            $candidate = trim(strip_tags(html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            if ($this->normalizeTitleForComparison($candidate) === $this->normalizeTitleForComparison($normalizedTitle)) {
                return (int) ($post['id'] ?? 0) ?: null;
            }
        }

        return null;
    }

    private function normalizeTitleForComparison(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace(["\u{2019}", "`", "´", "’"], "'", $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        return $normalized;
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
                $this->persistWordPressPostMapping($news, (int) $postId);

                // Best-effort Yoast SEO meta update
                $this->updateYoastMetaBasicAuthDetailed((int) $postId, $news, $username, $password);

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

            if (!$response->successful()) {
                return false;
            }

            // Keep Yoast SEO in sync when updating manually
            $this->updateYoastMetaBasicAuthDetailed((int) $postId, $news, $username, $password);

            return true;
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
