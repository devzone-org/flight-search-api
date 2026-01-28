<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\FlightSearchAggregator;
use App\Services\Suppliers\TravelportProvider;
use Illuminate\Http\Request;

class FlightSearchController extends Controller
{
    protected FlightSearchAggregator $aggregator;

    public function __construct(FlightSearchAggregator $aggregator)
    {
        $this->aggregator = $aggregator;
    }

    /**
     * STEP 2: Controller runs after Route.
     * Purpose:
     *  - Validate API request
     *  - Take authenticated agent
     *  - Pass sanitized request to Aggregator
     */
    public function search(Request $request)
    {
        $agent = $request->user(); // Authenticated agent from middleware

        // Validate request fields coming from agent portal
        $data = $request->validate([
            'from'      => 'required|string|size:3',
            'to'        => 'required|string|size:3',
            'departure' => 'required|date',
            'return'    => 'nullable|date',
            'trip_type' => 'required|in:oneway,roundtrip',
            'adults'    => 'required|integer|min:1',
            'children'  => 'nullable|integer|min:0',
            'infants'   => 'nullable|integer|min:0',
            'channel_id'    => 'required|string',
        ]);

        // STEP 3: Send to Aggregator → cache, rules, suppliers
        $offers = $this->aggregator->search($agent, $data);

        return response()->json(
             $offers,
        );
    }

    public function quoteRequest(Request $request)
    {
        $agent = $request->user();

        $data = $request->validate([
            'search_id'       => 'required|string',
            'offer_id'       => 'required|string',
            'product_ref'       => 'required|string',
            'return_offer_id'   => 'nullable|string',
            'return_product_ref' => 'nullable|string',
            'supplier'       => 'required|string',
        ]);

        if(strtolower($data['supplier']) == 'travelport'){
            $supplier = Supplier::where('code', 'travelport')->first();
            if(!empty($supplier)){
               $provider = new TravelportProvider($supplier);
                $quote = $provider->getQuote($data);

                return response()->json(
                    $quote,
                );
            }
        }
    }

    public function bookRequest(Request $request)
    {
        $agent = $request->user();

        $data = $request->validate([
            'search_id'       => 'required|string',
            'offer_id'       => 'required|string',
            'product_ref'       => 'required|string',
            'return_offer_id'   => 'nullable|string',
            'return_product_ref' => 'nullable|string',
            'supplier'       => 'required|string',
            'contact.email' => 'required|email',
            'contact.phone' => 'required|string',
            'passengers' => 'required|array',
        ]);

        if(strtolower($data['supplier']) == 'travelport'){
            $supplier = Supplier::where('code', 'travelport')->first();
            if(!empty($supplier)){
               $provider = new TravelportProvider($supplier);
                $booking = $provider->bookFlight($data);

                return response()->json(
                    $booking,
                );
            }
        }
    }
}
