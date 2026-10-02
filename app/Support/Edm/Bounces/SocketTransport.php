<?php

namespace App\Support\Edm\Bounces;

use RuntimeException;

/** IMAP over a TCP socket: implicit TLS (993), STARTTLS (143), or plain. */
final class SocketTransport implements ImapTransport
{
    /** @param resource $stream */
    private function __construct(private $stream) {}

    public static function open(string $host, int $port, string $encryption = 'ssl', int $timeout = 20): self
    {
        $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $stream = @stream_socket_client("{$scheme}://{$host}:{$port}", $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (! $stream) {
            throw new RuntimeException("Could not reach {$host}:{$port} ({$error}).");
        }

        stream_set_timeout($stream, $timeout);
        $transport = new self($stream);

        if ($encryption === 'tls') {
            $transport->readLine(); // greeting
            $transport->write("S0 STARTTLS\r\n");
            $transport->readLine();

            if (! stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException("STARTTLS with {$host} failed.");
            }

            // ImapMailbox::login() expects to read a greeting first.
            $transport->pending = "* OK ready after STARTTLS\r\n";
        }

        return $transport;
    }

    private ?string $pending = null;

    public function write(string $data): void
    {
        if (@fwrite($this->stream, $data) === false) {
            throw new RuntimeException('Writing to the mail server failed.');
        }
    }

    public function readLine(): ?string
    {
        if ($this->pending !== null) {
            [$line, $this->pending] = [$this->pending, null];

            return $line;
        }

        $line = fgets($this->stream);

        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);

            if ($meta['timed_out'] ?? false) {
                throw new RuntimeException('The mail server stopped responding.');
            }

            return null;
        }

        return $line;
    }

    public function read(int $bytes): string
    {
        $data = '';

        while (strlen($data) < $bytes && ! feof($this->stream)) {
            $chunk = fread($this->stream, $bytes - strlen($data));

            if ($chunk === false || ($chunk === '' && (stream_get_meta_data($this->stream)['timed_out'] ?? false))) {
                throw new RuntimeException('The mail server stopped mid-message.');
            }

            $data .= $chunk;
        }

        return $data;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
