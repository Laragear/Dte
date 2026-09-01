<?php

namespace Laragear\Dte;

use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Contracts\Config\Repository as ConfigRepositoryContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\ServiceProvider;
use Laragear\Dte\Certificate\CertificateResolver;
use Laragear\Dte\Certification\CertificationManager;
use Laragear\Dte\Configuration\ConfigurationManager;
use Laragear\Dte\Console\Commands\CheckNearDepletedCafsCommand;
use Laragear\Dte\Console\Commands\CompileDteXmlCommand;
use Laragear\Dte\Console\Commands\FetchInboundMailboxCommand;
use Laragear\Dte\Console\Commands\MakeFakeCafCommand;
use Laragear\Dte\Console\Commands\MakeFakeCertificateCommand;
use Laragear\Dte\Console\Commands\PackReadyDtesCommand;
use Laragear\Dte\Console\Commands\PollTrackStatusCommand;
use Laragear\Dte\Console\Commands\ProcessAndSendEnvelopeCommand;
use Laragear\Dte\Console\Commands\RejectExpiringPhantomInvoicesCommand;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Dte\Contracts\TokenProviderInterface;
use Laragear\Dte\Enums\DteEnvironment;
use Laragear\Dte\Environment\EnvironmentResolver;
use Laragear\Dte\Events\InboundDteAcknowledged;
use Laragear\Dte\Gateways\SoapGateway;
use Laragear\Dte\Listeners\SendCommercialReceiptListener;
use Laragear\Dte\Mailbox\MailboxManager;
use Laragear\Dte\Pdf\Pdf417Generator;
use Le\PDF417\PDF417;
use Le\PDF417\Renderer\ImageRenderer;

class DteServiceProvider extends ServiceProvider
{
    public const string CONFIG = __DIR__.'/../config/dte.php';

    public const string MIGRATIONS = __DIR__.'/../database/migrations';

    public const string VIEWS = __DIR__.'/../resources/views';

    /**
     * Rules to register into the validator.
     *
     * @var array<string[]>
     */
    public const array RULES = [
        ['sii_certificate', 'validateSiiCertificate', 'sii::validation.certificate'],
        ['sii_caf', 'validateSiiCaf', 'sii::validation.caf'],
    ];

    /**
     * Register bindings in the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(static::CONFIG, 'dte');

        $this->loadViewsFrom(static::VIEWS, 'dte');

        $this->app->scoped(EnvironmentResolver::class);
        $this->app->singleton(ConfigurationManager::class);
        $this->app->singleton(CertificateResolver::class);
        $this->app->scoped(CertificationManager::class);
        $this->app->bind(CertificateResolverInterface::class, CertificateResolver::class);
        $this->app->bind(TokenProviderInterface::class, SoapGateway::class);
        $this->app
            ->when(SoapGateway::class)
            ->needs(CacheRepositoryContract::class)
            ->give(function (Container $app) {
                return $app->make('cache')->store($app->make('config')->get('dte.cache.store'));
            });
        $this->app->singleton(MailboxManager::class);

        // Avoid clashing with other generator instances.
        $this->app
            ->when(Pdf417Generator::class)
            ->needs(PDF417::class)
            ->give(static function (): PDF417 {
                $encoder = new PDF417;
                $encoder->setSecurityLevel(5);
                $encoder->setForceBinary(true);

                return $encoder;
            });

        // Avoid clashing with other generator instances.
        $this->app
            ->when(Pdf417Generator::class)
            ->needs(ImageRenderer::class)
            ->give(static function (): ImageRenderer {
                return new ImageRenderer([
                    'format' => 'png',
                    'scale' => 3,
                    'ratio' => 3,
                    'padding' => 20,
                ]);
            });
    }

    /**
     * Boot the service provider.
     */
    public function boot(ConfigRepositoryContract $config, Dispatcher $event, EnvironmentResolver $environment): void
    {
        $this->callAfterResolving('validator', static function (Factory $validator, Container $app): void {
            $translator = $app->make('translator');

            foreach (static::RULES as [$rule, $extension, $key]) {
                $validator->extend(
                    $rule,
                    ValidatesSiiDocuments::$extension(...),
                    $translator->get($key),
                );
            }
        });

        $this->publishes([static::CONFIG => $this->app->configPath('dte.php')], 'config');

        $this->publishesMigrations([
            static::MIGRATIONS => $this->app->databasePath('/migrations'),
        ], 'migrations');

        // Allow all these commands to be fireable inside the application programmatically.
        // For example, the user can call one of these Artisan commands from the frontend
        // or run them through a webhook. This avoids binding these exclusively to CLI.
        $this->commands([
            CheckNearDepletedCafsCommand::class,
            FetchInboundMailboxCommand::class,
            RejectExpiringPhantomInvoicesCommand::class,
            PollTrackStatusCommand::class,
            CompileDteXmlCommand::class,
            ProcessAndSendEnvelopeCommand::class,
            PackReadyDtesCommand::class,
        ]);

        if ($config->get('dte.dim.auto_email_receipts', false)) {
            $event->listen(
                InboundDteAcknowledged::class,
                SendCommercialReceiptListener::class,
            );
        }

        /** Validate the operational environment before serving the library. */
        if ($this->shouldRegisterFakeCommands($environment)) {
            $this->commands([
                MakeFakeCertificateCommand::class,
                MakeFakeCafCommand::class,
            ]);
        }
    }

    /**
     * Check if the environment is not production or certification to make fake certificates/CAF.
     */
    protected function shouldRegisterFakeCommands(EnvironmentResolver $environment): bool
    {
        if ($this->app->runningInConsole()) {
            $resolved = $environment->resolve();

            return $resolved !== DteEnvironment::Production;
        }

        return false;
    }
}
