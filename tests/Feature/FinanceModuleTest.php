<?php

namespace Tests\Feature;

use App\Actions\RegisterOwner;
use App\Models\FinanceEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceModuleTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email = 'finance@example.test'): User
    {
        return app(RegisterOwner::class)->execute(['name' => 'Owner', 'email' => $email, 'password' => 'secret123', 'organization_name' => 'Gráfica']);
    }

    private function entryData(array $extra = []): array
    {
        return array_merge([
            'type' => 'income',
            'description' => 'Pedido recebido',
            'category' => 'Vendas',
            'amount' => '1.234,56',
            'occurred_on' => now()->format('Y-m-d'),
            'payment_method' => 'pix',
            'notes' => '',
        ], $extra);
    }

    public function test_registers_formatted_amount_and_shows_real_monthly_totals(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get('/finance/entries/create')->assertOk();
        $this->actingAs($owner)->post('/finance/entries', $this->entryData(['organization_id' => 999]))->assertRedirect();

        $entry = FinanceEntry::firstOrFail();
        $this->assertSame($owner->organization_id, $entry->organization_id);
        $this->assertSame(123456, $entry->amount_cents);
        $this->assertSame('R$ 1.234,56', $entry->formatted_amount);
        $this->get('/finance')->assertOk()->assertViewHas('incomeCents', 123456)->assertViewHas('expenseCents', 0);
        $this->get('/modules')->assertOk()->assertSee('Orçamentos e aprovação')->assertSee('Relatórios');
    }

    public function test_expenses_are_subtracted_and_monthly_chart_uses_real_values(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/finance/entries', $this->entryData())->assertRedirect();
        $this->actingAs($owner)->post('/finance/entries', $this->entryData([
            'type' => 'expense',
            'description' => 'Compra de papel',
            'amount' => '245,50',
            'payment_method' => 'transfer',
        ]))->assertRedirect();

        $month = now()->format('Y-m');
        $this->get('/finance?month='.$month)->assertOk()
            ->assertViewHas('incomeCents', 123456)
            ->assertViewHas('expenseCents', 24550)
            ->assertViewHas('balanceLabel', 'R$ 989,06')
            ->assertViewHas('chart', fn ($chart) => $chart->last()['income'] === 123456 && $chart->last()['expense'] === 24550);
    }

    public function test_financial_entries_and_aggregates_are_scoped_to_the_organization(): void
    {
        $owner = $this->owner();
        $other = $this->owner('other-finance@example.test');
        $this->actingAs($owner)->post('/finance/entries', $this->entryData())->assertRedirect();
        $this->actingAs($other)->post('/finance/entries', $this->entryData(['amount' => '9.999,99']))->assertRedirect();

        $this->actingAs($owner)->get('/finance')->assertOk()
            ->assertViewHas('incomeCents', 123456)
            ->assertViewHas('entries', fn ($entries) => $entries->total() === 1 && $entries->first()->description === 'Pedido recebido');
    }

    public function test_amount_zero_future_date_and_guest_access_are_rejected(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/finance/entries', $this->entryData(['amount' => '0,00']))->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post('/finance/entries', $this->entryData(['occurred_on' => now()->addDay()->format('Y-m-d')]))->assertSessionHasErrors('occurred_on');
        $this->assertDatabaseCount('finance_entries', 0);
        auth()->logout();
        $this->get('/finance')->assertRedirect('/login');
        $this->post('/finance/entries', $this->entryData())->assertRedirect('/login');
    }
}
