<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettlementController extends Controller
{
    public function store(Request $request, $groupId)
    {
        if ($cached = $this->getIdempotencyResponse($request)) {
            return $cached;
        }

        $group = Group::with(['members:id', 'guests:id,group_id'])->findOrFail($groupId);
        $this->ensureGroupAccess($request, $group);

        $data = $request->validate([
            'from_user_id'  => 'nullable|exists:users,id',
            'from_guest_id' => 'nullable|exists:group_guests,id',
            'to_user_id'    => 'nullable|exists:users,id',
            'to_guest_id'   => 'nullable|exists:group_guests,id',
            'amount'        => 'required|numeric|min:0.01',
        ]);

        $fromUser = $data['from_user_id'] ?? null;
        $fromGuest = $data['from_guest_id'] ?? null;
        $toUser = $data['to_user_id'] ?? null;
        $toGuest = $data['to_guest_id'] ?? null;

        // XOR from
        if ((is_null($fromUser) && is_null($fromGuest)) || (!is_null($fromUser) && !is_null($fromGuest))) {
            throw ValidationException::withMessages([
                'from_user_id' => ['لازم تحدد دافع واحد فقط (عضو أو ضيف).'],
            ]);
        }

        // XOR to
        if ((is_null($toUser) && is_null($toGuest)) || (!is_null($toUser) && !is_null($toGuest))) {
            throw ValidationException::withMessages([
                'to_user_id' => ['لازم تحدد مستلم واحد فقط (عضو أو ضيف).'],
            ]);
        }

        $fromKey = $fromUser ? 'u:'.$fromUser : 'g:'.$fromGuest;
        $toKey = $toUser ? 'u:'.$toUser : 'g:'.$toGuest;

        if ($fromKey === $toKey) {
            throw ValidationException::withMessages([
                'amount' => ['لا يمكن عمل تسوية لنفس الشخص.'],
            ]);
        }

        $memberIds = $group->members->pluck('id')->map(fn($v) => (int)$v)->toArray();
        $guestIds = $group->guests->pluck('id')->map(fn($v) => (int)$v)->toArray();

        if ($fromUser && !in_array((int)$fromUser, $memberIds, true)) {
            throw ValidationException::withMessages(['from_user_id' => ['العضو الدافع ليس ضمن هذا الجروب.']]);
        }
        if ($toUser && !in_array((int)$toUser, $memberIds, true)) {
            throw ValidationException::withMessages(['to_user_id' => ['العضو المستلم ليس ضمن هذا الجروب.']]);
        }
        if ($fromGuest && !in_array((int)$fromGuest, $guestIds, true)) {
            throw ValidationException::withMessages(['from_guest_id' => ['الضيف الدافع ليس ضمن هذا الجروب.']]);
        }
        if ($toGuest && !in_array((int)$toGuest, $guestIds, true)) {
            throw ValidationException::withMessages(['to_guest_id' => ['الضيف المستلم ليس ضمن هذا الجروب.']]);
        }

        return DB::transaction(function () use ($request, $data, $groupId) {
            $settlement = Settlement::create([
                'group_id'      => $groupId,
                'from_user_id'  => $data['from_user_id'] ?? null,
                'from_guest_id' => $data['from_guest_id'] ?? null,
                'to_user_id'    => $data['to_user_id'] ?? null,
                'to_guest_id'   => $data['to_guest_id'] ?? null,
                'amount'        => $data['amount'],
            ]);

            // مهم: لا نعدل ExpenseSplit.is_settled هنا
            $response = response()->json([
                'message' => 'تم تسجيل التسوية بنجاح',
                'settlement' => $settlement,
            ], 201);

            $this->storeIdempotencyResponse($request, $response);

            return $response;
        });
    }

    public function index(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->ensureGroupAccess($request, $group);

        $settlements = Settlement::with(['fromUser', 'fromGuest', 'toUser', 'toGuest'])
            ->where('group_id', $groupId)
            ->latest()
            ->get();

        return response()->json($settlements);
    }
}