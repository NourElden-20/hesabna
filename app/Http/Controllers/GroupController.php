<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseSplit;
use App\Models\Group;
use App\Models\GroupGuest;
use App\Models\GroupMember;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $createdGroups = Group::where('created_by', $user->id)->get();
        $memberGroups = $user->groups;

        $allGroups = $createdGroups->merge($memberGroups)->unique('id')->values();

        return response()->json($allGroups);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'emoji' => 'nullable|string|max:10',
        ]);

        $group = Group::create([
            'name' => $request->name,
            'emoji' => $request->emoji ?? '💰',
            'created_by' => $request->user()->id,
            'invite_token' => Str::random(10),
        ]);

        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json($group, 201);
    }

    public function show(Request $request, $id)
    {
        $group = Group::with(['members', 'guests', 'expenses'])->findOrFail($id);
        $this->ensureGroupAccess($request, $group);

        return response()->json($group);
    }

    public function addMember(Request $request, $id)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $group = Group::findOrFail($id);
        $this->ensureGroupAccess($request, $group);

        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير موجود، ممكن تضيفه كضيف',
            ], 404);
        }

        $exists = GroupMember::where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'المستخدم عضو في الجروب بالفعل',
            ], 409);
        }

        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
        ]);

        return response()->json(['message' => 'تم إضافة العضو بنجاح']);
    }

    public function addGuest(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
        ]);

        $group = Group::findOrFail($id);
        $this->ensureGroupAccess($request, $group);

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

        $exists = GroupMember::where('group_id', $group->id)
            ->where('user_id', $request->user()->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'أنت عضو في الجروب بالفعل'], 409);
        }

        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'تم الانضمام للجروب بنجاح',
            'group' => $group,
        ]);
    }

    public function getBalances(Request $request, $id)
    {
        $group = Group::with(['members', 'guests'])->findOrFail($id);
        $this->ensureGroupAccess($request, $group);

        $balances = [];

        foreach ($group->members as $member) {
            $balances['user_'.$member->id] = [
                'id' => $member->id,
                'name' => $member->name,
                'type' => 'member',
                'balance' => 0.0,
            ];
        }

        foreach ($group->guests as $guest) {
            $balances['guest_'.$guest->id] = [
                'id' => $guest->id,
                'name' => $guest->name,
                'type' => 'guest',
                'balance' => 0.0,
            ];
        }

        $expenses = Expense::where('group_id', $id)->get();
        foreach ($expenses as $expense) {
            $key = $expense->paid_by_user_id
                ? 'user_'.$expense->paid_by_user_id
                : 'guest_'.$expense->paid_by_guest_id;

            if (isset($balances[$key])) {
                $balances[$key]['balance'] += (float) $expense->amount;
            }
        }

        $splits = ExpenseSplit::whereHas('expense', function ($query) use ($id) {
            $query->where('group_id', $id);
        })->get();

        foreach ($splits as $split) {
            $key = $split->user_id
                ? 'user_'.$split->user_id
                : 'guest_'.$split->guest_id;

            if (isset($balances[$key])) {
                $balances[$key]['balance'] -= (float) $split->amount;
            }
        }

        $settlements = Settlement::where('group_id', $id)->get();
        foreach ($settlements as $settlement) {
            $fromKey = $settlement->from_user_id
                ? 'user_'.$settlement->from_user_id
                : 'guest_'.$settlement->from_guest_id;

            $toKey = $settlement->to_user_id
                ? 'user_'.$settlement->to_user_id
                : 'guest_'.$settlement->to_guest_id;

            if (isset($balances[$fromKey])) {
                $balances[$fromKey]['balance'] += (float) $settlement->amount;
            }
            if (isset($balances[$toKey])) {
                $balances[$toKey]['balance'] -= (float) $settlement->amount;
            }
        }

        // تقريب + إزالة الضوضاء الصغيرة جدا
        foreach ($balances as &$entry) {
            $entry['balance'] = round((float) $entry['balance'], 2);
            if (abs($entry['balance']) < 0.01) {
                $entry['balance'] = 0.0;
            }
        }
        unset($entry);

        return response()->json([
            'status' => 'success',
            'data' => array_values($balances),
        ]);
    }
}