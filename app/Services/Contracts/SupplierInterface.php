<?php

namespace App\Services\Contracts;

interface SupplierInterface
{
    public function code(): string; // 'travelport', 'sabre'

    public function searchFlights(array $search): array;
    // Later: bookFlight(), priceFlight(), cancelBooking(), etc.
    
}
