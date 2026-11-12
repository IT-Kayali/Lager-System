<?php

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function erp02Manager(): User
{
    return User::factory()->create([
        'role' => User::ROLE_MANAGER,
        'is_active' => true,
    ]);
}

function erp02Product(string $name, ?Supplier $supplier = null): Product
{
    return Product::query()->create([
        'name' => $name,
        'unit' => 'piece',
        'minimum_stock' => 0,
        'supplier_id' => $supplier?->id,
    ]);
}

function erp02Upload(array $headers, array $values): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Produkte');
    $sheet->fromArray([$headers, $values]);

    $path = tempnam(sys_get_temp_dir(), 'erp02-');
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return new UploadedFile(
        $path,
        'produkte.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true
    );
}

it('exportiert Lieferanten aus den Stammdaten ohne product_code im Produktblatt', function () {
    $supplier = Supplier::query()->create([
        'company_name' => 'Hauptlieferant GmbH',
    ]);

    $linkedProduct = erp02Product('Export Hauptprodukt', $supplier);
    $linkedProduct->update(['supplier' => 'Veralteter Freitext']);

    $plainProduct = erp02Product('Export Zweitprodukt');
    $plainProduct->update(['supplier' => 'Freitextlieferant']);

    $response = $this->actingAs(erp02Manager())
        ->get(route('products.excel.export'))
        ->assertOk();

    $path = $response->baseResponse->getFile()->getPathname();
    $spreadsheet = IOFactory::load($path);

    try {
        $productsSheet = $spreadsheet->getSheetByName('Produkte');

        expect($productsSheet)->not->toBeNull();
        expect($productsSheet->rangeToArray('A1:G1')[0])->toBe([
            'name',
            'manufacturer_designation',
            'serial_number',
            'unit',
            'supplier',
            'minimum_stock',
            'description',
        ]);

        expect($productsSheet->getCell('E2')->getValue())->toBe('Hauptlieferant GmbH')
            ->and($productsSheet->getCell('E3')->getValue())->toBe('Freitextlieferant');

        $priceSheet = $spreadsheet->getSheetByName('Preisstaffeln');
        expect($priceSheet)->not->toBeNull()
            ->and($priceSheet->getCell('A1')->getValue())->toBe('product_code');
    } finally {
        $spreadsheet->disconnectWorksheets();
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('importiert das neue Produktblatt anhand des eindeutigen Namens und verknüpft den Lieferanten', function () {
    $supplier = Supplier::query()->create([
        'company_name' => 'Importlieferant GmbH',
    ]);

    $product = erp02Product('Import Bestandsprodukt');

    $upload = erp02Upload(
        ['name', 'manufacturer_designation', 'serial_number', 'unit', 'supplier', 'minimum_stock', 'description'],
        ['Import Bestandsprodukt', 'Hersteller', 'CODE-123', 'piece', 'Importlieferant GmbH', 0, 'Aktualisierte Beschreibung']
    );

    try {
        $this->actingAs(erp02Manager())
            ->post(route('products.excel.import'), ['excel_file' => $upload])
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success');
    } finally {
        @unlink($upload->getRealPath());
    }

    expect(Product::query()->count())->toBe(1);
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Import Bestandsprodukt',
        'supplier_id' => $supplier->id,
        'supplier' => 'Importlieferant GmbH',
        'description' => 'Aktualisierte Beschreibung',
    ]);
});

it('legt Produkte ohne product_code mit automatisch erzeugter Kennung an', function () {
    $upload = erp02Upload(
        ['name', 'unit', 'minimum_stock'],
        ['Neues Excelprodukt', 'piece', 0]
    );

    try {
        $this->actingAs(erp02Manager())
            ->post(route('products.excel.import'), ['excel_file' => $upload])
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success');
    } finally {
        @unlink($upload->getRealPath());
    }

    $created = Product::query()->where('name', 'Neues Excelprodukt')->firstOrFail();
    expect($created->product_code)->toStartWith('PRD-');
});

it('unterstützt weiterhin ältere Excel-Importe mit product_code auch beim Umbenennen', function () {
    $product = erp02Product('Altprodukt');

    $upload = erp02Upload(
        ['product_code', 'name', 'unit', 'minimum_stock'],
        [$product->product_code, 'Umbenanntes Altprodukt', 'piece', 0]
    );

    try {
        $this->actingAs(erp02Manager())
            ->post(route('products.excel.import'), ['excel_file' => $upload])
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success');
    } finally {
        @unlink($upload->getRealPath());
    }

    expect(Product::query()->count())->toBe(1);
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Umbenanntes Altprodukt',
        'product_code' => $product->product_code,
    ]);
});
