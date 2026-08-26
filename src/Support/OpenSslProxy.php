<?php

namespace Laragear\Dte\Support;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;
use SensitiveParameter;
use Throwable;

use function array_shift;
use function array_values;
use function is_array;
use function is_int;
use function is_string;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkcs12_export;
use function openssl_pkey_new;
use function openssl_x509_check_private_key;
use function preg_match;
use function preg_match_all;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @internal
 */
class OpenSslProxy
{
    /**
     * Create a new OpenSSL Proxy instance.
     */
    public function __construct(
        protected Filesystem $file,
        protected Factory $process,
    ) {
        //
    }

    /**
     * Read a PKCS#12 certificate from the given path.
     *
     * @return array{cert: string, pkey: string, extracerts?: list<string>}
     */
    public function readPkcs12(string $path, string $password): array
    {
        return $this->readPkcs12String($this->readFile($path), $password);
    }

    /**
     * Read a PKCS#12 certificate from a binary string.
     *
     * @return array{cert: string, pkey: string, extracerts?: list<string>}
     */
    public function readPkcs12String(string $contents, string $password): array
    {
        $certificates = [];
        $success = false;

        try {
            $success = openssl_pkcs12_read($contents, $certificates, $password);
        } catch (Throwable $e) {
            try {
                return $this->readLegacyPkcs12String($contents, $password);
            } catch (Throwable) {
                throw new RuntimeException('Unable to read the PKCS#12 certificate.', previous: $e);
            }
        }

        if ($success) {
            return $this->normalizeCertificates($certificates);
        }

        return $this->readLegacyPkcs12String($contents, $password);
    }

    /**
     * Read the certificate file contents.
     */
    protected function readFile(string $path): string
    {
        try {
            return $this->file->get($path);
        } catch (FileNotFoundException) {
            throw new RuntimeException("Unable to read the PKCS#12 certificate at [$path].");
        }
    }

    /**
     * Normalize the native PKCS#12 certificate output.
     *
     * @param  array<array-key, mixed>  $certificates
     * @return array{cert: string, pkey: string, extracerts?: list<string>}
     */
    protected function normalizeCertificates(array $certificates): array
    {
        if (! is_string($certificates['cert'] ?? null) || ! is_string($certificates['pkey'] ?? null)) {
            throw new RuntimeException('The PKCS#12 certificate does not contain a certificate and private key.');
        }

        $result = ['cert' => $certificates['cert'], 'pkey' => $certificates['pkey']];

        if (isset($certificates['extracerts'])) {
            $result['extracerts'] = array_values($certificates['extracerts']);
        }

        return $result;
    }

    /**
     * Read the certificate using the OpenSSL legacy provider.
     *
     * @return array{cert: string, pkey: string, extracerts?: list<string>}
     */
    protected function readLegacyPkcs12String(string $contents, string $password): array
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pkcs12_'.uniqid();

        if (! $this->file->put($path, $contents)) {
            throw new RuntimeException('Unable to create a temporary file for the PKCS#12 certificate.');
        }

        try {
            $result = $this->process->command([
                'openssl',
                'pkcs12',
                '-in',
                $path,
                '-nodes',
                '-legacy',
                '-passin',
                'env:DTE_PKCS12_PASSWORD',
            ])->env([
                'DTE_PKCS12_PASSWORD' => $password,
            ])->run();
        } finally {
            $this->file->delete($path);
        }

        if (! $result?->successful()) {
            if ($result->seeInErrorOutput('password')) {
                throw new RuntimeException('Unable to read the PKCS#12 certificate using wrong password.');
            }

            throw new RuntimeException('Unable to read the PKCS#12 certificate using OpenSSL legacy mode.');
        }

        return $this->parsePem($result->output());
    }

    /**
     * Parse certificate and private-key PEM blocks from OpenSSL output.
     *
     * @return array{cert: string, pkey: string, extracerts?: list<string>}
     */
    protected function parsePem(string $pem): array
    {
        // 1. Optimize PCRE: Remove named capture groups to prevent array duplication
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $certificates);
        preg_match('/-----BEGIN ((?:RSA |EC )?PRIVATE KEY)-----.*?-----END \1-----/s', $pem, $privateKey);

        $certificateMatches = $certificates[0] ?? [];

        // 2. Free the multi-dimensional regex array immediately
        unset($certificates);

        if (empty($certificateMatches) || empty($privateKey[0])) {
            throw new RuntimeException('OpenSSL legacy mode did not return a certificate and private key.');
        }

        $result = [
            // 3. array_shift automatically re-indexes the array numerically from 0
            'cert' => array_shift($certificateMatches),
            'pkey' => $privateKey[0],
        ];

        // Free the private key matches array
        unset($privateKey);

        if ($certificateMatches !== []) {
            // 4. Assign directly. The redundant array_slice and array_values are gone.
            $result['extracerts'] = $certificateMatches;
        }

        return $result;
    }

    /**
     * Parse validity metadata from an X.509 certificate.
     *
     * @return array{validFrom_time_t: int, validTo_time_t: int}
     */
    public function parseX509(string $certificate): array
    {
        try {
            $metadata = openssl_x509_parse($certificate, false);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to parse the X.509 certificate metadata.', previous: $e);
        }

        if (
            ! is_array($metadata)
            || ! is_int($metadata['validFrom_time_t'] ?? null)
            || ! is_int($metadata['validTo_time_t'] ?? null)
        ) {
            throw new RuntimeException('Unable to parse the X.509 certificate metadata.');
        }

        return [
            'validFrom_time_t' => $metadata['validFrom_time_t'],
            'validTo_time_t' => $metadata['validTo_time_t'],
        ];
    }

    /**
     * Determine whether a private key belongs to an X.509 certificate.
     */
    public function privateKeyMatches(string $certificate, string $privateKey): bool
    {
        try {
            return openssl_x509_check_private_key($certificate, $privateKey);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to check if the private key matches the certificate.', previous: $e);
        }
    }

    /**
     * Sign data with the given private key using SHA1.
     */
    public function sign(string $data, string $privateKey): string
    {
        $signature = '';

        try {
            $result = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA1);
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to sign data with private key.', previous: $e);
        }

        if (! $result || $signature === '') {
            throw new RuntimeException('Failed to sign data with private key.');
        }

        return base64_encode($signature);
    }

    /**
     * Get the details of a private key.
     *
     * @return array<string, mixed>|null
     */
    public function privateKeyDetails(string $privateKey): ?array
    {
        try {
            $key = openssl_pkey_get_private($privateKey);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to parse the private key.', previous: $e);
        }

        if ($key === false) {
            return null;
        }

        try {
            $details = openssl_pkey_get_details($key);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to get the private key details.', previous: $e);
        }

        return is_array($details) ? $details : null;
    }

    /**
     * Generates a new private key
     */
    public function pkeyNew(?array $options = null): OpenSSLAsymmetricKey|false
    {
        return openssl_pkey_new($options);
    }

    /**
     * Returns an array with the key details
     */
    public function pkeyGetDetails(OpenSSLAsymmetricKey $key): array|false
    {
        return openssl_pkey_get_details($key);
    }

    /**
     * Gets an exportable representation of a key into a string
     */
    public function pkeyExport(
        #[SensitiveParameter]
        OpenSSLAsymmetricKey|OpenSSLCertificate|array|string $key,
        string &$output,
        #[SensitiveParameter]
        ?string $passphrase = null,
        ?array $options = null,
    ): bool {
        return openssl_pkey_export($key, $output, $passphrase, $options);
    }

    /**
     * Generates a CSR (Cross-Signing Request).
     */
    public function csrNew(
        array $distinguishedNames,
        &$privateKey,
        ?array $options = null,
        ?array $extraAttributes = null,
    ): OpenSSLCertificateSigningRequest|false {
        return openssl_csr_new($distinguishedNames, $privateKey, $options, $extraAttributes);
    }

    /**
     * Sign a CSR with another certificate (or itself) and generate a certificate.
     */
    public function csrSign(
        OpenSSLCertificateSigningRequest|string $csr,
        OpenSSLCertificate|string|null $caCertificate,
        #[SensitiveParameter]
        OpenSSLAsymmetricKey|OpenSSLCertificate|array|string $privateKey,
        int $days,
        ?array $options = null,
        int $serial = 0,
        ?string $serialHex = null,
    ): OpenSSLCertificate|false {
        return openssl_csr_sign($csr, $caCertificate, $privateKey, $days, $options, $serial, $serialHex);
    }

    /**
     * Exports a PKCS#12 Compatible Certificate Store File to variable.
     */
    public function pkcs12Export(
        OpenSSLCertificate|string $certificate,
        string &$output,
        #[SensitiveParameter]
        OpenSSLAsymmetricKey|OpenSSLCertificate|array|string $privateKey,
        #[SensitiveParameter]
        string $passphrase,
        array $options = [],
    ): bool {
        return openssl_pkcs12_export($certificate, $output, $privateKey, $passphrase, $options);
    }

    /**
     * Verify signature.
     */
    public function verify(
        string $data,
        string $signature,
        $publicKey,
        string|int $algorithm = OPENSSL_ALGO_SHA1,
        int $padding = 0,
    ): int|false {
        return openssl_verify($data, $signature, $publicKey, $algorithm, $padding);
    }
}
