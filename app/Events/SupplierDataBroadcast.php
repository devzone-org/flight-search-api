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
    public $channel_id;

    public function __construct(array $data, $channel_id)
    {
        $this->data = $data;
        $this->channel_id = $channel_id;
    }

    public function broadcastOn()
    {
        return new Channel('supplier-data' . $this->channel_id);
    }

    public function broadcastAs()
    {
        return 'supplier.updated';
    }
}
