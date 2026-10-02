<?php

namespace App\Support\Edm\Bounces;

use RuntimeException;

/**
 * Just enough IMAP to read a bounce mailbox: log in, find unprocessed messages,
 * fetch each one, and mark it done.
 *
 * Written against the protocol rather than PHP's imap extension, because that
 * extension left PHP core in 8.4 and is missing on most current cPanel PHP
 * builds. It needs only an SSL socket, which every host has.
 *
 * Messages are marked with the EdmProcessed keyword, not \Seen, so a person
 * reading the mailbox in webmail cannot hide a bounce from the processor by
 * opening it first. Servers that do not allow custom keywords (rare; cPanel's
 * Dovecot does) fall back to \Seen.
 */
class ImapMailbox
{
    public const KEYWORD = 'EdmProcessed';

    private int $tag = 0;

    private bool $keywords = true;

    public function __construct(private ImapTransport $transport) {}

    public static function connect(string $host, int $port, string $encryption = 'ssl', int $timeout = 20): self
    {
        return new self(SocketTransport::open($host, $port, $encryption, $timeout));
    }

    public function login(string $username, string $password): void
    {
        $this->transport->readLine(); // server greeting
        $this->command('LOGIN '.$this->quote($username).' '.$this->quote($password));
    }

    public function select(string $folder = 'INBOX'): void
    {
        $lines = $this->command('SELECT '.$this->quote($folder));
        $flags = implode(' ', array_filter($lines, fn ($l) => str_contains($l, 'PERMANENTFLAGS')));

        // "\*" in PERMANENTFLAGS means new keywords may be created.
        $this->keywords = $flags === '' || str_contains($flags, '\\*');
    }

    /**
     * UIDs not yet processed, from the last $days days.
     *
     * @return list<int>
     */
    public function unprocessed(int $days = 14): array
    {
        $since = now()->subDays($days)->format('j-M-Y');
        $criteria = $this->keywords ? 'UNKEYWORD '.self::KEYWORD : 'UNSEEN';

        $uids = [];
        foreach ($this->command("UID SEARCH {$criteria} SINCE {$since}") as $line) {
            if (preg_match('/^\* SEARCH\s*(.*)$/i', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }

        return $uids;
    }

    /** The raw RFC 822 message, without marking it read. */
    public function fetch(int $uid): string
    {
        $lines = $this->command("UID FETCH {$uid} (BODY.PEEK[])", literal: true);

        return $lines['literal'] ?? '';
    }

    public function markProcessed(int $uid): void
    {
        $flag = $this->keywords ? self::KEYWORD : '\\Seen';
        $this->command("UID STORE {$uid} +FLAGS.SILENT ({$flag})");
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (RuntimeException) {
            // Already gone; nothing to clean up.
        }

        $this->transport->close();
    }

    /**
     * Send a command and read until its tagged reply.
     *
     * With $literal, the first {n} literal in the response is captured whole
     * under the 'literal' key — that is the message body for FETCH.
     *
     * @return array<int|string, string>
     */
    private function command(string $command, bool $literal = false): array
    {
        $tag = 'A'.str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        $this->transport->write("{$tag} {$command}\r\n");

        $lines = [];

        while (true) {
            $line = $this->transport->readLine();

            if ($line === null) {
                throw new RuntimeException('The mail server closed the connection.');
            }

            if (preg_match('/\{(\d+)\}\r?\n?$/', $line, $m)) {
                $data = $this->transport->read((int) $m[1]);

                if ($literal && ! isset($lines['literal'])) {
                    $lines['literal'] = $data;
                }

                continue;
            }

            if (str_starts_with($line, $tag.' ')) {
                $status = strtoupper(substr($line, strlen($tag) + 1, 2));

                if ($status !== 'OK') {
                    // Never echo a LOGIN command back: it holds the password.
                    $what = str_starts_with($command, 'LOGIN') ? 'LOGIN' : strtok($command, ' ');

                    throw new RuntimeException("IMAP {$what} failed: ".trim(substr($line, strlen($tag) + 1)));
                }

                return $lines;
            }

            $lines[] = rtrim($line, "\r\n");
        }
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
