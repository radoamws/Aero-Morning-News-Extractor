<?php

namespace App\Services;

use App\Models\News;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinkedInService
{
    /**
     * Carte statique slug LinkedIn → URN LinkedIn.
     * Vérifiée par scraping direct (tests août 2026).
     * Ces URNs sont permanents — ils ne changent jamais.
     * Utilisée en priorité sur l'API et le scraping en temps réel.
     */
    /**
     * Noms officiels LinkedIn par slug (vérifiés par scraping août 2026).
     * Ces noms DOIVENT correspondre exactement au nom affiché sur la page LinkedIn.
     * Utilisés dans le texte du post ET dans localizedName des annotations Buffer.
     * LinkedIn valide que text[start:length] == "@" + localizedName → toute divergence
     * provoque l'erreur "Invalid LinkedIn organization mention: text at position does not match."
     */
    private const KNOWN_NAMES = [
        // ── eVTOL / AAM ───────────────────────────────────────────────────────
        'flyarcher'                     => 'Archer',             // Archer Aviation (nom LI: "Archer")
        'jobyaviation'                  => 'Joby Aviation',
        'wisk-aero'                     => 'Wisk',               // NOM OFFICIEL: "Wisk" (pas "Wisk Aero")
        'volocopter'                    => 'Volocopter',
        'insitu'                        => 'INsitu',             // CASSE OFFICIELLE: "INsitu"
        'skygrid'                       => 'SkyGrid',
        // ── Constructeurs / OEM ───────────────────────────────────────────────
        'boeing'                        => 'Boeing',
        'airbusgroup'                   => 'Airbus',             // NOM OFFICIEL: "Airbus" (pas "Airbus Group")
        'safran'                        => 'Safran',
        'thales'                        => 'Thales',
        'rolls-royce'                   => 'Rolls-Royce',
        'honeywell'                     => 'Honeywell',
        'collins-aerospace'             => 'Collins Aerospace',
        'prattwhitney'                  => 'Pratt & Whitney',
        'dassault-aviation'             => 'Dassault Aviation',
        'mtu-aero-engines'              => 'MTU Aero Engines',
        'textron'                       => 'Textron',
        'bell'                          => 'Bell',
        'leonardo'                      => 'Leonardo',
        'mbda'                          => 'MBDA',
        'rheinmetall'                   => 'Rheinmetall',
        'hensoldt'                      => 'HENSOLDT',           // CASSE OFFICIELLE: tout en majuscules
        // ── Défense ────────────────────────────────────────────────────────────
        'lockheed-martin'               => 'Lockheed Martin',
        'northrop-grumman-corporation'  => 'Northrop Grumman',
        'rtx'                           => 'RTX',
        'bae-systems'                   => 'BAE Systems',
        'general-dynamics'              => 'General Dynamics',
        'l3harris-technologies'         => 'L3Harris Technologies', // "Technologies" inclus
        // ── Airlines ───────────────────────────────────────────────────────────
        'korean-air'                    => 'Korean Air',
        'air-france'                    => 'Air France',
        'lufthansa-group'               => 'Lufthansa Group',
        'united-airlines'               => 'United Airlines',
        'delta-air-lines'               => 'Delta Air Lines',
        'american-airlines'             => 'American Airlines',
        'emirates'                      => 'Emirates',
        'cathay-pacific'                => 'Cathay Pacific',
        // ── Espace ─────────────────────────────────────────────────────────────
        'spacex'                        => 'SpaceX',
        'arianegroup'                   => 'ArianeGroup',
        // ── Aviation d'affaires / MRO / opérateurs ────────────────────────────
        'solairus-aviation'             => 'Solairus Aviation',
        'aerfin-limited'                => 'AerFin',
        'orix-aviation-systems-ltd'     => 'ORIX Aviation',
        'easyjet'                       => 'easyJet',           // CASSE OFFICIELLE: minuscule "easy"
        // ── Agences / institutions ─────────────────────────────────────────────
        'easa'                          => 'EASA',
        'nato'                          => 'NATO',
    ];

    private const KNOWN_URNS = [
        // ── eVTOL / AAM ───────────────────────────────────────────────────────
        'flyarcher'                 => 'urn:li:organization:42881474', // Archer Aviation (slug: flyarcher)
        'jobyaviation'              => 'urn:li:organization:6407329',
        'wisk-aero'                 => 'urn:li:organization:31425081',
        'volocopter'                => 'urn:li:organization:2914824',
        'insitu'                    => 'urn:li:organization:1151664',
        'skygrid'                   => 'urn:li:organization:2333563',
        // ── Constructeurs / OEM ───────────────────────────────────────────────
        'boeing'                    => 'urn:li:organization:1384',
        'airbusgroup'               => 'urn:li:organization:2734',
        'airbus'                    => 'urn:li:organization:3211',
        'safran'                    => 'urn:li:organization:521777',
        'thales'                    => 'urn:li:organization:1951',
        'rolls-royce'               => 'urn:li:organization:3871',
        'honeywell'                 => 'urn:li:organization:1344',
        'collins-aerospace'         => 'urn:li:organization:11695727',
        'prattwhitney'              => 'urn:li:organization:71747137',
        'dassault-aviation'         => 'urn:li:organization:8693',
        'mtu-aero-engines'          => 'urn:li:organization:14146',
        'textron'                   => 'urn:li:organization:3335',
        'bell'                      => 'urn:li:organization:1531',
        'leonardo'                  => 'urn:li:organization:36193',
        'mbda'                      => 'urn:li:organization:164883',
        'rheinmetall'               => 'urn:li:organization:1568176',
        'hensoldt'                  => 'urn:li:organization:17896121',
        // ── Défense ────────────────────────────────────────────────────────────
        'lockheed-martin'           => 'urn:li:organization:1319',
        'northrop-grumman-corporation' => 'urn:li:organization:1412',
        'rtx'                       => 'urn:li:organization:40653509',
        'bae-systems'               => 'urn:li:organization:1882',
        'general-dynamics'          => 'urn:li:organization:1904',
        'l3harris-technologies'     => 'urn:li:organization:40745219',
        // ── Airlines ───────────────────────────────────────────────────────────
        'korean-air'                => 'urn:li:organization:20798',
        'air-france'                => 'urn:li:organization:4242',
        'lufthansa-group'           => 'urn:li:organization:11295773',
        'lufthansa'                 => 'urn:li:organization:4231',
        'united-airlines'           => 'urn:li:organization:2380',
        'delta-air-lines'           => 'urn:li:organization:2272',
        'american-airlines'         => 'urn:li:organization:2640',
        'emirates'                  => 'urn:li:organization:5042',
        'cathay-pacific'            => 'urn:li:organization:7097',
        'easyjet'                   => 'urn:li:organization:8932',
        // ── Aviation d'affaires / MRO / opérateurs ────────────────────────────
        'solairus-aviation'             => 'urn:li:organization:435016',
        'aerfin-limited'                => 'urn:li:organization:1293994',
        'orix-aviation-systems-ltd'     => 'urn:li:organization:5314080',
        // ── Espace ─────────────────────────────────────────────────────────────
        'spacex'                    => 'urn:li:organization:30846',
        'arianegroup'               => 'urn:li:organization:10236541',
        // ── Agences / institutions ─────────────────────────────────────────────
        'easa'                      => 'urn:li:organization:213394',
        'nato'                      => 'urn:li:organization:5636',
    ];

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

        // Buffer = provider unique pour les publications AeroMorning.
        // Les anciens fallbacks (Make.com, API LinkedIn directe) sont désactivés :
        // ils masquaient les erreurs Buffer et rendaient le diagnostic impossible.
        if (!empty(env('BUFFER_API_KEY', '')) && !empty(env('BUFFER_LINKEDIN_CHANNEL_ID', ''))) {
            return $this->postViaBuffer($articleUrl, $news);
        }

        throw new \RuntimeException(
            'Buffer non configuré. Renseignez BUFFER_API_KEY et BUFFER_LINKEDIN_CHANNEL_ID dans .env.'
        );
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
    // Buffer.com
    // -------------------------------------------------------------------------

    /**
     * Publie la news sur LinkedIn via l'API GraphQL Buffer.
     * Buffer est un partenaire LinkedIn approuvé : il peut poster sur une page
     * entreprise sans passer par le Community Management API de LinkedIn.
     * Les @mentions cliquables sont transmises via le champ metadata.linkedin.annotations.
     */
    private function postViaBuffer(string $articleUrl, News $news): array
    {
        $apiKey    = env('BUFFER_API_KEY', '');
        $channelId = env('BUFFER_LINKEDIN_CHANNEL_ID', '');

        $companies   = $this->detectCompanies($news);
        Log::info('Buffer: companies détectées pour le post', [
            'news_id'   => $news->id,
            'news_title'=> $news->title,
            'companies' => array_map(fn($c) => $c['name'] . ' → ' . $c['slug'], $companies),
        ]);

        $postText    = $this->buildBufferPostText($news, $articleUrl, $companies);
        $annotations = $this->buildBufferAnnotations($postText, $companies);

        // ── LinkedIn metadata (annotations + carte article) ─────────────────
        // linkAttachment et annotations vont dans metadata.linkedin (pas à la racine)
        $linkedinMeta = [];

        // LinkAttachmentInput n'accepte que l'URL — Buffer scrappe automatiquement
        // og:title, og:description et og:image depuis l'article WordPress.
        $linkedinMeta['linkAttachment'] = ['url' => $articleUrl];

        if (!empty($annotations)) {
            $linkedinMeta['annotations'] = $annotations;
        }

        // ── Payload principal ────────────────────────────────────────────────
        $input = [
            'channelId'      => $channelId,
            'text'           => $postText,
            'schedulingType' => 'automatic',
            'mode'           => 'shareNow',   // publication immédiate
            'metadata'       => ['linkedin' => $linkedinMeta],
        ];

        // ── Mutation GraphQL ─────────────────────────────────────────────────
        // L'endpoint Buffer est https://api.buffer.com (sans /graphql)
        // La réponse est un union type : PostActionSuccess | MutationError
        $mutation = <<<'GQL'
mutation CreatePost($input: CreatePostInput!) {
    createPost(input: $input) {
        ... on PostActionSuccess {
            post { id text status }
        }
        ... on MutationError      { message }
        ... on InvalidInputError  { message }
        ... on UnauthorizedError  { message }
        ... on LimitReachedError  { message }
        ... on UnexpectedError    { message }
    }
}
GQL;

        $response = Http::withToken($apiKey)
            ->asJson()
            ->timeout(30)
            ->post('https://api.buffer.com', [
                'query'     => $mutation,
                'variables' => ['input' => $input],
            ]);

        Log::info('LinkedIn via Buffer', [
            'news_id'     => $news->id,
            'http_status' => $response->status(),
            'body'        => $response->body(),
            'annotations' => count($annotations),
            'companies'   => array_column($companies, 'name'),
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Buffer API error (HTTP {$response->status()}): " . $response->body()
            );
        }

        // Erreurs GraphQL de niveau protocole
        $gqlErrors = $response->json('errors', []);
        if (!empty($gqlErrors)) {
            $msg = implode(', ', array_column($gqlErrors, 'message'));
            throw new \RuntimeException("Buffer GraphQL error: {$msg}");
        }

        // Union type MutationError
        $mutError = $response->json('data.createPost.message');
        if ($mutError && !$response->json('data.createPost.post')) {
            throw new \RuntimeException("Buffer createPost error: {$mutError}");
        }

        $postId = $response->json('data.createPost.post.id', 'unknown');

        return [
            'success'  => true,
            'post_id'  => $postId,
            'url'      => 'https://www.linkedin.com/company/4869929/admin/page-posts/published/',
            'provider' => 'buffer',
        ];
    }

    /**
     * Retourne le nom officiel LinkedIn d'un slug.
     * Priorité : KNOWN_NAMES → cache linkedin.json (company_names) → nom OpenAI
     * Ce nom est utilisé dans le texte ET dans localizedName pour que LinkedIn valide.
     */
    private function officialName(string $slug, string $fallbackName): string
    {
        // 1. Carte statique vérifiée
        if (isset(self::KNOWN_NAMES[$slug])) {
            return self::KNOWN_NAMES[$slug];
        }
        // 2. Cache persistant (nom scrapé lors d'une résolution d'URN précédente)
        $settings = self::readSettings();
        $names    = $settings['company_names'] ?? [];
        if (!empty($names[$slug])) {
            return $names[$slug];
        }
        // 3. Nom retourné par OpenAI (dernier recours — peut différer du nom LinkedIn)
        return $fallbackName;
    }

    /**
     * Construit le texte du post pour Buffer/LinkedIn.
     * Structure : ✈️ Titre \n\n Excerpt \n\n @Entreprises \n\n #hashtags
     *
     * L'URL de l'article n'est PAS incluse dans le texte : elle est transmise
     * séparément via metadata.linkedin.linkAttachment.url, ce qui permet à
     * Buffer/LinkedIn de générer automatiquement la carte de prévisualisation
     * (og:title, og:description, og:image) sans dupliquer l'URL dans le texte.
     */
    private function buildBufferPostText(News $news, string $articleUrl, array $companies): string
    {
        $excerpt  = $this->buildExcerpt($news, 280);
        $hashtags = $this->buildHashtags($news);
        $title    = $news->title ?? '';

        // L'URL est dans linkAttachment → pas dans le texte
        $text = "✈️ {$title}\n\n{$excerpt}";

        if (!empty($companies)) {
            $parts = [];
            foreach ($companies as $c) {
                $parts[] = '@' . $this->officialName($c['slug'], $c['name']);
            }
            $text .= "\n\n" . implode(' ', $parts);
        }

        $text .= "\n\n{$hashtags}";

        return $text;
    }

    /**
     * Construit le tableau d'annotations Buffer (LinkedIn @mentions cliquables).
     *
     * Contrat Buffer AnnotationInputLinkedIn (documenté officiellement) :
     *
     *   localizedName = "Airbus"
     *   start          = position UTF-16 du "A"  ← premier char du NOM, PAS du "@"
     *   length         = longueur UTF-16 de "Airbus"  ← PAS de "@Airbus"
     *
     * Exemple officiel Buffer :
     *   text: "Check out Buffer's platform @Buffer"
     *   → localizedName: "Buffer", start: 29, length: 6
     *   → text[29 : 29+6] == "Buffer"  ✓
     *
     * Ce que LinkedIn valide :  text[start : start+length] == localizedName
     * (le "@" est immédiatement avant start mais n'est PAS dans l'annotation)
     */
    private function buildBufferAnnotations(string $text, array $companies): array
    {
        if (empty($companies)) {
            Log::debug('LinkedIn annotations: aucune company détectée → pas d\'annotations');
            return [];
        }

        Log::info('LinkedIn annotations: début de résolution', [
            'companies' => array_map(fn($c) => $c['name'] . ' (slug: ' . $c['slug'] . ')', $companies),
        ]);

        $annotations = [];
        $searchFrom  = 0; // position Unicode courante pour éviter de trouver deux fois la même mention

        foreach ($companies as $company) {
            $slug         = trim((string) ($company['slug'] ?? ''));
            if ($slug === '') continue;

            // Nom officiel LinkedIn — DOIT correspondre exactement au texte du post
            $officialName = $this->officialName($slug, $company['name']);
            if ($officialName === '') continue;

            // URN → null si inconnu (annotation impossible → mention reste en texte brut)
            $urn = $this->resolveCompanyUrn($slug);
            if (!$urn) {
                Log::warning("LinkedIn annotations: pas d'URN pour '{$officialName}' (slug: '{$slug}') → texte brut");
                continue;
            }

            // Valider le format URN et extraire l'ID numérique
            if (!preg_match('/^urn:li:organization:(\d+)$/', $urn, $m)) {
                Log::warning("LinkedIn annotations: URN invalide '{$urn}' pour '{$slug}'");
                continue;
            }
            $orgId = $m[1];

            // Localiser "@NomOfficiel" dans le texte
            $mention    = '@' . $officialName;
            $unicodePos = mb_strpos($text, $mention, $searchFrom);
            if ($unicodePos === false) {
                Log::warning("LinkedIn annotations: '{$mention}' introuvable dans le texte (searchFrom={$searchFrom})");
                continue;
            }

            // ── Calcul start / length ─────────────────────────────────────────────
            // start  = position UTF-16 du PREMIER CARACTÈRE du NOM (le char après "@")
            // length = nombre de code units UTF-16 du NOM SEUL (sans "@")
            //
            // ❌ ANCIENNE VERSION (fausse) :
            //   start = utf16PositionOf(text, unicodePos)     ← pointait sur "@"
            //   length = utf16Len("@Airbus")                  ← incluait le "@"
            //
            // ✅ VERSION CORRECTE :
            //   start = utf16PositionOf(text, unicodePos + 1) ← pointe sur "A"
            //   length = utf16Len("Airbus")                   ← NOM SEUL
            $nameUnicodePos = $unicodePos + 1; // sauter le "@"
            $start          = $this->utf16PositionOf($text, $nameUnicodePos);
            $length         = $this->utf16Len($officialName);

            // Validation locale : vérifier que text[start:length] == localizedName
            // avant d'envoyer à Buffer (évite les erreurs "text at position does not match")
            $extracted = $this->utf16Substring($text, $start, $length);
            if ($extracted !== $officialName) {
                Log::error('LinkedIn annotation: validation locale échouée', [
                    'slug'      => $slug,
                    'expected'  => $officialName,
                    'extracted' => $extracted,
                    'start'     => $start,
                    'length'    => $length,
                ]);
                continue;
            }

            // URL de la page LinkedIn (utiliser celle stockée si disponible)
            $link = !empty($company['url'])
                ? $company['url']
                : "https://www.linkedin.com/company/{$slug}/";

            $annotations[] = [
                'id'            => $orgId,
                'entity'        => $urn,
                'length'        => $length,
                'link'          => $link,
                'localizedName' => $officialName,
                'start'         => $start,
                'vanityName'    => $slug,
            ];

            Log::info("LinkedIn annotations: ✅ '{$mention}' → URN {$urn}", [
                'utf16_start'  => $start,
                'utf16_length' => $length,
                'org_id'       => $orgId,
                'validated'    => $extracted,
            ]);

            // Avancer searchFrom après la mention complète (pas juste +1)
            $searchFrom = $unicodePos + mb_strlen($mention);
        }

        Log::info('LinkedIn annotations: résolution terminée', [
            'total_companies'   => count($companies),
            'annotations_built' => count($annotations),
            'slugs_resolved'    => array_column($annotations, 'vanityName'),
        ]);

        return $annotations;
    }

    /**
     * Convertit un index de caractère Unicode (résultat de mb_strpos) en nombre
     * de code units UTF-16 depuis le début de la chaîne.
     * - Caractères BMP  (U+0000–U+FFFF) : 1 code unit (ASCII, accents, ✈️ U+2708…)
     * - Caractères supp (U+10000+)       : 2 code units (🔗 U+1F517, emoji couleurs…)
     */
    private function utf16PositionOf(string $text, int $unicodeCharIndex): int
    {
        $units = 0;
        for ($i = 0; $i < $unicodeCharIndex; $i++) {
            $cp    = mb_ord(mb_substr($text, $i, 1));
            $units += ($cp >= 0x10000) ? 2 : 1;
        }
        return $units;
    }

    /**
     * Extrait la sous-chaîne Unicode correspondant à [start, start+length[ en UTF-16.
     * Utilisé pour valider localement que text[start:length] == localizedName
     * avant d'envoyer l'annotation à Buffer.
     */
    private function utf16Substring(string $text, int $start, int $length): string
    {
        $result    = '';
        $units     = 0;
        $charCount = mb_strlen($text);

        for ($i = 0; $i < $charCount; $i++) {
            $char      = mb_substr($text, $i, 1);
            $cp        = mb_ord($char);
            $charUnits = ($cp >= 0x10000) ? 2 : 1;
            $charEnd   = $units + $charUnits;

            if ($units >= $start && $charEnd <= ($start + $length)) {
                $result .= $char;
            }

            $units = $charEnd;

            if ($units >= $start + $length) {
                break;
            }
        }

        return $result;
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
You are an aerospace, aviation, defense, eVTOL, UAM, AAM, and space industry expert.
Your task: extract the most important companies from the article to @mention on LinkedIn.

PRIORITY RULES (apply in order):
1. Prefer the ACQUIRER over the acquired subsidiary (e.g. Archer > SkyGrid when Archer buys SkyGrid)
2. Prefer publicly traded or large established companies (Airbus, Boeing, Thales…)
3. Prefer the OPERATOR/AIRLINE over the aircraft manufacturer when both mentioned
4. Include regulatory bodies (FAA, EASA…) only if they are the central topic
5. Omit pure subsidiaries with no independent LinkedIn following
6. Maximum 6 companies — quality over quantity

VERIFIED LINKEDIN SLUGS — use EXACT slug for companies listed:
eVTOL / AAM / Drones:
  Archer / Archer Aviation → flyarcher | Joby / Joby Aviation → jobyaviation
  Wisk / Wisk Aero → wisk-aero | Volocopter → volocopter | SkyGrid → skygrid | Insitu → insitu

Airlines:
  Korean Air → korean-air | Air France → air-france | Lufthansa Group → lufthansa-group
  United Airlines → united-airlines | Delta Air Lines → delta-air-lines
  American Airlines → american-airlines | Emirates → emirates | Cathay Pacific → cathay-pacific
  easyJet → easyjet

Aviation services / MRO / operators:
  Solairus Aviation → solairus-aviation
  AerFin / AerFin Limited → aerfin-limited
  ORIX Aviation / ORIX Aviation Systems → orix-aviation-systems-ltd

Manufacturers & OEMs:
  Airbus → airbusgroup | Boeing → boeing | Safran → safran | Thales → thales
  Dassault Aviation → dassault-aviation | Rolls-Royce → rolls-royce
  Pratt & Whitney → prattwhitney | Honeywell → honeywell
  Collins Aerospace → collins-aerospace | Leonardo → leonardo | MBDA → mbda
  Rheinmetall → rheinmetall | Textron → textron | Bell → bell | MTU Aero Engines → mtu-aero-engines

Defense:
  Lockheed Martin → lockheed-martin | Northrop Grumman → northrop-grumman-corporation
  RTX / Raytheon → rtx | BAE Systems → bae-systems
  General Dynamics → general-dynamics | L3Harris → l3harris-technologies

Space & agencies:
  SpaceX → spacex | ArianeGroup → arianegroup | NATO → nato | EASA → easa

IMPORTANT — for companies NOT in the list above:
LinkedIn slugs often include legal suffixes that are NOT in the company's public name.
You MUST provide 2–3 slug candidates, from most to least likely:
  → Examples of how LinkedIn slugs work:
     "Air Lease Corporation" → "air-lease-corporation" (full legal name)
     "Avolon" → "avolon-aerospace-limited" (with legal suffix)
     "Aircastle" → "aircastle" (no suffix needed)
     "Stellex Aerostructures" → "stellex-aerostructures" or "stellex-aerostructures-llc"
  → Always include the plain slug AND versions with -limited, -ltd, -group, -inc, -corporation

Do NOT include AeroMorning (our own publication).

Return ONLY a valid JSON object (no markdown), with this format:
{"companies": [
  {"name": "Official LinkedIn Name", "slug": "best-slug-guess", "slug_alternatives": ["alt1", "alt2"]},
  {"name": "Airbus", "slug": "airbusgroup", "slug_alternatives": []}
]}
slug_alternatives = [] for companies already in the verified list above.

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
        // Utilise Googlebot UA : retourne 404 pour les slugs inexistants (fiable),
        // contrairement au Chrome UA qui renvoie 999 même pour de faux slugs.
        // Pour chaque entreprise, on essaie dans l'ordre :
        //   1. Le slug principal (fourni par OpenAI ou connu statiquement)
        //   2. Les alternatives fournies par OpenAI (slug_alternatives)
        //   3. Des variantes automatiques avec suffixes légaux courants
        $verified = [];
        foreach ($companies as $company) {
            $primarySlug = trim($company['slug']);
            $alternatives = array_map('trim', $company['slug_alternatives'] ?? []);

            $validSlug = $this->discoverValidSlug($primarySlug, $alternatives);

            if ($validSlug !== null) {
                $verified[] = [
                    'name' => $company['name'],
                    'slug' => $validSlug,
                    'url'  => "https://www.linkedin.com/company/{$validSlug}/",
                ];
                if ($validSlug !== $primarySlug) {
                    Log::info("LinkedIn: slug substitué '{$primarySlug}' → '{$validSlug}' pour '{$company['name']}'");
                }
            } else {
                Log::info("LinkedIn: aucun slug valide trouvé pour '{$company['name']}' (testé: {$primarySlug}" .
                    (!empty($alternatives) ? ', ' . implode(', ', $alternatives) : '') . " + variantes auto)");
            }
        }

        // ── Pré-résolution des URNs ────────────────────────────────────────────
        // Buffer bloque la re-publication du même article → l'annotation doit
        // fonctionner AU PREMIER ESSAI. On résout les URNs ici (lors de la détection)
        // pour que le cache linkedin.json soit prêt AVANT l'appel à postViaBuffer().
        // Si la résolution échoue maintenant (429 temporaire), elle sera retentée
        // automatiquement lors de la publication (resolveCompanyUrn cherche en cache
        // puis re-scrape si absent).
        foreach ($verified as $company) {
            $urn = $this->resolveCompanyUrn($company['slug']);
            if ($urn) {
                Log::info("LinkedIn: URN pré-résolu pour '{$company['slug']}' → {$urn}");
            } else {
                Log::warning("LinkedIn: URN non résolu pour '{$company['slug']}' à la détection → mention texte brut si non résolu à la publication");
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
     * Découvre le premier slug LinkedIn valide parmi toutes les possibilités.
     *
     * Stratégie (dans l'ordre, s'arrête au premier succès) :
     *  1. Slug principal (fourni par OpenAI ou KNOWN_URNS)
     *  2. Alternatives explicites fournies par OpenAI (slug_alternatives)
     *  3. Variantes automatiques avec suffixes légaux courants :
     *     -limited, -ltd, -group, -inc, -corporation, -llc,
     *     -systems, -systems-ltd, -aviation, -aerospace, -services, -solutions
     *
     * Retourne le premier slug valide, ou null si aucun ne fonctionne.
     *
     * NOTE : pour éviter le rate-limiting LinkedIn entre chaque test HTTP,
     * on ajoute un délai minimal de 1 s entre les appels réseau.
     * Les slugs connus (KNOWN_URNS) et les 404 déjà en cache ne font PAS
     * d'appel réseau → pas de délai.
     */
    private function discoverValidSlug(string $primary, array $alternatives = []): ?string
    {
        // Tous les candidats à tester dans l'ordre
        $candidates = array_unique(array_filter(array_merge([$primary], $alternatives)));

        // Suffixes légaux courants ajoutés au slug principal (base = sans le dernier tiret-mot)
        // Ex : "aerfin" → "aerfin-limited", "aerfin-ltd", …
        $legalSuffixes = [
            '-limited', '-ltd', '-group', '-inc', '-corporation', '-llc',
            '-systems', '-systems-ltd', '-aviation', '-aerospace', '-services', '-solutions',
        ];
        foreach ($legalSuffixes as $suffix) {
            $candidates[] = $primary . $suffix;
        }

        $needsDelay = false; // On ne met un délai QUE si on fait un appel réseau
        foreach ($candidates as $slug) {
            if (isset(self::KNOWN_URNS[$slug])) {
                return $slug; // Connu statiquement → aucun appel réseau
            }

            if ($needsDelay) {
                sleep(1); // Évite le 429 entre appels LinkedIn successifs
            }

            if ($this->verifyLinkedInSlug($slug)) {
                return $slug;
            }

            // verifyLinkedInSlug fait un appel réseau seulement si le slug
            // n'est ni dans KNOWN_URNS ni dans le cache "404 confirmé".
            // On pose le délai de toute façon pour les cas réels.
            $needsDelay = true;
        }

        return null;
    }

    /**
     * Vérifie qu'un slug LinkedIn company correspond à une vraie page.
     * - Slugs dans KNOWN_URNS → toujours valides (aucun appel réseau)
     * - Cache linkedin.json → '' = 404 déjà confirmé → invalide
     * - Sinon : HTTP GET sur la page LinkedIn
     *   - 404 → invalide
     *   - Tout le reste (200, 429, 999…) → valide (LinkedIn bloque souvent les bots)
     */
    private function verifyLinkedInSlug(string $slug): bool
    {
        // Slug dans la carte statique → immédiatement valide
        if (isset(self::KNOWN_URNS[$slug])) {
            return true;
        }

        // Cache : '' = 404 déjà confirmé → invalide
        $settings = self::readSettings();
        $cache    = $settings['company_urns'] ?? [];
        if (array_key_exists($slug, $cache) && $cache[$slug] === '') {
            return false;
        }

        try {
            // Googlebot UA : LinkedIn répond 200 (SEO content) ou 404 (slug inexistant)
            // Les UAs browser normaux reçoivent 999 même pour des slugs valides → faux négatif
            ['status' => $status] = $this->curlGet(
                "https://www.linkedin.com/company/{$slug}/",
                'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
            );

            // 404 = slug inexistant ; tout le reste (200, 429, 999…) → page existe
            return $status !== 404;
        } catch (\Throwable $e) {
            // Impossible de vérifier (timeout, réseau…) → on fait confiance à OpenAI
            Log::debug("LinkedIn: vérification du slug '{$slug}' impossible: " . $e->getMessage());
            return true;
        }
    }

    /**
     * Retourne l'URN LinkedIn (urn:li:organization:ID) pour un slug donné.
     *
     * Ordre de résolution :
     *   1. Carte statique KNOWN_URNS (aucun appel réseau — instantané)
     *   2. Cache persistant linkedin.json (résultat d'un scraping précédent)
     *   3. Scraping de la page LinkedIn (fallback pour les slugs inconnus)
     *
     * Règles de cache :
     *   - "urn:li:organization:..." = URN confirmé
     *   - ""                        = 404 confirmé (slug inexistant)
     *   - Absent du cache           = jamais essayé ou erreur temporaire → retry
     */
    private function resolveCompanyUrn(string $slug): ?string
    {
        // ── 1. Carte statique (permanente, aucun appel réseau) ─────────────────
        if (isset(self::KNOWN_URNS[$slug])) {
            return self::KNOWN_URNS[$slug];
        }

        // ── 2. Cache persistant ────────────────────────────────────────────────
        $settings = self::readSettings();
        $cache    = $settings['company_urns'] ?? [];
        if (array_key_exists($slug, $cache)) {
            $val = $cache[$slug];
            return !empty($val) ? (string) $val : null;
        }

        // ── 3. Scraping LinkedIn (slug inconnu de la carte statique et du cache) ─
        return $this->scrapeLinkedInUrn($slug);
    }

    /**
     * Scrape la page LinkedIn d'une entreprise pour en extraire l'URN.
     *
     * LinkedIn retourne 999 pour les UAs browser normaux (anti-bot), mais sert
     * son contenu SEO aux crawlers officiels (Googlebot, LinkedInBot).
     * On essaie ces UAs dans l'ordre → met en cache le 1er URN trouvé.
     *
     * Ordre des tentatives :
     *   1. Googlebot  → HTTP 200 dans 90 % des cas, HTML riche avec URN
     *   2. LinkedInBot → fallback si Googlebot échoue (429 ou pas d'URN)
     *
     * Met en cache le résultat dans linkedin.json (company_urns + company_names).
     * NE cache PAS les échecs temporaires (429/999) pour permettre un retry.
     */
    private function scrapeLinkedInUrn(string $slug): ?string
    {
        // UAs testés et validés (août 2026) — LinkedIn sert son HTML SEO à ces bots
        $userAgents = [
            'Googlebot'   => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'LinkedInBot' => 'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)',
        ];

        foreach ($userAgents as $uaName => $ua) {
            try {
                ['status' => $status, 'body' => $body] = $this->curlGet(
                    "https://www.linkedin.com/company/{$slug}/",
                    $ua
                );

                Log::info("LinkedIn URN scrape [{$uaName}]: '{$slug}' → HTTP {$status}");

                if ($status === 404) {
                    // Slug confirmé inexistant → cache définitif pour éviter les retry
                    $settings         = self::readSettings();
                    $cache            = $settings['company_urns'] ?? [];
                    $cache[$slug]     = '';
                    self::writeSettings(['company_urns' => $cache]);
                    Log::info("LinkedIn: '{$slug}' → 404 confirmé, mis en cache comme absent");
                    return null;
                }

                // Extraire l'ID numérique de l'organisation depuis le HTML
                $orgId = null;
                if     (preg_match('/"urn:li:(?:fs_)?organization:(\d+)"/', $body, $m))        $orgId = $m[1];
                elseif (preg_match('/"entityUrn"\s*:\s*"urn:li:company:(\d+)"/', $body, $m))   $orgId = $m[1];
                elseif (preg_match('/data-company-id="(\d+)"/', $body, $m))                    $orgId = $m[1];
                elseif (preg_match('/"companyId"\s*:\s*(\d+)/', $body, $m))                    $orgId = $m[1];

                if ($orgId) {
                    $urn      = "urn:li:organization:{$orgId}";
                    $settings = self::readSettings();
                    $cache    = $settings['company_urns'] ?? [];
                    $cache[$slug] = $urn;

                    // Extraire le nom officiel depuis le <title> (format Googlebot : "Nom - Employees, Jobs…")
                    $officialName = null;
                    if (preg_match('/<title[^>]*>([^<]+?)\s*\|\s*LinkedIn<\/title>/i', $body, $nm)) {
                        $candidate = html_entity_decode(trim($nm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        // Supprimer le suffixe LinkedIn standard " - Employees, Jobs, Stock & Culture"
                        $candidate = preg_replace('/\s*-\s*(?:Employees|Jobs|Products|Company|Overview|About)\b.*/i', '', $candidate);
                        $candidate = trim($candidate);
                        if (!empty($candidate)) $officialName = $candidate;
                    }

                    $update = ['company_urns' => $cache];
                    if ($officialName) {
                        $names        = $settings['company_names'] ?? [];
                        $names[$slug] = $officialName;
                        $update['company_names'] = $names;
                        Log::info("LinkedIn: '{$slug}' → URN {$urn}, nom officiel '{$officialName}' [{$uaName}]");
                    } else {
                        Log::info("LinkedIn: '{$slug}' → URN scrapé: {$urn} [{$uaName}]");
                    }
                    self::writeSettings($update);
                    return $urn;
                }

                // Page existe (200/429/999) mais URN non trouvé dans ce HTML → essayer l'UA suivant
                Log::warning("LinkedIn: '{$slug}' → HTTP {$status} mais URN introuvable [{$uaName}], essai UA suivant");

            } catch (\Throwable $e) {
                Log::warning("LinkedIn: scraping échoué pour '{$slug}' [{$uaName}]: " . $e->getMessage());
            }
        }

        // Tous les UAs épuisés sans URN → NE PAS mettre en cache (erreur temporaire, retry au prochain post)
        Log::warning("LinkedIn: '{$slug}' → aucun UA n'a permis de résoudre l'URN");
        return null;
    }

    /**
     * Effectue un GET HTTP via cURL natif avec un User-Agent personnalisé.
     * Utilisé pour le scraping LinkedIn qui bloque les UAs browser standards (HTTP 999).
     *
     * @return array{status: int, body: string}
     */
    private function curlGet(string $url, string $userAgent): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => [
                "User-Agent: {$userAgent}",
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
        ]);
        $body   = curl_exec($ch) ?: '';
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body];
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
