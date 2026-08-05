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
        $articleUrl = $this->resolveArticleUrl($news);
        $postText   = $this->buildPostText($news, $articleUrl);

        // Mode Make.com webhook (prioritaire) — permet de poster en tant que page entreprise
        $makeWebhookUrl = env('LINKEDIN_MAKE_WEBHOOK_URL', '');
        if (!empty($makeWebhookUrl)) {
            return $this->postViaMake($makeWebhookUrl, $news, $postText, $articleUrl);
        }

        // Mode API LinkedIn directe (nécessite w_organization_social pour une page)
        if (empty($this->accessToken)) {
            throw new \RuntimeException('Token LinkedIn non configuré. Utilisez le panel "Configuration LinkedIn" du dashboard.');
        }
        if (empty($this->authorUrn)) {
            throw new \RuntimeException('URN LinkedIn non configuré. Utilisez le panel "Configuration LinkedIn" du dashboard.');
        }

        return $this->postViaLinkedInApi($postText, $articleUrl, $news);
    }

    private function postViaMake(string $webhookUrl, News $news, string $postText, string $articleUrl): array
    {
        // detectCompanies() déjà en cache (appelé depuis buildPostText() → buildCompanyMentions())
        $companies  = $this->detectCompanies($news);
        $ugcPayload = $this->buildUGCPostPayload($news, $articleUrl, $companies);

        $payload = [
            // ── Champs de compatibilité (debug / ancienne version) ───────────
            'text'      => $postText,
            'url'       => $articleUrl,
            'title'     => $news->title,
            'excerpt'   => $this->buildExcerpt($news, 200),
            'hashtags'  => $this->buildHashtags($news),
            'image_url' => $this->resolvePublicImageUrl($news),
            'companies' => $companies,
            // ── Payload UGC Posts API v2 — pour "Make an API Call" ───────────
            // li_payload = chaîne JSON (json_encode) → Make.com reçoit une STRING
            // Dans le Raw body de Make, {{2.li_payload}} substitue cette chaîne JSON
            // → LinkedIn reçoit un corps JSON valide
            //
            // Dans Make, module "LinkedIn - Make an API Call" :
            //   URL    : https://api.linkedin.com/v2/ugcPosts
            //   Method : POST
            //   Headers: Content-Type: application/json
            //            X-Restli-Protocol-Version: 2.0.0
            //   Body   : Raw → {{2.li_payload}}   ← pas de toJSON(), c'est déjà une string JSON
            'li_payload' => json_encode($ugcPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $response = Http::timeout(30)->post($webhookUrl, $payload);

        Log::info('LinkedIn via Make webhook', [
            'news_id' => $news->id,
            'status'  => $response->status(),
            'body'    => $response->body(),
            'payload' => json_encode($payload)
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException("Make webhook error (HTTP {$response->status()}): " . $response->body());
        }

        return [
            'success' => true,
            'post_id' => 'via-make',
            'url'     => 'https://www.linkedin.com/company/4869929/admin/page-posts/published/',
        ];
    }

    /**
     * Construit le payload UGC Posts API v2 pour LinkedIn.
     * Endpoint : POST https://api.linkedin.com/v2/ugcPosts
     *
     * C'est le MÊME endpoint qu'utilise Make's "Create a Company Text Post" → même auth ✓
     * Les @mentions cliquables passent par le tableau "annotations" avec positions UTF-16.
     *
     * !! Important : ce payload doit être envoyé comme objet JSON (pas une string JSON).
     *    Dans Make : Body = {{toJSON(2.li_payload)}}
     */
    private function buildUGCPostPayload(News $news, string $articleUrl, array $companies): array
    {
        $orgUrn  = env('LINKEDIN_ORG_URN', '');
        if (empty($orgUrn)) {
            $orgUrn = str_contains($this->authorUrn, 'organization')
                ? $this->authorUrn
                : 'urn:li:organization:4869929';
        }

        $excerpt  = $this->buildExcerpt($news, 280);
        $hashtags = $this->buildHashtags($news);
        $title    = $news->title ?? '';

        // ── Texte + calcul des positions UTF-16 pour les annotations ─────────
        // Structure : "✈️ Title\n\nExcerpt\n\n🔗 URL\n\n[companies]\n\nHashtags"
        $header   = "✈️ {$title}";
        $linkLine = "🔗 {$articleUrl}";

        // Préfixe = tout ce qui précède la section entreprises
        $prefix = "{$header}\n\n{$excerpt}\n\n{$linkLine}\n\n";

        $annotations  = [];
        $companyNames = [];
        $offset       = $this->utf16Len($prefix); // position UTF-16 de début des entreprises

        foreach ($companies as $i => $company) {
            if ($i > 0) {
                $offset++; // espace entre les noms
            }
            $name = $company['name'];
            $urn  = $this->resolveCompanyUrn($company['slug']);

            if ($urn) {
                // Annotation UGC API : {entity, start (offset UTF-16), length (nb code units du nom)}
                $annotations[] = [
                    'entity' => ['com.linkedin.common.CompanyEntityUrn' => $urn],
                    'start'  => $offset,
                    'length' => $this->utf16Len($name),
                ];
            }

            $companyNames[] = $name;
            $offset        += $this->utf16Len($name);
        }

        // Texte final assemblé
        $text = $prefix;
        if (!empty($companyNames)) {
            $text .= implode(' ', $companyNames) . "\n\n";
        }
        $text .= $hashtags;

        $shareCommentary = ['text' => $text];
        if (!empty($annotations)) {
            $shareCommentary['annotations'] = $annotations;
        }

        return [
            'author'         => $orgUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary'    => $shareCommentary,
                    'shareMediaCategory' => 'ARTICLE',
                    'media'              => [[
                        'status'      => 'READY',
                        'description' => ['text' => $this->buildExcerpt($news, 200)],
                        'originalUrl' => $articleUrl,
                        'title'       => ['text' => $title],
                    ]],
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];
    }

    /**
     * Compte les code units UTF-16 d'une chaîne.
     * Nécessaire pour les positions d'annotations LinkedIn UGC (qui utilisent UTF-16, pas bytes).
     *   - Caractères BMP  (U+0000–U+FFFF)  : 1 code unit chacun  (ASCII, accents, ✈️ U+2708…)
     *   - Caractères supp (U+10000+)         : 2 code units chacun (emojis 🔗 U+1F517, 😀…)
     */
    private function utf16Len(string $str): int
    {
        $units = 0;
        $len   = mb_strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $cp     = mb_ord(mb_substr($str, $i, 1));
            $units += ($cp >= 0x10000) ? 2 : 1;
        }
        return $units;
    }

    private function postViaLinkedInApi(string $postText, string $articleUrl, News $news): array
    {
        // Payload UGC complet avec annotations @mentions cliquables
        // (postText non utilisé ici — buildUGCPostPayload génère son propre texte avec annotations)
        $companies = $this->detectCompanies($news);
        $payload   = $this->buildUGCPostPayload($news, $articleUrl, $companies);

        $response = Http::withToken($this->accessToken)
            ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
            ->post('https://api.linkedin.com/v2/ugcPosts', $payload);

        $annotationCount = count(
            $payload['specificContent']['com.linkedin.ugc.ShareContent']['shareCommentary']['annotations'] ?? []
        );

        Log::info('LinkedIn UGC post (direct API)', [
            'news_id'     => $news->id,
            'status'      => $response->status(),
            'body'        => $response->body(),
            'annotations' => $annotationCount,
            'companies'   => array_column($companies, 'name'),
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
        $mentions = $this->buildCompanyMentions($news);
        $hashtags = $this->buildHashtags($news);

        $text = "✈️ {$news->title}\n\n{$excerpt}\n\n🔗 {$articleUrl}";

        // Mentions entreprises — ligne séparée au-dessus des hashtags (comme dans la capture)
        if (!empty($mentions)) {
            $text .= "\n\n" . $mentions;
        }

        $text .= "\n\n{$hashtags}";

        return $text;
    }

    // -------------------------------------------------------------------------
    // Company mentions
    // -------------------------------------------------------------------------

    /**
     * Retourne la ligne de mentions "@Company1 @Company2 …" pour le post LinkedIn.
     * Utilise le format @[Name](urn:li:organization:ID) reconnu par l'API LinkedIn UGC.
     * Les URNs sont résolus via l'API LinkedIn puis mis en cache dans linkedin.json.
     * Si l'URN est inconnu, le nom est inclus en texte simple.
     */
    private function buildCompanyMentions(News $news): string
    {
        $companies = $this->detectCompanies($news);
        if (empty($companies)) {
            return '';
        }

        $parts = [];
        foreach ($companies as $company) {
            // Toujours préfixer avec @ — visible dans le post même sans URN cliquable
            // (les URN cliquables nécessitent LINKEDIN_ACCESS_TOKEN + annotations dans li_payload)
            $parts[] = '@' . $company['name'];
        }

        return implode(' ', $parts);
    }

    /**
     * Détecte les entreprises via OpenAI (wrapper avec cache et fallback silencieux).
     * Retourne un tableau de ['name' => '...', 'slug' => '...', 'url' => '...'].
     */
    private function detectCompanies(News $news): array
    {
        try {
            return $this->detectCompaniesWithOpenAI($news);
        } catch (\Throwable $e) {
            Log::warning("LinkedIn: détection des entreprises échouée: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Demande à OpenAI d'extraire les entreprises mentionnées dans l'article,
     * avec leurs slugs LinkedIn. Vérifie l'existence de chaque URL avant de l'accepter.
     * Résultats mis en cache par news ID dans linkedin.json.
     */
    private function detectCompaniesWithOpenAI(News $news): array
    {
        $apiKey = env('OPENAI_API_KEY', '');
        if (empty($apiKey)) {
            return [];
        }

        // ── Cache par news ID ──────────────────────────────────────────────────
        $settings = self::readSettings();
        $cache    = $settings['detected_companies'] ?? [];
        $cacheKey = 'news_' . $news->id;

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey] ?? [];
        }

        // ── Contexte article ───────────────────────────────────────────────────
        $title   = $news->title          ?? '';
        $meta    = $news->metadescription ?? '';
        $content = mb_substr(
            strip_tags(html_entity_decode(
                (string) ($news->content_brut ?? $news->content ?? ''),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )),
            0,
            2500
        );

        // ── Prompt ────────────────────────────────────────────────────────────
        $prompt = <<<PROMPT
You are an aerospace, aviation, defense, eVTOL, UAM, and space industry expert.

Identify ALL companies, organizations, agencies, and institutions mentioned in the article below.
Include: airlines, manufacturers, defense contractors, eVTOL/UAM/AAM startups, government agencies,
military branches, regulatory bodies, research institutes, and international organizations.

Known LinkedIn slugs (use these when the entity is mentioned):
- Archer / Archer Aviation → archerair
- Joby / Joby Aviation → jobyaviation
- Wisk / Wisk Aero → wiskaero
- Volocopter → volocopter
- Lilium → lilium-jet
- Korean Air → koreanair
- Advanced Air Mobility International / AAM International → advanced-air-mobility-international
- Airbus → airbusgroup
- Boeing → the-boeing-company
- Safran → safrangroup
- Thales → thales
- Dassault Aviation → dassault-aviation
- Rolls-Royce → rolls-royce
- GE Aerospace / General Electric → ge-aerospace
- Pratt & Whitney → pratt-whitney
- European Defence Agency / EDA → european-defence-agency
- NATO → nato
- NSPA → nspa-nato-support-and-procurement-agency
- European Commission → european-commission
- EASA → easa
- FAA → federal-aviation-administration
- Lockheed Martin → lockheed-martin
- Northrop Grumman → northropgrumman
- RTX / Raytheon → rtx
- BAE Systems → bae-systems
- SpaceX → spacex

Rules:
- Include BOTH the aircraft/eVTOL maker AND the airline/operator when both are mentioned
- "AAM" in eVTOL/urban aviation context often refers to "Advanced Air Mobility International"
- Only include entities with a confirmed LinkedIn presence
- Use the exact slug you know — omit if unsure
- Do NOT include AeroMorning (that is us, not a mention)
- Maximum 8 entities
- Return ONLY a JSON object

Format: {"companies": [{"name": "Official LinkedIn Display Name", "slug": "linkedin-vanity-slug"}]}

Article title: {$title}
Summary: {$meta}
Content: {$content}
PROMPT;

        // ── Appel OpenAI ─────────────────────────────────────────────────────
        // On utilise le modèle rapide (gpt-4o-mini) — simple extraction d'entités
        $model    = env('OPENAI_FALLBACK_MODEL', 'gpt-4o-mini');
        $response = Http::withToken($apiKey)
            ->timeout(25)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'           => $model,
                'messages'        => [['role' => 'user', 'content' => $prompt]],
                'temperature'     => 0.1,
                'max_tokens'      => 400,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("OpenAI API error ({$response->status()}): " . $response->body());
        }

        $parsed    = json_decode($response->json('choices.0.message.content', '{}'), true);
        $companies = array_filter($parsed['companies'] ?? [], fn($c) => !empty($c['name']) && !empty($c['slug']));
        $companies = array_slice(array_values($companies), 0, 8);

        // ── Vérification HTTP de chaque slug LinkedIn ─────────────────────────
        $verified = [];
        foreach ($companies as $company) {
            $slug = trim($company['slug']);
            if ($this->verifyLinkedInSlug($slug)) {
                $verified[] = [
                    'name' => $company['name'],
                    'slug' => $slug,
                    'url'  => "https://www.linkedin.com/company/{$slug}/",
                ];
            } else {
                Log::info("LinkedIn: slug '{$slug}' rejeté (page introuvable)");
            }
        }

        // ── Mise en cache ─────────────────────────────────────────────────────
        // On ne cache PAS un résultat vide : si OpenAI ou la vérification a échoué,
        // on réessaiera au prochain appel plutôt que de stocker [] indéfiniment.
        if (!empty($verified)) {
            $cache[$cacheKey] = $verified;
            self::writeSettings(['detected_companies' => $cache]);
        }

        Log::info("LinkedIn: OpenAI a détecté " . count($verified) . " entreprise(s) pour news #{$news->id}", [
            'companies' => array_column($verified, 'name'),
        ]);

        return $verified;
    }

    /**
     * Vérifie qu'une page LinkedIn company/{slug}/ existe et est accessible.
     * Retourne false uniquement si LinkedIn confirme une 404 (slug invalide).
     * Toute autre réponse (200, 999, redirect…) est considérée comme valide.
     */
    private function verifyLinkedInSlug(string $slug): bool
    {
        try {
            $response = Http::timeout(6)
                ->withHeaders([
                    // UA crédible pour éviter d'être bloqué avant même d'obtenir un code HTTP
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                                  . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                                  . 'Chrome/124.0.0.0 Safari/537.36',
                    'Accept'     => 'text/html,application/xhtml+xml',
                ])
                ->get("https://www.linkedin.com/company/{$slug}/");

            // 404 = slug inexistant ; tout le reste (200, 302, 999…) → page existe
            return $response->status() !== 404;
        } catch (\Throwable $e) {
            // Impossible de vérifier (timeout, réseau…) → on fait confiance à OpenAI
            Log::debug("LinkedIn: vérification du slug '{$slug}' impossible: " . $e->getMessage());
            return true;
        }
    }

    /**
     * Retourne l'URN LinkedIn (urn:li:organization:ID) pour un slug donné.
     * Vérifie d'abord le cache dans linkedin.json, puis appelle l'API si un token est dispo.
     * Retourne null si l'URN n'est pas connu (pas de token ou API refusée).
     */
    private function resolveCompanyUrn(string $slug): ?string
    {
        $settings = self::readSettings();
        $cache    = $settings['company_urns'] ?? [];

        // '' = déjà cherché mais non trouvé ; urn:... = trouvé
        if (array_key_exists($slug, $cache)) {
            return !empty($cache[$slug]) ? (string) $cache[$slug] : null;
        }

        if (empty($this->accessToken)) {
            $cache[$slug] = '';
            self::writeSettings(['company_urns' => $cache]);
            return null;
        }

        try {
            $response = Http::timeout(5)
                ->withToken($this->accessToken)
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->get('https://api.linkedin.com/v2/organizations', [
                    'q'          => 'vanityName',
                    'vanityName' => $slug,
                    'projection' => '(id)',
                ]);

            if ($response->successful()) {
                $elements = $response->json('elements', []);
                if (!empty($elements[0]['id'])) {
                    $urn          = 'urn:li:organization:' . $elements[0]['id'];
                    $cache[$slug] = $urn;
                    self::writeSettings(['company_urns' => $cache]);
                    Log::info("LinkedIn: URN résolu pour '{$slug}' → {$urn}");
                    return $urn;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("LinkedIn: impossible de résoudre l'URN pour '{$slug}': " . $e->getMessage());
        }

        $cache[$slug] = ''; // marque comme cherché et non trouvé
        self::writeSettings(['company_urns' => $cache]);
        return null;
    }

    /**
     * Construit l'URL publique complète de l'image pour LinkedIn.
     *
     * image_url en BDD = "/storage/images/xxx.jpg" (chemin relatif)
     * Fichier sur disque  = storage/app/public/images/xxx.jpg
     * URL publique        = https://api.aeromorning.com/storage/images/xxx.jpg
     *   ↑ possible via la symlink public/storage → ../storage/app/public
     *   ↑ créée par "php artisan storage:link"
     *
     * APP_URL peut pointer vers le frontend Nuxt (news.aeromorning.com).
     * On utilise LINKEDIN_IMAGE_BASE_URL qui doit viser l'API (api.aeromorning.com).
     */
    private function resolvePublicImageUrl(News $news): string
    {
        $raw = $news->image_url ?? '';
        if (empty($raw)) {
            return '';
        }
        // Déjà une URL absolue → on la retourne telle quelle
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }
        // Chemin relatif /storage/images/xxx.jpg →
        //   https://api.aeromorning.com/storage/images/xxx.jpg
        $base = rtrim(env('LINKEDIN_IMAGE_BASE_URL', 'https://api.aeromorning.com'), '/');
        return $base . '/' . ltrim($raw, '/');
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
