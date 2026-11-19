<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CustomerGroup;
use App\Models\ManualPriceRule;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Support\GermanNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PriceController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::query()
            ->active()
            ->with('categories')
            ->orderBy('name')
            ->get(['id', 'product_code', 'name', 'unit']);

        $groups = CustomerGroup::query()
            ->ordered()
            ->get();

        $selectedProduct = $products->firstWhere('id', (int) $request->query('product_id')) ?? $products->first();
        $selectedGroup = $groups->firstWhere('id', (int) $request->query('customer_group_id')) ?? $groups->first();
        $usesPriceTiers = $selectedProduct?->usesPriceTiers() ?? true;
        $tiers = collect();
        $manualRules = collect();

        if ($selectedProduct && $selectedGroup) {
            if ($usesPriceTiers) {
                ProductPriceTier::ensureForProduct($selectedProduct);

                $tiers = ProductPriceTier::query()
                    ->where('product_id', $selectedProduct->id)
                    ->where('customer_group_id', $selectedGroup->id)
                    ->orderBy('min_grams')
                    ->orderBy('max_grams')
                    ->orderBy('id')
                    ->get();
            } else {
                $manualRules = ManualPriceRule::query()
                    ->where('product_id', $selectedProduct->id)
                    ->where('customer_group_id', $selectedGroup->id)
                    ->orderBy('min_quantity')
                    ->orderByRaw('CASE WHEN max_quantity IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('max_quantity')
                    ->orderBy('id')
                    ->get();
            }
        }

        return view('pages.prices.index', [
            'products' => $products,
            'groups' => $groups,
            'selectedProduct' => $selectedProduct,
            'selectedGroup' => $selectedGroup,
            'usesPriceTiers' => $usesPriceTiers,
            'tiers' => $tiers,
            'manualRules' => $manualRules,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $selection = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'customer_group_id' => ['required', 'integer', 'exists:customer_groups,id'],
        ]);

        $product = Product::query()->active()->with('categories')->findOrFail($selection['product_id']);
        $group = CustomerGroup::findOrFail($selection['customer_group_id']);

        if ($product->usesPriceTiers()) {
            return $this->updateTierPrices($request, $product, $group);
        }

        return $this->updateManualRules($request, $product, $group);
    }

    private function updateTierPrices(Request $request, Product $product, CustomerGroup $group): RedirectResponse
    {
        // Textfelder zeigen die Preise immer mit deutschem Komma und exakt
        // fuenf Nachkommastellen. Die Validierung erhaelt Dezimalpunkte.
        $prices = $request->input('prices', []);
        if (is_array($prices)) {
            foreach ($prices as $tierId => $price) {
                if (is_string($price)) {
                    $prices[$tierId] = GermanNumber::parse($price);
                }
            }
            $request->merge(['prices' => $prices]);
        }

        $data = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*' => ['required', 'numeric', 'decimal:0,5', 'min:0', 'max:999999999'],
        ]);

        ProductPriceTier::ensureForProduct($product);

        foreach ($data['prices'] as $tierId => $price) {
            $tier = ProductPriceTier::query()
                ->where('id', $tierId)
                ->where('product_id', $product->id)
                ->where('customer_group_id', $group->id)
                ->firstOrFail();

            $oldPrice = (float) $tier->price;
            $newPrice = round((float) $price, 5);

            if ($oldPrice !== $newPrice) {
                $tier->update([
                    'price' => $newPrice,
                ]);

                ActivityLog::record('price.updated', $tier, [
                    'product_code' => $product->product_code,
                    'product_name' => $product->name,
                    'customer_group' => $group->name,
                    'tier' => $tier->tier_label,
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice,
                ]);
            }
        }

        return redirect()
            ->route('prices.index', [
                'product_id' => $product->id,
                'customer_group_id' => $group->id,
            ])
            ->with('success', 'Preisstaffeln wurden erfolgreich gespeichert.');
    }

    private function updateManualRules(Request $request, Product $product, CustomerGroup $group): RedirectResponse
    {
        $rules = collect($request->input('rules', []))
            ->filter(fn ($rule) => is_array($rule))
            ->filter(fn (array $rule) =>
                filled($rule['min_quantity'] ?? null)
                || filled($rule['max_quantity'] ?? null)
                || filled($rule['price'] ?? null)
                || filled($rule['label'] ?? null)
            )
            ->map(function (array $rule): array {
                if (isset($rule['price']) && is_string($rule['price'])) {
                    $rule['price'] = GermanNumber::parse($rule['price']);
                }

                return $rule;
            })
            ->values()
            ->all();

        $request->merge(['rules' => $rules]);

        $data = $request->validate([
            'rules' => ['required', 'array', 'min:1', 'max:100'],
            'rules.*.min_quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999.999'],
            'rules.*.max_quantity' => ['nullable', 'numeric', 'gte:rules.*.min_quantity', 'max:999999999.999'],
            'rules.*.price' => ['required', 'numeric', 'decimal:0,5', 'min:0', 'max:999999999'],
            'rules.*.label' => ['nullable', 'string', 'max:100'],
        ], [
            'rules.required' => 'Bitte mindestens eine Preisregel anlegen.',
            'rules.min' => 'Bitte mindestens eine Preisregel anlegen.',
            'rules.*.min_quantity.required' => 'Für jede Regel ist eine Mindestmenge erforderlich.',
            'rules.*.max_quantity.gte' => 'Die Bis-Menge darf nicht kleiner als die Von-Menge sein.',
            'rules.*.price.required' => 'Für jede Regel ist ein Preis erforderlich.',
        ]);

        $normalizedRules = collect($data['rules'])
            ->map(fn (array $rule) => [
                'min_quantity' => round((float) $rule['min_quantity'], 3),
                'max_quantity' => filled($rule['max_quantity'] ?? null)
                    ? round((float) $rule['max_quantity'], 3)
                    : null,
                'price' => round((float) $rule['price'], 5),
                'label' => filled($rule['label'] ?? null) ? trim((string) $rule['label']) : null,
            ])
            ->sortBy('min_quantity')
            ->values();

        $previousMax = null;
        $previousWasOpenEnded = false;

        foreach ($normalizedRules as $index => $rule) {
            if ($previousWasOpenEnded || ($previousMax !== null && $rule['min_quantity'] <= $previousMax)) {
                throw ValidationException::withMessages([
                    'rules' => 'Die Mengenbereiche dürfen sich nicht überschneiden. Bitte die Von-/Bis-Mengen prüfen.',
                ]);
            }

            $previousMax = $rule['max_quantity'];
            $previousWasOpenEnded = $rule['max_quantity'] === null;
        }

        DB::transaction(function () use ($product, $group, $normalizedRules): void {
            ManualPriceRule::query()
                ->where('product_id', $product->id)
                ->where('customer_group_id', $group->id)
                ->delete();

            foreach ($normalizedRules as $rule) {
                ManualPriceRule::query()->create([
                    'product_id' => $product->id,
                    'customer_group_id' => $group->id,
                    ...$rule,
                ]);
            }

            ActivityLog::record('manual_prices.updated', $product, [
                'product_code' => $product->product_code,
                'product_name' => $product->name,
                'customer_group' => $group->name,
                'rules_count' => $normalizedRules->count(),
            ]);
        });

        return redirect()
            ->route('prices.index', [
                'product_id' => $product->id,
                'customer_group_id' => $group->id,
            ])
            ->with('success', 'Manuelle Preisregeln wurden erfolgreich gespeichert.');
    }
}
