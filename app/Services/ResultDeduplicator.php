<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\Marketplace\ExternalProductData;

/**
 * Conservative de-duplication of provider search results.
 *
 * Only items that share an identical fingerprint (provider code + external
 * product id + external offer id) are treated as duplicates. Cross-provider
 * matching by title/brand/EAN is intentionally out of scope for v1: a false
 * merge hides a legitimate offer, which is far worse than showing the same
 * product twice.
 */
class ResultDeduplicator
{
    /**
     * @param ExternalProductData[] $items
     * @return ExternalProductData[]
     */
    public function deduplicate(array $items): array
    {
        /** @var array<string, int> $positionByFingerprint */
        $positionByFingerprint = [];

        /** @var ExternalProductData[] $kept */
        $kept = [];

        foreach ($items as $item) {
            if (! $item instanceof ExternalProductData) {
                continue;
            }

            $fingerprint = $item->fingerprint();

            if (! array_key_exists($fingerprint, $positionByFingerprint)) {
                $positionByFingerprint[$fingerprint] = count($kept);
                $kept[] = $item;

                continue;
            }

            // Duplicate: keep the cheaper offer but hold its original slot so
            // the caller-visible ordering stays stable.
            $position = $positionByFingerprint[$fingerprint];

            if ($this->isCheaper($item, $kept[$position])) {
                $kept[$position] = $item;
            }
        }

        return array_values($kept);
    }

    /**
     * A null price never wins against a known price.
     */
    private function isCheaper(ExternalProductData $candidate, ExternalProductData $current): bool
    {
        if ($candidate->priceAmount === null) {
            return false;
        }

        if ($current->priceAmount === null) {
            return true;
        }

        return $candidate->priceAmount < $current->priceAmount;
    }
}
