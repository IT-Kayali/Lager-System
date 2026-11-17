<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WarningController extends Controller
{
    public function index(Request $request): View
    {
        app(\App\Services\ReservationReleaseService::class)->releaseExpired();

        $filter = (string) $request->query('filter', 'warning');
        $search = trim((string) $request->query('search'));
        $searchField = (string) $request->query('search_field', 'all');
        $exact = $request->boolean('exact');

        if (! in_array($filter, ['warning', 'low', 'critical', 'all'], true)) {
            $filter = 'warning';
        }

        $allowedSearchFields = ['all', 'name', 'manufacturer', 'code', 'supplier'];
        if (! in_array($searchField, $allowedSearchFields, true)) {
            $searchField = 'all';
        }

        $allProducts = $this->productRows();

        $summary = [
            'all' => $allProducts->count(),
            'low' => $allProducts->where('status', 'low')->count(),
            'critical' => $allProducts->where('status', 'critical')->count(),
            'warning' => $allProducts->whereIn('status', ['low', 'critical'])->count(),
        ];

        $searchedProducts = $this->searchRows($allProducts, $search, $searchField, $exact);

        return view('pages.warnings.index', [
            'products' => $this->filteredRows($searchedProducts, $filter),
            'filter' => $filter,
            'summary' => $summary,
            'search' => $search,
            'searchField' => $searchField,
            'exact' => $exact,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        app(\App\Services\ReservationReleaseService::class)->releaseExpired();

        $filter = (string) $request->query('filter', 'warning');
        $search = trim((string) $request->query('search'));
        $searchField = (string) $request->query('search_field', 'all');
        $exact = $request->boolean('exact');

        if (! in_array($filter, ['warning', 'low', 'critical', 'all'], true)) {
            $filter = 'warning';
        }

        if (! in_array($searchField, ['all', 'name', 'manufacturer', 'code', 'supplier'], true)) {
            $searchField = 'all';
        }

        $products = $this->filteredRows(
            $this->searchRows($this->productRows(), $search, $searchField, $exact),
            $filter
        );

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bestandswarnungen');

        $sheet->fromArray([[
            'Produkt',
            'Bezeichnung durch Hersteller',
            'Code-Nummer',
            'Einheit',
            'Lieferant',
            'Gesamtbestand',
            'Reserviert',
            'Verfügbar',
            'Mindestbestand',
            'Warnschwelle',
            'Status',
        ]]);

        $rowNumber = 2;

        foreach ($products as $row) {
            /** @var Product $product */
            $product = $row['product'];

            $sheet->fromArray([[
                $product->name,
                $product->manufacturer_designation,
                $product->serial_number,
                $product->unit,
                $product->supplierRecord?->company_name ?: $product->supplier,
                (float) $row['total_stock'],
                (float) $row['reserved_stock'],
                (float) $row['available_stock'],
                (float) $row['minimum_stock'],
                (float) $row['warning_threshold'],
                $this->statusLabel($row['status']),
            ]], null, 'A' . $rowNumber);

            $rowNumber++;
        }

        if ($rowNumber > 2) {
            foreach (['F', 'G', 'H', 'I', 'J'] as $column) {
                $sheet->getStyle($column . '2:' . $column . ($rowNumber - 1))
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');
            }
        }

        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:K' . max(1, $rowNumber - 1));

        $path = storage_path('app/bestandswarnungen-' . now()->format('Y-m-d-His') . '.xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return response()
            ->download($path, 'bestandswarnungen.xlsx')
            ->deleteFileAfterSend(true);
    }

    private function productRows(): Collection
    {
        return Product::query()
            ->active()
            ->with([
                'supplierRecord',
                'batches' => fn ($query) => $query->orderBy('received_at')->orderBy('id'),
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) {
                return [
                    'product' => $product,
                    'status' => $product->stock_status,
                    'total_stock' => $product->total_stock,
                    'reserved_stock' => $product->reserved_stock,
                    'available_stock' => $product->available_stock,
                    'minimum_stock' => (float) $product->minimum_stock,
                    'warning_threshold' => $product->low_stock_warning_threshold,
                    'max_reservable' => $product->max_reservable,
                ];
            });
    }

    private function searchRows(Collection $rows, string $search, string $searchField, bool $exact): Collection
    {
        if ($search === '') {
            return $rows;
        }

        $needle = mb_strtolower($search);

        return $rows
            ->filter(function (array $row) use ($needle, $searchField, $exact): bool {
                /** @var Product $product */
                $product = $row['product'];
                $supplier = $product->supplierRecord?->company_name ?: $product->supplier;

                $values = match ($searchField) {
                    'name' => [$product->name],
                    'manufacturer' => [$product->manufacturer_designation],
                    'code' => [$product->serial_number, $product->product_code],
                    'supplier' => [$supplier],
                    default => [
                        $product->name,
                        $product->manufacturer_designation,
                        $product->serial_number,
                        $product->product_code,
                        $supplier,
                    ],
                };

                foreach ($values as $value) {
                    $candidate = mb_strtolower(trim((string) $value));

                    if ($candidate === '') {
                        continue;
                    }

                    if ($exact ? $candidate === $needle : str_contains($candidate, $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    private function filteredRows(Collection $rows, string $filter): Collection
    {
        return $rows
            ->filter(function (array $row) use ($filter) {
                if ($filter === 'critical') {
                    return $row['status'] === 'critical';
                }

                if ($filter === 'low') {
                    return $row['status'] === 'low';
                }

                if ($filter === 'all') {
                    return true;
                }

                return in_array($row['status'], ['low', 'critical'], true);
            })
            ->values();
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'critical' => 'Kritisch',
            'low' => 'Niedrig',
            default => 'OK',
        };
    }
}
