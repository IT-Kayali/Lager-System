<?php

use App\Models\CustomerGroup;
use App\Models\ManualPriceRule;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPriceTier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function erp11Manager(): User
{
    return User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
}

function erp11Group(string $name): CustomerGroup
{
    return CustomerGroup::query()->create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name)]);
}

function erp11Product(string $name): Product
{
    return Product::query()->create(['name' => $name, 'unit' => 'gram', 'minimum_stock' => 0]);
}

function erp11Workbook(Tests\TestCase $test, string $groupId = 'all', bool $template = false): Spreadsheet
{
    $route = $template ? 'prices.excel.template' : 'prices.excel.export';
    $response = $test->get(route($route, ['customer_group_id' => $groupId]))->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    try {
        return IOFactory::load($path);
    } finally {
        @unlink($path);
    }
}

function erp11Upload(Spreadsheet $spreadsheet): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'erp11-');
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return new UploadedFile($path, 'preise.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

it('erzeugt dynamische Excel-Vorlagen für neue Kundengruppen und alle Gruppen', function () {
    $alpha = erp11Group('Platin');
    $beta = erp11Group('Silber Neu');
    $product = erp11Product('Duft 11');

    $this->actingAs(erp11Manager());
    $single = erp11Workbook($this, (string) $alpha->id, true);
    $singleSheet = $single->getSheetByName('Preise');
    expect($singleSheet->getHighestDataRow())->toBeGreaterThan(1)
        ->and((string) $singleSheet->getCell('B2')->getValue())->toBe($alpha->name)
        ->and($singleSheet->getCell('H2')->getValue())->toBeNull()
        ->and((int) $singleSheet->getCell('J2')->getValue())->toBe($product->id)
        ->and((int) $singleSheet->getCell('K2')->getValue())->toBe($alpha->id);
    $single->disconnectWorksheets();

    $all = erp11Workbook($this);
    $sheet = $all->getSheetByName('Preise');
    $names = [];
    for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
        $names[] = (string) $sheet->getCell('B' . $row)->getValue();
    }
    expect($names)->toContain($alpha->name, $beta->name);
    $all->disconnectWorksheets();
});

it('zeigt Änderungen erst in der Vorschau und aktualisiert nur die bestätigte Gruppe', function () {
    $alpha = erp11Group('Gruppe A');
    $beta = erp11Group('Gruppe B');
    $product = erp11Product('Preisprodukt A');
    $a = ProductPriceTier::query()->where('product_id', $product->id)->where('customer_group_id', $alpha->id)->orderBy('id')->firstOrFail();
    $b = ProductPriceTier::query()->where('product_id', $product->id)->where('customer_group_id', $beta->id)->orderBy('id')->firstOrFail();

    $this->actingAs(erp11Manager());
    $workbook = erp11Workbook($this, (string) $alpha->id, true);
    $workbook->getSheetByName('Preise')->setCellValue('H2', 12.75);
    $upload = erp11Upload($workbook);

    try {
        $preview = $this->post(route('prices.excel.import'), [
            'excel_file' => $upload,
            'customer_group_id' => (string) $alpha->id,
        ])->assertOk();
        expect((float) $a->fresh()->price)->toBe(0.0);
        expect($preview->viewData('preview')['rows'])->toHaveCount(1);

        $this->post(route('prices.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'),
            'selected_rows' => [2],
        ])->assertRedirect(route('prices.index'));

        expect((float) $a->fresh()->price)->toBe(12.75)
            ->and((float) $b->fresh()->price)->toBe(0.0);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('überspringt leere Preise, übernimmt ausdrücklich 0 und bewahrt andere Zeilen', function () {
    $group = erp11Group('Gruppe Null');
    $product = erp11Product('Preisprodukt B');
    $rules = ProductPriceTier::query()->where('product_id', $product->id)->where('customer_group_id', $group->id)->orderBy('min_grams')->get();
    $rules[0]->update(['price' => 4.5]);
    $rules[1]->update(['price' => 9.5]);

    $this->actingAs(erp11Manager());
    $workbook = erp11Workbook($this, (string) $group->id, true);
    $workbook->getSheetByName('Preise')->setCellValue('H2', 0);
    $upload = erp11Upload($workbook);

    try {
        $preview = $this->post(route('prices.excel.import'), [
            'excel_file' => $upload,
            'customer_group_id' => (string) $group->id,
        ])->assertOk();

        $this->post(route('prices.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'),
            'selected_rows' => [2],
        ])->assertRedirect();

        expect((float) $rules[0]->fresh()->price)->toBe(0.0)
            ->and((float) $rules[1]->fresh()->price)->toBe(9.5);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('weist Gruppenverwechslung und doppelte Preiszeilen vor dem Schreiben zurück', function () {
    $alpha = erp11Group('Fremd A');
    $beta = erp11Group('Fremd B');
    erp11Product('Preisprodukt C');
    $this->actingAs(erp11Manager());
    $workbook = erp11Workbook($this, (string) $alpha->id, true);
    $workbook->getSheetByName('Preise')->setCellValue('H2', 11);
    $upload = erp11Upload($workbook);

    try {
        $this->post(route('prices.excel.import'), [
            'excel_file' => $upload,
            'customer_group_id' => (string) $beta->id,
        ])->assertSessionHasErrors('excel_file');
    } finally {
        @unlink($upload->getRealPath());
    }

    $workbook = erp11Workbook($this, (string) $alpha->id, true);
    $sheet = $workbook->getSheetByName('Preise');
    $sheet->setCellValue('H2', 12);
    $sheet->fromArray([$sheet->rangeToArray('A2:L2')[0]], null, 'A' . ($sheet->getHighestDataRow() + 1));
    $upload = erp11Upload($workbook);
    try {
        $this->post(route('prices.excel.import'), [
            'excel_file' => $upload,
            'customer_group_id' => (string) $alpha->id,
        ])->assertSessionHasErrors('excel_file');
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('erkennt Preisänderungen seit der Vorschau und rollt die Übernahme zurück', function () {
    $group = erp11Group('Parallel');
    $product = erp11Product('Preisprodukt D');
    $rule = ProductPriceTier::query()->where('product_id', $product->id)->where('customer_group_id', $group->id)->orderBy('id')->firstOrFail();

    $this->actingAs(erp11Manager());
    $workbook = erp11Workbook($this, (string) $group->id, true);
    $workbook->getSheetByName('Preise')->setCellValue('H2', 7.75);
    $upload = erp11Upload($workbook);
    try {
        $preview = $this->post(route('prices.excel.import'), [
            'excel_file' => $upload, 'customer_group_id' => (string) $group->id,
        ])->assertOk();

        $rule->update(['price' => 4.25]);
        $this->post(route('prices.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'), 'selected_rows' => [2],
        ])->assertRedirect(route('prices.excel.import.form'))->assertSessionHas('error');
        expect((float) $rule->fresh()->price)->toBe(4.25);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('aktualisiert vorhandene manuelle Mengenpreise ohne Regeln zu löschen', function () {
    $group = erp11Group('Manuell A');
    $product = erp11Product('Preisprodukt M');
    $category = ProductCategory::query()->create(['name' => 'Manuelle Preise', 'price_tiers_enabled' => false]);
    $product->categories()->attach($category->id);
    $manual = ManualPriceRule::query()->create([
        'product_id' => $product->id, 'customer_group_id' => $group->id,
        'min_quantity' => 1, 'max_quantity' => null, 'price' => 4.00, 'label' => 'ab 1',
    ]);

    $this->actingAs(erp11Manager());
    $workbook = erp11Workbook($this, (string) $group->id, true);
    $sheet = $workbook->getSheetByName('Preise');
    expect((string) $sheet->getCell('C2')->getValue())->toBe('manuell');
    $sheet->setCellValue('H2', 8.12345);
    $upload = erp11Upload($workbook);

    try {
        $preview = $this->post(route('prices.excel.import'), [
            'excel_file' => $upload, 'customer_group_id' => (string) $group->id,
        ])->assertOk();
        $this->post(route('prices.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'), 'selected_rows' => [2],
        ])->assertRedirect(route('prices.index'));

        expect((float) $manual->fresh()->price)->toBe(8.12345)
            ->and(ManualPriceRule::query()->where('product_id', $product->id)->count())->toBe(1);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('verhindert Preisexport und -import für Lagerrollen', function () {
    $warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
    $this->actingAs($warehouse)->get(route('prices.excel.export'))->assertForbidden();
    $this->get(route('prices.excel.template'))->assertForbidden();
    $this->get(route('prices.excel.import.form'))->assertForbidden();
    $this->post(route('prices.excel.import'), [])->assertForbidden();
});


it('exportiert und importiert Staffelpreise mit fünf Nachkommastellen', function () {
    $group = erp11Group('Präzision');
    $product = erp11Product('Fünf Stellen');
    $tier = ProductPriceTier::query()
        ->where('product_id', $product->id)
        ->where('customer_group_id', $group->id)
        ->orderBy('min_grams')
        ->firstOrFail();
    $tier->update(['price' => '0.12345']);

    $this->actingAs(erp11Manager());
    $spreadsheet = erp11Workbook($this, (string) $group->id);
    $sheet = $spreadsheet->getSheetByName('Preise');

    expect((float) $sheet->getCell('H2')->getValue())->toBe(0.12345)
        ->and($sheet->getStyle('H2')->getNumberFormat()->getFormatCode())->toBe('0.00000');

    $sheet->setCellValue('H2', 0.00001);
    $upload = erp11Upload($spreadsheet);
    try {
        $preview = $this->post(route('prices.excel.import'), [
            'excel_file' => $upload,
            'customer_group_id' => (string) $group->id,
        ])->assertOk();

        $this->post(route('prices.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'),
            'selected_rows' => [2],
        ])->assertRedirect(route('prices.index'));

        expect((string) $tier->fresh()->price)->toBe('0.00001');
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('erzeugt auch für viele Produkte und mehrere Gruppen einen vollständigen XLSX-Export', function () {
    erp11Group('Großexport A');
    erp11Group('Großexport B');

    for ($i = 1; $i <= 100; $i++) {
        erp11Product('Großexport Produkt ' . $i);
    }

    $this->actingAs(erp11Manager());
    $spreadsheet = erp11Workbook($this);
    $sheet = $spreadsheet->getSheetByName('Preise');

    expect($sheet->getHighestDataRow())->toBeGreaterThanOrEqual(1000)
        ->and($spreadsheet->getSheetByName('Anleitung'))->not->toBeNull();

    $spreadsheet->disconnectWorksheets();
});

it('speichert fünfstellige Preise auch über die normale Preisverwaltung', function () {
    $group = erp11Group('Direkte Eingabe');
    $product = erp11Product('Fünf Dezimalstellen');
    $tier = ProductPriceTier::query()
        ->where('product_id', $product->id)
        ->where('customer_group_id', $group->id)
        ->orderBy('min_grams')->firstOrFail();

    $this->actingAs(erp11Manager())
        ->put(route('prices.update'), [
            'product_id' => $product->id,
            'customer_group_id' => $group->id,
            'prices' => [$tier->id => '12.34567'],
        ])->assertRedirect();

    expect((string) $tier->fresh()->price)->toBe('12.34567');
});

it('prueft auch einen ausgefuellten Gesamtpreisexport mit gebuendelten Datenbankabfragen', function () {
    erp11Group('Importpaket A');
    erp11Group('Importpaket B');
    for ($i = 1; $i <= 60; $i++) {
        erp11Product('Preisimport Produkt ' . $i);
    }

    $this->actingAs(erp11Manager());
    $spreadsheet = erp11Workbook($this, 'all');
    $sheet = $spreadsheet->getSheetByName('Preise');
    expect($sheet->getHighestDataRow())->toBeGreaterThan(500);

    // Beim Export sind alle Preiszellen gefuellt, auch unveraenderte.
    // Nur die erste Zeile weicht von der Datenbank ab.
    $sheet->setCellValue('H2', 0.12345);
    $upload = erp11Upload($spreadsheet);

    $selects = 0;
    \Illuminate\Support\Facades\DB::listen(function ($query) use (&$selects): void {
        if (preg_match('/\bfrom\s+[`"]?product_price_tiers/i', $query->sql)) {
            $selects++;
        }
    });

    try {
        $response = $this->post(route('prices.excel.import'), [
            'excel_file' => $upload,
            'customer_group_id' => 'all',
        ])->assertOk();

        expect($response->viewData('preview')['rows'])->toHaveCount(1)
            ->and($selects)->toBeLessThan(20);
    } finally {
        @unlink($upload->getRealPath());
    }
});


it('exportiert Stückprodukte ohne vorhandene manuelle Regel und legt sie erst nach Bestätigung an', function () {
    $group = erp11Group('Stückpreise');
    $product = Product::query()->create(['name' => 'Karton Stück', 'unit' => 'piece', 'minimum_stock' => 0]);
    $category = ProductCategory::query()->create(['name' => 'Stück ohne Staffel', 'price_tiers_enabled' => false]);
    $product->categories()->attach($category->id);

    $this->actingAs(erp11Manager());
    $workbook = erp11Workbook($this, (string) $group->id);
    $sheet = $workbook->getSheetByName('Preise');

    expect($sheet->getHighestDataRow())->toBe(2)
        ->and((string) $sheet->getCell('C2')->getValue())->toBe('manuell')
        ->and((string) $sheet->getCell('G2')->getValue())->toBe('Stück')
        ->and((int) $sheet->getCell('L2')->getValue())->toBe(0)
        ->and($sheet->getCell('H2')->getValue())->toBeNull();

    $sheet->setCellValue('H2', 3.12345);
    $upload = erp11Upload($workbook);

    try {
        $preview = $this->post(route('prices.excel.import'), [
            'excel_file' => $upload,
            'customer_group_id' => (string) $group->id,
        ])->assertOk();

        expect($preview->viewData('preview')['rows'])->toHaveCount(1)
            ->and($preview->viewData('preview')['rows'][0]['create'])->toBeTrue()
            ->and(ManualPriceRule::query()->count())->toBe(0);

        $this->postJson(route('prices.excel.import.begin'), [
            'preview_token' => $preview->viewData('previewToken'),
            'excluded_rows_csv' => '',
        ])->assertOk()->assertJsonPath('total', 1);

        $this->postJson(route('prices.excel.import.batch'), [
            'preview_token' => $preview->viewData('previewToken'),
        ])->assertOk()->assertJsonPath('done', true);

        $rule = ManualPriceRule::query()->firstOrFail();
        expect($rule->product_id)->toBe($product->id)
            ->and($rule->customer_group_id)->toBe($group->id)
            ->and((string) $rule->min_quantity)->toBe('1.000')
            ->and($rule->max_quantity)->toBeNull()
            ->and((string) $rule->price)->toBe('3.12345');
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('legt bei leerem Preis keine neue manuelle Regel an', function () {
    $group = erp11Group('Stück leer');
    $product = Product::query()->create(['name' => 'Stück ungeändert', 'unit' => 'piece', 'minimum_stock' => 0]);
    $category = ProductCategory::query()->create(['name' => 'Stück ohne Preis', 'price_tiers_enabled' => false]);
    $product->categories()->attach($category->id);

    $this->actingAs(erp11Manager());
    $workbook = erp11Workbook($this, (string) $group->id, true);
    $upload = erp11Upload($workbook);

    try {
        $preview = $this->post(route('prices.excel.import'), [
            'excel_file' => $upload,
            'customer_group_id' => (string) $group->id,
        ])->assertOk();

        expect($preview->viewData('preview')['rows'])->toHaveCount(0)
            ->and(ManualPriceRule::query()->count())->toBe(0);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('übernimmt mehr als hundert Preise in einzelnen Paketen ohne Input-Limit', function () {
    $group = erp11Group('Paketimport');
    for ($i = 1; $i <= 21; $i++) {
        erp11Product('Paketprodukt ' . $i);
    }

    $this->actingAs(erp11Manager());
    $workbook = erp11Workbook($this, (string) $group->id, true);
    $sheet = $workbook->getSheetByName('Preise');
    $last = $sheet->getHighestDataRow();
    expect($last)->toBeGreaterThan(102);

    for ($line = 2; $line <= $last; $line++) {
        $sheet->setCellValue('H' . $line, 7.00001);
    }
    $upload = erp11Upload($workbook);

    try {
        $preview = $this->post(route('prices.excel.import'), [
            'excel_file' => $upload,
            'customer_group_id' => (string) $group->id,
        ])->assertOk();
        $total = count($preview->viewData('preview')['rows']);
        expect($total)->toBe($last - 1);
        $token = $preview->viewData('previewToken');

        $this->postJson(route('prices.excel.import.begin'), [
            'preview_token' => $token,
            'excluded_rows_csv' => '2,3',
        ])->assertOk()->assertJsonPath('total', $total - 2);

        $this->postJson(route('prices.excel.import.batch'), [
            'preview_token' => $token,
        ])->assertOk()->assertJsonPath('processed', 100)->assertJsonPath('done', false);

        // Erneutes Begin darf die schon gespeicherten Pakete nicht zurücksetzen.
        $this->postJson(route('prices.excel.import.begin'), [
            'preview_token' => $token,
        ])->assertOk()->assertJsonPath('processed', 100);

        $this->postJson(route('prices.excel.import.batch'), [
            'preview_token' => $token,
        ])->assertOk()->assertJsonPath('processed', $total - 2)->assertJsonPath('done', true);

        expect(ProductPriceTier::query()->where('customer_group_id', $group->id)->where('price', '7.00001')->count())->toBe($total - 2)
            ->and(ProductPriceTier::query()->where('customer_group_id', $group->id)->where('price', 0)->count())->toBe(2);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('weist gefälschte Zeilenausschlüsse vor der Importbestätigung zurück', function () {
    $group = erp11Group('Ungültige Auswahl');
    erp11Product('Auswahlprodukt');

    $this->actingAs(erp11Manager());
    $workbook = erp11Workbook($this, (string) $group->id, true);
    $workbook->getSheetByName('Preise')->setCellValue('H2', 9.0);
    $upload = erp11Upload($workbook);
    try {
        $preview = $this->post(route('prices.excel.import'), [
            'excel_file' => $upload, 'customer_group_id' => (string) $group->id,
        ])->assertOk();

        $this->postJson(route('prices.excel.import.begin'), [
            'preview_token' => $preview->viewData('previewToken'),
            'excluded_rows_csv' => '99999',
        ])->assertStatus(422);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('verhindert die Anlage einer Stückregel bei Änderung nach der Vorschau', function () {
    $group = erp11Group('Parallel Stück');
    $product = Product::query()->create(['name' => 'Stück Parallel', 'unit' => 'piece', 'minimum_stock' => 0]);
    $category = ProductCategory::query()->create(['name' => 'Stück manuell parallel', 'price_tiers_enabled' => false]);
    $product->categories()->attach($category->id);

    $this->actingAs(erp11Manager());
    $workbook = erp11Workbook($this, (string) $group->id, true);
    $workbook->getSheetByName('Preise')->setCellValue('H2', 9.0);
    $upload = erp11Upload($workbook);
    try {
        $preview = $this->post(route('prices.excel.import'), [
            'excel_file' => $upload, 'customer_group_id' => (string) $group->id,
        ])->assertOk();

        ManualPriceRule::query()->create([
            'product_id' => $product->id, 'customer_group_id' => $group->id,
            'min_quantity' => 1, 'max_quantity' => null, 'price' => 4,
        ]);

        $this->postJson(route('prices.excel.import.begin'), [
            'preview_token' => $preview->viewData('previewToken'),
        ])->assertOk();
        $this->postJson(route('prices.excel.import.batch'), [
            'preview_token' => $preview->viewData('previewToken'),
        ])->assertStatus(422);

        expect(ManualPriceRule::query()->count())->toBe(1)
            ->and((float) ManualPriceRule::query()->firstOrFail()->price)->toBe(4.0);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('begrenzt die Auswahl auch bei 2000 Preiszeilen nicht auf max_input_vars', function () {
    $user = erp11Manager();
    $this->actingAs($user);

    $token = \Illuminate\Support\Str::random(48);
    $rows = array_map(fn (int $line) => ['line' => $line], range(2, 2002));
    \Illuminate\Support\Facades\Cache::put(
        'price-excel-import:' . $user->id . ':' . $token,
        ['scope' => 'all', 'rows' => $rows],
        now()->addMinutes(30)
    );

    $this->postJson(route('prices.excel.import.begin'), [
        'preview_token' => $token,
        'excluded_rows_csv' => '2,3',
    ])->assertOk()
        ->assertJsonPath('total', 1999)
        ->assertJsonPath('processed', 0)
        ->assertJsonPath('done', false);
});

it('zeigt Staffelpreise im Formular mit deutschem Komma und allen fünf Dezimalstellen', function () {
    $group = erp11Group('Anzeige fünf Stellen');
    $product = erp11Product('Anzeige Staffel');
    $tier = ProductPriceTier::query()
        ->where('product_id', $product->id)
        ->where('customer_group_id', $group->id)
        ->orderBy('min_grams')
        ->firstOrFail();

    $tier->update(['price' => '12.34567']);

    $this->actingAs(erp11Manager())
        ->get(route('prices.index', [
            'product_id' => $product->id,
            'customer_group_id' => $group->id,
        ]))
        ->assertOk()
        ->assertSee('value="12,34567"', false)
        ->assertSee('inputmode="decimal"', false);
});

it('speichert deutsche Preisangaben mit fünf Dezimalstellen im Staffelbereich', function () {
    $group = erp11Group('Deutsche Staffel');
    $product = erp11Product('Kommapreis');
    $tier = ProductPriceTier::query()
        ->where('product_id', $product->id)
        ->where('customer_group_id', $group->id)
        ->orderBy('min_grams')
        ->firstOrFail();

    $this->actingAs(erp11Manager())
        ->put(route('prices.update'), [
            'product_id' => $product->id,
            'customer_group_id' => $group->id,
            'prices' => [$tier->id => '12,34567'],
        ])
        ->assertRedirect();

    expect((string) $tier->fresh()->price)->toBe('12.34567');

    $this->put(route('prices.update'), [
        'product_id' => $product->id,
        'customer_group_id' => $group->id,
        'prices' => [$tier->id => '12,345678'],
    ])->assertSessionHasErrors('prices.' . $tier->id);

    expect((string) $tier->fresh()->price)->toBe('12.34567');
});

it('zeigt und speichert manuelle Stückpreise mit fünf Dezimalstellen', function () {
    $group = erp11Group('Deutsche Stückpreise');
    $product = Product::query()->create([
        'name' => 'Fünfstelliger Stückpreis',
        'unit' => 'piece',
        'minimum_stock' => 0,
    ]);
    $category = ProductCategory::query()->create([
        'name' => 'Fünfstellige manuelle Preise',
        'price_tiers_enabled' => false,
    ]);
    $product->categories()->attach($category->id);

    $rule = ManualPriceRule::query()->create([
        'product_id' => $product->id,
        'customer_group_id' => $group->id,
        'min_quantity' => 1,
        'max_quantity' => null,
        'price' => '3.00001',
    ]);

    $this->actingAs(erp11Manager())
        ->get(route('prices.index', [
            'product_id' => $product->id,
            'customer_group_id' => $group->id,
        ]))
        ->assertOk()
        ->assertSee('value="3,00001"', false);

    $this->put(route('prices.update'), [
        'product_id' => $product->id,
        'customer_group_id' => $group->id,
        'rules' => [[
            'min_quantity' => '1',
            'max_quantity' => '',
            'price' => '17,34567',
            'label' => '',
        ]],
    ])->assertRedirect();

    expect(ManualPriceRule::query()
        ->where('product_id', $product->id)
        ->where('customer_group_id', $group->id)
        ->count())->toBe(1);
    expect((string) ManualPriceRule::query()
        ->where('product_id', $product->id)
        ->where('customer_group_id', $group->id)
        ->firstOrFail()->price)->toBe('17.34567');
});
