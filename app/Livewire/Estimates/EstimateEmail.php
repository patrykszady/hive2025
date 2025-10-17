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

    public string $to = '';

    public string $subject = '';

    public string $body = '';

    public bool $include_estimate_pdf = true;

    public bool $include_reimbursements_pdf = false;

    public bool $hasReimbursements = false;

    protected $listeners = ['compose' => 'openModal'];

    protected function rules(): array
    {
        return [
            'to' => ['required', 'string', function ($attribute, $value, $fail) {
                $emails = $this->parseRecipientInput($value);

                if (empty($emails)) {
                    $fail('Please provide at least one email address.');
                    return;
                }

                foreach ($emails as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        $fail("{$email} is not a valid email address.");
                        return;
                    }
                }
            }],
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'include_estimate_pdf' => 'boolean',
            'include_reimbursements_pdf' => 'boolean',
        ];
    }

    public function updated($field)
    {
        if (in_array($field, ['to', 'subject', 'body'])) {
            $this->validateOnly($field);
        }
    }

    public function openModal(Estimate $estimate)
    {
        $this->authorize('view', $estimate);

        $this->estimate = $estimate->fresh(['project.client.users', 'vendor']);

        $recipientEmails = $this->estimate->client->users
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->to = implode(', ', $recipientEmails);

        $this->subject = $this->estimate->vendor->name . ' | ' . $this->estimate->client->name . ' | Estimate ' . $this->estimate->project->name;
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

        $recipients = collect($this->parseRecipientInput($this->to))
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
            recipients: $recipients,
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
            text: 'We are sending the estimate to ' . $this->to,
        );

        $this->reset(['to', 'subject', 'body', 'include_estimate_pdf', 'include_reimbursements_pdf', 'hasReimbursements', 'estimate']);
        $this->include_estimate_pdf = true;
        $this->include_reimbursements_pdf = false;
    }

    private function parseRecipientInput(string $value): array
    {
        return collect(explode(',', $value))
            ->map(fn ($email) => trim($email))
            ->filter()
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.estimates.email');
    }
}
