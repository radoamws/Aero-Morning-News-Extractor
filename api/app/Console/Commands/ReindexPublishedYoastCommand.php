<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Services\WordPressPostingService;
use Illuminate\Console\Command;

class ReindexPublishedYoastCommand extends Command
{
    protected $signature = 'news:reindex-published-yoast
        {--limit=500 : Max number of mapped synced items to process}
        {--resume-after-news-id= : Continue with news IDs lower than this one}
        {--lang= : Restrict to FR or EN}
        {--dry-run : Print candidates without doing WP calls}';

    protected $description = 'Reindex Yoast for already published news using local wp_post_id mapping.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $resumeAfterNewsId = $this->option('resume-after-news-id');
        $resumeAfterNewsId = is_numeric($resumeAfterNewsId) ? (int) $resumeAfterNewsId : null;
        $lang = strtoupper((string) $this->option('lang'));
        $dryRun = (bool) $this->option('dry-run');

        if ($lang !== '' && !in_array($lang, ['FR', 'EN'], true)) {
            $this->error('Option --lang must be FR or EN.');
            return Command::FAILURE;
        }

        $query = News::query()
            ->where('status', News::STATUS_SYNCED)
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->orderByDesc('id');

        if ($lang !== '') {
            $query->where('lang', $lang);
        }

        if (is_int($resumeAfterNewsId) && $resumeAfterNewsId > 0) {
            $query->where('id', '<', $resumeAfterNewsId);
        }

        $targets = $query->limit($limit)->get();

        if ($targets->isEmpty()) {
            $this->info('No mapped synced news found for Yoast reindex.');
            return Command::SUCCESS;
        }

        $processed = 0;
        $updated = 0;
        $notFound = 0;
        $failed = 0;

        foreach ($targets as $news) {
            $processed++;

            if ($dryRun) {
                $this->line(sprintf('[DRY] #%d [%s] wp_post_id=%d %s', $news->id, $news->lang, (int) $news->wp_post_id, $news->title));
                continue;
            }

            $service = new WordPressPostingService($news->lang);
            $result = $service->reindexYoastForNews($news);

            if (($result['success'] ?? false) === true) {
                $updated++;
                $this->info(sprintf('#%d reindexed (WP %d)', $news->id, (int) ($result['wp_post_id'] ?? 0)));
                continue;
            }

            if (($result['error'] ?? null) === 'wordpress_post_not_found') {
                $notFound++;
                $this->warn(sprintf('#%d mapped post not found anymore (WP %d)', $news->id, (int) ($news->wp_post_id ?? 0)));
                continue;
            }

            $failed++;
            $this->error(sprintf('#%d failed: %s', $news->id, (string) ($result['error'] ?? 'unknown_error')));
        }

        $this->newLine();
        $this->line('Reindex summary');
        $this->line('Processed: ' . $processed);
        $this->line('Updated:   ' . $updated);
        $this->line('Not found: ' . $notFound);
        $this->line('Failed:    ' . $failed);

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
