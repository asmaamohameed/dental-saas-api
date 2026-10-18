<?php

namespace App\Enums;

enum RefundReasonCode: string
{
    case TreatmentCancelledByPatient = 'treatment_cancelled_by_patient';
    case TreatmentCancelledByClinic = 'treatment_cancelled_by_clinic';
    case PatientDissatisfied = 'patient_dissatisfied';
    case DuplicatePayment = 'duplicate_payment';
    case IncorrectPayment = 'incorrect_payment';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
