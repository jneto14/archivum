<?php

use App\Http\Controllers\Organization\OrganizationNodeController;
use App\Http\Controllers\Organization\OrganizationRuleController;
use App\Http\Controllers\Organization\OrganizationSchemeController;
use App\Http\Controllers\Organization\OrganizationSchemeMigrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('workspaces/{workspace}/organization/schemes', [OrganizationSchemeController::class, 'store'])->name('organization.schemes.store');
    Route::patch('organization/schemes/{scheme}', [OrganizationSchemeController::class, 'update'])->name('organization.schemes.update');
    Route::post('organization/schemes/{scheme}/migrate', [OrganizationSchemeMigrationController::class, 'store'])->name('organization.schemes.migrate');

    Route::post('organization/schemes/{scheme}/nodes', [OrganizationNodeController::class, 'store'])->name('organization.schemes.nodes.store');

    Route::post('organization/schemes/{scheme}/rules', [OrganizationRuleController::class, 'store'])->name('organization.schemes.rules.store');
    Route::patch('organization/schemes/{scheme}/rules/{rule}', [OrganizationRuleController::class, 'update'])->name('organization.schemes.rules.update');
    Route::delete('organization/schemes/{scheme}/rules/{rule}', [OrganizationRuleController::class, 'destroy'])->name('organization.schemes.rules.destroy');
});
