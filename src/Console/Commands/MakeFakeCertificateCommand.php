<?php

namespace Laragear\Dte\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Laragear\Dte\Configuration\ConfigurationManager as Config;
use Laragear\Dte\Console\Commands\Concerns\HasDefaultRut;
use Laragear\Dte\Support\OpenSslProxy as OpenSsl;

class MakeFakeCertificateCommand extends Command
{
    use HasDefaultRut;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dte:make-fake-cert
                            {--rut= : The RUT for the certificate (defaults to dummy)}
                            {--disk= : The storage disk to use to save the .p12 certificate}
                            {--path= : The path to save the .p12 certificate}
                            {--password=secret : The password for the .p12 certificate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a self-signed dummy PKCS#12 (.p12) digital certificate for local/testing environments.';

    /**
     * Execute the console command.
     */
    public function handle(Repository $config, Factory $storage, OpenSsl $openSsl, Config $configManager): int
    {
        $rut = $this->rut($configManager);
        $name = $this->option('disk') ?: $config->get('dte.certificate.disk') ?: 'local';
        $path = $this->option('path') ?: $config->get('dte.certificate.path') ?: 'certificate.p12';
        $password = $this->option('password') ?: $config->get('dte.certificate.password') ?: 'secret';

        $this->info("Generating dummy certificate for {$name} ({$rut->format()})...");

        $key = $openSsl->pkeyNew([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            $this->error('Failed to generate private key.');

            return self::FAILURE;
        }

        $dn = [
            'countryName' => 'CL',
            'stateOrProvinceName' => 'RM',
            'localityName' => 'Santiago',
            'organizationName' => $name,
            'commonName' => $name,
            'serialNumber' => $rut->formatBasic(),
        ];

        $csr = $openSsl->csrNew($dn, $key);

        if ($csr === false) {
            $this->error('Failed to generate CSR.');

            return self::FAILURE;
        }

        $cert = $openSsl->csrSign($csr, null, $key, 365 * 2);

        if ($cert === false) {
            $this->error('Failed to sign certificate.');

            return self::FAILURE;
        }

        $p12 = '';

        if (! $openSsl->pkcs12Export($cert, $p12, $key, $password)) {
            $this->error('Failed to export PKCS#12.');

            return self::FAILURE;
        }

        $disk = $config->get('dte.certificate.disk') ?: 'local';

        $storage->disk($disk)->put($path, $p12);

        $this->info("Successfully created fake certificate at disk {$disk}: {$path}");
        $this->info("Password: {$password}");

        return self::SUCCESS;
    }
}
