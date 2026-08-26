<?php

namespace Laragear\Dte\Pdf;

use Le\PDF417\PDF417;
use Le\PDF417\Renderer\ImageRenderer;
use RuntimeException;
use Throwable;

use function base64_encode;

class Pdf417Generator
{
    /**
     * Create the PDF417 Generator instance.
     */
    public function __construct(
        protected PDF417 $encoder,
        protected ImageRenderer $renderer,
    ) {
        //
    }

    /**
     * Generate a PDF417 barcode from the TED XML string.
     */
    public function generate(string $ted): string
    {
        try {
            return 'data:image/png;base64,'.base64_encode(
                (string) $this->renderer->render($this->encoder->encode($ted))->encode('png', 100)
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to generate the PDF417 barcode.', 0, $e);
        }
    }
}
