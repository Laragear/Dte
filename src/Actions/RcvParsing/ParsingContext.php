<?php

namespace Laragear\Dte\Actions\RcvParsing;

use Illuminate\Support\LazyCollection;
use Laragear\Dte\Enums\RcvType;
use Laragear\Rut\Rut;

class ParsingContext
{
    /**
     * Create a new parsing context.
     *
     * @param  mixed  $source  Filepath, UploadedFile, string, or stream.
     * @param  RcvType  $type  The RCV type.
     * @param  Rut  $companyRut  The local company RUT to establish orientations.
     */
    public function __construct(
        public mixed $source,
        public readonly RcvType $type,
        public readonly Rut $companyRut,
        public array $headerMap = [],
        public ?LazyCollection $records = null,
        public mixed $stream = null,
    ) {
        //
    }
}
