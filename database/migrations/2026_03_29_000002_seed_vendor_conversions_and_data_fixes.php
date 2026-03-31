<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $now = now();

            // ──────────────────────────────────────────────────
            // 1. J Peterson Design — link client 143 to vendor 60
            // ──────────────────────────────────────────────────

            DB::table('clients')->where('id', 143)->update(['vendor_id' => 60]);

            // Create Peter Garvey user & homeowner client for project 179
            $garveyUserId = DB::table('users')->insertGetId([
                'first_name' => 'Peter',
                'last_name' => 'Garvey',
                'email' => 'pgarvy@lifespice.com',
                'cell_phone' => '3125436250',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $garveyClientId = DB::table('clients')->insertGetId([
                'address' => '2030 N Sedgwick',
                'address_2' => 'Unit O',
                'city' => 'Chicago',
                'state' => 'IL',
                'zip_code' => 60614,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('client_user')->insert([
                'client_id' => $garveyClientId,
                'user_id' => $garveyUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('client_vendor')->insert([
                'client_id' => $garveyClientId,
                'vendor_id' => 60,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('projects')->where('id', 179)->update(['client_id' => $garveyClientId]);

            DB::table('project_vendor')->insertOrIgnore([
                'project_id' => 179,
                'vendor_id' => 60,
                'client_id' => $garveyClientId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Fix check 328: soft-delete dummy expense, correct amounts
            DB::table('expenses')->where('id', 1754)->whereNull('deleted_at')->update(['deleted_at' => $now]);
            DB::table('expenses')->where('id', 2199)->update(['amount' => 3060]);
            DB::table('payments')->where('id', 3736)->delete();
            DB::table('payments')->where('id', 3731)->update(['amount' => 3060, 'parent_client_payment_id' => null]);

            // ──────────────────────────────────────────────────
            // 2. Dream Kitchens — create vendor and homeowners
            // ──────────────────────────────────────────────────

            $dkVendorId = DB::table('vendors')->insertGetId([
                'business_name' => 'Dream Kitchens',
                'business_type' => 'Sub',
                'address' => '806 Central Ave',
                'address_2' => '101',
                'city' => 'Highland Park',
                'state' => 'IL',
                'zip_code' => 60035,
                'business_phone' => '8474332400',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('clients')->where('id', 2)->update([
                'business_name' => 'Dream Kitchens',
                'vendor_id' => $dkVendorId,
            ]);

            DB::table('client_vendor')->insert([
                'client_id' => 2,
                'vendor_id' => $dkVendorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // DK team: Rick (9), Karen (11), Melinda (67)
            foreach ([9, 11, 67] as $dkUserId) {
                DB::table('user_vendor')->insert([
                    'user_id' => $dkUserId,
                    'vendor_id' => $dkVendorId,
                    'role_id' => 1,
                    'is_employed' => 1,
                    'hourly_rate' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('users')->where('id', $dkUserId)->update([
                    'primary_vendor_id' => $dkVendorId,
                ]);
            }

            $dkHomeowners = [
                [
                    'projects' => [3],
                    'address' => '326 Roger Williams Ave', 'address_2' => null,
                    'city' => 'Highland Park', 'state' => 'IL', 'zip_code' => 60035,
                    'users' => [
                        ['first_name' => 'Steve', 'last_name' => 'Pemberton', 'email' => 'steve_pemberton@client2.com', 'cell_phone' => '8477804950'],
                    ],
                ],
                [
                    'projects' => [7],
                    'address' => '486 Calvin Court', 'address_2' => null,
                    'city' => 'Gurnee', 'state' => 'IL', 'zip_code' => 60031,
                    'users' => [
                        ['first_name' => 'Joseph', 'last_name' => 'Mirretti', 'email' => 'mirritaly@aol.com', 'cell_phone' => '8473703775'],
                    ],
                ],
                [
                    'projects' => [19, 36],
                    'address' => '527 Cherry St', 'address_2' => null,
                    'city' => 'Winnetka', 'state' => 'IL', 'zip_code' => 60093,
                    'users' => [
                        ['first_name' => 'Mark', 'last_name' => 'Berlind', 'email' => 'markberlind@comcast.net', 'cell_phone' => '3128139768'],
                        ['first_name' => 'Rene', 'last_name' => 'Berlind', 'email' => 'reneberlind@comcast.net', 'cell_phone' => '8474006923'],
                    ],
                ],
                [
                    'projects' => [20],
                    'existing_client_id' => 226,
                    'users' => [],
                ],
                [
                    'projects' => [24],
                    'address' => '1740 Wildrose Ct', 'address_2' => null,
                    'city' => 'Highland Park', 'state' => 'IL', 'zip_code' => 60035,
                    'users' => [
                        ['first_name' => 'Joyce', 'last_name' => 'Persky', 'email' => 'joyce_persky@client2.com', 'cell_phone' => '8479269820'],
                        ['first_name' => 'Alan', 'last_name' => 'Miller', 'email' => 'alan_miller@client2.com', 'cell_phone' => '0002000005'],
                    ],
                ],
                [
                    'projects' => [43],
                    'address' => '2200 Tennyson Ln', 'address_2' => null,
                    'city' => 'Highland Park', 'state' => 'IL', 'zip_code' => 60035,
                    'users' => [
                        ['first_name' => 'Rhonda', 'last_name' => 'Cohen', 'email' => 'rhonda_cohen@client2.com', 'cell_phone' => '8478312640'],
                    ],
                ],
                [
                    'projects' => [53],
                    'address' => '4735 Wellington Drive', 'address_2' => null,
                    'city' => 'Long Grove', 'state' => 'IL', 'zip_code' => 60047,
                    'users' => [
                        ['first_name' => 'Pamela', 'last_name' => 'Goris', 'email' => 'protectlife1@aol.com', 'cell_phone' => '4146907917'],
                    ],
                ],
                [
                    'projects' => [57],
                    'address' => '9045 Karlov Ave', 'address_2' => null,
                    'city' => 'Skokie', 'state' => 'IL', 'zip_code' => 60076,
                    'users' => [
                        ['first_name' => 'Raphaela', 'last_name' => 'Stern', 'email' => 'raphaelastern@me.com', 'cell_phone' => '8472874660'],
                    ],
                ],
                [
                    'projects' => [60],
                    'address' => '940 Augusta Way', 'address_2' => 'Unit 113',
                    'city' => 'Highland Park', 'state' => 'IL', 'zip_code' => 60061,
                    'users' => [
                        ['first_name' => 'Gail', 'last_name' => 'Heckmyer', 'email' => 'heckmyer@sbcglobal.net', 'cell_phone' => '3125192262'],
                    ],
                ],
                [
                    'projects' => [68],
                    'address' => '1745 E Summit Ct', 'address_2' => null,
                    'city' => 'Deerfield', 'state' => 'IL', 'zip_code' => 60015,
                    'users' => [
                        ['first_name' => 'Susan', 'last_name' => 'Fried', 'email' => 'susan1745@gmail.com', 'cell_phone' => '8472045181'],
                    ],
                ],
                [
                    'projects' => [70],
                    'address' => '1660 First St', 'address_2' => 'Unit 301',
                    'city' => 'Highland Park', 'state' => 'IL', 'zip_code' => 60035,
                    'users' => [
                        ['first_name' => 'Barbara', 'last_name' => 'Richardson', 'email' => 'needlepointer711@gmail.com', 'cell_phone' => '0002000001'],
                    ],
                ],
                [
                    'projects' => [80],
                    'address' => '31 Williamsburg Ln', 'address_2' => null,
                    'city' => 'Skokie', 'state' => 'IL', 'zip_code' => 60203,
                    'users' => [
                        ['first_name' => 'Amy', 'last_name' => 'Kaissar', 'email' => 'ajkaissar@yahoo.com', 'cell_phone' => '8479339112'],
                    ],
                ],
                [
                    'projects' => [81],
                    'address' => '1021 Dover Ct', 'address_2' => null,
                    'city' => 'Libertyville', 'state' => 'IL', 'zip_code' => 60048,
                    'users' => [
                        ['first_name' => 'Jan', 'last_name' => 'Nichols', 'email' => 'jannichol11@gmail.com', 'cell_phone' => '0002000002'],
                    ],
                ],
                [
                    'projects' => [84],
                    'address' => '1683 Violet Ct', 'address_2' => null,
                    'city' => 'Highland Park', 'state' => 'IL', 'zip_code' => 60035,
                    'users' => [
                        ['first_name' => 'Barbara', 'last_name' => 'Hillebrand', 'email' => 'bhillebrand001@gmail.com', 'cell_phone' => '0002000003'],
                        ['first_name' => 'Peter', 'last_name' => 'Hillebrand', 'email' => 'peter_hillebrand@client2.com', 'cell_phone' => '0002000004'],
                    ],
                ],
                [
                    'projects' => [92],
                    'address' => '1064 Fraz Dr', 'address_2' => null,
                    'city' => 'Lake Forest', 'state' => 'IL', 'zip_code' => 60045,
                    'users' => [
                        ['first_name' => 'Nick', 'last_name' => 'Gianopulos', 'email' => 'nick_gianopulos@client2.com', 'cell_phone' => '8472099916'],
                    ],
                ],
                [
                    'projects' => [93],
                    'address' => '17 Sommerset Lane', 'address_2' => null,
                    'city' => 'Lincolnshire', 'state' => 'IL', 'zip_code' => 60069,
                    'users' => [
                        ['first_name' => 'Mort', 'last_name' => 'Zelman', 'email' => 'morts.emails@gmail.com', 'cell_phone' => '8476079593'],
                    ],
                ],
                [
                    'projects' => [96],
                    'address' => '803 Sheridan Rd', 'address_2' => null,
                    'city' => 'Highland Park', 'state' => 'IL', 'zip_code' => 60035,
                    'users' => [
                        ['first_name' => 'Hal', 'last_name' => 'Sider', 'email' => 'hsider@compasslexecon.com', 'cell_phone' => '8477803330'],
                    ],
                ],
                [
                    'projects' => [99],
                    'address' => '198 Janes Loop', 'address_2' => null,
                    'city' => 'Highwood', 'state' => 'IL', 'zip_code' => 60040,
                    'users' => [
                        ['first_name' => 'Diane', 'last_name' => 'Allen', 'email' => 'diane_allen@client2.com', 'cell_phone' => '8472177756'],
                    ],
                ],
                [
                    'projects' => [112],
                    'address' => '975 Coventry Ln', 'address_2' => null,
                    'city' => 'Highland Park', 'state' => 'IL', 'zip_code' => 60035,
                    'users' => [
                        ['first_name' => 'Wallace', 'last_name' => 'Dunn', 'email' => 'wjdunn43@comcast.net', 'cell_phone' => '8479971831'],
                    ],
                ],
                [
                    'projects' => [123],
                    'address' => '3129 Valcour Dr', 'address_2' => null,
                    'city' => 'Glenview', 'state' => 'IL', 'zip_code' => 60026,
                    'users' => [
                        ['first_name' => 'David', 'last_name' => 'Levee', 'email' => 'davidelevee@gmail.com', 'cell_phone' => '8478309503'],
                        ['first_name' => 'Carin', 'last_name' => 'Levee', 'email' => 'carinlevee@gmail.com', 'cell_phone' => '8475024313'],
                    ],
                ],
                [
                    'projects' => [124],
                    'address' => '373 Elder Ln', 'address_2' => null,
                    'city' => 'Winnetka', 'state' => 'IL', 'zip_code' => 60093,
                    'users' => [
                        ['first_name' => 'Chris', 'last_name' => 'Tapp', 'email' => 'chrismtapp@gmail.com', 'cell_phone' => '0002000006'],
                        ['first_name' => 'Marni', 'last_name' => 'Tapp', 'email' => 'marnitapp@gmail.com', 'cell_phone' => '0002000007'],
                    ],
                ],
                [
                    'projects' => [129],
                    'address' => '1649 St Johns Ave', 'address_2' => null,
                    'city' => 'Highland Park', 'state' => 'IL', 'zip_code' => 60035,
                    'users' => [
                        ['first_name' => 'Jackie', 'last_name' => 'Melinger', 'email' => 'jackiemelinger@gmail.com', 'cell_phone' => '8473619798'],
                    ],
                ],
            ];

            // Helper: create homeowner client, users, and link to vendor
            $createHomeowner = function (array $homeowner, int $vendorId) use ($now) {
                if (isset($homeowner['existing_client_id'])) {
                    $clientId = $homeowner['existing_client_id'];
                } else {
                    $clientId = DB::table('clients')->insertGetId([
                        'address' => $homeowner['address'],
                        'address_2' => $homeowner['address_2'],
                        'city' => $homeowner['city'],
                        'state' => $homeowner['state'],
                        'zip_code' => $homeowner['zip_code'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    foreach ($homeowner['users'] as $userData) {
                        $userId = DB::table('users')->insertGetId([
                            'first_name' => $userData['first_name'],
                            'last_name' => $userData['last_name'],
                            'email' => $userData['email'],
                            'cell_phone' => $userData['cell_phone'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        DB::table('client_user')->insert([
                            'client_id' => $clientId,
                            'user_id' => $userId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                DB::table('client_vendor')->insert([
                    'client_id' => $clientId,
                    'vendor_id' => $vendorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($homeowner['projects'] as $projectId) {
                    DB::table('projects')->where('id', $projectId)->update(['client_id' => $clientId]);

                    DB::table('project_vendor')->insertOrIgnore([
                        'project_id' => $projectId,
                        'vendor_id' => $vendorId,
                        'client_id' => $clientId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return $clientId;
            };

            foreach ($dkHomeowners as $homeowner) {
                $createHomeowner($homeowner, $dkVendorId);
            }

            // DK self-projects (DK is the client who hired GS)
            foreach ([35, 108, 111] as $selfProjectId) {
                DB::table('project_vendor')->insertOrIgnore([
                    'project_id' => $selfProjectId,
                    'vendor_id' => $dkVendorId,
                    'client_id' => 2,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // ──────────────────────────────────────────────────
            // 3. Denori Design — create vendor and homeowner
            // ──────────────────────────────────────────────────

            $ddVendorId = DB::table('vendors')->insertGetId([
                'business_name' => 'Denori Design',
                'business_type' => 'Sub',
                'address' => '1117 N Douglas Ave',
                'city' => 'Arlington Heights',
                'state' => 'IL',
                'zip_code' => '60004',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('clients')->where('id', 5)->update(['vendor_id' => $ddVendorId]);

            DB::table('client_vendor')->insert([
                'client_id' => 5,
                'vendor_id' => $ddVendorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Denise DiVarco (user 15) → Denori Design vendor
            DB::table('user_vendor')->insert([
                'user_id' => 15,
                'vendor_id' => $ddVendorId,
                'role_id' => 1,
                'is_employed' => 1,
                'hourly_rate' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('users')->where('id', 15)->update(['primary_vendor_id' => $ddVendorId]);

            // Ann Marie Mathews homeowner for project 6
            $createHomeowner([
                'projects' => [6],
                'address' => '411 S We Go Trail',
                'address_2' => null,
                'city' => 'Mount Prospect',
                'state' => 'IL',
                'zip_code' => '60056',
                'users' => [
                    ['first_name' => 'Ann Marie', 'last_name' => 'Mathews', 'email' => 'ann_marie_mathews@client5.com', 'cell_phone' => '0005000001'],
                ],
            ], $ddVendorId);

            // ──────────────────────────────────────────────────
            // 4. Merge duplicate checks for DK registered vendor 854
            // ──────────────────────────────────────────────────

            $duplicates = DB::table('checks')
                ->select(
                    'check_number',
                    DB::raw('MIN(id) as keep_id'),
                    DB::raw('SUM(amount) as total_amount'),
                )
                ->where('belongs_to_vendor_id', 854)
                ->where('check_type', 'Check')
                ->whereNotNull('check_number')
                ->whereNull('deleted_at')
                ->groupBy('check_number')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicates as $dup) {
                DB::table('checks')->where('id', $dup->keep_id)->update(['amount' => $dup->total_amount]);

                $removeIds = DB::table('checks')
                    ->where('belongs_to_vendor_id', 854)
                    ->where('check_number', $dup->check_number)
                    ->where('check_type', 'Check')
                    ->whereNull('deleted_at')
                    ->where('id', '!=', $dup->keep_id)
                    ->pluck('id')
                    ->toArray();

                DB::table('expenses')->whereIn('check_id', $removeIds)->update(['check_id' => $dup->keep_id]);
                DB::table('transactions')->whereIn('check_id', $removeIds)->update(['check_id' => $dup->keep_id]);
                DB::table('checks')->whereIn('id', $removeIds)->update(['deleted_at' => $now]);
            }

            // ──────────────────────────────────────────────────
            // 5. Backfill missing DK expenses from null-reference payments
            // ──────────────────────────────────────────────────

            $dkProjectIds = DB::table('project_vendor')
                ->where('vendor_id', 854)
                ->pluck('project_id')
                ->toArray();

            $payments = DB::table('payments')
                ->whereIn('project_id', $dkProjectIds)
                ->whereNull('reference')
                ->get();

            foreach ($payments as $payment) {
                $ownerVendorId = $payment->belongs_to_vendor_id;

                $exists = DB::table('expenses')
                    ->where('project_id', $payment->project_id)
                    ->where('belongs_to_vendor_id', 854)
                    ->where('vendor_id', $ownerVendorId)
                    ->where('amount', $payment->amount)
                    ->where('date', $payment->date)
                    ->whereNull('invoice')
                    ->whereNull('deleted_at')
                    ->exists();

                if ($exists) {
                    continue;
                }

                $checkId = DB::table('checks')->insertGetId([
                    'check_type' => 'Other',
                    'check_number' => null,
                    'date' => $payment->date,
                    'amount' => $payment->amount,
                    'vendor_id' => $ownerVendorId,
                    'belongs_to_vendor_id' => 854,
                    'created_by_user_id' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('expenses')->insert([
                    'date' => $payment->date,
                    'amount' => $payment->amount,
                    'project_id' => $payment->project_id,
                    'vendor_id' => $ownerVendorId,
                    'invoice' => null,
                    'check_id' => $checkId,
                    'belongs_to_vendor_id' => 854,
                    'created_by_user_id' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }); // end transaction
    }

    public function down(): void
    {
        // Data migration — not fully reversible
    }
};
