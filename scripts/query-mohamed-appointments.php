<?php

use App\Models\Appointment;
use App\Models\User;
use App\Services\DoctorAvailabilityService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$doctors = User::withoutGlobalScopes()
    ->whereRaw('LOWER(name) LIKE ?', ['%mohamed%'])
    ->get(['id', 'name', 'tenant_id']);

echo 'Doctors matching mohamed: '.$doctors->count().PHP_EOL;

foreach ($doctors as $doctor) {
    echo "Doctor: {$doctor->name} ({$doctor->id}) tenant={$doctor->tenant_id}".PHP_EOL;

    $counts = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->selectRaw('status, count(*) as c')
        ->groupBy('status')
        ->pluck('c', 'status');

    echo 'Status counts: '.json_encode($counts).PHP_EOL;

    $checkedIn = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('status', 'checked_in')
        ->orderBy('scheduled_at')
        ->get(['id', 'scheduled_at', 'checked_in_at', 'updated_at']);

    echo 'checked_in rows: '.$checkedIn->count().PHP_EOL;
    foreach ($checkedIn as $appointment) {
        echo "  id={$appointment->id} scheduled={$appointment->scheduled_at} checked_in_at={$appointment->checked_in_at} updated={$appointment->updated_at}".PHP_EOL;
    }

    $inProgress = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('status', 'in_progress')
        ->orderBy('scheduled_at')
        ->get(['id', 'scheduled_at', 'started_at', 'checked_in_at', 'updated_at']);

    echo 'in_progress rows: '.$inProgress->count().PHP_EOL;
    foreach ($inProgress as $appointment) {
        echo "  id={$appointment->id} scheduled={$appointment->scheduled_at} started_at={$appointment->started_at} checked_in_at={$appointment->checked_in_at}".PHP_EOL;
    }

    $nullChecked = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('status', 'checked_in')
        ->whereNull('checked_in_at')
        ->count();

    $nullStarted = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('status', 'in_progress')
        ->whereNull('started_at')
        ->count();

    echo "null checked_in_at for checked_in status: {$nullChecked}".PHP_EOL;
    echo "null started_at for in_progress status: {$nullStarted}".PHP_EOL;

    $todayStart = Carbon::now()->startOfDay();
    $todayEnd = Carbon::now()->endOfDay();

    $waitingOld = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('status', 'checked_in')
        ->whereBetween('scheduled_at', [$todayStart, $todayEnd])
        ->count();

    $waitingNew = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('status', 'checked_in')
        ->whereNotNull('checked_in_at')
        ->whereBetween('checked_in_at', [$todayStart, $todayEnd])
        ->count();

    $scheduledToday = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->whereBetween('scheduled_at', [$todayStart, $todayEnd])
        ->count();

    echo "OLD waiting_count proxy (checked_in + scheduled_at today): {$waitingOld}".PHP_EOL;
    echo "NEW waiting_count proxy (checked_in_at today): {$waitingNew}".PHP_EOL;
    echo "Appointments with scheduled_at today (any status): {$scheduledToday}".PHP_EOL;

    app(CurrentTenant::class)->set($doctor->tenant_id);
    $service = app(DoctorAvailabilityService::class);
    $fromIso = Carbon::now()->startOfDay()->toIso8601String();
    $toIso = Carbon::now()->endOfDay()->toIso8601String();
    $window = $service->resolveWindow($fromIso, $toIso);
    $row = $service
        ->listForTenant($doctor->tenant_id, $window['from'], $window['to'], $window['weekday'])
        ->firstWhere('id', $doctor->id);

    if ($row) {
        echo 'DoctorAvailabilityService waiting_count (UTC day bounds): '.$row->waiting_count.PHP_EOL;
        echo 'DoctorAvailabilityService in_visit: '.($row->in_visit ? 'yes' : 'no').PHP_EOL;
    }

    $localFrom = '2026-10-06T21:00:00.000Z';
    $localTo = '2026-10-07T20:59:59.000Z';
    $localWindow = $service->resolveWindow($localFrom, $localTo);
    $localRow = $service
        ->listForTenant($doctor->tenant_id, $localWindow['from'], $localWindow['to'], $localWindow['weekday'])
        ->firstWhere('id', $doctor->id);
    if ($localRow) {
        echo "DoctorAvailabilityService waiting_count (frontend Oct-7 local window): {$localRow->waiting_count}".PHP_EOL;
    }
}
