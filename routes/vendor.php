<?php

use App\Http\Controllers\Vendor\Auth\AuthController;
use App\Http\Controllers\Vendor\BankAccountController;
use App\Http\Controllers\Vendor\CampaignController;
use App\Http\Controllers\Vendor\CampaignVoucherController;
use App\Http\Controllers\Vendor\DashboardController;
use App\Http\Controllers\Vendor\DocumentController;
use App\Http\Controllers\Vendor\DocumentUploadController;
use App\Http\Controllers\Vendor\Auth\PasswordResetController;
use App\Http\Controllers\Vendor\NotificationController;
use App\Http\Controllers\Vendor\ProfileController;
use Illuminate\Support\Facades\Route;

// Keep the hub's familiar entry URL, protected by the vendor session.
Route::get('npc-statuses', fn () => redirect()->route('vendor.npc-statuses.index'))
    ->middleware(['auth:vendor', 'vendor.active', 'vendor.cashier'])->name('npc-statuses.index');

// The vendor login is the portal's public entry point, so it lives at the site
// root (/login) rather than under /vendor. Name stays `vendor.login` so all
// route('vendor.login') references keep working.
Route::middleware('guest:vendor')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('vendor.login');
    Route::post('login', [AuthController::class, 'login']);

    // Self-service password reset, on the vendor broker. Kept beside the login
    // it belongs to rather than under /vendor, the registration area.
    Route::get('forgot-password', [PasswordResetController::class, 'showRequest'])->name('vendor.password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->name('vendor.password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('vendor.password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('vendor.password.update');
});

Route::prefix('vendor')->name('vendor.')->group(function () {
    // Guest (vendor) registration stays under /vendor.
    Route::middleware('guest:vendor')->group(function () {
        Route::get('register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('register', [AuthController::class, 'register']);

        // Email OTP: the account exists but holds no session until verified, so
        // these stay in the guest group and identify the vendor via the session.
        Route::get('verify-otp', [AuthController::class, 'showOtp'])->name('otp.show');
        Route::post('verify-otp', [AuthController::class, 'verifyOtp'])->name('otp.verify');
        Route::post('verify-otp/resend', [AuthController::class, 'resendOtp'])->name('otp.resend');
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

        // Accreditation documents — suppliers and partners only. A cashier is a
        // store till: it is never accredited, so it never uploads these.
        Route::middleware('vendor.trading')->group(function () {
            Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
            Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
        });

        Route::post('bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
        Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
        Route::delete('bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])->name('bank-accounts.destroy');

        Route::put('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::put('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        // Transactions require a fully active (approved) vendor account
        Route::middleware('vendor.active')->group(function () {
            Route::middleware('vendor.cashier')->prefix('npc-statuses')->name('npc-statuses.')->group(function () {
                $controller = \App\Http\Controllers\Vendor\NpcStatusController::class;
                Route::get('/', [$controller, 'index'])->name('index');
                Route::get('{npcStatus}/stores/{store}/seals/{type}/download', [$controller, 'downloadStoreSeal'])
                    ->whereIn('type', \App\Models\NpcStatusAttachment::SEAL_TYPES)->name('stores.seal.download');
                Route::post('{npcStatus}/stores/{store}/seals/{type}/proof', [$controller, 'uploadStoreProof'])
                    ->whereIn('type', \App\Models\NpcStatusAttachment::SEAL_TYPES)->name('stores.proof.upload');
                Route::get('{npcStatus}/stores/{store}/seals/{type}/proof', [$controller, 'downloadStoreProof'])
                    ->whereIn('type', \App\Models\NpcStatusAttachment::SEAL_TYPES)->name('stores.proof.download');
            });
            // OCR document intake (upload instead of encoding)
            Route::get('accounts-payable', [\App\Http\Controllers\Vendor\AccountsPayableController::class, 'index'])
                ->middleware('vendor.trading')
                ->name('accounts-payable.index');

            // Campaigns — the loyalty stamps counter. `vendor.cashier` is the
            // real boundary: hiding the nav item proves nothing, so a supplier
            // typing the URL is refused here.
            Route::middleware('vendor.cashier')->prefix('campaigns')->name('campaigns.')->group(function () {
                Route::get('/', [CampaignController::class, 'index'])->name('index');

                Route::get('assets-at-location', [CampaignController::class, 'assetsAtLocation'])->name('assets-at-location');
                Route::get('assets/{asset}/units-at-location', [CampaignController::class, 'unitsAtLocation'])->name('assets.units-at-location');

                Route::post('scan/resolve', [CampaignController::class, 'resolveScan'])->name('scan.resolve');
                Route::post('scan/add-stamp', [CampaignController::class, 'scanAddStamp'])->name('scan.add-stamp');
                Route::post('scan/resolve-redeem', [CampaignController::class, 'resolveRedeemScan'])->name('scan.resolve-redeem');

                Route::get('cards/{card}/entries', [CampaignController::class, 'cardEntries'])->name('cards.entries');
                Route::post('cards/{card}/add-stamps', [CampaignController::class, 'addStamps'])->name('cards.add-stamps');
                Route::post('cards/{card}/record-purchase', [CampaignController::class, 'recordPurchase'])->name('cards.record-purchase');
                Route::post('cards/{card}/redeem', [CampaignController::class, 'redeem'])->name('cards.redeem');

                Route::post('vouchers/verify', [CampaignVoucherController::class, 'verify'])->name('vouchers.verify');
                Route::post('vouchers/redeem', [CampaignVoucherController::class, 'redeem'])->name('vouchers.redeem');
            });

            // Invoice/PO intake — trading accounts only, same reason as above.
            Route::middleware('vendor.trading')->group(function () {
                Route::get('document-uploads', [DocumentUploadController::class, 'index'])->name('document-uploads.index');
                Route::get('document-uploads/create', [DocumentUploadController::class, 'create'])->name('document-uploads.create');
                Route::post('document-uploads', [DocumentUploadController::class, 'store'])->name('document-uploads.store');
                Route::get('document-uploads/{documentUpload}', [DocumentUploadController::class, 'show'])->name('document-uploads.show');
                Route::get('document-uploads/{documentUpload}/file', [DocumentUploadController::class, 'file'])->name('document-uploads.file');
                Route::put('document-uploads/{documentUpload}/cancel', [DocumentUploadController::class, 'cancel'])->name('document-uploads.cancel');
            });
        });
    });
});
