<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\DatabaseTestCase;
use Tests\Unit\Caf\Fixtures\CafFixture;

class SiiCafRuleTest extends DatabaseTestCase
{
    /*
     |--------------------------------------------------------------------------
     | Happy paths
     |--------------------------------------------------------------------------
     */

    public function test_it_validates_a_valid_caf()
    {
        Route::post('test-caf', function (Request $request) {
            $request->validate([
                'caf' => 'sii_caf',
            ]);

            return response()->json(['success' => true]);
        });

        $fixture = CafFixture::create();

        $response = $this->postJson('test-caf', [
            'caf' => UploadedFile::fake()->createWithContent('caf.xml', $fixture->xml()),
        ]);

        $response->assertOk();
    }

    public function test_it_validates_a_valid_caf_string()
    {
        Route::post('test-caf', function (Request $request) {
            $request->validate([
                'caf' => 'sii_caf',
            ]);

            return response()->json(['success' => true]);
        });

        $fixture = CafFixture::create();

        $response = $this->postJson('test-caf', [
            'caf' => $fixture->xml(),
        ]);

        $response->assertOk();
    }

    public function test_it_validates_with_literal_rut_parameter()
    {
        $fixture = CafFixture::create();

        Route::post('test-caf-literal-rut', function (Request $request) use ($fixture) {
            $request->validate([
                'caf' => 'sii_caf:'.$fixture->issuer,
            ]);

            return response()->json(['success' => true]);
        });

        $response = $this->postJson('test-caf-literal-rut', [
            'caf' => $fixture->xml(),
        ]);

        $response->assertOk();
    }

    /*
     |--------------------------------------------------------------------------
     | Sad paths
     |--------------------------------------------------------------------------
     */

    public function test_it_fails_validation_with_invalid_mime_type()
    {
        Route::post('test-caf', function (Request $request) {
            $request->validate([
                'caf' => 'sii_caf',
            ]);

            return response()->json(['success' => true]);
        });

        $fixture = CafFixture::create();

        $response = $this->postJson('test-caf', [
            'caf' => UploadedFile::fake()->createWithContent('caf.pdf', $fixture->xml())->mimeType('application/pdf'),
        ]);

        $response->assertJsonValidationErrors([
            'caf' => 'sii::validation.caf',
        ]);
    }

    public function test_it_fails_validation_with_invalid_caf_xml()
    {
        Route::post('test-caf', function (Request $request) {
            $request->validate([
                'caf' => 'sii_caf',
            ]);

            return response()->json(['success' => true]);
        });

        $response = $this->postJson('test-caf', [
            'caf' => '<AUTORIZACION></AUTORIZACION>',
        ]);

        $response->assertJsonValidationErrors([
            'caf' => 'sii::validation.caf',
        ]);
    }

    public function test_it_validates_with_rut_parameter_field()
    {
        Route::post('test-caf-rut', function (Request $request) {
            $request->validate([
                'company_rut' => 'required',
                'caf' => 'sii_caf:company_rut',
            ]);

            return response()->json(['success' => true]);
        });

        $fixture = CafFixture::create();

        $response = $this->postJson('test-caf-rut', [
            'company_rut' => $fixture->issuer,
            'caf' => $fixture->xml(),
        ]);

        $response->assertOk();
    }

    public function test_it_fails_validation_with_wrong_rut()
    {
        Route::post('test-caf-rut', function (Request $request) {
            $request->validate([
                'company_rut' => 'required',
                'caf' => 'sii_caf:company_rut',
            ]);

            return response()->json(['success' => true]);
        });

        $fixture = CafFixture::create();

        $response = $this->postJson('test-caf-rut', [
            'company_rut' => '11.111.111-1',
            'caf' => $fixture->xml(),
        ]);

        $response->assertJsonValidationErrors([
            'caf' => 'sii::validation.caf',
        ]);
    }
}
