<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TutorController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::get('subjects', [SubjectController::class, 'index']);
    Route::get('tutors', [TutorController::class, 'index']);
    Route::get('tutors/{tutor}', [TutorController::class, 'show']);
    Route::get('tutors/{tutor}/availability', [TutorController::class, 'availability'])->name('tutors.availability');

    Route::prefix('tutor')->middleware('role:tutor')->group(function (): void {
        Route::get('profile', [TutorController::class, 'profile']);
        Route::put('profile', [TutorController::class, 'update']);
        Route::get('availability', [AvailabilityController::class, 'index']);
        Route::post('availability', [AvailabilityController::class, 'store']);
        Route::put('availability/{availability}', [AvailabilityController::class, 'update']);
        Route::delete('availability/{availability}', [AvailabilityController::class, 'destroy']);
    });

    Route::prefix('bookings')->group(function (): void {
        Route::get('/', [BookingController::class, 'index']);
        Route::post('/', [BookingController::class, 'store'])->middleware('role:student');
        Route::get('{booking}', [BookingController::class, 'show']);
        Route::patch('{booking}/cancel', [BookingController::class, 'cancel'])->middleware('role:student,tutor');
        Route::patch('{booking}/reschedule', [BookingController::class, 'reschedule'])->middleware('role:student');
        Route::patch('{booking}/confirm', [BookingController::class, 'confirm'])->middleware('role:tutor');
        Route::patch('{booking}/reject', [BookingController::class, 'reject'])->middleware('role:tutor');
        Route::patch('{booking}/complete', [BookingController::class, 'complete'])->middleware('role:tutor');
    });

    Route::prefix('admin')->middleware('role:admin')->group(function (): void {
        Route::get('users', [AdminController::class, 'users']);
        Route::patch('users/{user}/status', [AdminController::class, 'updateStatus']);
        Route::get('bookings', [BookingController::class, 'index']);
    });
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'read']);
});
