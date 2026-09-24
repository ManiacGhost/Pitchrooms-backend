<?php

declare(strict_types=1);

namespace PitchRooms\Support;

use RuntimeException;

/**
 * Minimal SMTP client — enough to send transactional mail through Brevo's
 * relay with no Composer dependency.
 *
 * Supports STARTTLS on 587 (Brevo's default) and implicit TLS on 465, with
 * AUTH LOGIN / PLAIN. Messages go out as multipart/alternative so every client
 * gets either the HTML or the plain-text part.
 */
final class Smtp
{
    /** @var resource|null */
    private $socket = null;
    private array $transcript = [];

    public function __construct(
        private string $host,
        private int $port = 587,
        private string $username = '',
        private string $password = '',
        private string $security = 'tls',
        private int $timeout = 20
    ) {
    }

    /**
     * @param array{email:string,name?:string} $from
     * @param array<int, string> $to
     * @throws RuntimeException on any protocol or transport failure
     */
    public function send(array $from, array $to, string $subject, string $html, string $text = '', ?string $replyTo = null): bool
    {
        if ($to === []) {
            throw new RuntimeException('No recipients given.');
        }

        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();

            $this->command('MAIL FROM:<' . $from['email'] . '>', [250]);
            foreach ($to as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            $this->command('DATA', [354]);

            // Dot-stuff the body only, then append the terminator. Stuffing
            // after appending would turn the closing "." into ".." and the
            // server would wait forever for an end that never comes.
            $message = $this->buildMessage($from, $to, $subject, $html, $text, $replyTo);
            $this->write($this->dotStuff($message) . "\r\n.", raw: true);
            $this->expect([250]);

            $this->command('QUIT', [221, 250]);
        } finally {
            $this->disconnect();
        }

        return true;
    }

    private function connect(): void
    {
        $useImplicitTls = strtolower($this->security) === 'ssl' || $this->port === 465;
        $address = ($useImplicitTls ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'SNI_enabled'       => true,
                'peer_name'         => $this->host,
            ],
        ]);

        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            $address,
            $errorNumber,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf(
                'Cannot reach %s:%d — %s. Shared hosts often block outbound SMTP; try MAIL_DRIVER=brevo_api.',
                $this->host,
                $this->port,
                $errorMessage !== '' ? $errorMessage : 'connection refused'
            ));
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $this->expect([220]);
    }

    private function handshake(): void
    {
        $hostname = gethostname() ?: 'pitchrooms';
        $this->command('EHLO ' . $hostname, [250]);

        if (strtolower($this->security) === 'tls' && $this->port !== 465) {
            $this->command('STARTTLS', [220]);

            if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed — the server certificate could not be verified.');
            }

            // The EHLO must be repeated once the channel is encrypted.
            $this->command('EHLO ' . $hostname, [250]);
        }
    }

    private function authenticate(): void
    {
        if ($this->username === '' || $this->password === '') {
            return;
        }

        try {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334], sensitive: true);
            $this->command(base64_encode($this->password), [235], sensitive: true);
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                'SMTP authentication failed. For Brevo, SMTP_USER is your Brevo login email and '
                . 'SMTP_PASS is the SMTP key (not your account password). ' . $e->getMessage()
            );
        }
    }

    private function buildMessage(array $from, array $to, string $subject, string $html, string $text, ?string $replyTo): string
    {
        $boundary = 'pr-' . bin2hex(random_bytes(12));
        $fromName = $this->encodeHeader($from['name'] ?? 'PitchRooms');

        if ($text === '') {
            $text = self::htmlToText($html);
        }

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . sprintf('%s <%s>', $fromName, $from['email']),
            'To: ' . implode(', ', $to),
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($this->host) . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: PitchRooms',
        ];

        if ($replyTo !== null && $replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        $body = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode($text),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode($html),
            '--' . $boundary . '--',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $body);
    }

    /**
     * Plain-text alternative. Block-level tags become line breaks so the text
     * part does not read as one run-on paragraph, and links keep their target.
     */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = preg_replace('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '$2 ($1)', $text) ?? $text;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|h[1-6]|li|tr|table|blockquote)>#i', "\n\n", $text) ?? $text;
        $text = preg_replace('#<li\b[^>]*>#i', '- ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim(implode("\n", array_map('trim', explode("\n", $text))));
    }

    private function encodeHeader(string $value): string
    {
        // Non-ASCII subjects and names need RFC 2047 encoding.
        return preg_match('/[\x80-\xFF]/', $value) === 1
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    private function command(string $command, array $expectedCodes, bool $sensitive = false): string
    {
        $this->write($command, $sensitive);

        return $this->expect($expectedCodes);
    }

    /**
     * A leading "." on any body line would be read as end-of-data, so it is
     * doubled. RFC 5321 §4.5.2.
     */
    private function dotStuff(string $body): string
    {
        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    /**
     * $sensitive keeps credentials out of the transcript.
     * $raw skips dot-stuffing for payloads already stuffed by the caller.
     */
    private function write(string $data, bool $sensitive = false, bool $raw = false): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('SMTP socket is closed.');
        }

        if (!$raw) {
            $data = $this->dotStuff($data);
        }

        $this->transcript[] = '> ' . ($sensitive ? '[redacted]' : substr($data, 0, 120));

        if (@fwrite($this->socket, $data . "\r\n") === false) {
            throw new RuntimeException('Writing to the SMTP socket failed.');
        }
    }

    private function expect(array $codes): string
    {
        if ($this->socket === null) {
            throw new RuntimeException('SMTP socket is closed.');
        }

        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            // Multi-line replies use "250-"; the final line uses "250 ".
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($response === '') {
            $meta = stream_get_meta_data($this->socket);
            throw new RuntimeException($meta['timed_out'] ?? false
                ? 'SMTP server timed out.'
                : 'SMTP server closed the connection unexpectedly.');
        }

        $this->transcript[] = '< ' . trim($response);
        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $codes, true)) {
            throw new RuntimeException(sprintf('SMTP error: expected %s, got "%s".', implode('/', $codes), trim($response)));
        }

        return $response;
    }

    private function disconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /** Protocol transcript for diagnostics; credentials are redacted. */
    public function transcript(): array
    {
        return $this->transcript;
    }
}
