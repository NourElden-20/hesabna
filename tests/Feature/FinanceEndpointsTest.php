<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupGuest;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_smoke_flow_register_login_group_members_guests_expenses_settlement_delete_and_balances(): void
    {
        $registerOwner = $this->postJson('/api/auth/register', [
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
            'phone' => '01000000001',
        ])->assertStatus(201);

        $ownerToken = $registerOwner->json('token');
        $ownerId = $registerOwner->json('user.id');

        $registerMember = $this->postJson('/api/auth/register', [
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => 'password',
            'phone' => '01000000002',
        ])->assertStatus(201);

        $memberId = $registerMember->json('user.id');

        $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'password',
        ])->assertStatus(200);

        $createGroup = $this
            ->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/groups', [
                'name' => 'Release Smoke Group',
            ])->assertStatus(201);

        $groupId = $createGroup->json('id');

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson("/api/groups/{$groupId}/members", [
                'phone' => '01000000002',
            ])->assertStatus(200);

        $guestResponse = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson("/api/groups/{$groupId}/guests", [
                'name' => 'Guest Smoke',
                'phone' => '01000000003',
            ])->assertStatus(201);

        $guestId = $guestResponse->json('id');

        $firstExpense = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson("/api/groups/{$groupId}/expenses", [
                'name' => 'Expense One',
                'amount' => 90.0,
                'paid_by_user_id' => $ownerId,
                'splits' => [
                    ['user_id' => $ownerId, 'amount' => 30.0],
                    ['user_id' => $memberId, 'amount' => 30.0],
                    ['guest_id' => $guestId, 'amount' => 30.0],
                ],
            ])->assertStatus(201);

        $secondExpense = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson("/api/groups/{$groupId}/expenses", [
                'name' => 'Expense Two',
                'amount' => 60.0,
                'paid_by_guest_id' => $guestId,
                'splits' => [
                    ['user_id' => $ownerId, 'amount' => 20.0],
                    ['user_id' => $memberId, 'amount' => 20.0],
                    ['guest_id' => $guestId, 'amount' => 20.0],
                ],
            ])->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson("/api/groups/{$groupId}/settlements", [
                'from_user_id' => $memberId,
                'to_user_id' => $ownerId,
                'amount' => 20.0,
            ])->assertStatus(201);

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson("/api/groups/{$groupId}/settlements", [
                'from_guest_id' => $guestId,
                'to_user_id' => $ownerId,
                'amount' => 10.0,
            ])->assertStatus(201);

        $balances = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->getJson("/api/groups/{$groupId}/balances")
            ->assertStatus(200)
            ->json('data');

        $indexed = collect($balances)->keyBy(fn (array $row) => $row['type'].'_'.$row['id']);
        $this->assertEquals(10.0, $indexed['member_'.$ownerId]['balance']);
        $this->assertEquals(-30.0, $indexed['member_'.$memberId]['balance']);
        $this->assertEquals(20.0, $indexed['guest_'.$guestId]['balance']);

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->deleteJson('/api/expenses/'.$secondExpense->json('expense.id'))
            ->assertStatus(200);

        $afterDeleteBalances = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->getJson("/api/groups/{$groupId}/balances")
            ->assertStatus(200)
            ->json('data');

        $afterDeleteIndexed = collect($afterDeleteBalances)->keyBy(fn (array $row) => $row['type'].'_'.$row['id']);
        $this->assertEquals(30.0, $afterDeleteIndexed['member_'.$ownerId]['balance']);
        $this->assertEquals(-10.0, $afterDeleteIndexed['member_'.$memberId]['balance']);
        $this->assertEquals(-20.0, $afterDeleteIndexed['guest_'.$guestId]['balance']);
        $this->assertDatabaseMissing('expenses', ['id' => $secondExpense->json('expense.id')]);
        $this->assertDatabaseHas('expenses', ['id' => $firstExpense->json('expense.id')]);
    }

    public function test_group_endpoints_require_membership_or_creator(): void
    {
        $creator = $this->makeUser();
        $outsider = $this->makeUser();
        $group = $this->makeGroup($creator);

        Sanctum::actingAs($outsider);

        $this->getJson("/api/groups/{$group->id}")
            ->assertStatus(403);

        $this->getJson("/api/groups/{$group->id}/expenses")
            ->assertStatus(403);

        $this->getJson("/api/groups/{$group->id}/settlements")
            ->assertStatus(403);
    }

    public function test_expense_and_settlement_are_idempotent_with_same_key(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $group = $this->makeGroup($user1, [$user2]);

        Sanctum::actingAs($user1);

        $expensePayload = [
            'name' => 'Dinner',
            'amount' => 100,
            'paid_by_user_id' => $user1->id,
            'splits' => [
                ['user_id' => $user1->id, 'amount' => 50],
                ['user_id' => $user2->id, 'amount' => 50],
            ],
        ];

        $firstExpense = $this->postJson("/api/groups/{$group->id}/expenses", $expensePayload, [
            'Idempotency-Key' => 'expense-key-1',
        ])->assertStatus(201);

        $secondExpense = $this->postJson("/api/groups/{$group->id}/expenses", $expensePayload, [
            'Idempotency-Key' => 'expense-key-1',
        ])->assertStatus(201);

        $this->assertSame(
            $firstExpense->json('expense.id'),
            $secondExpense->json('expense.id')
        );
        $this->assertDatabaseCount('expenses', 1);

        $settlementPayload = [
            'from_user_id' => $user2->id,
            'to_user_id' => $user1->id,
            'amount' => 50,
        ];

        $firstSettlement = $this->postJson("/api/groups/{$group->id}/settlements", $settlementPayload, [
            'Idempotency-Key' => 'settlement-key-1',
        ])->assertStatus(201);

        $secondSettlement = $this->postJson("/api/groups/{$group->id}/settlements", $settlementPayload, [
            'Idempotency-Key' => 'settlement-key-1',
        ])->assertStatus(201);

        $this->assertSame(
            $firstSettlement->json('settlement.id'),
            $secondSettlement->json('settlement.id')
        );
        $this->assertDatabaseCount('settlements', 1);
    }

    public function test_expense_validates_split_sum_and_returns_standard_422_shape(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $group = $this->makeGroup($user1, [$user2]);

        Sanctum::actingAs($user1);

        $this->postJson("/api/groups/{$group->id}/expenses", [
            'name' => 'Taxi',
            'amount' => 100,
            'paid_by_user_id' => $user1->id,
            'splits' => [
                ['user_id' => $user1->id, 'amount' => 40],
                ['user_id' => $user2->id, 'amount' => 50],
            ],
        ])->assertStatus(422)
            ->assertJsonStructure(['message', 'errors'])
            ->assertJsonPath('errors.splits.0', 'مجموع التقسيمات لازم يساوي قيمة المصروف.');
    }

    public function test_balances_support_members_guests_rounding_and_settlement_flow(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $group = $this->makeGroup($user1, [$user2]);
        $guest = GroupGuest::create([
            'group_id' => $group->id,
            'name' => 'Guest A',
        ]);

        Sanctum::actingAs($user1);

        $this->postJson("/api/groups/{$group->id}/expenses", [
            'name' => 'Trip',
            'amount' => 90.00,
            'paid_by_user_id' => $user1->id,
            'splits' => [
                ['user_id' => $user1->id, 'amount' => 30.00],
                ['user_id' => $user2->id, 'amount' => 30.00],
                ['guest_id' => $guest->id, 'amount' => 30.00],
            ],
        ])->assertStatus(201);

        $this->postJson("/api/groups/{$group->id}/settlements", [
            'from_user_id' => $user2->id,
            'to_user_id' => $user1->id,
            'amount' => 10.00,
        ])->assertStatus(201);

        $this->postJson("/api/groups/{$group->id}/settlements", [
            'from_guest_id' => $guest->id,
            'to_user_id' => $user1->id,
            'amount' => 30.00,
        ])->assertStatus(201);

        $balancesResponse = $this->getJson("/api/groups/{$group->id}/balances")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $balances = collect($balancesResponse->json('data'))
            ->keyBy(fn (array $row) => $row['type'].'_'.$row['id']);

        $this->assertEquals(20.0, $balances['member_'.$user1->id]['balance']);
        $this->assertEquals(-20.0, $balances['member_'.$user2->id]['balance']);
        $this->assertEquals(0.0, $balances['guest_'.$guest->id]['balance']);

        $expenseId = Expense::firstOrFail()->id;
        $this->deleteJson("/api/expenses/{$expenseId}")
            ->assertStatus(200);

        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('settlements', 2);
    }

    private function makeUser(): User
    {
        static $counter = 1;

        $user = User::factory()->create([
            'phone' => '01000000'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
        ]);
        $counter++;

        return $user;
    }

    private function makeGroup(User $creator, array $members = []): Group
    {
        $group = Group::create([
            'name' => 'Test Group',
            'emoji' => 'G',
            'created_by' => $creator->id,
            'invite_token' => 'invite-'.$creator->id.'-'.uniqid(),
        ]);

        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $creator->id,
        ]);

        foreach ($members as $member) {
            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $member->id,
            ]);
        }

        return $group;
    }
}
