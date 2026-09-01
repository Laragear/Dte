<?php

namespace Tests\Unit\Certification\TestSet\Pipes;

use Laragear\Dte\Certification\TestingSet\Pipes\SendTestingIecv;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use Laragear\Dte\Certification\TestingSet\TestSetSalesBook;
use Laragear\Dte\Gateways\IecvUploadGateway;
use Laragear\Dte\SiiEndpoints;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Tests\TestCase;

class SendTestingIecvTest extends TestCase
{
    use InteractsWithPipelines;

    public function test_sends_iecv_and_returns_data(): void
    {
        $gateway = $this->mock(IecvUploadGateway::class);
        $gateway->expects('upload')
            ->withArgs(function (Rut $issuer, Rut $sender, string $xml, string $url) {
                return $issuer->formatBasic() === '76123456-0'
                    && $sender->formatBasic() === '76123456-0'
                    && $xml === '<xml></xml>'
                    && $url === SiiEndpoints::SOAP_CERTIFICATION;
            })
            ->andReturn('123456');

        $data = new TestSetData(Rut::parse('76.123.456-0'));
        $data->iecvXml = '<xml></xml>';

        $this->pipeline(TestSetSalesBook::class)
            ->isolatePipe(SendTestingIecv::class)
            ->send($data)
            ->assertPassable(function (TestSetData $result) use ($data) {
                return $result === $data;
            });
    }
}
