<?php

namespace Laragear\Dte\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\DateFactory;
use Laragear\Dte\Enums\InboundDteStatus;
use Laragear\Dte\Gateways\ReclamoWebserviceGateway;
use Laragear\Dte\Models\SiiInboundDocument;
use Psr\Log\LoggerInterface;
use Throwable;

class RejectExpiringPhantomInvoicesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dte:reject-phantom-invoices {--days=6 : Days threshold before rejecting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reject PhantomPending invoices nearing the automatic acceptance deadline';

    /**
     * Execute the console command.
     */
    public function handle(LoggerInterface $log, DateFactory $date, ReclamoWebserviceGateway $gateway): int
    {
        $days = (int) $this->option('days');
        $threshold = $date->now()->subDays($days);

        /** @var Collection<int, SiiInboundDocument> $documents */
        $documents = SiiInboundDocument::query()
            ->where('status', InboundDteStatus::PhantomPending)
            ->where('created_at', '<=', $threshold)
            ->get();

        if ($documents->isEmpty()) {
            $this->info('No expiring phantom invoices found.');

            return self::SUCCESS;
        }

        $rejected = 0;
        $failed = 0;

        foreach ($documents as $document) {
            try {
                // Reject via SII WS (Reclamo al Contenido - RCD)
                $gateway->reject($document, 'Rechazo automático de factura fantasma (Sin recepción).');

                $document->status = InboundDteStatus::CommercialRejected;
                $document->save();

                $rejected++;
            } catch (Throwable $e) {
                $failed++;

                $log->error('Failed to reject phantom invoice.', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTrace(),
                ]);
            }
        }

        $this->info("Rejected {$rejected} phantom invoices. Failed: {$failed}.");

        return self::SUCCESS;
    }
}
