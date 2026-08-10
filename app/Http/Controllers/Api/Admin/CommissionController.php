<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\OrderCommission;
use App\Services\CommissionService;
use App\Traits\ScopesToBranch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Commission statements.
 *
 * Every figure returned here is summed from `order_commissions` rows. There is
 * no projection, no estimate and no "expected" total: a worker sees what has
 * actually been recorded against delivered orders, or an empty statement.
 */
class CommissionController extends Controller
{
    use ScopesToBranch;

    public function __construct(private readonly CommissionService $commissions) {}

    public function index(Request $request)
    {
        $admin = $this->admin();
        $seesEveryone = $admin->can('commission.view_all');

        if (! $seesEveryone && ! $admin->can('commission.view_own')) {
            abort(403, 'You do not have access to commission records');
        }

        $query = OrderCommission::query()
            ->with(['order:id,order_number,total,delivered_at', 'admin:id,name', 'branch:id,name,code'])
            ->when(! $seesEveryone, fn ($q) => $q->where('admin_id', $admin->id))
            ->when($seesEveryone && $request->filled('admin_id'), fn ($q) => $q->where('admin_id', $request->integer('admin_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->integer('status')))
            ->when($request->date('from'), fn ($q, $from) => $q->whereDate('earned_at', '>=', $from))
            ->when($request->date('to'), fn ($q, $to) => $q->whereDate('earned_at', '<=', $to))
            ->latest('id');

        // A manager still only sees their own branch, exactly as for orders.
        $this->scopeBranch($query);

        $rows = (clone $query)->paginate(getPaginate(20));

        return responseSuccess('commissions', 'Commissions fetched', [
            'commissions' => collect($rows->items())->map(fn (OrderCommission $row) => [
                'id' => $row->id,
                'order_id' => $row->order_id,
                'order_number' => $row->order?->order_number,
                'staff' => $row->admin?->name,
                'admin_id' => $row->admin_id,
                'branch' => $row->branch?->name,
                'rate' => (float) $row->rate,
                'basis_amount' => (float) $row->basis_amount,
                'amount' => (float) $row->amount,
                'status' => (int) $row->status,
                'status_label' => $row->status_label,
                'note' => $row->note,
                'payout_reference' => $row->payout_reference,
                'earned_at' => $row->earned_at?->toIso8601String(),
                'paid_at' => $row->paid_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
            'totals' => $this->totals(clone $query),
            'by_staff' => $seesEveryone ? $this->byStaff(clone $query) : [],
            'scheme' => [
                'enabled' => $this->commissions->enabled(),
                'rate' => $this->commissions->rate(),
                'attribution' => $this->commissions->attribution(),
                'basis' => 'Order subtotal, less any discount. Delivery and tax are excluded.',
                'event' => 'Recorded when an order is marked delivered; reversed if it is returned or cancelled.',
            ],
            'can_manage' => $admin->can('commission.manage'),
            'sees_everyone' => $seesEveryone,
            'staff' => $seesEveryone ? $this->staffOptions() : [],
        ]);
    }

    /** Approve a pending commission, or record that one has been paid. */
    public function changeStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', 'integer', Rule::in([Status::COMMISSION_APPROVED, Status::COMMISSION_PAID])],
            'payout_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $commission = OrderCommission::findOrFail($id);

        if (! $this->admin()->isSuperAdmin() && (int) $commission->branch_id !== (int) $this->admin()->branch_id) {
            abort(403, 'This commission belongs to another branch');
        }

        if ((int) $commission->status === Status::COMMISSION_REVERSED) {
            return responseError('reversed', ['A reversed commission cannot be approved or paid'], code: 422);
        }

        if ((int) $commission->status === Status::COMMISSION_PAID) {
            return responseError('already_paid', ['This commission has already been paid'], code: 422);
        }

        $target = (int) $data['status'];

        $commission->update(array_filter([
            'status' => $target,
            'approved_at' => $commission->approved_at ?? now(),
            'paid_at' => $target === Status::COMMISSION_PAID ? now() : null,
            'payout_reference' => $data['payout_reference'] ?? $commission->payout_reference,
        ], fn ($value) => $value !== null));

        return responseSuccess(
            'commission_updated',
            $target === Status::COMMISSION_PAID ? 'Commission marked as paid' : 'Commission approved',
            ['commission' => ['id' => $commission->id, 'status' => (int) $commission->fresh()->status]],
        );
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<OrderCommission>  $query */
    private function totals($query): array
    {
        $rows = $query->reorder()->get(['status', 'amount']);

        $sum = fn (array $statuses) => round(
            $rows->whereIn('status', $statuses)->sum('amount'),
            2,
        );

        return [
            'pending' => $sum([Status::COMMISSION_PENDING]),
            'approved' => $sum([Status::COMMISSION_APPROVED]),
            'paid' => $sum([Status::COMMISSION_PAID]),
            'reversed' => $sum([Status::COMMISSION_REVERSED]),
            // What is actually owed: earned, not yet paid, not withdrawn.
            'outstanding' => $sum([Status::COMMISSION_PENDING, Status::COMMISSION_APPROVED]),
            'orders' => $rows->count(),
        ];
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<OrderCommission>  $query */
    private function byStaff($query): array
    {
        return $query->reorder()
            ->selectRaw('admin_id, COUNT(*) as orders, SUM(amount) as total, SUM(CASE WHEN status IN (?, ?) THEN amount ELSE 0 END) as outstanding', [
                Status::COMMISSION_PENDING,
                Status::COMMISSION_APPROVED,
            ])
            ->groupBy('admin_id')
            ->with('admin:id,name')
            ->get()
            ->map(fn ($row) => [
                'admin_id' => $row->admin_id,
                'staff' => $row->admin?->name ?? 'Unknown',
                'orders' => (int) $row->orders,
                'total' => round((float) $row->total, 2),
                'outstanding' => round((float) $row->outstanding, 2),
            ])
            ->sortByDesc('outstanding')
            ->values()
            ->all();
    }

    private function staffOptions(): array
    {
        $query = Admin::query()->select('id', 'name', 'branch_id')->orderBy('name');
        $this->scopeBranch($query);

        return $query->get()->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->all();
    }
}
