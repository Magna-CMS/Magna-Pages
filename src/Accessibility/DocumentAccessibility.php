<?php

declare(strict_types=1);

namespace Magna\Pages\Accessibility;

use Magna\Blocks\PageTree;
use Magna\Pages\Themes\ThemeTokens;

/**
 * Accessibility checker v1 (docs/magna-pages/08-BUILD-PHASES.md item C7):
 * walks a block document and reports what an editor can actually fix —
 * missing image alt text, broken heading order, vague link labels — plus
 * one theme-level check (text/surface contrast from the active tokens).
 *
 * Advisory by design: findings never block saving or publishing. The
 * checker exists to teach, not to gate — a hard gate on heuristics teaches
 * people to game the checker instead.
 */
class DocumentAccessibility
{
    /** Link/button labels that say nothing out of context (WCAG 2.4.4). */
    private const VAGUE_LABELS = ['click here', 'here', 'read more', 'learn more', 'more', 'link'];

    /** WCAG AA minimum contrast for normal text. */
    private const MIN_CONTRAST = 4.5;

    public function __construct(private readonly ThemeTokens $tokens) {}

    /**
     * @param  array<mixed, mixed>  $document
     * @return list<array{code: string, severity: string, nodeId: string|null, message: string}>
     */
    public function check(array $document): array
    {
        $findings = [];
        $h1Count = 0;
        $previousLevel = null;

        foreach (PageTree::fromArray($document)->sections as $section) {
            if ($section->isRef()) {
                continue;
            }
            foreach ($section->columns as $column) {
                foreach ($column->blocks as $block) {
                    $data = $block->data;

                    if ($block->block === 'image'
                        && ! empty($data['media_id'])
                        && trim((string) ($data['alt'] ?? '')) === ''
                    ) {
                        $findings[] = [
                            'code' => 'image-alt', 'severity' => 'error', 'nodeId' => $block->id,
                            'message' => 'Image has no alt text. Describe the image, or leave alt empty ONLY for purely decorative images.',
                        ];
                    }

                    if ($block->block === 'heading') {
                        $level = (int) substr(is_string($data['level'] ?? null) ? $data['level'] : 'h2', 1);
                        $level = $level >= 1 && $level <= 6 ? $level : 2;

                        if ($level === 1 && ++$h1Count > 1) {
                            $findings[] = [
                                'code' => 'multiple-h1', 'severity' => 'warning', 'nodeId' => $block->id,
                                'message' => 'More than one H1 on the page. Screen readers use H1 as the page landmark — keep one.',
                            ];
                        }
                        if ($previousLevel !== null && $level > $previousLevel + 1) {
                            $findings[] = [
                                'code' => 'heading-skip', 'severity' => 'warning', 'nodeId' => $block->id,
                                'message' => "Heading level jumps from h{$previousLevel} to h{$level}. Screen-reader users navigate by heading structure — do not skip levels.",
                            ];
                        }
                        $previousLevel = $level;
                    }

                    if ($block->block === 'button') {
                        $label = strtolower(trim((string) ($data['label'] ?? '')));
                        if ($label !== '' && in_array($label, self::VAGUE_LABELS, true)) {
                            $findings[] = [
                                'code' => 'vague-link', 'severity' => 'warning', 'nodeId' => $block->id,
                                'message' => "\"{$label}\" says nothing out of context. Name the destination: \"Read the pricing guide\", not \"read more\".",
                            ];
                        }
                    }
                }
            }
        }

        $contrast = $this->themeContrastFinding();
        if ($contrast !== null) {
            $findings[] = $contrast;
        }

        return $findings;
    }

    /**
     * Text-on-surface contrast from the active theme's effective tokens
     * (site overrides included — a Design-tab repaint can break contrast a
     * theme shipped correct). Non-hex values are skipped, never guessed.
     *
     * @return array{code: string, severity: string, nodeId: string|null, message: string}|null
     */
    private function themeContrastFinding(): ?array
    {
        $variables = $this->tokens->cssVariables();
        $text = $this->relativeLuminance($variables['--color-text'] ?? '');
        $surface = $this->relativeLuminance($variables['--color-surface'] ?? '');
        if ($text === null || $surface === null) {
            return null;
        }

        $ratio = (max($text, $surface) + 0.05) / (min($text, $surface) + 0.05);
        if ($ratio >= self::MIN_CONTRAST) {
            return null;
        }

        return [
            'code' => 'token-contrast', 'severity' => 'error', 'nodeId' => null,
            'message' => sprintf(
                'Theme text on surface contrast is %.1f:1 — WCAG AA needs at least 4.5:1. Adjust the text or surface token in the Design tab.',
                $ratio,
            ),
        ];
    }

    private function relativeLuminance(string $hex): ?float
    {
        if (preg_match('/^#([0-9a-f]{6})$/i', trim($hex), $m) !== 1) {
            return null;
        }

        $channels = [];
        foreach (str_split($m[1], 2) as $pair) {
            $c = hexdec($pair) / 255;
            $channels[] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
