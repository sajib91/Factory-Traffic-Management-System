<?php

use App\Http\Controllers\TrafficApiController;
use Illuminate\Support\Facades\Route;

Route::get('/junctions', [TrafficApiController::class, 'index']);
Route::post('/junctions', [TrafficApiController::class, 'store']);
Route::get('/junctions/{id}', [TrafficApiController::class, 'show']);
Route::get('/junctions/{id}/status', [TrafficApiController::class, 'status']);
Route::get('/junctions/{id}/history', [TrafficApiController::class, 'history']);
Route::post('/junctions/{id}/commands', [TrafficApiController::class, 'command']);
Route::post('/sensor-events', [TrafficApiController::class, 'sensorEvent']);
Route::post('/controller-events', [TrafficApiController::class, 'controllerEvent']);
