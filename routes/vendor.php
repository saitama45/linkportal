<?php

use App\Http\Controllers\Vendor\Auth\AuthController;
use App\Http\Controllers\Vendor\BankAccountController;
use App\Http\Controllers\Vendor\DashboardController;
use App\Http\Controllers\Vendor\DocumentController;
use App\Http\Controllers\Vendor\DocumentUploadController;
use App\Http\Controllers\Vendor\NotificationController;
use App\Http\Controllers\Vendor\ProfileController;
use Illuminate\Support\Facades\Route;

// The vendor login is the portal's public entry point, so it lives at the site
// root (/login) rather than under /vendor. Name stays `vendor.login` so all
// route('vendor.login') references keep working.
Route::middleware('guest:vendor')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('vendor.login');
    Route::post('login', [AuthController::class, 'login']);
});

Route::prefix('vendor')->name('vendor.')->group(function () {
    // Guest (vendor) registration stays under /vendor.
    Route::middleware('guest:vendor')->group(function () {
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
            // OCR document intake (upload instead of encoding)
            Route::get('accounts-payable', [\App\Http\Controllers\Vendor\AccountsPayableController::class, 'index'])->name('accounts-payable.index');

            Route::get('document-uploads', [DocumentUploadController::class, 'index'])->name('document-uploads.index');
            Route::get('document-uploads/create', [DocumentUploadController::class, 'create'])->name('document-uploads.create');
            Route::post('document-uploads', [DocumentUploadController::class, 'store'])->name('document-uploads.store');
            Route::get('document-uploads/{documentUpload}', [DocumentUploadController::class, 'show'])->name('document-uploads.show');
            Route::put('document-uploads/{documentUpload}/cancel', [DocumentUploadController::class, 'cancel'])->name('document-uploads.cancel');
        });
    });
});
