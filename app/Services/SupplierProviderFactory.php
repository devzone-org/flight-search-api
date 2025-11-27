<?php


namespace App\Services;
use App\Models\User;
use App\Services\AgentRuleService;
use App\Services\Suppliers\TravelportProvider;


/**
 * Purpose:
 * - Build provider objects based on supplier DB + agent rules
 */
class SupplierProviderFactory
{
    
     protected AgentRuleService $agentRuleService;

    public function __construct(AgentRuleService $agentRuleService)
    {
        $this->agentRuleService = $agentRuleService;
    }

    public function forSearch(User $agent): array
    {
        $suppliers = $this->agentRuleService->allowedSuppliersForSearch($agent);

        $providers = [];

        foreach ($suppliers as $supplier) {
            if ($supplier->code === 'travelport') {
                $providers[] = new TravelportProvider($supplier);
            }

            // Later you can add Sabre, Amadeus etc.
        }

        return $providers;
    }
}