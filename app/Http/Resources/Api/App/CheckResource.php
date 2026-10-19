<?php

namespace App\Http\Resources\Api\App;

use App\Models\CheckImage;
use App\Services\Checks\AppCheckBreakdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A check as the app's Checks tab shows it. With withDetail() it adds the
 * images and the expenses/timesheets breakdown the detail screen needs
 * beyond the list row — see CheckController::show() and
 * App\Services\Checks\AppCheckBreakdown.
 *
 * `status`/`status_color` are the same Check::status()/statusColor()
 * accessors the web's checks table badge reads (Complete/Missing
 * Transactions/No Transactions), not re-derived here.
 *
 * @mixin \App\Models\Check
 */
class CheckResource extends JsonResource
{
    protected bool $detail = false;

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'date' => $this->date?->toDateString(),
            'check_number' => $this->check_number,
            'check_type' => $this->check_type,
            'payment_label' => $this->payment_label,
            'bank' => $this->resource->bank_account?->getNameAndType(),
            'payee' => [
                'label' => $this->owner,
                'web_path' => $this->webPathFor($this->payeeUrl()),
            ],
            'status' => [
                'label' => $this->status,
                'color' => $this->statusColor,
            ],
            'web_path' => '/checks/'.$this->id,
        ];

        if (! $this->detail) {
            return $row;
        }

        $breakdown = app(AppCheckBreakdown::class);

        return $row + [
            'images' => $this->imagesArray(),
            'expenses' => $breakdown->expenses($this->resource),
            'timesheets' => $breakdown->timesheets($this->resource),
        ];
    }

    /**
     * The web's payeeUrl()/owner() point at vendors.show or users.show — the
     * app has no native screen for either yet, so this is only used for the
     * "Open in Hive" link, never a route the app itself can push to.
     */
    private function webPathFor(?string $absoluteOrRelativeRoute): ?string
    {
        if (! $absoluteOrRelativeRoute) {
            return null;
        }

        return '/'.ltrim(parse_url($absoluteOrRelativeRoute, PHP_URL_PATH) ?? '', '/');
    }

    /**
     * Scanned check images, each as a session-less signed URL (see
     * routes/web.php's checks.image.signed, mirroring
     * projects.timelapse.frame.signed) so the app can show them without a
     * web session or cookie.
     *
     * @return list<array{id: int, url: string, check_date: ?string}>
     */
    private function imagesArray(): array
    {
        return $this->resource->checkImages()
            ->orderBy('check_date')
            ->get()
            ->filter(fn (CheckImage $image) => (bool) $image->image_filename)
            ->map(fn (CheckImage $image) => [
                'id' => $image->id,
                'url' => URL::temporarySignedRoute(
                    'checks.image.signed',
                    now()->addHours(24),
                    ['image' => $image->id]
                ),
                'check_date' => $image->check_date?->toDateString(),
            ])
            ->values()
            ->all();
    }
}
