<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseSplits;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'first_name' => 'Patryk',
            'last_name' => 'Szady',
            'cell_phone' => '2249993880',
            'email' => 'patryk.szady@live.com',
        ]);
        User::factory(19)->create();
        // ->withoutGlobalScopes()

        Client::factory(12)->hasProjects(3)->has(User::factory()->count(2))->create();
        // Project::factory(21)->create();

        Vendor::factory()->create([
            'business_name' => 'GS Construction',
            'address' => '400 N Wheeling Rd',
            'city' => 'Prospect Heights',
            'state' => 'IL',
            'zip_code' => '60070',
            'business_type' => 'Sub',
        ]);

        $vendors = Vendor::factory(49)->create();

        foreach ($vendors as $vendor) {
            Vendor::withoutGlobalScopes()->find(1)->vendors()->attach($vendor->id);
        }

        $vendors = Vendor::factory(50)->create();

        // Bank::factory(2)->create();

        DB::table('distributions')->insert(
            [
                'name' => 'OFFICE',
                'vendor_id' => 1,
                'user_id' => null,
            ]
        );

        DB::table('distributions')->insert(
            [
                'name' => 'Patryk - Home',
                'vendor_id' => 1,
                'user_id' => 1,
            ]
        );

        DB::table('company_emails')->insert(
            [
                'vendor_id' => 1,
                'email' => 'patryk.szady@live.com',
            ]
        );

        DB::table('company_emails')->insert(
            [
                'vendor_id' => 1,
                'email' => 'patryk@gs.construction',
            ]
        );

        //pivot of user_vendor table
        DB::table('user_vendor')->insert(
            [
                'user_id' => 1,
                'vendor_id' => 1,
                'role_id' => 1,
                'is_employed' => 1,
                'created_at' => now(),
                'updated_at' => now(),
                'hourly_rate' => 20,
            ]
        );

        DB::table('user_vendor')->insert(
            [
                'user_id' => 1,
                'vendor_id' => 15,
                'role_id' => 1,
                'is_employed' => 1,
                'created_at' => now(),
                'updated_at' => now(),
                'hourly_rate' => 25,
            ]
        );

        //associate one of the projects just created
        Expense::factory(140)->create();

        //each ExpenseSPlits needs to be doubled.
        Expense::factory(10)->hasSplits(3)->create();
    }
}
