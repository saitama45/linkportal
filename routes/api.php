<?php

use App\Http\Controllers\Api\IntegrationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// App-to-app integration endpoints (Sanctum PATs from portal_personal_access_tokens)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/integrations/ghelpdesk/document-review-decision', [IntegrationController::class, 'documentReviewDecision'])
        ->name('api.integrations.document-review-decision');

    Route::post('/integrations/accounting/invoice-payment-status', [IntegrationController::class, 'invoicePaymentStatus'])
        ->name('api.integrations.invoice-payment-status');
});
