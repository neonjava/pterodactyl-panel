<?php

namespace Pterodactyl\Events\Network;

use Illuminate\Queue\SerializesModels;
use Pterodactyl\Events\Event;
use Pterodactyl\Models\NetworkNodeTelemetry;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class TelemetryUpdated extends Event implements ShouldBroadcastNow
{
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public array $telemetry
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return ['network-telemetry'];
    }

    /**
     * Broadcast key details.
     */
    public function broadcastWith(): array
    {
        return $this->telemetry;
    }
}
