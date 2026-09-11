<?php

namespace Tests\Unit\Validation;

use Illuminate\Http\UploadedFile;
use Laragear\Dte\Validation\ValidatesSiiDocuments;
use Tests\TestCase;
use Tests\Unit\Caf\Fixtures\CafFixture;

class ValidatesSiiDocumentsTest extends TestCase
{
    /*
     |---------- | validateCertificate — unhappy paths | ---------- |
     */

    public function test_validate_certificate_returns_false_when_password_is_null(): void
    {
        static::assertFalse(ValidatesSiiDocuments::validateCertificate(null, 'binary'));
    }

    public function test_validate_certificate_returns_false_when_password_is_empty(): void
    {
        static::assertFalse(ValidatesSiiDocuments::validateCertificate('', 'binary'));
    }

    public function test_validate_certificate_returns_false_when_uploaded_file_mime_type_is_invalid(): void
    {
        // Line 61: UploadedFile with MIME type NOT in CERT_MIME_TYPES → return false
        $mock = \Mockery::mock(UploadedFile::class);
        $mock->shouldReceive('getMimeType')->once()->andReturn('text/plain');

        static::assertFalse(ValidatesSiiDocuments::validateCertificate('password', $mock));
    }

    public function test_validate_certificate_returns_false_when_openssl_throws(): void
    {
        static::assertFalse(ValidatesSiiDocuments::validateCertificate('password', 'invalid binary'));
    }

    /*
     |---------- | validateSiiCaf — unhappy paths | ---------- |
     */

    public function test_validate_sii_caf_returns_false_when_caf_parse_throws(): void
    {
        $validator = \Mockery::mock(\Illuminate\Validation\Validator::class);
        // getData is not called when parsing fails

        static::assertFalse(
            ValidatesSiiDocuments::validateSiiCaf('caf', 'not xml at all', [], $validator),
        );
    }

    public function test_validate_sii_caf_returns_false_when_rut_parse_throws(): void
    {
        // Lines 122-123: Rut::parse('not-a-valid-rut') throws → return false
        $fixture = CafFixture::create();
        $validCaf = $fixture->xml(1, 100);

        $validator = \Mockery::mock(\Illuminate\Validation\Validator::class);
        $validator->shouldReceive('getData')->once()->andReturn([]);

        static::assertFalse(
            ValidatesSiiDocuments::validateSiiCaf('caf', $validCaf, ['not-a-valid-rut'], $validator),
        );
    }

    public function test_validate_sii_caf_returns_false_when_issuer_rut_does_not_match(): void
    {
        $fixture = CafFixture::create();
        $validCaf = $fixture->xml(1, 100);

        // issuer_rut is the fixture's RUT, pass a different one
        $validator = \Mockery::mock(\Illuminate\Validation\Validator::class);
        $validator->shouldReceive('getData')->once()->andReturn(['rut' => '99999999-9']);

        static::assertFalse(
            ValidatesSiiDocuments::validateSiiCaf('caf', $validCaf, ['rut'], $validator),
        );
    }

    public function test_validate_sii_caf_returns_false_when_uploaded_file_has_invalid_mime_type(): void
    {
        $mock = \Mockery::mock(UploadedFile::class);

        $validator = \Mockery::mock(\Illuminate\Validation\Validator::class);
        $validator->shouldReceive('validateMimetypes')->once()->andReturn(false);

        static::assertFalse(
            ValidatesSiiDocuments::validateSiiCaf('caf', $mock, [], $validator),
        );
    }

    /*
     |---------- | validateSiiCaf — happy paths | ---------- |
     */

    public function test_validate_sii_caf_returns_true_for_valid_caf_without_rut_check(): void
    {
        $fixture = CafFixture::create();
        $validCaf = $fixture->xml(1, 100);

        $validator = \Mockery::mock(\Illuminate\Validation\Validator::class);

        static::assertTrue(
            ValidatesSiiDocuments::validateSiiCaf('caf', $validCaf, [], $validator),
        );
    }

    public function test_validate_sii_caf_returns_true_when_issuer_rut_matches(): void
    {
        $fixture = CafFixture::create();
        $validCaf = $fixture->xml(1, 100);

        $validator = \Mockery::mock(\Illuminate\Validation\Validator::class);
        $validator->shouldReceive('getData')->once()->andReturn(['rut' => $fixture->issuer]);

        static::assertTrue(
            ValidatesSiiDocuments::validateSiiCaf('caf', $validCaf, ['rut'], $validator),
        );
    }
}
