<?php

declare(strict_types=1);

namespace Magna\Pages\Themes;

use Illuminate\View\Factory as ViewFactory;
use InvalidArgumentException;
use Magna\Themes\ThemeManager;
use Magna\Themes\ThemeManifest;
use Throwable;

/**
 * The restricted-view audit behind magna:theme:check (§C8): a theme is
 * DATA — templates and tokens — and its views must stay inside the layout
 * contract instead of being a PHP execution vehicle. The store's "checked"
 * gate runs exactly this audit; a full compile-time sandbox (executing
 * themes with no PHP capability at all) is future work this audit's rules
 * are the specification for.
 *
 * Errors are constructs a theme may never ship; warnings are contract
 * drift worth a look.
 */
class ThemeAuditor
{
    /** Raw-echo variables the LAYOUT CONTRACT itself requires printing. */
    private const RAW_ECHO_ALLOWLIST = ['tokensCss', 'headerPartHtml', 'footerPartHtml', 'popupsHtml', 'mainHtml', 'sectionsHtml'];

    /** Function names that have no business appearing anywhere in a view. */
    private const FORBIDDEN_CALLS = [
        'eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
        'file_get_contents', 'file_put_contents', 'fopen', 'unlink', 'rmdir',
        'require', 'require_once', 'include', 'include_once', 'base64_decode',
    ];

    /**
     * Query and container constructs, pattern => finding. "Views receive
     * data; they never query" has been the contract's first rule since the
     * contract existed — these patterns are the audit actually saying so,
     * because the cheap route around a value the payload lacks is a view
     * that fetches it itself, and that route used to pass this audit
     * clean. `app()->getLocale()` alone stays allowed: the shipped themes'
     * documented idiom for the lang attribute, and it reads request state
     * rather than resolving a service.
     */
    private const FORBIDDEN_QUERY_PATTERNS = [
        '/(?<![@\w$>])Entry\s*::\s*type\s*\(/i' => 'Entry::type() in a view — views receive data; they never query. Resolve it on the payload (e.g. the pageUrl closure) instead.',
        '/::\s*query\s*\(/i' => '::query() in a view — views receive data; they never query.',
        '/(?<![@\w$>])DB\s*::/' => 'DB:: in a view — views receive data; they never query.',
        '/(?<![@\w$>])resolve\s*\(/i' => 'resolve() in a view — views receive data; they never pull from the container.',
        '/(?<![@\w$>])app\s*\(\s*[^)\s]/i' => 'app(...) in a view — views receive data; they never pull from the container.',
        '/(?<![@\w$>])app\s*\(\s*\)\s*->\s*(?!getLocale\b)\w+/i' => 'app()-> in a view — only app()->getLocale() (the lang-attribute idiom) is allowed; views never pull from the container.',
    ];

    public function __construct(
        private readonly ThemeManager $themes,
        private readonly ViewFactory $views,
    ) {}

    /**
     * @return list<array{level: string, file: string, message: string}>
     */
    public function audit(string $themeName): array
    {
        $findings = [];
        $base = $this->themes->pathFor($themeName);

        try {
            $manifest = ThemeManifest::loadFromFile($base.'/'.ThemeManifest::FILENAME);
        } catch (Throwable $e) {
            return [['level' => 'error', 'file' => ThemeManifest::FILENAME, 'message' => $e->getMessage()]];
        }

        $this->auditTokens($base, $findings);

        foreach ($this->viewFiles($base.'/views') as $file) {
            $this->auditView($base, $file, $manifest, $findings);
        }

        if (! $manifest->isAddon() && ! is_file($base.'/views/system/layout.blade.php')) {
            $findings[] = ['level' => 'warning', 'file' => 'views/system/layout.blade.php',
                'message' => 'No layout shell — pages render through the built-in fallback shell.'];
        }

        return $findings;
    }

    /** @param list<array{level: string, file: string, message: string}> $findings */
    private function auditTokens(string $base, array &$findings): void
    {
        $tokensFile = $base.'/tokens.json';
        if (! is_file($tokensFile)) {
            return;
        }

        $decoded = json_decode((string) file_get_contents($tokensFile), true);
        if (! is_array($decoded)) {
            $findings[] = ['level' => 'error', 'file' => 'tokens.json', 'message' => 'Not valid JSON.'];

            return;
        }

        foreach ($decoded as $category => $tokens) {
            if (! is_array($tokens)) {
                continue;
            }
            foreach ($tokens as $key => $definition) {
                $value = is_array($definition) ? ($definition['value'] ?? null) : $definition;
                if ((is_string($value) || is_numeric($value))
                    && (str_contains((string) $value, ';') || str_contains((string) $value, '('))
                ) {
                    $findings[] = ['level' => 'error', 'file' => 'tokens.json',
                        'message' => "Token {$category}.{$key} contains ';' or '(' — refused by the injection filter at render, so it will never apply."];
                }
            }
        }
    }

    /**
     * @param  list<array{level: string, file: string, message: string}>  $findings
     */
    private function auditView(string $base, string $file, ThemeManifest $manifest, array &$findings): void
    {
        $relative = str_replace('\\', '/', substr($file, strlen($base) + 1));
        $source = (string) file_get_contents($file);

        if ($manifest->isAddon() && ! str_starts_with($relative, 'views/blocks/')) {
            $findings[] = ['level' => 'error', 'file' => $relative,
                'message' => 'Addons may only ship block views — layouts and system views belong to the host theme.'];
        }

        // Raw <?php escapes Blade entirely — refused. @php stays available
        // (it is Blade's own local-variable idiom, and the shipped themes
        // use it for exactly that); what matters is WHAT gets called, and
        // the call scan below covers @php content like everything else.
        if (preg_match('/<\?(php|=)?/i', $source) === 1) {
            $findings[] = ['level' => 'error', 'file' => $relative,
                'message' => 'Raw <?php — theme views are templates, never code.'];
        }

        foreach (self::FORBIDDEN_CALLS as $call) {
            // (?<![@\w]) so @include()/@includeIf() — Blade directives — do
            // not trip the include() rule.
            if (preg_match('/(?<![@\w])'.preg_quote($call, '/').'\s*\(/i', $source) === 1) {
                $findings[] = ['level' => 'error', 'file' => $relative,
                    'message' => "Call to {$call}() — no place in a view."];
            }
        }

        foreach (self::FORBIDDEN_QUERY_PATTERNS as $pattern => $message) {
            if (preg_match($pattern, $source) === 1) {
                $findings[] = ['level' => 'error', 'file' => $relative, 'message' => $message];
            }
        }

        if (preg_match_all('/\{!!(.*?)!!\}/s', $source, $matches) > 0) {
            foreach ($matches[1] as $expression) {
                $trimmed = trim($expression);
                $allowed = false;
                foreach (self::RAW_ECHO_ALLOWLIST as $variable) {
                    if ($trimmed === '$'.$variable) {
                        $allowed = true;
                        break;
                    }
                }
                // Server-sanitized block data is the other legitimate raw
                // echo: `$block['_resolved'][…]`, optionally with an
                // escaped fallback — the sanitized-richtext idiom.
                if (! $allowed && preg_match(
                    '/^\$block\[\'_resolved\'\]\[\'[a-z0-9_]+\'\](\s*\?\?\s*e\(.+\))?$/',
                    $trimmed,
                ) === 1) {
                    $allowed = true;
                }
                if (! $allowed) {
                    $findings[] = ['level' => 'error', 'file' => $relative,
                        'message' => "Raw echo {!! {$trimmed} !!} — only the layout-contract HTML variables ("
                            .implode(', ', array_map(fn (string $v): string => '$'.$v, self::RAW_ECHO_ALLOWLIST))
                            .') may print unescaped.'];
                }
            }
        }

        if (str_starts_with($relative, 'views/blocks/') && preg_match('/<script\b/i', $source) === 1) {
            $findings[] = ['level' => 'error', 'file' => $relative,
                'message' => 'A <script> element in a block view — interactivity ships through curated mechanisms, not theme script.'];
        }

        if (str_starts_with($relative, 'views/blocks/')) {
            $this->auditOverride($relative, $source, $findings);
        }
    }

    /**
     * What an overriding block view DROPS from the view it replaces.
     *
     * A theme override is a whole-view replacement, so anything the default
     * view carried inline is simply gone — and the rule above forbids
     * putting a <script> back. That combination has already shipped a
     * permanently invisible control: the scheme toggle's un-hide, click
     * handler and stored choice all lived in its view's @once script, and a
     * theme that overrode the view purely to restyle the button lost the
     * button. Nothing said so, because the failure is not a styling
     * regression anybody sees — the control is absent, not wrong.
     *
     * Behaviour now ships separately (block-behaviour/, outside the theme
     * chain), which is the real fix. This stays because it catches the same
     * class everywhere else: a core or plugin block view that still carries
     * inline script, and — the one way the repaired toggle can still be
     * broken — an override that drops the `data-magna-*` hook the behaviour
     * binds to.
     *
     * Warnings, not errors: dropping either may be exactly what the theme
     * means. What it may not do is drop them without being told.
     *
     * @param  list<array{level: string, file: string, message: string}>  $findings
     */
    private function auditOverride(string $relative, string $source, array &$findings): void
    {
        $handle = basename($relative, '.blade.php');
        $default = $this->defaultViewSource($handle);

        if ($default === null) {
            return;
        }

        // Both sides compared without comments: the default view for
        // scheme-toggle NAMES its optional `data-magna-label-to-*` attributes
        // in a comment explaining the contract, and reading that as markup
        // warned every override that declined an optional attribute. The same
        // stripping on the override keeps a hook mentioned only in ITS
        // comments from counting as kept.
        $default = self::withoutComments($default);
        $source = self::withoutComments($source);

        /*
         * Worded from what is TRUE NOW, and silent once it stops being true.
         *
         * This warning used to end "Ask for it to move to a block-behaviour
         * view, which an override cannot drop" — a destination named in the
         * present tense. A theme author read it as a statement that the
         * behaviour HAD moved, deleted their own working wiring on the
         * strength of it, and shipped a page whose light/dark button never
         * appeared: the markup renders `hidden` and nothing was left to lift
         * it. The sentence invited exactly that.
         *
         * So the question is asked of the installation rather than of
         * intentions. Where core already ships a behaviour view for this
         * handle, an override genuinely cannot drop the behaviour and there
         * is nothing to warn about. Where it does not, the theme must carry
         * the behaviour itself, which is what the message now says. The
         * warning disappears on its own the day the handle gains a behaviour
         * view, so it cannot go stale in either direction — the only way a
         * warning about a half-finished migration can be trusted.
         */
        if (preg_match('/<script\b/i', $default) === 1
            && preg_match('/<script\b/i', $source) !== 1
            && ! $this->hasBehaviourView($handle)) {
            $findings[] = ['level' => 'warning', 'file' => $relative,
                'message' => "Overrides {$handle}, and core still ships that block's behaviour inside the view you replaced, so this override drops it — a block view may not carry script of its own. Until that behaviour moves to a block-behaviour view your theme has to carry it; a guard that returns early when the button is already wired keeps it correct afterwards too."];
        }

        preg_match_all('/data-magna-[a-z0-9-]+/i', $default, $matches);

        foreach (array_unique(array_map('strtolower', $matches[0])) as $hook) {
            if (! str_contains(strtolower($source), $hook)) {
                $findings[] = ['level' => 'warning', 'file' => $relative,
                    'message' => "Overrides {$handle} without its `{$hook}` attribute — that is the hook the block's behaviour binds to, so whatever it drives will not run on this markup."];
            }
        }
    }

    /**
     * Blade source with `{{-- --}}` and PHP block comments removed, so prose
     * ABOUT an attribute or a script is never mistaken for the thing itself.
     * Line comments are left alone: `//` inside a URL is far more common in
     * a view than a hook name mentioned in one.
     */
    private static function withoutComments(string $source): string
    {
        $stripped = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        return (string) preg_replace('!/\*.*?\*/!s', '', $stripped);
    }

    /**
     * The source of the default view this override replaces, or null when
     * the handle has none.
     *
     * The `magna::` namespace is asked directly rather than through
     * ThemeViewResolver: the resolver's job is to let a theme win, and the
     * question here is precisely what the theme is winning AGAINST. The
     * resolver also registers namespaces as a side effect, which an audit
     * of a theme that is not even active has no business doing.
     */
    /**
     * Whether core ships a behaviour view for this handle — the sibling the
     * renderer includes beside whichever markup view won, which an override
     * therefore cannot drop.
     *
     * Asked of the `magna::` namespace alone, like defaultViewSource(): the
     * question is what CORE provides, and a theme cannot answer it.
     */
    private function hasBehaviourView(string $handle): bool
    {
        try {
            $this->views->getFinder()->find('magna::block-behaviour.'.$handle);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private function defaultViewSource(string $handle): ?string
    {
        try {
            $path = $this->views->getFinder()->find('magna::blocks.'.$handle);
        } catch (InvalidArgumentException) {
            return null;
        }

        $source = file_get_contents($path);

        return is_string($source) ? $source : null;
    }

    /** @return list<string> */
    private function viewFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
