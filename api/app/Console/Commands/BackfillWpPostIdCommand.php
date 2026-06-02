<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Services\WordPressPostingService;
use Illuminate\Console\Command;

class BackfillWpPostIdCommand extends Command
{
    protected $signature = 'news:backfill-wp-post-id
        {--limit=1000 : Max number of synced items to process}
        {--resume-after-news-id= : Continue with news IDs lower than this one}
        {--lang= : Restrict to FR or EN}
        {--dry-run : Print candidates without persisting mapping}';

    protected $description = 'Backfill local t_news.wp_post_id for already published/synced items.';

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
            ->where(function ($q) {
                $q->whereNull('wp_post_id')->orWhere('wp_post_id', '<=', 0);
            })
            ->orderByDesc('id');

        if ($lang !== '') {
            $query->where('lang', $lang);
        }

        if (is_int($resumeAfterNewsId) && $resumeAfterNewsId > 0) {
            $query->where('id', '<', $resumeAfterNewsId);
        }

        $targets = $query->limit($limit)->get();

        if ($targets->isEmpty()) {
            $this->info('No synced news without wp_post_id mapping found.');
            return Command::SUCCESS;
        }

        $processed = 0;
        $mapped = 0;
        $notFound = 0;
        $failed = 0;

        foreach ($targets as $news) {
            $processed++;

            if ($dryRun) {
                $this->line(sprintf('[DRY] #%d [%s] %s', $news->id, $news->lang, $news->title));
                continue;
            }

            $service = new WordPressPostingService($news->lang);
            $result = $service->backfillWordPressPostIdForNews($news);

            if (($result['success'] ?? false) === true) {
                $mapped++;
                $this->info(sprintf('#%d mapped to WP post %d', $news->id, (int) ($result['wp_post_id'] ?? 0)));
                continue;
            }

            if (($result['error'] ?? null) === 'wordpress_post_not_found') {
                $notFound++;
                $this->warn(sprintf('#%d not found on WP (%s)', $news->id, $news->title));
                continue;
            }

            $failed++;
            $this->error(sprintf('#%d failed: %s', $news->id, (string) ($result['error'] ?? 'unknown_error')));
        }

        $this->newLine();
        $this->line('Backfill summary');
        $this->line('Processed: ' . $processed);
        $this->line('Mapped:    ' . $mapped);
        $this->line('Not found: ' . $notFound);
        $this->line('Failed:    ' . $failed);

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
