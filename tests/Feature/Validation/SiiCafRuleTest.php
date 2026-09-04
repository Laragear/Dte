<?php

namespace Tests\Feature\Validation;

use Illuminate\Http\UploadedFile;
use Laragear\MetaTesting\Validation\InteractsWithValidator;
use Tests\DatabaseTestCase;
use Tests\Unit\Caf\Fixtures\CafFixture;

class SiiCafRuleTest extends DatabaseTestCase
{
    use InteractsWithValidator;

    /*
     |--------------------------------------------------------------------------
     | Happy paths
     |--------------------------------------------------------------------------
     */

    public function test_it_validates_a_valid_caf()
    {
        $this->assertValidationPasses([
            'caf' => UploadedFile::fake()->createWithContent('caf.xml', CafFixture::create()->xml()),
        ], [
            'caf' => 'sii_caf'
        ]);
    }

    public function test_it_validates_a_valid_caf_string()
    {
        $this->assertValidationPasses([
            'caf' => CafFixture::create()->xml(),
        ], [
            'caf' => 'sii_caf'
        ]);
    }

    public function test_it_validates_with_literal_rut_parameter()
    {
        $fixture = CafFixture::create();

        $this->assertValidationPasses([
            'caf' => $fixture->xml(),
        ], [
            'caf' => 'sii_caf:'.$fixture->issuer,
        ]);
    }

    public function test_it_validates_with_rut_parameter_field()
    {
        $fixture = CafFixture::create();

        $this->assertValidationPasses([
            'company_rut' => $fixture->issuer,
            'caf' => $fixture->xml(),
        ], [
            'company_rut' => 'required',
            'caf' => 'sii_caf:company_rut',
        ]);
    }

    public function test_it_validates_when_empty_but_not_when_required(): void
    {
        $this->assertValidationPasses([
            'company_rut' => '76.123.456-0',
            'caf' => '',
        ], [
            'company_rut' => 'required',
            'caf' => 'sii_caf:company_rut',
        ]);

        $this->assertValidationFails([
            'company_rut' => '76.123.456-0',
            'caf' => '',
        ], [
            'company_rut' => 'required',
            'caf' => 'required|sii_caf:company_rut',
        ]);
    }

    /*
     |--------------------------------------------------------------------------
     | Sad paths
     |--------------------------------------------------------------------------
     */

    public function test_it_fails_validation_with_invalid_mime_type()
    {
        $fixture = UploadedFile::fake()->createWithContent('caf.pdf', CafFixture::create()->xml());

        $this->assertValidationFails([
            'caf' => $fixture->mimeType('application/pdf'),
        ], [
            'caf' => 'sii_caf'
        ]);
    }

    public function test_it_fails_validation_with_invalid_caf_xml()
    {
        $this->assertValidationFails([
            'caf' => '<AUTORIZACION></AUTORIZACION>',
        ], [
            'caf' => 'sii_caf'
        ]);
    }

    public function test_it_fails_validation_with_wrong_rut()
    {
        $this->assertValidationFails([
            'company_rut' => '11.111.111-1',
            'caf' => CafFixture::create()->xml(),
        ], [
            'company_rut' => 'required',
            'caf' => 'sii_caf:company_rut',
        ]);
    }

    public function test_fails_with_uploaded_text_file(): void
    {
        $this->assertValidationFails([
            'company_rut' => '76.123.456-0',
            'caf' => UploadedFile::fake()->create('test.txt', 10, 'text/plain'),
        ], [
            'company_rut' => 'required',
            'caf' => 'sii_caf:company_rut',
        ]);
    }
}
