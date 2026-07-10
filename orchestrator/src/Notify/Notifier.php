<?php

declare(strict_types=1);

namespace App\Notify;

/**
 * Failure/'success' notifications. Always appends to a log file; additionally
 * posts to a Discord webhook when configured. Failures include an optional
 * mention (ping); successes are plain. Per design, callers only notify on real
 * work or failures — not on every "nothing to do" run — to avoid hourly spam.
 */
final class Notifier
{
    public function __construct(
        private readonly string $logFile,
        private readonly string $webhookUrl = '',
        private readonly string $mention = '',
    ) {
    }

    public function success(string $title, string $message = ''): void
    {
        $this->send('✅', $title, $message, ping: false);
    }

    public function failure(string $title, string $message = ''): void
    {
        $this->send('❌', $title, $message, ping: true);
    }

    public function warning(string $title, string $message = ''): void
    {
        $this->send('⚠️', $title, $message, ping: false);
    }

    private function send(string $emoji, string $title, string $message, bool $ping): void
    {
        $line = sprintf('[%s] %s %s%s', date('Y-m-d H:i:s'), $emoji, $title,
            $message !== '' ? ' — ' . $message : '');
        $this->appendLog($line);

        if ($this->webhookUrl === '') {
            return;
        }

        $content = ($ping && $this->mention !== '' ? $this->mention . ' ' : '')
            . $emoji . ' **' . $title . '**' . ($message !== '' ? "\n" . $message : '');
        $this->postDiscord($content);
    }

    private function appendLog(string $line): void
    {
        $dir = \dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        @file_put_contents($this->logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function postDiscord(string $content): void
    {
        // Discord hard-limits content to 2000 chars.
        $payload = json_encode(['content' => mb_substr($content, 0, 1900)], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($this->webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($ok === false) {
            $this->appendLog(sprintf('[%s] (discord post failed: %s)', date('Y-m-d H:i:s'), $err));
        }
    }
}
