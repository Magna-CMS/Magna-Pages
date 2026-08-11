{{--
    Consent registry output. `necessary` scripts load normally. Consent
    categories render INERT (no src attribute at all); the runtime below
    activates them only for categories the visitor accepted, and the
    banner shows until a choice is stored. Everything is per-browser
    (localStorage) — nothing here varies the cached HTML.
--}}
@foreach($integrations as $integration)
    @if($integration['category'] === 'necessary')
        <script src="{{ $integration['src'] }}" defer></script>
    @else
        <script type="text/plain"
                data-magna-integration="{{ $integration['handle'] }}"
                data-magna-consent="{{ $integration['category'] }}"
                data-src="{{ $integration['src'] }}"></script>
    @endif
@endforeach

@if($needsConsent)
    <div class="magna-consent" data-magna-consent-banner hidden>
        <p class="magna-consent__text">
            This site uses optional services for analytics and marketing.
            Choose what may load.
        </p>
        <div class="magna-consent__actions">
            <button type="button" data-magna-consent-accept="all">Accept all</button>
            <button type="button" data-magna-consent-accept="necessary">Only necessary</button>
        </div>
    </div>

    <style>
        .magna-consent { position: fixed; inset-inline: 0; bottom: 0; z-index: 9998; display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between; padding: 1rem clamp(1rem, 4vw, 2rem); background: var(--color-surface-alt, #1c1917); color: var(--color-text, #e7e5e4); box-shadow: 0 -10px 30px rgba(0,0,0,0.25); }
        .magna-consent[hidden] { display: none; }
        .magna-consent__text { margin: 0; font-size: 0.9rem; }
        .magna-consent__actions { display: flex; gap: 0.5rem; }
        .magna-consent__actions button { padding: 0.5rem 1rem; border: 1px solid currentColor; border-radius: var(--radius, 0.25rem); background: transparent; color: inherit; font: inherit; cursor: pointer; }
    </style>
    <script>
        (function () {
            var KEY = 'magna-consent';
            function activate(categories) {
                document.querySelectorAll('script[data-magna-consent]').forEach(function (inert) {
                    if (categories.indexOf(inert.getAttribute('data-magna-consent')) === -1) { return; }
                    var live = document.createElement('script');
                    live.src = inert.getAttribute('data-src');
                    live.defer = true;
                    inert.replaceWith(live);
                });
            }
            var stored = null;
            try { stored = window.localStorage.getItem(KEY); } catch (e) {}
            if (stored !== null) {
                activate(stored === 'all' ? ['analytics', 'marketing'] : []);
                return;
            }
            var banner = document.querySelector('[data-magna-consent-banner]');
            if (!banner) { return; }
            banner.hidden = false;
            banner.querySelectorAll('[data-magna-consent-accept]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var choice = button.getAttribute('data-magna-consent-accept');
                    try { window.localStorage.setItem(KEY, choice); } catch (e) {}
                    banner.hidden = true;
                    activate(choice === 'all' ? ['analytics', 'marketing'] : []);
                });
            });
        })();
    </script>
@endif
