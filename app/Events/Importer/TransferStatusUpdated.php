<?php

namespace Pterodactyl\Events\Importer;

use Pterodactyl\Events\Event;
use Illuminate\Queue\SerializesModels;
use Pterodactyl\Models\ImporterTransfer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class TransferStatusUpdated extends Event implements ShouldBroadcast
{
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public ImporterTransfer $transfer)
    {
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('server.' . $this->transfer->server->uuid),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->transfer->id,
            'status' => $this->transfer->status,
            'bytes_transferred' => $this->transfer->bytes_transferred,
            'bytes_total' => $this->transfer->bytes_total,
            'speed' => $this->transfer->speed,
            'eta' => $this->transfer->eta,
            'completed_at' => $this->transfer->completed_at ? $this->transfer->completed_at->toIso8601String() : null,
        ];
    }
}
