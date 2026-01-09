<?php

namespace Workbench\App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderShipped implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $orderId,
        public int $userId,
        public string $trackingNumber,
        public array $items = []
    ) {}

    /**
     * Get the channels the event should broadcast on.
     * Using private channels for sensitive order information.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("orders.{$this->userId}"),
            new PrivateChannel("order.{$this->orderId}"),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'tracking_number' => $this->trackingNumber,
            'items_count' => count($this->items),
            'items' => $this->items,
            'shipped_at' => now()->toIso8601String(),
            'timestamp' => now()->timestamp,
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'order.shipped';
    }

    /**
     * Determine if this event should broadcast.
     */
    public function broadcastWhen(): bool
    {
        return ! empty($this->trackingNumber);
    }

    /**
     * Get the tags that should be assigned to the queued broadcast job.
     */
    public function broadcastTags(): array
    {
        return ['broadcasts', 'orders', "user:{$this->userId}"];
    }

    /**
     * Determine the time at which the broadcasting job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(5);
    }
}
