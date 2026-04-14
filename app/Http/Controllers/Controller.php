<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\IdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function ensureGroupAccess(Request $request, Group $group): void
    {
        $isCreator = (int) $group->created_by === (int) $request->user()->id;
        $isMember = GroupMember::where('group_id', $group->id)
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $isCreator && ! $isMember) {
            abort(403, 'غير مصرح لك بالوصول لهذا الجروب');
        }
    }

    protected function getIdempotencyResponse(Request $request): ?JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return null;
        }

        $endpoint = $request->method().' '.$request->path();
        $existing = IdempotencyKey::where('user_id', $request->user()->id)
            ->where('endpoint', $endpoint)
            ->where('idempotency_key', $key)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest()
            ->first();

        if (! $existing) {
            return null;
        }

        return response()->json(
            json_decode($existing->response_body, true),
            (int) $existing->response_status
        );
    }

    protected function storeIdempotencyResponse(Request $request, JsonResponse $response): void
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return;
        }

        $endpoint = $request->method().' '.$request->path();

        IdempotencyKey::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'endpoint' => $endpoint,
                'idempotency_key' => $key,
            ],
            [
                'response_status' => $response->status(),
                'response_body' => json_encode(
                    $response->getData(true),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ]
        );
    }
}
