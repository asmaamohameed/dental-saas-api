<?php

namespace App\Enums;

enum RefundReasonCode: string
{
    case TREATMENT_CANCELLED_BY_PATIENT = 'treatment_cancelled_by_patient';
    case TREATMENT_CANCELLED_BY_CLINIC = 'treatment_cancelled_by_clinic';
    case PATIENT_DISSATISFIED = 'patient_dissatisfied';
    case DUPLICATE_PAYMENT = 'duplicate_payment';
    case INCORRECT_PAYMENT = 'incorrect_payment';
    case OTHER = 'other';

    public const NOTE_MAX_LENGTH = 500;

    /**
     * Stable keys in display order. Labels live in the UI translation layer.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::TREATMENT_CANCELLED_BY_PATIENT,
            self::TREATMENT_CANCELLED_BY_CLINIC,
            self::PATIENT_DISSATISFIED,
            self::DUPLICATE_PAYMENT,
            self::INCORRECT_PAYMENT,
            self::OTHER,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::ordered());
    }

    public function requiresNote(): bool
    {
        return $this === self::OTHER;
    }
}
