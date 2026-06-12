<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareService
{
    private string $zoneId;
    private string $apiToken;
    private bool $enabled;

    public function __construct()
    {
        $this->zoneId   = (string) env('CLOUDFLARE_ZONE_ID', '');
        $this->apiToken = (string) env('CLOUDFLARE_API_TOKEN', '');
        $this->enabled  = filter_var(env('CLOUDFLARE_PURGE_ENABLED', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * Purge only the FR and EN homepages from Cloudflare cache.
     * Never purges the entire zone (no purge_everything).
     */
    public function purgeHomepage(): array
    {
        if (!$this->enabled) {
            return ['skipped' => true, 'reason' => 'CLOUDFLARE_PURGE_ENABLED is false'];
        }

        if ($this->zoneId === '' || $this->apiToken === '') {
            Log::warning('Cloudflare purge skipped: missing CLOUDFLARE_ZONE_ID or CLOUDFLARE_API_TOKEN');
            return ['skipped' => true, 'reason' => 'Missing Cloudflare credentials in .env'];
        }

        $frBase = rtrim((string) env('WORDPRESS_FR_URL', 'https://aeromorning.com'), '/');
        $enBase = rtrim((string) env('WORDPRESS_EN_URL', 'https://aeromorning.com/en'), '/');

        $urls = [
            $frBase . '/',
            $enBase . '/',
        ];

        $apiUrl = "https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/purge_cache";

        try {
            $response = Http::withToken($this->apiToken)
                ->timeout(15)
                ->post($apiUrl, ['files' => $urls]);

            $body = $response->json();

            if ($response->ok() && ($body['success'] ?? false)) {
                Log::info('Cloudflare homepage cache purged', ['urls' => $urls]);
                return ['success' => true, 'urls' => $urls];
            }

            $errors = $body['errors'] ?? [];
            Log::error('Cloudflare purge failed', [
                'status' => $response->status(),
                'errors' => $errors,
            ]);
            return ['success' => false, 'errors' => $errors, 'http_status' => $response->status()];

        } catch (\Throwable $e) {
            Log::error('Cloudflare purge exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
