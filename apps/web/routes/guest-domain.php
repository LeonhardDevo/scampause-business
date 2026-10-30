<?php

declare(strict_types=1);

use App\Http\Controllers\Domain\EmployeeResponseController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('employee-response/{token}', [EmployeeResponseController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{48}')
        ->middleware(['signed', 'throttle:30,1'])
        ->name('employee-response.show');
    Route::post('employee-response/{token}', [EmployeeResponseController::class, 'store'])
        ->where('token', '[A-Za-z0-9]{48}')
        ->middleware('throttle:10,1')
        ->name('employee-response.store');
});
