<?php

namespace Tests\Unit\Certificate;

use Laragear\Dte\Certificate\CertificateResolver;
use Laragear\Dte\Certificate\DigitalCertificate;
use Laragear\Dte\Contracts\Certifiable;
use Laragear\Dte\Contracts\CertificateResolverInterface;
use Laragear\Rut\Rut;
use Mockery;
use RuntimeException;
use Tests\DatabaseTestCase;
use UnexpectedValueException;

class CertificateResolverTest extends DatabaseTestCase
{
    public function test_resolves_a_certificate_using_callback()
    {
        $rut = Rut::parse('76.123.456-0');
        $expected = new DigitalCertificate('binary', 'secret');

        CertificateResolver::resolveUsing(function (Rut $r) use ($rut, $expected) {
            static::assertSame($rut->formatBasic(), $r->formatBasic());

            return $expected;
        });

        $resolver = $this->app->make(CertificateResolverInterface::class);

        static::assertSame($expected, $resolver->resolve($rut));
    }

    public function test_resolves_a_certifiable_object()
    {
        $rut = Rut::parse('76.123.456-0');
        $expected = new DigitalCertificate('binary', 'secret');

        $certifiable = Mockery::mock(Certifiable::class);
        $certifiable->expects('toDigitalCertificate')->andReturn($expected);

        CertificateResolver::resolveUsing(function () use ($certifiable) {
            return $certifiable;
        });

        $resolver = $this->app->make(CertificateResolverInterface::class);

        static::assertSame($expected, $resolver->resolve($rut));
    }

    public function test_returns_null_when_callback_returns_null()
    {
        $rut = Rut::parse('76.123.456-0');

        CertificateResolver::resolveUsing(function () {
            return null;
        });

        $resolver = $this->app->make(CertificateResolverInterface::class);

        static::assertNull($resolver->resolve($rut));
    }

    public function test_throws_when_callback_returns_invalid_type()
    {
        $rut = Rut::parse('76.123.456-0');

        CertificateResolver::resolveUsing(function () {
            return 'string';
        });

        $resolver = $this->app->make(CertificateResolverInterface::class);

        $this->expectException(UnexpectedValueException::class);
        $resolver->resolve($rut);
    }

    public function test_throws_when_no_callback_registered()
    {
        $rut = Rut::parse('76.123.456-0');

        $resolver = $this->app->make(CertificateResolverInterface::class);

        $this->expectException(RuntimeException::class);
        $resolver->resolve($rut);
    }
}
