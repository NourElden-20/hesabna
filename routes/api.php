<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\SettlementController;
use Illuminate\Support\Facades\Route;

// Public
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

// Protected
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // Groups
    Route::get('groups', [GroupController::class, 'index']);
    Route::post('groups', [GroupController::class, 'store']);
    Route::post('groups/join/{token}', [GroupController::class, 'join']); // ← قبل {id}
    Route::get('groups/{id}', [GroupController::class, 'show']);
    Route::post('groups/{id}/members', [GroupController::class, 'addMember']);
    Route::post('groups/{id}/guests', [GroupController::class, 'addGuest']);
    Route::get('groups/{id}/balances', [GroupController::class, 'getBalances']);

    // Expenses
    Route::get('groups/{id}/expenses', [ExpenseController::class, 'index']);
    Route::post('groups/{id}/expenses', [ExpenseController::class, 'store']);
    Route::delete('expenses/{id}', [ExpenseController::class, 'destroy']);

    // Settlements
    Route::get('groups/{id}/settlements', [SettlementController::class, 'index']);
    Route::post('groups/{id}/settlements', [SettlementController::class, 'store']);

});
