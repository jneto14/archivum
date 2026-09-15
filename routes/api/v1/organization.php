<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\OrganizationLabelController;
use App\Http\Controllers\Api\V1\OrganizationLevelController;
use App\Http\Controllers\Api\V1\OrganizationNodeController;
use App\Http\Controllers\Api\V1\OrganizationRuleController;
use App\Http\Controllers\Api\V1\OrganizationSchemeController;
use Illuminate\Support\Facades\Route;

/*
| How the physical archive is laid out, and where a document goes in it.
|
| The node listing walks the tree a tier at a time with `parent_id`, rather
| than mirroring the interface's storage page, which builds the whole nested
| structure in one payload. A page can afford that because it is drawing a
| browser; a client opening one cover does not want every shelf in the
| building.
*/
Route::get('workspaces/{workspace}/organization/schemes', [OrganizationSchemeController::class, 'index'])->name('organization.schemes.index');
Route::post('workspaces/{workspace}/organization/schemes', [OrganizationSchemeController::class, 'store'])->name('organization.schemes.store');
Route::get('organization/schemes/{scheme}', [OrganizationSchemeController::class, 'show'])->name('organization.schemes.show');
Route::patch('organization/schemes/{scheme}', [OrganizationSchemeController::class, 'update'])->name('organization.schemes.update');

Route::post('organization/schemes/{scheme}/levels', [OrganizationLevelController::class, 'store'])->name('organization.schemes.levels.store');
Route::patch('organization/schemes/{scheme}/levels/{level}', [OrganizationLevelController::class, 'update'])->name('organization.schemes.levels.update');
Route::delete('organization/schemes/{scheme}/levels/{level}', [OrganizationLevelController::class, 'destroy'])->name('organization.schemes.levels.destroy');

Route::get('organization/schemes/{scheme}/nodes', [OrganizationNodeController::class, 'index'])->name('organization.schemes.nodes.index');
Route::post('organization/schemes/{scheme}/nodes', [OrganizationNodeController::class, 'store'])->name('organization.schemes.nodes.store');
Route::delete('organization/schemes/{scheme}/nodes/{node}', [OrganizationNodeController::class, 'destroy'])->name('organization.schemes.nodes.destroy');
Route::get('organization/nodes/{node}/documents', [OrganizationNodeController::class, 'documents'])->name('organization.nodes.documents');
Route::post('organization/nodes/{node}/migrate', [OrganizationNodeController::class, 'migrate'])->name('organization.nodes.migrate');

Route::post('organization/schemes/{scheme}/rules', [OrganizationRuleController::class, 'store'])->name('organization.schemes.rules.store');
Route::patch('organization/schemes/{scheme}/rules/{rule}', [OrganizationRuleController::class, 'update'])->name('organization.schemes.rules.update');
Route::delete('organization/schemes/{scheme}/rules/{rule}', [OrganizationRuleController::class, 'destroy'])->name('organization.schemes.rules.destroy');

Route::get('organization/schemes/{scheme}/labels', [OrganizationLabelController::class, 'index'])->name('organization.schemes.labels');
