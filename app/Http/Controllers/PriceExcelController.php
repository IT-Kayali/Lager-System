<?php

namespace App\Http\Controllers;

use App\Models\CustomerGroup;
use App\Models\ManualPriceRule;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Support\PriceExcelImportPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PriceExcelController extends Controller
{
    public function export(Request $request, PriceExcelImportPlan $service): BinaryFileResponse
    {
        return $this->workbook($request, $service, false);
    }

    public function template(Request $request, PriceExcelImportPlan $service): BinaryFileResponse
    {
        return $this->workbook($request, $service, true);
    }

    private function workbook(Request $request, PriceExcelImportPlan $service, bool $template): BinaryFileResponse
    {
        $scope = (string) $request->query('customer_group_id', 'all');
        $groupId = $service->groupId($scope);
        $groups = CustomerGroup::query()->when($groupId !== null, fn ($q) => $q->whereKey($groupId))->ordered()->get();
        $products = Product::query()->active()->with('categories')->orderBy('name')->get();

        $tierRecords = ProductPriceTier::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->whereIn('customer_group_id', $groups->pluck('id'))
            ->orderBy('min_grams')->get()->groupBy(fn ($r) => $r->product_id . ':' . $r->customer_group_id);
        $manualRecords = ManualPriceRule::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->whereIn('customer_group_id', $groups->pluck('id'))
            ->orderBy('min_quantity')->get()->groupBy(fn ($r) => $r->product_id . ':' . $r->customer_group_id);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Preise');
        $sheet->fromArray([PriceExcelImportPlan::HEADERS]);
        $row = 2;

        foreach ($products as $product) {
            $mode = $product->usesPriceTiers() ? 'staffel' : 'manuell';
            foreach ($groups as $group) {
                $key = $product->id . ':' . $group->id;
                $rules = $mode === 'staffel' ? ($tierRecords[$key] ?? collect()) : ($manualRecords[$key] ?? collect());
                foreach ($rules as $rule) {
                    $sheet->fromArray([[
                        $product->name,
                        $group->name,
                        $mode,
                        $mode === 'staffel' ? $rule->tier_key : '',
                        $mode === 'staffel' ? $rule->min_grams : (float) $rule->min_quantity,
                        $mode === 'staffel' ? $rule->max_grams : ($rule->max_quantity !== null ? (float) $rule->max_quantity : null),
                        $product->unitLabel('de'),
                        $template ? null : (float) $rule->price,
                        $mode === 'staffel' ? $rule->tier_label : $rule->label,
                        $product->id,
                        $group->id,
                        $rule->id,
                    ]], null, 'A' . $row);
                    // Frei gepflegte Namen/Beschreibungen nie als Excel-Formel ausgeben.
                    $sheet->setCellValueExplicit('A' . $row, (string) $product->name, DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B' . $row, (string) $group->name, DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('I' . $row, (string) ($mode === 'staffel' ? $rule->tier_label : $rule->label), DataType::TYPE_STRING);
                    $row++;
                }
            }
        }

        $sheet->getStyle('A1:L1')->getFont()->setBold(true);
        $sheet->freezePane('D2');
        $sheet->setAutoFilter('A1:I' . max(1, $row - 1));
        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        foreach (range('J', 'L') as $column) {
            $sheet->getColumnDimension($column)->setVisible(false);
        }
        if ($row > 2) {
            $sheet->getStyle('E2:F' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.000');
            $sheet->getStyle('H2:H' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $help = $spreadsheet->createSheet();
        $help->setTitle('Anleitung');
        $help->fromArray([
            ['Preis-Excel: Anleitung'],
            ['Nur Spalte H (price) bearbeiten. Alle anderen Spalten und die versteckten IDs unverändert lassen.'],
            ['Eine leere Preiszelle überspringt die Zeile; der Preis 0 wird übernommen.'],
            ['Für eine neue Kundengruppe oder neue Preisstaffeln bitte immer eine frische Vorlage herunterladen.'],
            ['Manuelle Mengenregeln zuerst im Preisbereich anlegen; Excel aktualisiert vorhandene Preisregeln.'],
            ['Import: erst Vorschau prüfen, dann gewünschte Zeilen bestätigen. Keine Zeilen werden gelöscht.'],
        ]);
        $help->getColumnDimension('A')->setWidth(105);
        $spreadsheet->setActiveSheetIndex(0);

        $path = storage_path('app/preise-' . Str::uuid() . '.xlsx');
        try {
            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $suffix = $groupId === null ? 'alle-gruppen' : 'gruppe-' . $groupId;
        $name = $template ? 'preisvorlage-' : 'preise-';

        return response()->download($path, $name . $suffix . '.xlsx')->deleteFileAfterSend(true);
    }

    public function importForm(): View
    {
        return view('pages.prices.excel-import', [
            'groups' => CustomerGroup::query()->ordered()->get(),
        ]);
    }

    public function import(Request $request, PriceExcelImportPlan $service): View|RedirectResponse
    {
        $data = $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'customer_group_id' => ['required', 'string'],
        ]);

        $scope = (string) $data['customer_group_id'];
        $service->groupId($scope);

        try {
            $spreadsheet = IOFactory::load($data['excel_file']->getRealPath());
            try {
                $preview = $service->prepare($spreadsheet, $scope);
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            return back()->with('error', 'Die Excel-Datei konnte nicht gelesen werden. Bitte eine gültige Vorlage hochladen.');
        }

        $token = Str::random(48);
        Cache::put($this->previewKey($token), $preview, now()->addMinutes(30));

        return view('pages.prices.excel-import', [
            'groups' => CustomerGroup::query()->ordered()->get(),
            'preview' => $preview,
            'previewToken' => $token,
        ]);
    }

    public function confirm(Request $request, PriceExcelImportPlan $service): RedirectResponse
    {
        $data = $request->validate([
            'preview_token' => ['required', 'string', 'size:48'],
            'selected_rows' => ['nullable', 'array'],
            'selected_rows.*' => ['integer', 'min:2'],
        ]);

        $key = $this->previewKey($data['preview_token']);
        $plan = Cache::get($key);
        if (! is_array($plan)) {
            return redirect()->route('prices.excel.import.form')
                ->with('error', 'Die Vorschau ist abgelaufen. Bitte Excel-Datei neu hochladen.');
        }

        $lines = array_values(array_unique(array_map('intval', $data['selected_rows'] ?? [])));
        try {
            $count = $service->apply($plan, $lines);
        } catch (ValidationException $exception) {
            return redirect()->route('prices.excel.import.form')
                ->with('error', $exception->validator->errors()->first('excel_file'));
        } catch (\Throwable $exception) {
            report($exception);
            return redirect()->route('prices.excel.import.form')
                ->with('error', 'Keine Preise übernommen. Bitte das Fehlerprotokoll prüfen.');
        }

        Cache::forget($key);

        return redirect()->route('prices.index')->with('success', "Excel-Preisimport abgeschlossen: {$count} Preise aktualisiert.");
    }

    private function previewKey(string $token): string
    {
        return 'price-excel-import:' . auth()->id() . ':' . $token;
    }
}
