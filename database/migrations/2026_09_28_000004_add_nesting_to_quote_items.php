<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_items', function (Blueprint $table): void {
            // Guarda a geometria validada na linha para preservar o cálculo feito na versão.
            $table->json('nesting')->nullable();
        });

        Schema::table('quote_item_components', function (Blueprint $table): void {
            // Distingue o consumo informado pelo operador do total derivado do nesting.
            $table->string('quantity_source', 16)->default('manual');
        });
    }

    public function down(): void
    {
        Schema::table('quote_items', function (Blueprint $table): void {
            $table->dropColumn('nesting');
        });

        Schema::table('quote_item_components', function (Blueprint $table): void {
            $table->dropColumn('quantity_source');
        });
    }
};
