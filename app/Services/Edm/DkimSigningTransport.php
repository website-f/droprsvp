<?php

namespace App\Services\Edm;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Wraps the EDM mail transport to DKIM-sign each message with an organizer's
 * own domain key just before it leaves. Signing has to be the very last step
 * — any header added afterwards breaks the signature — and the transport is
 * the one place that runs after Laravel has finished building the message.
 */
final class DkimSigningTransport implements TransportInterface
{
    public function __construct(private TransportInterface $inner, private DkimSigner $signer) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($message instanceof Message) {
            // The envelope must come from the original: the signed copy no
            // longer carries the To/From objects Symfony derives it from.
            $envelope ??= Envelope::create($message);
            $message = $this->signer->sign($message);
        }

        return $this->inner->send($message, $envelope);
    }

    public function __toString(): string
    {
        return 'dkim+'.$this->inner;
    }
}
