<?php

namespace App\Livewire\Entry;

use App\Models\Bid;
use App\Models\Check;
use App\Models\Client;
use App\Models\Distribution;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Timesheet;
use App\Models\Vendor;
use App\Scopes\VendorScope;
use Illuminate\Http\Request;
use Livewire\Attributes\PublicProperty;
use Livewire\Component;

class VendorRegistration extends Component
{
    public Vendor $vendor;
    public $user;
    public $view;

    #[PublicProperty]
    public $registration;

    protected $listeners = ['refreshComponent' => '$refresh', 'confirmProcess'];

    public function mount(Request $request)
    {
        $this->view = $request->route()->getName();
        $this->user = auth()->user();
        
        // Initialize registration with default values
        $this->registration = $this->vendor->registration ?? new \stdClass();

        // Only include steps needed for the vendor type
        $registrationSteps = $this->vendor->business_type === '1099'
            ? ['vendor_info', 'registered']
            : ['vendor_info', 'team_members', 'emails_registered', 'banks_registered', 'registered'];

        foreach ($registrationSteps as $step) {
            if (!isset($this->registration->{$step})) {
                $this->registration->{$step} = false;
            }
        }

        // Rest of your mount logic
        if (in_array($this->vendor->business_type, ['Sub', 'DBA'])) {
            if ($this->vendor->distributions->isEmpty()) {
                //create OFFICE and admin user distributions
                Distribution::create([
                    'vendor_id' => $this->user->vendor->id,
                    'name' => 'OFFICE',
                    'user_id' => 0,
                ]);

                Distribution::create([
                    'vendor_id' => $this->vendor->id,
                    'name' => $this->user->first_name.' - Home',
                    'user_id' => $this->user->id,
                ]);
            }

            if ($this->vendor->company_emails()->exists() and !isset($this->registration->emails_registered)) {
                $this->confirmProcess('emails_registered');
            }

            if ($this->vendor->banks()->exists() and !isset($this->registration->banks_registered)) {
                $this->confirmProcess('banks_registered');
            }
        } 
    }

    public function confirmProcess($process_step)
    {
        // Using object property access for dynamic property
        $this->registration->{$process_step} = true;
        $this->vendor->registration = $this->registration;
        $this->vendor->save();
    }

    public function getStepStatus($stepName)
    {
        // If step is completed
        if (isset($this->registration->{$stepName}) && $this->registration->{$stepName}) {
            return 'completed';
        }
        
        // If step is current (previous step complete, this one not)
        $previousStep = $this->getPreviousStep($stepName);
        if ($previousStep === null || 
            (isset($this->registration->{$previousStep}) && $this->registration->{$previousStep})) {
            return 'current';
        }
        
        // Otherwise it's a future step
        return 'upcoming';
    }

    public function getPreviousStep($step)
    {
        $steps = [
            'vendor_info' => null,
            'team_members' => 'vendor_info',
            'emails_registered' => 'team_members',
            'banks_registered' => 'emails_registered',
            // For 1099, registered depends on vendor_info only; otherwise on banks_registered
            'registered' => $this->vendor->business_type === '1099' ? 'vendor_info' : 'banks_registered'
        ];
        
        return $steps[$step] ?? null;
    }

    // Add these methods to define the steps and rendering
    public function getRegistrationSteps(): array
    {
        // Always include vendor_info
        $steps = [
            [
                'name' => 'vendor_info',
                'label' => 'Confirm',
                'description' => $this->vendor->name . ', '. $this->vendor->business_type,
                'suffix' => 'Account',
                'icon' => 'briefcase',
            ],
        ];

        // Non-1099 vendors include more steps
        if ($this->vendor->business_type !== '1099') {
            $steps[] = [
                'name' => 'team_members',
                'label' => 'Add',
                'description' => 'Team Members',
                'suffix' => null,
                'icon' => 'user-plus',
            ];

            if (in_array($this->vendor->business_type, ['Sub', 'DBA'])) {
                $steps[] = [
                    'name' => 'emails_registered',
                    'label' => 'Add',
                    'description' => 'Receipt',
                    'suffix' => 'Accounts',
                    'icon' => 'envelope',
                ];
                
                $steps[] = [
                    'name' => 'banks_registered',
                    'label' => 'Add',
                    'description' => 'Transaction',
                    'suffix' => 'Accounts',
                    'icon' => 'credit-card',
                ];
            }
        }

        // Final step
        $steps[] = [
            'name' => 'registered',
            'label' => '',
            'description' => $this->vendor->name . ', '. $this->vendor->business_type,
            'suffix' => 'registration complete',
            'icon' => 'check-circle',
        ];
        
        return $steps;
    }

    /**
     * Get visibility state for a step section
     */
    public function isStepVisible(string $stepName): bool
    {
        if (!isset($this->registration->{$stepName})) {
            return false;
        }
        
        return (bool)$this->registration->{$stepName};
    }

    public function addVendorHiveInfo()
    {
        //5-19-2023 ... queue this in case someone EXITS, if job not done and user tries to come back, show the spinning/loading wheel upon login...
        ini_set('max_execution_time', '480000');
        //where vendor is registering initinally or going forward ($vendor->registration->registered = true)
        $vendor = $this->vendor;

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
        // dd($this);
        // $this->addVendorHiveInfo();

        //register vendor
        $this->confirmProcess('registered');

        $timesheets = Timesheet::withoutGlobalScopes()
            ->where('user_id', $this->user->id)
            ->where(function($query) {
                $query->whereNotNull('paid_by')
                      ->orWhereNotNull('check_id');
            })
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'DESC')
            ->get();

        // First group by vendor_id
        $vendorGroups = $timesheets->groupBy('vendor_id');

        // Then for each vendor group, group by check_id
        $nestedGroups = $vendorGroups->map(function ($vendorTimesheets) {
            return $vendorTimesheets->groupBy('check_id');
        });

        // Now loop through the nested structure
        foreach($nestedGroups as $vendor_id => $checkGroups) {
            // Get the actual vendor model
            $vendor = Vendor::withoutGlobalScopes()->find($vendor_id);

            // Skip if vendor doesn't exist
            if (!$vendor) continue;
            
            // Process vendor client logic
            if ($vendor->client) {
                $vendor_client = $vendor->client;
            } else {
                //create new and ONLY Client for $vendor
                //5-25-2025 incorporate VendorObserver | similar code
                //8-8-2025 need to sync vendor and client data including users/members in an observer?
                $vendor_client = new Client();
                $vendor_client->business_name = $vendor->business_name;
                $vendor_client->address = $vendor->address;
                $vendor_client->address_2 = $vendor->address_2;
                $vendor_client->city = $vendor->city;
                $vendor_client->state = $vendor->state;
                $vendor_client->zip_code = $vendor->zip_code;
                $vendor_client->vendor_id = $vendor->id;

                $vendor_client->save();
            
                // Attach the client to the authenticated user's vendor in the pivot table
                $vendor_client->vendors()->attach($this->user->vendor->id, ['source' => 'Vendor Client']);

                // Get all admin users from the vendor and attach them to the client
                $adminUsers = $vendor->users()
                    ->wherePivot('role_id', 1) // Admin role ID is 1
                    ->wherePivot('is_employed', true) // Only active employees
                    ->get();

                if ($adminUsers->isNotEmpty()) {
                    // Attach all admin users to this client
                    $vendor_client->users()->attach($adminUsers->pluck('id')->toArray());
                }
            }

            // Now loop through each check group for this vendor
            foreach ($checkGroups as $check_id => $timesheets) {     
                //create a Payment for $user->vendor based on $check and $timesheets?
                // Create a payment record for each timesheet in the check group
                $parent_payment_id = null;
                foreach ($timesheets as $index => $timesheet) {
                    // Check if the project is already attached to the user's vendor
                    $project = $timesheet->project()->withoutGlobalScopes()->first();

                    if (!$project) {
                        continue; // Skip if no project found
                    }

                    $project_vendor = $project->vendors()
                        ->where('vendors.id', $this->user->vendor->id)
                        ->exists();

                    // If the project is not attached to the vendor yet, attach it
                    if (!$project_vendor) {
                        $project->vendors()->attach(
                            $this->user->vendor->id,
                            ['client_id' => $vendor_client->id]
                        );
                        
                        // Create project status using status_code
                        $statusCode = \App\Support\ProjectStatusMap::codeFor('VIEW ONLY') ?? 11;
                        ProjectStatus::create([
                            'project_id' => $project->id,
                            'belongs_to_vendor_id' => $this->user->vendor->id,
                            'status_code' => $statusCode,
                            'start_date' => $project->created_at->format('Y-m-d'),
                        ]);
                    }
                    
                    // First payment has null parent_id, others reference the first payment's ID
                    $payment = Payment::create([
                        'amount' => $timesheet->amount,
                        'project_id' => $timesheet->project_id,
                        'date' => $timesheet->created_at->format('Y-m-d'),
                        // 'reference' => $check ? $check->check_number : 'Check #' . $check_id,
                        'belongs_to_vendor_id' => $this->user->vendor->id,
                        // 'note' => "Payment for timesheet ID: {$timesheet->id}",
                        'created_by_user_id' => 0,
                        'parent_client_payment_id' => $parent_payment_id,
                        'check_id' => !empty($check_id) ? $check_id : NULL
                    ]);
                    
                    // Set the first payment's ID as parent for all subsequent payments
                    if ($index == 0) {
                        $parent_payment_id = $payment->id;
                    }
                }
            }
        }
        
        return redirect(route('dashboard'));
    }

    public function render()
    {
        return view('livewire.entry.vendor-registration');
    }
}
