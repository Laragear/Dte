<?php

namespace Tests\Unit\Certification\Interchange\Pipes;

use Illuminate\Filesystem\Filesystem;
use Laragear\Dte\Certification\Interchange\Interchange;
use Laragear\Dte\Certification\Interchange\InterchangeData;
use Laragear\Dte\Certification\Interchange\Pipes\FetchInterchangeXml;
use Laragear\Dte\Contracts\MailboxDriverInterface;
use Laragear\Dte\Data\InboundEmailData;
use Laragear\Dte\Mailbox\MailboxManager;
use Laragear\MetaTesting\Pipeline\InteractsWithPipelines;
use Laragear\Rut\Rut;
use Mockery\MockInterface;
use RuntimeException;
use Tests\DatabaseTestCase;

class FetchInterchangeXmlTest extends DatabaseTestCase
{
    use InteractsWithPipelines;

    public function test_fetches_from_mailbox(): void
    {
        $this->mock(MailboxManager::class, function (MockInterface $mock) {
            $driver = $this->mock(MailboxDriverInterface::class);

            $email1 = new InboundEmailData(
                messageId: 'ignore',
                sender: 'someone@example.com',
                subject: 'Ignore',
                xmlAttachment: '',
            );

            $email2 = new InboundEmailData(
                messageId: 'found',
                sender: 'sii_dte_intercambio@sii.cl',
                subject: 'Intercambio',
                xmlAttachment: '<xml></xml>',
            );

            $driver->expects('unread')->once()->andReturn(collect([$email1, $email2]));
            $driver->expects('markAsRead')->once()->with($email2);

            $mock->allows('driver')->andReturn($driver);
        });

        $this->pipeline(Interchange::class)
            ->isolatePipe(FetchInterchangeXml::class)
            ->send(new InterchangeData(new Rut(76_123_456, 0), source: 'mailbox'))
            ->assertPassable(function (InterchangeData $data) {
                static::assertSame('found', $data->emailData->messageId);
                static::assertSame('<xml></xml>', $data->emailData->xmlAttachment);

                return true;
            });
    }

    public function test_fetches_from_file_path(): void
    {
        $this->mock(Filesystem::class, function (MockInterface $mock) {
            $mock->expects('exists')->andReturnTrue();
            $mock->expects('get')->andReturn('<xml></xml>');
        });

        $this->pipeline(Interchange::class)
            ->isolatePipe(FetchInterchangeXml::class)
            ->send(new InterchangeData(new Rut(76_123_456, 0), source: 'file', filePath: '/dummy.xml'))
            ->assertPassable(function (InterchangeData $data) {
                static::assertStringContainsString('manual-file-', $data->emailData->messageId);
                static::assertSame('sii_dte_intercambio@sii.cl', $data->emailData->sender);
                static::assertSame('<xml></xml>', $data->emailData->xmlAttachment);

                return true;
            });
    }

    public function test_fetches_from_xml_content(): void
    {
        $this->pipeline(Interchange::class)
            ->isolatePipe(FetchInterchangeXml::class)
            ->send(new InterchangeData(new Rut(76_123_456, 0), source: 'file', xmlContent: '<raw></raw>'))
            ->assertPassable(function (InterchangeData $data) {
                static::assertStringContainsString('manual-file-', $data->emailData->messageId);
                static::assertSame('sii_dte_intercambio@sii.cl', $data->emailData->sender);
                static::assertSame('<raw></raw>', $data->emailData->xmlAttachment);

                return true;
            });
    }

    public function test_fails_when_file_does_not_exist(): void
    {
        $this->mock(Filesystem::class, function (MockInterface $mock) {
            $mock->allows('exists')->andReturnFalse();
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The provided interchange XML file does not exist.');

        $this->pipeline(Interchange::class)
            ->isolatePipe(FetchInterchangeXml::class)
            ->send(new InterchangeData(new Rut(76_123_456, 0), source: 'file', filePath: '/dummy.xml'));
    }

    public function test_fails_when_no_unread_interchange_emails_found(): void
    {
        $this->mock(MailboxManager::class, function (MockInterface $mock) {
            $driver = $this->mock(MailboxDriverInterface::class);

            $driver->expects('unread')->once()->andReturn(collect());

            $mock->expects('driver')->andReturn($driver);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Failed to fetch interchange email from SII.');

        $this->pipeline(Interchange::class)
            ->isolatePipe(FetchInterchangeXml::class)
            ->send(new InterchangeData(new Rut(76_123_456, 0), source: 'mailbox'));
    }
}
