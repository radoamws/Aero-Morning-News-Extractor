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
        $prompt = $this->buildTitlePrompt($emailContent, 'FR');
        return $this->sanitizeTitle($this->callOpenAI($prompt, 80), $emailContent, 'FR');
    }

    /**
     * Generate English title
     */
    public function generateEnglishTitle(string $emailContent): ?string
    {
        $prompt = $this->buildTitlePrompt($emailContent, 'EN');
        return $this->sanitizeTitle($this->callOpenAI($prompt, 80), $emailContent, 'EN');
    }

    /**
     * Generate French content
     */
    public function generateFrenchContent(string $emailContent, string $titleFr): ?string
    {
                $prompt = $this->buildContentPrompt($emailContent, $titleFr, 'FR');
                return $this->sanitizeHtmlArticle($this->callOpenAI($prompt, 1400), $emailContent, $titleFr, 'FR');
    }

    /**
     * Generate English content
     */
    public function generateEnglishContent(string $emailContent, string $titleEn): ?string
    {
                $prompt = $this->buildContentPrompt($emailContent, $titleEn, 'EN');
                return $this->sanitizeHtmlArticle($this->callOpenAI($prompt, 1400), $emailContent, $titleEn, 'EN');
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
Tu es un classificateur éditorial spécialisé en aéronautique et transport aérien.

CONTEXTE :
Voici le contenu HTML brut d'une news aéronautique.
Le texte peut être français et/ou anglais.

CONTENU DE LA NEWS: 
$newsContent

LISTE DES TAGS:
$tagsList

MÉTHODE D'ANALYSE OBLIGATOIRE :
1. Identifier les entités clés (compagnies aériennes, avions, fabricants, aéroports).
2. Identifier les sujets spécifiques et les domaines concernés.
3. Sélectionner UNIQUEMENT les tags directement pertinents.

FORMAT DE SORTIE :
Retourne les wp_id que tu trouve et séparés par des virgules.
Aucun texte supplémentaire et aucune lettre.
Si aucun tag n'est applicable, retourne vide.

EXEMPLE VALIDE :
1,3,7";

        return $this->sanitizeIdList($this->callOpenAI($prompt, 80), $tags, $newsContent, false, 'tag_name');
    }

    private function buildTitlePrompt(string $content, string $lang): string
    {
        $language = $lang === 'FR' ? 'FRENCH' : 'ENGLISH';

        return "You are an aviation editor.\n"
            . "Write ONE SEO title in {$language} from the article text below.\n"
            . "Rules:\n"
            . "- Use only the {$language} article section.\n"
            . "- Ignore email chrome, confidentiality notices, styles, signatures, reply chains and bilingual sections in the other language.\n"
            . "- Maximum 53 characters.\n"
            . "- If longer, rewrite naturally; do not cut mid-word.\n"
            . "- No HTML. No quotes. No prefix like RE/FW/TR.\n"
            . "- Return only the title.\n\n"
            . $content;
    }

    private function buildContentPrompt(string $content, string $title, string $lang): string
    {
        $language = $lang === 'FR' ? 'FRENCH' : 'ENGLISH';

        return "You are an aviation news extractor.\n"
            . "Extract ONLY the main {$language} news article from the text below.\n"
            . "Rules:\n"
            . "- Keep only the {$language} version.\n"
            . "- Exclude confidentiality notices, style blocks, signatures, contacts, reply chains, headers, footers and unrelated boilerplate.\n"
            . "- Return clean semantic HTML only using <h2>, <p>, <ul>, <li>, <a>, <img> when justified.\n"
            . "- Start with <h2>{$title}</h2>.\n"
            . "- Do not include CSS, <style>, <head>, <body>, <html>, tables, inline office markup or both languages.\n"
            . "- Keep factual content only.\n"
            . "- Return HTML only.\n\n"
            . $content;
    }

    private function buildMetaDescriptionPrompt(string $content, string $lang): string
    {
        $language = $lang === 'FR' ? 'FRENCH' : 'ENGLISH';

        return "Write one plain-text SEO meta description in {$language}.\n"
            . "Rules:\n"
            . "- 110 to 140 characters.\n"
            . "- Plain text only, no HTML, no CSS, no quotes.\n"
            . "- Mention the core aviation topic and one key fact.\n"
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
            . "- No sentence, no punctuation at the end, no HTML.\n"
            . "- Return only the keyphrase.\n\n"
            . strip_tags($content);
    }

    private function sanitizeTitle(?string $title, string $fallbackContent, string $lang): ?string
    {
        $fallbackTitle = $this->extractFallbackTitleFromContent($fallbackContent, $lang);

        $text = $fallbackTitle !== '' ? $fallbackTitle : $this->sanitizePlainText($title ?? '');

        $text = preg_replace('/^(RE|FW|FWD|TR|CP)\s*:\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*[-|:]\s*(version|v)\s*\d+$/i', '', $text) ?? $text;
        return $this->smartLimit($text, 53);
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
        $text = $this->extractMetaSourceText($content);

        if (mb_strlen($text) > 140) {
            $text = $this->smartLimit($text, 140);
        }

        if (mb_strlen($text) < 110) {
            $base = $this->sanitizePlainText(strip_tags($content));
            $text = $this->smartLimit($base, 140);
        }

        return trim($text);
    }

    private function sanitizeKeyphrase(?string $value, string $content, string $lang): ?string
    {
        $title = $this->extractFallbackTitleFromContent($content, $lang);
        $text = $this->extractKeyphraseFromContent($title !== '' ? $title : $content, $lang);

        return $this->smartLimit($text, 60);
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

        return $lang === 'FR' ? 'Actualite aviation' : 'Aviation news update';
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

    /**
     * Call OpenAI API
     */
    private function callOpenAI(string $prompt, int $maxTokens = 500): ?string
    {
        try {
            $response = $this->client->chat()->create([
                'model' => env('OPENAI_MODEL', 'gpt-4-turbo-preview'),
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
