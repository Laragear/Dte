<?php

namespace Laragear\Dte;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\DateFactory;
use Illuminate\Validation\Validator;
use Laragear\Dte\Caf\CafParser;
use Laragear\Dte\Support\OpenSslProxy;
use Laragear\Rut\Rut;
use Throwable;

class ValidatesSiiDocuments
{
    /**
     * The MIME Types to check against the uploaded file when the file is an Uploaded file.
     *
     * @const string[]
     */
    protected const array MIME_TYPES = [
        'com.rsa.pkcs-12',
        'application/x-pkcs12',
        'application/pkcs12',
        'application/octet-stream',
    ];

    /**
     * Validates the SII Certificate by decrypting it with a password and checking its validity.
     *
     * @param  UploadedFile  $value
     * @param  array<int, string>  $parameters
     */
    public static function validateSiiCertificate(
        string $attribute,
        mixed $value,
        array $parameters,
        Validator $validator,
    ): bool {
        $parameters[0] ??= 'password';
        $password = Arr::get($validator->getData(), $parameters[0]);

        if ($password === null || $password === '') {
            return false;
        }

        // If the certificate is not a correctly PKCS#12 mime type, bail out.
        if ($value instanceof UploadedFile) {
            if (! $validator->validateMimetypes($attribute, $value, static::MIME_TYPES)) {
                return false;
            }
            $pkcs12 = $value->get();
        } else {
            $pkcs12 = $value;
        }

        if (! is_string($pkcs12) || $pkcs12 === '') {
            return false;
        }

        $openSsl = app(OpenSslProxy::class);

        try {
            $pem = $openSsl->readPkcs12String($pkcs12, $password);
        } catch (Throwable) {
            return false;
        }

        if (! isset($pem['cert'])) {
            return false;
        }

        $metadata = $openSsl->parseX509($pem['cert']);

        $date = app(DateFactory::class);

        return $date->now()->isBetween(
            $date->createFromTimestamp($metadata['validFrom_time_t']),
            $date->createFromTimestamp($metadata['validTo_time_t']),
        );
    }

    /**
     * Validates the SII CAF XML.
     */
    public static function validateSiiCaf(
        string $attribute,
        mixed $value,
        array $parameters,
        Validator $validator,
    ): bool {
        if ($value instanceof UploadedFile) {
            if (! $validator->validateMimetypes($attribute, $value, ['text/xml', 'application/xml'])) {
                return false;
            }
            $xml = $value->get();
        } else {
            $xml = $value;
        }

        if (! is_string($xml) || $xml === '') {
            return false;
        }

        try {
            $parsed = app(CafParser::class)->parse($xml);
        } catch (Throwable) {
            return false;
        }

        if (isset($parameters[0])) {
            $expectedRut = Arr::get($validator->getData(), $parameters[0], $parameters[0]);

            try {
                if ($parsed['issuer_rut'] !== Rut::parse($expectedRut)->formatRaw()) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
