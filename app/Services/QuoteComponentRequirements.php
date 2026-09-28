<?php

namespace App\Services;

use App\Models\QuotePreset;

/** Liga escolhas críticas do wizard à ficha técnica sem presumir rendimento. */
final class QuoteComponentRequirements
{
    public function missing(QuotePreset $product, array $answers, array $selectedCodes): array
    {
        $required = match ($product->code) {
            'sign-facade' => $this->facade($answers),
            'product-frontlight-banner' => $this->banner($answers),
            'uniform-polo' => $this->polo($answers),
            default => [],
        };

        return array_values(array_diff(array_unique($required), $selectedCodes));
    }

    private function facade(array $answers): array
    {
        $required = ['material-acm-'.($answers['acm_thickness'] ?? ''), 'material-metal-tube-'.($answers['structure_tube'] ?? ''), 'process-welding'];
        $required = [...$required, ...match ($answers['lighting'] ?? 'none') {
            'led-12v' => ['material-led-module', 'material-led-power-supply-12v'],
            'led-24v' => ['material-led-module', 'material-led-power-supply-24v'],
            'projector' => ['material-projector'], 'neon' => ['material-neon-led'], default => [],
        }];
        foreach ([
            'anti_rust_paint' => 'finish-structural-paint', 'requires_munk' => 'third-party-munk',
            'requires_scaffold' => 'third-party-scaffold', 'height_installation' => 'process-installation-height',
            'cnc_outsourced' => 'third-party-cnc', 'galvanizing_outsourced' => 'third-party-galvanizing',
        ] as $field => $code) {
            if (($answers[$field] ?? false) === true) $required[] = $code;
        }
        return $required;
    }

    private function banner(array $answers): array
    {
        return [match ($answers['material'] ?? '') {
            'frontlight-440g' => 'material-frontlight-440g', 'frontlight-500g' => 'material-frontlight-500g',
            'backlight' => 'material-backlight', 'mesh' => 'material-mesh',
            'sublimation-fabric' => 'material-sublimation-fabric', default => '',
        }, 'process-large-format-print'];
    }

    private function polo(array $answers): array
    {
        $fabric = match ($answers['fabric'] ?? '') {
            'piquet' => 'material-piquet', 'dryfit' => 'material-dryfit',
            'cotton' => 'material-cotton-menegotti', default => '',
        };
        $process = match ($answers['personalization'] ?? '') {
            'silk-screen' => ['process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink'],
            'embroidery' => ['process-computerized-embroidery', 'third-party-embroidery-matrix'],
            'dtf' => ['process-dtf-print-size', 'material-textile-dtf-transfer'],
            'textile-vinyl' => ['process-textile-vinyl', 'material-textile-vinyl'], default => [],
        };
        return [$fabric, ...$process];
    }
}
