<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Services\ProcessLogService;
use App\Services\WordPressPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PublishNewsCommand extends Command
{
    protected $signature   = 'news:publish';
    protected $description = 'Publish all pending (status=0) news to WordPress and send an email summary';

    public function handle(): int
    {
        $this->info('Starting WordPress publishing...');

        $processLogService = app(ProcessLogService::class);
        $processLog = $processLogService->startRun('publish_pending', [
            'source' => 'cli',
        ]);

        try {
            $pendingNews = News::where('status', News::STATUS_PENDING)->get();

            if ($pendingNews->isEmpty()) {
                $this->info('No pending news to publish.');
                $this->sendSummaryEmail(['success' => [], 'failed' => []]);
                $processLogService->finishRun($processLog, ProcessLogService::STATUS_SUCCESS, [
                    'published' => 0,
                    'failed' => 0,
                    'note' => 'No pending news to publish',
                ], 'No pending news to publish.');
                return Command::SUCCESS;
            }

        $this->info("Found {$pendingNews->count()} pending news items.");

        $results = [
            'success' => [],   // published (status 2)
            'failed'  => [],   // failed    (status 1)
        ];

            foreach ($pendingNews as $news) {
                $this->line("Processing news #{$news->id} [{$news->lang}]: {$news->title}");

            // Mark as syncing immediately so interrupted runs are visible
            $news->status = News::STATUS_SYNCING;
            $news->save();

            try {
                $service = new WordPressPostingService($news->lang);
                $result  = $service->publishToWordPress($news);

                if ($result['success']) {
                    $news->status = News::STATUS_SYNCED;
                    $news->save();

                    $results['success'][] = [
                        'id'         => $news->id,
                        'lang'       => $news->lang,
                        'title'      => $news->title,
                        'wp_post_id' => $result['wp_post_id'],
                    ];

                    $this->info("  ✓ Publié — WP post ID: {$result['wp_post_id']}");
                } else {
                    // Leave at status 1 so it is visible as "stuck"
                    $results['failed'][] = [
                        'id'    => $news->id,
                        'lang'  => $news->lang,
                        'title' => $news->title,
                        'error' => $result['error'] ?? 'Unknown error',
                    ];

                    $this->warn("  ✗ Échec: " . ($result['error'] ?? 'Unknown error'));
                }
            } catch (\Throwable $e) {
                Log::error("Unexpected error publishing news #{$news->id}: " . $e->getMessage());

                $results['failed'][] = [
                    'id'    => $news->id,
                    'lang'  => $news->lang,
                    'title' => $news->title,
                    'error' => $e->getMessage(),
                ];

                $this->error("  ✗ Exception: " . $e->getMessage());
            }
        }

            $this->sendSummaryEmail($results);

            $successCount = count($results['success']);
            $failedCount  = count($results['failed']);
            $this->info("Publishing terminé — Succès: {$successCount} | Échec: {$failedCount}");

            $status = $failedCount > 0
                ? ProcessLogService::STATUS_PARTIAL
                : ProcessLogService::STATUS_SUCCESS;

            $processLogService->finishRun($processLog, $status, [
                'published' => $successCount,
                'failed' => $failedCount,
                'failed_items' => $results['failed'],
            ], 'Publishing completed.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Fatal error: ' . $e->getMessage());
            $processLogService->failRun($processLog, $e, [
                'note' => 'Fatal error in news:publish',
            ]);
            return Command::FAILURE;
        }
    }

    // -------------------------------------------------------------------------

    private function sendSummaryEmail(array $results): void
    {
        $to           = config('services.notify.email', env('NOTIFY_EMAIL', 'rado.rakotoarivelo@amws.space'));
        $successCount = count($results['success']);
        $failedCount  = count($results['failed']);
        $total        = $successCount + $failedCount;

        // Count any remaining status=0 (not processed — process was interrupted)
        $remainingPending = News::where('status', News::STATUS_PENDING)->count();

        $body  = "Résumé de publication WordPress — " . now()->format('d/m/Y H:i') . "\n";
        $body .= str_repeat('=', 60) . "\n\n";
        $body .= "Total traité dans ce batch : {$total}\n";
        $body .= "  ✓ Publiées avec succès (status 2) : {$successCount}\n";
        $body .= "  ✗ En échec            (status 1) : {$failedCount}\n";

        if ($remainingPending > 0) {
            $body .= "  ⚠ Non traitées         (status 0) : {$remainingPending}  ← traitement possiblement interrompu\n";
        }

        $body .= "\n";

        if (!empty($results['success'])) {
            $body .= str_repeat('-', 60) . "\n";
            $body .= "NEWS PUBLIÉES (status 2)\n";
            $body .= str_repeat('-', 60) . "\n";
            foreach ($results['success'] as $item) {
                $body .= "  [#{$item['id']}] [{$item['lang']}] {$item['title']}\n";
                $body .= "        → WP post ID : {$item['wp_post_id']}\n";
            }
            $body .= "\n";
        }

        if (!empty($results['failed'])) {
            $body .= str_repeat('-', 60) . "\n";
            $body .= "NEWS EN ÉCHEC (status 1)\n";
            $body .= str_repeat('-', 60) . "\n";
            foreach ($results['failed'] as $item) {
                $body .= "  [#{$item['id']}] [{$item['lang']}] {$item['title']}\n";
                $body .= "        → Erreur : {$item['error']}\n";
            }
            $body .= "\n";
        }

        if ($remainingPending > 0) {
            $body .= str_repeat('-', 60) . "\n";
            $body .= "NEWS NON TRAITÉES (status 0) — {$remainingPending} restante(s)\n";
            $body .= str_repeat('-', 60) . "\n";
            $notProcessed = News::where('status', News::STATUS_PENDING)->select('id', 'lang', 'title')->get();
            foreach ($notProcessed as $item) {
                $body .= "  [#{$item->id}] [{$item->lang}] {$item->title}\n";
            }
            $body .= "\n";
        }

        try {
            Mail::raw($body, function ($message) use ($to) {
                $message->to($to)
                        ->subject('Résumé publication WordPress — ' . now()->format('d/m/Y H:i'));
            });
            Log::info("Summary email sent to {$to}");
        } catch (\Throwable $e) {
            Log::error("Failed to send summary email: " . $e->getMessage());
            $this->warn("Email summary could not be sent: " . $e->getMessage());
        }
    }
}
