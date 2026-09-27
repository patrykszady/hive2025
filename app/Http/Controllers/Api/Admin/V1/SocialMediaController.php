<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * GET social-media, PUT social-media/urls — the same {data:{...}} shape
 * ss-systems' shared Social Media screen (App\Livewire\Admin\
 * SocialMediaPosts + social-media-posts.blade.php) already reads for
 * gsc/jpeterson-design/dawnsellshomes.
 *
 * This app posts NOTHING to social media itself: there is no Meta/X/
 * LinkedIn posting integration, so `configured.*` is always false and
 * `automation.items` is always empty. ss-systems/CLAUDE.md is explicit
 * that a payload with no `automation` key at all makes the screen say
 * "not available for this site" (that happened to jpeterson-design's SEO
 * screen for an unrelated missing key, and it reads as a bug) — so the key
 * IS always sent, just empty, which renders the "Automatic posting"
 * section as a quiet heading with nothing under it rather than that
 * warning.
 *
 * `subject: 'listings'` is borrowed from dawnsellshomes' realtor
 * adaptation of this same screen. Neither of the screen's two subject
 * modes ('projects', the default, or 'listings') is a perfect fit for a
 * software company with no posting pipeline at all: 'projects' would add
 * Google Business Profile/Yelp tiles and a "photos publish automatically"
 * line that is simply untrue here, so 'listings' is the closer fit — it
 * hides those two sections and gives us `listing_photos_note` to say
 * plainly that posting is not set up, calmly, in the roster's own words
 * rather than a project/photo one. The remaining cosmetic mismatch
 * ("Total Listings", "Remaining Listings") is a wart worth fixing on the
 * ss-systems side with a third, subject-neutral mode; noted rather than
 * hacked around here.
 *
 * No automation or posting endpoints (PUT social-media/automation/{p},
 * POST social-media/post) are declared: with every automation item empty
 * and every `configured` flag false, ss-systems' screen never renders a
 * button that would call them (its automation loop skips any platform
 * missing from `automation.items`, and both the "Post Now" dropdown and
 * every per-platform Post Now button are gated on `configured`) — see
 * that component's render(). Adding them back is a one-file change
 * (routes/api-admin/social-media.php + this controller) the day this site
 * actually connects a posting platform.
 */
class SocialMediaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'subject' => 'listings',
                'stats' => [
                    'total_eligible' => 0,
                    'remaining_instagram' => 0,
                    'posted_instagram' => 0,
                    'remaining_facebook' => 0,
                    'posted_facebook' => 0,
                ],
                'configured' => [
                    'instagram' => false,
                    'facebook' => false,
                    'google_business' => false,
                    'any' => false,
                ],
                'publishing_off' => [
                    'instagram' => false,
                    'facebook' => false,
                    'google_business' => false,
                ],
                'platforms' => $this->platformsPayload(),
                'automation' => [
                    'timezone' => null,
                    'items' => [],
                ],
                'listing_photos_note' => 'This site does not post to social media automatically. Use the links below to keep every profile current by hand.',
                'uploaded_posts' => [],
                'remaining_images' => [],
                'gbp_images' => [],
            ],
        ]);
    }

    /**
     * PUT social-media/urls — the profile roster, one address per platform
     * (config/social-platforms.php), stored as platform_settings
     * `socials.url.{key}` — the same keys the citation board reads
     * (App\Support\Citations\KnownListings). A blank clears the stored
     * address. A key outside the configured roster is refused outright
     * (422) rather than silently dropped, so a typo in a future client
     * update fails loudly instead of quietly doing nothing.
     */
    public function saveUrls(Request $request): JsonResponse
    {
        $platforms = array_keys((array) config('social-platforms', []));

        $data = $request->validate(['urls' => ['sometimes', 'array']]);
        $submitted = (array) ($data['urls'] ?? []);

        $unknown = array_diff(array_keys($submitted), $platforms);
        if ($unknown !== []) {
            return response()->json([
                'message' => 'Unknown platform: '.implode(', ', $unknown).'.',
                'errors' => ['urls' => ['Unknown platform: '.implode(', ', $unknown).'.']],
            ], 422);
        }

        $validated = Validator::make(
            $submitted,
            collect($platforms)->mapWithKeys(fn (string $p) => [$p => ['nullable', 'url', 'max:500']])->all(),
        )->validate();

        foreach ($platforms as $platform) {
            $url = trim((string) ($validated[$platform] ?? ''));
            PlatformSetting::put('socials.url.'.$platform, $url !== '' ? $url : null);
        }

        return response()->json(['data' => ['platforms' => $this->platformsPayload()]]);
    }

    /**
     * The roster in the shape the admin's Social Media screen renders
     * ({key, label, icon, placeholder, url, posts}). No footer/config
     * link exists to fall back to (see config/social-platforms.php's
     * docblock), so a platform with nothing saved is simply blank.
     *
     * @return list<array<string, mixed>>
     */
    protected function platformsPayload(): array
    {
        return collect((array) config('social-platforms', []))
            ->map(fn (array $platform, string $key) => [
                'key' => $key,
                'label' => (string) ($platform['label'] ?? ucfirst($key)),
                'icon' => null,
                'placeholder' => (string) ($platform['placeholder'] ?? 'https://…'),
                'url' => (string) (PlatformSetting::get('socials.url.'.$key) ?? ''),
                'posts' => (bool) ($platform['posts'] ?? false),
            ])
            ->values()
            ->all();
    }
}
