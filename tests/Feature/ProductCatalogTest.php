<?php

namespace Tests\Feature;

use App\Actions\RegisterOwner;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email = 'owner@example.test'): User
    {
        return app(RegisterOwner::class)->execute(['name' => 'Owner', 'email' => $email, 'password' => 'secret123', 'organization_name' => 'Gráfica']);
    }

    private function productData(array $extra = []): array
    {
        return array_merge([
            'name' => 'Cartão de visita',
            'category' => 'Impressos',
            'sku' => 'CARD-001',
            'description' => 'Cartão colorido',
            'unit' => 'unit',
            'unit_label' => '',
            'price' => '12,50',
            'is_active' => '1',
        ], $extra);
    }

    public function test_product_saves_exact_cents_and_ignores_submitted_tenant(): void
    {
        $owner = $this->owner();
        $other = $this->owner('other@example.test');

        $this->actingAs($owner)->get('/products/create')->assertOk();
        $this->actingAs($owner)->post('/products', $this->productData(['organization_id' => $other->organization_id]))
            ->assertRedirect('/products/1');

        $product = Product::firstOrFail();
        $this->assertSame($owner->organization_id, $product->organization_id);
        $this->assertSame(1250, $product->price_cents);
        $this->assertSame('12.50', $product->price);
        $this->assertSame('R$ 12,50', $product->formatted_price);
        $this->get('/products/'.$product->id)->assertOk();
        $this->get('/products/'.$product->id.'/edit')->assertOk();
    }

    public function test_price_conversion_supports_exact_values_and_blank_price(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/products', $this->productData(['price' => '0,01']))->assertRedirect();
        $this->assertSame(1, Product::firstOrFail()->price_cents);
        $this->actingAs($owner)->post('/products', $this->productData(['sku' => 'CARD-002', 'price' => '12345,67']))->assertRedirect();
        $this->assertSame(1234567, Product::where('sku', 'CARD-002')->firstOrFail()->price_cents);
        $this->actingAs($owner)->post('/products', $this->productData(['sku' => 'CARD-003', 'price' => '']))->assertRedirect();
        $this->assertNull(Product::where('sku', 'CARD-003')->firstOrFail()->price_cents);
    }

    public function test_sku_is_unique_inside_each_organization_only(): void
    {
        $first = $this->owner();
        $second = $this->owner('second@example.test');
        $this->actingAs($first)->post('/products', $this->productData())->assertRedirect();
        $this->actingAs($first)->post('/products', $this->productData(['name' => 'Duplicado']))->assertSessionHasErrors('sku');
        $this->actingAs($second)->post('/products', $this->productData())->assertRedirect();
        $this->assertDatabaseCount('products', 2);
    }

    public function test_custom_unit_requires_label_and_standard_unit_clears_it(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/products', $this->productData(['unit' => 'custom', 'unit_label' => '']))->assertSessionHasErrors('unit_label');
        $this->actingAs($owner)->post('/products', $this->productData(['unit' => 'custom', 'unit_label' => 'Caixa']))->assertRedirect();
        $product = Product::firstOrFail();
        $this->assertSame('Caixa', $product->unit_label);
        $this->actingAs($owner)->put('/products/'.$product->id, $this->productData(['sku' => 'CARD-001', 'unit' => 'unit', 'unit_label' => 'Ignorar']))->assertRedirect();
        $this->assertNull($product->fresh()->unit_label);
    }

    public function test_search_status_and_foreign_products_are_tenant_scoped(): void
    {
        $first = $this->owner();
        $second = $this->owner('second@example.test');
        $this->actingAs($first)->post('/products', $this->productData())->assertRedirect();
        $this->actingAs($first)->post('/products', $this->productData(['name' => 'Panfleto', 'sku' => 'P-01', 'is_active' => '0']))->assertRedirect();
        $this->actingAs($second)->post('/products', $this->productData(['name' => 'Segredo', 'sku' => 'X-01']))->assertRedirect();

        $ownActive = Product::where('organization_id', $first->organization_id)->where('is_active', true)->firstOrFail();
        $foreign = Product::where('organization_id', $second->organization_id)->firstOrFail();
        $this->actingAs($first)->get('/products?q=Cartão&status=active')->assertOk()
            ->assertViewHas('products', fn ($items) => $items->total() === 1 && $items->first()->is($ownActive));
        $this->get('/products/'.$foreign->id)->assertNotFound();
        $this->get('/products/'.$foreign->id.'/edit')->assertNotFound();
        $this->put('/products/'.$foreign->id, $this->productData(['name' => 'Invadido']))->assertNotFound();
        $this->patch('/products/'.$foreign->id.'/toggle', ['is_active' => 0])->assertNotFound();
        $this->assertSame('Segredo', $foreign->fresh()->name);
    }

    public function test_invalid_price_and_guests_are_rejected(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/products', $this->productData(['price' => '1.234,50']))->assertSessionHasErrors('price');
        $this->assertDatabaseCount('products', 0);
        auth()->logout();
        $this->post('/products', $this->productData())->assertRedirect('/login');
        $this->get('/products')->assertRedirect('/login');
    }

    public function test_product_can_be_deactivated_and_reactivated_without_deletion(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/products', $this->productData())->assertRedirect();
        $product = Product::firstOrFail();
        $this->patch('/products/'.$product->id.'/toggle', ['is_active' => 0])->assertRedirect('/products/'.$product->id);
        $this->assertFalse($product->fresh()->is_active);
        $this->patch('/products/'.$product->id.'/toggle', ['is_active' => 1])->assertRedirect('/products/'.$product->id);
        $this->assertTrue($product->fresh()->is_active);
    }
}
