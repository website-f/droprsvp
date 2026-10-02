<?php

namespace App\Support\Edm\Bounces;

/** The wire under ImapMailbox — a socket in production, a script in tests. */
interface ImapTransport
{
    public function write(string $data): void;

    /** One line including its line ending, or null when the connection is gone. */
    public function readLine(): ?string;

    /** Exactly $bytes bytes (an IMAP literal). */
    public function read(int $bytes): string;

    public function close(): void;
}
