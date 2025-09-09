<?php

namespace App\Livewire\Vendors;

use App\Livewire\Forms\VendorForm;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Expense;
use App\Models\Category;

use App\Services\GooglePlacesService;
use App\Traits\HandlesAddresses;

use Flux;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class VendorCreate extends Component
{
    use AuthorizesRequests, HandlesAddresses;

    public VendorForm $form;

    public $view_text = [
        'card_title' => 'Create Vendor',
        'button_text' => 'Create Vendor',
        'form_submit' => 'store',
    ];

    public Vendor $vendor;
    public User $user;
    public bool $has_user = false;

    public $business_name_text = null;
    public $vendor_add_type = null;
    public $via_vendor = null;
    public $vendor_id = null;
    public $existing_vendors = null;
    public $new_vendors_for_company = null;

    public $open_vendor_form = false;
    
    // For expenses tab
    public $expense_period = 'all'; // Possible values: 'month', 'quarter', 'year', 'all'

    protected $listeners =
        [
            'refreshComponent' => '$refresh',
            'userVendor',
            'addVendorToCompany',
            'newVendor',
            'editVendor',
            'viaVendor',
        ];

    protected $googlePlacesService;

    //so that protected $googlePlacesService can be initialized
    public function boot(GooglePlacesService $googlePlacesService)
    {
        $this->bootHandlesAddresses($googlePlacesService);
    }

    protected function rules()
    {
        return [
            'business_name_text' => 'nullable|min:3|max:255',
        ];
    }

    #[Computed]
    public function showUserSection(): bool
    {
        return in_array($this->form->business_type, ['Sub', 'DBA', '1099'], true);
    }

    #[Computed]
    public function showAddressSection(): bool
    {
        return (bool) $this->user && ($this->form->business_type !== 'Retail');
    }

    #[Computed]
    public function vendorExpensesByCategory()
    {
        // Only get expenses if we're editing an existing vendor
        if (!isset($this->form->vendor) || !$this->form->vendor->id) {
            return collect();
        }

        $expenses = Expense::query()
            ->where('vendor_id', $this->form->vendor->id)
            ->with('category')
            ->get();
        
        // Group expenses by primary category first
        $groupedByPrimary = $expenses->groupBy(function($expense) {
            return $expense->category ? $expense->category->friendly_primary : 'Uncategorized';
        });
        
        // Calculate totals and prepare data for display with subcategories
        $result = [];
        foreach ($groupedByPrimary as $primaryName => $primaryItems) {
            // Group by subcategory (friendly_detailed)
            $subcategoriesGrouped = $primaryItems->groupBy(function($expense) {
                return $expense->category ? $expense->category->friendly_detailed : 'Uncategorized';
            });
            
            $subcategoryData = [];
            foreach ($subcategoriesGrouped as $subcategoryName => $items) {
                $subcategoryData[] = [
                    'name' => $subcategoryName,
                    'total' => $items->sum('amount'),
                    'count' => $items->count(),
                    'expenses' => $items->sortByDesc('date')->values()
                ];
            }
            
            // Sort subcategories by total amount (highest first)
            usort($subcategoryData, function($a, $b) {
                return $b['total'] <=> $a['total'];
            });
            
            $result[$primaryName] = [
                'name' => $primaryName,
                'total' => $primaryItems->sum('amount'),
                'count' => $primaryItems->count(),
                'subcategories' => $subcategoryData
            ];
        }
        
        // Sort primary categories by total amount (highest first)
        return collect($result)->sortByDesc('total');
    }
    
    #[Computed]
    public function totalExpenses()
    {
        return $this->vendorExpensesByCategory()->sum('total');
    }

    public function updateExpensePeriod($period)
    {
        $this->expense_period = $period;
    }

    public $selectedCategoryId = null;

    public function editVendor(Vendor $vendor)
    {
        $this->form->setVendor($vendor);
    
        // Only set user for non-Retail vendors
        if ($vendor->business_type != 'Retail') {
            $this->user = $vendor->users()->first() ?? new User();
            $this->has_user = (bool) ($this->user->id ?? false);
        } else {
            $this->has_user = false;
        }
        
        $this->business_name_text = $vendor->business_name;
        $this->open_vendor_form = true;
        $this->vendor_add_type = $vendor->id;

        // Set the selected category to the vendor's current category
        $this->selectedCategoryId = $vendor->category_id;

        $this->view_text = [
            'card_title' => 'Update Vendor',
            'button_text' => 'Update',
            'form_submit' => 'edit',
        ];

        $this->modal('vendors_form_modal')->show();
    }

    public function updatedBusinessNameText($value)
    {
        // Always validate (nullable allows empty values)
        $this->validateOnly('business_name_text');

        $existing_vendor_ids = auth()->user()->vendor->vendors->pluck('id')->toArray();

        $this->existing_vendors =
            Vendor::withoutGlobalScopes()
                ->orderBy('business_name', 'ASC')
                ->where('business_name', 'like', "%{$value}%")
                ->whereIn('id', $existing_vendor_ids)
                ->distinct()
                ->get();

        $this->new_vendors_for_company =
            Vendor::withoutGlobalScopes()
                ->orderBy('business_name', 'ASC')
                ->where('business_name', 'like', "%{$value}%")
                ->whereNotIn('id', $existing_vendor_ids)
                ->distinct()
                ->get();

        $this->form->reset();
        $this->form->business_name = $value;
        $this->open_vendor_form = false;
    }

    public function viaVendor(User $user, $business_name)
    {
        $this->user = $user;
        $this->has_user = true;
        $this->form->business_name = $business_name;
        $this->business_name_text = $business_name;
        $this->form->business_type = '1099';
        $this->form->user_hourly_rate = 0;
        $this->form->user_role = 1;

        $this->via_vendor = true;
        $this->open_vendor_form = true;

        $this->modal('vendors_form_modal')->show();
    }

    public function newVendor()
    {
        $this->vendor_add_type = 'NEW';
        $this->modal('vendors_form_modal')->show();
    }

    public function openVendorForm()
    {
        $this->open_vendor_form = true;
    }

    //add Existing Vendor to auth->user->vendor (Company)
    //used to be addVendorToVendor
    public function addVendorToCompany($vendor_id)
    {
        //add $vendor to currently logged in vendor (company)
        auth()->user()->vendor->vendors()->attach($vendor_id);

        $this->redirectRoute('vendors.show', ['vendor' => $vendor_id]);
        // $this->dispatch('refreshComponent')->to('vendors.vendors-index');
        // $this->modal('vendors_form_modal')->close();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Vendor Added.',
            // route / href / wire:click
            // route: 'vendors/'.$vendor_id,
            text: '',
        );
    }

    //when Creating NEW Vendor
    public function userVendor($user_info)
    {
        $this->user = User::findOrFail($user_info['id']);
        $this->has_user = true;
        $this->form->user_hourly_rate = $user_info['hourly_rate'];
        $this->form->user_role = $user_info['role'];
    }

    public function edit()
    {
        $vendor = $this->form->update();

        $this->modal('vendors_form_modal')->close();
        $this->dispatch('refreshComponent')->to('vendors.vendor-details');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Updated',
            // route / href / wire:click
            text: $vendor->name,
        );
    }

    public function store()
    {
        if (isset($this->vendor->id)) {
            //attach vendor to auth->user->vendor (logged in/working vendor/Company)
            $vendor = $this->vendor;
            auth()->user()->vendor->vendors()->attach($vendor);
        //NEW VENDOR
        } else {
            $vendor = $this->form->store();

            //Add existing Vendor to the logged-in-vendor/company || add $vendor to currently logged in vendor
            auth()->user()->vendor->vendors()->attach($vendor->id);

            if ($vendor->business_type != 'Retail') {
                $user = $this->user;

                // attach to new $vendor with role_id of 1/admin (default on Model)
                $user->vendors()->attach(
                    $vendor->id, [
                        'role_id' => $this->form->user_role, //default on Model table
                        'hourly_rate' => $this->form->user_hourly_rate,
                        'start_date' => today()->format('Y-m-d'),
                    ]
                );
            }
        }

        if ($this->via_vendor) {
            //dispatch back to UserCreate
            $this->dispatch('ViaVendorId', via_vendor_id: $vendor->id)->to('users.user-create');
        }

        // Notify VendorsIndex to prepend a highlighted row for this new vendor (session-only)
        $this->dispatch('VendorCreated', vendor: [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'business_type' => $vendor->business_type,
            'ytd_expense_sum' => (float) ($vendor->ytd_expense_sum ?? 0),
        ])->to('vendors.vendors-index');

        //reset component
        $this->modal('vendors_form_modal')->close();
        $this->dispatch('refreshComponent')->self();
        $this->form->reset();


        // route: 'vendors/' . $vendor->id
        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Vendor Added.',
            // route / href / wire:click
            text: $vendor->name . ' was created.',
        );
    }

    #[Computed]
    public function availableCategories()
    {
        return Category::orderBy('friendly_primary')
            ->orderBy('friendly_detailed')
            ->get();
    }
    
    public function updateVendorCategory()
    {
        // Make sure we have a vendor
        if (!isset($this->form->vendor) || !$this->form->vendor->id) {
            return;
        }
        
        $vendor = $this->form->vendor;
        
        // Update the vendor's category
        $vendor->update([
            'category_id' => $this->selectedCategoryId
        ]);
        
        // Update all expenses for this vendor
        $expenseCount = Expense::where('vendor_id', $vendor->id)
            ->update(['category_id' => $this->selectedCategoryId]);
        
        // Show a success message
        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Category Updated',
            text: "Updated category for {$vendor->name} and {$expenseCount} expenses.",
        );
        
        // Refresh the component to show updated data
        $this->dispatch('refreshComponent')->self();
    }

    public function render()
    {
        return view('livewire.vendors.form');
    }
}
