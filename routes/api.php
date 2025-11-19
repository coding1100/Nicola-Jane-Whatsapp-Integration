<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsAppController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Health check endpoint
Route::get('/', [WhatsAppController::class, 'healthCheck']);
Route::get('/health', [WhatsAppController::class, 'healthCheck']);

// WhatsApp Bridge API endpoints
Route::post('/send', [WhatsAppController::class, 'send']);
Route::post('/incoming', [WhatsAppController::class, 'incoming']);
Route::post('/status', [WhatsAppController::class, 'status']);
Route::post('/onboard', [WhatsAppController::class, 'onboard']);
Route::get('/onboard/qr', [WhatsAppController::class, 'getQRCode']);
