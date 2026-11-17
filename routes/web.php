<?php

use App\Http\Controllers\BatchController;
use App\Http\Controllers\BatchExcelController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerGroupController;
use App\Http\Controllers\CustomerWalletTransactionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentTemplateController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OfferInternalNoteController;
use App\Http\Controllers\OfferPdfController;
use App\Http\Controllers\OfferStatusController;
use App\Http\Controllers\PriceController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductExcelController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SystemUserController;
use App\Http\Controllers\WarningController;
use App\Http\Controllers\WarehouseOfferController;
use App\Http\Controllers\WarehouseNotificationController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::redirect('/home', '/dashboard')->name('home');
Route::get('/application-theme.css', [SettingsController::class, 'applicationThemeStyles'])->name('application.theme.css');
Route::get('/login-styles.css', [SettingsController::class, 'loginStyles'])->name('login.styles');
Route::get('/login-background', [SettingsController::class, 'loginBackground'])->name('login.background');
Route::get('/login-logo', [SettingsController::class, 'loginLogo'])->name('login.logo');
Route::get('/site-favicon', [SettingsController::class, 'siteFavicon'])->name('site.favicon');

Route::middleware(['auth'])->group(function () {
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index')->middleware('role:' . User::ROLE_ADMIN);
    Route::put('settings/reservation', [SettingsController::class, 'updateReservation'])->name('settings.reservation.update')->middleware('role:' . User::ROLE_ADMIN);
    Route::put('settings/button-appearance', [SettingsController::class, 'updateButtonAppearance'])->name('settings.button-appearance.update')->middleware('role:' . User::ROLE_ADMIN);
    Route::put('settings/login-appearance', [SettingsController::class, 'updateLoginAppearance'])->name('settings.login-appearance.update')->middleware('role:' . User::ROLE_ADMIN);
    Route::post('settings/customer-groups', [CustomerGroupController::class, 'store'])->name('customer-groups.store')->middleware('role:' . User::ROLE_ADMIN);
    Route::put('settings/customer-groups/{customerGroup}', [CustomerGroupController::class, 'update'])->name('customer-groups.update')->middleware('role:' . User::ROLE_ADMIN);
    Route::delete('settings/customer-groups/{customerGroup}', [CustomerGroupController::class, 'destroy'])->name('customer-groups.destroy')->middleware('role:' . User::ROLE_ADMIN);

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/products/excel/export', [ProductExcelController::class, 'export'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('products.excel.export');
    Route::get('/products/excel/import', [ProductExcelController::class, 'importForm'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('products.excel.import.form');
    Route::post('/products/excel/import', [ProductExcelController::class, 'import'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('products.excel.import');
    Route::post('/products/excel/import/confirm', [ProductExcelController::class, 'confirm'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('products.excel.import.confirm');
    Route::resource('products', ProductController::class)->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE);
    Route::resource('product-categories', ProductCategoryController::class)->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE);
    Route::get('/kategorien', fn () => redirect()->route('product-categories.index'))->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('product-categories.redirect');
    Route::get('/batches/fifo-out', [BatchController::class, 'fifoOutForm'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('batches.fifo-out.form');
    Route::post('/batches/fifo-out', [BatchController::class, 'fifoOut'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('batches.fifo-out.store');
    Route::get('/batches/excel/export', [BatchExcelController::class, 'export'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('batches.excel.export');
    Route::get('/batches/excel/import', [BatchExcelController::class, 'importForm'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('batches.excel.import.form');
    Route::post('/batches/excel/import', [BatchExcelController::class, 'import'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('batches.excel.import');
    Route::post('/batches/excel/import/confirm', [BatchExcelController::class, 'confirm'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('batches.excel.import.confirm');
    Route::resource('batches', BatchController::class)->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE);
    Route::get('/branch-withdrawals', [\App\Http\Controllers\BranchWithdrawalController::class, 'index'])
        ->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)
        ->name('branch-withdrawals.index');
    Route::get('/branch-withdrawals/create', [\App\Http\Controllers\BranchWithdrawalController::class, 'create'])
        ->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)
        ->name('branch-withdrawals.create');
    Route::post('/branch-withdrawals', [\App\Http\Controllers\BranchWithdrawalController::class, 'store'])
        ->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)
        ->name('branch-withdrawals.store');
    Route::get('/branch-withdrawals/{branchWithdrawal}', [\App\Http\Controllers\BranchWithdrawalController::class, 'show'])
        ->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)
        ->name('branch-withdrawals.show');

    Route::get('/offers', [OfferController::class, 'index'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('offers.index');
    Route::get('/offers/create', [OfferController::class, 'create'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('offers.create');
    Route::get('/offers/price-preview', [OfferController::class, 'pricePreview'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('offers.price-preview');
    Route::post('/offers', [OfferController::class, 'store'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('offers.store');
    Route::get('/offers/{offer}', [OfferController::class, 'show'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('offers.show');
    Route::get('/offers/{offer}/edit', [OfferController::class, 'edit'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('offers.edit');
    Route::put('/offers/{offer}', [OfferController::class, 'update'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('offers.update');
    Route::put('/offers/{offer}/status', [OfferStatusController::class, 'update'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('offers.status');
    Route::post('/offers/{offer}/internal-notes', [OfferInternalNoteController::class, 'store'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('offers.internal-notes.store');
    Route::get('/offers/{offer}/pdf/{type}', [OfferPdfController::class, 'stream'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('offers.pdf');
    Route::post('/offers/{offer}/cancel', [OfferController::class, 'cancel'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('offers.cancel');
    Route::delete('/offers/{offer}', [OfferController::class, 'destroy'])->middleware('role:' . User::ROLE_MANAGER)->name('offers.destroy');

    Route::prefix('lager/benachrichtigungen')->name('warehouse.notifications.')->middleware('role:' . User::ROLE_WAREHOUSE)->group(function () {
        Route::get('/', [WarehouseNotificationController::class, 'index'])->name('index');
        Route::get('/{notification}/oeffnen', [WarehouseNotificationController::class, 'open'])->name('open');
    });

    Route::prefix('lager/angebote')->name('warehouse.offers.')->middleware('role:' . User::ROLE_WAREHOUSE)->group(function () {
        Route::get('/', [WarehouseOfferController::class, 'index'])->name('index');
        Route::get('/{offer}', [WarehouseOfferController::class, 'show'])->name('show');
        Route::put('/{offer}/status', [WarehouseOfferController::class, 'updateStatus'])->name('status');
        Route::get('/{offer}/lieferschein', [WarehouseOfferController::class, 'deliveryNote'])->name('delivery-note');
    });

    Route::resource('suppliers', SupplierController::class)->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE);
    Route::post('customers/{customer}/wallet-transactions', [CustomerWalletTransactionController::class, 'store'])->name('customers.wallet-transactions.store')->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES);
    Route::get('/customers', [CustomerController::class, 'index'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES . ',' . User::ROLE_CRM)->name('customers.index');
    Route::get('/customers/create', [CustomerController::class, 'create'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES . ',' . User::ROLE_CRM)->name('customers.create');
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES . ',' . User::ROLE_CRM)->name('customers.store');
    Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES . ',' . User::ROLE_CRM)->name('customers.edit');
    Route::match(['put', 'patch'], '/customers/{customer}', [CustomerController::class, 'update'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES . ',' . User::ROLE_CRM)->name('customers.update');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('customers.show');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('customers.destroy');
    Route::get('/prices', [PriceController::class, 'index'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('prices.index');
    Route::put('/prices', [PriceController::class, 'update'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_SALES)->name('prices.update');

    Route::get('/warnings/export', [WarningController::class, 'export'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('warnings.export');
    Route::get('/warnings', [WarningController::class, 'index'])->middleware('role:' . User::ROLE_MANAGER . ',' . User::ROLE_WAREHOUSE)->name('warnings.index');
    Route::get('/statistics', [StatisticsController::class, 'index'])->middleware('role:' . User::ROLE_MANAGER)->name('statistics.index');
    Route::get('/security', [SecurityController::class, 'index'])->middleware('role:' . User::ROLE_ADMIN)->name('security.index');
    Route::get('/security/users/create', [SystemUserController::class, 'create'])->middleware('role:' . User::ROLE_ADMIN)->name('security.users.create');
    Route::post('/security/users', [SystemUserController::class, 'store'])->middleware('role:' . User::ROLE_ADMIN)->name('security.users.store');
    Route::get('/security/users/{user}/edit', [SystemUserController::class, 'edit'])->middleware('role:' . User::ROLE_ADMIN)->name('security.users.edit');
    Route::put('/security/users/{user}', [SystemUserController::class, 'update'])->middleware('role:' . User::ROLE_ADMIN)->name('security.users.update');
    Route::delete('/security/users/{user}', [SystemUserController::class, 'destroy'])->middleware('role:' . User::ROLE_ADMIN)->name('security.users.destroy');
    Route::get('/document-templates', [DocumentTemplateController::class, 'index'])->middleware('role:' . User::ROLE_MANAGER)->name('document-templates.index');
    Route::put('/document-templates/{documentTemplate}', [DocumentTemplateController::class, 'update'])->middleware('role:' . User::ROLE_MANAGER)->name('document-templates.update');
});

if (file_exists(__DIR__ . '/auth.php')) { require __DIR__ . '/auth.php'; }
if (file_exists(__DIR__ . '/settings.php')) { require __DIR__ . '/settings.php'; }
