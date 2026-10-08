<?php

namespace App\DataTransferObjects;

final readonly class DoctorAvailabilityRow
{
    /**
     * @param  'busy'|'free'  $state
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $waiting_count,
        public bool $in_visit,
        public string $state,
        public ?string $next_booking_at,
        public ?bool $works_today,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     waiting_count: int,
     *     in_visit: bool,
     *     state: 'busy'|'free',
     *     next_booking_at: string|null,
     *     works_today: bool|null
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'waiting_count' => $this->waiting_count,
            'in_visit' => $this->in_visit,
            'state' => $this->state,
            'next_booking_at' => $this->next_booking_at,
            'works_today' => $this->works_today,
        ];
    }
}
