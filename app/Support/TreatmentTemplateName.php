<?php

namespace App\Support;

final class TreatmentTemplateName
{
    public static function normalize(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }

        return mb_strtolower(preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed);
    }
}
