<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_tiers', function (Blueprint $table): void {
            $table->decimal('price', 15, 5)->default(0)->change();
        });

        Schema::table('manual_price_rules', function (Blueprint $table): void {
            $table->decimal('price', 15, 5)->change();
        });
    }

    public function down(): void
    {
        // Achtung: Ein Rückbau auf zwei Stellen würde bereits gespeicherte
        // Fünf-Stellen-Preise runden. Produktions-Rollback nur nach Prüfung.
        Schema::table('product_price_tiers', function (Blueprint $table): void {
            $table->decimal('price', 12, 2)->default(0)->change();
        });

        Schema::table('manual_price_rules', function (Blueprint $table): void {
            $table->decimal('price', 12, 2)->change();
        });
    }
};
