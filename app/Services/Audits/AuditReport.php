<?php

namespace App\Services\Audits;

use App\Models\Check;
use App\Models\EwccvCoverageCheck;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XLSXWriter;

/**
 * The audit report for a company, a period, a type and a set of bank
 * accounts: the paid vendors with their checks, the policies that covered
 * them, EWCCV's answer as of the audit's coverage date, and the two files
 * an auditor gets (the merged certificates PDF and the Excel). Built by the
 * audit page and by the job that emails the package, from the same code.
 */
class AuditReport
{
    protected ?Collection $groups = null;

    /**
     * @param  list<int>  $bankAccountIds
     */
    public function __construct(
        public readonly int $companyVendorId,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?string $auditType,
        public readonly array $bankAccountIds,
        public readonly ?Carbon $coverageDate = null,
    ) {}

    public function company(): ?Vendor
    {
        return Vendor::withoutGlobalScopes()->find($this->companyVendorId);
    }

    /** EWCCV's answers as of the coverage date, by vendor id. */
    public function coverageChecks(): Collection
    {
        if ($this->auditType !== 'workers' || ! $this->coverageDate) {
            return collect();
        }

        return EwccvCoverageCheck::query()
            ->where('belongs_to_vendor_id', $this->companyVendorId)
            ->whereDate('coverage_date', $this->coverageDate)
            ->get()
            ->keyBy('vendor_id');
    }

    /**
     * One group per payee: the company itself first, then vendors, via-vendor
     * members and employees, each with its checks, the policies in the window
     * and EWCCV's answer for the coverage date.
     */
    public function groups(): Collection
    {
        if ($this->groups !== null) {
            return $this->groups;
        }
        if (! $this->endDate || $this->bankAccountIds === []) {
            return $this->groups = collect();
        }

        $start = $this->startDate;
        $end = $this->endDate;
        $authVendorId = $this->companyVendorId;

        $ownVendorChecks = Check::whereBetween('date', [$start, $end])
            ->whereIn('bank_account_id', $this->bankAccountIds)
            ->where('vendor_id', $authVendorId)
            ->whereNull('user_id')
            ->where('check_type', '!=', 'Cash')
            ->with(['vendor' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('date')
            ->get();

        $vendorChecksByVendor = Check::whereBetween('date', [$start, $end])
            ->whereIn('bank_account_id', $this->bankAccountIds)
            ->whereNotNull('vendor_id')
            ->whereNull('user_id')
            ->where('vendor_id', '!=', $authVendorId)
            ->where('check_type', '!=', 'Cash')
            ->with(['vendor' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('date')
            ->get()
            ->groupBy('vendor_id');

        $employeeChecksByUser = Check::whereBetween('date', [$start, $end])
            ->whereIn('bank_account_id', $this->bankAccountIds)
            ->whereNotNull('user_id')
            ->where('check_type', '!=', 'Cash')
            ->with('user.vendors')
            ->orderBy('date')
            ->get()
            ->groupBy('user_id');

        $viaVendorEmployees = $employeeChecksByUser->filter(function ($checks) use ($authVendorId) {
            $user = $checks->first()->user;
            if (! $user) {
                return false;
            }
            $role = method_exists($user, 'getRoleForVendor') ? $user->getRoleForVendor($authVendorId) : null;
            $viaVendorId = $user->vendors->firstWhere('id', $authVendorId)?->pivot?->via_vendor_id;

            return $role === 'Member' && ! is_null($viaVendorId);
        });

        $regularEmployeeChecksByUser = $employeeChecksByUser->reject(fn ($checks, $userId) => $viaVendorEmployees->has($userId));

        $groupsFromVendors = $vendorChecksByVendor
            ->filter(fn ($checks) => $checks->first()->vendor !== null)
            ->map(fn ($checks) => ['vendor' => $checks->first()->vendor, 'checks' => $checks->values()]);

        $groupsFromEmployees = $regularEmployeeChecksByUser->map(function ($checks) {
            $user = $checks->first()->user;
            $pseudoVendor = new class($user)
            {
                public string $business_name;

                public string $business_type = 'Employee';

                public function __construct($user)
                {
                    $this->business_name = (string) ($user?->full_name ?? 'Unknown');
                }
            };

            return ['vendor' => $pseudoVendor, 'checks' => $checks->values(), 'is_user' => true, 'user' => $user];
        });

        $viaVendorBuckets = [];
        foreach ($viaVendorEmployees as $checks) {
            $user = $checks->first()->user;
            $viaVendorId = $user?->vendors->firstWhere('id', $authVendorId)?->pivot?->via_vendor_id;
            if (! $viaVendorId) {
                continue;
            }
            $viaVendorBuckets[$viaVendorId] = ($viaVendorBuckets[$viaVendorId] ?? collect())->merge($checks);
        }
        $groupsFromViaMembers = collect($viaVendorBuckets)->map(function ($checks, $viaVendorId) {
            $vendor = Vendor::withoutGlobalScopes()->find($viaVendorId);
            if (! $vendor) {
                $firstUser = $checks->first()?->user;
                $vendor = new class($firstUser)
                {
                    public string $business_name;

                    public ?string $business_type = 'Vendor';

                    public function __construct($user)
                    {
                        $this->business_name = (string) ($user?->full_name ?? 'Unknown');
                    }
                };
            }

            return ['vendor' => $vendor, 'checks' => $checks->values(), 'via_vendor' => true];
        });

        $ownVendorGroup = $ownVendorChecks->isNotEmpty()
            ? ['vendor' => $ownVendorChecks->first()->vendor ?? $this->company(), 'checks' => $ownVendorChecks->values()]
            : null;

        $otherGroups = $groupsFromVendors
            ->merge($groupsFromViaMembers)
            ->merge($groupsFromEmployees)
            ->sort(function ($a, $b) {
                $aIsUser = (bool) ($a['is_user'] ?? false);
                $bIsUser = (bool) ($b['is_user'] ?? false);
                if ($aIsUser !== $bIsUser) {
                    return $aIsUser <=> $bIsUser;
                }

                return strtolower($a['vendor']->business_name ?? '') <=> strtolower($b['vendor']->business_name ?? '');
            })
            ->values();

        $mergedGroups = collect();
        if ($ownVendorGroup) {
            $mergedGroups->push($ownVendorGroup);
        }
        $mergedGroups = $mergedGroups->merge($otherGroups);

        $auditEnd = $end;
        $groupVendorIds = $mergedGroups
            ->map(fn ($group) => $group['vendor'] instanceof Vendor ? $group['vendor']->id : null)
            ->filter()
            ->unique()
            ->values();

        $docsByVendor = $groupVendorIds->isNotEmpty()
            ? VendorDoc::withoutGlobalScopes()
                ->where('belongs_to_vendor_id', $authVendorId)
                ->whereIn('vendor_id', $groupVendorIds)
                ->whereIn('type', array_values(array_filter(['professional', $this->auditType])))
                ->with('agent')
                ->get()
                ->groupBy('vendor_id')
            : collect();

        $coverageChecks = $this->coverageChecks();

        return $this->groups = $mergedGroups->map(function ($group) use ($auditEnd, $start, $end, $docsByVendor, $coverageChecks) {
            $vendor = $group['vendor'];

            if ($vendor instanceof Vendor) {
                $vendorDocs = collect($docsByVendor->get($vendor->id) ?? []);
                $group['coverage_check'] = $coverageChecks->get($vendor->id);

                $professional = $vendorDocs->where('type', 'professional')->sortByDesc('effective_date')->first();
                if ($professional && $professional->effective_date->lte($auditEnd) && $professional->expiration_date->gte($auditEnd)) {
                    $group['professional_doc'] = $professional;
                }

                if ($this->auditType) {
                    $typeDocs = $vendorDocs->where('type', $this->auditType);
                    foreach ($typeDocs as $doc) {
                        foreach ($group['checks']->whereBetween('date', [$doc->effective_date, $doc->expiration_date]) as $check) {
                            $check->covered = true;
                        }
                    }
                    $applicable = $typeDocs
                        ->filter(fn ($doc) => $doc->effective_date?->format('Y-m-d') <= $end && $doc->expiration_date?->format('Y-m-d') >= $start)
                        ->sortByDesc('expiration_date');
                    if ($applicable->isNotEmpty()) {
                        $group['applicable_docs'] = $applicable->values();
                    }
                }
            }

            return $group;
        })->values();
    }

    /**
     * The vendors whose coverage the audit asks EWCCV about: paid subs that
     * are not the company itself, an employee, a retail or categorised
     * vendor, or a vendor with a professional policy for the period — and
     * only those with a workers comp policy on file that is active today.
     *
     * @return list<int>
     */
    public function vendorsNeedingCoverage(): array
    {
        if ($this->auditType !== 'workers') {
            return [];
        }
        $own = $this->companyVendorId;
        $candidates = $this->groups()
            ->filter(fn (array $group) => $group['vendor'] instanceof Vendor
                && $group['vendor']->id !== $own
                && empty($group['is_user'])
                && empty($group['vendor']->category_id)
                && $group['vendor']->business_type !== 'Retail'
                && ! isset($group['professional_doc']))
            ->map(fn (array $group) => (int) $group['vendor']->id)
            ->unique()
            ->values();
        if ($candidates->isEmpty()) {
            return [];
        }

        return VendorDoc::withoutGlobalScopes()
            ->where('type', 'workers')
            ->where('belongs_to_vendor_id', $own)
            ->whereIn('vendor_id', $candidates)
            ->whereDate('effective_date', '<=', today())
            ->whereDate('expiration_date', '>=', today())
            ->pluck('vendor_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{covered: int, not_found: int, pending: int, unchecked: int, total: int} */
    public function coverageCounts(): array
    {
        $needing = $this->vendorsNeedingCoverage();
        $checks = $this->coverageChecks();
        $counts = ['covered' => 0, 'not_found' => 0, 'pending' => 0, 'unchecked' => 0, 'total' => count($needing)];
        foreach ($needing as $vendorId) {
            $key = match ($checks->get($vendorId)?->status) {
                EwccvCoverageCheck::STATUS_COVERED => 'covered',
                EwccvCoverageCheck::STATUS_NOT_FOUND => 'not_found',
                EwccvCoverageCheck::STATUS_PENDING => 'pending',
                default => 'unchecked',
            };
            $counts[$key]++;
        }

        return $counts;
    }

    /**
     * The certificate files to merge: every policy of the audit's type with
     * a check inside its window.
     *
     * @return array{sources: list<string>, missing: list<string>}
     */
    public function certificateFiles(): array
    {
        $expected = [];
        $missing = [];
        foreach ($this->groups() as $group) {
            $vendor = $group['vendor'];
            if (! ($vendor instanceof Vendor) || ! $this->auditType) {
                continue;
            }
            $docs = VendorDoc::withoutGlobalScopes()
                ->where('belongs_to_vendor_id', $this->companyVendorId)
                ->where('vendor_id', $vendor->id)
                ->where('type', $this->auditType)
                ->get();
            foreach ($docs as $doc) {
                if ($group['checks']->whereBetween('date', [$doc->effective_date, $doc->expiration_date])->isEmpty()) {
                    continue;
                }
                $path = Storage::disk('files')->path('vendor_docs/'.$doc->doc_filename);
                if (file_exists($path)) {
                    $expected[] = $path;
                } else {
                    $missing[] = (string) $doc->doc_filename;
                }
            }
        }

        return ['sources' => array_values(array_unique($expected)), 'missing' => array_values(array_unique($missing))];
    }

    public function filenameBase(): string
    {
        $company = $this->company();

        return 'Audit-'.$this->companyVendorId.'-'.Str::slug((string) ($company?->name ?? $company?->business_name ?? 'company')).'-'.$this->startDate.'-to-'.$this->endDate;
    }

    /** The merged certificates PDF, or null when a file is missing or there is nothing to merge. */
    public function certificatesPdf(CertificateMerger $merger, ?array &$missing = null): ?string
    {
        $files = $this->certificateFiles();
        $missing = $files['missing'];
        if ($missing !== [] || $files['sources'] === []) {
            return null;
        }

        return $merger->merge($files['sources'], $this->filenameBase());
    }

    /** The Coverage column of a policy row in the export. */
    public static function verificationCell(VendorDoc $doc): ?string
    {
        $summary = $doc->verification_summary;
        if ($summary && $summary['verified_at']) {
            return 'WC verified '.$summary['verified_at'].' ('.implode(', ', array_map('strtoupper', $summary['sources'])).')';
        }
        if ($summary && ! empty($summary['alert'])) {
            return 'Coverage cancelled (EWCCV)';
        }
        if ($doc->type === 'workers') {
            return 'Not verified';
        }

        return null;
    }

    /** The Excel workbook's bytes (By Vendor + All Checks sheets). */
    public function xlsx(): string
    {
        $authVendorId = $this->companyVendorId;
        $tmp = tempnam(sys_get_temp_dir(), 'audit-xlsx-');

        $underlineBorder = new Border(new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THICK, Border::STYLE_SOLID));
        $vendorHeaderStyle = (new Style)->setBorder($underlineBorder)->setFontBold();
        $coverageGreen = (new Style)->setFontColor(Color::GREEN);
        $coverageOrange = (new Style)->setFontColor('C05621');
        $coverageRed = (new Style)->setFontColor(Color::RED);
        $styleFor = fn (string $coverage) => match ($coverage) {
            'Covered' => $coverageGreen,
            'Not Applicable' => $coverageOrange,
            default => $coverageRed,
        };

        $writer = new XLSXWriter;
        $writer->openToFile($tmp);
        $writer->getCurrentSheet()->setName('By Vendor');
        $writer->addRow(Row::fromValues(['Vendor', 'Date', 'Payment', 'Amount', 'Coverage']));

        $allChecks = [];
        foreach ($this->groups() as $group) {
            $vendor = $group['vendor'];
            $writer->addRow(Row::fromValues([$vendor->business_name, null, null, null, null], $vendorHeaderStyle));

            $isUser = (bool) ($group['is_user'] ?? false);
            if ($isUser) {
                $role = $group['user']?->getRoleForVendor($authVendorId) ?? 'No Role';
                $writer->addRow(Row::fromValues([$vendor->business_name.($role === 'Admin' ? ' is a stakeholder in the business and is excluded.' : ' is an employee payment subject to audit review.'), null, null, null, null]));
            } elseif (($vendor->business_type ?? null) === 'Retail') {
                $writer->addRow(Row::fromValues([$vendor->business_name." is Retail and doesn't require coverage.", null, null, null, null]));
            } elseif (isset($group['professional_doc'])) {
                $doc = $group['professional_doc'];
                $writer->addRow(Row::fromValues(['Professional policy active ('.$doc->effective_date->format('m/d/Y').'–'.$doc->expiration_date->format('m/d/Y').')', null, null, null, null]));
            }

            if (isset($group['applicable_docs']) && $group['applicable_docs']->count() > 0) {
                foreach ($group['applicable_docs'] as $doc) {
                    $writer->addRow(Row::fromValues([$doc->number.' ('.$doc->effective_date->format('m/d/Y').'–'.$doc->expiration_date->format('m/d/Y').')', null, null, null, self::verificationCell($doc)]));
                }
                $writer->addRow(Row::fromValues(['', '', '', '', '']));
            }
            if (! empty($group['coverage_check'])) {
                $check = $group['coverage_check'];
                $writer->addRow(Row::fromValuesWithStyles([$check->label(), null, null, null, $check->badge()], null, [4 => match ($check->tone()) { 'green' => $coverageGreen, 'red' => $coverageRed, default => $coverageOrange }]));
                $writer->addRow(Row::fromValues(['', '', '', '', '']));
            }

            foreach ($group['checks'] as $check) {
                if ($isUser) {
                    $role = $group['user']?->getRoleForVendor($authVendorId) ?? 'No Role';
                    $coverage = $role === 'Admin' ? 'Not Applicable' : 'Not Covered';
                } else {
                    $coverage = $check->covered
                        ? 'Covered'
                        : ((isset($group['professional_doc']) || ($vendor->business_type ?? null) === 'Retail' || ! empty($vendor->category_id)) ? 'Not Applicable' : 'Not Covered');
                }
                $writer->addRow(Row::fromValuesWithStyles([null, $check->date->format('m/d/Y'), $check->payment_label.' '.$check->check_number, money($check->amount), $coverage], null, [4 => $styleFor($coverage)]));
                $allChecks[] = [
                    'type' => (string) $check->check_type,
                    'number' => (string) $check->check_number,
                    'date' => $check->date->format('m/d/Y'),
                    'vendor' => $vendor->business_name,
                    'payment' => $check->payment_label.' '.$check->check_number,
                    'amount' => money($check->amount),
                    'coverage' => $coverage,
                ];
            }
            $writer->addRow(Row::fromValues(['', '', '', '', '']));
        }

        usort($allChecks, function ($a, $b) {
            $typeCmp = strcasecmp($a['type'], $b['type']);

            return $typeCmp !== 0 ? $typeCmp : strnatcasecmp($a['number'], $b['number']);
        });
        $writer->addNewSheetAndMakeItCurrent()->setName('All Checks');
        $writer->addRow(Row::fromValues(['Date', 'Vendor', 'Payment', 'Amount', 'Coverage']));
        foreach ($allChecks as $row) {
            $writer->addRow(Row::fromValuesWithStyles([$row['date'], $row['vendor'], $row['payment'], $row['amount'], $row['coverage']], null, [4 => $styleFor($row['coverage'])]));
        }
        $writer->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
