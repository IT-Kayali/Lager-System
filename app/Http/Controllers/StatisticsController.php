<?php

namespace App\Http\Controllers;

use App\Models\BranchWithdrawal;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReservationReleaseService;
use App\Services\StatisticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StatisticsController extends Controller
{
    public function index(
        Request $request,
        ReservationReleaseService $reservationReleaseService,
        StatisticsService $statisticsService,
    ): View {
        $reservationReleaseService->releaseExpired();

        $filters = $statisticsService->filters($request);
        $dashboard = $statisticsService->build($filters);

        return view('pages.statistics.index', [
            'filters' => $filters,
            'dashboard' => $dashboard,
            'periods' => $statisticsService->periods(),
            'statusLabels' => Offer::STATUS_LABELS,
            'branchStatusLabels' => BranchWithdrawal::statusLabels(),
            'movementTypes' => $statisticsService->movementTypes(),
            'branches' => BranchWithdrawal::branches(),
            'shippingMethods' => [
                'Lieferung' => 'Lieferung',
                'Abholung' => 'Abholung',
            ],
            'customers' => Customer::query()
                ->orderBy('company_name')
                ->get(['id', 'customer_number', 'company_name']),
            'customerGroups' => CustomerGroup::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'products' => Product::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'product_code', 'name']),
            'categories' => ProductCategory::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'suppliers' => Supplier::query()
                ->orderBy('company_name')
                ->get(['id', 'supplier_number', 'company_name']),
            'users' => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'role']),
        ]);
    }
}
