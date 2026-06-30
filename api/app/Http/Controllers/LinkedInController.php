<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Services\LinkedInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class LinkedInController extends Controller
{
    public function __construct(private LinkedInService $linkedInService) {}

    /**
     * POST /news/{id}/post-to-linkedin
     */
    public function postNews(int $id): JsonResponse
    {
        $news = News::findOrFail($id);

        try {
            $result = $this->linkedInService->postNews($news);
            return response()->json([
                'message' => 'Article publié sur LinkedIn avec succès',
                'post_id' => $result['post_id'],
                'url'     => $result['url'],
            ]);
        } catch (\Throwable $e) {
            Log::error('LinkedIn posting failed', ['news_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Échec publication LinkedIn: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /linkedin/auth
     * Returns the LinkedIn OAuth authorization URL.
     */
    public function getAuthUrl(): JsonResponse
    {
        try {
            return response()->json(['url' => $this->linkedInService->getAuthUrl()]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /linkedin/callback?code=...
     * Exchanges the authorization code for an access token and saves it to storage.
     * Returns an HTML page (not JSON) so the user can close the tab.
     */
    public function handleCallback(Request $request): Response
    {
        $code = $request->query('code');
        if (!$code) {
            return response(
                $this->callbackHtml('Erreur', 'Paramètre "code" manquant dans l\'URL.', false),
                400
            )->header('Content-Type', 'text/html; charset=utf-8');
        }

        try {
            $token     = $this->linkedInService->exchangeCodeForToken($code);
            $expiresIn = (int) ($token['expires_in'] ?? 5184000); // 60 days default
            $this->linkedInService->saveToken($token['access_token'], $expiresIn);

            return response(
                $this->callbackHtml(
                    'Token LinkedIn sauvegardé !',
                    'Vous pouvez fermer cet onglet et retourner au dashboard.<br>Passez ensuite à l\'étape 2 : "Récupérer mon profil".',
                    true
                )
            )->header('Content-Type', 'text/html; charset=utf-8');
        } catch (\Throwable $e) {
            return response(
                $this->callbackHtml('Erreur', htmlspecialchars($e->getMessage()), false),
                500
            )->header('Content-Type', 'text/html; charset=utf-8');
        }
    }

    /**
     * GET /linkedin/auth-info
     * Returns the current user's LinkedIn sub and author URN (requires a saved token).
     */
    public function getAuthInfo(): JsonResponse
    {
        try {
            return response()->json($this->linkedInService->getAuthInfo());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /linkedin/settings
     * Returns current LinkedIn settings status (masked token, URN, expiry).
     */
    public function getSettings(): JsonResponse
    {
        return response()->json($this->linkedInService->getPublicSettings());
    }

    /**
     * POST /linkedin/save-urn
     * Saves the author URN to persistent storage.
     */
    public function saveUrn(Request $request): JsonResponse
    {
        $urn  = $request->input('urn', '');
        $name = $request->input('name', '');

        if (empty($urn)) {
            return response()->json(['message' => 'URN manquant'], 400);
        }

        if (!preg_match('/^urn:li:(person|organization):\w+$/', $urn)) {
            return response()->json(['message' => 'Format URN invalide (attendu: urn:li:person:XXXXX)'], 422);
        }

        try {
            $this->linkedInService->saveAuthorUrn($urn, $name);
            return response()->json(['message' => 'URN LinkedIn sauvegardé avec succès']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------

    private function callbackHtml(string $title, string $body, bool $success): string
    {
        $color = $success ? '#16a34a' : '#dc2626';
        $icon  = $success ? '✅' : '❌';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="fr">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>LinkedIn OAuth — {$title}</title>
          <style>
            body { font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center;
                   min-height: 100vh; margin: 0; background: #f8fafc; }
            .card { background: #fff; border-radius: 12px; padding: 2.5rem; max-width: 480px; width: 90%;
                    box-shadow: 0 4px 24px rgba(0,0,0,.08); text-align: center; }
            h1 { font-size: 1.4rem; color: {$color}; margin: 0 0 1rem; }
            p  { color: #475569; line-height: 1.6; }
            .icon { font-size: 3rem; margin-bottom: 1rem; }
          </style>
        </head>
        <body>
          <div class="card">
            <div class="icon">{$icon}</div>
            <h1>{$title}</h1>
            <p>{$body}</p>
          </div>
        </body>
        </html>
        HTML;
    }
}
