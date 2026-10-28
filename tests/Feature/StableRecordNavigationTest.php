<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\User;

beforeEach(function () {
    $this->manager = User::factory()->create([
        'role' => User::ROLE_MANAGER,
        'is_active' => true,
    ]);
});

it('opens the exact product for preview and editing even when another product name equals its numeric ID', function () {
    $product = Product::create([
        'name' => 'Originalprodukt',
        'unit' => 'gram',
        'minimum_stock' => 0,
    ]);

    $nameCollision = Product::create([
        'name' => (string) $product->id,
        'unit' => 'gram',
        'minimum_stock' => 0,
    ]);

    $preview = route('products.show', $product);
    $edit = route('products.edit', $product);
    $delete = route('products.destroy', $product);

    expect($preview)->toEndWith('/products/id-'.$product->id)
        ->and($edit)->toEndWith('/products/id-'.$product->id.'/edit')
        ->and($delete)->toEndWith('/products/id-'.$product->id)
        ->and($preview)->not->toBe(route('products.show', $nameCollision));

    $this->actingAs($this->manager)
        ->get($preview)
        ->assertOk()
        ->assertSee('<h1>Originalprodukt</h1>', false);

    $this->get($edit)
        ->assertOk()
        ->assertSee('value="Originalprodukt"', false);

    $this->get(route('products.index'))
        ->assertOk()
        ->assertSee('href="'.$preview.'"', false)
        ->assertSee('href="'.$edit.'"', false)
        ->assertSee('action="'.$delete.'"', false);

    // Old name links remain available, even with numeric names.
    $this->get(route('products.show', ['product' => (string) $product->id]))
        ->assertOk()
        ->assertSee('<h1>'.$nameCollision->name.'</h1>', false);
});

it('preserves the sales-only product preview with the stable route key', function () {
    $sales = User::factory()->create([
        'role' => User::ROLE_SALES,
        'is_active' => true,
    ]);

    $product = Product::create([
        'name' => 'Produkt Verkaufstest',
        'unit' => 'gram',
        'minimum_stock' => 0,
    ]);

    $this->actingAs($sales)
        ->get(route('sales.products.show', $product))
        ->assertOk()
        ->assertSee('<h1>Produkt Verkaufstest</h1>', false);

    $this->get(route('products.edit', $product))->assertForbidden();
});

it('uses exact supplier IDs across list preview edit and legacy links', function () {
    $supplier = Supplier::create([
        'supplier_number' => 'LFR-ROUTE-100',
        'company_name' => 'Meyer-Test',
    ]);

    $conflictingSlug = Supplier::create([
        'supplier_number' => 'LFR-ROUTE-101',
        'company_name' => 'Meyer Test',
    ]);

    $preview = route('suppliers.show', $supplier);
    $edit = route('suppliers.edit', $supplier);

    expect($preview)->toEndWith('/suppliers/id-'.$supplier->id)
        ->and($edit)->toEndWith('/suppliers/id-'.$supplier->id.'/edit');

    $this->actingAs($this->manager)
        ->get($preview)
        ->assertOk()
        ->assertSee('<h1>Meyer-Test</h1>', false);

    $this->get(route('suppliers.show', $conflictingSlug))
        ->assertOk()
        ->assertSee('<h1>Meyer Test</h1>', false);

    $this->get($edit)
        ->assertOk()
        ->assertSee('Meyer-Test');

    $this->get(route('suppliers.index'))
        ->assertOk()
        ->assertSee('href="'.$preview.'"', false)
        ->assertSee('href="'.$edit.'"', false);

    $this->get(route('suppliers.show', ['supplier' => 'LFR-ROUTE-100']))
        ->assertOk()
        ->assertSee('<h1>Meyer-Test</h1>', false);
});

it('keeps categories distinct when the names normalize to the same hyphenated URL', function () {
    $category = ProductCategory::create([
        'name' => 'Duft-Sets',
        'slug' => 'duft-sets-hyphen',
        'priority' => 10,
        'is_active' => true,
    ]);

    $other = ProductCategory::create([
        'name' => 'Duft Sets',
        'slug' => 'duft-sets-space',
        'priority' => 20,
        'is_active' => true,
    ]);

    $preview = route('product-categories.show', $category);
    $edit = route('product-categories.edit', $category);

    expect($preview)->toEndWith('/product-categories/id-'.$category->id)
        ->and($preview)->not->toBe(route('product-categories.show', $other))
        ->and($edit)->toEndWith('/product-categories/id-'.$category->id.'/edit');

    $this->actingAs($this->manager)
        ->get($preview)
        ->assertOk()
        ->assertSee('Duft-Sets');

    $this->get(route('product-categories.show', $other))
        ->assertOk()
        ->assertSee('Duft Sets');

    $this->get($edit)
        ->assertOk()
        ->assertSee('Duft-Sets');

    $this->get(route('product-categories.index'))
        ->assertOk()
        ->assertSee('href="'.$preview.'"', false)
        ->assertSee('href="'.$edit.'"', false);

    // Existing human-readable links remain supported when unambiguous.
    $this->get(route('product-categories.show', ['product_category' => 'Duft-Sets']))
        ->assertOk()
        ->assertSee('Duft-Sets');
});

it('rejects unknown canonical IDs rather than selecting another similarly named record', function () {
    $this->actingAs($this->manager)
        ->get(route('products.show', ['product' => 'id-999999999']))
        ->assertNotFound();

    $this->get(route('suppliers.edit', ['supplier' => 'id-999999999']))
        ->assertNotFound();

    $this->get(route('product-categories.show', ['product_category' => 'id-999999999']))
        ->assertNotFound();
});
