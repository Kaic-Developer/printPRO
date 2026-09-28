<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_quote_presets', function (Blueprint $table): void {
            // As dimensões cadastradas por gráfica impedem ajustar a mídia para reduzir o custo do orçamento.
            $table->unsignedSmallInteger('material_width_mm')->nullable();
            $table->unsignedSmallInteger('material_length_mm')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organization_quote_presets', function (Blueprint $table): void {
            $table->dropColumn(['material_width_mm', 'material_length_mm']);
        });
    }
};
