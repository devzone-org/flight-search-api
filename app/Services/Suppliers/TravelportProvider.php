<?php

namespace App\Services\Suppliers;

use App\Services\Contracts\SupplierInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class TravelportProvider implements SupplierInterface
{
    // protected Supplier $supplier;

    // public function __construct(Supplier $supplier)
    // {
    //     $this->supplier = $supplier;
    // }

    public function code(): string
    {
        return 'travelport';
    }


     /**
     * STEP 5: The Aggregator calls this. 
     * This function:
     * - gets token
     * - sends search request to Travelport
     * - returns unified offers
     */
    public function searchFlights(array $search): array
    {
        dd('TravelportProvider searchFlights called');
        $token = $this->getAccessToken();

        $body = $this->buildRequestBody($search);

        $json = Http::withToken($token)
            //->post($this->supplier->api_base_url.'/catalog/product-offers', $body)
            ->throw()
            ->json();

        return $this->transformToCommon($json, $search);
    }

     /**
     * Fetch token using OAuth (cached)
     */
    protected function getAccessToken(): string
    {
        $key = 'tp_token_'.$this->supplier->id;

        return Cache::remember($key, 3400, function () {
            $resp = Http::asForm()
                ->post($this->supplier->auth_url, [
                    'grant_type'    => $this->supplier->grant_type,
                    'username'      => $this->supplier->username,
                    'password'      => $this->supplier->password,
                    'client_id'     => $this->supplier->client_id,
                    'client_secret' => $this->supplier->client_secret,
                    'scope'         => $this->supplier->scope,
                ])
                ->throw()
                ->json();

            return $resp['access_token'];
        });
    }

    protected function buildRequestBody(array $search): array
    {
        $criteria = [
            [
                "from" => $search['from'],
                "to"   => $search['to'],
                "date" => $search['departure'],
            ],
        ];

        if ($search['trip_type'] === 'roundtrip') {
            $criteria[] = [
                "from" => $search['to'],
                "to"   => $search['from'],
                "date" => $search['return'],
            ];
        }

        return [
            "SearchCriteriaFlight" => $criteria,
            "Passengers" => [
                [
                    "type"     => "ADT",
                    "quantity" => $search['adults'],
                ]
            ]
        ];
    }


    /**
     * STEP 6: Convert Travelport JSON → common unified format
     * This is what your frontend will consume.
     */
    protected function transformToCommon(array $resp, array $search): array
    {
        // Simplified version (complete version already shared earlier)
        $offers = [];

        $offerings = $resp['CatalogProductOfferingsResponse']['CatalogProductOfferings']['CatalogProductOffering'] ?? [];

        foreach ($offerings as $o) {

            $pboList = $o['ProductBrandOptions'] ?? [];

            foreach ($pboList as $pbo) {
                $brandOfferings = $pbo['ProductBrandOffering'] ?? [];

                foreach ($brandOfferings as $offering) {
                    $price = $offering['BestCombinablePrice'] ?? null;

                    $offers[] = [
                        "supplier" => "travelport",
                        "offer_id" => $o['id'],
                        "price" => [
                            "currency" => $price['CurrencyCode']['value'] ?? null,
                            "base"     => $price['Base'] ?? 0,
                            "taxes"    => $price['TotalTaxes'] ?? 0,
                            "total"    => $price['TotalPrice'] ?? 0,
                        ],
                        "meta" => $o
                    ];
                }
            }
        }

        return $offers;
    }
}