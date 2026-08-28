<?php

declare(strict_types=1);

use App\Http\Controllers\Organization\OrganizationNodeController;
use App\Http\Controllers\Organization\OrganizationNodeMigrationController;
use App\Http\Controllers\Organization\OrganizationRuleController;
use App\Http\Controllers\Organization\OrganizationSchemeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('workspaces/{workspace}/organization/schemes', [OrganizationSchemeController::class, 'index'])->name('organization.schemes.index');
    Route::get('workspaces/{workspace}/organization/schemes/create', [OrganizationSchemeController::class, 'create'])->name('organization.schemes.create');
    Route::post('workspaces/{workspace}/organization/schemes', [OrganizationSchemeController::class, 'store'])->name('organization.schemes.store');
    Route::get('organization/schemes/{scheme}', [OrganizationSchemeController::class, 'show'])->name('organization.schemes.show');
    Route::get('organization/schemes/{scheme}/edit', [OrganizationSchemeController::class, 'edit'])->name('organization.schemes.edit');
    Route::patch('organization/schemes/{scheme}', [OrganizationSchemeController::class, 'update'])->name('organization.schemes.update');

    Route::post('organization/schemes/{scheme}/nodes', [OrganizationNodeController::class, 'store'])->name('organization.schemes.nodes.store');
    Route::delete('organization/schemes/{scheme}/nodes/{node}', [OrganizationNodeController::class, 'destroy'])->name('organization.schemes.nodes.destroy');
    Route::post('organization/nodes/{node}/migrate', [OrganizationNodeMigrationController::class, 'store'])->name('organization.nodes.migrate');

    Route::post('organization/schemes/{scheme}/rules', [OrganizationRuleController::class, 'store'])->name('organization.schemes.rules.store');
    Route::patch('organization/schemes/{scheme}/rules/{rule}', [OrganizationRuleController::class, 'update'])->name('organization.schemes.rules.update');
    Route::delete('organization/schemes/{scheme}/rules/{rule}', [OrganizationRuleController::class, 'destroy'])->name('organization.schemes.rules.destroy');
});
