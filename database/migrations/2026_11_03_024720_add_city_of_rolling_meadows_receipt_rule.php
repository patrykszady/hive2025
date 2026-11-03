<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The City of Rolling Meadows emails its permit-fee receipts as a PDF from
 * noreply@cityrm.org, subject "A copy of your receipt has been attached"
 * (Oct 30 and Nov 2, 2026, 123 Villa Circle). No receipt rule matched them,
 * and the vendor was not linked to GS through receipt_accounts, which is
 * what puts a rule on a mailbox (CompanyEmail::receipts()). Same shape as
 * the other municipal PDF rules (City of Chicago, Village of Palatine).
 *
 * Only where the vendor is the City of Rolling Meadows and no rule for this
 * sender exists yet, so it is safe to run on any copy of the database.
 */
return new class extends Migration
{
    private const VENDOR_ID = 531;

    private const COMPANY_VENDOR_ID = 1;

    private const FROM_ADDRESS = 'noreply@cityrm.org';

    public function up(): void
    {
        $vendorIsRollingMeadows = DB::table('vendors')
            ->where('id', self::VENDOR_ID)
            ->where('business_name', 'like', '%Rolling Meadows%')
            ->exists();
        if (! $vendorIsRollingMeadows) {
            return;
        }

        if (! DB::table('receipts')->where('from_address', self::FROM_ADDRESS)->exists()) {
            DB::table('receipts')->insert([
                'vendor_id' => self::VENDOR_ID,
                'from_type' => 3,
                'from_address' => self::FROM_ADDRESS,
                'from_subject' => json_encode(['A copy of your receipt has been attached']),
                'options' => json_encode(['pdf_html' => true, 'document_model' => 'prebuilt-receipt']),
                'receipt_type' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $linked = DB::table('receipt_accounts')
            ->where('vendor_id', self::VENDOR_ID)
            ->where('belongs_to_vendor_id', self::COMPANY_VENDOR_ID)
            ->exists();
        if (! $linked) {
            DB::table('receipt_accounts')->insert([
                'vendor_id' => self::VENDOR_ID,
                'belongs_to_vendor_id' => self::COMPANY_VENDOR_ID,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('receipts')->where('from_address', self::FROM_ADDRESS)->delete();
    }
};
