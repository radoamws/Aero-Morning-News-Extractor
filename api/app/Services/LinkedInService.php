<?php

namespace App\Services;

use App\Models\News;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinkedInService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    // These come from the settings file (with .env fallback)
    private string $accessToken;
    private string $authorUrn;

    private static function settingsPath(): string
    {
        return storage_path('app/linkedin.json');
    }

    private static function readSettings(): array
    {
        $path = self::settingsPath();
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    public static function writeSettings(array $merge): void
    {
        $current = self::readSettings();
        $updated = array_merge($current, $merge);
        file_put_contents(self::settingsPath(), json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function __construct()
    {
        $this->clientId   = env('LINKEDIN_CLIENT_ID', env('CLIENT_ID', ''));
        $this->clientSecret = env('LINKEDIN_CLIENT_SECRET', env('PRIMARY_CLIENT_SECRET', ''));
        $this->redirectUri  = env('LINKEDIN_REDIRECT_URI', '');

        $settings = self::readSettings();
        // Settings file takes precedence over .env for the token and URN
        $this->accessToken = $settings['access_token'] ?? env('LINKEDIN_ACCESS_TOKEN', '');
        $this->authorUrn   = $settings['author_urn']   ?? env('LINKEDIN_AUTHOR_URN', '');
    }

    // -------------------------------------------------------------------------
    // OAuth
    // -------------------------------------------------------------------------

    public function getAuthUrl(): string
    {
        if (empty($this->clientId)) {
            throw new \RuntimeException('LINKEDIN_CLIENT_ID non configuré dans .env');
        }
        if (empty($this->redirectUri)) {
            throw new \RuntimeException('LINKEDIN_REDIRECT_URI non configuré dans .env');
        }

        $params = http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'scope'         => 'openid profile w_member_social w_organization_social r_organization_social',
            'state'         => bin2hex(random_bytes(16)),
        ]);

        return 'https://www.linkedin.com/oauth/v2/authorization?' . $params;
    }

    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('LinkedIn token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    public function saveToken(string $accessToken, int $expiresIn): void
    {
        $expiresAt = now()->addSeconds($expiresIn)->toDateString();
        self::writeSettings([
            'access_token'    => $accessToken,
            'token_expires_at' => $expiresAt,
        ]);
        $this->accessToken = $accessToken;
    }

    // -------------------------------------------------------------------------
    // Profile / settings
    // -------------------------------------------------------------------------

    public function getAuthInfo(): array
    {
        if (empty($this->accessToken)) {
            throw new \RuntimeException('Token LinkedIn non configuré. Effectuez d\'abord l\'étape OAuth.');
        }

        // Profile de la personne connectée
        $profileResponse = Http::withToken($this->accessToken)
            ->get('https://api.linkedin.com/v2/userinfo');

        if (!$profileResponse->successful()) {
            throw new \RuntimeException('LinkedIn userinfo failed (HTTP ' . $profileResponse->status() . '): ' . $profileResponse->body());
        }

        $info = $profileResponse->json();
        $name = trim(($info['given_name'] ?? '') . ' ' . ($info['family_name'] ?? ''));

        // Pages administrées par ce compte
        $organizations = $this->getAdminOrganizations();

        return [
            'name'          => $name,
            'email'         => $info['email'] ?? null,
            'sub'           => $info['sub'] ?? null,
            'organizations' => $organizations,
        ];
    }

    public function getAdminOrganizations(): array
    {
        if (empty($this->accessToken)) {
            return [];
        }

        // Récupère les organisations dont l'utilisateur est admin
        $aclResponse = Http::withToken($this->accessToken)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->get('https://api.linkedin.com/v2/organizationAcls', [
                'q'     => 'roleAssignee',
                'role'  => 'ADMINISTRATOR',
                'state' => 'APPROVED',
            ]);

        if (!$aclResponse->successful()) {
            Log::warning('LinkedIn: impossible de récupérer les pages administrées', [
                'status' => $aclResponse->status(),
                'body'   => $aclResponse->body(),
            ]);
            return [];
        }

        $organizations = [];
        foreach ($aclResponse->json('elements', []) as $element) {
            $orgUrn = $element['organization'] ?? null;
            if (!$orgUrn) {
                continue;
            }

            // Extrait l'ID numérique depuis l'URN
            preg_match('/urn:li:organization:(\d+)/', $orgUrn, $m);
            $orgId = $m[1] ?? null;

            $orgName = null;
            if ($orgId) {
                $orgResponse = Http::withToken($this->accessToken)
                    ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                    ->get("https://api.linkedin.com/v2/organizations/{$orgId}", [
                        'projection' => '(id,localizedName)',
                    ]);
                if ($orgResponse->successful()) {
                    $orgName = $orgResponse->json('localizedName');
                }
            }

            $organizations[] = [
                'urn'  => $orgUrn,
                'id'   => $orgId,
                'name' => $orgName ?? $orgUrn,
            ];
        }

        return $organizations;
    }

    public function saveAuthorUrn(string $urn, string $name = ''): void
    {
        self::writeSettings([
            'author_urn'  => $urn,
            'author_name' => $name,
        ]);
        $this->authorUrn = $urn;
    }

    public function getPublicSettings(): array
    {
        $settings = self::readSettings();
        $tokenConfigured = !empty($settings['access_token'] ?? env('LINKEDIN_ACCESS_TOKEN', ''));
        return [
            'token_configured' => $tokenConfigured,
            'token_expires_at' => $settings['token_expires_at'] ?? null,
            'author_urn'       => $settings['author_urn'] ?? env('LINKEDIN_AUTHOR_URN', '') ?: null,
            'author_name'      => $settings['author_name'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // Post news
    // -------------------------------------------------------------------------

    public function postNews(News $news): array
    {
        if (empty($this->accessToken)) {
            throw new \RuntimeException('Token LinkedIn non configuré. Utilisez le panel "Configuration LinkedIn" du dashboard.');
        }
        if (empty($this->authorUrn)) {
            throw new \RuntimeException('URN LinkedIn non configuré. Utilisez le panel "Configuration LinkedIn" du dashboard.');
        }

        $articleUrl = $this->resolveArticleUrl($news);
        $postText   = $this->buildPostText($news, $articleUrl);

        $payload = [
            'author'          => $this->authorUrn,
            'lifecycleState'  => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary'    => ['text' => $postText],
                    'shareMediaCategory' => 'ARTICLE',
                    'media'              => [
                        [
                            'status'      => 'READY',
                            'description' => ['text' => $this->buildExcerpt($news, 200)],
                            'originalUrl' => $articleUrl,
                            'title'       => ['text' => $news->title],
                        ],
                    ],
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $response = Http::withToken($this->accessToken)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post('https://api.linkedin.com/v2/ugcPosts', $payload);

        Log::info('LinkedIn UGC post', [
            'news_id' => $news->id,
            'status'  => $response->status(),
            'body'    => $response->body(),
        ]);

        if (!$response->successful()) {
            $body   = $response->json() ?? [];
            $errMsg = $body['message'] ?? ($body['serviceErrorCode'] ?? $response->body());
            throw new \RuntimeException("LinkedIn API error (HTTP {$response->status()}): {$errMsg}");
        }

        $postUrn = $response->header('X-RestLi-Id') ?? ($response->json('id') ?? 'unknown');

        return [
            'success' => true,
            'post_id' => $postUrn,
            'url'     => 'https://www.linkedin.com/feed/update/' . rawurlencode($postUrn),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveArticleUrl(News $news): string
    {
        if (!empty($news->wp_post_id)) {
            try {
                $base = $news->lang === 'EN'
                    ? rtrim(env('WORDPRESS_EN_URL', env('WORDPRESS_URL', '')), '/')
                    : rtrim(env('WORDPRESS_FR_URL', env('WORDPRESS_URL', '')), '/');

                $response = Http::timeout(10)
                    ->get("{$base}/wp-json/wp/v2/posts/{$news->wp_post_id}?_fields=link");

                if ($response->successful() && !empty($response->json('link'))) {
                    return $response->json('link');
                }
            } catch (\Throwable $e) {
                Log::warning('LinkedIn: impossible de récupérer le permalink WP', ['error' => $e->getMessage()]);
            }
        }

        return rtrim(env('WORDPRESS_URL', 'https://aeromorning.com'), '/');
    }

    private function buildPostText(News $news, string $articleUrl): string
    {
        $excerpt  = $this->buildExcerpt($news, 280);
        $hashtags = $this->buildHashtags($news);

        return "✈️ {$news->title}\n\n{$excerpt}\n\n🔗 {$articleUrl}\n\n{$hashtags}";
    }

    private function buildExcerpt(News $news, int $maxLen): string
    {
        $raw = $news->metadescription ?? $news->content ?? $news->content_brut ?? '';
        $raw = strip_tags((string) $raw);
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = preg_replace('/\s+/', ' ', $raw);
        $raw = trim($raw);

        if (mb_strlen($raw) <= $maxLen) {
            return $raw;
        }

        $cut       = mb_substr($raw, 0, $maxLen);
        $lastSpace = mb_strrpos($cut, ' ');

        return ($lastSpace !== false ? mb_substr($cut, 0, $lastSpace) : $cut) . '…';
    }

    private function buildHashtags(News $news): string
    {
        $tags = [];

        if (!empty($news->focuskeyphrase)) {
            $clean = preg_replace('/[^a-zA-Z0-9\s\-]/', '', (string) $news->focuskeyphrase);
            foreach (preg_split('/[\s\-]+/', $clean) as $word) {
                $word = trim($word);
                if (mb_strlen($word) >= 3) {
                    $tags[] = '#' . ucfirst(mb_strtolower($word));
                }
            }
        }

        $tags[] = '#Aviation';
        $tags[] = '#Aeromorning';
        $tags[] = '#Aerospace';

        return implode(' ', array_slice(array_unique($tags), 0, 10));
    }
}
