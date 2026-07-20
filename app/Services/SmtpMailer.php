<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Minimal, dependency-free SMTP client (AUTH LOGIN) for sending plain-text
 * UTF-8 mails through an authenticated account — e.g. notif@leochiron.fr on
 * mail.leochiron.fr:465. Supports implicit TLS (port 465, 'ssl') and STARTTLS
 * (port 587, 'tls'). One connection is reused for every recipient.
 *
 * Config keys: host, port, username, password, secure ('ssl'|'tls'),
 *              from (optional), from_name (optional), timeout (optional).
 */
class SmtpMailer
{
    private array $config;
    /** @var resource|null */
    private $conn = null;
    private bool $dead = false;
    private string $lastError = '';

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** True when the config has the minimum needed to attempt SMTP delivery. */
    public static function isConfigured($config): bool
    {
        return is_array($config)
            && !empty($config['host'])
            && !empty($config['username'])
            && !empty($config['password']);
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * Sends one message. Returns true on success, false on any failure (the
     * reason is kept in lastError() and logged). The connection is opened on
     * the first call and reused afterwards.
     */
    public function send(string $fromEmail, string $fromName, string $to, string $subject, string $body): bool
    {
        try {
            if ($this->conn === null) {
                $this->connect();
            }

            $this->command('MAIL FROM:<' . $fromEmail . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', 250);
            $this->command('DATA', 354);

            $message = $this->buildMessage($fromEmail, $fromName, $to, $subject, $body);
            $this->write($message . "\r\n.");
            $this->expect(250);

            return true;
        } catch (RuntimeException $e) {
            $this->lastError = $e->getMessage();
            error_log('SMTP send failed: ' . $e->getMessage());
            // A broken pipe poisons the whole connection: drop it so we don't
            // keep trying on a dead socket for the remaining recipients.
            if (strpos($e->getMessage(), 'write') !== false || strpos($e->getMessage(), 'connect') !== false) {
                $this->dead = true;
            }
            return false;
        }
    }

    public function close(): void
    {
        if ($this->conn !== null && !$this->dead) {
            try {
                $this->write('QUIT');
            } catch (RuntimeException $e) {
                // ignore: we are closing anyway
            }
        }
        if (is_resource($this->conn)) {
            fclose($this->conn);
        }
        $this->conn = null;
    }

    // ------------------------------------------------------------------

    private function connect(): void
    {
        if ($this->dead) {
            throw new RuntimeException('connection previously failed');
        }

        $host = (string)$this->config['host'];
        $port = (int)($this->config['port'] ?? 465);
        $secure = (string)($this->config['secure'] ?? ($port === 465 ? 'ssl' : 'tls'));
        $timeout = (int)($this->config['timeout'] ?? 15);

        $transport = ($secure === 'ssl') ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);

        $conn = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($conn === false) {
            $this->dead = true;
            throw new RuntimeException('connect failed: ' . $errstr . ' (' . $errno . ')');
        }
        $this->conn = $conn;
        stream_set_timeout($this->conn, $timeout);

        // Any failure during the handshake (greeting, EHLO, TLS, AUTH) poisons
        // the connection: mark it dead so the remaining recipients fail fast.
        try {
            $this->expect(220); // server greeting

            $ehloName = $this->ehloName((string)($this->config['from'] ?? $this->config['username']));
            $this->command('EHLO ' . $ehloName, 250);

            // STARTTLS upgrade for port 587 / secure='tls'
            if ($secure === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed');
                }
                $this->command('EHLO ' . $ehloName, 250);
            }

            // AUTH LOGIN
            $this->command('AUTH LOGIN', 334);
            $this->command(base64_encode((string)$this->config['username']), 334);
            $this->command(base64_encode((string)$this->config['password']), 235);
        } catch (RuntimeException $e) {
            $this->dead = true;
            throw $e;
        }
    }

    private function buildMessage(string $fromEmail, string $fromName, string $to, string $subject, string $body): string
    {
        $from = $fromName !== ''
            ? mb_encode_mimeheader($fromName, 'UTF-8', 'B') . ' <' . $fromEmail . '>'
            : '<' . $fromEmail . '>';

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $from,
            'To: <' . $to . '>',
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->hostFromEmail($fromEmail) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        // Normalise to CRLF and dot-stuff lines starting with '.'
        $body = preg_replace("/\r\n|\r|\n/", "\r\n", $body) ?? $body;
        $body = preg_replace('/^\./m', '..', $body) ?? $body;

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function command(string $command, int $expectedCode): void
    {
        $this->write($command);
        $this->expect($expectedCode);
    }

    private function write(string $data): void
    {
        if (!is_resource($this->conn) || @fwrite($this->conn, $data . "\r\n") === false) {
            throw new RuntimeException('socket write failed');
        }
    }

    private function expect(int $code): void
    {
        $line = '';
        do {
            $line = fgets($this->conn, 515);
            if ($line === false) {
                throw new RuntimeException('no SMTP response (expected ' . $code . ')');
            }
            // Multiline replies use "250-..." continuations, "250 ..." ends them
            $continues = isset($line[3]) && $line[3] === '-';
        } while ($continues);

        $actual = (int)substr($line, 0, 3);
        if ($actual !== $code) {
            throw new RuntimeException('SMTP expected ' . $code . ', got: ' . trim($line));
        }
    }

    private function ehloName(string $fromOrUser): string
    {
        $domain = $this->hostFromEmail($fromOrUser);
        return $domain !== '' ? $domain : 'localhost';
    }

    private function hostFromEmail(string $email): string
    {
        $at = strrpos($email, '@');
        return $at !== false ? substr($email, $at + 1) : (string)($this->config['host'] ?? 'localhost');
    }
}
