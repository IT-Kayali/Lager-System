<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Services\ReservationReleaseService;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        app(\App\Services\ReservationReleaseService::class)->releaseExpired();

        $productListQuery = $this->listContext($request);
        $search = $productListQuery['search'] ?? '';
        $searchField = $productListQuery['search_field'] ?? 'all';
        $exact = ($productListQuery['exact'] ?? 0) === 1;
        $sort = $productListQuery['sort'] ?? '';
        $direction = $productListQuery['direction'] ?? 'asc';

        $products = $this->filteredProducts($productListQuery)
            ->with('categories')
            ->with('supplierRecord')
            ->with(['batches' => fn ($query) => $query->orderBy('received_at')->orderBy('id')])
            ->when(
                $sort === 'name',
                fn ($query) => $query->naturalNameOrder($direction),
                fn ($query) => $query->naturalNameOrder()
            )
            ->paginate(15)
            ->withQueryString();

        return view('pages.products.index', compact('products', 'search', 'searchField', 'exact', 'productListQuery'));
    }

    /**
     * Only supported list parameters are carried through a delete request.
     * Never redirect to an arbitrary URL supplied by a client.
     */
    private function listContext(Request $request): array
    {
        $query = $request->query();
        $context = [];
        if (isset($query['search']) && is_string($query['search'])) {
            $context['search'] = trim($query['search']);
        }
        if (in_array($query['search_field'] ?? null, ['all', 'name', 'manufacturer', 'code', 'supplier'], true)) {
            $context['search_field'] = $query['search_field'];
        }
        if (isset($query['exact']) && is_scalar($query['exact'])) {
            $context['exact'] = filter_var($query['exact'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (($query['sort'] ?? null) === 'name') {
            $context['sort'] = 'name';
        }
        if (isset($query['direction']) && is_string($query['direction']) && in_array(strtolower($query['direction']), ['asc', 'desc'], true)) {
            $context['direction'] = strtolower($query['direction']);
        }
        $page = filter_var($query['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page !== false) {
            $context['page'] = $page;
        }

        return $context;
    }

    private function filteredProducts(array $context): Builder
    {
        $search = $context['search'] ?? '';
        $searchField = $context['search_field'] ?? 'all';
        $exact = ($context['exact'] ?? 0) === 1;

        return Product::query()
            ->active()
            ->when($search !== '', function ($query) use ($search, $searchField, $exact) {
                $operator = $exact ? '=' : 'like';
                $value = $exact ? $search : "%{$search}%";

                $query->where(function ($subQuery) use ($searchField, $operator, $value) {
                    switch ($searchField) {
                        case 'name':
                            $subQuery->where('name', $operator, $value);
                            break;

                        case 'manufacturer':
                            $subQuery->where('manufacturer_designation', $operator, $value);
                            break;

                        case 'code':
                            $subQuery
                                ->where('serial_number', $operator, $value)
                                ->orWhere('product_code', $operator, $value);
                            break;

                        case 'supplier':
                            $subQuery
                                ->where('supplier', $operator, $value)
                                ->orWhereHas('supplierRecord', fn ($supplierQuery) => $supplierQuery->where('company_name', $operator, $value));
                            break;

                        default:
                            $subQuery
                                ->where('name', $operator, $value)
                                ->orWhere('manufacturer_designation', $operator, $value)
                                ->orWhere('serial_number', $operator, $value)
                                ->orWhere('product_code', $operator, $value)
                                ->orWhere('supplier', $operator, $value)
                                ->orWhereHas('supplierRecord', fn ($supplierQuery) => $supplierQuery->where('company_name', $operator, $value));
                            break;
                    }
                });
            });
    }

    public function show(Product $product): View
    {
        $product->load([
            'supplierRecord',
            'categories' => fn ($query) => $query->orderByRaw('LOWER(name) ASC')->orderBy('id'),
            'batches' => fn ($query) => $query
                ->orderBy('received_at')
                ->orderBy('id'),
        ]);

        return view('pages.products.show', [
            'product' => $product,
        ]);
    }

    public function create(): View
    {
        $product = new Product([
            'unit' => 'gram',
            'minimum_stock' => 0,
        ]);

        $suppliers = Supplier::query()
            ->orderByRaw('LOWER(company_name) ASC')
            ->orderBy('id')
            ->get();

        return view('pages.products.create', compact('product', 'suppliers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $initialBatchData = $this->validatedInitialBatchData($request);

        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids']);

        DB::transaction(function () use ($data, $categoryIds, $initialBatchData): void {
            $product = Product::create($data);
            $product->categories()->sync($categoryIds);

            $initialBatches = collect($initialBatchData['initial_batches'] ?? [])
                ->filter(fn ($row) => (float) ($row['quantity'] ?? 0) > 0)
                ->values();

            foreach ($initialBatches as $row) {
                $receivedAt = Carbon::parse(($row['received_at'] ?? null) ?: now()->toDateString())->startOfDay();
                $expiresAt = ($row['expires_at'] ?? null) ?: $receivedAt
                    ->copy()
                    ->addMonthsNoOverflow(ApplicationSetting::defaultBatchExpiryMonths())
                    ->toDateString();

                $batch = ProductBatch::create([
                    'product_id' => $product->id,
                    'batch_number' => ($row['batch_number'] ?? null) ?: null,
                    'quantity' => (float) ($row['quantity'] ?? 0),
                    'received_at' => $receivedAt->toDateString(),
                    'expires_at' => $expiresAt,
                ]);

                StockMovement::create([
                    'product_id' => $product->id,
                    'product_batch_id' => $batch->id,
                    'user_id' => auth()->id(),
                    'type' => 'in',
                    'quantity' => $batch->quantity,
                    'note' => 'Wareneingang / Charge beim Produkt angelegt',
                ]);
            }
        });

        return redirect()
            ->route('products.index')
            ->with('success', 'Produkt wurde erfolgreich angelegt.');
    }

    public function edit(Product $product): View
    {
        $suppliers = Supplier::query()
            ->orderByRaw('LOWER(company_name) ASC')
            ->orderBy('id')
            ->get();

        return view('pages.products.edit', compact('product', 'suppliers'));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validatedData($request, $product);

        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids']);

        $product->update($data);
        $product->categories()->sync($categoryIds);

        return redirect()
            ->route('products.index')
            ->with('success', 'Produkt wurde erfolgreich aktualisiert.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        // Product routes also accept numeric legacy IDs. A stale name-based
        // form must not accidentally delete another product with that ID.
        if ($request->has('expected_product_id') && (string) $request->input('expected_product_id') !== (string) $product->id) {
            return $this->returnToProductList($request)
                ->with('error', 'Produkt hat sich geändert. Bitte aktualisiere die Produktliste.');
        }

        if (! $this->deleteUnusedProduct($product->id)) {
            return $this->returnToProductList($request)
                ->with('error', 'Produkt kann nicht gelöscht werden, da es bereits in Angeboten, Chargen, Lagerbewegungen oder anderen Vorgängen verwendet wurde oder nicht mehr vorhanden ist. Historische Daten bleiben erhalten.');
        }

        return $this->returnToProductList($request)->with('success', 'Produkt wurde gelöscht.');
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bulk_action' => ['required', Rule::in(['delete'])],
            'product_ids' => ['required', 'array', 'list', 'min:1', 'max:15'],
            'product_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        $deleted = 0;
        $skipped = 0;
        foreach ($data['product_ids'] as $id) {
            if ($this->deleteUnusedProduct((int) $id)) {
                $deleted++;
            } else {
                $skipped++;
            }
        }

        $response = $this->returnToProductList($request);
        if ($deleted > 0) {
            $response->with('success', $deleted . ' Produkte gelöscht.');
        }
        if ($skipped > 0) {
            $response->with('error', $skipped . ' Produkte nicht gelöscht: bereits verwendet, archiviert oder nicht mehr vorhanden. Historische Daten bleiben erhalten.');
        }

        return $response;
    }

    private function returnToProductList(Request $request): RedirectResponse
    {
        $context = $this->listContext($request);
        if (($context['page'] ?? 1) > 1) {
            $lastPage = max(1, (int) ceil($this->filteredProducts($context)->count() / 15));
            $context['page'] = min($context['page'], $lastPage);
        }

        return redirect()->route('products.index', $context);
    }

    private function deleteUnusedProduct(int $id): bool
    {
        try {
            return DB::transaction(function () use ($id): bool {
                $product = Product::query()->active()->lockForUpdate()->find($id);
                if ($product === null
                    || $product->offerItems()->exists()
                    || $product->batches()->exists()
                    || $product->stockMovements()->exists()
                    || DB::table('branch_withdrawal_items')->where('product_id', $id)->exists()
                    || DB::table('branch_withdrawals')->where('product_id', $id)->exists()) {
                    return false;
                }

                return (bool) $product->delete();
            });
        } catch (QueryException $exception) {
            if ($this->isForeignKeyConstraintViolation($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    private function isForeignKeyConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23503'
            || ($sqlState === '23000' && in_array($driverCode, [1451, 1452], true))
            || ($sqlState === '23000' && in_array($driverCode, [19, 787], true)
                && str_contains(strtolower($exception->getMessage()), 'foreign key'));
    }

    private function validatedInitialBatchData(Request $request): array
    {
        return $request->validate([
            'initial_batches' => ['nullable', 'array'],
            'initial_batches.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'initial_batches.*.batch_number' => [
                'nullable',
                'string',
                'max:255',
                'distinct',
                Rule::unique('product_batches', 'batch_number'),
            ],
            'initial_batches.*.received_at' => ['nullable', 'date'],
            'initial_batches.*.expires_at' => ['nullable', 'date'],
        ]);
    }

    private function validatedData(Request $request, ?Product $product = null): array
    {
        $uniqueProductName = Rule::unique('products', 'name');

        if ($product) {
            $uniqueProductName->ignore($product->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255', $uniqueProductName],
            'manufacturer_designation' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'unit' => ['required', Rule::in(array_keys($this->units()))],
            'supplier' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'minimum_stock' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:product_categories,id'],
        ], [
            'name.unique' => 'Ein Produkt mit derselben Produktbezeichnung ist bereits vorhanden.',
        ]);
    }

    private function units(): array
    {
        return [
            'gram' => 'Gramm',
            'liter' => 'Liter',
            'piece' => 'Stück',
        ];
    }
}
