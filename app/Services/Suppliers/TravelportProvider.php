<?php

namespace App\Services\Suppliers;

use App\Services\Contracts\SupplierInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\Supplier;
use Illuminate\Support\Arr;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TravelportProvider implements SupplierInterface
{
    // Typed supplier model injected into the provider.
    // Holds the Supplier Eloquent model instance used for settings & credentials.
    protected Supplier $supplier;

    // Public properties for configuration loaded from the Supplier model.
    // - $base_url: the Travelport API base URL (string).
    // - $settings: decoded settings array (from JSON).
    // - $credentials: decoded credentials array (from JSON).
    public
        $base_url,
        $settings,
        $credentials;


    public function __construct(Supplier $supplier)
    {
        // Store the injected Supplier model for later use.
        $this->supplier = $supplier;

        // The Supplier model stores a JSON string in the `settings` field.
        // Decode it into an associative array for easy access.
        $settings = $this->supplier->settings; // JSON field (string)
        $settings = json_decode($settings, true); // decode to array
        $this->settings = $settings; // save decoded settings

        // Read the base URL from settings and assign to a dedicated property.
        // Assumes settings contain a `base_url` key.
        $this->base_url = $this->settings['base_url'];

        // The Supplier model also stores credentials as a JSON string.
        // Decode and keep them in $this->credentials for authenticated calls.
        $creds = $this->supplier->credentials; // JSON field (string)
        $this->credentials = json_decode($creds, true); // decode to array
    }


    public function code(): string
    {
        return 'travelport';
    }

    protected function buildCacheKey(array $search): string
    {
        return 'flight_search:' . md5(json_encode($search));
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
        $cache_key = $this->buildCacheKey($search);

        return Cache::remember($cache_key, 60, function () use ($search) {
            return $this->sendHttpRequestForOffers($search);
        });
    }

    private function sendHttpRequestForOffers(array $search) :array
    {
        $body = $this->buildRequestBody($search);

        $json = Http::withHeaders($this->headerMaking())
            ->post($this->base_url . '/air/catalog/search/catalogproductofferings', $body)
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

            $resp = Http::asForm()
                ->post($this->supplier->endpoint, [
                    'grant_type'    => $this->credentials['grant_type'] ?? null,
                    'username'      => $this->credentials['username'] ?? null,
                    'password'      => $this->credentials['password'] ?? null,
                    'client_id'     => $this->credentials['client_id'] ?? null,
                    'client_secret' => $this->credentials['client_secret'] ?? null,
                    'scope'         => $this->credentials['scope'] ?? null,
                ])
                ->throw()
                ->json();

            return $resp['access_token'];
        });
    }

    /**
     * Build the request body expected by Travelport's catalog search endpoint.
     *
     * - Uses the provided $search array to build PassengerCriteria and SearchCriteriaFlight.
     * - Returns a wrapped structure under "CatalogProductOfferingsQueryRequest".
     *
     * Note: This function assumes keys like 'adults', 'children', 'infants', 'departure',
     * 'from', 'to', 'trip_type' and optional 'return' exist in the $search array.
     *
     * @param array $search
     * @return array
     */
    protected function buildRequestBody(array $search): array
    {
        // Local alias for readability
        $req = $search;

        // -----------------------
        // 1) Build PassengerCriteria
        // -----------------------
        // Travelport expects an array of PassengerCriteria objects, each with:
        //  - @type: "PassengerCriteria"
        //  - number: how many passengers of that type
        //  - passengerTypeCode: ADT / CHD / INF
        $passengers = [];

        // Add adults if present and greater than zero
        if (!empty($req['adults']) && $req['adults'] > 0) {
            $passengers[] = [
                "@type" => "PassengerCriteria",
                "number" => $req['adults'],
                "passengerTypeCode" => "ADT"
            ];
        }

        // Add children if present and greater than zero
        if (!empty($req['children']) && $req['children'] > 0) {
            $passengers[] = [
                "@type" => "PassengerCriteria",
                "number" => $req['children'],
                "passengerTypeCode" => "CHD"
            ];
        }

        // Add infants if present and greater than zero
        if (!empty($req['infants']) && $req['infants'] > 0) {
            $passengers[] = [
                "@type" => "PassengerCriteria",
                "number" => $req['infants'],
                "passengerTypeCode" => "INF"
            ];
        }

        // -----------------------
        // 2) Build Flight Search Criteria (one or more slices)
        // -----------------------
        // Each SearchCriteriaFlight represents one flight slice with departureDate, From and To.
        // Start with the first (outbound) slice using keys 'departure', 'from', 'to'.
        $searchCriteriaFlight = [
            [
                "@type" => "SearchCriteriaFlight",
                "departureDate" => $req['departure'],
                "From" => ["value" => $req['from']],
                "To"   => ["value" => $req['to']]
            ]
        ];

        // If this is a round-trip and a return date is provided, add the inbound slice.
        // The inbound slice swaps From/To compared to the outbound.
        if (($req['trip_type'] ?? null) === "roundtrip" && !empty($req['return'])) {
            $searchCriteriaFlight[] = [
                "@type" => "SearchCriteriaFlight",
                "departureDate" => $req['return'],
                "From" => ["value" => $req['to']],
                "To"   => ["value" => $req['from']]
            ];
        }

        // -----------------------
        // 3) Wrap into the final request structure
        // -----------------------
        // The top-level key expected by the API is "CatalogProductOfferingsQueryRequest"
        // which contains "CatalogProductOfferingsRequest" with various options.
        return [
            "CatalogProductOfferingsQueryRequest" => [
                "CatalogProductOfferingsRequest" => [
                    "@type" => "CatalogProductOfferingsRequestAir",

                    // Pagination / limits
                    "offersPerPage" => 15,
                    "maxNumberOfUpsellsToReturn" => 4,

                    // Content source hint (e.g. GDS)
                    "contentSourceList" => ["GDS"],

                    // The passenger mix constructed above
                    "PassengerCriteria" => $passengers,

                    // One or more search slices
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

        $airports_details = config('airports');

        foreach ($offerings as $offering) {

            $offerId     = Arr::get($offering, 'id');
            $origin      = Arr::get($offering, 'Departure');
            $destination = Arr::get($offering, 'Arrival');

            $origin_airport_details = $airports_details[$origin] ?? [];
            $destination_airport_details = $airports_details[$destination] ?? [];

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
                    $flight_stops = [];

                    if (!empty($stopCount) && $stopCount > 0 && is_array($flightRefs)) {

                        $flight_stops_details = [];

                        foreach ($flightRefs as $flight_ref) {

                            if (empty($flights[$flight_ref])) {
                                continue;
                            }

                            $reference = $flights[$flight_ref];

                            $depDate = Arr::get($reference, 'Departure.date');
                            $depTime = Arr::get($reference, 'Departure.time');
                            $arrDate = Arr::get($reference, 'Arrival.date');
                            $arrTime = Arr::get($reference, 'Arrival.time');

                            // Skip if any required time value is missing
                            if (!$depDate || !$depTime || !$arrDate || !$arrTime) {
                                continue;
                            }

                            $departure_timestamp = strtotime($depDate . ' ' . $depTime);
                            $arrival_timestamp   = strtotime($arrDate . ' ' . $arrTime);

                            // Skip invalid timestamps
                            if ($departure_timestamp === false || $arrival_timestamp === false) {
                                continue;
                            }

                            $flight_stops_details[] = [
                                'departure_airport' => Arr::get($reference, 'Departure.location', ''),
                                'departure_time'    => $departure_timestamp,
                                'arrival_airport'   => Arr::get($reference, 'Arrival.location', ''),
                                'arrival_time'      => $arrival_timestamp,
                            ];
                        }

                        $count = count($flight_stops_details);

                        if ($count > 1) {

                            for ($i = 0; $i < $count - 1; $i++) {

                                $current_row = $flight_stops_details[$i];
                                $next_row    = $flight_stops_details[$i + 1];

                                // Absolute safety for missing keys
                                if (
                                    !isset($current_row['arrival_time'], $next_row['departure_time']) ||
                                    !is_numeric($current_row['arrival_time']) ||
                                    !is_numeric($next_row['departure_time'])
                                ) {
                                    continue;
                                }

                                $difference = max(0, $next_row['departure_time'] - $current_row['arrival_time']);

                                $days    = intdiv($difference, 86400);
                                $hours   = intdiv($difference % 86400, 3600);
                                $minutes = intdiv($difference % 3600, 60);

                                if ($days > 0) {
                                    $duration = "{$days}d {$hours}h";
                                } elseif ($hours > 0) {
                                    $duration = "{$hours}h {$minutes}m";
                                } else {
                                    $duration = "{$minutes}m";
                                }

                                // Prevent undefined index
                                $airport = $next_row['departure_airport'] ?? null;

                                if ($airport) {
                                    $airport_details = $airports_details[$airport] ?? [];
                                    $city = is_array($airport_details) && !empty($airport_details['city'])
                                        ? $airport_details['city']
                                        : $airport;
                                    $flight_stops[$city] = $duration;
                                }
                            }
                        }
                    }


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

                        'origin_details' => [
                            'airport_code' => $origin,
                            'airport' => $origin_airport_details['airport_name'] ?? null,
                            'city' => $origin_airport_details['city'] ?? null,
                            'country' => $origin_airport_details['country_name'] ?? null,
                        ],

                        'destination_details' => [
                            'airport_code' => $destination,
                            'airport' => $destination_airport_details['airport_name'] ?? null,
                            'city' => $destination_airport_details['city'] ?? null,
                            'country' => $destination_airport_details['country_name'] ?? null,
                        ],

                        'summary' => [
                            'stops'               => $stopCount,
                            'stops_details'       => $flight_stops,
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

                    // ---------- brand details ----------
                    $brand = $brands[$brandRef] ?? [];
                    $brand_array = [
                        'Carry-on baggage' => 'Not Offered',
                        'Check-in baggage' => 'Not Offered',
                        'Seat Selection' => 'Not Offered',
                        'Meal' => 'Not Offered',
                        'Modification' => 'Not Offered',
                        'Cancellation' => 'Not Offered',
                    ];
                    if(!empty($brand)){
                        $brand_details = Arr::get($brand, 'BrandAttribute', []);
                        $brand_additional_details = Arr::get($brand, 'AdditionalBrandAttribute', []);
                        $brand_details = array_merge($brand_details, $brand_additional_details);

                        foreach ($brand_array as $key => $value) {
                            $filter_key = '';
                            if($key === 'Carry-on baggage'){
                                $filter_key = 'CarryOn';
                            }elseif($key === 'Check-in baggage'){
                                $filter_key = 'CheckedBag';
                            }elseif($key === 'Seat Selection'){
                                $filter_key = 'SeatAssignment';
                            }elseif($key === 'Meal'){
                                $filter_key = 'Meals';
                            }elseif($key === 'Modification'){
                                $filter_key = 'Rebooking';
                            }elseif($key === 'Cancellation'){
                                $filter_key = 'Refund';
                            }
                            $filtered = array_filter(
                                $brand_details,
                                fn ($item) => ($item['classification'] ?? null) === $filter_key
                            );
                            $brand_array[$key] = array_values($filtered)[0]['inclusion'] ?? 'Not Offered';

                        }
                    }
                    // ---------- end brand details ----------

                    // ---------- add baggage details ----------
//                    $terms_data = $terms[$termsRef] ?? [];
//                    if(!empty($terms_data)){
//                        $baggage_details = Arr::get($terms_data, 'BaggageAllowance', []);
//                        $penalties = Arr::get($terms_data, 'Penalties', []);
//                        $result = [];
//                        if(!empty($baggage_details)){
//                            foreach ($baggage_details as $baggage) {
//
//
//                                // 1️⃣ Format baggage type
//                                $type = $baggage['baggageType'] ?? ($baggage['@type'] ?? 'Unknown');
//
//                                // Split camelCase / PascalCase into words
//                                $type_formatted = preg_replace('/([a-z])([A-Z])/', '$1 $2', $type);
//                                $type_formatted = preg_replace('/([A-Z])([A-Z][a-z])/', '$1 $2', $type_formatted);
//
//                                // 2️⃣ Take the first baggage item safely
//                                $first_item = $baggage['BaggageItem'][0] ?? [];
//
//                                // Quantity
//                                $quantity = $first_item['quantity'] ?? 1;
//
//                                // Included in offer
//                                $include_in_offer = $first_item['includedInOfferPrice'] ?? 'No';
//                                $additional_cost = false;
//                                if(strtolower($include_in_offer) === 'no'){
//                                    // it means price will be applicable
//                                    $additional_cost = true;
//                                }
//
//                                // Weight if exists
//                                $weight = null;
//                                if (!empty($first_item['Measurement']) && is_array($first_item['Measurement'])) {
//                                    $measure = $first_item['Measurement'][0] ?? [];
//                                    if (isset($measure['value']) && isset($measure['unit'])) {
//                                        $weight = $measure['value'] . ' ' . $measure['unit'];
//                                    }
//                                }
//
//                                if(!empty($weight)){
//                                    $weight .= ' ' . $quantity . ' Piece';
//                                }
//                                $text = $first_item['Text'] ?? 'No baggage info available';
//
//                                $result[] = [
//                                    'key' => $type_formatted,
//                                    'value' => !empty($additional_cost) ? 'Additional Cost' : (!empty($weight) ? $weight : $text),
//                                    'packet' => $baggage
//                                ];
//                            }
//                        }
//                    }
                    // ---------- end baggage details ----------

                    // ---------- add fare option ----------
                    $fareOption = [
                        'id'                  => $this->offerId($offerId, $productRef, $brandRef),
                        'product_ref'         => $productRef,
                        'brand_ref'           => $brandRef,
                        'terms_ref'           => $termsRef,
                        'combinability_code'  => $comboCodes,
                        'flight_additional_details' => $brand_array,

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

    private function normalizePrice(array $price, bool $checkout = false): array
    {
        if($checkout){
            $currency = Arr::get($price, 'OfferListResponse.OfferID.0.Price.CurrencyCode.value', null);
            $base     = Arr::get($price, 'OfferListResponse.OfferID.0.Price.Base', 0);
            $taxes    = Arr::get($price, 'OfferListResponse.OfferID.0.Price.TotalTaxes', 0);
            $fees     = Arr::get($price, 'OfferListResponse.OfferID.0.Price.TotalFees', 0);
            $total    = Arr::get($price, 'OfferListResponse.OfferID.0.Price.TotalPrice', 0);
        }else{
            $currency = Arr::get($price, 'CurrencyCode.value');
            $base     = Arr::get($price, 'Base', 0);
            $taxes    = Arr::get($price, 'TotalTaxes', 0);
            $fees     = Arr::get($price, 'TotalFees', 0);
            $total    = Arr::get($price, 'TotalPrice', 0);
        }

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

        return $this->normalizePrice($json, true);
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

        $response = Http::withHeaders($this->headerMaking())
            ->post($this->base_url . "/air/book/reservation/reservations/$session_id", $body)
            ->throw()
            ->json();

        $error = Arr::get($response, 'ReservationResponse.Result.Error.0');

        if ($error) {
            $message = trim(
                (Arr::get($error, 'category') ? Arr::get($error, 'category') . ': ' : '') .
                Arr::get($error, 'Message', 'Unknown error during booking.')
            );

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        return $response;
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
