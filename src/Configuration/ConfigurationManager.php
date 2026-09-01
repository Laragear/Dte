<?php

namespace Laragear\Dte\Configuration;

use Closure;
use Illuminate\Contracts\Container\Container;
use Laragear\Dte\Data\IssuerData;
use Laragear\Rut\Rut;
use RuntimeException;

class ConfigurationManager
{
    /**
     * The callback to return the global Issuer Data when found.
     *
     * @var (Closure():(IssuerData|null))|null
     */
    protected ?Closure $issuerResolver = null;

    /**
     * The callback to return the global Sender RUT when found.
     *
     * @var (Closure(Rut):(?Rut))|null
     */
    protected ?Closure $senderResolver = null;

    /**
     * Create a new Configuration Manager instance.
     */
    public function __construct(
        protected Container $container,
    ) {
        //
    }

    /**
     * Register the application callback used to resolve the global issuer.
     *
     * @param  Closure(?Rut):(IssuerData|null)|null  $callback
     */
    public static function resolveIssuerUsing(?Closure $callback): void
    {
        app()->afterResolving(self::class, static function (self $manager) use ($callback) {
            $manager->setIssuerResolver($callback);
        });
    }

    /**
     * Register the application callback used to resolve the global sender RUT.
     *
     * @param  Closure(Rut):(?Rut)|null  $callback
     */
    public static function resolveSenderUsing(?Closure $callback): void
    {
        app()->afterResolving(self::class, static function (self $manager) use ($callback) {
            $manager->setSenderResolver($callback);
        });
    }

    /**
     * Register a single company configuration for single-tenant applications.
     */
    public static function setCompany(Closure $callback): void
    {
        app()->afterResolving(self::class, static function (self $manager) use ($callback) {
            $manager->setIssuerResolver(static fn() => app()->call($callback)?->issuer);
            $manager->setSenderResolver(static fn() => app()->call($callback)?->senderRut);
        });
    }

    /**
     * Set the issuer resolver on this instance.
     */
    public function setIssuerResolver(?Closure $callback): static
    {
        $this->issuerResolver = $callback;

        return $this;
    }

    /**
     * Set the sender resolver on this instance.
     */
    public function setSenderResolver(?Closure $callback): static
    {
        $this->senderResolver = $callback;

        return $this;
    }

    /**
     * Resolve the issuer data from the registered application callback.
     */
    public function hasIssuerResolver(): bool
    {
        return $this->issuerResolver !== null;
    }

    /**
     * Check if the sender resolver was set.
     */
    public function hasSenderResolver(): bool
    {
        return $this->senderResolver !== null;
    }

    /**
     * Returns the issuer data from the resolver.
     */
    public function getIssuer(?Rut $rut = null): IssuerData
    {
        if ($this->issuerResolver === null) {
            throw new RuntimeException('No Issuer resolver has been registered.');
        }

        return $this->container->call($this->issuerResolver, ['rut' => $rut])
            ?? throw new RuntimeException('The registered Issuer resolver returned null.');
    }

    /**
     * Resolve the sender RUT from the registered application callback.
     */
    public function getSender(Rut $issuerRut): Rut
    {
        if ($this->senderResolver === null) {
            throw new RuntimeException('No Sender resolver has been registered.');
        }

        $sender = $this->container->call($this->senderResolver, ['issuer' => $issuerRut]);

        return $sender
            ? Rut::parse($sender)
            : throw new RuntimeException('The registered Sender resolver returned null.');
    }
}
