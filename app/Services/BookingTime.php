<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class BookingTime
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function parse(array $data): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $data['date'].' '.$data['start_time'], 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', $data['date'].' '.$data['end_time'], 'UTC');
        if ($start->lte(now()) || $end->lte($start)) {
            throw ValidationException::withMessages(['start_time' => 'Choose a future start time and a later end time on the same day.']);
        }

        return [$start, $end];
    }
}
