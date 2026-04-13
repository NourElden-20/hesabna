<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupGuest;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Expense;
use App\Models\ExpenseSplit;
use App\Models\Settlement;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // الجروبات اللي إنت عملتها
        $createdGroups = Group::where('created_by', $user->id)->get();

        // الجروبات اللي إنت عضو فيها
        $memberGroups = $user->groups;

        // دمجهم مع بعض من غير تكرار
        $allGroups = $createdGroups->merge($memberGroups)->unique('id')->values();

        return response()->json($allGroups);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'emoji' => 'nullable|string',
        ]);

        $group = Group::create([
            'name' => $request->name,
            'emoji' => $request->emoji ?? '💰',
            'created_by' => $request->user()->id,
            'invite_token' => Str::random(10),
        ]);

        // ضيف المستخدم الحالي كعضو تلقائياً
        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json($group, 201);
    }

    public function show(Request $request, $id)
    {
        $group = Group::with(['members', 'guests', 'expenses'])->findOrFail($id);

        return response()->json($group);
    }

    public function addMember(Request $request, $id)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $group = Group::findOrFail($id);

        // دور على المستخدم برقم الموبايل
        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم مش موجود — ممكن تضيفه كضيف',
            ], 404);
        }

        // تأكد إنه مش عضو أصلاً
        $exists = GroupMember::where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'المستخدم عضو في الجروب أصلاً',
            ], 409);
        }

        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'تم إضافة العضو بنجاح',
        ]);
    }

    public function addGuest(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string',
        ]);

        $group = Group::findOrFail($id);

        $guest = GroupGuest::create([
            'group_id' => $group->id,
            'name' => $request->name,
            'phone' => $request->phone,
        ]);

        return response()->json($guest, 201);
    }

    public function join(Request $request, $token)
    {
        $group = Group::where('invite_token', $token)->firstOrFail();

        $user = $request->user();

        // تأكد إنه مش عضو أصلاً
        $exists = GroupMember::where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'إنت عضو في الجروب أصلاً',
            ], 409);
        }

        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'تم الانضمام للجروب بنجاح',
            'group' => $group,
        ]);
    }

    /**
     * حساب صافي الأرصدة لكل أعضاء وضيوف الجروب
     * المسار: GET /api/groups/{id}/balances
     */
    public function getBalances($id)
    {
        try {
            // جلب الجروب مع الأعضاء والضيوف
            $group = Group::with(['members', 'guests'])->findOrFail($id);
            $balances = [];

            // 1. تجهيز القائمة: نضع رصيد مبدئي (0) لكل عضو وضيف
            foreach ($group->members as $member) {
                $balances['user_'.$member->id] = [
                    'id' => $member->id,
                    'name' => $member->name,
                    'type' => 'member',
                    'balance' => 0,
                ];
            }

            foreach ($group->guests as $guest) {
                $balances['guest_'.$guest->id] = [
                    'id' => $guest->id,
                    'name' => $guest->name,
                    'type' => 'guest',
                    'balance' => 0,
                ];
            }

            // 2. إضافة المبالغ التي دفعها الأشخاص (تزيد رصيدهم بالـ +)
            $expenses = Expense::where('group_id', $id)->get();
            foreach ($expenses as $expense) {
                $key = $expense->paid_by_user_id ? 'user_'.$expense->paid_by_user_id : 'guest_'.$expense->paid_by_guest_id;
                if (isset($balances[$key])) {
                    $balances[$key]['balance'] += $expense->amount;
                }
            }

            // 3. خصم المبالغ التي يجب عليهم دفعها (نصيبهم في التقسيم -)
            $splits = ExpenseSplit::whereHas('expense', function ($query) use ($id) {
                $query->where('group_id', $id);
            })->get();

            foreach ($splits as $split) {
                $key = $split->user_id ? 'user_'.$split->user_id : 'guest_'.$split->guest_id;
                if (isset($balances[$key])) {
                    $balances[$key]['balance'] -= $split->amount;
                }
            }

            // 4. أخذ التسويات (Settlements) في الاعتبار
            $settlements = Settlement::where('group_id', $id)->get();
            foreach ($settlements as $settlement) {
                // الشخص اللي دفع (المديون سابقاً) رصيده بيزيد لأنه بيسدد اللي عليه
                $fromKey = $settlement->from_user_id ? 'user_'.$settlement->from_user_id : 'guest_'.$settlement->from_guest_id;
                // الشخص اللي استلم (الدائن سابقاً) رصيده بيقل لأنه استلم فلوسه خلاص
                $toKey = $settlement->to_user_id ? 'user_'.$settlement->to_user_id : 'guest_'.$settlement->to_guest_id;

                if (isset($balances[$fromKey])) {
                    $balances[$fromKey]['balance'] += $settlement->amount;
                }
                if (isset($balances[$toKey])) {
                    $balances[$toKey]['balance'] -= $settlement->amount;
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => array_values($balances),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'حدث خطأ أثناء حساب الأرصدة: '.$e->getMessage(),
            ], 500);
        }
    }
}
