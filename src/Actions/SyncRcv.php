<?php

namespace Laragear\Dte\Actions;

use Illuminate\Contracts\Config\Repository;
use Laragear\Dte\Actions\Cuadratura\Sync;
use Laragear\Dte\Actions\RcvParsing\Parse;
use Laragear\Dte\Configuration\ConfigurationManager;
use Laragear\Dte\Enums\RcvType;
use Laragear\Rut\Rut;

class SyncRcv
{
    /**
     * Create a new SyncRcv action instance.
     */
    public function __construct(
        protected Repository $config,
        protected Parse $parser,
        protected Sync $cuadratura,
        protected ConfigurationManager $configManager,
    ) {
        //
    }

    /**
     * Executes the RCV synchronization pipeline against a source file or payload.
     *
     * @param  mixed  $source  File path, SplFileInfo, UploadedFile, string payload, or stream resource.
     * @return array<string, int>
     */
    public function handle(mixed $source, RcvType|string $type): array
    {
        $type = is_string($type) ? RcvType::from($type) : $type;

        $companyRut = Rut::parse(
            $this->configManager->getIssuer()?->rut?->formatRaw() ?? $this->config->get('dte.issuer.rut'),
        );

        $context = $this->parser->forBatch($source, $type, $companyRut);

        return $this->cuadratura->forParsing($context);
    }
}
