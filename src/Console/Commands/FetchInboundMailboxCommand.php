<?php

namespace Laragear\Dte\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Laragear\Dte\Mailbox\MailboxManager;
use Laragear\Dte\Services\InboundDteProcessor;
use Psr\Log\LoggerInterface;
use Throwable;

class FetchInboundMailboxCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dte:fetch-mailbox {--driver= : The mailbox driver to use (defaults to config)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll configured DTE mailbox for UNREAD messages and process them';

    /**
     * Execute the console command.
     */
    public function handle(
        LoggerInterface $logger,
        MailboxManager $mailbox,
        InboundDteProcessor $processor,
        Repository $config
    ): int {
        $driverName = $this->option('driver');
        $driver = $driverName ? $mailbox->driver($driverName) : $mailbox->driver();

        $disallowedPrefixes = array_filter(explode(',', (string) $config->get('dte.dim.disallowed_prefixes', '')));
        $disallowedDomains = array_filter(explode(',', (string) $config->get('dte.dim.disallowed_domains', '')));

        $processed = 0;
        $failed = 0;

        foreach ($driver->unread() as $email) {
            try {
                $sender = strtolower($email->sender);
                $parts = explode('@', $sender);
                $prefix = $parts[0] ?? '';
                $domain = $parts[1] ?? '';

                if (in_array($prefix, $disallowedPrefixes, true) || in_array($domain, $disallowedDomains, true)) {
                    $driver->markAsRead($email);

                    continue;
                }

                // The processor already uses transactions for database-level
                $processor->process($email);
                // Only mark as read if the entire transaction succeeds.
                $driver->markAsRead($email);

                $processed++;
            } catch (Throwable $e) {
                $failed++;

                $logger->error('Failed to process inbound DTE email.', [
                    'message_id' => $email->messageId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTrace(),
                ]);
            }
        }

        $this->info("Mailbox fetch complete. Processed: $processed. Failed: $failed.");

        return self::SUCCESS;
    }
}
