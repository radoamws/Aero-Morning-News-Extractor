<?php

namespace App\Services;

use PhpImap\Mailbox;
use PhpImap\IncomingMail;
use Illuminate\Support\Facades\Log;

class EmailService
{
    private ?Mailbox $mailbox = null;

    public function __construct()
    {
        // Defer IMAP setup so unrelated API routes do not depend on mailbox availability.
    }

    /**
     * Initialize IMAP connection
     */
    private function initializeMailbox(): void
    {
        try {
            if (!extension_loaded('imap')) {
                throw new \RuntimeException('PHP IMAP extension is not enabled.');
            }

            if (!$this->hasValidConfig()) {
                throw new \RuntimeException('IMAP configuration is incomplete. Check IMAP_HOST, IMAP_USERNAME and IMAP_PASSWORD.');
            }

            $imapPath = $this->buildMailboxPath();

            $this->mailbox = new Mailbox(
                $imapPath,
                env('IMAP_USERNAME'),
                env('IMAP_PASSWORD'),
                storage_path('app/attachments'),
                'UTF-8'
            );
        } catch (\Throwable $e) {
            Log::error('IMAP connection error: ' . $e->getMessage());
            $this->mailbox = null;
        }
    }

    private function hasValidConfig(): bool
    {
        $host = (string) env('IMAP_HOST');
        $username = (string) env('IMAP_USERNAME');
        $password = (string) env('IMAP_PASSWORD');

        if ($host === '' || $username === '' || $password === '') {
            return false;
        }

        $placeholders = [
            'your-imap-host.com',
            'your-email@example.com',
            'your-imap-password',
        ];

        return !in_array($host, $placeholders, true)
            && !in_array($username, $placeholders, true)
            && !in_array($password, $placeholders, true);
    }

    private function buildMailboxPath(): string
    {
        $host = (string) env('IMAP_HOST');
        $port = (string) env('IMAP_PORT', '993');
        $encryption = strtolower((string) env('IMAP_ENCRYPTION', 'ssl'));

        $flags = ['/imap'];

        if ($encryption === 'ssl') {
            $flags[] = '/ssl';
        } elseif ($encryption === 'tls') {
            $flags[] = '/tls';
        } elseif ($encryption === 'notls' || $encryption === 'none') {
            $flags[] = '/notls';
        }

        return '{' . $host . ':' . $port . implode('', $flags) . '}INBOX';
    }

    /**
     * Get emails from mailbox based on configured criteria.
     */
    public function getUnreadEmails(): array
    {
        try {
            if (!extension_loaded('imap')) {
                Log::warning('Skipping email fetch: PHP IMAP extension is not enabled.');
                return [];
            }

            if (!$this->mailbox) {
                $this->initializeMailbox();
            }

            if (!$this->mailbox) {
                throw new \RuntimeException('IMAP mailbox is unavailable. Check IMAP configuration in .env.');
            }

            $criteria = strtoupper((string) env('IMAP_SEARCH_CRITERIA', 'UNSEEN'));
            $mailsIds = $this->mailbox->searchMailbox($criteria);

            if (!$mailsIds) {
                Log::info("No emails found for IMAP criteria: {$criteria}");
                return [];
            }

            $emails = [];
            foreach ($mailsIds as $mailId) {
                try {
                    $emails[] = $this->mailbox->getMail($mailId);
                } catch (\Exception $e) {
                    Log::warning("Error fetching email ID $mailId: " . $e->getMessage());
                    continue;
                }
            }

            return $emails;
        } catch (\Throwable $e) {
            Log::error('Error fetching unread emails: ' . $e->getMessage());
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Extract email content
     */
    public function extractEmailContent(IncomingMail $mail): array
    {
        $content = [
            'subject' => $mail->subject ?? '',
            'html_body' => $mail->textHtml ?? '',
            'text_body' => $mail->textPlain ?? '',
            'attachments' => [],
            'message_id' => $mail->messageId ?? '',
            'from' => $mail->fromAddress ?? ''
        ];

        // Extract attachments
        if (!empty($mail->getAttachments())) {
            foreach ($mail->getAttachments() as $attachment) {
                $filename = $attachment->name ?? '';
                if ($filename === '' && !empty($attachment->filePath)) {
                    $filename = basename((string) $attachment->filePath);
                }

                $content['attachments'][] = [
                    'filename' => $filename,
                    'mime' => $attachment->mimeType ?? ($attachment->mime ?? ''),
                    'path' => $attachment->filePath
                ];
            }
        }

        return $content;
    }

    /**
     * Check if email has attachments
     */
    public function hasAttachments(IncomingMail $mail): bool
    {
        return !empty($mail->getAttachments());
    }

    /**
     * Mark email as read
     */
    public function markAsRead(int $mailId): bool
    {
        try {
            if (!$this->mailbox) {
                $this->initializeMailbox();
            }

            if (!$this->mailbox) {
                throw new \RuntimeException('IMAP mailbox is unavailable.');
            }

            $this->mailbox->setFlag([$mailId], "\\Seen");
            return true;
        } catch (\Throwable $e) {
            Log::error("Error marking email $mailId as read: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Extract image URL from HTML content
     */
    public function extractImageUrlFromHtml(string $html): ?string
    {
        // Look for img tags
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            return $matches[1];
        }

        // Look for image links
        if (preg_match('/<a[^>]+href=["\']([^"\']+\.(?:jpg|jpeg|png|gif))["\'][^>]*>/i', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
