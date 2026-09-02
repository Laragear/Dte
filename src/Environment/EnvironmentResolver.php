<?php

namespace Laragear\Dte\Environment;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Laragear\Dte\Enums\DteEnvironment as DteEnv;
use LogicException;
use function is_string;

final class EnvironmentResolver
{

    /**
     * The resolved DTE environment.
     */
    protected DteEnv $resolved;

    /**
     * Create an EnvironmentResolver instance.
     */
    public function __construct(
        protected Repository $config,
        protected Application $app,
    ) {
        //
    }

    /**
     * Resolve and validate the configured DTE environment.
     */
    public function resolve(): DteEnv
    {
        return $this->resolved ??= $this->getCurrentEnvironment();
    }

    /**
     * Resolves the current environment.
     */
    protected function getCurrentEnvironment(): DteEnv
    {
        $dteEnvironment = $this->parse($this->config->get('dte.environment'))
            ?? $this->parse($this->app->environment())
            ?? DteEnv::DEFAULT;

        if ($this->isEnvironmentMismatch($dteEnvironment)) {
            throw new LogicException('APP_ENV and DTE_ENV must both be production or both be non-production.');
        }

        return $dteEnvironment;
    }

    /**
     * Determine if the application environment and DTE environment mismatch regarding production status.
     *
     * Checks whether one is in production while the other is not.
     */
    protected function isEnvironmentMismatch(DteEnv $dteEnvironment): bool
    {
        // If the library environment is production, then the app should also be production
        return $this->app->environment(DteEnv::Production->value) !== ($dteEnvironment === DteEnv::Production);
    }

    /**
     * Parse a supported environment value.
     */
    public function flush(): void
    {
        unset($this->resolved);
    }

    /**
     * Parse a supported environment value.
     */
    protected function parse(mixed $environment): ?DteEnv
    {
        return is_string($environment) ? DteEnv::tryFrom($environment) : null;
    }
}
