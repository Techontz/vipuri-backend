<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Company;
use App\Models\Order;
use App\Services\AuditService;
use App\Services\FileManager;
use App\Traits\ScopesToBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Branch management. Only a super admin may create, edit or delete branches;
 * a branch manager can read their own branch record.
 */
class BranchController extends Controller
{
    use ScopesToBranch;

    public function __construct(
        private readonly AuditService $audit,
        private readonly FileManager $files,
    ) {}

    public function index(Request $request)
    {
        $query = Branch::query()
            ->withCount(['staff', 'orders'])
            ->when($request->query('search'), fn ($q, $s) => $q->where(function ($inner) use ($s) {
                $inner->where('name', 'like', "%$s%")
                    ->orWhere('code', 'like', "%$s%")
                    ->orWhere('city', 'like', "%$s%");
            }))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->orderByDesc('is_default')
            ->orderBy('name');

        $this->scopeBranch($query, 'id');

        $branches = $query->paginate(getPaginate(20));

        return responseSuccess('branches', 'Branches fetched', [
            'branches' => BranchResource::collection($branches->items()),
            'pagination' => $this->pagination($branches),
        ]);
    }

    /** Flat list for select boxes. */
    public function options()
    {
        $query = Branch::query()->active()->orderBy('name');
        $this->scopeBranch($query, 'id');

        return responseSuccess('branch_options', 'Branches fetched', [
            'branches' => $query->get(['id', 'name', 'code', 'city']),
        ]);
    }

    public function show(int $id)
    {
        $this->authorizeBranch($id);

        $branch = Branch::withCount(['staff', 'orders'])->findOrFail($id);

        return responseSuccess('branch', 'Branch fetched', [
            'branch' => new BranchResource($branch),
            'stats' => $this->branchStats($branch),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertSuperAdmin();

        $data = $this->validated($request);

        $data['company_id'] = Company::current()->id;
        $data['slug'] = $this->uniqueSlug($data['name']);

        if ($request->hasFile('image')) {
            $data['image'] = $this->files->uploadImage($request->file('image'), 'branch');
        }

        $branch = Branch::create($data);

        if ($branch->is_default) {
            Branch::where('id', '!=', $branch->id)->update(['is_default' => 0]);
        }

        $this->audit->log('branch.created', $branch, newValues: $branch->getAttributes(), description: "Branch {$branch->name} created", branchId: $branch->id);

        return responseSuccess('branch_created', 'Branch created', ['branch' => new BranchResource($branch)]);
    }

    public function update(Request $request, int $id)
    {
        $this->assertSuperAdmin();

        $branch = Branch::findOrFail($id);
        $before = $branch->getAttributes();

        $data = $this->validated($request, $id);

        if ($request->hasFile('image')) {
            $data['image'] = $this->files->uploadImage($request->file('image'), 'branch', $branch->image);
        }

        $branch->fill($data)->save();

        if ($branch->is_default) {
            Branch::where('id', '!=', $branch->id)->update(['is_default' => 0]);
        }

        $this->audit->logUpdate('branch.updated', $branch, $before, "Branch {$branch->name} updated");

        return responseSuccess('branch_updated', 'Branch updated', ['branch' => new BranchResource($branch->fresh())]);
    }

    public function changeStatus(int $id)
    {
        $this->assertSuperAdmin();

        $branch = Branch::findOrFail($id);

        if ($branch->is_default && $branch->status) {
            return responseError('default_branch', ['The default branch cannot be deactivated']);
        }

        $branch->changeStatus();

        $this->audit->log(
            'branch.status_changed',
            $branch,
            newValues: ['status' => $branch->status],
            description: "Branch {$branch->name} " . ($branch->status ? 'activated' : 'deactivated'),
            branchId: $branch->id,
        );

        return responseSuccess('branch_status_changed', 'Branch status updated', [
            'branch' => new BranchResource($branch),
        ]);
    }

    /**
     * Branches are only deletable while nothing depends on them; otherwise the
     * caller is told to deactivate instead, so history is never destroyed.
     */
    public function destroy(int $id)
    {
        $this->assertSuperAdmin();

        $branch = Branch::withCount(['staff', 'orders', 'inventories'])->findOrFail($id);

        if ($branch->is_default) {
            return responseError('default_branch', ['The default branch cannot be deleted']);
        }

        if ($branch->orders_count > 0 || $branch->staff_count > 0) {
            return responseError('branch_in_use', [
                'This branch has staff or orders attached. Deactivate it instead of deleting.',
            ]);
        }

        $name = $branch->name;
        BranchInventory::where('branch_id', $branch->id)->delete();
        $branch->delete();

        $this->audit->log('branch.deleted', description: "Branch {$name} deleted");

        return responseSuccess('branch_deleted', 'Branch deleted');
    }

    /** Performance snapshot used by the super admin branch monitor. */
    public function performance(Request $request)
    {
        $this->assertSuperAdmin();

        $from = $request->date('from') ?? now()->subDays(30)->startOfDay();
        $to = $request->date('to') ?? now()->endOfDay();

        $branches = Branch::query()->orderBy('name')->get()->map(function (Branch $branch) use ($from, $to) {
            $orders = Order::where('branch_id', $branch->id)->whereBetween('created_at', [$from, $to]);

            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'city' => $branch->city,
                'status' => (bool) $branch->status,
                'orders' => (clone $orders)->count(),
                'delivered' => (clone $orders)->delivered()->count(),
                'cancelled' => (clone $orders)->cancelled()->count(),
                'revenue' => (float) (clone $orders)->where('payment_status', Status::PAYMENT_SUCCESS)->sum('total'),
                'staff' => Admin::where('branch_id', $branch->id)->count(),
                'low_stock_items' => BranchInventory::where('branch_id', $branch->id)->lowStock()->count(),
                'out_of_stock_items' => BranchInventory::where('branch_id', $branch->id)->outOfStock()->count(),
            ];
        });

        return responseSuccess('branch_performance', 'Branch performance fetched', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'branches' => $branches->values(),
        ]);
    }

    private function branchStats(Branch $branch): array
    {
        $orders = Order::where('branch_id', $branch->id);

        return [
            'orders_total' => (clone $orders)->count(),
            'orders_pending' => (clone $orders)->pending()->count(),
            'orders_delivered' => (clone $orders)->delivered()->count(),
            'revenue' => (float) (clone $orders)->where('payment_status', Status::PAYMENT_SUCCESS)->sum('total'),
            'staff_total' => Admin::where('branch_id', $branch->id)->count(),
            'inventory_items' => BranchInventory::where('branch_id', $branch->id)->count(),
            'low_stock_items' => BranchInventory::where('branch_id', $branch->id)->lowStock()->count(),
            'out_of_stock_items' => BranchInventory::where('branch_id', $branch->id)->outOfStock()->count(),
        ];
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:40', 'unique:branches,code' . ($id ? ",$id" : '')],
            'email' => ['nullable', 'email', 'max:191'],
            'dial_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:191'],
            'region' => ['nullable', 'string', 'max:191'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
            'is_pickup_point' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
            'opening_hours' => ['nullable', 'array'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;

        while (Branch::where('slug', $slug)->exists()) {
            $slug = $base . '-' . ++$i;
        }

        return $slug;
    }

    private function assertSuperAdmin(): void
    {
        if (! $this->admin()->isCompanyWide()) {
            abort(403, 'Only company-wide administrators can manage branches');
        }
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
