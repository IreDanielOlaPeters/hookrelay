<?php

use Illuminate\Support\Carbon;

if (! function_exists('endOfIfDate')) {
    /** Upper bound for a date filter: a bare "YYYY-MM-DD" means the end of that day. */
    function endOfIfDate(string $value): Carbon
    {
        $date = Carbon::parse($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $date->endOfDay() : $date;
    }
}
