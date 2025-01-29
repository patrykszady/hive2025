<?php

namespace App\Livewire\Entry;

use App\Models\Bid;
use App\Models\Check;
use App\Models\Client;
use App\Models\Distribution;
use App\Models\Project;
use App\Models\Scopes\VendorScope;
use App\Models\Vendor;
use Livewire\Component;

class VendorRegistration extends Component
{
    public Vendor $vendor;

    public $user;

    public $vendor_users;

    public $vendor_add_type;

    public $registration;

    protected $listeners = ['refreshComponent' => '$refresh', 'confirmProcessStep'];

    public function mount()
    {
        $this->user = auth()->user();
        $this->vendor_add_type = $this->user->vendor->id;
        $this->vendor_users = $this->user->vendor->users()->where('is_employed', 1)->get();
        $this->registration = $this->user->vendor->registration;

        //06-21-2024 gate or scope? This shouldnt be here...
        if (is_null($this->user->vendor)) {
            return redirect(route('vendor_selection'));
        }

        if ($this->vendor->id != $this->user->vendor->id or $this->registration['registered']) {
            return redirect(route('vendor_selection'));
        }

        if (in_array($this->vendor->business_type, ['Sub', 'DBA'])) {
            if ($this->user->vendor->distributions->isEmpty()) {
                //create OFFICE and admin user distributions
                Distribution::create([
                    'vendor_id' => $this->user->vendor->id,
                    'name' => 'OFFICE',
                    'user_id' => 0,
                ]);

                Distribution::create([
                    'vendor_id' => $this->user->vendor->id,
                    'name' => $this->user->first_name.' - Home',
                    'user_id' => $this->user->id,
                ]);
            }

            if ($this->user->vendor->company_emails()->exists() and $this->registration['emails_registered'] == false) {
                $this->confirmProcess('emails_registered');
            }

            if ($this->user->vendor->banks()->exists() and $this->registration['banks_registered'] == false) {
                $this->confirmProcess('banks_registered');
            }
        } elseif ($this->vendor->business_type == '1099') {
            $this->confirmProcess('team_members');
            $this->confirmProcess('emails_registered');
            $this->confirmProcess('banks_registered');
        }
    }

    public function confirmProcess($process_step)
    {
        $this->registration[$process_step] = true;
        $this->user->vendor->registration = json_encode($this->registration);
        $this->user->vendor->save();
    }

    public function confirmProcessStep($process_step)
    {
        $this->confirmProcess($process_step);

        if ($process_step === 'vendor_info') {
            $this->dispatch('refresh')->to('vendors.vendor-details');
        } elseif ($process_step === 'team_members') {
            $this->dispatch('refresh')->to('users.users-index');
        }
    }

    public function addVendorHiveInfo()
    {
        // dd('TOO FAR');
        //5-19-2023 ... queue this in case someone EXITS, if job not done and user tries to come back, show the spinning/loading wheel upon login...
        ini_set('max_execution_time', '480000');
        //where vendor is registering initinally or going forward ($vendor->registration->registered = true)
        $vendor = $this->user->vendor;
        $vendor_users_ids = $vendor->users->pluck('id')->toArray();
        $vendor_id = $vendor->id;

        //3-21-2023 this should be one query? $projects_query
        //5-24-2023 .. what about Expense Splits?
        $projects_query_expenses =
            Project::withoutGlobalScopes()
                ->withWhereHas('expenses', function ($query) use ($vendor_id) {
                    $query->withoutGlobalScopes()->where('vendor_id', $vendor_id);
                })->get();

        $projects_query_timesheets =
            Project::withoutGlobalScopes()
                ->withWhereHas('timesheets', function ($query) use ($vendor_users_ids) {
                    $query->withoutGlobalScopes()->whereIn('user_id', $vendor_users_ids);
                })->get();

        // $projects_query =
        //     Project::withoutGlobalScopes()
        //         ->with('timesheets', function ($query) use ($vendor_users_ids) {
        //             $query->withoutGlobalScopes()->whereIn('user_id', $vendor_users_ids)->whereHas('project');
        //         })
        //         ->with('expenses', function ($query) use ($vendor) {
        //             $query->withoutGlobalScopes()->where('vendor_id', $vendor->id)->whereHas('project');
        //         });

        //$projects = $projects_query->get();
        $projects = $projects_query_expenses->merge($projects_query_timesheets);

        //group $projects_query by 'belongs_to_vendor_id',
        $belongs_to_vendors_ids = array_keys($projects->groupBy('belongs_to_vendor_id')->toArray());

        foreach ($belongs_to_vendors_ids as $belongs_to_vendor_id) {
            //find vendor_id on clients table
            $client = Client::withoutGlobalScopes()->where('vendor_id', $belongs_to_vendor_id)->first();

            //if vendor doesn't have a client
            //When created we need to create a Client associated with this vendor_id
            //5-25-2025 incorporate VendorObserver | similar code
            if (is_null($client)) {
                //create client from $this->vendor
                $adding_vendor = Vendor::withoutGlobalScope(VendorScope::class)->findOrFail($belongs_to_vendor_id);
                // dd($adding_vendor);
                $client = Client::make();
                $client->business_name = $adding_vendor->business_name;
                $client->address = $adding_vendor->address;
                $client->address_2 = $adding_vendor->address_2;
                $client->city = $adding_vendor->city;
                $client->state = $adding_vendor->state;
                $client->zip_code = $adding_vendor->zip_code;
                $client->home_phone = $adding_vendor->business_phone;
                //attach
                $client->vendor_id = $adding_vendor->id;

                $client->save();
            }

            //attach $vendor->id to this $client (which is linked to a vendor_id / the one we're associating expenses / payments to below)
            $client->vendors()->attach($vendor->id);
        }

        foreach ($projects as $project) {
            if ($project->belongs_to_vendor_id != $vendor->id) {
                $vendor_id = $vendor->id;
                $client_id = $client->id;
            } else {
                $vendor_id = $project->belongs_to_vendor_id;
                $client_id = $project->client_id;
            }

            $project->vendors()->attach($vendor_id, ['client_id' => $client_id]);
            app(\App\Http\Controllers\VendorRegisteredController::class)
                ->add_project_status(
                    $project->id,
                    $vendor_id,
                    'VIEW ONLY'
                );
        }

        //PAYMENTS
        $checks = Check::withoutGlobalScopes()
            ->where('vendor_id', $vendor->id)
            ->with('expenses', function ($query) {
                $query->withoutGlobalScopes();
            })
            ->with('timesheets', function ($query) {
                $query->withoutGlobalScopes();
            })->get();

        foreach ($checks as $check) {
            //check->expenses
            app(\App\Http\Controllers\VendorRegisteredController::class)
                ->create_payment_from_check(
                    $check,
                    $check->expenses,
                    $vendor
                );

            //check->timesheets
            app(\App\Http\Controllers\VendorRegisteredController::class)
                ->create_payment_from_check(
                    $check,
                    $check->timesheets,
                    $vendor
                );
        }

        //BIDS
        $projects = Project::all();

        foreach ($projects as $project) {
            //if payments MORE than bids
            if ($project->finances['payments'] > $project->finances['total_bid']) {
                $amount_difference = $project->finances['payments'] - $project->finances['total_bid'];

                //if project has NO Bids... bid type = 1, if more: bid type = 2
                if (! $project->bids()->exists()) {
                    $bid_type = 1;
                } else {
                    $bid_type = 2;
                }

                //create vendor/project bid
                Bid::create([
                    'amount' => $amount_difference,
                    'type' => $bid_type,
                    'project_id' => $project->id,
                    'vendor_id' => $vendor_id,
                ]);
            }
        }

    }

    public function store()
    {
        $this->addVendorHiveInfo();

        //register vendor with user
        $this->user->vendor->registration = '{"registered": true}';
        $this->user->vendor->save();

        return redirect(route('dashboard'));
    }

    public function render()
    {
        return view('livewire.entry.vendor-registration');
    }
}
