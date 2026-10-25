<?php

namespace Database\Factories;

use App\Models\EstimateSubDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstimateSubDocument>
 */
class EstimateSubDocumentFactory extends Factory
{
    protected $model = EstimateSubDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'belongs_to_vendor_id' => 1,
            'uploaded_by_user_id' => 1,
            'status' => EstimateSubDocument::STATUS_EXTRACTING,
            'file_disk' => 'local',
            'file_path' => 'estimate-sub-documents/test/invoice.pdf',
            'original_name' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
        ];
    }
}
