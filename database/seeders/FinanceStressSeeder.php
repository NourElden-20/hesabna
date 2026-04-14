<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\ExpenseSplit;
use App\Models\Group;
use App\Models\GroupGuest;
use App\Models\GroupMember;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FinanceStressSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::factory()->count(100)->create();

        $groupCount = 18;
        $expenseNames = ['Food', 'Fuel', 'Coffee', 'Outing', 'Bills', 'Shopping', 'Trip'];
        $expenseEmoji = ['🍔', '⛽', '☕', '🎉', '💡', '🛒', '🧳'];

        for ($g = 1; $g <= $groupCount; $g++) {
            $creator = $users->random();

            $group = Group::create([
                'name' => "Group {$g}",
                'emoji' => '💰',
                'created_by' => $creator->id,
                'invite_token' => Str::uuid()->toString(),
            ]);

            $memberUsers = $users
                ->where('id', '!=', $creator->id)
                ->random(rand(4, 11))
                ->push($creator)
                ->unique('id')
                ->values();

            foreach ($memberUsers as $member) {
                GroupMember::firstOrCreate([
                    'group_id' => $group->id,
                    'user_id' => $member->id,
                ]);
            }

            $guests = collect();
            $guestCount = rand(1, 4);
            for ($i = 0; $i < $guestCount; $i++) {
                $guests->push(GroupGuest::create([
                    'group_id' => $group->id,
                    'name' => "Guest {$g}-{$i}",
                    'phone' => '01'.str_pad((string) rand(0, 999999999), 9, '0', STR_PAD_LEFT),
                ]));
            }

            $expensesPerGroup = rand(25, 60);
            $allPayersUsers = $memberUsers->values();
            $allPayersGuests = $guests->values();

            for ($e = 0; $e < $expensesPerGroup; $e++) {
                $amount = rand(50, 2500);

                $payByUser = rand(0, 100) < 75;
                $paidByUserId = null;
                $paidByGuestId = null;

                if ($payByUser || $allPayersGuests->isEmpty()) {
                    $paidByUserId = $allPayersUsers->random()->id;
                } else {
                    $paidByGuestId = $allPayersGuests->random()->id;
                }

                $expense = Expense::create([
                    'name' => $expenseNames[array_rand($expenseNames)],
                    'amount' => $amount,
                    'emoji' => $expenseEmoji[array_rand($expenseEmoji)],
                    'group_id' => $group->id,
                    'paid_by_user_id' => $paidByUserId,
                    'paid_by_guest_id' => $paidByGuestId,
                ]);

                $participants = collect();

                foreach ($memberUsers as $user) {
                    $participants->push(['type' => 'user', 'id' => $user->id]);
                }
                foreach ($guests as $guest) {
                    $participants->push(['type' => 'guest', 'id' => $guest->id]);
                }

                $splitCount = rand(2, min(6, $participants->count()));
                $selected = $participants->random($splitCount)->values();
                $remaining = $amount;

                for ($s = 0; $s < $splitCount; $s++) {
                    $row = $selected[$s];
                    $isLast = $s === $splitCount - 1;

                    if ($isLast) {
                        $splitAmount = $remaining;
                    } else {
                        $maxAllowed = max(1, $remaining - ($splitCount - $s - 1));
                        $splitAmount = rand(1, $maxAllowed);
                        $remaining -= $splitAmount;
                    }

                    ExpenseSplit::create([
                        'expense_id' => $expense->id,
                        'user_id' => $row['type'] === 'user' ? $row['id'] : null,
                        'guest_id' => $row['type'] === 'guest' ? $row['id'] : null,
                        'amount' => $splitAmount,
                        'is_settled' => (bool) rand(0, 1),
                    ]);
                }
            }

            $settlementsPerGroup = rand(8, 20);
            $actors = collect();

            foreach ($memberUsers as $user) {
                $actors->push(['type' => 'user', 'id' => $user->id]);
            }
            foreach ($guests as $guest) {
                $actors->push(['type' => 'guest', 'id' => $guest->id]);
            }

            for ($s = 0; $s < $settlementsPerGroup; $s++) {
                if ($actors->count() < 2) {
                    break;
                }

                $from = $actors->random();
                do {
                    $to = $actors->random();
                } while ($to['type'] === $from['type'] && $to['id'] === $from['id']);

                Settlement::create([
                    'group_id' => $group->id,
                    'from_user_id' => $from['type'] === 'user' ? $from['id'] : null,
                    'from_guest_id' => $from['type'] === 'guest' ? $from['id'] : null,
                    'to_user_id' => $to['type'] === 'user' ? $to['id'] : null,
                    'to_guest_id' => $to['type'] === 'guest' ? $to['id'] : null,
                    'amount' => rand(10, 1200),
                ]);
            }
        }
    }
}
