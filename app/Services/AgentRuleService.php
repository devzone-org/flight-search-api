<?php


namespace App\Services;

use App\Models\User;

/**
 * Purpose:
 * - Decide which supplier agent can use
 * - Apply markup
 * - Block sector SKT → DXB etc.
 */
class AgentRuleService
{

    /**
     * STEP 3.2:
     * Check if agent is allowed to search a specific route
     * e.g. prevent SKT→DXB
     */
    public function canSearchSector(User $agent, string $from, string $to): bool
    {
        // Example block: You can load rules from DB later
        // if ($from === 'SKT' && $to === 'DXB') return false;

        return true; // allow all for now
    }

    /**
     * STEP 4: Only return suppliers that agent can use
     */
    public function allowedSuppliersForSearch(User $agent): array
    {
        return \App\Models\Supplier::where('active', 1)
            ->get()
            ->all();
    }

    /**
     * STEP 6: Apply agent markups after supplier response
     */
    public function applyMarkupsAndFilters(User $agent, array $offers): array
    {
        return $offers; // allow all for now
        // $markupBySupplier = $agent->suppliers->mapWithKeys(function (Supplier $supplier) {
        //     return [
        //         $supplier->code => $supplier->pivot->markup_percent ?? 0,
        //     ];
        // });

        // return collect($offers)
        //     ->map(function ($offer) use ($markupBySupplier) {
        //         $supplierCode = $offer['supplier'];
        //         $base         = $offer['price']['total'];
        //         $percent      = $markupBySupplier[$supplierCode] ?? 0;

        //         $value = round($base * ($percent / 100));

        //         $offer['price']['markup_percent']     = $percent;
        //         $offer['price']['markup_value']       = $value;
        //         $offer['price']['total_after_markup'] = $base + $value;

        //         return $offer;
        //     })
        //     ->values()
        //     ->all();
    }
}
