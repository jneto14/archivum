<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * A label belongs on what somebody physically picks up — a box, a cover, a
     * drawer — not on every node of the scheme. A position is a slot on a page,
     * and an archive with a few hundred of them would otherwise offer a few
     * hundred labels nobody wants. So each level says whether its nodes carry
     * one, and none does until a workspace says so.
     */
    public function up(): void
    {
        Schema::table('organization_levels', function (Blueprint $table) {
            $table->boolean('has_printable_label')->default(false)->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('organization_levels', function (Blueprint $table) {
            $table->dropColumn('has_printable_label');
        });
    }
};
