<?php

namespace App\Livewire\Users;

use App\Livewire\Clients\ClientCreate;
use App\Livewire\Clients\ClientsShow;
use App\Livewire\Dashboard\DashboardShow;
use App\Livewire\Forms\UserForm;
use App\Livewire\Vendors\VendorCreate;
use App\Livewire\Users\UserDetails;
use App\Livewire\Users\UsersIndex;

use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;

use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class UserCreate extends Component
{
    use AuthorizesRequests;

    public UserForm $form;

    public $view_text = [
        'card_title' => 'Create User',
        'button_text' => 'Add User',
        'form_submit' => 'save',
    ];

    public $model = ['type' => null, 'id' => null];
    public $user_cell = '';
    public $user_form = false;

    public $via_vendors = [];
    public $via_client = null;

    protected $listeners = ['refreshComponent' => '$refresh', 'newMember', 'editMember', 'editClientMember', 'removeMember', 'ViaVendorId'];

    public function rules()
    {
        return [
            'user_cell' => ['required', 'digits:10'],
        ];
    }

    public function updated($field, $value)
    {
        if ($field == 'user_cell') {
            // Don't reset when editing existing user or client member
            if ($this->model['type'] !== 'user' && $this->model['type'] !== 'client_member') {
                $this->form->reset();
                $this->user_form = false;
            }
        }

        if ($field == 'form.role') {
            $this->form->via_vendor = null;
            $this->form->hourly_rate = null;
        }
    }

    public function user_cell_find()
    {
        $this->validateOnly('user_cell');

        $user = User::where('cell_phone', $this->user_cell)->first();

        if ($user) {
            $this->form->setUser($user);

            //vendor team member error
            if ($this->model['type'] === 'vendor') {
                $this->via_vendors = $user->vendors()->where('business_type', '!=', 'Sub')->get();

                if ($this->model['id'] == 'NEW') {

                } else {
                    $vendor = Vendor::findOrFail($this->model['id']);

                    if ($vendor->users()->where('user_id', $user->id)->employed()->exists()) {
                        return $this->addError('user_exists_on_model', $user->first_name.' already belongs to Vendor.');
                    }
                }

                //client user error
            } elseif ($this->model['type'] === 'client') {
                if ($this->model['id'] == 'NEW') {

                } else {
                    $client = Client::findOrFail($this->model['id']);

                    if ($client->users()->where('user_id', $user->id)->exists()) {
                        return $this->addError('user_exists_on_model', $user->first_name.' already belongs to Client.');
                    }
                }
            } else {
                abort(404);
            }
        }

        $this->user_form = true;
        $this->resetErrorBag();
    }

    public function create_via_vendor()
    {
        //dispatch to VendorCreate with user, via_vendor (come back here after with via_vendor(id))
        $this->dispatch('viaVendor', user: $this->form->user, business_name: $this->form->user->full_name)->to(VendorCreate::class);
    }

    public function ViaVendorId($via_vendor_id)
    {
        $this->form->via_vendor = $via_vendor_id;
        $this->via_vendors = $this->form->user->vendors()->where('business_type', '!=', 'Sub')->get();
    }

    //new Vendor or Client member
    public function newMember($model, $model_id = null)
    {
        //creating NEW Vendor or Client or adding Team Member/Client User to existing Vendor or Client
        $this->model['type'] = $model;
        $this->model['id'] = $model_id;

        // 5-17-2023 ... this creates duplicates in the array of $this->model
        if ($model === 'client') {
            if ($this->model['id'] === 'NEW') {
                $this->view_text['card_title'] = 'Create Client';
                $this->view_text['button_text'] = 'Continue to Client';
            } else {
                $this->view_text['card_title'] = 'Add User to Client';
                $this->view_text['button_text'] = 'Add User';
            }
        } elseif ($model === 'vendor') {
            //if creating User for New Vendor dont show user_role or user_hourly
            if ($this->model['id'] === 'NEW') {
                $this->view_text['card_title'] = 'Add Owner to Vendor';
                $this->view_text['button_text'] = 'Add Owner';
            } else {
                $this->view_text['card_title'] = 'Add User to Vendor';
                $this->view_text['button_text'] = 'Add User';
            }
        }

        $this->modal('user_form_modal')->show();
    }

    public function editMember(User $user)
    {
        $this->user_cell = $user->cell_phone ?? '';
        $this->user_form = true;

        // //creating new Vendor or Client or adding Team Member/Client User to existing Vendor or Client
        $this->model['type'] = 'user';
        $this->model['id'] = $user->id;

        $this->form->setUser($user);

        $this->view_text['card_title'] = 'Edit User';
        $this->view_text['button_text'] = 'Update User';
        $this->view_text['form_submit'] = 'edit';

        $this->modal('user_form_modal')->show();
    }

    /**
     * Edit a client member's contact information (email/cell phone).
     * This is for client users editing other client members.
     */
    public function editClientMember(User $user)
    {
        $this->authorize('update_client_member', $user);

        $this->user_cell = $user->cell_phone ?? '';
        $this->user_form = true;

        $this->model['type'] = 'client_member';
        $this->model['id'] = $user->id;

        $this->form->setUser($user);

        $this->view_text['card_title'] = 'Edit Contact Info';
        $this->view_text['button_text'] = 'Update';
        $this->view_text['form_submit'] = 'editClientMemberSave';

        $this->modal('user_form_modal')->show();
    }

    /**
     * Save client member contact info changes (email/cell phone only).
     */
    public function editClientMemberSave()
    {
        $user = User::findOrFail($this->model['id']);
        $this->authorize('update_client_member', $user);

        // Form object handles validation via rules() and messages()
        $this->form->validate();

        $user->update([
            'email' => $this->form->email,
            'cell_phone' => $this->form->cell_phone,
        ]);

        $this->dispatch('refreshComponent')->to(UsersIndex::class);
        
        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Contact info updated.',
            text: '',
        );

        $this->modal('user_form_modal')->close();
    }

    public function removeMember(User $user)
    {
        $user->vendor->users()->wherePivot('is_employed', '1')
            ->updateExistingPivot($user->id, [
                'end_date' => today()->format('Y-m-d'),
                'is_employed' => 0,
                'updated_at' => now(),
            ]);

        $this->redirect(DashboardShow::class, navigate: true);
        
        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'User Removed.',
            // route / href / wire:click
            text: '',
        );
    }

    public function save_user_only()
    {
        $user = $this->form->store();
        $this->form->setUser($user);
    }

    public function save()
    {
        if (isset($this->form->user)) {
            $user = $this->form->user;
        } else {
            //create New User
            $user = $this->form->store();
        }

        //Vendor User
        if ($this->model['type'] === 'vendor') {
            // when creating new Vendor
            if ($this->model['id'] === 'NEW') {
                $user->hourly_rate = 0;
                $user->role = 1;

                //VendorCreate
                $this->dispatch('userVendor', $user->toArray());
            } else {
                // Check if this relationship already exists to prevent duplicates
                if (!$user->vendors()->where('vendor_id', $this->model['id'])->exists()) {
                    $user->vendors()->attach(
                        $this->model['id'], [
                            'role_id' => $this->form->role,
                            'hourly_rate' => $this->form->hourly_rate,
                            'start_date' => today()->format('Y-m-d'),
                            'via_vendor_id' => $this->form->via_vendor ?? null
                        ]
                    );

                    // $this->dispatch('confirmProcessStep', 'team_members')->to('entry.vendor-registration');
                    // $this->dispatch('refreshComponent')->to('users.users-index');
                    $this->dispatch('refreshComponent')->to(UsersIndex::class);

                    Flux::toast(
                        duration: 5000,
                        position: 'top right',
                        variant: 'success',
                        heading: 'User Added to Vendor.',
                        // route / href / wire:click
                        text: '',
                    );
                }
            }
            //Client User
            //if existing User .. dispatchTo ClientCreate with user (show existing users the User is part of) and close $this->modal.
        } elseif ($this->model['type'] === 'client') {
            // when creating new Client
            if ($this->model['id'] == 'NEW') {
                $this->dispatch('addUser', user: $user->id, client_id: $this->model['id'])->to(ClientCreate::class);
            } else {
                //add User to existing/this Client
                // Check if this relationship already exists to prevent duplicates
                if (!$user->clients()->where('client_id', $this->model['id'])->exists()) {
                    $user->clients()->attach($this->model['id']);
                    
                    // Sync to Nylas contacts
                    $client = \App\Models\Client::find($this->model['id']);
                    app(\App\Services\NylasContactSyncService::class)->syncUserContactsForClient($user, $client);
                    
                    $this->dispatch('refreshComponent')->to(UsersIndex::class);
                    $this->dispatch('refreshComponent')->to('clients.clients-show');
                    
                    Flux::toast(
                        duration: 5000,
                        position: 'top right',
                        variant: 'success',
                        heading: 'User Added to Client.',
                        text: '',
                    );
                }
            }
        }

        $this->modal('user_form_modal')->close();
    }

    public function edit()
    {
        $user = $this->form->update();

        $this->dispatch('refreshComponent')->to(UserDetails::class);
        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'User Edited.',
            // route / href / wire:click
            text: '',
        );

        $this->modal('user_form_modal')->close();
    }

    public function render()
    {
        return view('livewire.users.form');
    }
}
