<?php

namespace App\Services\Suppliers;

use App\Services\Contracts\SupplierInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\Supplier;
use Illuminate\Support\Arr;
use Carbon\Carbon;

class TravelportProvider implements SupplierInterface
{
    protected Supplier $supplier;
    public
        $base_url,
        $settings,
        $credentials;


    public function __construct(Supplier $supplier)
    {
        $this->supplier = $supplier;

        $settings = $this->supplier->settings; // JSON field
        $settings = json_decode($settings, true);
        $this->settings = $settings;

        $this->base_url = $this->settings['base_url'];

        $creds = $this->supplier->credentials; // JSON field
        $this->credentials = json_decode($creds, true);
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

        // NOTE: pass $search into transformer so we can derive slice_index + direction
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
        if ($req['trip_type'] === "roundtrip" && $req['return']) {
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
     * Transform raw Travelport JSON into common format:
     *
     * {
     *   supplier: "travelport",
     *   search_id: "...",
     *   references: { flights, products, brands, terms },
     *   itineraries: [
     *      {
     *        id, offer_id, origin, destination, slice_index, direction, flight_refs[],
     *        summary: {...},
     *        fare_options: [ {...}, {...} ]
     *      }
     *   ]
     * }
     */
    public function transformToCommon(array $response, array $search = []): array
    {
        // ------------------------------------------------------------
        // 0) Detect structure (ReferenceList may be under root or under CatalogProductOfferings)
        // ------------------------------------------------------------
        $root    = Arr::get($response, 'CatalogProductOfferingsResponse', []);
        $catalog = Arr::get($root, 'CatalogProductOfferings', []);

        $referenceListA = Arr::get($catalog, 'ReferenceList', []);
        $referenceListB = Arr::get($root, 'ReferenceList', []);
        $referenceList  = $referenceListA ?: $referenceListB;

        $searchId = Arr::get($catalog, 'Identifier.value')
            ?? Arr::get($root, 'Identifier.value')
            ?? null;

        $offerings = Arr::get($catalog, 'CatalogProductOffering', [])
            ?: Arr::get($root, 'CatalogProductOffering', []);

        // determine trip_type & slices meta (for slice_index + direction)
        $tripType   = $search['trip_type'] ?? 'oneway';
        $slicesMeta = $this->buildSlicesMeta($search);

        // ------------------------------------------------------------
        // 1) Build reference maps (flights, products, brands, terms)
        // ------------------------------------------------------------
        $flights  = [];
        $products = [];
        $brands   = [];
        $terms    = [];

        foreach ($referenceList as $list) {
            $type = $list['@type'] ?? null;

            if ($type === 'ReferenceListFlight') {
                foreach ($list['Flight'] ?? [] as $item) {
                    $flights[$item['id']] = $item;
                }
            }

            if ($type === 'ReferenceListProduct') {
                foreach ($list['Product'] ?? [] as $item) {
                    $products[$item['id']] = $this->normalizeProduct($item);
                }
            }

            if ($type === 'ReferenceListBrand') {
                foreach ($list['Brand'] ?? [] as $item) {
                    $brands[$item['id']] = $item;
                }
            }

            if ($type === 'ReferenceListTermsAndConditions') {
                foreach ($list['TermsAndConditions'] ?? [] as $item) {
                    $terms[$item['id']] = $item;
                }
            }
        }

        // ------------------------------------------------------------
        // 2) Build itineraries = (offering + flight_refs) group
        //    Each itinerary has multiple fare_options (product+brand)
        // ------------------------------------------------------------
        $itinerariesByKey = [];

        foreach ($offerings as $offering) {

            $offerId     = Arr::get($offering, 'id');
            $origin      = Arr::get($offering, 'Departure');
            $destination = Arr::get($offering, 'Arrival');

            // figure out which slice this (origin, destination) belongs to
            $sliceMeta = $this->findSliceForItinerary($slicesMeta, (string) $origin, (string) $destination);

            foreach (Arr::get($offering, 'ProductBrandOptions', []) as $pbo) {

                $flightRefs = Arr::get($pbo, 'flightRefs', []);
                if (empty($flightRefs)) {
                    continue;
                }

                // key = offering + same flight refs => same physical routing
                $itiKey = $this->buildItineraryKey($offerId, $flightRefs);

                // Create itinerary shell if first time
                if (! isset($itinerariesByKey[$itiKey])) {
                    $stopCount = max(count($flightRefs) - 1, 0);

                    $itinerariesByKey[$itiKey] = [
                        'id'          => $itiKey,
                        'supplier'    => $this->code(),
                        'search_id'   => $searchId,
                        'offer_id'    => $offerId,

                        // NEW: these two indexes + trip_type
                        'trip_type'   => $tripType,
                        'slice_index' => $sliceMeta['index'] ?? 1,          // 1,2,3...
                        'direction'   => $sliceMeta['direction'] ?? 'unknown', // outbound|inbound|multi|unknown

                        'origin'      => $origin,
                        'destination' => $destination,
                        'flight_refs' => $flightRefs,

                        'summary' => [
                            'stops'               => $stopCount,
                            'is_direct'           => $stopCount === 0,
                            'has_stops'           => $stopCount > 0,

                            'duration'            => null,
                            'duration_minutes'    => null,
                            'main_cabin'          => null,
                            'main_passenger_type' => null,

                            'departure_time'      => null,
                            'arrival_time'        => null,

                            'cheapest_price'      => null,
                        ],

                        'fare_options' => [],
                    ];
                }

                $itinerary = &$itinerariesByKey[$itiKey];

                // For each brand/product/price variant add a fare_option
                foreach (Arr::get($pbo, 'ProductBrandOffering', []) as $pboffer) {

                    $productRef = Arr::get($pboffer, 'Product.0.productRef');
                    $brandRef   = Arr::get($pboffer, 'Brand.BrandRef');
                    $termsRef   = Arr::get($pboffer, 'TermsAndConditions.termsAndConditionsRef');
                    $comboCodes = Arr::get($pboffer, 'CombinabilityCode', []);

                    $priceBlock       = Arr::get($pboffer, 'BestCombinablePrice', []);
                    $priceSummary     = $this->normalizePrice($priceBlock);
                    $passengerPricing = $this->normalizePriceBreakdown(
                        Arr::get($priceBlock, 'PriceBreakdown', [])
                    );

                    $product = $products[$productRef] ?? null;
                    $mainPax = $product['main_pax'] ?? 'ADT';
                    $cabin   = Arr::get($product, "passenger_products.$mainPax.cabin");

                    // ---------- fill departure / arrival time (once) ----------
                    if ($itinerary['summary']['departure_time'] === null && isset($flightRefs[0])) {
                        $firstRef    = $flightRefs[0];
                        $firstFlight = $flights[$firstRef] ?? null;

                        if ($firstFlight) {
                            $depDate = Arr::get($firstFlight, 'Departure.date');
                            $depTime = Arr::get($firstFlight, 'Departure.time');
                            if ($depDate && $depTime) {
                                $itinerary['summary']['departure_time'] = $depDate . 'T' . $depTime;
                            }
                        }
                    }

                    if ($itinerary['summary']['arrival_time'] === null && !empty($flightRefs)) {
                        $lastRef    = $flightRefs[count($flightRefs) - 1];
                        $lastFlight = $flights[$lastRef] ?? null;

                        if ($lastFlight) {
                            $arrDate = Arr::get($lastFlight, 'Arrival.date');
                            $arrTime = Arr::get($lastFlight, 'Arrival.time');
                            if ($arrDate && $arrTime) {
                                $itinerary['summary']['arrival_time'] = $arrDate . 'T' . $arrTime;
                            }
                        }
                    }

                    // ---------- add fare option ----------
                    $fareOption = [
                        'id'                  => $this->offerId($offerId, $productRef, $brandRef),
                        'product_ref'         => $productRef,
                        'brand_ref'           => $brandRef,
                        'terms_ref'           => $termsRef,
                        'combinability_code'  => $comboCodes,

                        'cabin'               => $cabin,
                        'main_passenger_type' => $mainPax,

                        'pricing' => [
                            'currency'      => $priceSummary['currency'],
                            'base'          => $priceSummary['base'],
                            'taxes'         => $priceSummary['taxes'],
                            'fees'          => $priceSummary['fees'],
                            'surcharges'    => $priceSummary['surcharges'],
                            'total'         => $priceSummary['total'],
                            'per_passenger' => $passengerPricing,
                        ],
                    ];

                    $itinerary['fare_options'][] = $fareOption;

                    // ---------- fill duration / cabin / main pax ----------
                    if ($itinerary['summary']['duration'] === null && $product) {
                        $durationIso = $product['total_duration'] ?? null;
                        $itinerary['summary']['duration']         = $durationIso;
                        $itinerary['summary']['duration_minutes'] = $this->isoToMinutes($durationIso);
                    }

                    if ($itinerary['summary']['main_passenger_type'] === null) {
                        $itinerary['summary']['main_passenger_type'] = $mainPax;
                    }

                    if ($itinerary['summary']['main_cabin'] === null && $cabin) {
                        $itinerary['summary']['main_cabin'] = $cabin;
                    }

                    // ---------- track cheapest fare for card price ----------
                    $total = $priceSummary['total'] ?? null;
                    if ($total !== null) {
                        $cheapest = $itinerary['summary']['cheapest_price'];
                        if ($cheapest === null || $total < $cheapest['total']) {
                            $itinerary['summary']['cheapest_price'] = [
                                'currency'   => $priceSummary['currency'],
                                'base'       => $priceSummary['base'],
                                'taxes'      => $priceSummary['taxes'],
                                'fees'       => $priceSummary['fees'],
                                'surcharges' => $priceSummary['surcharges'],
                                'total'      => $total,
                            ];
                        }
                    }
                }
            }
        }

        // ------------------------------------------------------------
        // Final output
        // ------------------------------------------------------------
        return [
            'supplier'  => $this->code(),
            'search_id' => $searchId,

            'references' => [
                'flights'  => $flights,
                'products' => $products,
                'brands'   => $brands,
                'terms'    => $terms,
            ],

            'itineraries' => array_values($itinerariesByKey),
        ];
    }

    // ========================= Helpers ==============================

    /**
     * Build slice meta from the original search:
     * index: 1,2,3...
     * direction: outbound|inbound|multi|unknown
     */
    protected function buildSlicesMeta(array $search): array
    {
        $tripType = $search['trip_type'] ?? 'oneway';
        $slices   = [];

        if ($tripType === 'oneway') {
            $slices[] = [
                'index'     => 1,
                'from'      => $search['from'] ?? null,
                'to'        => $search['to'] ?? null,
                'direction' => 'outbound',
            ];
        } elseif (in_array($tripType, ['round', 'roundtrip'], true)) {
            $from = $search['from'] ?? null;
            $to   = $search['to'] ?? null;

            $slices[] = [
                'index'     => 1,
                'from'      => $from,
                'to'        => $to,
                'direction' => 'outbound',
            ];
            $slices[] = [
                'index'     => 2,
                'from'      => $to,
                'to'        => $from,
                'direction' => 'inbound',
            ];
        } elseif ($tripType === 'multicity') {
            foreach ($search['slices'] ?? [] as $i => $slice) {
                $slices[] = [
                    'index'     => $i + 1,
                    'from'      => $slice['from'] ?? null,
                    'to'        => $slice['to'] ?? null,
                    'direction' => 'multi',
                ];
            }
        }

        // fallback if nothing built
        if (empty($slices)) {
            $slices[] = [
                'index'     => 1,
                'from'      => $search['from'] ?? null,
                'to'        => $search['to'] ?? null,
                'direction' => 'unknown',
            ];
        }

        return $slices;
    }

    /**
     * Match an itinerary (origin/destination) to a slice.
     */
    protected function findSliceForItinerary(array $slicesMeta, ?string $origin, ?string $destination): array
    {
        foreach ($slicesMeta as $slice) {
            if (
                ($slice['from'] ?? null) === $origin &&
                ($slice['to'] ?? null) === $destination
            ) {
                return $slice;
            }
        }

        // default if no exact match
        return [
            'index'     => 1,
            'direction' => 'unknown',
        ];
    }

    private function normalizeProduct(array $p): array
    {
        $id = $p['id'];

        $segments = [];
        foreach ($p['FlightSegment'] ?? [] as $seg) {
            $segments[] = [
                'sequence'   => $seg['sequence'] ?? null,
                'flight_ref' => Arr::get($seg, 'Flight.FlightRef'),
                'duration'   => $seg['duration'] ?? null,
                'connection' => $seg['connectionDuration'] ?? null,
            ];
        }

        $pax = [];
        foreach ($p['PassengerFlight'] ?? [] as $pf) {
            $type = $pf['passengerTypeCode'] ?? null;
            if (! $type) {
                continue;
            }

            foreach ($pf['FlightProduct'] ?? [] as $fp) {
                $pax[$type] = [
                    'class_of_service' => $fp['classOfService'] ?? null,
                    'cabin'            => $fp['cabin'] ?? null,
                    'fare_basis_code'  => $fp['fareBasisCode'] ?? null,
                    'fare_type'        => $fp['fareType'] ?? null,
                    'fare_type_code'   => $fp['fareTypeCode'] ?? null,
                    'brand_ref'        => Arr::get($fp, 'Brand.BrandRef'),
                ];
            }
        }

        $mainPax = array_key_exists('ADT', $pax)
            ? 'ADT'
            : (array_key_first($pax) ?: null);

        return [
            'id'                 => $id,
            'total_duration'     => $p['totalDuration'] ?? null,
            'segments'           => $segments,
            'passenger_products' => $pax,
            'main_pax'           => $mainPax,
        ];
    }

    private function normalizePrice(array $price): array
    {
        $currency = Arr::get($price, 'OfferListResponse.OfferID.0.Price.CurrencyCode.value', null);
        $base     = Arr::get($price, 'OfferListResponse.OfferID.0.Price.Base', 0);
        $taxes    = Arr::get($price, 'OfferListResponse.OfferID.0.Price.TotalTaxes', 0);
        $fees     = Arr::get($price, 'OfferListResponse.OfferID.0.Price.TotalFees', 0);
        $total    = Arr::get($price, 'OfferListResponse.OfferID.0.Price.TotalPrice', 0);

        $surcharges = 0;
        foreach (Arr::get($price, 'PriceBreakdown', []) as $pb) {
            foreach (Arr::get($pb, 'Surcharges.Surcharge', []) as $s) {
                $surcharges += Arr::get($s, 'value', 0);
            }
        }

        return compact('currency', 'base', 'taxes', 'fees', 'surcharges', 'total');
    }

    private function normalizePriceBreakdown(array $breakdown): array
    {
        $res = [];

        foreach ($breakdown as $pb) {
            $amount = $pb['Amount'] ?? [];

            $currency = Arr::get($amount, 'CurrencyCode.value');
            $base     = Arr::get($amount, 'Base', 0);
            $taxes    = Arr::get($amount, 'Taxes.TotalTaxes', 0);
            $fees     = Arr::get($amount, 'Fees.TotalFees', 0);
            $total    = Arr::get($amount, 'Total', 0);

            $surcharges = 0;
            foreach (Arr::get($pb, 'Surcharges.Surcharge', []) as $s) {
                $surcharges += Arr::get($s, 'value', 0);
            }

            $res[] = [
                'type'       => Arr::get($pb, 'requestedPassengerType'),
                'quantity'   => Arr::get($pb, 'quantity', 1),
                'currency'   => $currency,
                'base'       => $base,
                'taxes'      => $taxes,
                'fees'       => $fees,
                'surcharges' => $surcharges,
                'total'      => $total,
            ];
        }

        return $res;
    }

    private function offerId(?string $offeringId, ?string $productRef, ?string $brandRef): string
    {
        return implode('_', array_filter([$offeringId, $productRef, $brandRef]));
    }

    private function buildItineraryKey(string $offerId, array $flightRefs): string
    {
        return $offerId . ':' . implode('-', $flightRefs);
    }

    private function isoToMinutes(?string $iso): ?int
    {
        if (! $iso || ! str_starts_with($iso, 'PT')) {
            return null;
        }

        $hours = 0;
        $mins  = 0;

        if (preg_match('/(\d+)H/', $iso, $h)) {
            $hours = (int) $h[1];
        }

        if (preg_match('/(\d+)M/', $iso, $m)) {
            $mins = (int) $m[1];
        }

        return $hours * 60 + $mins;
    }


    public function getQuote(array $data): array
    {
        $offer_id = explode(':',$data['offer_id']);
        $offer_id = $offer_id[0] ?? '';

        $body = [
            '@type' => 'OfferQueryBuildFromCatalogProductOfferings',
            'BuildFromCatalogProductOfferingsRequest' => [
                '@type' => 'BuildFromCatalogProductOfferingsRequestAir',
                'CatalogProductOfferingsIdentifier' => [
                    'Identifier' => [
                        'value' => $data['search_id']
                    ]
                ],
                'CatalogProductOfferingSelection' => [
                    [
                        'CatalogProductOfferingIdentifier' => [
                            'Identifier' => [
                                'value' => $offer_id
                            ]
                        ],
                        'ProductIdentifier' => [
                            [
                                'Identifier' => [
                                    'value' => $data['product_ref']
                                ]
                            ]
                        ]
                    ]
                ],
                'validateInventoryInd' => true
            ]
        ];

        $json = Http::withHeaders($this->headerMaking())
            ->post($this->base_url . '/air/price/offers/buildfromcatalogproductofferings', $body)
            ->throw()
            ->json();

        return $this->normalizePrice($json);
    }

    public function bookFlight(array $data): array
    {
        $offer_id = explode(':',$data['offer_id']);
        $offer_id = $offer_id[0] ?? '';

        $session_id = $this->startSessionRequest();
        $offer_id_from_add_offer = $this->addOffer($data, $offer_id, $session_id);
        $travels_response = $this->addTravelers($data, $session_id);

        if($travels_response['success'] === false){
            return $travels_response;
        }

        if (in_array(null, $travels_response['traveler_ids'], true)) {
            return ['message' => 'All Travels not registered.'];
        }

        $body = [
            '@type' => 'ReservationQueryCommitReservation',
        ];

        $json = Http::withHeaders($this->headerMaking())
            ->post($this->base_url . "/air/book/reservation/reservations/$session_id", $body)
            ->throw()
            ->json();

        return $json;
    }

    private function headerMaking() :array
    {
        $token = $this->getAccessToken();

        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate',
            'XAUTH_TRAVELPORT_ACCESSGROUP' => $this->credentials['XAUTH_TRAVELPORT_ACCESSGROUP'] ?? '',
            'Accept-Version' => $this->settings['api_version'],
            'Content-Version' => $this->settings['api_version'],
            'taxBreakDown' => 'true'
        ];
    }

    private function startSessionRequest()
    {
        $body = [
            "@type" => "ReservationID",
            "ReservationID" => []
        ];
        $json = Http::withHeaders($this->headerMaking())
            ->post($this->base_url . '/air/book/session/reservationworkbench', $body)
            ->throw()
            ->json();

        return $json['ReservationResponse']['Reservation']['Identifier']['value'] ?? null;
    }

    private function addOffer($data, $offer_id, $session_id)
    {
        $body = [
            '@type' => 'OfferQueryBuildFromCatalogProductOfferings',
            'BuildFromCatalogProductOfferingsRequest' => [
                '@type' => 'BuildFromCatalogProductOfferingsRequestAir',
                'CatalogProductOfferingsIdentifier' => [
                    'Identifier' => [
                        'value' => $data['search_id']
                    ]
                ],
                'CatalogProductOfferingSelection' => [
                    [
                        'CatalogProductOfferingIdentifier' => [
                            'Identifier' => [
                                'value' => $offer_id
                            ]
                        ],
                        'ProductIdentifier' => [
                            [
                                'Identifier' => [
                                    'value' => $data['product_ref']
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
        $json = Http::withHeaders($this->headerMaking())
            ->post($this->base_url . "/air/book/airoffer/reservationworkbench/{$session_id}/offers/buildfromcatalogproductofferings", $body)
            ->throw()
            ->json();

        return $json['OfferListResponse']['OfferID']['Identifier']['value'] ?? null;
    }

    private function addTravelers($data, $session_id)
    {
        $response_array = [];
        $passengers = $data['passengers'] ?? [];

        foreach ($passengers as $passenger) {
            $body = [
                '@type' => 'Traveler',
                'gender' => strtolower($passenger['gender']) == 'f' ? 'Female' : 'Male',
                'birthDate' => $passenger['dob'] ?? null,
                'id' => $passenger['key'],
                'passengerTypeCode' => $passenger['type'] ?? null,
                'PersonName' => [
                    '@type' => 'PersonNameDetail',
                    'Given' => $passenger['first_name'] ?? null,
                    'Surname' => $passenger['last_name'] ?? null,
                ],
                'Telephone' => [
                    [
                        '@type' => 'Telephone',
                        'countryAccessCode' => '1', // HardCoded
                        'phoneNumber' => $data['contact']['phone'] ?? null,
                        'id' => '4', //Hardcoded
                        'cityCode' => 'ORD', // Hardcoded
                        'role' => 'Home' // Hardcoded
                    ]
                ],
                'Email' => [
                    [
                        'value' => $data['contact']['email'] ?? null,
                    ]
                ],
                'TravelDocument' => [
                    [
                        '@type' => 'TravelDocumentDetail',
                        'docNumber' => $passenger['passport_no'] ?? null,
                        'docType' => 'Passport',
                        'expireDate' => $passenger['passport_expiry'] ?? null,
                        'issueCountry' => $passenger['nationality'] ?? null,
                        'birthDate' => $passenger['dob'] ?? null,
                        'Gender' => strtolower($passenger['gender']) == 'f' ? 'Female' : 'Male',
                        'PersonName' => [
                            '@type' => 'PersonName',
                            'Given' => $passenger['first_name'] ?? null,
                            'Surname' => $passenger['last_name'] ?? null,
                        ]
                    ]
                ]
            ];

            $json = Http::withHeaders($this->headerMaking())
                ->post($this->base_url . "/air/book/traveler/reservationworkbench/$session_id/travelers", $body)
                ->throw()
                ->json();

            if(isset($json['TravelerResponse']['Result']['Error'][0]['Message'])){
                return [
                    'success' => false,
                    'message' => $json['TravelerResponse']['Result']['Error'][0]['Message']
                ];
            }

            $response_array[] = $json['TravelerResponse']['Traveler']['Identifier']['value'] ?? null;
        }



        return [
            'success' => true,
            'traveler_ids' => $response_array
        ];
    }
}
