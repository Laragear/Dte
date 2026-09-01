<?php

namespace Laragear\Dte\Certification\Simulation\Pipes;

use Closure;
use Faker\Generator;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Laragear\Dte\Certification\Simulation\SimulationData;
use Laragear\Dte\Enums\DteType;
use Laragear\Dte\Models\SiiDte;
use function count;

class GenerateSimulationDtes
{
    /**
     * Create a new Generate Simulation Dtes instance.
     */
    public function __construct(
        protected Generator $faker,
    ) {
        //
    }

    /**
     * Handle the incoming simulation data.
     */
    public function handle(SimulationData $data, Closure $next): SimulationData
    {
        $quantity = $data->quantity;

        $typeLabels = [
            DteType::Invoice->value => 'Invoice (33)',
            DteType::InvoiceExempt->value => 'Exempt Invoice (34)',
            DteType::Receipt->value => 'Receipt (39)',
            DteType::ExemptReceipt->value => 'Exempt Receipt (41)',
            DteType::InvoiceLiquidation->value => 'Invoice Liquidation (43)',
            DteType::PurchaseInvoice->value => 'Purchase Invoice (46)',
            DteType::DispatchGuide->value => 'Dispatch Guide (52)',
            DteType::DebitNote->value => 'Debit Note (56)',
            DteType::CreditNote->value => 'Credit Note (61)',
        ];

        $selectedTypes = empty($data->documentTypes) ? array_keys($typeLabels) : $data->documentTypes;

        $data->dtes = SiiDte::factory([
            'issuer_rut' => $data->rut,
            'created_at' => $this->faker->dateTimeBetween('-2 months', '-1 day'),
        ])
            ->sequence(fn(Sequence $sequence) => [
                'document_type' => $selectedTypes[$sequence->index % count($selectedTypes)],
            ])
            ->createMany($quantity);

        return $next($data);
    }
}
