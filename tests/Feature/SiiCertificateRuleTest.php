<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Laragear\Dte\Support\OpenSslProxy;
use Laragear\Dte\ValidatesSiiDocuments;
use Mockery\MockInterface;
use Tests\DatabaseTestCase;
use Tests\Unit\Certificate\Fixtures\CertificateFixture;

use function time;

class SiiCertificateRuleTest extends DatabaseTestCase
{
    /*
     |--------------------------------------------------------------------------
     | Happy paths
     |--------------------------------------------------------------------------
     */

    public function test_it_validates_a_valid_certificate()
    {
        Route::post('test-cert', function (Request $request) {
            $request->validate([
                'password' => 'required',
                'cert' => 'sii_certificate',
            ]);

            return response()->json(['success' => true]);
        });

        $fixture = CertificateFixture::create();

        $response = $this->postJson('test-cert', [
            'password' => $fixture->password,
            'cert' => UploadedFile::fake()->createWithContent('cert.p12', file_get_contents($fixture->path)),
        ]);

        $response->assertOk();

        $fixture->delete();
    }

    public function test_it_validates_with_custom_password_field()
    {
        Route::post('test-cert-custom', function (Request $request) {
            $request->validate([
                'cert_pass' => 'required',
                'cert' => 'sii_certificate:cert_pass',
            ]);

            return response()->json(['success' => true]);
        });

        $fixture = CertificateFixture::create();

        $response = $this->postJson('test-cert-custom', [
            'cert_pass' => $fixture->password,
            'cert' => UploadedFile::fake()->createWithContent('cert.p12', file_get_contents($fixture->path)),
        ]);

        $response->assertOk();

        $fixture->delete();
    }

    /*
     |--------------------------------------------------------------------------
     | Sad paths
     |--------------------------------------------------------------------------
     */

    public function test_it_fails_validation_with_missing_password()
    {
        Route::post('test-cert', function (Request $request) {
            $request->validate([
                'cert' => 'sii_certificate',
            ]);

            return response()->json(['success' => true]);
        });

        $response = $this->postJson('test-cert', [
            'cert' => 'binary-string-cert',
        ]);

        $response->assertJsonValidationErrors([
            'cert' => 'sii::validation.certificate',
        ]);
    }

    public function test_it_fails_validation_with_wrong_password_or_corrupt_file()
    {
        Route::post('test-cert', function (Request $request) {
            $request->validate([
                'password' => 'required',
                'cert' => 'sii_certificate',
            ]);

            return response()->json(['success' => true]);
        });

        $fixture = CertificateFixture::create();

        $response = $this->postJson('test-cert', [
            'password' => 'wrong',
            'cert' => UploadedFile::fake()->createWithContent('cert.p12', file_get_contents($fixture->path)),
        ]);

        $response->assertJsonValidationErrors([
            'cert' => 'sii::validation.certificate',
        ]);

        $fixture->delete();
    }

    public function test_it_fails_validation_when_certificate_is_expired()
    {
        // Mock OpenSslProxy to return an expired certificate metadata
        $this->mock(OpenSslProxy::class, function (MockInterface $mock) {
            $mock->shouldReceive('readPkcs12String')->andReturn(['cert' => 'fake-cert', 'pkey' => 'fake-key']);
            $mock->shouldReceive('parseX509')->andReturn([
                'validFrom_time_t' => time() - 10000,
                'validTo_time_t' => time() - 5000,
            ]);

            return response()->json(['success' => true]);
        });

        Route::post('test-cert', function (Request $request) {
            $request->validate([
                'password' => 'required',
                'cert' => 'sii_certificate',
            ]);

            return response()->json(['success' => true]);
        });

        $response = $this->postJson('test-cert', [
            'password' => 'secret',
            'cert' => 'binary-string-cert',
        ]);

        $response->assertJsonValidationErrors([
            'cert' => 'sii::validation.certificate',
        ]);
    }

    public function test_missing_coverage(): void
    {
        $validator = $this->app['validator']->make(['cert' => '', 'password' => 'secret'], []);
        $file = UploadedFile::fake()->create('test.txt', 10, 'text/plain');
        static::assertFalse(ValidatesSiiDocuments::validateSiiCertificate('cert', $file, ['password'], $validator));
        static::assertFalse(ValidatesSiiDocuments::validateSiiCertificate('cert', '', ['password'], $validator));

        $proxy = \Mockery::mock(OpenSslProxy::class);
        $proxy->expects('readPkcs12String')->andReturn(['pkey' => 'exists']);
        $this->app->instance(OpenSslProxy::class, $proxy);
        static::assertFalse(ValidatesSiiDocuments::validateSiiCertificate('cert', 'valid', ['password'], $validator));
    }
}
