<?php

use App\Http\Controllers\OfferController;
use App\Models\BranchWithdrawal;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('shows the same available stock with bounded queries in the offer editor', function () {
    $group = CustomerGroup::query()->create([
        'name' => 'Stock-Performance-Gruppe',
        'slug' => 'stock-performance-gruppe',
        'color' => '#D4AD16',
    ]);
    $customer = Customer::query()->create([
        'customer_group_id' => $group->id,
        'company_name' => 'Stock-Performance-Kunde',
    ]);
    $manager = User::factory()->create([
        'role' => User::ROLE_MANAGER,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Stock-Performance-Produkt',
        'unit' => 'gram',
        'minimum_stock' => 0,
    ]);
    $empty = Product::query()->create([
        'name' => 'Stock-Performance-Leeres-Produkt',
        'unit' => 'gram',
        'minimum_stock' => 0,
    ]);
    ProductBatch::query()->create([
        'product_id' => $product->id,
        'quantity' => 100,
        'received_at' => now()->toDateString(),
    ]);

    $offer = Offer::query()->create([
        'customer_id' => $customer->id,
        'user_id' => $manager->id,
        'status' => Offer::STATUS_OFFER,
        'template_type' => 'with_company',
        'document_type' => 'offer',
        'subtotal' => 0,
        'total' => 0,
        'reserved_until' => now()->addHours(72),
    ]);
    $offer->items()->create([
        'product_id' => $product->id,
        'product_code' => $product->product_code,
        'product_name' => $product->name,
        'unit' => 'gram',
        'quantity' => 25,
        'unit_price' => 0,
        'line_total' => 0,
    ]);

    $withdrawal = BranchWithdrawal::query()->create([
        'product_id' => $product->id,
        'user_id' => $manager->id,
        'withdrawal_number' => 'FIL-STOCK-PERFORMANCE',
        'branch_name' => BranchWithdrawal::BRANCH_MAIN,
        'status' => BranchWithdrawal::STATUS_OPEN,
        'quantity' => 15,
        'stock_before' => 100,
        'stock_after' => 100,
        'batch_allocations' => [],
        'note' => 'Performance-Testnotiz',
    ]);
    $withdrawal->items()->create([
        'product_id' => $product->id,
        'quantity' => 15,
        'stock_before' => 100,
        'stock_after' => 100,
        'batch_allocations' => [],
    ]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $method = new ReflectionMethod(OfferController::class, 'products');
    $list = $method->invoke(new OfferController());
    $queryCount = $queries;
    $byId = $list->keyBy('id');

    expect($queryCount)->toBeLessThanOrEqual(20)
        ->and((float) $byId->get($product->id)->offer_editor_available_stock)->toBe(60.0)
        ->and((float) $byId->get($empty->id)->offer_editor_available_stock)->toBe(0.0)
        ->and((float) $product->fresh()->available_stock)->toBe(60.0);
});
