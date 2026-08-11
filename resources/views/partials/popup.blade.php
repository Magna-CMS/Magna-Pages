{{--
    One popup overlay. Self-contained: the shared style/script pair ships
    once per page via @once. Dismissal is per-browser (localStorage keyed
    by slug) — a popup republished under a new slug shows again, which is
    the editor's lever for "show this to everyone once more".
--}}
@once
<style>
    .magna-popup { position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; background: rgba(15, 23, 42, 0.55); padding: 1rem; }
    .magna-popup[hidden] { display: none; }
    .magna-popup__panel { position: relative; background: var(--color-surface, #fff); color: var(--color-text, #111); border-radius: var(--radius, 0.5rem); max-width: 640px; width: 100%; max-height: 85vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4); }
    .magna-popup__close { position: absolute; top: 0.5rem; right: 0.5rem; border: 0; background: transparent; font-size: 1.5rem; line-height: 1; cursor: pointer; padding: 0.25rem 0.5rem; color: inherit; }
</style>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-magna-popup]').forEach(function (popup) {
            var key = 'magna-popup-dismissed:' + popup.getAttribute('data-magna-popup');
            var dismissed = false;
            try { dismissed = window.localStorage.getItem(key) === '1'; } catch (e) {}
            if (dismissed) { return; }
            popup.hidden = false;
            popup.querySelectorAll('[data-magna-popup-close]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    popup.hidden = true;
                    try { window.localStorage.setItem(key, '1'); } catch (e) {}
                });
            });
        });
    });
</script>
@endonce

<div class="magna-popup" data-magna-popup="{{ $slug }}" role="dialog" aria-label="{{ $title !== '' ? $title : 'Announcement' }}" hidden>
    <div class="magna-popup__panel">
        <button class="magna-popup__close" type="button" aria-label="Close" data-magna-popup-close>&times;</button>
        {!! $sectionsHtml !!}
    </div>
</div>
