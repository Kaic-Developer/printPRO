<?php

namespace Tests\Feature;

use App\Actions\ManageCustomers;
use App\Actions\RegisterOwner;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkspaceFlowTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email = 'owner@example.test'): User
    {
        return app(RegisterOwner::class)->execute(['name' => 'Proprietário', 'email' => $email, 'password' => 'secret123', 'organization_name' => 'Gráfica']);
    }

    private function data(array $extra = []): array
    {
        return array_merge(['name' => 'Cliente', 'type' => 'company', 'email' => 'client@example.test'], $extra);
    }

    public function test_registration_creates_owner_and_organization_and_logs_in(): void
    {
        $this->withSession(['probe' => true]);
        $oldId = session()->getId();
        $this->post('/register', ['name' => 'Ana', 'organization_name' => 'Impressão', 'email' => 'ana@example.test', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'organization_id' => 999])
            ->assertRedirect('/dashboard');
        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Impressão', $user->organization->name);
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertNotSame($oldId, session()->getId());
        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_registration_validation_and_transaction_rollback(): void
    {
        $this->post('/register', [])->assertSessionHasErrors(['name', 'email', 'password', 'organization_name']);
        $this->assertDatabaseCount('organizations', 0);
        $this->owner();
        try {
            $this->owner();
            $this->fail('Deveria rejeitar e-mail duplicado.');
        } catch (QueryException $exception) {
            $this->assertDatabaseCount('organizations', 1);
        }
        $this->post('/register', ['name' => 'Ana', 'organization_name' => 'Outra', 'email' => 'owner@example.test', 'password' => 'secret123', 'password_confirmation' => 'different'])->assertSessionHasErrors(['email', 'password']);
    }

    public function test_login_regenerates_session_and_logout_invalidates_it(): void
    {
        $user = $this->owner();
        $this->withSession(['probe' => 'private']);
        $oldId = session()->getId();
        $this->post('/login', ['email' => $user->email, 'password' => 'secret123', 'remember' => '1'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotNull($user->fresh()->remember_token);
        $token = session()->token();
        $this->post('/logout')->assertRedirect('/login')->assertSessionMissing('probe');
        $this->assertGuest();
        $this->assertNotSame($token, session()->token());
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        $user = $this->owner();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => 'secret123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_guests_cannot_access_customer_routes(): void
    {
        foreach (['/dashboard', '/customers', '/customers/create', '/customers/1', '/customers/1/edit'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/customers', $this->data())->assertRedirect('/login');
        $this->put('/customers/1', $this->data())->assertRedirect('/login');
    }

    public function test_customer_flow_ignores_foreign_organization_and_other_untrusted_fields(): void
    {
        $user = $this->owner();
        $other = $this->owner('other@example.test');
        $this->actingAs($user)->post('/customers', $this->data(['organization_id' => $other->organization_id, 'id' => 999]))->assertRedirect();
        $customer = Customer::firstOrFail();
        $this->assertSame($user->organization_id, $customer->organization_id);
        $this->assertNotEquals(999, $customer->id);
        $this->get('/customers/create')->assertOk();
        $this->get('/customers/'.$customer->id)->assertOk()->assertViewHas('customer', fn ($value) => $value->is($customer));
        $this->get('/customers/'.$customer->id.'/edit')->assertOk();
        $this->put('/customers/'.$customer->id, $this->data(['name' => 'Atualizado', 'organization_id' => $other->organization_id]))->assertRedirect('/customers/'.$customer->id);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'organization_id' => $user->organization_id, 'name' => 'Atualizado']);
        $this->post('/customers', ['name' => '', 'type' => 'invalid', 'email' => 'invalid'])->assertSessionHasErrors(['name', 'type', 'email']);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_two_organizations_are_isolated_in_search_dashboard_and_idor(): void
    {
        $first = $this->owner();
        $second = $this->owner('other@example.test');
        $action = app(ManageCustomers::class);
        $own = $action->save($first, $this->data());
        $foreign = $action->save($second, $this->data(['name' => 'Segredo']));
        $this->actingAs($first)->get('/customers?q=client')->assertOk()->assertViewHas('customers', fn ($items) => $items->total() === 1 && $items->first()->is($own))->assertViewHas('search', 'client');
        $this->get('/dashboard')->assertOk()->assertViewHas('customerCount', 1)->assertViewHas('recentCustomers', fn ($items) => $items->count() === 1 && $items->first()->is($own));
        $this->get('/customers/'.$foreign->id)->assertNotFound();
        $this->get('/customers/'.$foreign->id.'/edit')->assertNotFound();
        $this->put('/customers/'.$foreign->id, $this->data(['organization_id' => $first->organization_id]))->assertNotFound();
        $this->assertSame('Segredo', $foreign->fresh()->name);
        $this->assertSame($second->organization_id, $foreign->fresh()->organization_id);
    }

    public function test_pagination_and_empty_dashboard(): void
    {
        $user = $this->owner();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertViewHas('customerCount', 0);
        for ($i = 0; $i < 16; $i++) {
            app(ManageCustomers::class)->save($user, $this->data(['name' => sprintf('Cliente %02d', $i)]));
        }
        $this->get('/customers?q=Cliente&page=2')->assertOk()->assertViewHas('customers', fn ($items) => $items->total() === 16 && $items->count() === 1 && str_contains($items->url(1), 'q=Cliente'));
    }

    public function test_idor_and_mass_assignment_without_rendering_views(): void
    {
        $first = $this->owner();
        $second = $this->owner('second@example.test');
        $action = app(ManageCustomers::class);
        $foreign = $action->save($second, $this->data());
        $this->actingAs($first)->get('/customers/'.$foreign->id)->assertNotFound();
        $this->get('/customers/'.$foreign->id.'/edit')->assertNotFound();
        $this->put('/customers/'.$foreign->id, $this->data(['name' => 'Invadido', 'organization_id' => $first->organization_id]))->assertNotFound();
        $this->assertSame('Cliente', $foreign->fresh()->name);
        $this->post('/customers', $this->data(['organization_id' => $second->organization_id, 'id' => 999]))->assertRedirect();
        $own = $action->query($first)->firstOrFail();
        $this->put('/customers/'.$own->id, $this->data(['name' => 'Editado', 'organization_id' => $second->organization_id]))->assertRedirect();
        $this->assertSame($first->organization_id, $own->fresh()->organization_id);
        $this->assertSame('Editado', $own->fresh()->name);
        $this->assertCount(1, $action->query($first)->get());
        $this->post('/customers', ['type' => 'invalid'])->assertSessionHasErrors(['name', 'type']);
        $this->assertDatabaseCount('customers', 2);
    }
}
