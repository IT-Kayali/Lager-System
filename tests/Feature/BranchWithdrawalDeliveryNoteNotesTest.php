<?php

use App\Models\BranchWithdrawal;
use App\Models\DocumentTemplate;
use App\Models\Product;
use App\Models\User;
use App\Support\BranchWithdrawalDeliveryNoteLayout;

function branchNotePdfWithdrawal(?string $note): BranchWithdrawal
{
    $product = Product::query()->create([
        'name' => 'Prüfprodukt Lagernotiz',
        'unit' => 'gram',
        'minimum_stock' => 0,
    ]);

    $withdrawal = BranchWithdrawal::query()->create([
        'product_id' => $product->id,
        'user_id' => auth()->id(),
        'withdrawal_number' => 'FIL-NOTE-' . uniqid(),
        'branch_name' => BranchWithdrawal::BRANCH_DEZ,
        'status' => BranchWithdrawal::STATUS_ISSUED,
        'quantity' => 2.5,
        'stock_before' => 0,
        'stock_after' => 0,
        'batch_allocations' => [],
        'note' => $note,
    ]);

    $withdrawal->items()->create([
        'product_id' => $product->id,
        'quantity' => 2.5,
        'stock_before' => 0,
        'stock_after' => 0,
        'batch_allocations' => [],
    ]);

    return $withdrawal->load('items.product');
}

function branchNotePdfHtml(BranchWithdrawal $withdrawal): string
{
    $template = DocumentTemplate::query()->updateOrCreate(
        ['key' => DocumentTemplate::WITH_COMPANY],
        [
            'name' => 'Mit Firmendaten & Logo',
            'company_name' => 'Alowidat Test',
            'show_company_details' => true,
            'show_logo' => false,
        ]
    );

    return view('pdf.branch-withdrawal-delivery-note', [
        'branchWithdrawal' => $withdrawal,
        'template' => $template,
        'logoDataUri' => null,
    ])->render();
}

it('shows warehouse notes below the product table and keeps their line breaks safely escaped', function () {
    $manager = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
    $this->actingAs($manager);

    $withdrawal = branchNotePdfWithdrawal("Erste Zeile & <script>alert(1)</script>\nZweite Zeile");
    $html = branchNotePdfHtml($withdrawal);

    expect($html)
        ->toContain('class="branch-note"')
        ->toContain('Lagernotiz')
        ->toContain('Erste Zeile &amp; &lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('Zweite Zeile')
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('class="ownership"')
        ->toContain('Seite 1 / 1');

    expect(strpos($html, 'class="items"'))->toBeLessThan(strpos($html, 'class="branch-note"'));
});

it('omits the note block for an empty warehouse note', function () {
    $manager = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
    $this->actingAs($manager);

    $html = branchNotePdfHtml(branchNotePdfWithdrawal('   '));

    expect($html)->not->toContain('class="branch-note"')
        ->toContain('class="items"');
});

it('splits long notes across pages without consuming the ownership or footer area', function () {
    $items = collect(range(1, 40))->map(fn (int $index) => (object) [
        'description' => 'Prüfprodukt ' . $index,
        'quantity' => '2,5 gram',
    ]);

    $note = str_repeat('Bitte vor Übergabe alle Positionen und Chargen prüfen. ', 85);
    $pages = BranchWithdrawalDeliveryNoteLayout::paginate($items, $note);

    expect($pages->count())->toBeGreaterThan(3)
        ->and($pages->flatMap(fn (array $page) => $page['items'])->count())->toBe(40)
        ->and($pages->flatMap(fn (array $page) => $page['note_lines'])->count())->toBeGreaterThan(30)
        ->and($pages->last()['note_continued'])->toBeTrue();

    foreach ($pages as $page) {
        expect($page['used_mm'])->toBeLessThanOrEqual($page['available_mm']);
    }

    expect($pages->filter(fn (array $page) => $page['note_lines'] !== [])->count())
        ->toBeGreaterThan(1);
});

it('keeps a short warehouse note on the first page with a single product', function () {
    $pages = BranchWithdrawalDeliveryNoteLayout::paginate(
        collect([(object) ['description' => 'Produkt', 'quantity' => '2,5 gram']]),
        'Ware bitte intern prüfen.'
    );

    expect($pages)->toHaveCount(1);
    expect($pages->first()['note_lines'])->toBe(['Ware bitte intern prüfen.']);
    expect($pages->first()['note_continued'])->toBeFalse();
});

it('keeps the existing PDF role access and streams notes for warehouse staff', function () {
    $manager = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
    $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
    $sales = User::factory()->create(['role' => User::ROLE_SALES, 'is_active' => true]);

    $this->actingAs($manager);
    $withdrawal = branchNotePdfWithdrawal('Nur für die interne Übergabe');

    // Die PDF-Route benötigt eine gespeicherte Firmenvorlage (byKey/firstOrFail).
    DocumentTemplate::query()->updateOrCreate(
        ['key' => DocumentTemplate::WITH_COMPANY],
        [
            'name' => 'Mit Firmendaten & Logo',
            'company_name' => 'Alowidat Test',
            'show_company_details' => true,
            'show_logo' => false,
        ]
    );

    $this->actingAs($warehouse)
        ->get(route('branch-withdrawals.delivery-note', $withdrawal))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($sales)
        ->get(route('branch-withdrawals.delivery-note', $withdrawal))
        ->assertForbidden();
});
