<?php

use App\Models\BranchWithdrawal;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\User;

function bulkTestProduct(string $name, array $extra = []): Product
{
    return Product::create($extra + ['name' => $name, 'unit' => 'gram', 'minimum_stock' => 0]);
}

function bulkTestUser(string $role = User::ROLE_MANAGER): User
{
    return User::factory()->create(['role' => $role, 'is_active' => true]);
}

function bulkTestList(int $count): array
{
    $products = [];
    foreach (range(1, $count) as $index) {
        $products[] = bulkTestProduct('Sammeltest ' . $index, ['supplier' => 'Testlieferant']);
    }

    return $products;
}

it('returns to the same filtered page after a single deletion', function () {
    $products = bulkTestList(18);
    $context = ['search' => 'Testlieferant', 'search_field' => 'supplier', 'exact' => 1, 'sort' => 'name', 'direction' => 'desc', 'page' => 2];

    $this->actingAs(bulkTestUser())
        ->delete(route('products.destroy', ['product' => $products[0]] + $context), ['expected_product_id' => $products[0]->id])
        ->assertRedirect(route('products.index', $context))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('products', ['id' => $products[0]->id]);
    $this->get(route('products.index', $context))->assertOk()->assertSee('Testlieferant');
});

it('returns to the preceding available filtered page if the last page disappears', function () {
    $products = bulkTestList(16);
    bulkTestProduct('Nicht im Suchergebnis');
    $context = ['search' => 'Testlieferant', 'search_field' => 'supplier', 'exact' => 1, 'page' => 2];

    $this->actingAs(bulkTestUser())
        ->delete(route('products.destroy', ['product' => $products[15]] + $context))
        ->assertRedirect(route('products.index', array_replace($context, ['page' => 1])))
        ->assertSessionHas('success');
});

it('deletes only selected ids and stays on the filtered current page', function () {
    $products = bulkTestList(19);
    $context = ['search' => 'Sammeltest', 'search_field' => 'name', 'exact' => 0, 'sort' => 'name', 'direction' => 'asc', 'page' => 2];

    $this->actingAs(bulkTestUser(User::ROLE_WAREHOUSE))
        ->post(route('products.bulk-action', $context), [
            'bulk_action' => 'delete',
            'product_ids' => [$products[15]->id, $products[17]->id],
        ])
        ->assertRedirect(route('products.index', $context))
        ->assertSessionHas('success', '2 Produkte gelöscht.');

    $this->assertDatabaseMissing('products', ['id' => $products[15]->id]);
    $this->assertDatabaseMissing('products', ['id' => $products[17]->id]);
    $this->assertDatabaseHas('products', ['id' => $products[16]->id]);
    $this->assertDatabaseHas('products', ['id' => $products[18]->id]);
});

it('clamps the page after bulk deletion using the filtered total', function () {
    $products = bulkTestList(17);
    $context = ['search' => 'Sammeltest', 'search_field' => 'name', 'page' => 2];

    $this->actingAs(bulkTestUser())
        ->post(route('products.bulk-action', $context), [
            'bulk_action' => 'delete',
            'product_ids' => [$products[15]->id, $products[16]->id],
        ])
        ->assertRedirect(route('products.index', array_replace($context, ['page' => 1])))
        ->assertSessionHas('success', '2 Produkte gelöscht.');
});

it('protects stock and historical records for both deletion actions and skips protected products in a mixed selection', function () {
    $user = bulkTestUser();
    $unused = bulkTestProduct('Frei');
    $withBatch = bulkTestProduct('Charge');
    $withMovement = bulkTestProduct('Bewegung');
    $withOffer = bulkTestProduct('Angebot');
    $withBranch = bulkTestProduct('Filialausgang');
    $archived = bulkTestProduct('Archiviert', ['archived_at' => now()]);
    $batch = ProductBatch::create(['product_id' => $withBatch->id, 'batch_number' => 'BULK-TEST', 'quantity' => 0, 'received_at' => now()]);
    $movement = StockMovement::create(['product_id' => $withMovement->id, 'user_id' => $user->id, 'type' => 'in', 'quantity' => 2]);
    $group = CustomerGroup::create(['name' => 'Bulk-Gruppe', 'slug' => 'bulk-gruppe', 'color' => '#D4AD16']);
    $customer = Customer::create(['company_name' => 'Bulk-Kunde', 'customer_group_id' => $group->id]);
    $offer = Offer::create(['customer_id' => $customer->id, 'user_id' => $user->id, 'status' => Offer::STATUS_OFFER, 'template_type' => 'with_company', 'document_type' => 'offer', 'subtotal' => 10, 'total' => 10]);
    $offerItem = $offer->items()->create(['product_id' => $withOffer->id, 'product_code' => $withOffer->product_code, 'product_name' => $withOffer->name, 'unit' => 'gram', 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10]);
    $branch = BranchWithdrawal::create(['product_id' => $withBranch->id, 'user_id' => $user->id, 'withdrawal_number' => 'BULK-FIL-001', 'branch_name' => BranchWithdrawal::BRANCH_DEZ, 'status' => BranchWithdrawal::STATUS_ISSUED, 'quantity' => 1, 'stock_before' => 1, 'stock_after' => 0, 'note' => 'Test']);
    $protected = [$withBatch, $withMovement, $withOffer, $withBranch, $archived];

    $this->actingAs($user);
    foreach ($protected as $product) {
        $this->delete(route('products.destroy', $product))->assertRedirect(route('products.index'))->assertSessionHas('error');
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    $this->post(route('products.bulk-action'), [
        'bulk_action' => 'delete',
        'product_ids' => collect($protected)->pluck('id')->push($unused->id)->push(99999999)->all(),
    ])->assertRedirect(route('products.index'))
        ->assertSessionHas('success', '1 Produkte gelöscht.')
        ->assertSessionHas('error');

    $this->assertDatabaseMissing('products', ['id' => $unused->id]);
    foreach ($protected as $product) {
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
    $this->assertDatabaseHas('product_batches', ['id' => $batch->id]);
    $this->assertDatabaseHas('stock_movements', ['id' => $movement->id]);
    $this->assertDatabaseHas('offer_items', ['id' => $offerItem->id]);
    $this->assertDatabaseHas('branch_withdrawals', ['id' => $branch->id]);
});

it('validates actions, ids, duplicates and the page-size limit before deleting', function () {
    $product = bulkTestProduct('Validierung');
    $this->actingAs(bulkTestUser());
    foreach ([
        ['bulk_action' => 'archive', 'product_ids' => [$product->id]],
        ['bulk_action' => 'delete', 'product_ids' => []],
        ['bulk_action' => 'delete', 'product_ids' => [$product->id, $product->id]],
        ['bulk_action' => 'delete', 'product_ids' => range(1, 16)],
        ['bulk_action' => 'delete', 'product_ids' => ['bad']],
    ] as $payload) {
        $this->postJson(route('products.bulk-action'), $payload)->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
});

it('prevents stale numeric product names from deleting another product', function () {
    $other = bulkTestProduct('Anderes Produkt');
    $named = bulkTestProduct((string) $other->id);
    $url = route('products.destroy', $named);
    $named->delete();

    $this->actingAs(bulkTestUser())
        ->delete($url, ['expected_product_id' => $named->id])
        ->assertRedirect(route('products.index'))
        ->assertSessionHas('error');
    $this->assertDatabaseHas('products', ['id' => $other->id]);
});

it('shows bulk controls only to permitted roles and rejects unauthorized submissions', function () {
    $product = bulkTestProduct('Berechtigung');
    $payload = ['bulk_action' => 'delete', 'product_ids' => [$product->id]];
    $this->post(route('products.bulk-action'), $payload)->assertRedirect(route('login'));
    foreach ([User::ROLE_SALES, User::ROLE_CRM] as $role) {
        $this->actingAs(bulkTestUser($role))
            ->post(route('products.bulk-action'), $payload)->assertForbidden();
    }
    $this->actingAs(bulkTestUser(User::ROLE_SALES))
        ->get(route('sales.products.index'))->assertOk()->assertDontSee('data-product-bulk-runtime', false);
    $this->actingAs(bulkTestUser())
        ->get(route('products.index'))->assertOk()
        ->assertSee('data-product-bulk-runtime', false)
        ->assertSee('data-product-bulk-all', false)
        ->assertSee('form="products-bulk-form"', false)
        ->assertSee('data-confirm=', false);
    $this->assertDatabaseHas('products', ['id' => $product->id]);
});
