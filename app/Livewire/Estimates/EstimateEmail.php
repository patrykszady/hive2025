<?php

namespace App\Livewire\Estimates;

use App\Jobs\SendEstimateEmailJob;
use App\Models\CompanyEmail;
use App\Models\Estimate;
use App\Support\EstimateEmailTemplate;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class EstimateEmail extends Component
{
    use AuthorizesRequests;

    public ?Estimate $estimate = null;

    public array $to = [];

    public string $from = '';

    public array $availableFromEmails = [];

    public $adminUsers = null;

    public string $subject = '';

    public string $body = '';

    public bool $include_estimate_pdf = true;

    public bool $include_reimbursements_pdf = false;

    public bool $hasReimbursements = false;

    protected $listeners = ['compose' => 'openModal'];

    protected function rules(): array
    {
        return [
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['required', 'email'],
            'from' => ['required', 'email'],
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'include_estimate_pdf' => 'boolean',
            'include_reimbursements_pdf' => 'boolean',
        ];
    }

    public function updated($field)
    {
        if (in_array($field, ['to', 'from', 'subject', 'body'])) {
            $this->validateOnly($field);
        }
    }

    public function openModal(Estimate $estimate)
    {
        $this->authorize('view', $estimate);

        $this->estimate = $estimate->fresh(['project.client.users', 'vendor']);

        $this->to = $this->estimate->client->users
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Get admin users from the vendor
        $this->adminUsers = $this->estimate->vendor->users()
            ->wherePivot('role_id', 1)
            ->wherePivot('is_employed', 1)
            ->get(['users.id', 'first_name', 'last_name', 'email']);

        $this->availableFromEmails = $this->adminUsers
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Set default from to current user's email if they're an admin, otherwise first admin
        $currentUserEmail = auth()->user()->email;
        $this->from = in_array($currentUserEmail, $this->availableFromEmails) 
            ? $currentUserEmail 
            : ($this->availableFromEmails[0] ?? '');

        // Set the default subject and body for the estimate email
        $this->subject = 'Estimate for ' . $this->estimate->project->project_name . ' | ' . $this->estimate->client->last_names . ' | ' . $this->estimate->vendor->name;
        $this->body = EstimateEmailTemplate::defaultBody($this->estimate);

        $reimbursementsTotal = $this->estimate->project->finances['reimbursments'] ?? 0;
        $this->hasReimbursements = $reimbursementsTotal > 0;
        $this->include_reimbursements_pdf = $this->hasReimbursements;

        $this->modal('estimate_email_modal')->show();
    }

    public function send()
    {
        if (! $this->estimate) {
            return;
        }

        $this->authorize('view', $this->estimate);

        $this->validate();

        $this->estimate->loadMissing(['project.client', 'vendor']);

        $user = auth()->user();

        $vendorEmail = $user?->vendor?->business_email;

        if (! $vendorEmail) {
            Flux::toast(
                duration: 7000,
                position: 'top right',
                variant: 'danger',
                heading: 'Email Not Sent',
                text: 'Your vendor profile is missing a business email address.',
            );

            return;
        }

        $companyEmail = CompanyEmail::query()
            ->where('email', $vendorEmail)
            ->first();

        if (! $companyEmail || ! $companyEmail->grant_id) {
            Flux::toast(
                duration: 7000,
                position: 'top right',
                variant: 'danger',
                heading: 'Email Not Sent',
                text: 'We could not find a connected company email for this vendor.',
            );

            return;
        }

        $recipients = collect($this->to)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($recipients)) {
            Flux::toast(
                duration: 7000,
                position: 'top right',
                variant: 'danger',
                heading: 'Email Not Sent',
                text: 'We need at least one valid recipient email.',
            );

            return;
        }

        SendEstimateEmailJob::dispatch(
            estimateId: $this->estimate->id,
            companyEmailId: $companyEmail->id,
            userId: $user->id,
            recipients: $this->to,
            fromEmail: $this->from,
            subject: $this->subject,
            body: $this->body,
            includeEstimatePdf: $this->include_estimate_pdf,
            includeReimbursementsPdf: $this->include_reimbursements_pdf,
        );

        $this->modal('estimate_email_modal')->close();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Email Queued',
            text: 'We are sending the estimate to ' . count($this->to) . ' recipient(s)',
        );

        $this->reset(['to', 'from', 'availableFromEmails', 'adminUsers', 'subject', 'body', 'include_estimate_pdf', 'include_reimbursements_pdf', 'hasReimbursements', 'estimate']);
        $this->include_estimate_pdf = true;
        $this->include_reimbursements_pdf = false;
        $this->to = [];
        $this->from = '';
        $this->availableFromEmails = [];
    }

    public function getUserDisplayName(string $email): string
    {
        if (!$this->estimate) {
            return $email;
        }

        $user = $this->estimate->client->users->firstWhere('email', $email);
        
        if (!$user) {
            return $email;
        }

        return $user->first_name . ' (' . $email . ')';
    }

    public function getFromUserDisplayName(string $email): string
    {
        if (!$this->adminUsers) {
            return $email;
        }

        $user = $this->adminUsers->firstWhere('email', $email);
        
        if (!$user) {
            return $email;
        }

        return $user->first_name . ' ' . $user->last_name . ' (' . $email . ')';
    }

    public function render()
    {
        return view('livewire.estimates.email');
    }
}
