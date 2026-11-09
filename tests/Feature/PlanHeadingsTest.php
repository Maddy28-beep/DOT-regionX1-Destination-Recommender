<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Plan My Trip used to show every heading, label and answer in the same dark green at nearly the same size, which a reader
 * found confusing. plan-headings.css gives each level its own look; these keep that order from collapsing back into one.
 */
class PlanHeadingsTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(public_path('css/plan-headings.css'));
    }

    /** @return array<string, string> the --ph-* colours the sheet defines */
    private function colours(): array
    {
        preg_match_all('/--ph-([a-z]+):\s*(#[0-9a-f]{6})/i', $this->css(), $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($x) => [$x[1] => strtolower($x[2])])->all();
    }

    private function luminance(string $hex): float
    {
        $c = array_map(fn ($v) => hexdec($v) / 255, str_split(ltrim($hex, '#'), 2));
        $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function contrast(string $a, string $b): float
    {
        [$hi, $lo] = [max($this->luminance($a), $this->luminance($b)), min($this->luminance($a), $this->luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    public function test_the_plan_page_loads_the_headings_sheet_after_the_layout_sheets(): void
    {
        $html = file_get_contents(resource_path('views/plan/preferences.blade.php'));

        $layout = strpos($html, 'css/plan-trip-layout.css');
        $headings = strpos($html, 'css/plan-headings.css');

        $this->assertNotFalse($headings, 'plan-headings.css is linked');
        $this->assertGreaterThan($layout, $headings, 'it loads last, so it wins over the older sheets');
    }

    public function test_each_level_has_its_own_look_and_the_step_title_and_labels_are_not_green(): void
    {
        $css = $this->css();

        // Level 1: the step title is near-black, not the green it used to share with everything.
        $this->assertMatchesRegularExpression('/\.plan-wizard-step > h2 \{[^}]*color: var\(--ph-ink\)[^}]*font-weight: 800/s', $css);
        // Level 2: a section heading is bold and carries a short green bar as its marker.
        $this->assertMatchesRegularExpression('/\.plan-wizard \.pc-block__head h3,[^{]*\{[^}]*color: var\(--ph-heading\)[^}]*border-left: 4px solid var\(--primary\)/s', $css);
        // Level 3: a field label is plain dark grey.
        $this->assertMatchesRegularExpression('/\.plan-wizard \.pc-field > label,[^{]*\{[^}]*color: var\(--ph-label\)/s', $css);
        // Hints are quieter than every heading.
        $this->assertMatchesRegularExpression('/\.plan-wizard \.pc-help,[^{]*\{[^}]*color: var\(--ph-muted\)/s', $css);

        $this->assertStringNotContainsString('var(--primary-dark)', preg_replace('/\/\*.*?\*\//s', '', $css), 'no heading level is the old dark green');
    }

    public function test_the_four_text_colours_are_distinct_and_readable_on_white_and_on_the_page_background(): void
    {
        $c = $this->colours();

        $this->assertSame(['ink', 'heading', 'label', 'muted'], array_keys($c));
        $this->assertCount(4, array_unique($c), 'four different greys');

        foreach (['white' => '#ffffff', 'page' => '#f5f6f3'] as $name => $background) {
            foreach ($c as $role => $hex) {
                $this->assertGreaterThanOrEqual(4.5, $this->contrast($hex, $background), "{$role} {$hex} on {$name}");
            }
        }

        // The order is real: each level is lighter than the one above it (a higher luminance is a lighter colour).
        $this->assertLessThan($this->luminance($c['heading']), $this->luminance($c['ink']));
        $this->assertLessThan($this->luminance($c['label']), $this->luminance($c['heading']));
        $this->assertLessThan($this->luminance($c['muted']), $this->luminance($c['label']));
    }

    public function test_it_changes_colour_weight_and_a_marker_only_never_the_layout(): void
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $this->css());

        // A layout property (not "line-height", which a letter before the dash rules out) would move things.
        $this->assertDoesNotMatchRegularExpression('/(^|[;{\s])(display|grid-template-[a-z]+|flex[a-z-]*|margin(-[a-z]+)?|height|min-height|position|gap)\s*:/', $css, 'this sheet only recolours and re-weights');
    }
}
