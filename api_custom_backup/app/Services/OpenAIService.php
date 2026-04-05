<?php

namespace App\Services;

use OpenAI\Client;
use Illuminate\Support\Facades\Log;

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
        $prompt = "Task: Extract a SEO-optimized news title in FRENCH from an email containing aviation news.

--------------------------------------------------

GOAL:
Generate a clean meta title suitable for WordPress SEO usage (Yoast / RankMath), based strictly on the email content.

--------------------------------------------------

REQUIREMENTS:
- Output language: FRENCH (do NOT translate if already French content exists)
- Length: 1 to 53 characters maximum (STRICT)
- Must represent the main news topic accurately
- Must be SEO-friendly and readable

--------------------------------------------------

ANALYSIS INSTRUCTIONS:
Carefully analyze the full email content, including:
- Main aviation news topic
- Key facts (airline, aircraft, airport, event)
- Technical or operational details
- Timeline or important implications

Ignore:
- Email headers (FW, RE, FWD, CC, TR, CP)
- Signatures
- Greetings and closing text
- Quoted replies or forwarded chains
- Metadata or unrelated content

--------------------------------------------------

TITLE GENERATION RULES:
- Extract or rewrite a meaningful headline
- If the original title is too long (>53 characters), intelligently shorten it without losing meaning
- If no clear title exists, create a concise SEO title based on the main subject
- Prioritize clarity, relevance, and search intent

--------------------------------------------------

CLEANING RULES (CRITICAL):
- Remove invalid or unsafe characters
- Remove brackets [ ], unnecessary punctuation, or email artifacts
- Ensure output is safe for JSON and WordPress REST API
- Preserve valid text formatting only (no unnecessary HTML unless already clean and meaningful)

--------------------------------------------------

OUTPUT RULES:
- Return ONLY the final title
- No explanations
- No JSON
- No formatting tags unless naturally part of clean HTML text

EMAIL CONTENT:
$emailContent";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate English title
     */
    public function generateEnglishTitle(string $emailContent): ?string
    {
        $prompt = "Task: Extract a SEO-optimized news title in ENGLISH from an email containing aviation news.

--------------------------------------------------

GOAL:
Generate a clean meta title suitable for WordPress SEO usage (Yoast / RankMath), based strictly on the email content.

--------------------------------------------------

REQUIREMENTS:
- Output language: ENGLISH (do NOT translate if already ENGLISH content exists)
- Length: 1 to 53 characters maximum (STRICT)
- Must represent the main news topic accurately
- Must be SEO-friendly and readable

--------------------------------------------------

ANALYSIS INSTRUCTIONS:
Carefully analyze the full email content, including:
- Main aviation news topic
- Key facts (airline, aircraft, airport, event)
- Technical or operational details
- Timeline or important implications

Ignore:
- Email headers (FW, RE, FWD, CC, TR, CP)
- Signatures
- Greetings and closing text
- Quoted replies or forwarded chains
- Metadata or unrelated content

--------------------------------------------------

TITLE GENERATION RULES:
- Extract or rewrite a meaningful headline
- If the original title is too long (>53 characters), intelligently shorten it without losing meaning
- If no clear title exists, create a concise SEO title based on the main subject
- Prioritize clarity, relevance, and search intent

--------------------------------------------------

CLEANING RULES (CRITICAL):
- Remove invalid or unsafe characters
- Remove brackets [ ], unnecessary punctuation, or email artifacts
- Ensure output is safe for JSON and WordPress REST API
- Preserve valid text formatting only (no unnecessary HTML unless already clean and meaningful)

--------------------------------------------------

OUTPUT RULES:
- Return ONLY the final title
- No explanations
- No JSON
- No formatting tags unless naturally part of clean HTML text

EMAIL CONTENT:
$emailContent";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate French content
     */
    public function generateFrenchContent(string $emailContent, string $titleFr): ?string
    {
        $prompt = "Task: Extract the main aviation news article content in CLEAN HTML (FRENCH) from an email.

--------------------------------------------------

GOAL:
Generate a structured, WordPress-ready HTML article from the email content.

Language: FRENCH (do NOT translate if already in French)

Output: STRICTLY HTML only (no JSON, no explanations)

--------------------------------------------------

CORE INSTRUCTIONS:

Extract ONLY the main article content from the email while preserving its structure and meaning.

--------------------------------------------------

TITLE HANDLING RULE:
- If the provided FR title (TITRE_FR) exceeds 53 characters,
insert it as:
<h2>Title</h2>
at the very beginning of the article content.

TITRE_FR: $titleFr

--------------------------------------------------

HTML PRESERVATION RULES:
Keep and preserve:
- <p> paragraphs
- <a> links
- <img> images
- <ul>, <li> lists
- <h2> sections when relevant

Ensure clean, semantic structure suitable for WordPress rendering.

--------------------------------------------------

REMOVE COMPLETELY:
- Email headers (FW, RE, FWD, CC, TR, CP)
- Greetings and signatures
- Contact details (emails, phone numbers, addresses)
- Social media links unrelated to the article
- Email layout tables and formatting-only structures
- Logos, headers, and footers not part of the article
- Tracking content or boilerplate text

--------------------------------------------------

CLEANING RULES (CRITICAL FOR WORDPRESS):
- Remove all invalid or unsafe characters
- Ensure output is safe for JSON POST and WordPress REST API
- Escape or remove:
  * control characters
  * broken Unicode
  * unescaped quotes
  * malformed symbols

--------------------------------------------------

CONTENT INTEGRITY RULES:
- Do NOT summarize
- Do NOT rewrite
- Do NOT translate
- Preserve full article depth and all factual content
- Include all available paragraphs, details, and embedded media

--------------------------------------------------

SOURCE CLEANING:
Remove any occurrence of:
- \"/PRNewswire/\"
- \"BUSINESS WIRE\"
- \"BUSINESSWIRE\"
- \"/<xxx>/\" (dynamic placeholder patterns)

--------------------------------------------------

SOURCE EXTRACTION RULE:
If no explicit source is mentioned in the article:
- Search within the email content for the original source name
- Append at the END of the article in this format:

Source: <source_name>

--------------------------------------------------

OUTPUT RULE:
Return ONLY clean HTML content.
No explanations, no metadata, no JSON.

EMAIL CONTENT:
$emailContent";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate English content
     */
    public function generateEnglishContent(string $emailContent, string $titleEn): ?string
    {
        $prompt = "Task: Extract the main aviation news article content in CLEAN HTML (ENGLISH) from an email.

--------------------------------------------------

GOAL:
Generate a structured, WordPress-ready HTML article from the email content.

Language: ENGLISH (do NOT translate if already in ENGLISH)

Output: STRICTLY HTML only (no JSON, no explanations)

--------------------------------------------------

CORE INSTRUCTIONS:

Extract ONLY the main article content from the email while preserving its structure and meaning.

--------------------------------------------------

TITLE HANDLING RULE:
- If the provided EN title (TITRE_EN) exceeds 53 characters,
insert it as:
<h2>Title</h2>
at the very beginning of the article content.

TITRE_EN: $titleEn

--------------------------------------------------

HTML PRESERVATION RULES:
Keep and preserve:
- <p> paragraphs
- <a> links
- <img> images
- <ul>, <li> lists
- <h2> sections when relevant

Ensure clean, semantic structure suitable for WordPress rendering.

--------------------------------------------------

REMOVE COMPLETELY:
- Email headers (FW, RE, FWD, CC, TR, CP)
- Greetings and signatures
- Contact details (emails, phone numbers, addresses)
- Social media links unrelated to the article
- Email layout tables and formatting-only structures
- Logos, headers, and footers not part of the article
- Tracking content or boilerplate text

--------------------------------------------------

CLEANING RULES (CRITICAL FOR WORDPRESS):
- Remove all invalid or unsafe characters
- Ensure output is safe for JSON POST and WordPress REST API
- Escape or remove:
  * control characters
  * broken Unicode
  * unescaped quotes
  * malformed symbols

--------------------------------------------------

CONTENT INTEGRITY RULES:
- Do NOT summarize
- Do NOT rewrite
- Do NOT translate
- Preserve full article depth and all factual content
- Include all available paragraphs, details, and embedded media

--------------------------------------------------

SOURCE CLEANING:
Remove any occurrence of:
- \"/PRNewswire/\"
- \"BUSINESS WIRE\"
- \"BUSINESSWIRE\"
- \"/<xxx>/\" (dynamic placeholder patterns)

--------------------------------------------------

SOURCE EXTRACTION RULE:
If no explicit source is mentioned in the article:
- Search within the email content for the original source name
- Append at the END of the article in this format:

Source: <source_name>

--------------------------------------------------

OUTPUT RULE:
Return ONLY clean HTML content.
No explanations, no metadata, no JSON.

EMAIL CONTENT:
$emailContent";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate French meta description
     */
    public function generateFrenchMetaDescription(string $contentFr): ?string
    {
        $prompt = "Task: Generate an SEO meta description in FRENCH based on a portion of the French news content.

--------------------------------------------------

GOAL:
Create a clean, engaging meta description suitable for WordPress SEO (Yoast / RankMath).

--------------------------------------------------

REQUIREMENTS:
- Output language: FRENCH
- Length: STRICTLY between 106 and 141 characters (including spaces)
- Output type: plain text only (NO HTML, NO JSON)

--------------------------------------------------

CONTENT INSTRUCTIONS:
- Extract key information from the provided news content
- Focus on:
  * main aviation topic
  * key fact or event
  * relevant entities (airline, aircraft, airport, manufacturer)

- Make it clear, concise, and SEO-friendly
- Must encourage user engagement (click intent)

--------------------------------------------------

CLEANING RULES (CRITICAL):
Ensure the output is safe for:
- JSON POST requests
- WordPress REST API

Therefore:
- Remove or replace all invalid characters
- Remove control characters
- Fix or remove broken Unicode
- Remove unescaped quotes or malformed symbols

--------------------------------------------------

STRICT RULES:
- Do NOT include HTML
- Do NOT include JSON
- Do NOT summarize excessively (keep factual accuracy)
- Do NOT invent information not present in the source
- Do NOT exceed or go below the character limits

--------------------------------------------------

OUTPUT RULE:
Return ONLY the final meta description as plain text.

CONTENT:
$contentFr";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate English meta description
     */
    public function generateEnglishMetaDescription(string $contentEn): ?string
    {
        $prompt = "Task: Generate an SEO meta description in ENGLISH based on a portion of the ENGLISH news content.

--------------------------------------------------

GOAL:
Create a clean, engaging meta description suitable for WordPress SEO (Yoast / RankMath).

--------------------------------------------------

REQUIREMENTS:
- Output language: ENGLISH
- Length: STRICTLY between 106 and 141 characters (including spaces)
- Output type: plain text only (NO HTML, NO JSON)

--------------------------------------------------

CONTENT INSTRUCTIONS:
- Extract key information from the provided news content
- Focus on:
  * main aviation topic
  * key fact or event
  * relevant entities (airline, aircraft, airport, manufacturer)

- Make it clear, concise, and SEO-friendly
- Must encourage user engagement (click intent)

--------------------------------------------------

CLEANING RULES (CRITICAL):
Ensure the output is safe for:
- JSON POST requests
- WordPress REST API

Therefore:
- Remove or replace all invalid characters
- Remove control characters
- Fix or remove broken Unicode
- Remove unescaped quotes or malformed symbols

--------------------------------------------------

STRICT RULES:
- Do NOT include HTML
- Do NOT include JSON
- Do NOT summarize excessively (keep factual accuracy)
- Do NOT invent information not present in the source
- Do NOT exceed or go below the character limits

--------------------------------------------------

OUTPUT RULE:
Return ONLY the final meta description as plain text.

CONTENT:
$contentEn";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate French focus keyphrase
     */
    public function generateFrenchKeyphrase(string $contentFr): ?string
    {
        $prompt = "Task: Generate a SEO Focus Keyphrase in FRENCH based on the content of the French news article content.

--------------------------------------------------

GOAL:
Extract or create a highly relevant focus keyphrase for SEO (WordPress / Yoast / RankMath).

--------------------------------------------------

REQUIREMENTS:
- Output language: FRENCH
- Output type: plain text only (NO HTML, NO JSON)

--------------------------------------------------

KEYPHRASE RULES:
- Must represent the main topic of the aviation news article
- Must be SEO-relevant and searchable
- Length: 2 to 5 words (STRICT)
- Must include at least one key entity when possible:
  * airline (e.g., Air France)
  * aircraft (e.g., Airbus A320)
  * manufacturer or airport if relevant

--------------------------------------------------

ANALYSIS INSTRUCTIONS:
Carefully analyze the full content of the article, including:
- main subject of the article
- aviation entities (airlines, aircraft, manufacturers, airports)
- key event or news angle

Ignore:
- email metadata
- signatures
- greetings
- irrelevant text or repeated headers

--------------------------------------------------

CLEANING RULES (CRITICAL):
Ensure output is safe for:
- JSON POST requests
- WordPress REST API

Therefore:
- Remove or replace invalid characters
- Remove control characters
- Fix or remove broken Unicode
- Remove unescaped quotes or malformed symbols
- Ensure no HTML is included

--------------------------------------------------

STRICT RULES:
- Do NOT use full sentences
- Do NOT include explanations
- Do NOT exceed 5 words
- Do NOT invent information not present in the source

--------------------------------------------------

OUTPUT RULE:
Return ONLY the final focus keyphrase as plain text.

CONTENT:
$contentFr";

        return $this->callOpenAI($prompt);
    }

    /**
     * Generate English focus keyphrase
     */
    public function generateEnglishKeyphrase(string $contentEn): ?string
    {
        $prompt = "Task: Generate a SEO Focus Keyphrase in ENGLISH based on the English content (ENGLISH news article content).

--------------------------------------------------

GOAL:
Extract or create a highly relevant focus keyphrase for SEO (WordPress / Yoast / RankMath).

--------------------------------------------------

REQUIREMENTS:
- Output language: ENGLISH
- Output type: plain text only (NO HTML, NO JSON)

--------------------------------------------------

KEYPHRASE RULES:
- Must represent the main topic of the aviation news article
- Must be SEO-relevant and searchable
- Length: 2 to 5 words (STRICT)
- Must include at least one key entity when possible:
  * airline (e.g., Air France)
  * aircraft (e.g., Airbus A320)
  * manufacturer or airport if relevant

--------------------------------------------------

ANALYSIS INSTRUCTIONS:
Carefully analyze the full content of the article, including:
- main subject of the article
- aviation entities (airlines, aircraft, manufacturers, airports)
- key event or news angle

Ignore:
- email metadata
- signatures
- greetings
- irrelevant text or repeated headers

--------------------------------------------------

CLEANING RULES (CRITICAL):
Ensure output is safe for:
- JSON POST requests
- WordPress REST API

Therefore:
- Remove or replace invalid characters
- Remove control characters
- Fix or remove broken Unicode
- Remove unescaped quotes or malformed symbols
- Ensure no HTML is included

--------------------------------------------------

STRICT RULES:
- Do NOT use full sentences
- Do NOT include explanations
- Do NOT exceed 5 words
- Do NOT invent information not present in the source

--------------------------------------------------

OUTPUT RULE:
Return ONLY the final focus keyphrase as plain text.

CONTENT:
$contentEn";

        return $this->callOpenAI($prompt);
    }

    /**
     * Classify news into categories
     */
    public function classifyCategories(string $newsContent, array $categories, string $lang = 'FR'): ?string
    {
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

        return $this->callOpenAI($prompt);
    }

    /**
     * Classify news into tags
     */
    public function classifyTags(string $newsContent, array $tags, string $lang = 'FR'): ?string
    {
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

        return $this->callOpenAI($prompt);
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
