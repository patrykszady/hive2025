<?php

namespace App\Services;

use App\Models\CheckImage;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a check image's handwritten payee text to a real entity — a User
 * (employee) or Vendor of the owning company.
 *
 * Priority:
 *   1. The linked check's own payee (vendor_id / user_id) — authoritative.
 *   2. Fuzzy likeness match (same normalize + similar_text approach as the
 *      receipts vendor matcher) against the company's users and vendor list,
 *      accepted only with a clear winner: score ≥ 60% and ≥ 5 points ahead
 *      of the runner-up. Cursive payees OCR with mangled letters
 *      ("Gheyoa Szady" → Grzegorz Szady at 69%), so thresholds are tuned
 *      against real statement data.
 */
class CheckImagePayeeResolver
{
    private const MIN_SCORE = 60.0;

    private const MIN_LEAD = 5.0;

    /** @var array<int, Collection> */
    private array $candidateCache = [];

    /**
     * @return array{source: string, user_id: ?int, vendor_id: ?int, score: ?float}|null
     */
    public function resolve(CheckImage $image): ?array
    {
        // Idempotent: never overwrite an existing resolution.
        if ($image->payee_user_id || $image->payee_vendor_id) {
            return null;
        }

        // 1. Adopt the linked check's payee — it is the system's ground truth.
        if ($image->check_id && ($check = $image->check()->first())) {
            if ($check->user_id || $check->vendor_id) {
                $result = [
                    'source'    => 'check',
                    'user_id'   => $check->user_id,
                    'vendor_id' => $check->user_id ? null : $check->vendor_id,
                    'score'     => null,
                ];

                return $this->apply($image, $result);
            }
        }

        // 2. Fuzzy-match the handwritten payee against company users + vendors.
        if (! $image->payee || ! $image->belongs_to_vendor_id) {
            return null;
        }

        $scores = $this->candidates($image->belongs_to_vendor_id)
            ->map(function (array $candidate) use ($image) {
                similar_text(
                    $this->normalize($image->payee),
                    $this->normalize($candidate['name']),
                    $percent
                );
                $candidate['score'] = $percent;

                return $candidate;
            })
            ->sortByDesc('score')
            ->values();

        $best   = $scores->first();
        $second = $scores->get(1);

        if (! $best || $best['score'] < self::MIN_SCORE) {
            return null;
        }

        if ($second && ($best['score'] - $second['score']) < self::MIN_LEAD) {
            Log::channel('check_images')->info('Payee fuzzy match ambiguous — skipped', [
                'image'  => $image->image_filename,
                'payee'  => $image->payee,
                'first'  => $best['name'] . ' (' . round($best['score'], 1) . '%)',
                'second' => $second['name'] . ' (' . round($second['score'], 1) . '%)',
            ]);

            return null;
        }

        return $this->apply($image, [
            'source'    => 'fuzzy',
            'user_id'   => $best['type'] === 'user' ? $best['id'] : null,
            'vendor_id' => $best['type'] === 'vendor' ? $best['id'] : null,
            'score'     => round($best['score'], 1),
        ]);
    }

    private function apply(CheckImage $image, array $result): array
    {
        $image->update([
            'payee_user_id'   => $result['user_id'],
            'payee_vendor_id' => $result['vendor_id'],
        ]);

        Log::channel('check_images')->info('Check image payee resolved', [
            'image' => $image->image_filename,
            'payee' => $image->payee,
        ] + $result);

        return $result;
    }

    /**
     * Company members and the company's vendor list.
     *
     * @return Collection<int, array{type: string, id: int, name: string}>
     */
    private function candidates(int $belongsToVendorId): Collection
    {
        return $this->candidateCache[$belongsToVendorId] ??= collect()
            ->concat(
                User::query()
                    ->join('user_vendor', 'user_vendor.user_id', '=', 'users.id')
                    ->where('user_vendor.vendor_id', $belongsToVendorId)
                    ->get(['users.id', 'users.first_name', 'users.last_name'])
                    ->unique('id')
                    ->map(fn (User $user) => [
                        'type' => 'user',
                        'id'   => (int) $user->id,
                        'name' => trim($user->first_name . ' ' . $user->last_name),
                    ])
            )
            ->concat(
                Vendor::withoutGlobalScopes()
                    ->join('vendors_vendor as vv', 'vv.vendor_id', '=', 'vendors.id')
                    ->where('vv.belongs_to_vendor_id', $belongsToVendorId)
                    ->get(['vendors.id', 'vendors.business_name'])
                    ->unique('id')
                    ->map(fn (Vendor $vendor) => [
                        'type' => 'vendor',
                        'id'   => (int) $vendor->id,
                        'name' => (string) $vendor->business_name,
                    ])
            )
            ->filter(fn (array $candidate) => $candidate['name'] !== '')
            ->values();
    }

    /**
     * Unicode-safe normalize (same as the receipts vendor matcher).
     */
    private function normalize(?string $value): string
    {
        $value = mb_strtolower((string) $value, 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $value) ?? '';

        return preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    }
}
