<?php

namespace App\Services;

use App\Models\CategoryFr;
use App\Models\CategoryEn;
use App\Models\TagFr;
use App\Models\TagEn;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            $categories = $this->fetchFromWordpress("{$this->wpFrUrl}/wp-json/wp/v2/categories", 100);
            
            if (!$categories) {
                Log::warning('No categories fetched from WordPress FR');
                return false;
            }

            foreach ($categories as $category) {
                CategoryFr::updateOrCreate(
                    ['wp_id' => $category['id']],
                    ['categ_name' => $category['name']]
                );
            }

            Log::info('Successfully synced ' . count($categories) . ' French categories');
            return true;
        } catch (\Exception $e) {
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
            $categories = $this->fetchFromWordpress("{$this->wpEnUrl}/wp-json/wp/v2/categories", 100);
            
            if (!$categories) {
                Log::warning('No categories fetched from WordPress EN');
                return false;
            }

            foreach ($categories as $category) {
                CategoryEn::updateOrCreate(
                    ['wp_id' => $category['id']],
                    ['categ_name' => $category['name']]
                );
            }

            Log::info('Successfully synced ' . count($categories) . ' English categories');
            return true;
        } catch (\Exception $e) {
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
            $tags = $this->fetchFromWordpress("{$this->wpFrUrl}/wp-json/wp/v2/tags", 100);
            
            if (!$tags) {
                Log::warning('No tags fetched from WordPress FR');
                return false;
            }

            foreach ($tags as $tag) {
                TagFr::updateOrCreate(
                    ['wp_id' => $tag['id']],
                    ['tag_name' => $tag['name']]
                );
            }

            Log::info('Successfully synced ' . count($tags) . ' French tags');
            return true;
        } catch (\Exception $e) {
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
            $tags = $this->fetchFromWordpress("{$this->wpEnUrl}/wp-json/wp/v2/tags", 100);
            
            if (!$tags) {
                Log::warning('No tags fetched from WordPress EN');
                return false;
            }

            foreach ($tags as $tag) {
                TagEn::updateOrCreate(
                    ['wp_id' => $tag['id']],
                    ['tag_name' => $tag['name']]
                );
            }

            Log::info('Successfully synced ' . count($tags) . ' English tags');
            return true;
        } catch (\Exception $e) {
            Log::error('Error syncing EN tags: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch data from WordPress API with pagination
     */
    private function fetchFromWordpress(string $url, int $perPage = 100): ?array
    {
        try {
            $allItems = [];
            $page = 1;
            
            do {
                $response = Http::timeout(30)->get($url, [
                    'per_page' => $perPage,
                    'page' => $page
                ]);

                if (!$response->successful()) {
                    Log::error("WordPress API error: {$response->status()} - {$url}");
                    return null;
                }

                $items = $response->json();
                if (empty($items)) {
                    break;
                }

                $allItems = array_merge($allItems, $items);
                $page++;
                
            } while (count($items) === $perPage);

            return $allItems;
        } catch (\Exception $e) {
            Log::error("Error fetching from WordPress: " . $e->getMessage());
            return null;
        }
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
