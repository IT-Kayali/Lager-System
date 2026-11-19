<?php

namespace App\Http\Controllers;

use App\Models\CustomerGroup;
use App\Models\ManualPriceRule;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Support\PriceExcelImportPlan;
use App\Support\PriceExcelStreamWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        $groups = CustomerGroup::query()
            ->when($groupId !== null, fn ($query) => $query->whereKey($groupId))
            ->ordered()
            ->get();

        // Maximal 50 Produkte sowie deren aktuelle Preise gleichzeitig laden.
        // Der XLSX-Schreiber schreibt XML auf die Festplatte statt alle Zellen
        // als PhpSpreadsheet-Objekte im Arbeitsspeicher zu halten.
        $writer = new PriceExcelStreamWriter();

        Product::query()
            ->active()
            ->with('categories')
            ->chunkById(50, function ($products) use ($groups, $template, $writer): void {
                $productIds = $products->pluck('id');
                $groupIds = $groups->pluck('id');

                $tiers = ProductPriceTier::query()
                    ->whereIn('product_id', $productIds)
                    ->whereIn('customer_group_id', $groupIds)
                    ->orderBy('min_grams')
                    ->get()
                    ->groupBy(fn ($rule) => $rule->product_id . ':' . $rule->customer_group_id);

                $manualRules = ManualPriceRule::query()
                    ->whereIn('product_id', $productIds)
                    ->whereIn('customer_group_id', $groupIds)
                    ->orderBy('min_quantity')
                    ->get()
                    ->groupBy(fn ($rule) => $rule->product_id . ':' . $rule->customer_group_id);

                foreach ($products as $product) {
                    $mode = $product->usesPriceTiers() ? 'staffel' : 'manuell';

                    foreach ($groups as $group) {
                        $key = $product->id . ':' . $group->id;
                        $rules = $mode === 'staffel'
                            ? ($tiers[$key] ?? collect())
                            : ($manualRules[$key] ?? collect());

                        foreach ($rules as $rule) {
                            $writer->append([
                                $product->name,
                                $group->name,
                                $mode,
                                $mode === 'staffel' ? $rule->tier_key : '',
                                $mode === 'staffel' ? $rule->min_grams : (float) $rule->min_quantity,
                                $mode === 'staffel' ? $rule->max_grams : ($rule->max_quantity !== null ? (float) $rule->max_quantity : null),
                                $product->unitLabel('de'),
                                $template ? null : number_format((float) $rule->price, 5, '.', ''),
                                $mode === 'staffel' ? $rule->tier_label : $rule->label,
                                $product->id,
                                $group->id,
                                $rule->id,
                            ]);
                        }
                    }
                }
            });

        $path = storage_path('app/preise-' . Str::uuid() . '.xlsx');
        $writer->finish($path);

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
