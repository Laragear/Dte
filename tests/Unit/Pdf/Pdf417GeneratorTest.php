<?php

namespace Tests\Unit\Pdf;

use Exception;
use Intervention\Image\Image;
use Laragear\Dte\Pdf\Pdf417Generator;
use Le\PDF417\BarcodeData;
use Le\PDF417\PDF417;
use Le\PDF417\Renderer\ImageRenderer;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class Pdf417GeneratorTest extends TestCase
{
    public function test_generates_pdf417_barcode_data_uri(): void
    {
        $image = Mockery::mock(Image::class);
        $data = new BarcodeData;

        $encoder = $this->mock(PDF417::class, static function (MockInterface $mock) use ($data): void {
            $mock->expects('encode')->with('<TED>test</TED>')->once()->andReturn($data);
        });

        $renderer = $this->mock(ImageRenderer::class, static function (MockInterface $mock) use ($data, $image): void {
            $mock->expects('render')->with($data)->once()->andReturn($image);
        });

        $image->expects('encode')->with('png', 100)->once()->andReturn('binary_png_data');

        $generator = $this->app->make(Pdf417Generator::class, [
            'encoder' => $encoder,
            'renderer' => $renderer,
        ]);

        $result = $generator->generate('<TED>test</TED>');

        static::assertSame('data:image/png;base64,'.base64_encode('binary_png_data'), $result);
    }

    public function test_wraps_exceptions_in_runtime_exception(): void
    {
        $encoder = $this->mock(PDF417::class);
        $encoder->expects('encode')->andThrow(new Exception('Encoding failed.'));

        $renderer = $this->mock(ImageRenderer::class);

        $generator = $this->app->make(Pdf417Generator::class, [
            'encoder' => $encoder,
            'renderer' => $renderer,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to generate the PDF417 barcode.');

        $generator->generate('<TED>test</TED>');
    }
}
