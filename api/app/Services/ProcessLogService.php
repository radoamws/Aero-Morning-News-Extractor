<?php

namespace App\Services;

use App\Models\ProcessLog;
use Illuminate\Support\Facades\Log;

class ProcessLogService
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    public function startRun(string $processType, array $meta = []): ProcessLog
    {
        return ProcessLog::create([
            'process_type' => $processType,
            'status' => self::STATUS_RUNNING,
            'source' => (string) ($meta['source'] ?? null),
            'news_id' => $meta['news_id'] ?? null,
            'email_message_id' => (string) ($meta['email_message_id'] ?? null),
            'message' => (string) ($meta['message'] ?? null),
            'details' => $this->encodeDetails($meta['details'] ?? null),
            'started_at' => now(),
        ]);
    }

    public function finishRun(ProcessLog $log, string $status, array $details = [], ?string $message = null): void
    {
        try {
            $log->status = $status;
            $log->message = $message;
            $log->details = $this->encodeDetails($details);
            $log->finished_at = now();
            $log->save();
        } catch (\Throwable $e) {
            Log::warning('Unable to persist process log finishRun: ' . $e->getMessage());
        }
    }

    public function failRun(ProcessLog $log, \Throwable $e, array $details = []): void
    {
        $details['exception'] = [
            'message' => $e->getMessage(),
            'class' => get_class($e),
        ];

        $this->finishRun($log, self::STATUS_FAILED, $details, $e->getMessage());
    }

    private function encodeDetails(mixed $details): ?string
    {
        if ($details === null) {
            return null;
        }

        if (is_string($details)) {
            return $details;
        }

        $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return null;
        }

        // Avoid exploding DB size on long runs.
        $maxLen = 200000;
        if (strlen($json) > $maxLen) {
            return substr($json, 0, $maxLen) . '...<truncated>';
        }

        return $json;
    }
}
