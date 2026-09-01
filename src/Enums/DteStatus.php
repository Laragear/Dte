<?php

namespace Laragear\Dte\Enums;

enum DteStatus: string
{
    public const self DEFAULT = self::Pending;

    /** The document is waiting to be processed. */
    case Pending = 'pending';

    /** The document data is being converted into XML. */
    case Building = 'building';

    /** The document cannot continue until an authorized folio is available. */
    case RequiresCaf = 'requires_caf';

    /** The document XML is being digitally signed. */
    case Signing = 'signing';

    /** The document has a valid digital signature and is waiting to be included in an envelope. */
    case Signed = 'signed';

    /** The document envelope has been sent to the SII. */
    case Sent = 'sent';

    /** The SII accepted the document. */
    case Accepted = 'accepted';

    /** The SII rejected the document. */
    case Rejected = 'rejected';

    /** Processing stopped because of an unrecoverable error. */
    case Failed = 'failed';

    /** The document was legally cancelled. */
    case Annulled = 'annulled';

    /** Determine if the document has reached a final state. */
    public function isTerminalState(): bool
    {
        return match ($this) {
            self::Accepted, self::Rejected, self::Failed, self::Annulled => true,
            default => false,
        };
    }
}
