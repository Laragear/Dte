<?php

namespace Laragear\Dte\Certificate;

use Closure;
use Illuminate\Contracts\Container\Container;
use Laragear\Dte\Contracts\Certifiable;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Rut\Rut;
use RuntimeException;
use UnexpectedValueException;

class CertificateResolver implements CertificateResolverInterface
{
    /**
     * The callback to return a Digital Certificate when found.
     *
     * @var (Closure(Rut):(DigitalCertificate|Certifiable|null))|null
     */
    protected ?Closure $callback = null;

    /**
     * Create a Certificate Resolver instance.
     */
    public function __construct(
        protected Container $container,
    ) {
        //
    }

    /**
     * Set the resolver callback on this instance.
     */
    public function setResolver(?Closure $callback): static
    {
        $this->callback = $callback;

        return $this;
    }

    /**
     * Resolve the digital certificate available for the taxpayer.
     */
    public function resolve(Rut $rut): ?DigitalCertificate
    {
        if ($this->callback === null) {
            throw new RuntimeException('No certificate resolver callback defined.');
        }

        $certificate = $this->container->call($this->callback, [Rut::class => $rut, 'rut' => $rut]);

        if ($certificate === null) {
            return null;
        }

        if ($certificate instanceof Certifiable) {
            $certificate = $certificate->toDigitalCertificate();
        }

        if (!$certificate instanceof DigitalCertificate) {
            throw new UnexpectedValueException(
                'The certificate resolver callback must return a DigitalCertificate or Certifiable.',
            );
        }

        return $certificate;
    }

    /**
     * Register the application callback used to resolve certificates.
     *
     * @param  Closure(Rut $rut):(DigitalCertificate|Certifiable|null)  $callback
     */
    public static function resolveUsing(Closure $callback): void
    {
        app()->afterResolving(CertificateResolverInterface::class, static function (self $resolver) use ($callback) {
            $resolver->setResolver($callback);
        });
    }
}
