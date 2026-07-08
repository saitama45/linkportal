<?php

use App\Http\Controllers\Vendor\Auth\AuthController;
use App\Http\Controllers\Vendor\BankAccountController;
use App\Http\Controllers\Vendor\DashboardController;
use App\Http\Controllers\Vendor\DocumentController;
use App\Http\Controllers\Vendor\InvoiceController;
use App\Http\Controllers\Vendor\NotificationController;
use App\Http\Controllers\Vendor\ProfileController;
use App\Http\Controllers\Vendor\PurchaseOrderController;
use App\Http\Controllers\Vendor\QuotationController;
use Illuminate\Support\Facades\Route;

Route::prefix('vendor')->name('vendor.')->group(function () {
    // Guest (vendor) routes
    Route::middleware('guest:vendor')->group(function () {
        Route::get('login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('login', [AuthController::class, 'login']);
        Route::get('register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('register', [AuthController::class, 'register']);
    });

    // Authenticated vendor routes (pending vendors may complete onboarding)
    Route::middleware('auth:vendor')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
        Route::post('profile/contacts', [ProfileController::class, 'storeContact'])->name('profile.contacts.store');
        Route::delete('profile/contacts/{contact}', [ProfileController::class, 'destroyContact'])->name('profile.contacts.destroy');

        Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

        Route::post('bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
        Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
        Route::delete('bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])->name('bank-accounts.destroy');

        Route::put('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::put('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        // Transactions require a fully active (approved) vendor account
        Route::middleware('vendor.active')->group(function () {
            Route::post('purchase-orders/{purchaseOrder}/acknowledge', [PurchaseOrderController::class, 'acknowledge'])
                ->name('purchase-orders.acknowledge');

            Route::resource('invoices', InvoiceController::class);
            Route::resource('purchase-orders', PurchaseOrderController::class);
            Route::resource('quotations', QuotationController::class);
        });
    });
});
