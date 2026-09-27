<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    {{-- Microsoft Clarity — heatmaps and session recordings of the PUBLIC
         marketing pages only (this is the guest layout, the one this app's
         welcome/login/registration pages render with — the logged-in
         app's own layout, components/layouts/app.blade.php, never gets
         this block). head.blade.php's own hardcoded production tag stays
         untouched: that one tracks the signed-in app itself; this is a
         separate, independent tag for the marketing site, driven by the
         id saved from the SEO screen's Connect Services modal.

         Pushed BEFORE the @include below on purpose: this partial's
         @stack('head-appended') renders while head.blade.php executes, so
         whatever this view has pushed by then is what shows up — @push
         writes to the shared view Factory's stack storage immediately,
         same as an ordinary PHP statement earlier in this file.

         Microsoft's snippet exactly as documented, not deferred: their
         installation check looks for this exact form (double quotes,
         type="text/javascript", in the head) — see ss-systems'
         "Clarity in the head, in Microsoft's exact form" commit, which
         found a requestIdleCallback-deferred copy sent data but still
         reported as not installed. The tag itself is async, so it never
         blocks the page. data-navigate-once so a wire:navigate swap never
         loads it twice; the whole block is gone when no project id is
         configured (App\Support\Seo\ClaritySettings::projectId(), the id
         saved from the SEO screen's Connect Services modal, env only as a
         transition fallback). --}}
    @php $clarityProjectId = app(\App\Support\Seo\ClaritySettings::class)->projectId(); @endphp
    @if ($clarityProjectId)
        @push('head-appended')
            <script type="text/javascript" data-navigate-once>
                (function(c,l,a,r,i,t,y){
                    c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                    t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                    y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
                })(window, document, "clarity", "script", "{{ $clarityProjectId }}");
            </script>
        @endpush
    @endif
    @include('components.layouts.head')

    <body class="{{ $bodyClass ?? 'lg:bg-gradient-to-r lg:from-white lg:from-50% lg:to-indigo-900 lg:to-50%' }}">
        {{-- Guests only need the timezone sync once they log in — and on
             CachePublicPage-cached pages a rendered Livewire component would
             carry the FIRST visitor's snapshot + CSRF token to everyone else. --}}
        @auth
            <livewire:browser-timezone />
        @endauth
        <div class="min-h-screen font-sans antialiased text-gray-900">
            <div data-page-fade class="transition-opacity duration-100">
                {{ $slot }}
            </div>
        </div>

        
        <flux:toast />

        @stack('scripts')

        {{-- ss-systems/platform-kit's Pulse beacon — first-party, anonymous
             usage telemetry behind the central admin's "Site Pulse" card (see
             the kit's docs/PULSE.md and App\Providers\AppServiceProvider's
             Recorder/SnapshotBuilder/BeaconController bindings). This is the
             shared PUBLIC (unauthenticated) layout — the /{locale}/welcome
             marketing pages and legal pages render with it, and so do
             login/registration/cant-login and the vendor-availability
             response page. It is never included in
             components/layouts/app.blade.php, the logged-in app's layout —
             so Pulse never measures a signed-in contractor's own use of the
             app. Gets an automatic `page` event per view plus delegated
             tel:/mailto: click tracking and capped JS-error capture for
             free; the `signup` feature event below is this site's own
             addition on top of that. --}}
        {!! \SsSystems\Platform\Pulse\BeaconScript::render('/pulse', ['fn' => 'ssPulse']) !!}
        <script>
            // A click on a marketing page's sign-up call to action (any link
            // to route('registration')) counts as a `signup` feature event.
            // One delegated listener here covers every current AND future
            // CTA on every marketing/legal page rendered through this
            // layout, rather than an onclick on each button.
            (function () {
                var registrationPath = @json(parse_url(route('registration'), PHP_URL_PATH));

                document.addEventListener('click', function (e) {
                    var link = e.target.closest('a[href]');
                    if (!link) return;

                    try {
                        if (new URL(link.href, window.location.origin).pathname === registrationPath) {
                            window.ssPulse && window.ssPulse('signup');
                        }
                    } catch (x) {}
                });
            })();
        </script>

        @fluxScripts
        {{-- Render Livewire's JS (Alpine included) in the template rather than
             relying on post-request auto-injection: CachePublicPage captures
             the body BEFORE that injection runs, so cache-hit visitors were
             served pages with no Alpine at all — dead nav dropdown, language
             switcher, and marketing interactions. An explicit render is part
             of the cached body; the auto-injector sees it and skips. --}}
        @livewireScripts
    </body>
</html>
