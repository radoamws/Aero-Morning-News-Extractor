<?php

namespace App\Services;

use PhpImap\Mailbox;
use PhpImap\IncomingMail;
use PhpImap\Exceptions\ConnectionException;
use Illuminate\Support\Facades\Log;

class EmailService
{
    private ?Mailbox $mailbox = null;
    private ?string $lastMailboxInitError = null;

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
            $this->lastMailboxInitError = null;

            if (!extension_loaded('imap')) {
                throw new \RuntimeException('PHP IMAP extension is not enabled.');
            }

            if (!$this->hasValidConfig()) {
                throw new \RuntimeException('IMAP configuration is incomplete. Check IMAP_HOST, IMAP_USERNAME and IMAP_PASSWORD.');
            }

            $username = (string) env('IMAP_USERNAME');
            $password = (string) env('IMAP_PASSWORD');
            $attachmentsDir = storage_path('app/attachments');

            $pathsToTry = $this->buildMailboxPathsToTry();
            $connectionErrors = [];

            foreach ($pathsToTry as $imapPath) {
                try {
                    $mailbox = new Mailbox(
                        $imapPath,
                        $username,
                        $password,
                        $attachmentsDir,
                        'UTF-8'
                    );

                    // Prevent long hangs during TLS negotiation / broken servers.
                    $timeoutSec = (int) env('IMAP_TIMEOUT_SEC', 15);
                    if ($timeoutSec > 0) {
                        $mailbox->setTimeouts($timeoutSec);
                    }

                    // Keep retries small; we already have fallback paths.
                    $mailbox->setConnectionRetry((int) env('IMAP_CONNECTION_RETRY', 1));
                    $mailbox->setConnectionRetryDelay((int) env('IMAP_CONNECTION_RETRY_DELAY_MS', 0));

                    // Proactively open a stream so TLS/SSL failures are caught here.
                    $mailbox->getImapStream(true);

                    $this->mailbox = $mailbox;
                    Log::info('IMAP connection established.', [
                        'imap_path' => $this->redactMailboxPath($imapPath),
                    ]);
                    return;
                } catch (\Throwable $e) {
                    $msg = $this->normalizeImapExceptionMessage($e);
                    $connectionErrors[] = $msg;
                    Log::warning('IMAP connection attempt failed.', [
                        'imap_path' => $this->redactMailboxPath($imapPath),
                        'error' => $msg,
                    ]);
                }
            }

            $summary = $connectionErrors ? implode(' | ', array_unique($connectionErrors)) : 'Unknown IMAP error';
            throw new \RuntimeException('IMAP connection failed. ' . $summary);
        } catch (\Throwable $e) {
            $msg = $this->normalizeImapExceptionMessage($e);
            $this->lastMailboxInitError = $msg;
            Log::error('IMAP connection error: ' . $msg);
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

        $validateCert = $this->envBool('IMAP_VALIDATE_CERT', true);

        return $this->buildMailboxPathFor($host, $port, $encryption, $validateCert, (string) env('IMAP_FOLDER', 'INBOX'));
    }

    private function buildMailboxPathsToTry(): array
    {
        $host = (string) env('IMAP_HOST');
        $port = (string) env('IMAP_PORT', '993');
        $encryption = strtolower((string) env('IMAP_ENCRYPTION', 'ssl'));
        $folder = (string) env('IMAP_FOLDER', 'INBOX');

        $validateCert = $this->envBool('IMAP_VALIDATE_CERT', true);

        $paths = [];
        $paths[] = $this->buildMailboxPathFor($host, $port, $encryption, $validateCert, $folder);

        // Common fix on hosts with bad/self-signed certs.
        if ($validateCert) {
            $paths[] = $this->buildMailboxPathFor($host, $port, $encryption, false, $folder);
        }

        // Common mismatch: server expects STARTTLS on 143 instead of implicit SSL on 993.
        if ($encryption === 'ssl' && (string) $port === '993') {
            $paths[] = $this->buildMailboxPathFor($host, '143', 'tls', $validateCert, $folder);
            if ($validateCert) {
                $paths[] = $this->buildMailboxPathFor($host, '143', 'tls', false, $folder);
            }
        }

        // Opposite mismatch: configured TLS on 143 but server is implicit SSL on 993.
        if ($encryption === 'tls' && (string) $port === '143') {
            $paths[] = $this->buildMailboxPathFor($host, '993', 'ssl', $validateCert, $folder);
            if ($validateCert) {
                $paths[] = $this->buildMailboxPathFor($host, '993', 'ssl', false, $folder);
            }
        }

        // Last resort (only if explicitly allowed): try plaintext.
        if ($this->envBool('IMAP_ALLOW_NOTLS_FALLBACK', false)) {
            $paths[] = $this->buildMailboxPathFor($host, $port, 'notls', $validateCert, $folder);
            $paths[] = $this->buildMailboxPathFor($host, '143', 'notls', $validateCert, $folder);
        }

        // Deduplicate while keeping order.
        $unique = [];
        foreach ($paths as $path) {
            if (!in_array($path, $unique, true)) {
                $unique[] = $path;
            }
        }

        return $unique;
    }

    private function buildMailboxPathFor(string $host, string $port, string $encryption, bool $validateCert, string $folder): string
    {
        $flags = ['/imap'];

        if ($encryption === 'ssl') {
            $flags[] = '/ssl';
        } elseif ($encryption === 'tls') {
            $flags[] = '/tls';
        } elseif ($encryption === 'notls' || $encryption === 'none') {
            $flags[] = '/notls';
        }

        if (!$validateCert) {
            $flags[] = '/novalidate-cert';
        }

        $folder = $folder !== '' ? $folder : 'INBOX';
        return '{' . $host . ':' . $port . implode('', $flags) . '}' . $folder;
    }

    private function envBool(string $key, bool $default): bool
    {
        $raw = env($key);
        if ($raw === null) {
            return $default;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        $raw = strtolower(trim((string) $raw));
        if ($raw === '') {
            return $default;
        }

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private function redactMailboxPath(string $imapPath): string
    {
        // Keep host/port/flags for diagnostics, but avoid logging folder names with sensitive structure.
        if (preg_match('/^(\{[^}]+\})/i', $imapPath, $m) === 1) {
            return $m[1] . '<folder>';
        }
        return '<imap_path>';
    }

    private function normalizeImapExceptionMessage(\Throwable $e): string
    {
        if ($e instanceof ConnectionException) {
            $first = $e->getErrors('first');
            return is_string($first) ? $first : (string) $e->getMessage();
        }

        $msg = (string) $e->getMessage();
        $msgTrim = trim($msg);

        // Some upstream errors are JSON arrays encoded as a string.
        if ($msgTrim !== '' && str_starts_with($msgTrim, '[')) {
            $decoded = json_decode($msgTrim, true);
            if (is_array($decoded) && isset($decoded[0]) && is_string($decoded[0])) {
                return $decoded[0];
            }
        }

        return $msgTrim !== '' ? $msgTrim : get_class($e);
    }

    /**
     * Get email IDs from mailbox based on configured criteria.
     */
    public function getUnreadEmailIds(): array
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
                $suffix = $this->lastMailboxInitError ? (' ' . $this->lastMailboxInitError) : '';
                throw new \RuntimeException('IMAP mailbox is unavailable. Check IMAP configuration in .env.' . $suffix);
            }

            $criteria = strtoupper((string) env('IMAP_SEARCH_CRITERIA', 'UNSEEN'));
            $mailsIds = $this->mailbox->searchMailbox($criteria);

            if (!$mailsIds) {
                Log::info("No emails found for IMAP criteria: {$criteria}");
                return [];
            }

            return $mailsIds;
        } catch (\Throwable $e) {
            $msg = $this->normalizeImapExceptionMessage($e);
            Log::error('Error fetching unread emails: ' . $msg);
            throw new \RuntimeException($msg, 0, $e);
        }
    }

    /**
     * Fetch a single mail by ID without marking it as seen.
     */
    public function getMailById(int $mailId): IncomingMail
    {
        try {
            if (!extension_loaded('imap')) {
                throw new \RuntimeException('PHP IMAP extension is not enabled.');
            }

            if (!$this->mailbox) {
                $this->initializeMailbox();
            }

            if (!$this->mailbox) {
                $suffix = $this->lastMailboxInitError ? (' ' . $this->lastMailboxInitError) : '';
                throw new \RuntimeException('IMAP mailbox is unavailable.' . $suffix);
            }

            // IMPORTANT: do not mark emails as seen just by reading them.
            return $this->mailbox->getMail($mailId, false);
        } catch (\Throwable $e) {
            $msg = $this->normalizeImapExceptionMessage($e);
            Log::warning("Error fetching email ID {$mailId}: " . $msg);
            throw new \RuntimeException($msg, 0, $e);
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

        // Hard block: known email signature banner assets.
        if (
            ($normalizedTag !== '' && preg_match('/endless\s+possibilities|constellation|www\.amws\.space|amws\.space/i', $normalizedTag) === 1)
            || preg_match('/endless\s*possibilities|constellation/i', $normalizedUrl) === 1
        ) {
            return true;
        }

        // Some webmails proxy images like: https://ci*.googleusercontent.com/...#https://origin.example/img.png
        // Inspect the fragment too so the origin URL can be blocked.
        if (str_contains($normalizedUrl, '#')) {
            $fragment = (string) substr($normalizedUrl, (int) strrpos($normalizedUrl, '#') + 1);
            if ($fragment !== '' && preg_match('/^https?:\/\//i', $fragment) === 1) {
                if (
                    str_contains($fragment, 'amws.space')
                    || str_contains($fragment, 'amws')
                    || preg_match('/endless\s*possibilities|constellation/i', $fragment) === 1
                ) {
                    return true;
                }
            }
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
