<?php
/**
 * WP-CLI eval-file: reindex Yoast SEO indexables for all published posts.
 *
 * Usage (on the WordPress server, in the WP root):
 *   wp eval-file /path/to/reindex-yoast-scores.php
 *
 * Optional: limit to specific post IDs
 *   wp eval-file /path/to/reindex-yoast-scores.php --post_type=post --batch=100
 *
 * Preferred built-in alternative (Yoast SEO ≥ 14):
 *   wp yoast index --reindex
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * What this script does:
 *   1. Fetches all published posts (IDs only, low memory)
 *   2. For each post, calls Yoast's Indexable_Builder to force a full rebuild
 *      of the indexable record in wp_yoast_indexable:
 *        - Rereads post meta (_yoast_wpseo_metadesc, _yoast_wpseo_focuskw …)
 *        - Recomputes primary_focus_keyword_score, readability_score
 *        - Updates structured data / schema
 *   3. Falls back to wp_update_post() (touches post_modified) if Yoast's
 *      builder is unavailable (Yoast < 14 or Free vs Premium mismatch).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */

if (! defined('ABSPATH')) {
    WP_CLI::error('This script must be run via wp eval-file.');
    exit(1);
}

// ── Configuration ────────────────────────────────────────────────────────────
$post_type   = WP_CLI\Utils\get_flag_value($assoc_args ?? [], 'post_type', 'post');
$batch_size  = (int) WP_CLI\Utils\get_flag_value($assoc_args ?? [], 'batch', 100);
$dry_run     = isset($assoc_args['dry-run']);
// ─────────────────────────────────────────────────────────────────────────────

// Detect Yoast indexable builder availability
$use_indexable_builder = function_exists('YoastSEO')
    && class_exists('\Yoast\WP\SEO\Builders\Indexable_Builder');

if ($use_indexable_builder) {
    WP_CLI::log("Yoast Indexable_Builder detected — using full indexable rebuild.");
} else {
    WP_CLI::warning("Yoast Indexable_Builder NOT available — falling back to wp_update_post().");
}

if ($dry_run) {
    WP_CLI::log("[DRY RUN] No changes will be made.");
}

// Fetch all published post IDs
$all_ids = get_posts([
    'post_type'      => $post_type,
    'post_status'    => 'publish',
    'numberposts'    => -1,
    'fields'         => 'ids',
    'no_found_rows'  => true,
    'update_post_meta_cache'  => false,
    'update_post_term_cache'  => false,
]);

$total  = count($all_ids);
$done   = 0;
$ok     = 0;
$failed = 0;

WP_CLI::log(sprintf("Found %d published %s(s) to reindex.", $total, $post_type));

if ($total === 0) {
    WP_CLI::success('Nothing to do.');
    exit(0);
}

// Build indexable builder & hierarchy builder once (expensive to instantiate)
$builder         = null;
$hierarchy       = null;
$indexable_repo  = null;

if ($use_indexable_builder && ! $dry_run) {
    try {
        $container       = YoastSEO()->classes;
        $builder         = $container->get(\Yoast\WP\SEO\Builders\Indexable_Builder::class);
        $hierarchy       = $container->get(\Yoast\WP\SEO\Builders\Indexable_Hierarchy_Builder::class);
        $indexable_repo  = $container->get(\Yoast\WP\SEO\Repositories\Indexable_Repository::class);
    } catch (\Throwable $e) {
        WP_CLI::warning("Could not init Yoast builder: " . $e->getMessage() . " — switching to fallback.");
        $builder = null;
    }
}

// Progress bar
$progress = WP_CLI\Utils\make_progress_bar("Reindexing Yoast scores", $total);

foreach ($all_ids as $post_id) {
    $post_id = (int) $post_id;

    if ($dry_run) {
        WP_CLI::log("[DRY RUN] Would reindex post ID {$post_id}");
        $ok++;
        $done++;
        $progress->tick();
        continue;
    }

    try {
        if ($builder !== null) {
            // ── Preferred: Yoast full indexable rebuild ───────────────────
            $indexable = $builder->build_for_id_and_type($post_id, 'post', true);
            if ($indexable && $indexable_repo && $hierarchy) {
                $hierarchy->build($indexable);
            }
        } else {
            // ── Fallback: touch post_modified to fire save_post hooks ─────
            // Avoids creating a new revision by suppressing that hook.
            remove_action('post_updated', 'wp_save_post_revision');
            wp_update_post(['ID' => $post_id]);
            add_action('post_updated', 'wp_save_post_revision');
        }

        $ok++;
    } catch (\Throwable $e) {
        WP_CLI::warning("FAIL post {$post_id}: " . $e->getMessage());
        $failed++;
    }

    $done++;
    $progress->tick();

    // Periodically clear the object cache to avoid memory bloat
    if ($done % $batch_size === 0) {
        wp_cache_flush();
    }
}

$progress->finish();

WP_CLI::success(sprintf(
    "Done. %d/%d posts reindexed successfully. %d failed.",
    $ok,
    $total,
    $failed
));
