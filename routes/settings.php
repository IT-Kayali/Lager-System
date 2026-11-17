<?php

use App\Http\Controllers\BranchWithdrawalController;
use App\Http\Controllers\BranchWithdrawalPdfController;
use App\Http\Controllers\OfferInternalNoteController;
use App\Http\Controllers\PriceTierDefinitionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SystemBackupController;
use App\Http\Controllers\SystemDataResetController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::put('settings/price-tiers', [PriceTierDefinitionController::class, 'updateAll'])
        ->name('price-tiers.update')
        ->middleware('role:'.User::ROLE_ADMIN);

    Route::put('settings/offer-numbering', [SettingsController::class, 'updateOfferNumber'])
        ->name('settings.offer-numbering.update')
        ->middleware('role:'.User::ROLE_ADMIN);

    Route::put('settings/low-stock-warning', [SettingsController::class, 'updateLowStockWarning'])
        ->name('settings.low-stock-warning.update')
        ->middleware('role:'.User::ROLE_ADMIN);

    Route::put('settings/batch-expiry', [SettingsController::class, 'updateBatchExpiry'])
        ->name('settings.batch-expiry.update')
        ->middleware('role:'.User::ROLE_ADMIN);

    Route::post(
        'settings/backups',
        [SystemBackupController::class, 'store']
    )
        ->name('settings.backups.store')
        ->middleware([
            'role:'.User::ROLE_ADMIN,
            'throttle:3,10',
        ]);

    Route::put(
        'settings/backups/configuration',
        [SystemBackupController::class, 'updateSettings']
    )
        ->name('settings.backups.settings')
        ->middleware('role:'.User::ROLE_ADMIN);

    Route::get(
        'settings/backups/{filename}/restore',
        [SystemBackupController::class, 'showRestore']
    )
        ->where('filename', '[A-Za-z0-9._-]+')
        ->name('settings.backups.restore.confirm')
        ->middleware('role:'.User::ROLE_ADMIN);

    Route::post(
        'settings/backups/{filename}/restore',
        [SystemBackupController::class, 'restore']
    )
        ->where('filename', '[A-Za-z0-9._-]+')
        ->name('settings.backups.restore')
        ->middleware([
            'role:'.User::ROLE_ADMIN,
            'throttle:5,10',
        ]);

    Route::get(
        'settings/backups/{filename}/download',
        [SystemBackupController::class, 'download']
    )
        ->where('filename', '[A-Za-z0-9._-]+')
        ->name('settings.backups.download')
        ->middleware('role:'.User::ROLE_ADMIN);

    Route::delete(
        'settings/backups/{filename}',
        [SystemBackupController::class, 'destroy']
    )
        ->where('filename', '[A-Za-z0-9._-]+')
        ->name('settings.backups.destroy')
        ->middleware('role:'.User::ROLE_ADMIN);

    Route::post(
        'settings/system-data-reset',
        [SystemDataResetController::class, 'store']
    )
        ->name('settings.system-data-reset')
        ->middleware([
            'role:'.User::ROLE_ADMIN,
            'throttle:3,10',
        ]);

    Route::post('offer-notes/{offer}', [OfferInternalNoteController::class, 'store'])
        ->name('offer-notes.store')
        ->middleware('role:'.User::ROLE_MANAGER.','.User::ROLE_SALES.','.User::ROLE_WAREHOUSE);

    Route::get('branch-withdrawals/{branchWithdrawal}/delivery-note', [BranchWithdrawalPdfController::class, 'stream'])
        ->name('branch-withdrawals.delivery-note')
        ->middleware('role:'.User::ROLE_MANAGER.','.User::ROLE_WAREHOUSE);

    Route::get('branch-withdrawals/{branchWithdrawal}/edit', [BranchWithdrawalController::class, 'edit'])
        ->name('branch-withdrawals.edit')
        ->middleware('role:'.User::ROLE_MANAGER);

    Route::put('branch-withdrawals/{branchWithdrawal}', [BranchWithdrawalController::class, 'update'])
        ->name('branch-withdrawals.update')
        ->middleware('role:'.User::ROLE_MANAGER.','.User::ROLE_WAREHOUSE);

    Route::delete('branch-withdrawals/{branchWithdrawal}', [BranchWithdrawalController::class, 'destroy'])
        ->name('branch-withdrawals.destroy')
        ->middleware('role:'.User::ROLE_MANAGER);

    Route::get('verkauf/produkte', [ProductController::class, 'index'])
        ->name('sales.products.index')
        ->middleware('role:'.User::ROLE_SALES);

    Route::get('verkauf/produkte/{product}', [ProductController::class, 'show'])
        ->name('sales.products.show')
        ->middleware('role:'.User::ROLE_SALES);

    Route::get('verkauf/filialausgaenge', [BranchWithdrawalController::class, 'index'])
        ->name('sales.branch-withdrawals.index')
        ->middleware('role:'.User::ROLE_SALES);

    Route::get('verkauf/filialausgaenge/erstellen', [BranchWithdrawalController::class, 'create'])
        ->name('sales.branch-withdrawals.create')
        ->middleware('role:'.User::ROLE_SALES);

    Route::post('verkauf/filialausgaenge', [BranchWithdrawalController::class, 'store'])
        ->name('sales.branch-withdrawals.store')
        ->middleware('role:'.User::ROLE_SALES);

    Route::get('verkauf/filialausgaenge/{branchWithdrawal}', [BranchWithdrawalController::class, 'show'])
        ->name('sales.branch-withdrawals.show')
        ->middleware('role:'.User::ROLE_SALES);

    Route::get('verkauf/filialausgaenge/{branchWithdrawal}/bearbeiten', [BranchWithdrawalController::class, 'edit'])
        ->name('sales.branch-withdrawals.edit')
        ->middleware('role:'.User::ROLE_SALES);

    Route::put('verkauf/filialausgaenge/{branchWithdrawal}', [BranchWithdrawalController::class, 'update'])
        ->name('sales.branch-withdrawals.update')
        ->middleware('role:'.User::ROLE_SALES);

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');
    Route::livewire('settings/security', 'pages::settings.security')->name('security.edit');
});
