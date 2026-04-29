<?php

namespace App\Livewire\Estimates;

use App\Jobs\SendEstimateEmailJob;
use App\Models\CompanyEmail;
use App\Models\Estimate;
use App\Models\EmailTemplate;
use App\Models\ProjectStatus;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithFileUploads;

class EstimateEmail extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public ?Estimate $estimate = null;

    public array $to = [];

    public string $from = '';

    public $availableFromEmails = [];

    public $adminUsers = [];

    public string $subject = '';

    public string $body = '';

    public ?int $selectedTemplateId = null;

    public $availableTemplates = [];

    public bool $include_estimate_pdf = true;

    public bool $include_estimate_xlsx = false;

    public bool $include_reimbursements_pdf = false;

    public bool $hasReimbursements = false;

    public string $newRecipientEmail = '';

    public array $availableContacts = [];

    public array $additionalAttachments = [];

    public bool $showChanges = false;

    public ?string $project_status = null;

    public ?string $project_status_date = null;

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
            'include_estimate_xlsx' => 'boolean',
            'include_reimbursements_pdf' => 'boolean',
            'additionalAttachments' => 'array|max:5',
            'additionalAttachments.*' => 'file|max:10240',
            'project_status' => 'nullable|integer',
            'project_status_date' => 'nullable|date',
        ];
    }

    public function updated($field)
    {
        if (in_array($field, ['to', 'from', 'subject', 'body'])) {
            $this->validateOnly($field);
        }

        if ($field === 'selectedTemplateId' && $this->selectedTemplateId && $this->estimate) {
            $template = EmailTemplate::find($this->selectedTemplateId);
            if ($template) {
                $this->subject = $this->replacePlaceholders($template->subject, $this->estimate);
                $this->body = $this->replacePlaceholders($template->body, $this->estimate);
            }
        }

        if ($field === 'from' && $this->selectedTemplateId && $this->estimate) {
            $template = EmailTemplate::find($this->selectedTemplateId);
            if ($template) {
                $this->subject = $this->replacePlaceholders($template->subject, $this->estimate);
                $this->body = $this->replacePlaceholders($template->body, $this->estimate);
            }
        }
    }

    public function openModal(Estimate $estimate, bool $showChanges = false)
    {
        $this->authorize('view', $estimate);

        $this->showChanges = $showChanges;

        $this->estimate = $estimate->fresh(['project.client.users', 'project.latestStatus', 'vendor']);

        $this->to = $this->estimate->client->users
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Get admin users from the vendor for display names
        $this->adminUsers = $this->estimate->vendor->users()
            ->employed()
            ->wherePivot('role_id', 1)
            ->get(['users.id', 'first_name', 'last_name', 'email']);

        // Build available contacts list (client users + vendor admin users)
        $clientUsers = $this->estimate->client->users
            ->map(fn ($user) => [
                'email' => $user->email,
                'name' => trim($user->first_name . ' ' . ($user->last_name ?? '')),
                'group' => 'Client',
            ])
            ->filter(fn ($contact) => ! empty($contact['email']))
            ->values();

        $vendorUsers = $this->adminUsers
            ->map(fn ($user) => [
                'email' => $user->email,
                'name' => trim($user->first_name . ' ' . ($user->last_name ?? '')),
                'group' => 'Team',
            ])
            ->filter(fn ($contact) => ! empty($contact['email']))
            ->values();

        $this->availableContacts = $clientUsers->merge($vendorUsers)
            ->unique('email')
            ->values()
            ->all();

        // Get vendor's company emails (Nylas connected emails)
        $this->availableFromEmails = CompanyEmail::where('vendor_id', $this->estimate->vendor->id)
            ->whereNotNull('grant_id')
            ->get();

        // Set default from to current user's email if they have a company email, otherwise first available
        $currentUserEmail = auth()->user()->email;
        $currentUserCompanyEmail = $this->availableFromEmails->firstWhere('email', $currentUserEmail);
        $this->from = $currentUserCompanyEmail?->email ?? ($this->availableFromEmails->first()?->email ?? '');

        // Load available templates
        $this->availableTemplates = EmailTemplate::where('type', 'estimate')
            ->orderBy('name')
            ->get();

        $defaultTemplate = $this->availableTemplates->first();
        $this->selectedTemplateId = $defaultTemplate?->id;
        $this->subject = $defaultTemplate ? $this->replacePlaceholders($defaultTemplate->subject, $this->estimate) : '';
        $this->body = $defaultTemplate ? $this->replacePlaceholders($defaultTemplate->body, $this->estimate) : '';

        $reimbursementsTotal = $this->estimate->project->finances['reimbursments'] ?? 0;
        $this->hasReimbursements = $reimbursementsTotal > 0;
        $this->include_estimate_xlsx = false;
        $this->include_reimbursements_pdf = $this->hasReimbursements;
        $this->newRecipientEmail = '';
        $this->additionalAttachments = [];
        $this->project_status = null;
        $this->project_status_date = today()->format('Y-m-d');

        $this->modal('estimate_email_modal')->show();
    }

    protected function replacePlaceholders(string $text, Estimate $estimate): string
    {
        $clientName = $estimate->client?->business_name 
            ? $estimate->client->business_name 
            : ($estimate->client?->first_names ?? 'there');
        $clientFirstNames = $estimate->client?->first_names ?? '';
        $clientLastNames = $estimate->client?->last_names ?? '';
        $projectName = $estimate->project->project_name ?? 'your project';
        $projectAddress = $estimate->project->address ?? '';
        $estimateTotal = '$' . number_format($estimate->amount ?? 0, 2);
        $vendorName = $estimate->vendor->name ?? 'our team';
        $shortVendorName = data_get($estimate->vendor->options, 'short_name') ?: $vendorName;

        // Resolve sender name from the selected 'from' email
        $senderUser = $this->adminUsers
            ? collect($this->adminUsers)->firstWhere('email', $this->from)
            : null;
        $senderFirstName = $senderUser?->first_name ?? (auth()->user()?->first_name ?? '');
        $senderLastName = $senderUser?->last_name ?? (auth()->user()?->last_name ?? '');

        // Generate the signing page URL (auth-protected, no signed URL needed)
        $signingLink = route('estimate.sign', ['estimate' => $estimate->id]);

        return str_replace(
            [
                '{{client_name}}', 
                '{{client_first_names}}', 
                '{{client_last_names}}', 
                '{{project_name}}', 
                '{{project_address_1}}', 
                '{{estimate_total}}', 
                '{{vendor_name}}',
                '{{short_vendor_name}}',
                '{{sender_first_name}}',
                '{{sender_last_name}}',
                '{{signing_link}}',
            ],
            [
                $clientName, 
                $clientFirstNames, 
                $clientLastNames, 
                $projectName, 
                $projectAddress, 
                $estimateTotal, 
                $vendorName,
                $shortVendorName,
                $senderFirstName,
                $senderLastName,
                $signingLink,
            ],
            $text
        );
    }

    public function addRecipient(): void
    {
        $email = trim($this->newRecipientEmail);

        if ($email === '') {
            return;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError('newRecipientEmail', 'Please enter a valid email address.');
            return;
        }

        if (in_array($email, $this->to, true)) {
            $this->addError('newRecipientEmail', 'This email is already added.');
            return;
        }

        $this->to[] = $email;
        $this->newRecipientEmail = '';
        $this->resetErrorBag('newRecipientEmail');
    }

    public function removeRecipient(string $email): void
    {
        $this->to = array_values(array_filter($this->to, fn ($e) => $e !== $email));
    }

    public function toggleContact(string $email): void
    {
        if (in_array($email, $this->to, true)) {
            $this->removeRecipient($email);
        } else {
            $this->to[] = $email;
        }
    }

    public function removeAttachment(int $index): void
    {
        if (isset($this->additionalAttachments[$index])) {
            $this->additionalAttachments[$index]->delete();
            unset($this->additionalAttachments[$index]);
            $this->additionalAttachments = array_values($this->additionalAttachments);
        }
    }

    public function send()
    {
        if (! $this->estimate) {
            return;
        }

        $this->authorize('view', $this->estimate);

        $this->validate();

        $this->estimate->loadMissing(['project.client', 'project.latestStatus', 'vendor']);

        $user = auth()->user();

        $trackingProvider = (string) config('email_tracking.provider', 'nylas');

        // Find the CompanyEmail matching the selected "from" address
        $companyEmailQuery = CompanyEmail::query()
            ->where('email', $this->from)
            ->where('vendor_id', $this->estimate->vendor->id);

        if ($trackingProvider !== 'mailtrap') {
            $companyEmailQuery->whereNotNull('grant_id');
        }

        $companyEmail = $companyEmailQuery->first();

        if (!$companyEmail) {
            Flux::toast(
                duration: 7000,
                position: 'top right',
                variant: 'danger',
                heading: 'Email Not Sent',
                text: $trackingProvider === 'mailtrap'
                    ? 'The selected sender email is not available.'
                    : 'The selected sender email is not connected to Nylas.',
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

        $templateName = null;
        if ($this->selectedTemplateId) {
            $template = EmailTemplate::find($this->selectedTemplateId);
            $templateName = $template?->name;
        }

        // Store uploaded attachments to temp directory so the queued job can access them
        $additionalAttachmentPaths = [];
        if (! empty($this->additionalAttachments)) {
            $tempDir = storage_path('app/temp');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            foreach ($this->additionalAttachments as $file) {
                $originalName = $file->getClientOriginalName();
                $safeName = preg_replace('/[^\w.\-]/', '_', $originalName);
                $tempPath = $tempDir . '/' . $safeName;

                // Avoid collisions if two files share the same name
                if (file_exists($tempPath)) {
                    $info = pathinfo($safeName);
                    $tempPath = $tempDir . '/' . ($info['filename'] ?? 'file') . '_' . uniqid() . '.' . ($info['extension'] ?? 'bin');
                }

                file_put_contents($tempPath, $file->get());
                $additionalAttachmentPaths[] = $tempPath;
            }
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
            includeEstimateXlsx: $this->include_estimate_xlsx,
            emailTemplateName: $templateName,
            senderIp: request()->ip(),
            additionalAttachmentPaths: $additionalAttachmentPaths,
            showChanges: $this->showChanges,
        );

        $this->modal('estimate_email_modal')->close();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Email Queued',
            text: 'We are sending the estimate to ' . count($this->to) . ' recipient(s)',
        );

        $this->reset([
            'to',
            'from',
            'availableFromEmails',
            'adminUsers',
            'subject',
            'body',
            'include_estimate_pdf',
            'include_estimate_xlsx',
            'include_reimbursements_pdf',
            'hasReimbursements',
            'additionalAttachments',
            'project_status',
            'project_status_date',
            'estimate',
            'newRecipientEmail',
            'availableContacts',
        ]);
        $this->include_estimate_pdf = true;
        $this->include_estimate_xlsx = false;
        $this->include_reimbursements_pdf = false;
        $this->to = [];
        $this->from = '';
        $this->availableFromEmails = [];
    }

    public function update_project(): void
    {
        if (! $this->estimate?->project) {
            return;
        }

        $this->validate([
            'project_status' => ['required', 'integer'],
            'project_status_date' => ['required', 'date'],
        ]);

        $userVendorId = auth()->user()?->vendor?->id;

        if (! $userVendorId) {
            Flux::toast(
                duration: 7000,
                position: 'top right',
                variant: 'danger',
                heading: 'Status Not Updated',
                text: 'Your vendor profile is missing. Please contact support.',
            );

            return;
        }

        $project = $this->estimate->project;
        $statusCode = (int) $this->project_status;

        $status = ProjectStatus::create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $userVendorId,
            'status_code' => $statusCode,
            'start_date' => $this->project_status_date,
        ]);

        if ($statusCode === 10) {
            $project->estimates()->delete();
        }

        $this->project_status = null;
        $this->project_status_date = today()->format('Y-m-d');

        $this->dispatch('refreshComponent')->to('projects.project-show');
        $this->dispatch('refreshComponent')->to('estimates.estimates-index');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Status Updated',
            text: $status->title . ' started on ' . $status->start_date->format('m/d/Y'),
        );
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
