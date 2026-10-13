<?php

namespace App\Services;

use App\Enums\OdontogramToothState;
use App\Enums\PatientTreatmentStatus;
use App\Enums\TreatmentToothStatus;
use App\Models\Patient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OdontogramService
{
    /**
     * @return array{
     *     teeth: array<string, array{state: string, treatments: list<array<string, mixed>>}>,
     *     treatment_status_by_tooth: array<string, string>,
     *     records: list<mixed>
     * }
     */
    public function buildForPatient(Patient $patient): array
    {
        $rows = $this->fetchTreatmentTeeth($patient);

        return $this->buildPayload($rows);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    public function fetchTreatmentTeeth(Patient $patient): Collection
    {
        return DB::table('patient_treatment_teeth as ptt')
            ->join('patient_treatments as pt', 'pt.id', '=', 'ptt.patient_treatment_id')
            ->leftJoin('treatment_templates as tt', 'tt.id', '=', 'pt.treatment_template_id')
            ->where('pt.patient_id', $patient->id)
            ->where('pt.tenant_id', $patient->tenant_id)
            ->where('pt.clinical_status', '!=', PatientTreatmentStatus::CANCELLED->value)
            ->where('ptt.status', '!=', TreatmentToothStatus::SKIPPED->value)
            ->orderBy('ptt.tooth_number')
            ->select([
                'ptt.id as tooth_row_id',
                'ptt.tooth_number',
                'ptt.status as tooth_status',
                'ptt.price',
                'ptt.completed_at',
                'ptt.updated_at as row_updated_at',
                'pt.id as patient_treatment_id',
                'tt.name_ar as service_name_ar',
                'tt.name_en as service_name_en',
            ])
            ->get();
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array{
     *     teeth: array<string, array{state: string, treatments: list<array<string, mixed>>}>,
     *     treatment_status_by_tooth: array<string, string>,
     *     records: list<mixed>
     * }
     */
    public function buildPayload(Collection $rows): array
    {
        /** @var array<string, list<\stdClass>> $byTooth */
        $byTooth = [];
        foreach ($rows as $row) {
            $byTooth[(string) $row->tooth_number][] = $row;
        }

        $teeth = [];
        $legacyMap = [];

        foreach ($byTooth as $toothNumber => $entries) {
            $state = $this->deriveState($entries);
            $teeth[$toothNumber] = [
                'state' => $state->value,
                'treatments' => $this->mapTreatments($entries),
            ];

            $legacyKey = $state->legacyTreatmentStatusKey();
            if ($legacyKey !== null) {
                $legacyMap[$toothNumber] = $legacyKey;
            }
        }

        return [
            'teeth' => $teeth,
            'treatment_status_by_tooth' => $legacyMap,
            'records' => [],
        ];
    }

    /**
     * @param  list<\stdClass>  $entries
     * @return list<array<string, mixed>>
     */
    private function mapTreatments(array $entries): array
    {
        return array_map(static function (\stdClass $row): array {
            return [
                'id' => $row->patient_treatment_id,
                'tooth_row_id' => $row->tooth_row_id,
                'service_name_ar' => $row->service_name_ar,
                'service_name_en' => $row->service_name_en,
                'status' => $row->tooth_status,
                'price' => number_format((float) $row->price, 2, '.', ''),
            ];
        }, $entries);
    }

    /**
     * @param  list<\stdClass>  $entries
     */
    public function deriveState(array $entries): OdontogramToothState
    {
        if ($this->hasStatus($entries, TreatmentToothStatus::IN_PROGRESS)) {
            return OdontogramToothState::IN_PROGRESS;
        }

        if ($this->hasStatus($entries, TreatmentToothStatus::PLANNED)) {
            return OdontogramToothState::PLANNED;
        }

        $latestDone = $this->latestDoneEntry($entries);
        if ($latestDone !== null) {
            return OdontogramToothState::DONE;
        }

        return OdontogramToothState::NONE;
    }

    /**
     * @param  list<\stdClass>  $entries
     */
    private function hasStatus(array $entries, TreatmentToothStatus $status): bool
    {
        foreach ($entries as $row) {
            if ($row->tooth_status === $status->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<\stdClass>  $entries
     */
    private function latestDoneEntry(array $entries): ?\stdClass
    {
        $done = array_values(array_filter(
            $entries,
            fn (\stdClass $row) => $row->tooth_status === TreatmentToothStatus::DONE->value
        ));

        if ($done === []) {
            return null;
        }

        usort($done, function (\stdClass $a, \stdClass $b): int {
            $aTime = $a->completed_at ?? $a->row_updated_at;
            $bTime = $b->completed_at ?? $b->row_updated_at;

            return strcmp((string) $bTime, (string) $aTime);
        });

        return $done[0];
    }
}
