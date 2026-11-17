<?php

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function erp04Manager(): User
{
    return User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
}

function erp04Product(string $name): Product
{
    return Product::query()->create(['name' => $name, 'unit' => 'piece', 'minimum_stock' => 0]);
}

function erp04Batch(Product $product, string $number, float $quantity = 10): ProductBatch
{
    return ProductBatch::query()->create([
        'product_id' => $product->id,
        'batch_number' => $number,
        'quantity' => $quantity,
        'received_at' => '2026-01-05',
        'expires_at' => '2028-01-05',
    ]);
}

function erp04Export(Tests\TestCase $test): Spreadsheet
{
    $response = $test->get(route('batches.excel.export'))->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();
    try {
        return IOFactory::load($path);
    } finally {
        @unlink($path);
    }
}

function erp04Upload(Spreadsheet $spreadsheet): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'erp04-');
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return new UploadedFile($path, 'chargen.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function erp04EmptyWorkbook(): Spreadsheet
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getActiveSheet()->setTitle('Chargen');
    $spreadsheet->getActiveSheet()->fromArray([[
        'batch_number', 'product', 'quantity', 'expires_at', 'received_at',
    ]]);

    return $spreadsheet;
}

it('exportiert alle Chargen, bietet ein sichtbares Blatt und schützt Text vor Excel-Formeln', function () {
    $product = erp04Product('Export-Produkt');
    $batch = erp04Batch($product, '=BATCH-001', 2.125);
    $sheet = erp04Export($this->actingAs(erp04Manager()));

    expect($sheet->getSheetByName('Chargen')->getCell('A2')->getValue())->toBe('=BATCH-001')
        ->and($sheet->getSheetByName('Chargen')->getCell('A2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and((float) $sheet->getSheetByName('Chargen')->getCell('C2')->getValue())->toBe(2.125)
        ->and((int) $sheet->getSheetByName('Chargen')->getCell('F2')->getValue())->toBe($batch->id)
        ->and($sheet->getSheetByName('Chargen')->getColumnDimension('F')->getVisible())->toBeFalse()
        ->and($sheet->getSheetByName('__batch_sync')->getSheetState())->toBe(Worksheet::SHEETSTATE_VERYHIDDEN);

    $sheet->disconnectWorksheets();
});

it('zeigt die Importvorschau ohne Bestandsänderung und bucht eine bestätigte Korrektur', function () {
    $product = erp04Product('Bestand Test');
    $batch = erp04Batch($product, 'IMP-001', 12);
    $sheet = erp04Export($this->actingAs(erp04Manager()));
    $sheet->getSheetByName('Chargen')->setCellValue('C2', 9.5);
    $upload = erp04Upload($sheet);

    try {
        $preview = $this->post(route('batches.excel.import'), ['excel_file' => $upload])->assertOk();
        expect((float) $batch->fresh()->quantity)->toBe(12.0)
            ->and(StockMovement::query()->count())->toBe(0)
            ->and($preview->viewData('preview')['rows'][0]['quantity_delta'])->toBe(-2.5);

        $token = $preview->viewData('previewToken');
        $this->post(route('batches.excel.import.confirm'), [
            'preview_token' => $token,
            'selected_rows' => [2],
        ])->assertRedirect(route('batches.excel.import.form'));
        expect((float) $batch->fresh()->quantity)->toBe(12.0);

        $this->post(route('batches.excel.import.confirm'), [
            'preview_token' => $token,
            'selected_rows' => [2],
            'stock_confirmation' => 'BESTAND',
        ])->assertRedirect(route('batches.index'));

        expect((float) $batch->fresh()->quantity)->toBe(9.5)
            ->and(StockMovement::query()->count())->toBe(1)
            ->and((float) StockMovement::query()->first()->quantity)->toBe(-2.5)
            ->and(StockMovement::query()->first()->type)->toBe('adjustment');
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('legt neue Chargen für bestehende Produkte an und bucht Wareneingänge', function () {
    $product = erp04Product('Neuware');
    $sheet = erp04EmptyWorkbook();
    $sheet->getActiveSheet()->fromArray([['NEU-04', 'Neuware', '1,250', '', '17.11.2026']], null, 'A2');
    $upload = erp04Upload($sheet);

    try {
        $this->actingAs(erp04Manager());
        $preview = $this->post(route('batches.excel.import'), ['excel_file' => $upload])->assertOk();
        expect(ProductBatch::query()->count())->toBe(0)
            ->and($preview->viewData('preview')['rows'][0]['action'])->toBe('create');

        $this->post(route('batches.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'),
            'selected_rows' => [2],
            'stock_confirmation' => 'BESTAND',
        ])->assertRedirect(route('batches.index'));

        $batch = ProductBatch::query()->where('batch_number', 'NEU-04')->firstOrFail();
        expect($batch->product_id)->toBe($product->id)
            ->and((float) $batch->quantity)->toBe(1.25)
            ->and($batch->received_at->format('Y-m-d'))->toBe('2026-11-17')
            ->and($batch->expires_at)->not->toBeNull()
            ->and(StockMovement::query()->first()->type)->toBe('in');
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('übernimmt nur ausgewählte Zeilen und löscht nie fehlende Chargen', function () {
    $product = erp04Product('Teilimport');
    $first = erp04Batch($product, 'AAA-04', 4);
    $second = erp04Batch($product, 'BBB-04', 8);
    $sheet = erp04Export($this->actingAs(erp04Manager()));
    $sheet->getSheetByName('Chargen')->setCellValue('C2', 5);
    $sheet->getSheetByName('Chargen')->removeRow(3);
    $upload = erp04Upload($sheet);

    try {
        $preview = $this->post(route('batches.excel.import'), ['excel_file' => $upload])->assertOk();
        $this->post(route('batches.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'),
            'selected_rows' => [],
        ])->assertRedirect(route('batches.index'));

        expect((float) $first->fresh()->quantity)->toBe(4.0)
            ->and((float) $second->fresh()->quantity)->toBe(8.0)
            ->and(ProductBatch::query()->count())->toBe(2)
            ->and(StockMovement::query()->count())->toBe(0);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('benennt vorhandene Batchnummern über die verborgene ID um, ohne die Produktzuordnung zu ändern', function () {
    $product = erp04Product('Umbenennung');
    $batch = erp04Batch($product, 'ALT-04', 6);
    $sheet = erp04Export($this->actingAs(erp04Manager()));
    $sheet->getSheetByName('Chargen')->setCellValue('A2', 'NEU-04');
    $upload = erp04Upload($sheet);

    try {
        $preview = $this->post(route('batches.excel.import'), ['excel_file' => $upload])->assertOk();
        $this->post(route('batches.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'),
            'selected_rows' => [2],
        ])->assertRedirect(route('batches.index'));

        expect($batch->fresh()->batch_number)->toBe('NEU-04')
            ->and($batch->fresh()->product_id)->toBe($product->id)
            ->and(StockMovement::query()->count())->toBe(0);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('lehnt eine seit dem Export geänderte Charge ab', function () {
    $product = erp04Product('Altbestand');
    $batch = erp04Batch($product, 'STALE-04');
    $sheet = erp04Export($this->actingAs(erp04Manager()));
    DB::table('product_batches')->where('id', $batch->id)->update([
        'quantity' => 13,
        'updated_at' => now()->addDay(),
    ]);
    $upload = erp04Upload($sheet);

    try {
        $this->post(route('batches.excel.import'), ['excel_file' => $upload])->assertSessionHasErrors('excel_file');
        expect((float) $batch->fresh()->quantity)->toBe(13.0);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('bricht nach zwischenzeitlicher Bestandsänderung die gesamte Bestätigung ab', function () {
    $product = erp04Product('Zwischenstand');
    $batch = erp04Batch($product, 'LATE-04', 6);
    $sheet = erp04Export($this->actingAs(erp04Manager()));
    $sheet->getSheetByName('Chargen')->setCellValue('C2', 7);
    $upload = erp04Upload($sheet);

    try {
        $preview = $this->post(route('batches.excel.import'), ['excel_file' => $upload])->assertOk();
        DB::table('product_batches')->where('id', $batch->id)->update(['quantity' => 5]);
        $this->post(route('batches.excel.import.confirm'), [
            'preview_token' => $preview->viewData('previewToken'),
            'selected_rows' => [2],
            'stock_confirmation' => 'BESTAND',
        ])->assertRedirect(route('batches.excel.import.form'));
        expect((float) $batch->fresh()->quantity)->toBe(5.0)
            ->and(StockMovement::query()->count())->toBe(0);
    } finally {
        @unlink($upload->getRealPath());
    }
});

it('lehnt unbekannte Produkte, doppelte Batchnummern und Excel-Formeln ab', function () {
    erp04Product('Vorhanden');
    $this->actingAs(erp04Manager());

    foreach (['unknown', 'duplicate', 'formula'] as $case) {
        $sheet = erp04EmptyWorkbook();
        $sheet->getActiveSheet()->fromArray([['ERR-04', $case === 'unknown' ? 'Unbekannt' : 'Vorhanden', 2, '', '2026-11-17']], null, 'A2');
        if ($case === 'duplicate') {
            $sheet->getActiveSheet()->fromArray([['ERR-04', 'Vorhanden', 3, '', '2026-11-17']], null, 'A3');
        }
        if ($case === 'formula') {
            $sheet->getActiveSheet()->setCellValue('C2', '=1+1');
        }
        $upload = erp04Upload($sheet);
        try {
            $this->post(route('batches.excel.import'), ['excel_file' => $upload])->assertSessionHasErrors('excel_file');
            expect(ProductBatch::query()->count())->toBe(0);
        } finally {
            @unlink($upload->getRealPath());
        }
    }
});

it('verweigert Rollen ohne Chargenberechtigung den Excel-Zugriff', function () {
    $sales = User::factory()->create(['role' => User::ROLE_SALES, 'is_active' => true]);
    $this->actingAs($sales)->get(route('batches.excel.export'))->assertForbidden();
    $this->get(route('batches.excel.import.form'))->assertForbidden();
});
