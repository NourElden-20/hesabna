<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseSplit;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    /**
     * عرض كل مصاريف الجروب
     */
    public function index($groupId)
    {
        $expenses = Expense::with(['paidByUser', 'paidByGuest', 'splits.user', 'splits.guest'])
            ->where('group_id', $groupId)
            ->latest()
            ->get();

        return response()->json($expenses);
    }

    /**
     * تسجيل مصروف جديد وتقسيمه
     */
    public function store(Request $request, $groupId)
    {
        $request->validate([
            'name'             => 'required|string|max:255',
            'amount'           => 'required|numeric|min:0.01',
            'emoji'            => 'nullable|string',
            'paid_by_user_id'  => 'nullable|exists:users,id',
            'paid_by_guest_id' => 'nullable|exists:group_guests,id',
            'splits'           => 'required|array|min:1',
            'splits.*.user_id' => 'nullable|exists:users,id',
            'splits.*.guest_id'=> 'nullable|exists:group_guests,id',
            'splits.*.amount'  => 'required|numeric|min:0',
        ]);

        $group = Group::findOrFail($groupId);

        // استخدام Transaction عشان نضمن إن المصروف والتقسيم يتسجلوا مع بعض أو مفيش حاجة تتسجل لو حصل خطأ
        return DB::transaction(function () use ($request, $group) {
            
            // 1. إنشاء المصروف الأساسي
            $expense = Expense::create([
                'group_id'         => $group->id,
                'name'             => $request->name,
                'amount'           => $request->amount,
                'emoji'            => $request->emoji ?? '💸',
                'paid_by_user_id'  => $request->paid_by_user_id,
                'paid_by_guest_id' => $request->paid_by_guest_id,
            ]);

            // 2. توزيع المبالغ (Splits)
            foreach ($request->splits as $splitData) {
                ExpenseSplit::create([
                    'expense_id' => $expense->id,
                    'user_id'    => $splitData['user_id'] ?? null,
                    'guest_id'   => $splitData['guest_id'] ?? null,
                    'amount'     => $splitData['amount'],
                    'is_settled' => false,
                ]);
            }

            return response()->json([
                'message' => 'تم إضافة المصروف وتقسيمه بنجاح',
                'expense' => $expense->load('splits')
            ], 201);
        });
    }

    /**
     * حذف مصروف (سيؤدي لحذف التقسيمات تلقائياً لو أعددت الـ Cascade في الداتابيز)
     */
    public function destroy($id)
    {
        $expense = Expense::findOrFail($id);
        $expense->delete();

        return response()->json(['message' => 'تم حذف المصروف بنجاح']);
    }
}