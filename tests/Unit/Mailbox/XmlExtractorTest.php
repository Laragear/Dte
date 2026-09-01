<?php

namespace Tests\Unit\Mailbox;

use Laragear\Dte\Mailbox\XmlExtractor;
use Tests\TestCase;

class XmlExtractorTest extends TestCase
{
    public function test_extracts(): void
    {
        $extractor = new XmlExtractor;

        static::assertSame('<?xml version="1.0"?>', $extractor->extractFromRaw('bla <?xml version="1.0"?>'));
        static::assertSame(
            '<?xml content',
            $extractor->extractFromRaw("Content-Type: text/xml\r\n\r\n".base64_encode('<?xml content')),
        );
        static::assertSame('', $extractor->extractFromRaw('no xml at all'));

        static::assertSame('<?xml version="1.0"?>', $extractor->extractFromString('bla <?xml version="1.0"?>'));
        static::assertSame('<?xml content tag', $extractor->extractFromString(base64_encode('<?xml content tag')));
        static::assertSame('no xml at all', $extractor->extractFromString('no xml at all'));

        $b64 = base64_encode('only base64 but no xml');
        static::assertSame($b64, $extractor->extractFromString($b64));
    }
}
