<?php

use Illuminate\Support\Facades\Route;
use janboddez\IndieAuth\Http\Controllers\IndieAuthController;

Route::prefix('indieauth')
    ->group(function () {
        Route::middleware('web')
            ->get('/metadata', [IndieAuthController::class, 'metadata']);

        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('/', [IndieAuthController::class, 'start']);
            Route::post('/', [IndieAuthController::class, 'approve']);
        });

        Route::middleware('api')
            ->post('/token', [IndieAuthController::class, 'issueToken']);

        Route::middleware(['api', 'auth:sanctum'])->group(function () {
            Route::get('/token', [IndieAuthController::class, 'verifyToken']);
            Route::post('/token/revocation', [IndieAuthController::class, 'revokeToken']);
        });
    });
