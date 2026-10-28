<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use App\Services\CategoryPriorityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductCategoryController extends Controller
{
    public function __construct(private readonly CategoryPriorityService $priorityService)
    {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $searchField = (string) $request->query('search_field', 'all');
        $exact = $request->boolean('exact');
        $status = trim((string) $request->query('status'));

        $allowedSearchFields = ['all', 'name', 'description'];
        if (! in_array($searchField, $allowedSearchFields, true)) {
            $searchField = 'all';
        }

        if (! in_array($status, ['', 'active', 'inactive'], true)) {
            $status = '';
        }

        $categories = ProductCategory::query()
            ->withCount('products')
            ->when($search !== '', function ($query) use ($search, $searchField, $exact) {
                $operator = $exact ? '=' : 'like';
                $value = $exact ? $search : "%{$search}%";

                $query->where(function ($subQuery) use ($searchField, $operator, $value) {
                    match ($searchField) {
                        'name' => $subQuery->where('name', $operator, $value),
                        'description' => $subQuery->where('description', $operator, $value),
                        default => $subQuery
                            ->where('name', $operator, $value)
                            ->orWhere('description', $operator, $value),
                    };
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderByRaw('LOWER(name) ASC')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('pages.product-categories.index', [
            'categories' => $categories,
            'search' => $search,
            'searchField' => $searchField,
            'exact' => $exact,
            'selectedStatus' => $status,
        ]);
    }

    public function create(): View
    {
        return view('pages.product-categories.create', [
            'category' => new ProductCategory([
                'priority' => $this->priorityService->nextPriority(),
                'is_active' => true,
                'price_tiers_enabled' => true,
                'color' => '#d4af37',
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->priorityService->create($this->validatedData($request));

        return redirect()
            ->route('product-categories.index')
            ->with('success', 'Kategorie wurde erfolgreich angelegt.');
    }

    public function show(ProductCategory $productCategory): View
    {
        $productCategory->load([
            'products' => fn ($query) => $query
                ->with('supplierRecord')
                ->naturalNameOrder(),
        ]);

        return view('pages.product-categories.show', [
            'category' => $productCategory,
        ]);
    }

    public function edit(ProductCategory $productCategory): View
    {
        return view('pages.product-categories.edit', [
            'category' => $productCategory,
        ]);
    }

    public function update(Request $request, ProductCategory $productCategory): RedirectResponse
    {
        $this->priorityService->update($productCategory, $this->validatedData($request, $productCategory));

        return redirect()
            ->route('product-categories.index')
            ->with('success', 'Kategorie wurde erfolgreich aktualisiert.');
    }

    public function destroy(ProductCategory $productCategory): RedirectResponse
    {
        if ($productCategory->products()->exists()) {
            return redirect()
                ->route('product-categories.index')
                ->with('error', 'Kategorie kann nicht gelöscht werden, weil noch Produkte zugeordnet sind.');
        }

        $this->priorityService->delete($productCategory);

        return redirect()
            ->route('product-categories.index')
            ->with('success', 'Kategorie wurde gelöscht.');
    }

    private function validatedData(Request $request, ?ProductCategory $categoryForUrl = null): array
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($categoryForUrl): void {
                    $slug = \Illuminate\Support\Str::slug((string) $value);

                    if ($slug === '' || $slug === 'create') {
                        $fail('Dieser Name kann nicht als eindeutiger Link verwendet werden.');
                        return;
                    }

                    $collision = ProductCategory::query()
                        ->when(
                            $categoryForUrl !== null,
                            fn ($query) => $query->where('id', '!=', $categoryForUrl?->id)
                        )
                        ->pluck('name')
                        ->contains(
                            fn ($existing) =>
                                \Illuminate\Support\Str::slug(
                                    (string) $existing
                                ) === $slug
                        );

                    if ($collision) {
                        $fail('Dieser Name erzeugt denselben Link wie ein vorhandener Eintrag.');
                    }
                },
            ],
            'priority' => ['required', 'integer', 'min:1', 'max:999999'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
            'price_tiers_enabled' => ['nullable', 'boolean'],
        ]);

        $data['priority'] = (int) $data['priority'];
        $data['is_active'] = $request->boolean('is_active');
        $data['price_tiers_enabled'] = $request->boolean('price_tiers_enabled');

        return $data;
    }
}
