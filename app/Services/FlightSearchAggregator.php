<?php


namespace App\Services;

use App\Models\User;
use App\Services\AgentRuleService;
use Illuminate\Support\Facades\Cache;


/**
 * Purpose of Aggregator:
 * - Normalize request
 * - Check sector rules (e.g. agent not allowed SKT→DXB)
 * - Use cache for 5 minutes
 * - Call ONE or MULTIPLE suppliers (Travelport, Sabre, etc.)
 * - Apply agent markups/policies
 */
class FlightSearchAggregator
{

    protected AgentRuleService $agentRuleService;
    protected SupplierProviderFactory $providerFactory;

    public function __construct(
        AgentRuleService $agentRuleService,
        SupplierProviderFactory $providerFactory
    ) {
        $this->agentRuleService = $agentRuleService;
        $this->providerFactory  = $providerFactory;
    }


    /**
     * STEP 3 (inside Aggregator):
     * 1. Normalize request input
     * 2. Check sector permission
     * 3. Use cache for 5m
     * 4. Call suppliers (Travelport, Sabre)
     * 5. Apply agent markups
     */

    public function search(User $agent, array $search): array
    {
        // 1) Normalize
        $normalized = $this->normalizeSearch($search);

        // 2) Check if this agent is allowed to search this route
        if (! $this->agentRuleService->canSearchSector(
            $agent,
            $normalized['from'],
            $normalized['to']
        )) {
            abort(403, "You cannot search this route ({$normalized['from']} → {$normalized['to']}).");
        }

        // 3) Create cache key for same search combinations
        $cacheKey = $this->buildCacheKey($normalized);

        // 4) Cache for 5 minutes
        $baseOffers = Cache::remember($cacheKey, 300, function () use ($agent, $normalized) {
            return $this->callSuppliers($agent, $normalized);
        });

        // 5) Apply agent markups/rules
        return $this->agentRuleService->applyMarkupsAndFilters($agent, $baseOffers);
    }


     protected function normalizeSearch(array $search): array
    {
        return [
            'from'      => strtoupper($search['from']),
            'to'        => strtoupper($search['to']),
            'departure' => $search['departure'],
            'return'    => $search['return'] ?? null,
            'trip_type' => $search['trip_type'],
            'adults'    => (int) $search['adults'],
            'children'  => (int) ($search['children'] ?? 0),
            'infants'   => (int) ($search['infants'] ?? 0),
        ];
    }

    protected function buildCacheKey(array $normalized): string
    {
        return 'flight_search:' . md5(json_encode($normalized));
    }

    /**
     * STEP 4: Call all enabled suppliers for this agent
     */
    protected function callSuppliers(User $agent, array $normalized): array
    {
        $providers = $this->providerFactory->forSearch($agent);

        $allOffers = [];

        foreach ($providers as $provider) {
            // Each provider returns "common format flight offers"
            $offers = $provider->searchFlights($normalized);
            $allOffers = array_merge($allOffers, $offers);
        }

        return $allOffers;
    }

}
