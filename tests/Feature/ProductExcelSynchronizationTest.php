<?php

use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function erp03Manager(): User
{
    return User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
}

function erp03Product(string $name): Product
{
    return Product::query()->create(['name' => $name, 'unit' => 'piece', 'minimum_stock' => 0]);
}

function erp03WorkbookUpload(Spreadsheet $spreadsheet): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'erp03-');
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

function erp03ExportWorkbook(Tests\TestCase $test): Spreadsheet
{
    $response = $test->get(route('products.excel.export'))->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    try {
        return IOFactory::load($path);
    } finally {
        @unlink($path);
    }
}

it('zeigt die Vorschau ohne die Datenbank zu verändern und übernimmt nur ausgewählte Zeilen', function () {
    $product = erp03Product('Excel Original');
    $spreadsheet = erp03ExportWorkbook($this->actingAs(erp03Manager()));
    $spreadsheet->getSheetByName('Produkte')->setCellValue('A2', 'Excel Umbenannt');
    $upload = erp03WorkbookUpload($spreadsheet);

    try {
        $preview = $this->post(route('products.excel.import'), [
            'excel_file' => $upload,
            'import_mode' => 'update',
        ])->assertOk();

        expect(Product::query()->findOrFail($product->id)->name)->toBe('Excel Original');

        $this->post(route('products.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'),
            'selected_rows' => [],
        ])->assertRedirect(route('products.index'));

        expect(Product::query()->findOrFail($product->id)->name)->toBe('Excel Original');
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('erkennt Umbenennung über die ausgeblendete interne ID und überschreibt leere Zellen nicht', function () {
    $product = erp03Product('Altname');
    $product->update(['description' => 'Vorhanden', 'minimum_stock' => 8]);
    $spreadsheet = erp03ExportWorkbook($this->actingAs(erp03Manager()));
    $spreadsheet->getSheetByName('Produkte')->setCellValue('A2', 'Neuname');
    $spreadsheet->getSheetByName('Produkte')->setCellValue('G2', null);
    $spreadsheet->getSheetByName('Produkte')->setCellValue('F2', 0);
    $upload = erp03WorkbookUpload($spreadsheet);

    try {
        $preview = $this->post(route('products.excel.import'), [
            'excel_file' => $upload,
            'import_mode' => 'update',
        ])->assertOk();

        $this->post(route('products.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'),
            'selected_rows' => [2],
        ])->assertRedirect(route('products.index'));

        $product->refresh();
        expect($product->name)->toBe('Neuname')
            ->and($product->description)->toBe('Vorhanden')
            ->and((float) $product->minimum_stock)->toBe(0.0);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('archiviert nur im vollständigen Sync fehlende Exportzeilen und schützt später angelegte Produkte', function () {
    $removed = erp03Product('A Archiviert');
    $retained = erp03Product('B Behalten');
    $spreadsheet = erp03ExportWorkbook($this->actingAs(erp03Manager()));
    $spreadsheet->getSheetByName('Produkte')->removeRow(2, 1);

    $newer = erp03Product('C Später angelegt');
    $upload = erp03WorkbookUpload($spreadsheet);

    try {
        $preview = $this->post(route('products.excel.import'), [
            'excel_file' => $upload,
            'import_mode' => 'sync',
        ])->assertOk();

        $archives = $preview->viewData('preview')['archives'];
        expect(array_column($archives, 'id'))->toBe([$removed->id]);

        $this->post(route('products.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'),
            'selected_rows' => [2],
            'archive_ids' => [$removed->id],
            'archive_confirmation' => 'ARCHIVIEREN',
        ])->assertRedirect(route('products.index'));

        expect($removed->fresh()->archived_at)->not->toBeNull()
            ->and($retained->fresh()->archived_at)->toBeNull()
            ->and($newer->fresh()->archived_at)->toBeNull();

        $this->get(route('products.index'))->assertOk()->assertDontSee('A Archiviert');
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('führt ohne vollständige Exportmetadaten niemals eine Archivierung durch', function () {
    erp03Product('Unverändert');
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getActiveSheet()->setTitle('Produkte');
    $spreadsheet->getActiveSheet()->fromArray([['name'], ['Anderes Produkt']]);
    $upload = erp03WorkbookUpload($spreadsheet);

    try {
        $this->actingAs(erp03Manager())->post(route('products.excel.import'), [
            'excel_file' => $upload,
            'import_mode' => 'sync',
        ])->assertSessionHasErrors('excel_file');

        expect(Product::query()->active()->count())->toBe(1);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('weist eine veraltete Excel-Datei ab statt geänderte Produkte zu überschreiben', function () {
    $product = erp03Product('Zeitprodukt');
    $spreadsheet = erp03ExportWorkbook($this->actingAs(erp03Manager()));
    $product->update(['description' => 'Nach Export geändert', 'updated_at' => now()->addMinute()]);
    $upload = erp03WorkbookUpload($spreadsheet);

    try {
        $this->post(route('products.excel.import'), [
            'excel_file' => $upload,
            'import_mode' => 'update',
        ])->assertSessionHasErrors('excel_file');

        expect($product->fresh()->description)->toBe('Nach Export geändert');
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('ignoriert alte Preisstaffelblätter vollständig', function () {
    CustomerGroup::query()->create(['name' => 'Gold', 'slug' => 'gold', 'color' => '#D4AD16']);
    $product = erp03Product('Kein Preisimport');
    $tier = ProductPriceTier::query()->where('product_id', $product->id)->firstOrFail();
    $oldPrice = $tier->price;

    $spreadsheet = erp03ExportWorkbook($this->actingAs(erp03Manager()));
    $priceSheet = $spreadsheet->createSheet();
    $priceSheet->setTitle('Preisstaffeln');
    $priceSheet->fromArray([
        ['product_code', 'customer_group', 'tier_key', 'tier_label', 'min_grams', 'max_grams', 'price'],
        [$product->product_code, 'wholesale', $tier->tier_key, $tier->tier_label, $tier->min_grams, $tier->max_grams, 99999],
    ]);
    $upload = erp03WorkbookUpload($spreadsheet);

    try {
        $preview = $this->post(route('products.excel.import'), [
            'excel_file' => $upload,
            'import_mode' => 'update',
        ])->assertOk();

        expect($preview->viewData('preview')['ignored_prices'])->toBeTrue();

        $this->post(route('products.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'),
            'selected_rows' => [2],
        ])->assertRedirect(route('products.index'));

        expect($tier->fresh()->price)->toBe($oldPrice);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('stellt ein archiviertes Produkt über die Importvorschau wieder her', function () {
    $product = erp03Product('Rückkehrer');
    $product->update(['archived_at' => now()]);

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getActiveSheet()->setTitle('Produkte');
    $spreadsheet->getActiveSheet()->fromArray([['name'], ['Rückkehrer']]);
    $upload = erp03WorkbookUpload($spreadsheet);

    try {
        $preview = $this->actingAs(erp03Manager())->post(route('products.excel.import'), [
            'excel_file' => $upload,
            'import_mode' => 'update',
        ])->assertOk();

        expect($preview->viewData('preview')['rows'][0]['action'])->toBe('restore');

        $this->post(route('products.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'),
            'selected_rows' => [2],
        ])->assertRedirect(route('products.index'));

        expect($product->fresh()->archived_at)->toBeNull();
    } finally {
        @unlink($upload->getRealPath());
    }
});
