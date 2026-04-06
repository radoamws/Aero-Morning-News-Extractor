<?php

namespace App\Services;

use App\Models\CategoryFr;
use App\Models\CategoryEn;
use App\Models\TagFr;
use App\Models\TagEn;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WordPressService
{
    private string $wpFrUrl;
    private string $wpEnUrl;

    public function __construct()
    {
        $this->wpFrUrl = rtrim(config('services.wordpress.fr_url', env('WORDPRESS_FR_URL')), '/');
        $this->wpEnUrl = rtrim(config('services.wordpress.en_url', env('WORDPRESS_EN_URL')), '/');
    }

    /**
     * Sync French categories from WordPress
     */
    public function syncCategoriesFr(): bool
    {
        try {
            $syncedCount = $this->syncWordPressItems(
                "{$this->wpFrUrl}/wp-json/wp/v2/categories",
                function (int $id, string $name): void {
                    CategoryFr::updateOrCreate(
                        ['wp_id' => $id],
                        ['categ_name' => $name]
                    );
                }
            );

            if ($syncedCount === 0) {
                Log::warning('No categories fetched from WordPress FR');
                return false;
            }

            Log::info('Successfully synced ' . $syncedCount . ' French categories');
            return true;
        } catch (Throwable $e) {
            Log::error('Error syncing FR categories: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync English categories from WordPress
     */
    public function syncCategoriesEn(): bool
    {
        try {
            $syncedCount = $this->syncWordPressItems(
                "{$this->wpEnUrl}/wp-json/wp/v2/categories",
                function (int $id, string $name): void {
                    CategoryEn::updateOrCreate(
                        ['wp_id' => $id],
                        ['categ_name' => $name]
                    );
                }
            );

            if ($syncedCount === 0) {
                Log::warning('No categories fetched from WordPress EN');
                return false;
            }

            Log::info('Successfully synced ' . $syncedCount . ' English categories');
            return true;
        } catch (Throwable $e) {
            Log::error('Error syncing EN categories: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync French tags from WordPress
     */
    public function syncTagsFr(): bool
    {
        try {
            $syncedCount = $this->syncWordPressItems(
                "{$this->wpFrUrl}/wp-json/wp/v2/tags",
                function (int $id, string $name): void {
                    TagFr::updateOrCreate(
                        ['wp_id' => $id],
                        ['tag_name' => $name]
                    );
                }
            );

            if ($syncedCount === 0) {
                Log::warning('No tags fetched from WordPress FR');
                return false;
            }

            Log::info('Successfully synced ' . $syncedCount . ' French tags');
            return true;
        } catch (Throwable $e) {
            Log::error('Error syncing FR tags: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync English tags from WordPress
     */
    public function syncTagsEn(): bool
    {
        try {
            $syncedCount = $this->syncWordPressItems(
                "{$this->wpEnUrl}/wp-json/wp/v2/tags",
                function (int $id, string $name): void {
                    TagEn::updateOrCreate(
                        ['wp_id' => $id],
                        ['tag_name' => $name]
                    );
                }
            );

            if ($syncedCount === 0) {
                Log::warning('No tags fetched from WordPress EN');
                return false;
            }

            Log::info('Successfully synced ' . $syncedCount . ' English tags');
            return true;
        } catch (Throwable $e) {
            Log::error('Error syncing EN tags: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync data from WordPress API with pagination and incremental upsert.
     */
    private function syncWordPressItems(string $url, callable $upsert, int $perPage = 50): int
    {
        $syncedCount = 0;
        $page = 1;
        $totalPages = 1;

        do {
            $response = Http::connectTimeout(15)
                ->timeout(60)
                ->retry(2, 1500)
                ->get($url, [
                    'per_page' => $perPage,
                    'page' => $page,
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException("WordPress API error {$response->status()} for {$url} (page {$page})");
            }

            $items = $response->json();
            if (!is_array($items)) {
                throw new \RuntimeException("Invalid WordPress response format for {$url} (page {$page})");
            }

            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['id'], $item['name'])) {
                    continue;
                }

                $upsert((int) $item['id'], (string) $item['name']);
                $syncedCount++;
            }

            $headerPages = (int) $response->header('X-WP-TotalPages', 1);
            $totalPages = $headerPages > 0 ? $headerPages : 1;
            $page++;
        } while (!empty($items) && $page <= $totalPages);

        return $syncedCount;
    }

    /**
     * Get all categories for classification
     */
    public function getCategoriesForClassification(string $lang = 'FR'): array
    {
        if ($lang === 'EN') {
            return CategoryEn::all(['wp_id', 'categ_name'])->toArray();
        }
        return CategoryFr::all(['wp_id', 'categ_name'])->toArray();
    }

    /**
     * Get all tags for classification
     */
    public function getTagsForClassification(string $lang = 'FR'): array
    {
        if ($lang === 'EN') {
            return TagEn::all(['wp_id', 'tag_name'])->toArray();
        }
        return TagFr::all(['wp_id', 'tag_name'])->toArray();
    }
}
