<?php

declare(strict_types=1);

namespace Magna\Pages\Themes;

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

    public function __construct(private readonly ThemeManager $themes) {}

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
