<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\SendEstimateEmailRequest;
use App\Jobs\SendEstimateEmailJob;
use App\Models\CompanyEmail;
use App\Models\EmailTemplate;
use App\Models\Estimate;
use App\Services\Estimates\EstimatePlaceholders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Emailing an estimate — mirrors App\Livewire\Estimates\EstimateEmail
 * exactly: the same recipient/from/template resolution (options()) and the
 * same App\Jobs\SendEstimateEmailJob dispatch (send()), which does the
 * actual PDF generation and sending identically to the web. Extra file
 * attachments (additionalAttachments) are left web-only — see this
 * class's docblock on send().
 */
class EstimateEmailController extends Controller
{
    /**
     * Everything the app's compose screen needs to prefill: recipients,
     * available "from" addresses, and the default template's subject/body
     * with placeholders already filled in, same as
     * App\Livewire\Estimates\EstimateEmail::openModal().
     */
    public function options(Request $request, int $estimate): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('view', $found);

        $found->loadMissing(['project.client.users', 'project.latestStatus', 'vendor']);

        $to = $found->client->users
            ->filter(fn ($user) => $user->hasRoutableEmail())
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $adminUsers = $found->vendor->users()
            ->employed()
            ->wherePivot('role_id', 1)
            ->get(['users.id', 'first_name', 'last_name', 'email']);

        $availableFromEmails = CompanyEmail::where('vendor_id', $found->vendor->id)
            ->whereNotNull('grant_id')
            ->get(['id', 'email']);

        $currentUserEmail = $request->user()->email;
        $defaultFrom = $availableFromEmails->firstWhere('email', $currentUserEmail)?->email
            ?? $availableFromEmails->first()?->email
            ?? '';

        $templates = EmailTemplate::where('type', 'estimate')->orderBy('name')->get();
        $defaultTemplate = $templates->first();
        $placeholders = app(EstimatePlaceholders::class);

        $reimbursementsTotal = $found->project->finances['reimbursments'] ?? 0;

        return response()->json([
            'data' => [
                'to' => $to,
                'from' => $defaultFrom,
                'available_from_emails' => $availableFromEmails->pluck('email')->values()->all(),
                'templates' => $templates->map(fn (EmailTemplate $template) => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'subject' => $placeholders->replace($template->subject, $found, $defaultFrom, $adminUsers),
                    'body' => $placeholders->replace($template->body, $found, $defaultFrom, $adminUsers),
                ])->values()->all(),
                'default_template_id' => $defaultTemplate?->id,
                'subject' => $defaultTemplate ? $placeholders->replace($defaultTemplate->subject, $found, $defaultFrom, $adminUsers) : '',
                'body' => $defaultTemplate ? $placeholders->replace($defaultTemplate->body, $found, $defaultFrom, $adminUsers) : '',
                'has_reimbursements' => $reimbursementsTotal > 0,
            ],
        ]);
    }

    /**
     * Queues App\Jobs\SendEstimateEmailJob exactly as
     * App\Livewire\Estimates\EstimateEmail::send() does — the PDF/XLSX
     * attachment generation and the actual send happen inside that job on
     * the web too, so nothing here is forked. Only the optional extra file
     * attachments (additionalAttachments) and the inline project-status
     * update (update_project()) are left web-only — neither changes what
     * gets sent, and both are edge cases a phone compose screen rarely
     * needs.
     */
    public function send(SendEstimateEmailRequest $request, int $estimate): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('view', $found);

        $found->loadMissing(['project.client', 'project.latestStatus', 'vendor']);

        $trackingProvider = (string) config('email_tracking.provider', 'nylas');

        $companyEmailQuery = CompanyEmail::query()
            ->where('email', $request->validated('from'))
            ->where('vendor_id', $found->vendor->id);

        if ($trackingProvider !== 'mailtrap') {
            $companyEmailQuery->whereNotNull('grant_id');
        }

        $companyEmail = $companyEmailQuery->first();

        abort_unless($companyEmail, 422, $trackingProvider === 'mailtrap'
            ? 'The selected sender email is not available.'
            : 'The selected sender email is not connected to Nylas.');

        SendEstimateEmailJob::dispatch(
            estimateId: $found->id,
            companyEmailId: $companyEmail->id,
            userId: $request->user()->id,
            recipients: $request->validated('to'),
            fromEmail: $request->validated('from'),
            subject: $request->validated('subject'),
            body: $request->validated('body'),
            includeEstimatePdf: $request->boolean('include_estimate_pdf', true),
            includeReimbursementsPdf: $request->boolean('include_reimbursements_pdf', false),
            includeEstimateXlsx: $request->boolean('include_estimate_xlsx', false),
            emailTemplateName: null,
            senderIp: $request->ip(),
            additionalAttachmentPaths: [],
            showChanges: false,
        );

        return response()->json(['data' => ['queued' => true, 'recipient_count' => count($request->validated('to'))]]);
    }

    private function visibleEstimate(int $id): Estimate
    {
        abort_unless(Estimate::withoutGlobalScopes()->whereKey($id)->exists(), 404);
        $found = Estimate::query()->whereKey($id)->first();
        abort_unless($found, 403, 'You do not have access to this estimate.');

        return $found;
    }
}
