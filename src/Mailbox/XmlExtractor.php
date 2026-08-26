<?php

namespace Laragear\Dte\Mailbox;

class XmlExtractor
{
    /**
     * Extracts XML from a raw email payload.
     */
    public function extractFromRaw(string $raw): string
    {
        if (preg_match('/<\?xml[\s\S]+/i', $raw, $matches)) {
            return $matches[0] ?? '';
        }

        if (preg_match('/Content-Type: text\/xml[\s\S]*?\r\n\r\n([A-Za-z0-9+\/\r\n=]+)/im', $raw, $matches)) {
            return base64_decode(trim($matches[1] ?? '')) ?: '';
        }

        return '';
    }

    /**
     * Extracts XML from a generic string payload.
     * Often used when the attachment is already decoded.
     */
    public function extractFromString(string $body): string
    {
        if (preg_match('/<\?xml[\s\S]+/i', $body, $matches)) {
            return isset($matches[0]) ? (string) $matches[0] : $body;
        }

        if (preg_match('/^([A-Za-z0-9+\/\r\n=]+)$/m', $body, $matches)) {
            $decoded = base64_decode(isset($matches[1]) ? (string) $matches[1] : '', true);

            if ($decoded !== false && str_contains($decoded, '<?xml')) {
                return $decoded;
            }
        }

        return $body;
    }
}
