<?php

use App\Models\BranchWithdrawal;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->manager = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
    $this->sales = User::factory()->create(['role' => User::ROLE_SALES, 'is_active' => true]);
    $this->warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
});

function withdrawalForHistory(User $creator, string $status, string $number): BranchWithdrawal
{
    $product = Product::query()->create([
        'name' => 'Historienprodukt ' . $number,
        'unit' => 'gram',
        'minimum_stock' => 0,
    ]);

    $withdrawal = BranchWithdrawal::query()->create([
        'product_id' => $product->id,
        'user_id' => $creator->id,
        'withdrawal_number' => $number,
        'branch_name' => BranchWithdrawal::BRANCH_DEZ,
        'status' => $status,
        'quantity' => 2.5,
        'stock_before' => 0,
        'stock_after' => 0,
        'batch_allocations' => [],
        'note' => 'Interne Notiz zu ' . $number,
    ]);

    $withdrawal->items()->create([
        'product_id' => $product->id,
        'quantity' => 2.5,
        'stock_before' => 0,
        'stock_after' => 0,
        'batch_allocations' => [],
    ]);

    return $withdrawal;
}

it('keeps handed-off and finished withdrawals visible to sales without edit access', function () {
    $statuses = [
        BranchWithdrawal::STATUS_IN_PROGRESS,
        BranchWithdrawal::STATUS_ISSUED,
        BranchWithdrawal::STATUS_CANCELLED,
    ];

    foreach ($statuses as $status) {
        $withdrawal = withdrawalForHistory($this->sales, $status, 'FIL-SALES-' . $status);

        $this->actingAs($this->sales)
            ->get(route('sales.branch-withdrawals.index', ['status' => $status]))
            ->assertOk()
            ->assertSee($withdrawal->withdrawal_number)
            ->assertSee(route('sales.branch-withdrawals.show', $withdrawal), false);

        $this->actingAs($this->sales)
            ->get(route('sales.branch-withdrawals.show', $withdrawal))
            ->assertOk()
            ->assertSee($withdrawal->withdrawal_number)
            ->assertSee('Historienprodukt ' . $withdrawal->withdrawal_number)
            ->assertSee('Interne Notiz zu ' . $withdrawal->withdrawal_number)
            ->assertSee('2,5')
            ->assertDontSee(route('sales.branch-withdrawals.edit', $withdrawal), false)
            ->assertDontSee('Filial-Lieferschein');

        $this->actingAs($this->sales)
            ->get(route('sales.branch-withdrawals.edit', $withdrawal))
            ->assertForbidden();
    }
});

it('keeps cancelled withdrawals visible to warehouse as read-only history', function () {
    $open = withdrawalForHistory($this->sales, BranchWithdrawal::STATUS_OPEN, 'FIL-DRAFT');
    $inProgress = withdrawalForHistory($this->sales, BranchWithdrawal::STATUS_IN_PROGRESS, 'FIL-HANDED-OFF');
    $issued = withdrawalForHistory($this->sales, BranchWithdrawal::STATUS_ISSUED, 'FIL-ISSUED');
    $cancelled = withdrawalForHistory($this->sales, BranchWithdrawal::STATUS_CANCELLED, 'FIL-CANCELLED');

    $this->actingAs($this->warehouse)
        ->get(route('branch-withdrawals.index'))
        ->assertOk()
        ->assertDontSee($open->withdrawal_number)
        ->assertSee($inProgress->withdrawal_number)
        ->assertSee($issued->withdrawal_number)
        ->assertSee($cancelled->withdrawal_number)
        ->assertSee(route('branch-withdrawals.show', $cancelled), false);

    $this->actingAs($this->warehouse)
        ->get(route('branch-withdrawals.index', ['status' => BranchWithdrawal::STATUS_CANCELLED]))
        ->assertOk()
        ->assertSee($cancelled->withdrawal_number)
        ->assertDontSee($issued->withdrawal_number);

    $this->actingAs($this->warehouse)
        ->get(route('branch-withdrawals.show', $cancelled))
        ->assertOk()
        ->assertSee('Storniert')
        ->assertSee('Interne Notiz zu FIL-CANCELLED')
        ->assertSee(route('branch-withdrawals.delivery-note', $cancelled), false)
        ->assertDontSee('name="status"', false);

    $this->actingAs($this->warehouse)
        ->get(route('branch-withdrawals.show', $open))
        ->assertForbidden();

    $this->actingAs($this->warehouse)
        ->put(route('branch-withdrawals.update', $cancelled), ['status' => BranchWithdrawal::STATUS_ISSUED])
        ->assertForbidden();

    expect($cancelled->fresh()->status)->toBe(BranchWithdrawal::STATUS_CANCELLED);
});

it('allows managers and admins to view all states but denies unrelated roles', function () {
    $open = withdrawalForHistory($this->manager, BranchWithdrawal::STATUS_OPEN, 'FIL-MANAGER-OPEN');
    $issued = withdrawalForHistory($this->manager, BranchWithdrawal::STATUS_ISSUED, 'FIL-MANAGER-ISSUED');

    $this->actingAs($this->manager)
        ->get(route('branch-withdrawals.show', $open))
        ->assertOk()
        ->assertSee(route('branch-withdrawals.edit', $open), false);

    $this->actingAs($this->manager)
        ->get(route('branch-withdrawals.show', $issued))
        ->assertOk()
        ->assertSee('Ausgegeben');

    $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    $this->actingAs($admin)->get(route('branch-withdrawals.show', $issued))->assertOk();

    $crm = User::factory()->create(['role' => User::ROLE_CRM, 'is_active' => true]);
    $this->actingAs($crm)->get(route('branch-withdrawals.show', $issued))->assertForbidden();
    $this->actingAs($crm)->get(route('sales.branch-withdrawals.show', $issued))->assertForbidden();

    $inactive = User::factory()->create(['role' => User::ROLE_SALES, 'is_active' => false]);
    // Die globale EnsureActiveUser-Middleware meldet deaktivierte Benutzer ab.
    $this->actingAs($inactive)
        ->get(route('sales.branch-withdrawals.show', $issued))
        ->assertRedirect(route('login'));

    auth()->logout();
    $this->get(route('branch-withdrawals.show', $issued))->assertRedirect(route('login'));
});

it('lets sales edit their open draft from the same detail page', function () {
    $open = withdrawalForHistory($this->sales, BranchWithdrawal::STATUS_OPEN, 'FIL-SALES-OPEN');

    $this->actingAs($this->sales)
        ->get(route('sales.branch-withdrawals.show', $open))
        ->assertOk()
        ->assertSee(route('sales.branch-withdrawals.edit', $open), false);
});
