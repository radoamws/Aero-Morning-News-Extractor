<?php

namespace App\Services;

use OpenAI\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OpenAIService
{
    private Client $client;

    public function __construct()
    {
        $this->client = \OpenAI::client(env('OPENAI_API_KEY'));
    }

    /**
     * Generate French title
     */
    public function generateFrenchTitle(string $emailContent): ?string
    {
        return $this->generateTitlePayload($emailContent, $this->extractFallbackTitleFromContent($emailContent, 'FR'), 'FR')['optimized'];
    }

    public function generateFrenchTitlePayload(string $emailContent, string $originalTitle): array
    {
        return $this->generateTitlePayload($emailContent, $originalTitle, 'FR');
    }

    /**
     * Generate English title
     */
    public function generateEnglishTitle(string $emailContent): ?string
    {
        return $this->generateTitlePayload($emailContent, $this->extractFallbackTitleFromContent($emailContent, 'EN'), 'EN')['optimized'];
    }

    public function generateEnglishTitlePayload(string $emailContent, string $originalTitle): array
    {
        return $this->generateTitlePayload($emailContent, $originalTitle, 'EN');
    }

    /**
     * Generate French content
     */
    public function generateFrenchContent(string $emailContent, string $titleFr, ?string $originalTitleFr = null): ?string
    {
        $prompt = $this->buildContentPrompt($emailContent, $titleFr, $originalTitleFr, 'FR');
        $displayTitle = $this->resolveContentH2Title($titleFr, $originalTitleFr);
        return $this->sanitizeHtmlArticle($this->callOpenAI($prompt, 1400), $emailContent, $displayTitle, 'FR');
    }

    /**
     * Generate English content
     */
    public function generateEnglishContent(string $emailContent, string $titleEn, ?string $originalTitleEn = null): ?string
    {
        $prompt = $this->buildContentPrompt($emailContent, $titleEn, $originalTitleEn, 'EN');
        $displayTitle = $this->resolveContentH2Title($titleEn, $originalTitleEn);
        return $this->sanitizeHtmlArticle($this->callOpenAI($prompt, 1400), $emailContent, $displayTitle, 'EN');
    }

    /**
     * Generate French meta description
     */
    public function generateFrenchMetaDescription(string $contentFr): ?string
    {
        $prompt = $this->buildMetaDescriptionPrompt($contentFr, 'FR');
        return $this->sanitizeMetaDescription($this->callOpenAI($prompt, 120), $contentFr, 'FR');
    }

    /**
     * Generate English meta description
     */
    public function generateEnglishMetaDescription(string $contentEn): ?string
    {
        $prompt = $this->buildMetaDescriptionPrompt($contentEn, 'EN');
        return $this->sanitizeMetaDescription($this->callOpenAI($prompt, 120), $contentEn, 'EN');
    }

    /**
     * Generate French focus keyphrase
     */
    public function generateFrenchKeyphrase(string $contentFr): ?string
    {
        $prompt = $this->buildKeyphrasePrompt($contentFr, 'FR');
        return $this->sanitizeKeyphrase($this->callOpenAI($prompt, 40), $contentFr, 'FR');
    }

    /**
     * Generate English focus keyphrase
     */
    public function generateEnglishKeyphrase(string $contentEn): ?string
    {
        $prompt = $this->buildKeyphrasePrompt($contentEn, 'EN');
        return $this->sanitizeKeyphrase($this->callOpenAI($prompt, 40), $contentEn, 'EN');
    }

    /**
     * Classify news into categories
     */
    public function classifyCategories(string $newsContent, array $categories, string $lang = 'FR'): ?string
    {
        if (empty($categories)) {
            return '';
        }

        $categoriesList = implode("\n", array_map(function ($cat) {
            return "- ID: {$cat['wp_id']}, Name: {$cat['categ_name']}";
        }, $categories));

        $prompt = "RÔLE :
Tu es un classificateur éditorial spécialisé en aéronautique et transport aérien.

CONTEXTE :
Voici le contenu HTML brut d'une news aéronautique.
Le texte peut être français et/ou anglais.

CONTENU DE LA NEWS: 
$newsContent

LISTE DES CATÉGORIES:
$categoriesList

--------------------------------------------------

RÈGLE FIXE :
La catégorie \"News\" est TOUJOURS pertinente pour ce contenu.

MÉTHODE D'ANALYSE OBLIGATOIRE :
1. Identifier le SUJET PRINCIPAL de la news.
2. Identifier les SUJETS SECONDAIRES DIRECTEMENT LIÉS au sujet principal.

CRITÈRES DE SÉLECTION :
Une catégorie est pertinente UNIQUEMENT si elle décrit directement :
- le type d'activité principale (ex : transport aérien)
- le secteur industriel concerné
- un aspect financier STRUCTURANT (leasing, investissement, contrat, acteur coté, ...)

FORMAT DE SORTIE :
Retourne les wp_id que tu trouve et séparés par des virgules. Et obligatoirement ajoute en premier le wp_id de la catégorie name = \"news\".
Aucun texte supplémentaire et aucune lettre.

EXEMPLE VALIDE :
1,5,8";

        return $this->sanitizeIdList($this->callOpenAI($prompt, 80), $categories, $newsContent, true, 'categ_name');
    }

    /**
     * Classify news into tags
     */
    public function classifyTags(string $newsContent, array $tags, string $lang = 'FR'): ?string
    {
        if (empty($tags)) {
            return '';
        }

        $tagsList = implode("\n", array_map(function ($tag) {
            return "- ID: {$tag['wp_id']}, Name: {$tag['tag_name']}";
        }, $tags));

        $prompt = "RÔLE :
        Tu es un classificateur éditorial expert en aéronautique, aviation civile et industrie aérospatiale.

        CONTEXTE :
        Tu dois sélectionner uniquement les tags réellement pertinents pour une news aéronautique.
        Le texte peut être en français et/ou anglais.

        CONTENU DE LA NEWS:
        $newsContent

        LISTE DES TAGS DISPONIBLES:
        $tagsList

        ========================
        FILTRES D'EXCLUSION STRICTS (IMPORTANT)
        ========================
        Tu dois REJETER absolument :

        - Tous les tags contenant une DATE explicite ou implicite :
        (ex: 2024, 2025, 2026, jan, feb, march, april, may, june, july, aug, sept, oct, nov, dec, Q1, Q2, Q3, Q4)
        - Tous les tags de temporalité :
        (today, yesterday, this week, recent, update, breaking, etc.)
        - Tous les tags NON liés directement à l’aviation / aerospace :
        - finance générale
        - politique générale
        - sport non aérien
        - technologie non aéronautique
        - business général sans lien aérien

        ========================
        PRIORITÉ ABSOLUE (ACCEPTÉS)
        ========================
        Tu dois privilégier UNIQUEMENT les tags liés à :

        1. COMPAGNIES AÉRIENNES
        - airlines, low-cost, cargo airlines

        2. AÉRONEFS & TECHNOLOGIE
        - aircraft models, engines, avionics, drones, satellites

        3. INDUSTRIE AÉROSPATIALE
        - Airbus, Boeing, Embraer, SpaceX, etc.

        4. AÉROPORTS & INFRASTRUCTURES
        - airports, ATC, air traffic systems

        5. ORGANISATIONS & RÉGULATEURS
        - ICAO, FAA, EASA, IATA, NASA, etc.

        6. PAYS / RÉGIONS UNIQUEMENT SI CONTEXTE AÉRONAUTIQUE
        - pays impliqué dans l’événement aviation

        ========================
        MÉTHODE D'ANALYSE OBLIGATOIRE
        ========================
        1. Identifier les entités aéronautiques principales (compagnies, avions, fabricants, aéroports).
        2. Identifier les organisations et autorités impliquées.
        3. Identifier les pays UNIQUEMENT s’ils sont directement liés à l’événement aérien.
        4. Écarter tous les tags non directement liés à l’industrie aéronautique.
        5. Ne garder que les tags ayant un lien direct avec le contenu de la news.
        6. Classer les tags restants par pertinence éditoriale.

        ========================
        ORDRE DE PRIORITÉ DES TAGS
        ========================
        1. Entités principales aéronautiques (Airbus, Boeing, etc.)
        2. Avions / technologies / programmes spatiaux
        3. Compagnies aériennes
        4. Aéroports / infrastructures
        5. Organisations (FAA, EASA, ICAO, NASA…)
        6. Pays UNIQUEMENT si essentiels au sujet

        ========================
        FORMAT DE SORTIE (TRÈS IMPORTANT)
        ========================
        - Retourne UNIQUEMENT les wp_id
        - Séparés par des virgules
        - Aucun texte, aucun mot, aucun espace
        - Si aucun tag pertinent → retourne vide

        EXEMPLE VALIDE :
        1,3,7";

        return $this->sanitizeIdList(
            $this->callOpenAI($prompt, 80),
            $tags,
            $newsContent,
            false,
            'tag_name'
        );
    }

    public function isAviationRelevant(string $content): bool
    {
        $plainContent = $this->sanitizePlainText($content);
        if ($plainContent === '') {
            return false;
        }

        $excerpt = mb_substr($plainContent, 0, 4000);

        $prompt = "You are filtering incoming emails for an aviation news workflow.\n"
            . "Decide whether the content below contains a real aviation, aerospace, airline, airport, aircraft, defense aviation, air transport, or space news article that is relevant for publication.\n"
            . "Reject emails that are mainly signatures, admin exchanges, personal messages, legal notices, generic business messages, marketing unrelated to aviation, or content without a real publishable aviation news story.\n"
            . "Return only one word: YES or NO.\n\n"
            . $excerpt;

        $response = mb_strtoupper(trim((string) $this->callOpenAI($prompt, 5)));
        if ($response === 'YES') {
            return true;
        }

        if ($response === 'NO') {
            return false;
        }

        return $this->matchesAviationHeuristic($plainContent);
    }

    private function buildTitlePrompt(string $content, string $originalTitle, string $lang): string
    {
        $language = $lang === 'FR' ? 'FRENCH' : 'ENGLISH';

        $cleanOriginalTitle = $this->sanitizePlainText($originalTitle);

        return "You are an aviation editor.\n"
            . "Write ONE compelling SEO news title in {$language} from the article text below.\n"
            . "ORIGINAL SOURCE TITLE: {$cleanOriginalTitle}\n"
            . "Rules:\n"
            . "- Use only the {$language} article section.\n"
            . "- Ignore email chrome, confidentiality notices, styles, signatures, reply chains and bilingual sections in the other language.\n"
            . "- Ignore transfer/page boilerplate such as Top of Form, Bottom of Form, Related Articles, Leave a comment, Topics, Flash News.\n"
            . "- The title must be clear, specific, attractive and newsworthy.\n"
            . "- Strict maximum: 62 characters (including spaces).\n"
            . "- The title must be a short descriptive phrase, not a single entity or keyword.\n"
            . "- Minimum target: 4 words when possible. Never return only 1 or 2 words.\n"
            . "- The title must be directly supported by the first meaningful lines of the article content.\n"
            . "- IMPORTANT: You are NOT allowed to truncate, crop, clip, or cut the title.\n"
            . "- If the original source title is already clear and 62 characters or fewer, keep its meaning and wording as close as possible.\n"
            . "- If the original source title exceeds 62 characters, you MUST fully REWRITE it into a shorter SEO headline.\n"
            . "- The rewrite must preserve the exact news meaning and the main aviation entities.\n"
            . "- Prefer reformulation, compression, and stronger wording instead of shortening.\n"
            . "- Never output incomplete phrases or cut sentences under any circumstances.\n"
            . "- Never end with conjunctions (and, or), prepositions (to, for, of, in), or unfinished ideas.\n"
            . "- The output must always be a complete grammatical sentence fragment suitable as a headline.\n"
            . "- Do not use ellipsis (...) or any form of shortening marker.\n"
            . "- No HTML. No quotes. No prefix like RE/FW/TR.\n"
            . "- Return only the final title.\n\n"
            . $content;
    }

    private function buildContentPrompt(string $content, string $title, ?string $originalTitle, string $lang): string
    {
        $language = $lang === 'FR' ? 'FRENCH' : 'ENGLISH';
        $originalTitle = $this->sanitizePlainText($originalTitle ?? $title);
        $optimizedTitle = $this->sanitizePlainText($title);
        $h2Title = $this->resolveContentH2Title($optimizedTitle, $originalTitle);

        return "You are an aviation news extractor.\n"
            . "Extract ONLY the main {$language} news article from the text below.\n"
            . "Rules:\n"
            . "- Keep only the {$language} version.\n"
            . "- Exclude confidentiality notices, style blocks, signatures, contacts, reply chains, headers, footers and unrelated boilerplate.\n"
            . "- Return clean semantic HTML only.\n"
            . "- Preserve and rebuild the editorial structure with meaningful headings and lists.\n"
            . "- Convert bullet points into proper <ul><li>...</li></ul>.\n"
            . "- Convert numbered lists into proper <ol><li>...</li></ol>.\n"
            . "- Use <h2> for main sections and <h3> for subsections when the source structure justifies it.\n"
            . "- Use <p>, <ul>, <ol>, <li>, <a>, <strong>, <em>, <blockquote>, <h2>, <h3> only when relevant.\n"
            . "- Do not include CSS, <style>, <head>, <body>, <html>, tables, or office markup.\n"
            . "- Keep factual content only.\n"
            . "- Return HTML only.\n\n"

            . "TITLE HANDLING RULE (VERY IMPORTANT):\n"
            . "- OPTIMIZED SEO TITLE: {$optimizedTitle}\n"
            . "- ORIGINAL SOURCE TITLE: {$originalTitle}\n"
            . "- REQUIRED H2 TITLE: {$h2Title}\n"
            . "- You MUST render exactly <h2>{$h2Title}</h2> at the start.\n"
            . "- Never alter, shorten, paraphrase, or replace the H2 title.\n"
            . "- The content must always start immediately after the <h2> title with no extra text.\n\n"

            . "CONTENT STRUCTURE RULE:\n"
            . "- After the <h2> title, immediately output the article content.\n"
            . "- Do not insert any commentary or extra text between title and content.\n\n"

            . $content;
    }

    private function buildMetaDescriptionPrompt(string $content, string $lang): string
    {
        $language = $lang === 'FR' ? 'FRENCH' : 'ENGLISH';

        return "Write one plain-text SEO meta description in {$language}.\n"
            . "Rules:\n"
            . "- Strictly between 107 and 142 characters, spaces included.\n"
            . "- Plain text only, no HTML, no CSS, no quotes.\n"
            . "- Mention the core aviation topic and one key fact.\n"
            . "- Make it attractive for SEO / SEA / GEO and natural for readers.\n"
            . "- Reformulate if needed to stay inside the character range.\n"
            . "- Return only the meta description.\n\n"
            . strip_tags($content);
    }

    private function buildKeyphrasePrompt(string $content, string $lang): string
    {
        $language = $lang === 'FR' ? 'FRENCH' : 'ENGLISH';

        return "Write one SEO focus keyphrase in {$language}.\n"
            . "Rules:\n"
            . "- 2 to 5 words.\n"
            . "- Must identify the central aviation subject.\n"
            . "- Prefer company, aircraft, airport, program or route names when present.\n"
            . "- No comma anywhere.\n"
            . "- No sentence, no punctuation at the end, no HTML.\n"
            . "- Reformulate if needed to remove separators and keep the phrase SEO-friendly.\n"
            . "- Return only the keyphrase.\n\n"
            . strip_tags($content);
    }

    private function sanitizeTitle(?string $title, string $originalTitle, string $fallbackContent, string $lang): ?string
    {
        $fallbackTitle = $this->extractFallbackTitleFromContent($fallbackContent, $lang);
        $normalizedOriginalTitle = $this->normalizeTitleCandidate($originalTitle);
        $candidate = $this->normalizeTitleCandidate($title ?? '');

        if ($normalizedOriginalTitle !== '' && mb_strlen($normalizedOriginalTitle) <= 62 && $this->isValidOptimizedTitle($normalizedOriginalTitle) && $this->isTitleRelatedToContent($normalizedOriginalTitle, $fallbackContent)) {
            return $normalizedOriginalTitle;
        }

        if ($candidate !== '' && $this->isValidOptimizedTitle($candidate) && $this->isTitleRelatedToContent($candidate, $fallbackContent)) {
            return $candidate;
        }

        $rewriteSource = $candidate !== '' ? $candidate : ($normalizedOriginalTitle !== '' ? $normalizedOriginalTitle : $fallbackTitle);
        $rewritten = $this->rewriteTitleToFit($rewriteSource, $fallbackContent, $lang);
        if ($rewritten !== '') {
            return $rewritten;
        }

        return $this->buildTitleFallbackWithoutTruncation($normalizedOriginalTitle !== '' ? $normalizedOriginalTitle : $fallbackTitle, $lang);
    }

    private function sanitizeHtmlArticle(?string $html, string $fallbackContent, string $title, string $lang): ?string
    {
        $candidate = trim((string) $html);

        if ($candidate === '' || strpos($candidate, '<') === false) {
            $candidate = '';
        }

        if ($candidate !== '') {
            $candidate = preg_replace('/<(html|head|body|style|table|tbody|thead|tfoot|tr|td|th)[^>]*>/i', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/<\/(html|head|body|style|table|tbody|thead|tfoot|tr|td|th)>/i', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/\sstyle="[^"]*"/i', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/\sclass="[^"]*"/i', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/\sid="[^"]*"/i', '', $candidate) ?? $candidate;
            $candidate = strip_tags($candidate, '<h1><h2><h3><h4><p><ul><ol><li><a><img><strong><em><blockquote><br>');
            $candidate = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $text = trim(strip_tags($candidate));
        if ($text === '' || mb_strlen($text) < 120) {
            $candidate = '<h2>' . e($title) . '</h2><p>' . nl2br(e(trim(strip_tags($fallbackContent)))) . '</p>';
        }

        return trim($candidate);
    }

    private function sanitizeMetaDescription(?string $value, string $content, string $lang): ?string
    {
        $candidate = $this->sanitizePlainText($value ?? '');
        if ($candidate !== '') {
            $candidate = $this->fitMetaDescriptionLength($candidate, $lang);
            if ($this->isMetaDescriptionLengthValid($candidate)) {
                return $candidate;
            }
        }

        $text = $this->extractMetaSourceText($content);
        if ($text === '') {
            $text = $this->sanitizePlainText(strip_tags($content));
        }

        return $this->fitMetaDescriptionLength($text, $lang);
    }

    private function sanitizeKeyphrase(?string $value, string $content, string $lang): ?string
    {
        $text = $this->sanitizePlainText($value ?? '');
        if ($text === '') {
            $title = $this->extractFallbackTitleFromContent($content, $lang);
            $text = $this->extractKeyphraseFromContent($title !== '' ? $title : $content, $lang);
        }

        return $this->fitKeyphrase($text, $content, $lang);
    }

    private function isMetaDescriptionLengthValid(string $text): bool
    {
        $length = mb_strlen(trim($text));
        return $length >= 107 && $length <= 142;
    }

    private function fitMetaDescriptionLength(string $text, string $lang): string
    {
        $text = $this->sanitizePlainText($text);
        $text = preg_replace('/\bsource\s*:\s*.+$/i', '', $text) ?? $text;
        $text = trim($text, " ,;:-");

        if (mb_strlen($text) > 142) {
            $text = $this->smartLimit($text, 142);
        }

        if (mb_strlen($text) < 107) {
            $suffix = $lang === 'FR'
                ? ' Les enjeux du secteur sont a suivre.'
                : ' The broader aviation impact is worth watching.';

            if (!str_ends_with($text, '.')) {
                $text .= '.';
            }

            if (mb_strlen($text . $suffix) <= 142) {
                $text .= $suffix;
            }
        }

        if (mb_strlen($text) < 107) {
            $baseWords = preg_split('/\s+/', $this->sanitizePlainText($text)) ?: [];
            while (mb_strlen($text) < 107 && !empty($baseWords)) {
                $text .= ' ' . end($baseWords);
            }
        }

        if (mb_strlen($text) > 142) {
            $text = $this->smartLimit($text, 142);
        }

        return trim($text, " ,;:-");
    }

    private function fitKeyphrase(string $text, string $content, string $lang): string
    {
        $text = str_replace(',', ' ', $text);
        $text = preg_replace('/[;:.!?\/\\|]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);

        $words = array_values(array_filter(
            preg_split('/\s+/', $text) ?: [],
            static fn ($word) => $word !== ''
        ));

        if (count($words) < 2) {
            $fallback = preg_split('/\s+/', $this->extractKeyphraseFromContent($content, $lang)) ?: [];
            $words = array_values(array_filter(array_merge($words, $fallback), static fn ($word) => $word !== ''));
        }

        $words = array_slice($words, 0, 5);

        if (count($words) < 2) {
            $words = $lang === 'FR' ? ['actualite', 'aviation'] : ['aviation', 'news'];
        }

        return implode(' ', $words);
    }

    private function sanitizeIdList(?string $value, array $items, string $content, bool $includeNewsDefault, string $nameField): string
    {
        $allowedIds = array_map(static fn ($item) => (string) $item['wp_id'], $items);
        $parts = preg_split('/[^0-9]+/', (string) $value) ?: [];
        $ids = [];

        foreach ($parts as $part) {
            if ($part !== '' && in_array($part, $allowedIds, true) && !in_array($part, $ids, true)) {
                $ids[] = $part;
            }
        }

        if ($includeNewsDefault) {
            foreach ($items as $item) {
                if (mb_strtolower((string) $item[$nameField]) === 'news' && !in_array((string) $item['wp_id'], $ids, true)) {
                    array_unshift($ids, (string) $item['wp_id']);
                    break;
                }
            }
        }

        $contentText = mb_strtolower($this->sanitizePlainText(strip_tags($content)));
        foreach ($items as $item) {
            $name = mb_strtolower((string) $item[$nameField]);
            if ($name !== '' && str_contains($contentText, $name) && !in_array((string) $item['wp_id'], $ids, true)) {
                $ids[] = (string) $item['wp_id'];
            }
        }

        return implode(',', array_slice($ids, 0, $includeNewsDefault ? 6 : 12));
    }

    private function sanitizePlainText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B\"'");
        return trim($text);
    }

    private function generateTitlePayload(string $emailContent, string $originalTitle, string $lang): array
    {
        $cleanOriginalTitle = $this->normalizeTitleCandidate($originalTitle);
        if ($cleanOriginalTitle === '') {
            $cleanOriginalTitle = $this->extractFallbackTitleFromContent($emailContent, $lang);
        }

        $prompt = $this->buildTitlePrompt($emailContent, $cleanOriginalTitle, $lang);
        $optimizedTitle = $this->sanitizeTitle($this->callOpenAI($prompt, 80), $cleanOriginalTitle, $emailContent, $lang);

        if (!$optimizedTitle) {
            $optimizedTitle = $this->buildTitleFallbackWithoutTruncation($cleanOriginalTitle, $lang);
        }

        return [
            'original' => $cleanOriginalTitle,
            'optimized' => $optimizedTitle,
            'use_original_in_h2' => mb_strlen($cleanOriginalTitle) > 62,
        ];
    }

    private function resolveContentH2Title(string $optimizedTitle, ?string $originalTitle): string
    {
        $cleanOriginalTitle = $this->normalizeTitleCandidate($originalTitle ?? '');
        if ($cleanOriginalTitle !== '' && mb_strlen($cleanOriginalTitle) > 62) {
            return $cleanOriginalTitle;
        }

        return $this->normalizeTitleCandidate($optimizedTitle);
    }

    private function normalizeTitleCandidate(string $title): string
    {
        $title = $this->sanitizePlainText($title);
        $title = preg_replace('/^(RE|FW|FWD|TR|CP)\s*:\s*/i', '', $title) ?? $title;
        $title = preg_replace('/\s*[-|:]\s*(version|v)\s*\d+$/i', '', $title) ?? $title;
        return trim($title, " ,;:-");
    }

    private function isValidOptimizedTitle(string $title): bool
    {
        if ($title === '' || mb_strlen($title) > 62) {
            return false;
        }

        if (preg_match('/\.\.\.|…$/u', $title) === 1) {
            return false;
        }

        if ($this->endsWithDanglingTitleWord($title)) {
            return false;
        }

        return $this->isDescriptiveTitle($title);
    }

    private function isDescriptiveTitle(string $title): bool
    {
        $words = array_values(array_filter(
            preg_split('/\s+/', trim($title)) ?: [],
            static fn ($word) => $word !== ''
        ));

        if (count($words) <= 2) {
            return false;
        }

        return mb_strlen(trim($title)) >= 16;
    }

    private function endsWithDanglingTitleWord(string $title): bool
    {
        $lastWord = mb_strtolower((string) preg_replace('/^.*\s/u', '', trim($title)));

        $danglingWords = [
            'and', 'or', 'to', 'for', 'of', 'in', 'on', 'with', 'without', 'from', 'by',
            'et', 'ou', 'de', 'du', 'des', 'dans', 'sur', 'pour', 'avec', 'sans', 'chez',
        ];

        return in_array($lastWord, $danglingWords, true);
    }

    private function rewriteTitleToFit(string $sourceTitle, string $content, string $lang): string
    {
        $language = $lang === 'FR' ? 'FRENCH' : 'ENGLISH';
        $prompt = "Rewrite this aviation news title in {$language}.\n"
            . "SOURCE TITLE: " . $this->normalizeTitleCandidate($sourceTitle) . "\n"
            . "Rules:\n"
            . "- Maximum 62 characters including spaces.\n"
            . "- Minimum 3 words.\n"
            . "- Must be a descriptive phrase summarizing the article.\n"
            . "- Never return only a city, company, country or program name.\n"
            . "- Preserve the exact meaning and the key aviation entities.\n"
            . "- Do not truncate, crop, or end on an incomplete word.\n"
            . "- Do not use ellipsis.\n"
            . "- Return only the rewritten title.\n\n"
            . $content;

        $rewritten = $this->normalizeTitleCandidate($this->callOpenAI($prompt, 60) ?? '');
        if ($this->isValidOptimizedTitle($rewritten) && $this->isTitleRelatedToContent($rewritten, $content)) {
            return $rewritten;
        }

        return '';
    }

    private function buildTitleFallbackWithoutTruncation(string $sourceTitle, string $lang): string
    {
        $sourceTitle = $this->normalizeTitleCandidate($sourceTitle);
        if ($this->isValidOptimizedTitle($sourceTitle) && !$this->isForbiddenTitleCandidate($sourceTitle)) {
            return $sourceTitle;
        }

        foreach ([' : ', ' – ', ' - ', ' | ', ' — ', '; '] as $separator) {
            $parts = array_values(array_filter(array_map('trim', explode($separator, $sourceTitle)), static fn ($part) => $part !== ''));
            foreach ($parts as $part) {
                if ($this->isValidOptimizedTitle($part) && !$this->isForbiddenTitleCandidate($part)) {
                    return $part;
                }
            }
        }

        $words = preg_split('/\s+/', $sourceTitle) ?: [];
        $compressed = [];
        foreach ($words as $word) {
            $candidate = trim(implode(' ', [...$compressed, $word]));
            if (mb_strlen($candidate) > 62) {
                break;
            }
            $compressed[] = $word;
        }

        $fallback = trim(implode(' ', $compressed));
        while ($fallback !== '' && $this->endsWithDanglingTitleWord($fallback)) {
            $segments = preg_split('/\s+/', $fallback) ?: [];
            array_pop($segments);
            $fallback = trim(implode(' ', $segments));
        }

        if ($this->isValidOptimizedTitle($fallback) && !$this->isForbiddenTitleCandidate($fallback)) {
            return $fallback;
        }

        return $sourceTitle;
    }

    private function smartLimit(string $text, int $maxLength): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $short = trim(mb_substr($text, 0, $maxLength + 1));
        $lastSpace = mb_strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace >= (int) floor($maxLength * 0.6)) {
            return rtrim(mb_substr($short, 0, $lastSpace), " ,;:-");
        }

        return rtrim(mb_substr($text, 0, $maxLength), " ,;:-");
    }

    private function extractFallbackTitleFromContent(string $content, string $lang): string
    {
        $text = $this->sanitizePlainText($content);
        $lines = preg_split('/\n+/', trim($content)) ?: [];

        foreach ($lines as $line) {
            $line = $this->sanitizePlainText($line);
            if (
                mb_strlen($line) >= 12
                && mb_strlen($line) <= 120
                && !str_starts_with($line, '•')
                && !preg_match('/^aeromorning\b/i', $line)
                && !preg_match('/^[0-9]+[.)]/', $line)
                && !$this->isForbiddenTitleCandidate($line)
                && !$this->looksLikeAddressLine($line)
            ) {
                return $line;
            }
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (mb_strlen($sentence) >= 20) {
                return $sentence;
            }
        }

        return '';
    }

    private function looksLikeAddressLine(string $line): bool
    {
        if (preg_match('/\b\d{3,}\b/', $line) === 1) {
            return true;
        }

        if (preg_match('/\b(route|rue|avenue|street|road|pointe|maurice|ile|island|po box)\b/i', $line) === 1) {
            return true;
        }

        $parts = array_filter(array_map('trim', explode(',', $line)), static fn ($part) => $part !== '');
        return count($parts) >= 3;
    }

    private function isForbiddenTitleCandidate(string $title): bool
    {
        $title = mb_strtolower(trim($title));
        if ($title === '') {
            return true;
        }

        $forbiddenPatterns = [
            '/^(top|bottom) of form$/i',
            '/^related articles$/i',
            '/^leave a comment$/i',
            '/^additional links$/i',
            '/^topics\s*:/i',
            '/^flash news$/i',
            '/^posted by\s*:/i',
            '/^source\s*:/i',
        ];

        foreach ($forbiddenPatterns as $pattern) {
            if (preg_match($pattern, $title) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isTitleRelatedToContent(string $title, string $content): bool
    {
        if ($this->isForbiddenTitleCandidate($title)) {
            return false;
        }

        $normalizedContent = mb_strtolower($this->sanitizePlainText($content));
        if ($normalizedContent === '') {
            return true;
        }

        $titleWords = preg_split('/\s+/', mb_strtolower($this->sanitizePlainText($title))) ?: [];
        $titleWords = array_values(array_filter($titleWords, static function (string $word): bool {
            return mb_strlen($word) >= 4 && !in_array($word, [
                'avec', 'pour', 'dans', 'from', 'with', 'this', 'that', 'les', 'des', 'une', 'the', 'over', 'into', 'renforce'
            ], true);
        }));

        if (empty($titleWords)) {
            return false;
        }

        $matches = 0;
        foreach ($titleWords as $word) {
            if (str_contains($normalizedContent, $word)) {
                $matches++;
            }
        }

        return $matches >= min(2, count($titleWords));
    }

    private function extractKeyphraseFromContent(string $content, string $lang): string
    {
        preg_match_all('/\b[A-Z][A-Za-z0-9\-]{2,}(?:\s+[A-Z0-9][A-Za-z0-9\-]{1,}){0,3}\b/u', strip_tags($content), $matches);
        $phrases = array_values(array_filter($matches[0] ?? [], static fn ($value) => mb_strlen(trim($value)) >= 4));

        if (!empty($phrases)) {
            return trim($phrases[0]);
        }

        $words = preg_split('/\s+/', $this->sanitizePlainText(strip_tags($content))) ?: [];
        return implode(' ', array_slice(array_filter($words), 0, 4));
    }

    private function looksRepeated(string $text): bool
    {
        if (mb_strlen($text) < 40) {
            return false;
        }

        $prefix = mb_substr($text, 0, 30);
        return mb_substr_count($text, $prefix) > 1;
    }

    private function extractMetaSourceText(string $content): string
    {
        $lines = preg_split('/\n+/', trim(strip_tags($content))) ?: [];
        $usable = [];

        foreach ($lines as $line) {
            $line = $this->sanitizePlainText($line);
            if (
                $line === ''
                || preg_match('/^aeromorning\b/i', $line)
                || preg_match('/^[0-9]+[.)]/', $line)
            ) {
                continue;
            }

            $usable[] = $line;
        }

        if (count($usable) > 1) {
            array_shift($usable);
        }

        return $this->sanitizePlainText(implode(' ', array_slice($usable, 0, 3)));
    }

    private function matchesAviationHeuristic(string $content): bool
    {
        $content = mb_strtolower($content);

        $keywords = [
            'aviation', 'aeronaut', 'aerosp', 'airline', 'airport', 'aircraft', 'flight', 'fleet',
            'boeing', 'airbus', 'embraer', 'atr', 'engine', 'faa', 'easa', 'iata', 'icao',
            'nasa', 'spacex', 'satellite', 'rocket', 'launch', 'orbital', 'lunar', 'air cargo',
            'transport aerien', 'transport aérien', 'compagnie aerienne', 'compagnie aérienne',
            'aeroport', 'aéroport', 'avion', 'vol', 'espace', 'spatial', 'helicopter', 'helicoptere', 'hélicoptère'
        ];

        $score = 0;
        foreach ($keywords as $keyword) {
            if (str_contains($content, $keyword)) {
                $score++;
            }
        }

        return $score >= 2;
    }

    /**
     * Call OpenAI API
     */
    private function callOpenAI(string $prompt, int $maxTokens = 500): ?string
    {
        try {
            $response = $this->client->chat()->create([
                'model' => env('OPENAI_MODEL', 'gpt-5-mini'),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => $maxTokens,
                'temperature' => 0.3
            ]);

            return trim($response->choices[0]->message->content);
        } catch (\Exception $e) {
            Log::error('OpenAI API error: ' . $e->getMessage());
            return null;
        }
    }
}
