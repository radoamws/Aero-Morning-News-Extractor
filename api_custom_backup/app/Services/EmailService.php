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
        $this->initializeMailbox();
    }

    /**
     * Initialize IMAP connection
     */
    private function initializeMailbox(): void
    {
        try {
            $imapPath = "{" . env('IMAP_HOST') . ":" . env('IMAP_PORT') . "/" . env('IMAP_ENCRYPTION', 'ssl') . "}INBOX";
            
            $this->mailbox = new Mailbox(
                $imapPath,
                env('IMAP_USERNAME'),
                env('IMAP_PASSWORD'),
                storage_path('app/attachments'),
                'UTF-8'
            );
        } catch (\Exception $e) {
            Log::error('IMAP connection error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all unread emails
     */
    public function getUnreadEmails(): array
    {
        try {
            if (!$this->mailbox) {
                $this->initializeMailbox();
            }

            $mailsIds = $this->mailbox->searchMailbox('UNSEEN');
            
            if (!$mailsIds) {
                Log::info('No unread emails found');
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
        } catch (\Exception $e) {
            Log::error('Error fetching unread emails: ' . $e->getMessage());
            return [];
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
                $content['attachments'][] = [
                    'filename' => $attachment->filename,
                    'mime' => $attachment->mimeType,
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
            $this->mailbox->setFlag($mailId, "\\Seen");
            return true;
        } catch (\Exception $e) {
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
