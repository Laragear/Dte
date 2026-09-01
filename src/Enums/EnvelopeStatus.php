<?php

namespace Laragear\Dte\Enums;

enum EnvelopeStatus: string
{
    public const self DEFAULT = self::Pending;

    /** The envelope is waiting to be assembled. */
    case Pending = 'pending';

    /** Signed documents are being added to the envelope. */
    case Assembling = 'assembling';

    /** The assembled envelope is being digitally signed. */
    case Signing = 'signing';

    /** The envelope has a valid digital signature. */
    case Signed = 'signed';

    /** The envelope is being sent to the SII. */
    case Sending = 'sending';

    /** The SII received the envelope and assigned a tracking identifier. */
    case Uploaded = 'uploaded';

    /** The SII accepted the envelope. */
    case Accepted = 'accepted';

    /** The SII rejected the envelope. */
    case Rejected = 'rejected';

    /** Processing stopped because of an unrecoverable error. */
    case Failed = 'failed';

    /** Determine if the envelope has reached a final state. */
    public function isTerminalState(): bool
    {
        return match ($this) {
            self::Accepted, self::Rejected, self::Failed => true,
            default => false,
        };
    }
}
