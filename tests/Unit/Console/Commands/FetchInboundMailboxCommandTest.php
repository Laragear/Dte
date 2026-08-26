<?php

namespace Tests\Unit\Console\Commands;

use Exception;
use Laragear\Dte\Contracts\MailboxDriverInterface;
use Laragear\Dte\Data\InboundEmailData;
use Laragear\Dte\Mailbox\MailboxManager;
use Laragear\Dte\Services\InboundDteProcessor;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class FetchInboundMailboxCommandTest extends TestCase
{
    /*
     |--------------------------------------------------------------------------
     | Happy paths
     |--------------------------------------------------------------------------
     */

    public function test_fetches_and_processes_unread_emails_and_marks_them_as_read(): void
    {
        $email1 = new InboundEmailData('msg-1', 'sender@test.cl', 'Subject 1', '<xml></xml>');
        $email2 = new InboundEmailData('msg-2', 'sender@test.cl', 'Subject 2', '<xml></xml>');

        $driver = $this->mock(
            MailboxDriverInterface::class,
            static function (MockInterface $mock) use ($email1, $email2): void {
                $mock->expects('unread')->andReturn([$email1, $email2]);
                $mock->expects('markAsRead')->with($email1);
                $mock->expects('markAsRead')->with($email2);
            },
        );

        $this->mock(MailboxManager::class)->expects('driver')->andReturn($driver);

        $this->mock(
            InboundDteProcessor::class,
            static function (MockInterface $mock) use ($email1, $email2): void {
                $mock->expects('process')->with($email1);
                $mock->expects('process')->with($email2);
            },
        );

        $this
            ->artisan('dte:fetch-mailbox')
            ->expectsOutput('Mailbox fetch complete. Processed: 2. Failed: 0.')
            ->assertSuccessful();
    }

    public function test_skips_and_marks_as_read_emails_from_disallowed_prefixes_and_domains(): void
    {
        $this->app->make('config')->set('dte.dim.disallowed_prefixes', 'admin,info,soporte');
        $this->app->make('config')->set('dte.dim.disallowed_domains', 'gmail.com,outlook.com');

        $allowed = new InboundEmailData('msg-1', 'factura@empresa.cl', 'Subject 1', '<xml></xml>');
        $disallowedPrefix = new InboundEmailData('msg-2', 'soporte@empresa.cl', 'Subject 2', '<xml></xml>');
        $disallowedDomain = new InboundEmailData('msg-3', 'info@gmail.com', 'Subject 3', '<xml></xml>');

        $driver = $this->mock(
            MailboxDriverInterface::class,
            static function (MockInterface $mock) use ($allowed, $disallowedPrefix, $disallowedDomain): void {
                $mock->expects('unread')->andReturn([$allowed, $disallowedPrefix, $disallowedDomain]);
                $mock->expects('markAsRead')->with($allowed);
                $mock->expects('markAsRead')->with($disallowedPrefix);
                $mock->expects('markAsRead')->with($disallowedDomain);
            },
        );

        $this->mock(MailboxManager::class)->expects('driver')->andReturn($driver);

        $this->mock(
            InboundDteProcessor::class,
            static function (MockInterface $mock) use ($allowed): void {
                $mock->expects('process')->with($allowed)->once();
            },
        );

        $this
            ->artisan('dte:fetch-mailbox')
            ->expectsOutput('Mailbox fetch complete. Processed: 1. Failed: 0.')
            ->assertSuccessful();
    }

    /*
     |--------------------------------------------------------------------------
     | Angry paths
     |--------------------------------------------------------------------------
     */

    public function test_does_not_mark_as_read_if_processing_fails(): void
    {
        $email1 = new InboundEmailData('msg-1', 'sender@test.cl', 'Subject 1', '<xml></xml>');
        $email2 = new InboundEmailData('msg-2', 'sender@test.cl', 'Subject 2', '<xml></xml>');

        $this
            ->mock(LoggerInterface::class)
            ->expects('error')
            ->withArgs(static function (string $message, array $context): bool {
                static::assertSame('Failed to process inbound DTE email.', $message);
                static::assertSame('msg-1', $context['message_id']);
                static::assertSame('Parse Error', $context['error']);
                static::assertIsArray($context['trace']);

                return true;
            });

        $driver = $this->mock(
            MailboxDriverInterface::class,
            static function (MockInterface $mock) use ($email1, $email2): void {
                $mock->expects('unread')->andReturn([$email1, $email2]);
                $mock->expects('markAsRead')->with($email1)->never();
                $mock->expects('markAsRead')->with($email2)->once();
            },
        );

        $this->mock(MailboxManager::class)->expects('driver')->andReturn($driver);

        $this->mock(
            InboundDteProcessor::class,
            static function (MockInterface $mock) use ($email1, $email2): void {
                $mock->expects('process')->with($email1)->andThrow(new Exception('Parse Error'));
                $mock->expects('process')->with($email2)->once();
            },
        );

        $this
            ->artisan('dte:fetch-mailbox')
            ->expectsOutput('Mailbox fetch complete. Processed: 1. Failed: 1.')
            ->assertSuccessful();
    }
}
