<?php

namespace App\Services\EmailTemplates;

use App\Models\EmailTemplate;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The app's Email Templates screen — the same list/create/edit/delete as
 * the web's /email_templates page (App\Livewire\EmailTemplates\
 * {EmailTemplateIndex,EmailTemplateForm}). EmailTemplate's own global
 * "vendor" scope already limits rows to the signed-in company's tree, same
 * as the web.
 */
class AppEmailTemplateDirectory
{
    private const PER_PAGE = 20;

    /** The Estimates tab also surfaces invoice templates — EmailTemplateIndex::templates(). */
    public function list(string $type, int $page): LengthAwarePaginator
    {
        $query = $type === 'estimate'
            ? EmailTemplate::query()->whereIn('type', ['estimate', 'invoice'])
            : EmailTemplate::query()->where('type', $type);

        return $query->orderBy('name')->paginate(self::PER_PAGE, ['*'], 'page', $page);
    }

    /**
     * Mirrors EmailTemplateForm::fixMojibake() exactly.
     */
    public function fixMojibake(string $text): string
    {
        if (! str_contains($text, 'â') && ! str_contains($text, 'Ã') && ! str_contains($text, 'Â')) {
            return $text;
        }

        return strtr($text, [
            'â€™' => '’',
            'â€œ' => '“',
            'â€�' => '”',
            'â€˜' => '‘',
            'â€”' => '—',
            'â€“' => '–',
            'â€¦' => '…',
            'â€¢' => '•',
            'Â ' => ' ',
        ]);
    }

    /**
     * Mirrors EmailTemplateForm::getPlaceholders() exactly.
     *
     * @return list<string>
     */
    public function placeholders(string $type): array
    {
        if ($type === 'contract') {
            return [
                'today_date', 'vendor_name', 'short_vendor_name', 'client_name', 'estimate_number',
                'project_address', 'start_date', 'end_date', 'estimate_total', 'estimate_total_words',
                'payment_schedule', 'current_year',
            ];
        }

        if ($type === 'lead') {
            return [
                'client_name', 'client_first_names', 'client_last_names', 'lead_message', 'lead_address',
                'lead_origin', 'lead_availability', 'vendor_name', 'short_vendor_name', 'sender_first_name',
                'sender_last_name',
            ];
        }

        return [
            'client_name', 'client_first_names', 'client_last_names', 'project_name', 'project_address_1',
            'estimate_total', 'vendor_name', 'short_vendor_name', 'sender_first_name', 'sender_last_name',
        ];
    }

    /**
     * @param  array{name: string, subject: ?string, body: string, type: string}  $data
     */
    public function create(int $vendorId, array $data): EmailTemplate
    {
        return EmailTemplate::create([
            'vendor_id' => $vendorId,
            'name' => $data['name'],
            'type' => $data['type'],
            'subject' => $data['type'] === 'contract' ? ($data['subject'] ?? null) : $data['subject'],
            'body' => $this->fixMojibake($data['body']),
        ]);
    }

    /**
     * @param  array{name: string, subject: ?string, body: string, type: string}  $data
     */
    public function update(EmailTemplate $template, array $data): EmailTemplate
    {
        $template->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'subject' => $data['type'] === 'contract' ? ($data['subject'] ?? null) : $data['subject'],
            'body' => $this->fixMojibake($data['body']),
        ]);

        return $template;
    }
}
