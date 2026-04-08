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
        $candidates = $this->extractImageCandidatesFromHtml($html);

        return $candidates[0] ?? null;
    }

    public function extractImageCandidatesFromHtml(string $html): array
    {
        $validCandidates = [];

        $extractIntAttr = static function (string $tag, string $attr): int {
            if (preg_match('/\b' . preg_quote($attr, '/') . '\s*=\s*["\']?(\d{1,5})/i', $tag, $m) === 1) {
                return (int) $m[1];
            }
            return 0;
        };

        $extractPxFromStyle = static function (string $tag, string $prop): int {
            if (preg_match('/\bstyle\s*=\s*["\'][^"\']*\b' . preg_quote($prop, '/') . '\s*:\s*(\d{1,5})\s*px/i', $tag, $m) === 1) {
                return (int) $m[1];
            }
            return 0;
        };

        $isSmallIcon = static function (string $imgTag) use ($extractIntAttr, $extractPxFromStyle): bool {
            $w = $extractIntAttr($imgTag, 'width');
            $h = $extractIntAttr($imgTag, 'height');

            if ($w === 0) {
                $w = $extractPxFromStyle($imgTag, 'width');
            }
            if ($h === 0) {
                $h = $extractPxFromStyle($imgTag, 'height');
            }

            if ($w > 0 && $h > 0) {
                return $w <= 160 && $h <= 160;
            }

            if ($w > 0) {
                return $w <= 80;
            }
            if ($h > 0) {
                return $h <= 80;
            }

            return false;
        };

        // Look for img tags and skip icons/signatures/header/footer assets.
        if (preg_match_all('/<img\b[^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $imgTag) {
                if (!preg_match('/\bsrc=["\']([^"\']+)["\']/i', $imgTag, $srcMatch)) {
                    continue;
                }

                if ($isSmallIcon($imgTag)) {
                    continue;
                }

                $url = (string) $srcMatch[1];

                $candidateUrls = [$url];
                if (preg_match('/\bsrcset=["\']([^"\']+)["\']/i', $imgTag, $srcsetMatch) === 1) {
                    $srcset = trim((string) $srcsetMatch[1]);
                    if ($srcset !== '') {
                        $parts = array_map('trim', explode(',', $srcset));
                        foreach ($parts as $part) {
                            if ($part === '') {
                                continue;
                            }
                            $urlPart = trim((string) preg_split('/\s+/', $part)[0]);
                            if ($urlPart !== '') {
                                $candidateUrls[] = $urlPart;
                            }
                        }
                    }
                }

                foreach ($candidateUrls as $candidateUrl) {
                    if ($this->isForbiddenInlineImageUrl($candidateUrl, $imgTag)) {
                        continue;
                    }

                    $validCandidates[] = $candidateUrl;
                }
            }
        }

        // Look for image links and skip internal AMWS assets.
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+\.(?:jpg|jpeg|png|gif|webp))(?:\?[^"\']*)?["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if (!$this->isForbiddenInlineImageUrl((string) $url)) {
                    $validCandidates[] = $url;
                }
            }
        }

        return array_values(array_unique($validCandidates));
    }

    private function isForbiddenInlineImageUrl(string $url, string $imgTag = ''): bool
    {
        $normalizedUrl = strtolower(trim($url));
        $normalizedTag = strtolower(trim($imgTag));

        if ($normalizedUrl === '') {
            return true;
        }

        if (
            str_contains($normalizedUrl, 'amws.space')
            || str_contains($normalizedUrl, 'amws')
            || str_contains($normalizedUrl, 'hc-undulydeepcolt-eu.n0c.com')
        ) {
            return true;
        }

        // In forwarded emails, relative image URLs (starting with / or without scheme) are unreliable and
        // frequently correspond to webmail/header/footer assets. We block them entirely.
        if ($this->isRelativeImageUrl($normalizedUrl)) {
            return true;
        }

        // Broad blocklist for signatures/social/share/decoration, regardless of absolute/relative URLs.
        if (preg_match('/\b(logo|favicon|sprite|icon|icons|social|share|addtoany|addthis|facebook|twitter|x\.com|linkedin|pinterest|instagram|youtube|whatsapp|telegram|reddit|button|badge|avatar|gravatar|signature|tracking|pixel|spacer|1x1|doubleclick|mailchimp|roundcube|webmail|banner|banniere|ads|advert|promo)\b/i', $normalizedUrl) === 1) {
            return true;
        }

        if (preg_match('/\bwp-content\/plugins\b|\bwp-includes\/(images|css)\b/i', $normalizedUrl) === 1) {
            return true;
        }

        if (preg_match('/\.(svg)(\?|#|$)/i', $normalizedUrl) === 1) {
            return true;
        }

        return false;
    }

    private function isRelativeImageUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        return !preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url)
            && !str_starts_with($url, 'cid:')
            && !str_starts_with($url, 'data:');
    }
}
