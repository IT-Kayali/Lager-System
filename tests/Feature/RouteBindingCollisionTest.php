<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\User;

function namedRouteProduct(string $name): Product
{
    return Product::create([
        'name' => $name,
        'unit' => 'piece',
        'minimum_stock' => 0,
    ]);
}

function namedRouteManager(): User
{
    return User::factory()->create([
        'role' => User::ROLE_MANAGER,
        'is_active' => true,
    ]);
}

it('verwendet den exakten Produktnamen bei numerischen Namen', function () {
    $other = namedRouteProduct('Anderes Produkt');
    $product = namedRouteProduct((string) $other->id);

    expect(route('products.show', $product))
        ->toEndWith('/products/' . $other->id);

    $this->actingAs(namedRouteManager())
        ->get(route('products.show', $product))
        ->assertOk()
        ->assertSee(
            '<h1>' . $product->name . '</h1>',
            false
        );

    $this->get(route('products.edit', $product))
        ->assertOk()
        ->assertSee(
            'value="' . $product->name . '"',
            false
        );
});

it('aktualisiert den richtigen Datensatz bei Namens-ID-Kollision', function () {
    $other = namedRouteProduct('Ursprungsprodukt');
    $product = namedRouteProduct((string) $other->id);

    $this->actingAs(namedRouteManager())
        ->put(route('products.update', $product), [
            'name' => 'Geändertes Produkt',
            'unit' => 'piece',
            'minimum_stock' => 0,
        ])
        ->assertRedirect(route('products.index'));

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Geändertes Produkt',
    ]);

    $this->assertDatabaseHas('products', [
        'id' => $other->id,
        'name' => 'Ursprungsprodukt',
    ]);
});

it('unterstützt ältere numerische ID-Links ohne Namenskollision', function () {
    $product = namedRouteProduct('Legacy Produkt');

    $this->actingAs(namedRouteManager())
        ->get(route('products.show', [
            'product' => $product->id,
        ]))
        ->assertOk()
        ->assertSee('<h1>Legacy Produkt</h1>', false);
});

it('verwendet auch in anderen Ansichten den Produktnamen', function () {
    $supplier = Supplier::create([
        'company_name' => 'Testlieferant',
    ]);

    $category = ProductCategory::create([
        'name' => 'Testkategorie',
        'priority' => 1,
        'is_active' => true,
    ]);

    $product = namedRouteProduct('3A');
    $product->supplier_id = $supplier->id;
    $product->save();
    $product->categories()->attach($category->id);

    $url = route('products.show', $product);

    expect($url)->toEndWith('/products/3A');

    $this->actingAs(namedRouteManager())
        ->get(route('product-categories.show', $category))
        ->assertOk()
        ->assertSee('href="' . $url . '"', false);

    $this->get(route('suppliers.show', $supplier))
        ->assertOk()
        ->assertSee('href="' . $url . '"', false);
});

it('kodiert Leerzeichen in Produktnamen korrekt', function () {
    $product = namedRouteProduct('Rose Duft');

    expect(route('products.show', $product))
        ->toEndWith('/products/Rose%20Duft');

    $this->actingAs(namedRouteManager())
        ->get(route('products.show', $product))
        ->assertOk()
        ->assertSee('<h1>Rose Duft</h1>', false);
});

it('liefert bei einem unbekannten nichtnumerischen Namen 404', function () {
    $this->actingAs(namedRouteManager())
        ->get(route('products.show', [
            'product' => 'Unbekanntes-Produkt-XYZ',
        ]))
        ->assertNotFound();
});
