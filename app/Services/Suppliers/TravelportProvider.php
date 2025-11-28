<?php

namespace App\Services\Suppliers;

use App\Services\Contracts\SupplierInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\Supplier;

class TravelportProvider implements SupplierInterface
{
    protected Supplier $supplier;


    public function __construct(Supplier $supplier)
    {
        $this->supplier = $supplier;
    }

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

        $token = $this->getAccessToken();

        $body = $this->buildRequestBody($search);

        $settings = $this->supplier->settings; // JSON field
        $settings = json_decode($settings, true);

        $creds = $this->supplier->credentials; // JSON field
        $creds = json_decode($creds, true);

        $json = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate',
            'XAUTH_TRAVELPORT_ACCESSGROUP' => $creds['XAUTH_TRAVELPORT_ACCESSGROUP'] ?? '',
            'Accept-Version' => $settings['api_version'],
            'Content-Version' => $settings['api_version'],
            'taxBreakDown' => 'true'
        ])
            ->post($settings['base_url'] . '/air/catalog/search/catalogproductofferings', $body)
            ->throw()
            ->json();



        return $this->transformToCommon($json, $search);
    }

    /**
     * Fetch token using OAuth (cached)
     */
    protected function getAccessToken(): string
    {







        $key = 'tp_token_' . $this->supplier->id;

        return Cache::remember($key, 85000, function () {

            $creds = $this->supplier->credentials; // JSON field
            $creds = json_decode($creds, true);
            $resp = Http::asForm()
                ->post($this->supplier->endpoint, [
                    'grant_type'    => $creds['grant_type'] ?? null,
                    'username'      => $creds['username'] ?? null,
                    'password'      => $creds['password'] ?? null,
                    'client_id'     => $creds['client_id'] ?? null,
                    'client_secret' => $creds['client_secret'] ?? null,
                    'scope'         => $creds['scope'] ?? null,
                ])
                ->throw()
                ->json();

            return $resp['access_token'];
        });
    }

    protected function buildRequestBody(array $search): array
    {



        $req = $search; // your search array

        // Build PassengerCriteria dynamically
        $passengers = [];

        if ($req['adults'] > 0) {
            $passengers[] = [
                "@type" => "PassengerCriteria",
                "number" => $req['adults'],
                "passengerTypeCode" => "ADT"
            ];
        }

        if ($req['children'] > 0) {
            $passengers[] = [
                "@type" => "PassengerCriteria",
                "number" => $req['children'],
                "passengerTypeCode" => "CHD"
            ];
        }

        if ($req['infants'] > 0) {
            $passengers[] = [
                "@type" => "PassengerCriteria",
                "number" => $req['infants'],
                "passengerTypeCode" => "INF"
            ];
        }

        // Build Flight Search Array
        $searchCriteriaFlight = [
            [
                "@type" => "SearchCriteriaFlight",
                "departureDate" => $req['departure'],
                "From" => ["value" => $req['from']],
                "To"   => ["value" => $req['to']]
            ]
        ];

        // Optional round-trip block
        if ($req['trip_type'] === "round" && $req['return']) {
            $searchCriteriaFlight[] = [
                "@type" => "SearchCriteriaFlight",
                "departureDate" => $req['return'],
                "From" => ["value" => $req['to']],
                "To"   => ["value" => $req['from']]
            ];
        }


        return [
            "CatalogProductOfferingsQueryRequest" => [
                "CatalogProductOfferingsRequest" => [
                    "@type" => "CatalogProductOfferingsRequestAir",
                    "offersPerPage" => 15,
                    "maxNumberOfUpsellsToReturn" => 4,
                    "contentSourceList" => ["GDS"],
                    "PassengerCriteria" => $passengers,
                    "SearchCriteriaFlight" => $searchCriteriaFlight
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
        return $resp;
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
