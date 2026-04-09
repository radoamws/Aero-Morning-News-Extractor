<?php

namespace App\Console\Commands;

use App\Http\Controllers\NewsController;
use Illuminate\Console\Command;

class ProcessEmailsCommand extends Command
{
    protected $signature = 'emails:process';
    protected $description = 'Process unread emails and extract aviation news';

    public function handle(): int
    {
        // XAMPP/CLI can still enforce max_execution_time; this job may take longer.
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        $this->info('Starting email processing...');

        try {
            $controller = app(NewsController::class);
            $response = $controller->processEmails();
            $data = json_decode($response->getContent(), true);

            if ($data['success']) {
                $this->info("✓ Processed: {$data['processed']} emails");
                if ($data['failed'] > 0) {
                    $this->warn("✗ Failed: {$data['failed']} emails");
                }
                return Command::SUCCESS;
            } else {
                $this->error("Error: {$data['message']}");
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
