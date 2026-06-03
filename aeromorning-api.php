<?php
// mu-plugin: aeromorning-api.php
add_action('rest_api_init', function () {
    register_rest_route('aeromorning/v1', '/yoast/(?P<id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'aeromorning_update_yoast_meta',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'args' => [
            'id' => [
                'validate_callback' => fn($v) => is_numeric($v),
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
});

/**
 * Return true if every word of $phrase appears somewhere in $text.
 *
 * Mirrors Yoast's keyphrase-in-text check: it does NOT require the words to
 * be contiguous — it only requires each word to be present at least once.
 * Both arguments must already be lower-cased UTF-8 strings.
 */
function aeromorning_phrase_words_in_text(string $text, string $phrase): bool {
    $words = array_filter(preg_split('/[\s\-]+/u', $phrase) ?: []);
    if (empty($words)) {
        return false;
    }
    foreach ($words as $word) {
        if ($word !== '' && mb_strpos($text, $word, 0, 'UTF-8') === false) {
            return false;
        }
    }
    return true;
}

/**
 * Count words in a UTF-8 string (handles French accented characters).
 * PHP's str_word_count() misses accented letters outside the current locale.
 */
function aeromorning_word_count(string $text): int {
    return (int) preg_match_all('/\p{L}+/u', $text);
}

/**
 * Count non-overlapping occurrences of each word of $phrase in $text, return
 * the minimum (i.e. how many times the full keyphrase could have appeared).
 */
function aeromorning_phrase_occurrences(string $text, string $phrase): int {
    $words = array_filter(preg_split('/[\s\-]+/u', $phrase) ?: []);
    if (empty($words)) {
        return 0;
    }
    $min = PHP_INT_MAX;
    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }
        $count = mb_substr_count($text, $word);
        $min   = min($min, $count);
    }
    return $min === PHP_INT_MAX ? 0 : $min;
}

/**
 * Compute a Yoast-compatible SEO score (1–100) using word-based matching.
 *
 * Yoast does NOT require the keyphrase words to be contiguous — it checks
 * whether each word of the keyphrase appears in the target field.  Using
 * exact-string (mb_strpos) matching would give falsely low scores for titles
 * such as "Airbus lance officiellement le programme A321XLR" with keyphrase
 * "Airbus A321XLR".
 *
 * Weighted checks (mirrors Yoast's scoring rubric):
 *   - Keyphrase words in title         : 9 pts
 *   - Keyphrase words in introduction  : 9 pts
 *   - Keyphrase words in meta desc     : 9 pts
 *   - Meta description length 50–156   : 9 pts  (3 pts if present but off)
 *   - Keyphrase word(s) in slug        : 6 pts
 *   - Text length ≥ 300 words          : 9 pts  (5 pts for 150–299)
 *   - Keyphrase density 0.5%–3%        : 9 pts  (3 pts if present but off)
 *
 * Returns 0 when no focus keyword is provided.
 * Always returns ≥ 1 when a focus keyword is set (prevents "Non disponible").
 * The real Yoast JS score will overwrite this value the next time an editor
 * opens and saves the post.
 */
function aeromorning_compute_seo_score(int $post_id, string $focuskw, string $metadesc): int {
    $focuskw = trim($focuskw);
    if ($focuskw === '') {
        return 0;
    }

    $post = get_post($post_id);
    if (! $post) {
        return 0;
    }

    $kw      = mb_strtolower($focuskw, 'UTF-8');
    $title   = mb_strtolower(strip_tags((string) $post->post_title), 'UTF-8');
    $content = mb_strtolower(wp_strip_all_tags((string) $post->post_content), 'UTF-8');
    $desc    = mb_strtolower(trim($metadesc), 'UTF-8');
    $slug    = mb_strtolower((string) $post->post_name, 'UTF-8');

    $score = 0;
    $max   = 60; // sum of all achievable points

    // Keyphrase words in title (9 pts)
    if (aeromorning_phrase_words_in_text($title, $kw)) {
        $score += 9;
    }

    // Keyphrase words in introduction — first ~300 chars (9 pts)
    $intro = mb_substr($content, 0, 300, 'UTF-8');
    if ($intro !== '' && aeromorning_phrase_words_in_text($intro, $kw)) {
        $score += 9;
    }

    // Keyphrase words in meta description (9 pts)
    if ($desc !== '' && aeromorning_phrase_words_in_text($desc, $kw)) {
        $score += 9;
    }

    // Meta description length 50–156 chars (9 pts; partial 3 pts if present but off)
    $desc_len = mb_strlen($desc, 'UTF-8');
    if ($desc_len >= 50 && $desc_len <= 156) {
        $score += 9;
    } elseif ($desc_len > 0) {
        $score += 3;
    }

    // Keyphrase first word in slug (6 pts) — slug is the post URL segment
    $kw_words = array_values(array_filter(preg_split('/[\s\-]+/u', $kw) ?: []));
    if (! empty($kw_words) && $slug !== '') {
        $slug_hits = 0;
        foreach ($kw_words as $w) {
            if ($w !== '' && str_contains($slug, sanitize_title($w))) {
                $slug_hits++;
            }
        }
        if ($slug_hits >= count($kw_words)) {
            $score += 6;
        } elseif ($slug_hits > 0) {
            $score += 3;
        }
    }

    // Text length (9 pts ≥ 300 words; 5 pts for 150–299)
    $word_count = aeromorning_word_count($content);
    if ($word_count >= 300) {
        $score += 9;
    } elseif ($word_count >= 150) {
        $score += 5;
    }

    // Keyphrase density (9 pts for 0.5%–3%; 3 pts if present but outside range)
    if ($word_count > 0) {
        $kw_occurrences = aeromorning_phrase_occurrences($content, $kw);
        $kw_word_count  = count($kw_words);
        // Yoast density = (occurrences × keyphrase_word_count) / total_words × 100
        $density = $kw_word_count > 0
            ? ($kw_occurrences * $kw_word_count) / $word_count * 100
            : 0.0;
        if ($density >= 0.5 && $density <= 3.0) {
            $score += 9;
        } elseif ($kw_occurrences > 0) {
            $score += 3;
        }
    }

    // Normalise to 1–100 (always ≥ 1 when focus keyword is provided)
    $normalized = (int) round(($score / $max) * 100);
    return max(1, min(100, $normalized));
}

function aeromorning_update_yoast_meta(WP_REST_Request $request): WP_REST_Response {
    $post_id  = (int) $request->get_param('id');
    $body     = $request->get_json_params();
    $metadesc = isset($body['metadesc']) ? sanitize_text_field($body['metadesc']) : null;
    $focuskw  = isset($body['focuskw'])  ? sanitize_text_field($body['focuskw'])  : null;
    $reindex  = !empty($body['reindex']);
    $touch_post = !empty($body['touch_post']);

    $post_title = array_key_exists('post_title', $body)
        ? sanitize_text_field((string) $body['post_title'])
        : null;
    $post_excerpt = array_key_exists('post_excerpt', $body)
        ? sanitize_text_field((string) $body['post_excerpt'])
        : null;
    $post_content = array_key_exists('post_content', $body)
        ? wp_kses_post((string) $body['post_content'])
        : null;

    $seo_score = array_key_exists('seo_score', $body) ? (int) $body['seo_score'] : null;
    $readability_score = array_key_exists('readability_score', $body) ? (int) $body['readability_score'] : null;

    if (! get_post($post_id)) {
        return new WP_REST_Response(['error' => 'post_not_found'], 404);
    }

    $updated = [];

    if ($metadesc !== null) {
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $metadesc);
        $updated['metadesc'] = $metadesc;
    }
    if ($focuskw !== null) {
        update_post_meta($post_id, '_yoast_wpseo_focuskw', $focuskw);
        $updated['focuskw'] = $focuskw;
    }

    // ─── SEO score (linkdex) ────────────────────────────────────────────────
    // _yoast_wpseo_linkdex drives primary_focus_keyword_score in yoast_indexable.
    // Without a non-zero value, Yoast displays "Non disponible" in the admin column.
    //
    // Priority:
    //   1. Explicit seo_score from caller  → use as-is
    //   2. No score provided + focus keyword set + current stored score is 0
    //      → compute a PHP-based approximation so the column shows a colored dot
    //      instead of "Non disponible". The real score will be overwritten the
    //      first time an editor opens and saves the post (Yoast JS analysis).
    if ($seo_score !== null) {
        update_post_meta($post_id, '_yoast_wpseo_linkdex', $seo_score);
        $updated['seo_score'] = $seo_score;
        $updated['seo_score_source'] = 'explicit';
    } elseif ($focuskw !== null) {
        $existing_score = (int) get_post_meta($post_id, '_yoast_wpseo_linkdex', true);
        // Compute when: score is missing (0) OR an explicit reindex was requested.
        // Do NOT override when the score was set by Yoast JS (real score, existing_score > 0
        // and reindex=false) so that a manual editor save is never downgraded.
        $should_compute = ($existing_score === 0) || $reindex;
        if ($should_compute) {
            $computed_score = aeromorning_compute_seo_score(
                $post_id,
                $focuskw,
                (string) ($metadesc ?? get_post_meta($post_id, '_yoast_wpseo_metadesc', true))
            );
            if ($computed_score > 0) {
                update_post_meta($post_id, '_yoast_wpseo_linkdex', $computed_score);
                $updated['seo_score'] = $computed_score;
                $updated['seo_score_source'] = 'computed';
            }
        }
    }

    if ($readability_score !== null) {
        update_post_meta($post_id, '_yoast_wpseo_content_score', $readability_score);
        $updated['readability_score'] = $readability_score;
    }

    $should_touch_post = $touch_post || $post_title !== null || $post_excerpt !== null || $post_content !== null;
    if ($should_touch_post) {
        $post_update = [
            'ID' => $post_id,
        ];

        if ($post_title !== null) {
            $post_update['post_title'] = $post_title;
        }
        if ($post_excerpt !== null) {
            $post_update['post_excerpt'] = $post_excerpt;
        }
        if ($post_content !== null) {
            $post_update['post_content'] = $post_content;
        }

        $touch_result = wp_update_post($post_update, true, true);
        if (is_wp_error($touch_result)) {
            return new WP_REST_Response([
                'success' => false,
                'post_id' => $post_id,
                'error' => 'post_touch_failed',
                'message' => $touch_result->get_error_message(),
                'updated' => $updated,
            ], 500);
        }

        $updated['post_touched'] = true;
    }

    // ─── Rebuild Yoast indexable (scores + structured data) ──────────────────
    // wp_update_post() fires save_post which triggers Yoast's indexable builder.
    // When reindex=true we additionally call build_for_id_and_type() explicitly
    // to guarantee the indexable is rebuilt even if the save_post hook is skipped.
    $reindex_result = 'skipped';

    if ($reindex && function_exists('YoastSEO')) {
        try {
            $container = YoastSEO()->classes;

            $builder = $container->get(\Yoast\WP\SEO\Builders\Indexable_Builder::class);
            $builder->build_for_id_and_type($post_id, 'post', true);

            $hierarchy = $container->get(\Yoast\WP\SEO\Builders\Indexable_Hierarchy_Builder::class);
            $indexable_repository = $container->get(\Yoast\WP\SEO\Repositories\Indexable_Repository::class);
            $indexable = $indexable_repository->find_by_id_and_type($post_id, 'post');
            if ($indexable) {
                $hierarchy->build($indexable);
            }

            $reindex_result = 'ok';
        } catch (\Throwable $e) {
            // Fallback: fire save_post hooks manually (works on Yoast < 14)
            do_action('save_post', $post_id, get_post($post_id), true);
            $reindex_result = 'fallback_save_post: ' . $e->getMessage();
        }
    }

    return new WP_REST_Response([
        'success'        => true,
        'post_id'        => $post_id,
        'updated'        => $updated,
        'reindex_result' => $reindex_result,
    ], 200);
}
