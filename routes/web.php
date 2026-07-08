<?php

use App\Http\Controllers\Admin\ApprovalInboxController;
use App\Http\Controllers\Admin\InvoiceReviewController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\PurchaseOrderReviewController;
use App\Http\Controllers\Admin\QuotationReviewController;
use App\Http\Controllers\Admin\VendorController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::resource('users', UserController::class)->except(['create', 'edit', 'show']);
    Route::put('users/{user}/reset-password', [UserController::class, 'resetPassword'])
        ->name('users.reset-password');

    Route::resource('roles', RoleController::class)->except(['show', 'create', 'edit']);
    Route::resource('companies', CompanyController::class)->except(['create', 'edit']);

    // --- Vendor management (internal) ---
    Route::get('vendors', [VendorController::class, 'index'])->name('vendors.index');
    Route::post('vendors', [VendorController::class, 'store'])->name('vendors.store');
    Route::get('vendors/{vendor}', [VendorController::class, 'show'])->name('vendors.show');
    Route::put('vendors/{vendor}', [VendorController::class, 'update'])->name('vendors.update');
    Route::put('vendors/{vendor}/status', [VendorController::class, 'updateStatus'])->name('vendors.status');
    Route::put('vendors/{vendor}/profile-review', [VendorController::class, 'reviewProfile'])->name('vendors.profile-review');
    Route::put('vendor-documents/{document}/review', [VendorController::class, 'reviewDocument'])->name('vendor-documents.review');
    Route::put('vendor-bank-accounts/{bankAccount}/review', [VendorController::class, 'reviewBankAccount'])->name('vendor-bank-accounts.review');

    // --- Product master data ---
    Route::resource('products', ProductController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('product-categories', [ProductController::class, 'storeCategory'])->name('product-categories.store');

    // --- Transaction review (invoices / POs / quotations) ---
    Route::get('invoices', [InvoiceReviewController::class, 'index'])->name('invoices.index');
    Route::get('invoices/create', [InvoiceReviewController::class, 'create'])->name('invoices.create');
    Route::post('invoices', [InvoiceReviewController::class, 'store'])->name('invoices.store');
    Route::get('invoices/{invoice}', [InvoiceReviewController::class, 'show'])->name('invoices.show');
    Route::put('invoices/{invoice}/act', [InvoiceReviewController::class, 'act'])->name('invoices.act');

    Route::get('purchase-orders', [PurchaseOrderReviewController::class, 'index'])->name('purchase-orders.index');
    Route::get('purchase-orders/create', [PurchaseOrderReviewController::class, 'create'])->name('purchase-orders.create');
    Route::post('purchase-orders', [PurchaseOrderReviewController::class, 'store'])->name('purchase-orders.store');
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderReviewController::class, 'show'])->name('purchase-orders.show');
    Route::put('purchase-orders/{purchaseOrder}/act', [PurchaseOrderReviewController::class, 'act'])->name('purchase-orders.act');

    Route::get('quotations', [QuotationReviewController::class, 'index'])->name('quotations.index');
    Route::get('quotations/create', [QuotationReviewController::class, 'create'])->name('quotations.create');
    Route::post('quotations', [QuotationReviewController::class, 'store'])->name('quotations.store');
    Route::get('quotations/{quotation}', [QuotationReviewController::class, 'show'])->name('quotations.show');
    Route::put('quotations/{quotation}/act', [QuotationReviewController::class, 'act'])->name('quotations.act');

    // --- Cross-type approvals inbox ---
    Route::get('approvals', [ApprovalInboxController::class, 'index'])->name('approvals.index');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::delete('profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
});

require __DIR__.'/auth.php';
