<?php

namespace App\Services;

use App\Models\QuotePreset;
use Illuminate\Validation\ValidationException;

/** Liga escolhas críticas do wizard à ficha técnica sem presumir rendimento. */
final class QuoteComponentRequirements
{
    public function missing(QuotePreset $product, array $answers, array $selectedCodes): array
    {
        $required = match ($product->code) {
            'sign-facade' => $this->facade($answers),
            'product-frontlight-banner' => $this->banner($answers),
            'product-printed-adhesive' => $this->adhesive($answers),
            'uniform-polo' => $this->polo($answers),
            'product-basic-tshirt' => $this->basicTshirt($answers),
            'print-business-card' => $this->businessCard($answers),
            'product-presentation-folder' => $this->presentationFolder($answers),
            'product-flyer' => $this->printedStock($answers, ['process-sheet-print', 'process-cutting']),
            'product-folder-print' => $this->printedFolder($answers),
            'product-envelopes', 'product-letterhead' => $this->printedStock($answers, $product->code === 'product-envelopes'
                ? ['process-sheet-print', 'process-die-cut-crease']
                : ['process-sheet-print']),
            'product-carbonless-pads' => $this->carbonlessPads($answers),
            'product-agenda-notebook' => $this->boundPrint($answers, true),
            'product-menu' => $this->menu($answers),
            'product-banner' => $this->simpleBanner($answers),
            'product-mug' => $this->mug($answers),
            'product-labels-roll-sheet' => $this->labels($answers),
            default => [],
        };

        return array_values(array_diff(array_unique($required), $selectedCodes));
    }

    /** Impede cobrar dois substratos mutuamente exclusivos para uma única escolha do wizard. */
    public function conflicting(QuotePreset $product, array $answers, array $selectedCodes): array
    {
        $choiceGroup = match ($product->code) {
            'product-mug' => ['material-gift-mug-ceramic', 'material-gift-mug-polymer', 'process-sublimation', 'process-gift-printing'],
            'product-labels-roll-sheet' => ['material-label-roll-stock', 'material-label-sheet-stock'],
            'product-frontlight-banner' => ['material-frontlight-440g', 'material-frontlight-500g', 'material-backlight', 'material-mesh', 'material-sublimation-fabric'],
            'print-business-card' => ['material-cardstock-250g', 'material-cardstock-300g', 'material-card-pvc-075'],
            'product-presentation-folder', 'product-flyer', 'product-folder-print' => ['material-cardstock-250g', 'material-couche-300g', 'material-offset-90g'],
            'product-envelopes', 'product-letterhead' => ['material-offset-90g'],
            'product-carbonless-pads' => ['material-carbonless-2-part', 'material-carbonless-3-part'],
            default => [],
        };
        if ($choiceGroup === []) return [];

        $requiredChoice = array_intersect($choiceGroup, $this->choiceRequirements($product->code, $answers));
        $selectedChoices = array_intersect($choiceGroup, $selectedCodes);
        return array_values(array_diff($selectedChoices, $requiredChoice));
    }

    /** Extrai apenas o insumo que representa a escolha unitária de substrato. */
    private function choiceRequirements(string $productCode, array $answers): array
    {
        return match ($productCode) {
            'product-mug' => $this->mug($answers),
            'product-labels-roll-sheet' => [$this->labels($answers)[0]],
            'product-frontlight-banner' => [$this->banner($answers)[0]],
            'print-business-card' => [$this->businessCard($answers)[0]],
            'product-presentation-folder' => [$this->presentationFolder($answers)[0]],
            'product-flyer', 'product-folder-print', 'product-envelopes', 'product-letterhead' => [$this->printedStock($answers, [])[0]],
            'product-carbonless-pads' => [$this->carbonlessPads($answers)[0]],
            default => [],
        };
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
            if ($this->isTrue($answers[$field] ?? false)) $required[] = $code;
        }
        return $required;
    }

    /** Vincula substrato, processo e acabamentos selecionados aos componentes precificáveis. */
    private function businessCard(array $answers): array
    {
        $selectedFinishes = (array) ($answers['finishes'] ?? []);
        if (in_array('matte', $selectedFinishes, true) && in_array('soft-touch', $selectedFinishes, true)) {
            throw ValidationException::withMessages(['answers.finishes' => 'Escolha laminação fosca ou soft touch, não as duas.']);
        }

        $stock = match ($answers['stock'] ?? '') {
            'couche-250g' => 'material-cardstock-250g',
            'couche-300g' => 'material-cardstock-300g',
            'pvc-075' => 'material-card-pvc-075',
            default => '',
        };
        $finishes = [];
        foreach ($selectedFinishes as $finish) {
            $code = match ($finish) {
                'matte', 'soft-touch' => 'finish-card-lamination',
                'uv-varnish' => 'finish-uv-varnish',
                'hot-stamping' => 'finish-hot-stamping',
                'rounded-corners' => 'finish-rounded-corners',
                default => '',
            };
            if ($code !== '') $finishes[] = $code;
        }
        if ($this->isTrue($answers['special_die'] ?? false)) $finishes[] = 'process-special-die';

        return [$stock, 'process-sheet-print', ...$finishes];
    }

    /** Uma pasta depende do estoque escolhido e dos acabamentos efetivamente marcados. */
    private function presentationFolder(array $answers): array
    {
        $stock = match ($answers['stock'] ?? '') {
            'couche-300g' => 'material-couche-300g',
            'offset-90g' => 'material-offset-90g',
            default => '',
        };
        if ($this->isTrue($answers['pocket_ear'] ?? false) && ! $this->isTrue($answers['pocket'] ?? false)) {
            throw ValidationException::withMessages(['answers.pocket_ear' => 'A orelha só pode ser incluída junto com a bolsa da pasta.']);
        }

        $required = [$stock, 'process-sheet-print'];
        if ($this->isTrue($answers['pocket'] ?? false)) $required[] = 'finish-folder-pocket';
        if ($this->isTrue($answers['die_cut'] ?? false)) $required[] = 'process-die-cut-crease';
        if ($this->isTrue($answers['lamination'] ?? false)) $required[] = 'finish-card-lamination';

        return $required;
    }

    private function banner(array $answers): array
    {
        $required = [match ($answers['material'] ?? '') {
            'frontlight-440g' => 'material-frontlight-440g', 'frontlight-500g' => 'material-frontlight-500g',
            'backlight' => 'material-backlight', 'mesh' => 'material-mesh',
            'sublimation-fabric' => 'material-sublimation-fabric', default => '',
        }, 'process-large-format-print'];
        foreach ((array) ($answers['finishing'] ?? []) as $finish) {
            if ($finish === 'hem-eyelets') $required[] = 'finish-banner-hem-eyelets';
            if ($finish === 'rods-cord') $required[] = 'finish-banner-rods-cord';
        }
        return $required;
    }

    /** Stocks de papel declarados no wizard precisam corresponder ao componente precificado. */
    private function printedStock(array $answers, array $processes): array
    {
        $stock = match ($answers['stock'] ?? '') {
            'couche-250g' => 'material-cardstock-250g',
            'couche-300g' => 'material-couche-300g',
            'offset-90g' => 'material-offset-90g',
            default => '',
        };
        return [$stock, ...$processes];
    }

    /** A ficha de folder inclui o papel escolhido e a dobra selecionada. */
    private function printedFolder(array $answers): array
    {
        return [...$this->printedStock($answers, ['process-sheet-print']), 'process-folding'];
    }

    /** A via escolhida determina o estoque autocopiativo; numeração só entra quando marcada. */
    private function carbonlessPads(array $answers): array
    {
        $material = match ((string) ($answers['copies'] ?? '')) {
            '2' => 'material-carbonless-2-part',
            '3' => 'material-carbonless-3-part',
            default => '',
        };
        $required = [$material, 'process-sheet-print'];
        if ($this->isTrue($answers['sequential_numbering'] ?? false)) $required[] = 'process-sequential-numbering';
        return $required;
    }

    /** Cada tipo de encadernação exige seu acabamento correspondente. */
    private function boundPrint(array $answers, bool $bindingRequired): array
    {
        $binding = match ($answers['binding'] ?? '') {
            'spiral' => 'finish-binding-spiral',
            'wire-o' => 'finish-binding-wire-o',
            'hardcover' => 'finish-binding-hardcover',
            'none' => null,
            default => $bindingRequired ? '' : null,
        };
        $required = ['material-offset-90g', 'process-sheet-print'];
        if ($binding !== null) $required[] = $binding;
        return $required;
    }

    /** O cardápio exige laminação e encadernação apenas quando informadas no wizard. */
    private function menu(array $answers): array
    {
        $required = ['material-couche-300g', 'process-sheet-print'];
        if ($this->isTrue($answers['laminated'] ?? false)) $required[] = 'finish-card-lamination';
        $binding = match ($answers['binding'] ?? 'none') {
            'spiral' => 'finish-binding-spiral', 'wire-o' => 'finish-binding-wire-o', default => null,
        };
        if ($binding !== null) $required[] = $binding;
        return $required;
    }

    /** O bastão e a cordinha são opcionais, mas obrigatórios na composição quando marcados. */
    private function simpleBanner(array $answers): array
    {
        $required = ['material-frontlight-440g', 'process-large-format-print'];
        if ($this->isTrue($answers['rods_cord'] ?? false)) $required[] = 'finish-banner-rods-cord';
        return $required;
    }

    /** A apresentação escolhida para o rótulo determina o substrato e o corte. */
    private function labels(array $answers): array
    {
        $material = match ($answers['format'] ?? '') {
            'roll' => 'material-label-roll-stock',
            'sheet' => 'material-label-sheet-stock',
            default => '',
        };
        return [$material, 'process-label-printing', 'process-label-die-cut'];
    }

    /** O material da caneca deve coincidir com a base incluída no orçamento. */
    private function mug(array $answers): array
    {
        $material = match ($answers['material'] ?? '') {
            'ceramic' => 'material-gift-mug-ceramic',
            'polymer' => 'material-gift-mug-polymer',
            default => '',
        };
        $process = match ($answers['print_method'] ?? '') {
            'sublimation' => 'process-sublimation',
            'other' => 'process-gift-printing',
            default => '',
        };
        return [$material, $process];
    }

    /** Obriga a ficha do adesivo a refletir mídia, impressão, aplicação e opções escolhidas. */
    private function adhesive(array $answers): array
    {
        $material = match ($answers['material'] ?? '') {
            'monomeric' => 'material-vinyl-monomeric', 'polymeric' => 'material-vinyl-polymeric',
            'perforated' => 'material-vinyl-perforated', 'frosted' => 'material-vinyl-frosted',
            'static-cling' => 'material-vinyl-static-cling', default => '',
        };
        $required = [$material, 'process-large-format-print', 'process-adhesive-application'];
        $laminationMaterial = match ($answers['lamination'] ?? 'none') {
            'gloss' => 'material-vinyl-gloss-lamination',
            'matte' => 'material-vinyl-matte-lamination',
            'scratch-resistant' => 'material-vinyl-scratch-lamination',
            default => null,
        };
        if ($laminationMaterial !== null) $required[] = $laminationMaterial;
        if (($answers['lamination'] ?? 'none') !== 'none') $required[] = 'finish-vinyl-lamination';
        if (($answers['cut_type'] ?? 'straight') === 'plotter') $required[] = 'process-plotter-cut';

        return $required;
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

    /** Relaciona todas as técnicas da camiseta aos insumos técnicos próprios de cada processo. */
    private function basicTshirt(array $answers): array
    {
        $fabric = match ($answers['fabric'] ?? '') {
            'cotton' => 'material-cotton-menegotti',
            'polyester' => 'material-polyester',
            'dryfit' => 'material-dryfit',
            default => '',
        };
        $personalization = match ($answers['personalization'] ?? '') {
            'silk-screen' => ['process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink'],
            'dtf' => ['process-dtf-print-size', 'material-textile-dtf-transfer'],
            'dtg' => ['process-dtg-print-size', 'material-textile-dtg-ink'],
            'sublimation' => ['process-sublimation', 'material-sublimation-paper', 'material-sublimation-ink'],
            'textile-vinyl' => ['process-textile-vinyl', 'material-textile-vinyl'],
            default => [],
        };

        return [$fabric, ...$personalization];
    }

    private function isTrue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
