<?php

namespace Laragear\Dte\Pdf;

use Closure;
use DateTimeInterface;
use DOMElement;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as Filesystem;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View as ViewContract;
use InvalidArgumentException;
use Laragear\Dte\Data\PdfData;
use Laragear\Dte\Models\SiiDte;
use Laragear\Dte\Support\LibxmlProxy;
use Laragear\Dte\Support\XmlDomFactory;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Spatie\LaravelPdf\PdfBuilder as SpatiePdfBuilder;
use Symfony\Component\HttpFoundation\Response;

use function is_a;
use function trim;

class PdfBuilder implements Responsable
{
    protected SiiDte $dte;

    protected ?string $disk = null;

    protected ?string $path = null;

    protected bool $force = false;

    protected ?Closure $customization = null;

    /**
     * Create a new PDF Builder instance.
     */
    public function __construct(
        protected Repository $config,
        protected Filesystem $storage,
        protected ViewFactory $view,
        protected Pdf417Generator $barcode,
        protected LibxmlProxy $libxml,
        protected XmlDomFactory $xml,
    ) {
        //
    }

    /**
     * Set the DTE for the builder.
     */
    public function forDte(SiiDte $dte): static
    {
        $this->dte = $dte;

        return $this;
    }

    /**
     * Set the disk to store the PDF.
     */
    public function disk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Set the path to store the PDF.
     */
    public function as(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Force the PDF to be generated even if it already exists.
     */
    public function force(mixed $condition = true): static
    {
        $this->force = value($condition, $this->dte);

        return $this;
    }

    /**
     * Set a callback to customize the underlying PDF builder.
     *
     * @param  Closure(SpatiePdfBuilder):void  $callback
     */
    public function customize(Closure $callback): static
    {
        $this->customization = $callback;

        return $this;
    }

    /**
     * Resolve the Spatie PDF Builder instance.
     */
    protected function resolveSpatieBuilder(?string $view = null, array $data = []): SpatiePdfBuilder|FakePdfBuilder
    {
        $html = $this->view($view, $data)->render();

        // Using the facade allows us to test with `Pdf::fake()`. Blame Spatie.
        $builder = Pdf::html($html)
            ->driver($this->config->get('dte.pdf.driver', 'dompdf'))
            ->format('letter');

        if ($this->customization) {
            ($this->customization)($builder);
        }

        return $builder;
    }

    /**
     * Get the View instance for the PDF.
     */
    public function view(?string $customView = null, array $data = []): ViewContract
    {
        $viewName = $customView
            ?? $this->config->get('dte.pdf.views.'.$this->dte->document_type->value)
            ?? $this->config->get('dte.pdf.views.default');

        $xml = $this->dte->payload?->xml
            ?? throw new InvalidArgumentException('The DTE must have an XML payload to generate a PDF.');

        $ted = $this->extractTed($xml);

        return $this->view->make($viewName, array_merge([
            'dte' => $this->dte,
            'barcode' => $this->barcode->generate($ted),
            'cedible' => false,
        ], $data));
    }

    /**
     * Resolve the disk and path for this DTE's PDF.
     *
     * @return array{disk: string, path: string}
     */
    protected function resolveDiskAndPath(): array
    {
        $disk = $this->disk ?? $this->config->get('dte.pdf.disk') ?? $this->config->get('filesystems.default');

        $path = $this->path ?? sprintf(
            '%s/%s-%s_%s_%s_%s.pdf',
            trim($this->config->get('dte.pdf.prefix', 'dte/pdf'), '/'),
            $this->dte->issuer_rut->num,
            $this->dte->issuer_rut->vd,
            $this->dte->document_type->value,
            $this->dte->folio,
            $this->dte->created_at->format('Y-m-d_His'),
        );

        return compact('disk', 'path');
    }

    /**
     * Generates and stores the PDF.
     */
    public function generate(): PdfData
    {
        ['disk' => $disk, 'path' => $path] = $this->resolveDiskAndPath();

        $storage = $this->storage->disk($disk);

        if ($this->force || ! $storage->exists($path)) {
            $storage->put($path, $this->binary());
        }

        return new PdfData($disk, $path);
    }

    /**
     * Direct binary access for advanced usage.
     */
    public function binary(): string
    {
        $spatie = $this->resolveSpatieBuilder();

        // Defensively return fake binary content when the stupidly made
        // fake builder calls for binary content. Spatie quality.
        $fake = '\Spatie\LaravelPdf\FakePdfBuilder';

        if (class_exists($fake) && is_a($spatie, $fake, true)) {
            return 'fake-pdf-content';
        }

        return $spatie->generatePdfContent();
    }

    /**
     * Download the PDF as a Response.
     */
    public function download(?string $name = null, array $headers = []): SpatiePdfBuilder|FakePdfBuilder
    {
        return $this->resolveSpatieBuilder()->headers($headers)->download($name);
    }

    /**
     * Get the URL to the PDF.
     */
    public function url(): string
    {
        $pdf = $this->generate();

        return $this->storage->disk($pdf->disk)->url($pdf->path);
    }

    /**
     * Get a temporary URL to the PDF.
     */
    public function temporaryUrl(DateTimeInterface $expiration, array $options = []): string
    {
        $pdf = $this->generate();

        return $this->storage->disk($pdf->disk)->temporaryUrl($pdf->path, $expiration, $options);
    }

    /**
     * Delete the PDF if it exists.
     */
    public function delete(): bool
    {
        ['disk' => $disk, 'path' => $path] = $this->resolveDiskAndPath();

        return $this->storage->disk($disk)->delete($path);
    }

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse($request): Response
    {
        return $this->resolveSpatieBuilder()->inline()->toResponse($request);
    }

    /**
     * Extract the raw <TED>...</TED> element from the compiled XML payload.
     */
    protected function extractTed(string $xml): string
    {
        $document = $this->xml->document();

        $previous = $this->libxml->use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        $this->libxml->clear_errors();
        $this->libxml->use_internal_errors($previous);

        if (! $loaded) {
            throw new InvalidArgumentException('The XML payload is invalid.');
        }

        $xpath = $this->xml->xpath($document);
        $xpath->registerNamespace('sii', 'http://www.sii.cl/SiiDte');

        $ted = $xpath->query('//sii:TED')->item(0);

        if (! $ted instanceof DOMElement) {
            throw new InvalidArgumentException('The XML payload does not contain a TED element.');
        }

        return $document->saveXML($ted) ?: throw new InvalidArgumentException('Unable to extract the TED XML.');
    }
}
