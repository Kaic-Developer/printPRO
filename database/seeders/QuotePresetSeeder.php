<?php

namespace Database\Seeders;

use App\Actions\InitializeQuoteCatalog;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class QuotePresetSeeder extends Seeder
{
    public function run(): void
    {
        app(InitializeQuoteCatalog::class)->execute();
        Organization::query()->each(fn (Organization $organization) => app(InitializeQuoteCatalog::class)->execute($organization));
    }
}
