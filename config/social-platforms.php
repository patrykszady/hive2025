<?php

/*
|--------------------------------------------------------------------------
| Where Hive itself can be found (2026-09-26)
|--------------------------------------------------------------------------
|
| The profile roster the central admin's Social Media screen shows and
| edits (PUT social-media/urls): one address per platform, stored as
| platform_settings `socials.url.{key}` (App\Models\PlatformSetting) — the
| same keys the citation board reads (App\Support\Citations\KnownListings)
| to know which directory listings are already live.
|
| This is a software company's roster, not a contractor's or a realtor's:
| the places a buyer or prospect looks a B2B product up, plus the two
| platforms with any account to actually post from. No profile link is
| published anywhere on the live site today (grepped resources/views and
| config — nothing found), so every starting value is empty rather than
| guessed.
|
| `posts` is honest, not a stub: this site posts nothing to social media
| itself (no Meta/X/LinkedIn API integration exists), so every entry is
| false and the admin's automation cards never appear — see
| SocialMediaController's docblock for how that stays calm rather than
| broken.
*/

return [
    'linkedin' => ['label' => 'LinkedIn (company page)', 'placeholder' => 'https://www.linkedin.com/company/…', 'posts' => false],
    'facebook' => ['label' => 'Facebook', 'placeholder' => 'https://www.facebook.com/…', 'posts' => false],
    'instagram' => ['label' => 'Instagram', 'placeholder' => 'https://www.instagram.com/…', 'posts' => false],
    'x' => ['label' => 'X (Twitter)', 'placeholder' => 'https://x.com/…', 'posts' => false],
    'youtube' => ['label' => 'YouTube', 'placeholder' => 'https://www.youtube.com/@…', 'posts' => false],
    'tiktok' => ['label' => 'TikTok', 'placeholder' => 'https://www.tiktok.com/@…', 'posts' => false],
];
