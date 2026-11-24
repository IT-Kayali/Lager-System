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

it('renders Arabic warehouse notes as joined right-aligned glyphs and preserves Latin lines', function () {
    $manager = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
    $this->actingAs($manager);

    $withdrawal = branchNotePdfWithdrawal("Bitte vorsichtig behandeln.\nيرجى تسليم البضاعة إلى الفرع رقم 6");
    $html = branchNotePdfHtml($withdrawal);

    expect($html)
        ->toContain('Bitte vorsichtig behandeln.')
        ->toContain('class="branch-note-line arabic"')
        ->toContain('font-family: DejaVu Sans, sans-serif;')
        ->not->toContain('يرجى تسليم البضاعة');

    expect(preg_match('/[\x{FB50}-\x{FEFF}]/u', $html))->toBe(1);

    $this->get(route('branch-withdrawals.delivery-note', $withdrawal))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
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

it('reserves enough height for wide glyphs in notes and item rows', function () {
    $pages = BranchWithdrawalDeliveryNoteLayout::paginate(
        collect([(object) [
            'description' => str_repeat('W', 70),
            'quantity' => str_repeat('W', 26),
        ]]),
        str_repeat('W', 76)
    );

    expect($pages)->toHaveCount(1);

    $page = $pages->first();

    // 76 breite W werden vor der PDF-Darstellung auf mehrere Zeilen verteilt.
    expect($page['note_lines'])->toHaveCount(2);

    foreach ($page['note_lines'] as $line) {
        expect(mb_strwidth($line, 'UTF-8'))->toBeLessThanOrEqual(40);
    }

    // Drei Zeilen Artikelbezeichnung plus Tabellenkopf und zwei Notizzeilen.
    expect($page['used_mm'])->toBeGreaterThanOrEqual(49.5)
        ->and($page['used_mm'])->toBeLessThanOrEqual($page['available_mm']);
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

it('shows only the newest assigned batch beneath the product and includes a position column', function () {
    $manager = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
    $this->actingAs($manager);

    $withdrawal = branchNotePdfWithdrawal('Ware prüfen.');
    $withdrawal->items()->firstOrFail()->update([
        'batch_allocations' => [
            ['batch_id' => 99, 'batch_number' => 'OLD-BATCH', 'received_at' => '2026-01-01', 'quantity' => 1],
            ['batch_id' => 2, 'batch_number' => 'NEW-BATCH', 'received_at' => '2026-02-01', 'quantity' => 1.5],
            ['batch_id' => 100, 'batch_number' => 'UNUSED-BATCH', 'received_at' => '2026-03-01', 'quantity' => 0],
        ],
    ]);

    $html = branchNotePdfHtml($withdrawal->fresh('items.product'));
    expect($html)
        ->toContain('<th style="width:8%;text-align:center">Pos.</th>')
        ->toContain('<td>1</td>')
        ->toContain('class="batch-number">Batch: NEW-BATCH')
        ->not->toContain('OLD-BATCH')
        ->not->toContain('UNUSED-BATCH');
});

it('reserves additional page space for batch lines without resetting positions', function () {
    $items = collect(range(1, 25))->map(fn (int $position) => (object) [
        'position' => $position,
        'description' => 'Produkt ' . $position,
        'quantity' => '12 gram',
        'batch_number' => str_repeat('W', 72),
    ]);

    $pages = BranchWithdrawalDeliveryNoteLayout::paginate($items, 'Lagernotiz');
    expect($pages->count())->toBeGreaterThan(1)
        ->and($pages->flatMap(fn (array $page) => $page['items'])->pluck('position')->all())->toBe(range(1, 25));

    foreach ($pages as $page) {
        expect($page['used_mm'])->toBeLessThanOrEqual($page['available_mm']);
    }
});

it('selects assigned batches by receipt date and uses the ID only to break ties', function () {
    $allocations = [
        ['batch_id' => 100, 'batch_number' => 'OLD', 'received_at' => '2026-01-01', 'quantity' => 1],
        ['batch_id' => 2, 'batch_number' => 'TIE-OLDER', 'received_at' => '2026-02-01', 'quantity' => 1],
        ['batch_id' => 3, 'batch_number' => 'TIE-NEWEST', 'received_at' => '2026-02-01', 'quantity' => 1],
        ['batch_id' => 4, 'batch_number' => 'NOT-USED', 'received_at' => '2026-03-01', 'quantity' => 0],
    ];

    expect(\App\Support\DeliveryNoteBatchNumber::latest($allocations))->toBe('TIE-NEWEST')
        ->and(\App\Support\DeliveryNoteBatchNumber::latest([]))->toBeNull();
});
