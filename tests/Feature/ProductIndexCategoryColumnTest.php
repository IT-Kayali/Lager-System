<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;

function categoryColumnUser(string $role = User::ROLE_MANAGER): User
{
    return User::factory()->create([
        'role' => $role,
        'is_active' => true,
    ]);
}

function categoryColumnProduct(string $name): Product
{
    return Product::query()->create([
        'name' => $name,
        'unit' => 'piece',
        'minimum_stock' => 0,
    ]);
}

it('zeigt alle zugeordneten Kategorien in der Produktübersicht', function () {
    $firstCategory = ProductCategory::query()->create([
        'name' => 'Testkategorie Alpha',
        'priority' => 1,
        'is_active' => true,
    ]);

    $secondCategory = ProductCategory::query()->create([
        'name' => 'Testkategorie Beta',
        'priority' => 2,
        'is_active' => true,
    ]);

    $product = categoryColumnProduct('Kategorie-Testprodukt');
    $product->categories()->attach([$firstCategory->id, $secondCategory->id]);

    $this->actingAs(categoryColumnUser())
        ->get(route('products.index'))
        ->assertOk()
        ->assertSee('<th>Kategorie</th>', false)
        ->assertSee('Testkategorie Alpha')
        ->assertSee('Testkategorie Beta')
        ->assertSee('Kategorie-Testprodukt');
});

it('zeigt Ohne Kategorie bei Produkten ohne Kategoriezuordnung', function () {
    categoryColumnProduct('Unkategorisiertes Testprodukt');

    $this->actingAs(categoryColumnUser(User::ROLE_WAREHOUSE))
        ->get(route('products.index'))
        ->assertOk()
        ->assertSee('Unkategorisiertes Testprodukt')
        ->assertSee('Ohne Kategorie');
});

it('behält die korrekte Tabellenbreite ohne Produkte', function () {
    $this->actingAs(categoryColumnUser())
        ->get(route('products.index'))
        ->assertOk()
        ->assertSee('<td colspan="8">', false);
});
