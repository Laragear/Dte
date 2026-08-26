<?php

namespace Tests\Unit\Mailbox;

use Laragear\Dte\Contracts\MailboxDriverInterface;
use Laragear\Dte\Mailbox\Drivers\AwsSesDriver;
use Laragear\Dte\Mailbox\Drivers\GoogleWorkspaceDriver;
use Laragear\Dte\Mailbox\Drivers\ImapDriver;
use Laragear\Dte\Mailbox\Drivers\Microsoft365Driver;
use Laragear\Dte\Mailbox\MailboxManager;
use Mockery;
use Tests\TestCase;

class MailboxManagerTest extends TestCase
{
    protected function makeManager(string $default = 'imap'): MailboxManager
    {
        $this->app->make('config')->set('dte.mailbox.default', $default);

        return $this->app->make(MailboxManager::class);
    }

    public function test_uses_the_configured_default_driver(): void
    {
        $manager = $this->makeManager('imap');

        static::assertSame('imap', $manager->getDefaultDriver());
    }

    public function test_resolves_the_mailbox_driver_interface(): void
    {
        $this->mock(ImapDriver::class);

        $driver = $this->makeManager('imap')->driver();

        static::assertInstanceOf(MailboxDriverInterface::class, $driver);
    }

    public function test_can_resolve_different_drivers(): void
    {
        foreach (['imap', 'google', 'microsoft', 'aws_ses'] as $driverName) {
            $driverClass = match ($driverName) {
                'imap' => ImapDriver::class,
                'google' => GoogleWorkspaceDriver::class,
                'microsoft' => Microsoft365Driver::class,
                'aws_ses' => AwsSesDriver::class,
            };

            $this->mock($driverClass);

            $manager = $this->makeManager($driverName);
            $driver = $manager->driver($driverName);

            static::assertInstanceOf(
                MailboxDriverInterface::class,
                $driver,
                "Driver {$driverName} must implement MailboxDriverInterface",
            );
        }
    }

    public function test_can_extend_with_custom_driver(): void
    {
        $customDriver = Mockery::mock(MailboxDriverInterface::class);

        $manager = $this->makeManager('custom');

        $manager->extend('custom', fn() => $customDriver);

        static::assertSame($customDriver, $manager->driver('custom'));
    }
}
