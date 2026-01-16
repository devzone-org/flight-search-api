<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierDataBroadcast implements ShouldBroadcast
{
    public array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function broadcastOn()
    {
        return new Channel('supplier-data');
    }

    public function broadcastAs()
    {
        return 'supplier.updated';
    }
}
