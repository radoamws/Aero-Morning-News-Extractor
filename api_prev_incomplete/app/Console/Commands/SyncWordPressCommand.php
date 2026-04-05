<?php

namespace App\Console\Commands;

use App\Http\Controllers\NewsController;
use Illuminate\Console\Command;

class SyncWordPressCommand extends Command
{
    protected $signature = 'wordpress:sync';
    protected $description = 'Sync categories and tags from WordPress';

    public function handle(): int
    {
        $this->info('Starting WordPress synchronization...');

        try {
            $controller = app(NewsController::class);
            $response = $controller->syncWordPressData();
            $data = json_decode($response->getContent(), true);

            if ($data['success']) {
                $this->info('✓ WordPress data synced successfully');
                foreach ($data['data'] as $type => $status) {
                    $status = $status ? '✓' : '✗';
                    $this->line("  $status $type");
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
