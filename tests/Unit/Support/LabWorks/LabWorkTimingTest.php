<?php

namespace Tests\Unit\Support\LabWorks;

use App\Enums\LabWorkStatus;
use App\Support\LabWorks\LabWorkTiming;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LabWorkTimingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_overdue_follows_the_clinic_date_when_utc_is_still_the_previous_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 23:30:00', 'UTC'));

        $utcDate = Carbon::now('UTC')->toDateString();
        $clinicDate = LabWorkTiming::todayDateString();

        $this->assertNotSame($utcDate, $clinicDate);

        $timing = LabWorkTiming::describe(LabWorkStatus::AT_LAB, $utcDate, null);

        $this->assertSame('overdue', $timing['kind']);
        $this->assertSame(1, $timing['days']);
    }

    public function test_due_this_week_ends_on_sunday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', LabWorkTiming::clinicTimezone()));

        $this->assertSame('2026-10-21', LabWorkTiming::todayDateString());
        $this->assertSame('2026-10-25', LabWorkTiming::endOfWeekDateString());
    }

    public function test_received_case_reports_days_late_or_on_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', LabWorkTiming::clinicTimezone()));

        $late = LabWorkTiming::describe(LabWorkStatus::RECEIVED, '2026-10-18', '2026-10-21');
        $onTime = LabWorkTiming::describe(LabWorkStatus::RECEIVED, '2026-10-21', '2026-10-20');

        $this->assertSame(['kind' => 'late', 'days' => 3], $late);
        $this->assertSame(['kind' => 'on_time', 'days' => 0], $onTime);
    }

    public function test_due_in_days_uses_the_clinic_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', LabWorkTiming::clinicTimezone()));

        $timing = LabWorkTiming::describe(LabWorkStatus::AT_LAB, '2026-10-24', null);

        $this->assertSame(['kind' => 'due_in', 'days' => 3], $timing);
        $this->assertSame(
            ['kind' => 'due_today', 'days' => 0],
            LabWorkTiming::describe(LabWorkStatus::AT_LAB, '2026-10-21', null)
        );
    }
}
