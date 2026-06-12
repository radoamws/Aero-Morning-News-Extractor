<?php

namespace App\Http\Controllers;

use App\Services\CloudflareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CloudflareCacheController extends Controller
{
    /**
     * Purge homepage cache — called from the dashboard (Sanctum-protected).
     */
    public function purgeHomepage(): JsonResponse
    {
        return $this->doPurge();
    }

    /**
     * Purge homepage cache — called from a cron job via secret token (no Sanctum).
     */
    public function purgeHomepageCron(Request $request): JsonResponse
    {
        $secret = (string) env('CLOUDFLARE_PURGE_CRON_SECRET', '');

        if ($secret === '' || $request->query('secret') !== $secret) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        return $this->doPurge();
    }

    private function doPurge(): JsonResponse
    {
        $result = app(CloudflareService::class)->purgeHomepage();

        if ($result['skipped'] ?? false) {
            return response()->json([
                'success' => true,
                'skipped' => true,
                'message' => 'Purge skipped: ' . ($result['reason'] ?? ''),
            ]);
        }

        if ($result['success'] ?? false) {
            return response()->json([
                'success' => true,
                'message' => 'Cloudflare homepage cache purged.',
                'urls'    => $result['urls'] ?? [],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Cloudflare purge failed.',
            'errors'  => $result['errors'] ?? ($result['error'] ?? 'Unknown error'),
        ], 500);
    }
}
