<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FlightSearchAggregator;
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
        ]);

        

        // STEP 3: Send to Aggregator → cache, rules, suppliers
        $offers = $this->aggregator->search($agent, $data);

        return response()->json( 
             $offers,
        );
    }
}
