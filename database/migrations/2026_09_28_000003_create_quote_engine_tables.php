<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('quote_presets')->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('kind', 32);
            $table->string('name');
            $table->string('unit', 32)->nullable();
            $table->string('production_sector', 48)->nullable();
            $table->string('wizard_key', 64)->nullable();
            $table->json('suggested_components');
            $table->json('wizard_schema')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->index(['kind', 'is_available']);
        });

        Schema::create('organization_quote_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_preset_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('unit_cost_cents')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'quote_preset_id'], 'org_quote_preset_unique');
            $table->index(['organization_id', 'is_enabled']);
        });

        Schema::create('organization_quote_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            // Fatores nulos significam que o administrador ainda precisa defini-los.
            $table->unsignedInteger('waste_basis_points')->nullable();
            $table->unsignedInteger('markup_multiplier_basis_points')->nullable();
            $table->timestamps();
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number', 40);
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('current_version')->default(1);
            $table->date('expires_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status', 'created_at']);
        });

        Schema::create('quote_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('snapshot');
            $table->unsignedBigInteger('cost_total_cents')->nullable();
            $table->unsignedBigInteger('sale_total_cents')->nullable();
            $table->boolean('is_calculable')->default(false);
            $table->timestamps();
            $table->unique(['quote_id', 'version_number']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_version_id')->constrained()->cascadeOnDelete();
            $table->string('preset_code', 96)->nullable();
            $table->string('name');
            $table->string('unit', 32);
            $table->unsignedBigInteger('quantity_milli');
            $table->json('answers');
            $table->unsignedBigInteger('cost_cents')->nullable();
            $table->unsignedBigInteger('sale_cents')->nullable();
            $table->string('production_sector', 48)->nullable();
            $table->timestamps();
            $table->index(['quote_version_id', 'production_sector']);
        });

        Schema::create('quote_item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_item_id')->constrained()->cascadeOnDelete();
            $table->string('preset_code', 96);
            $table->string('name');
            $table->string('kind', 32);
            $table->string('unit', 32);
            $table->unsignedBigInteger('quantity_per_unit_milli')->nullable();
            $table->unsignedBigInteger('quantity_milli')->nullable();
            $table->unsignedBigInteger('unit_cost_cents')->nullable();
            $table->unsignedBigInteger('cost_cents')->nullable();
            $table->string('production_sector', 48)->nullable();
            $table->timestamps();
        });

        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('quote_id')->constrained()->restrictOnDelete();
            $table->foreignId('quote_version_id')->constrained()->restrictOnDelete();
            $table->string('number', 48);
            $table->string('sector', 48);
            $table->string('status', 24)->default('pending');
            $table->json('snapshot');
            $table->timestamps();
            // A repetição do endpoint de aprovação não duplica ordens do mesmo setor.
            $table->unique(['quote_version_id', 'sector']);
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status', 'sector']);
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('quote_item_components');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quote_versions');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('organization_quote_settings');
        Schema::dropIfExists('organization_quote_presets');
        Schema::dropIfExists('quote_presets');
    }
};
