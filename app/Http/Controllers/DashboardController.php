<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use App\Services\ReservationReleaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(ReservationReleaseService $reservationReleaseService): View|RedirectResponse
    {
        $user = auth()->user();

        abort_unless($user?->is_active, 403, 'Dieser Benutzer ist deaktiviert.');

        if ($user->isWarehouse()) {
            return redirect()->route('products.index');
        }

        if ($user->isSales()) {
            return redirect()->route('offers.index');
        }

        if ($user->isCrm()) {
            return redirect()->route('customers.index');
        }

        abort_unless($user->hasRole(User::ROLE_MANAGER), 403, 'Keine Berechtigung für das Dashboard.');

        $reservationReleaseService->releaseExpired();

        $products = Schema::hasTable('products') ? Product::query()->active()->get() : collect();
        $criticalProducts = $products->filter(fn (Product $product) => $product->stock_status === 'critical')->count();
        $lowProducts = $products->filter(fn (Product $product) => $product->stock_status === 'low')->count();

        $stats = [
            'products' => $products->count(),
            'reservations' => Schema::hasTable('offers')
                ? DB::table('offers')->whereIn('status', Offer::RESERVING_STATUSES)->count()
                : 0,
            'offers' => Schema::hasTable('offers') ? DB::table('offers')->count() : 0,
            'critical' => $criticalProducts,
            'low' => $lowProducts,
            'users' => User::count(),
        ];

        $latestActivities = Schema::hasTable('activity_logs')
            ? ActivityLog::query()->with('user')->latest()->limit(20)->get()
            : collect();

        $salesRanking = collect();

        if (Schema::hasTable('products') && Schema::hasTable('offer_items') && Schema::hasTable('offers')) {
            $salesRanking = DB::table('products')
                ->leftJoin('offer_items', 'offer_items.product_id', '=', 'products.id')
                ->leftJoin('offers', function ($join): void {
                    $join->on('offers.id', '=', 'offer_items.offer_id')
                        ->where('offers.status', '=', Offer::STATUS_COMPLETED);
                })
                ->select([
                    'products.id',
                    'products.name',
                    'products.created_at',
                    DB::raw('COALESCE(SUM(CASE WHEN offers.id IS NOT NULL THEN offer_items.quantity ELSE 0 END), 0) as sold_quantity'),
                ])
                ->groupBy('products.id', 'products.name', 'products.created_at')
                ->get();
        }

        $topSellingProducts = $salesRanking
            ->sort(function ($a, $b): int {
                $quantityComparison = (float) $b->sold_quantity <=> (float) $a->sold_quantity;
                if ($quantityComparison !== 0) {
                    return $quantityComparison;
                }

                return strcmp((string) $a->created_at, (string) $b->created_at);
            })
            ->take(10)
            ->values();

        $leastSellingProducts = $salesRanking
            ->sort(function ($a, $b): int {
                $quantityComparison = (float) $a->sold_quantity <=> (float) $b->sold_quantity;
                if ($quantityComparison !== 0) {
                    return $quantityComparison;
                }

                return strcmp((string) $a->created_at, (string) $b->created_at);
            })
            ->take(10)
            ->values();

        return view('dashboard', compact(
            'stats',
            'latestActivities',
            'topSellingProducts',
            'leastSellingProducts'
        ));
    }
}
