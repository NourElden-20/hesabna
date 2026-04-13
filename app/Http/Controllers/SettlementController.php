<?php

namespace App\Http\Controllers;

use App\Models\Settlement;
use App\Models\ExpenseSplit;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettlementController extends Controller
{
    /**
     * تسجيل عملية دفع (تسوية) بين شخصين
     */
    public function store(Request $request, $groupId)
    {
        $request->validate([
            'from_user_id'  => 'nullable|exists:users,id',
            'from_guest_id' => 'nullable|exists:group_guests,id',
            'to_user_id'    => 'nullable|exists:users,id',
            'to_guest_id'   => 'nullable|exists:group_guests,id',
            'amount'        => 'required|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($request, $groupId) {
            // 1. تسجيل عملية التسوية
            $settlement = Settlement::create([
                'group_id'      => $groupId,
                'from_user_id'  => $request->from_user_id,
                'from_guest_id' => $request->from_guest_id,
                'to_user_id'    => $request->to_user_id,
                'to_guest_id'   => $request->to_guest_id,
                'amount'        => $request->amount,
            ]);

            // 2. تحديث حالة الـ Splits (اختياري حسب منطق تطبيقك)
            // ملاحظة: في أنظمة الديون المعقدة، التسوية عادة بتنزل من "إجمالي" الدين
            // لكن لتبسيط الأمر، سنبحث عن الديون غير المسددة لهذا الشخص ونحولها لـ Settled
            if ($request->from_user_id) {
                ExpenseSplit::where('user_id', $request->from_user_id)
                    ->where('is_settled', false)
                    ->whereHas('expense', function($q) use ($groupId) {
                        $q->where('group_id', $groupId);
                    })
                    ->update(['is_settled' => true]);
            }

            return response()->json([
                'message' => 'تم تسجيل التسوية بنجاح',
                'settlement' => $settlement
            ], 201);
        });
    }

    /**
     * عرض تاريخ التسويات في الجروب
     */
    public function index($groupId)
    {
        $settlements = Settlement::with(['fromUser', 'fromGuest', 'toUser', 'toGuest'])
            ->where('group_id', $groupId)
            ->latest()
            ->get();

        return response()->json($settlements);
    }
}