<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\Suppliers\TravelportProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SupplierWiseSearchFlight implements ShouldQueue
{
    use Queueable;
    public
        $supplier,
        $normalized = [];
    public function __construct($supplier, $normalized)
    {
        $this->supplier = $supplier;
        $this->normalized = $normalized;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $offers = [];
        // For Travel Port
        if ($this->supplier['code'] === 'travelport') {
            $provider = new TravelportProvider($this->supplier);
        }

        if(!empty($provider)){
            $offers = $provider->searchFlights($this->normalized);
        }

        if(!empty($offers)){

            broadcast(new \App\Events\SupplierDataBroadcast([
                'supplier_code' => $this->supplier['code'],
                'offers' => '',
            ]));
        }
    }
}
