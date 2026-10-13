<?php

namespace Tests\Unit\Services;

use App\Enums\OdontogramToothState;
use App\Enums\TreatmentToothStatus;
use App\Services\OdontogramService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OdontogramServiceTest extends TestCase
{
    private OdontogramService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OdontogramService::class);
    }

    public function test_done_extraction_service_shows_done_state(): void
    {
        $state = $this->service->deriveState([
            $this->row(toothStatus: TreatmentToothStatus::DONE),
        ]);

        $this->assertSame(OdontogramToothState::DONE, $state);
    }

    public function test_in_progress_beats_planned_and_done(): void
    {
        $state = $this->service->deriveState([
            $this->row(toothStatus: TreatmentToothStatus::PLANNED),
            $this->row(toothStatus: TreatmentToothStatus::DONE),
            $this->row(toothStatus: TreatmentToothStatus::IN_PROGRESS),
        ]);

        $this->assertSame(OdontogramToothState::IN_PROGRESS, $state);
    }

    public function test_planned_beats_done_when_no_in_progress(): void
    {
        $state = $this->service->deriveState([
            $this->row(toothStatus: TreatmentToothStatus::DONE, completedAt: '2026-01-01 10:00:00'),
            $this->row(toothStatus: TreatmentToothStatus::PLANNED),
        ]);

        $this->assertSame(OdontogramToothState::PLANNED, $state);
    }

    public function test_done_when_latest_relevant_row_is_done(): void
    {
        $state = $this->service->deriveState([
            $this->row(
                toothStatus: TreatmentToothStatus::DONE,
                completedAt: '2026-01-01 09:00:00',
                updatedAt: '2026-01-01 09:00:00',
            ),
            $this->row(
                toothStatus: TreatmentToothStatus::DONE,
                completedAt: '2026-01-02 09:00:00',
                updatedAt: '2026-01-02 09:00:00',
            ),
        ]);

        $this->assertSame(OdontogramToothState::DONE, $state);
    }

    public function test_none_when_no_relevant_rows(): void
    {
        $this->assertSame(OdontogramToothState::NONE, $this->service->deriveState([]));
    }

    public function test_skipped_status_does_not_contribute_to_state(): void
    {
        $state = $this->service->deriveState([
            $this->row(toothStatus: TreatmentToothStatus::SKIPPED),
        ]);

        $this->assertSame(OdontogramToothState::NONE, $state);
    }

    public function test_multiple_treatments_on_one_tooth_are_all_listed_with_row_id(): void
    {
        $payload = $this->service->buildPayload(collect([
            $this->row(patientTreatmentId: 't-1', toothRowId: 'row-1', toothStatus: TreatmentToothStatus::PLANNED, serviceEn: 'Filling'),
            $this->row(patientTreatmentId: 't-2', toothRowId: 'row-2', toothStatus: TreatmentToothStatus::IN_PROGRESS, serviceEn: 'Crown'),
        ]));

        $this->assertCount(2, $payload['teeth']['16']['treatments']);
        $this->assertSame('row-1', $payload['teeth']['16']['treatments'][0]['tooth_row_id']);
        $this->assertSame('in_progress', $payload['teeth']['16']['state']);
    }

    #[DataProvider('legacyMapProvider')]
    public function test_legacy_treatment_status_map(OdontogramToothState $state, ?string $legacy): void
    {
        $this->assertSame($legacy, $state->legacyTreatmentStatusKey());
    }

    /**
     * @return array<string, array{0: OdontogramToothState, 1: ?string}>
     */
    public static function legacyMapProvider(): array
    {
        return [
            'in_progress' => [OdontogramToothState::IN_PROGRESS, 'in_progress'],
            'planned' => [OdontogramToothState::PLANNED, 'planned'],
            'done' => [OdontogramToothState::DONE, 'completed'],
            'none' => [OdontogramToothState::NONE, null],
        ];
    }

    private function row(
        string $toothNumber = '16',
        TreatmentToothStatus $toothStatus = TreatmentToothStatus::PLANNED,
        ?string $completedAt = null,
        string $updatedAt = '2026-01-01 12:00:00',
        string $patientTreatmentId = 'pt-1',
        string $toothRowId = 'ptt-1',
        string $serviceEn = 'Service',
    ): object {
        return (object) [
            'tooth_row_id' => $toothRowId,
            'tooth_number' => $toothNumber,
            'tooth_status' => $toothStatus->value,
            'price' => '100.00',
            'completed_at' => $completedAt,
            'row_updated_at' => $updatedAt,
            'patient_treatment_id' => $patientTreatmentId,
            'service_name_ar' => 'خدمة',
            'service_name_en' => $serviceEn,
        ];
    }
}
