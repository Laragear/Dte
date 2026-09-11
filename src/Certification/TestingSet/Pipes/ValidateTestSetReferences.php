<?php

namespace Laragear\Dte\Certification\TestingSet\Pipes;

use Closure;
use Illuminate\Console\ManuallyFailedException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Laragear\Dte\Certification\TestingSet\TestSetData;
use function array_unique;
use function array_values;
use function count;
use function preg_match;
use function sprintf;

class ValidateTestSetReferences
{
    protected const string SET_REF_PATTERN = '/^CASO (\d+)-(\d+)$/';

    /**
     * Validate that every DTE has the required SET/CASO first reference,
     * then sort by case number and assign sort_order.
     */
    public function handle(TestSetData $data, Closure $next): TestSetData
    {
        $pairs = [];
        $seen = [];

        foreach ($data->dtes as $dte) {
            $dte->loadMissing('payload');

            $references = $dte->payload->data['references'] ?? [];

            $first = $references[0] ?? null;

            if (
                $first === null
                || ($first['document_type'] ?? null) !== 'SET'
                || !preg_match(self::SET_REF_PATTERN, $first['reason'] ?? '', $matches)
            ) {
                throw new ManuallyFailedException(
                    sprintf(
                        'DTE [%d] is missing the required SET/CASO reference. Use forTestCase("NNNNN-N") as the first reference when building the DTE.',
                        $dte->getKey(),
                    ),
                );
            }

            $set = (int) $matches[1];
            $case = (int) $matches[2];
            $pairKey = "{$set}-{$case}";

            if (isset($seen[$pairKey])) {
                throw new ManuallyFailedException(
                    sprintf('Duplicate test case CASO %s found on DTEs [%d] and [%d].', $pairKey, $seen[$pairKey], $dte->getKey()),
                );
            }

            $seen[$pairKey] = $dte->getKey();
            $pairs[] = [$set, $case, $dte];
        }

        usort($pairs, static function (array $a, array $b): int {
            return $a[0] !== $b[0] ? $a[0] <=> $b[0] : $a[1] <=> $b[1];
        });

        foreach ($pairs as $index => $entry) {
            $entry[2]->forceFill(['metadata' => ['sort_order' => $index + 1]])->save();
        }

        $data->dtes = EloquentCollection::make(array_column($pairs, 2));

        return $next($data);
    }
}
