<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseSplit;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->ensureGroupAccess($request, $group);

        $expenses = Expense::with(['paidByUser', 'paidByGuest', 'splits.user', 'splits.guest'])
            ->where('group_id', $groupId)
            ->latest()
            ->get();

        return response()->json($expenses);
    }

    public function store(Request $request, $groupId)
    {
        if ($cached = $this->getIdempotencyResponse($request)) {
            return $cached;
        }

        $group = Group::with(['members:id', 'guests:id,group_id'])->findOrFail($groupId);
        $this->ensureGroupAccess($request, $group);

        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'amount'           => 'required|numeric|min:0.01',
            'emoji'            => 'nullable|string|max:10',
            'paid_by_user_id'  => 'nullable|exists:users,id',
            'paid_by_guest_id' => 'nullable|exists:group_guests,id',
            'splits'           => 'required|array|min:1',
            'splits.*.user_id' => 'nullable|exists:users,id',
            'splits.*.guest_id'=> 'nullable|exists:group_guests,id',
            'splits.*.amount'  => 'required|numeric|min:0.01',
        ]);

        // XOR للدافع
        $payerUser = $data['paid_by_user_id'] ?? null;
        $payerGuest = $data['paid_by_guest_id'] ?? null;
        if ((is_null($payerUser) && is_null($payerGuest)) || (!is_null($payerUser) && !is_null($payerGuest))) {
            throw ValidationException::withMessages([
                'paid_by_user_id' => ['لازم تحدد دافع واحد فقط (عضو أو ضيف).'],
            ]);
        }

        $memberIds = $group->members->pluck('id')->map(fn($v) => (int)$v)->toArray();
        $guestIds = $group->guests->pluck('id')->map(fn($v) => (int)$v)->toArray();

        if ($payerUser && !in_array((int)$payerUser, $memberIds, true)) {
            throw ValidationException::withMessages([
                'paid_by_user_id' => ['الدافع (عضو) ليس ضمن أعضاء هذا الجروب.'],
            ]);
        }

        if ($payerGuest && !in_array((int)$payerGuest, $guestIds, true)) {
            throw ValidationException::withMessages([
                'paid_by_guest_id' => ['الدافع (ضيف) ليس ضمن ضيوف هذا الجروب.'],
            ]);
        }

        $sum = 0.0;
        foreach ($data['splits'] as $i => $split) {
            $sUser = $split['user_id'] ?? null;
            $sGuest = $split['guest_id'] ?? null;

            // XOR لكل split
            if ((is_null($sUser) && is_null($sGuest)) || (!is_null($sUser) && !is_null($sGuest))) {
                throw ValidationException::withMessages([
                    "splits.$i" => ['كل Split لازم يكون لشخص واحد فقط (عضو أو ضيف).'],
                ]);
            }

            if ($sUser && !in_array((int)$sUser, $memberIds, true)) {
                throw ValidationException::withMessages([
                    "splits.$i.user_id" => ['العضو في هذا الـ split ليس ضمن أعضاء الجروب.'],
                ]);
            }

            if ($sGuest && !in_array((int)$sGuest, $guestIds, true)) {
                throw ValidationException::withMessages([
                    "splits.$i.guest_id" => ['الضيف في هذا الـ split ليس ضمن ضيوف الجروب.'],
                ]);
            }

            $sum += (float) $split['amount'];
        }

        $amount = (float) $data['amount'];
        if (abs($sum - $amount) > 0.01) {
            throw ValidationException::withMessages([
                'splits' => ['مجموع التقسيمات لازم يساوي قيمة المصروف.'],
            ]);
        }

        return DB::transaction(function () use ($request, $data, $group) {
            $expense = Expense::create([
                'group_id'         => $group->id,
                'name'             => $data['name'],
                'amount'           => $data['amount'],
                'emoji'            => $data['emoji'] ?? '💸',
                'paid_by_user_id'  => $data['paid_by_user_id'] ?? null,
                'paid_by_guest_id' => $data['paid_by_guest_id'] ?? null,
            ]);

            foreach ($data['splits'] as $splitData) {
                ExpenseSplit::create([
                    'expense_id' => $expense->id,
                    'user_id'    => $splitData['user_id'] ?? null,
                    'guest_id'   => $splitData['guest_id'] ?? null,
                    'amount'     => $splitData['amount'],
                    'is_settled' => false,
                ]);
            }

            $response = response()->json([
                'message' => 'تم إضافة المصروف وتقسيمه بنجاح',
                'expense' => $expense->load(['paidByUser', 'paidByGuest', 'splits.user', 'splits.guest']),
            ], 201);

            $this->storeIdempotencyResponse($request, $response);

            return $response;
        });
    }

    public function destroy(Request $request, $id)
    {
        $expense = Expense::findOrFail($id);
        $group = Group::findOrFail($expense->group_id);
        $this->ensureGroupAccess($request, $group);

        $expense->delete();

        return response()->json(['message' => 'تم حذف المصروف بنجاح']);
    }
}