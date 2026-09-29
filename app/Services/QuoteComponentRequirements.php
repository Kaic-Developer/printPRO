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
            'product-acrylic-cutout' => $this->acrylicCutout($answers),
            'uniform-polo' => $this->polo($answers),
            'product-basic-tshirt' => $this->basicTshirt($answers),
            'product-dtf-dtg-print' => $this->dtfDtgPrint($answers),
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
            'product-roll-up' => $this->rollUp($answers),
            'product-long-drink-cup', 'product-squeeze', 'product-lanyard', 'product-eco-gift' => $this->genericGift($product->code),
            'product-labels-roll-sheet' => $this->labels($answers),
            'product-workwear', 'product-sweatshirt', 'product-apron', 'product-cap' => $this->textileGarment($product->code, $answers),
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
            'product-agenda-notebook', 'product-menu' => ['finish-binding-spiral', 'finish-binding-wire-o', 'finish-binding-hardcover'],
            'product-roll-up' => ['material-roll-up-stand'],
            'product-frontlight-banner' => ['material-frontlight-440g', 'material-frontlight-500g', 'material-backlight', 'material-mesh', 'material-sublimation-fabric', 'finish-banner-rods-cord'],
            'product-printed-adhesive' => ['material-vinyl-monomeric', 'material-vinyl-polymeric', 'material-vinyl-perforated', 'material-vinyl-frosted', 'material-vinyl-static-cling', 'material-vinyl-gloss-lamination', 'material-vinyl-matte-lamination', 'material-vinyl-scratch-lamination', 'finish-vinyl-lamination', 'process-plotter-cut'],
            'product-banner' => ['finish-banner-rods-cord'],
            'product-acrylic-cutout' => ['material-acrylic-sheet', 'material-acrylic-cast-2mm', 'material-acrylic-cast-3mm', 'material-acrylic-cast-4mm', 'material-acrylic-cast-5mm', 'material-acrylic-cast-6mm', 'material-acrylic-cast-8mm', 'material-acrylic-cast-10mm', 'material-ps-sheet', 'material-expanded-pvc-sheet', 'material-polycarbonate-sheet', 'process-laser-cut', 'process-router-cut', 'process-laser-router-cut', 'process-thermal-bending'],
            'print-business-card' => ['material-cardstock-250g', 'material-cardstock-300g', 'material-card-pvc-075', 'finish-card-lamination', 'finish-uv-varnish', 'finish-hot-stamping', 'finish-rounded-corners', 'process-special-die'],
            'product-presentation-folder' => ['material-cardstock-250g', 'material-couche-300g', 'material-offset-90g', 'process-die-cut-crease'],
            'product-flyer', 'product-folder-print' => ['material-cardstock-250g', 'material-couche-300g', 'material-offset-90g'],
            'product-envelopes', 'product-letterhead' => ['material-offset-90g'],
            'product-carbonless-pads' => ['material-carbonless-2-part', 'material-carbonless-3-part', 'process-sequential-numbering'],
            'product-dtf-dtg-print' => ['process-dtf-print-size', 'process-dtg-print-size', 'material-textile-dtf-transfer', 'material-textile-dtg-ink'],
            'uniform-polo' => ['material-piquet', 'material-dryfit', 'material-cotton-menegotti', 'process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink', 'process-computerized-embroidery', 'third-party-embroidery-matrix', 'process-dtf-print-size', 'material-textile-dtf-transfer', 'process-textile-vinyl', 'material-textile-vinyl'],
            'product-basic-tshirt' => ['material-cotton-menegotti', 'material-polyester', 'material-dryfit', 'process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink', 'process-dtf-print-size', 'material-textile-dtf-transfer', 'process-dtg-print-size', 'material-textile-dtg-ink', 'process-sublimation', 'material-sublimation-paper', 'material-sublimation-ink', 'process-textile-vinyl', 'material-textile-vinyl'],
            'product-workwear' => $this->textileChoiceGroup('workwear'),
            'product-sweatshirt' => $this->textileChoiceGroup('sweatshirt'),
            'product-apron' => $this->textileChoiceGroup('apron'),
            'product-cap' => $this->textileChoiceGroup('cap'),
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
            'product-frontlight-banner' => $this->banner($answers),
            'product-banner' => $this->simpleBanner($answers),
            'product-printed-adhesive' => $this->adhesive($answers),
            'product-roll-up' => $this->rollUp($answers),
            'product-acrylic-cutout' => $this->acrylicCutout($answers),
            'print-business-card' => array_values(array_intersect(
                ['material-cardstock-250g', 'material-cardstock-300g', 'material-card-pvc-075', 'finish-card-lamination', 'finish-uv-varnish', 'finish-hot-stamping', 'finish-rounded-corners', 'process-special-die'],
                $this->businessCard($answers),
            )),
            'product-presentation-folder' => array_values(array_intersect(
                ['material-cardstock-250g', 'material-couche-300g', 'material-offset-90g', 'process-die-cut-crease'],
                $this->presentationFolder($answers),
            )),
            'product-flyer', 'product-folder-print', 'product-envelopes', 'product-letterhead' => [$this->printedStock($answers, [])[0]],
            'product-carbonless-pads' => $this->carbonlessPads($answers),
            'product-agenda-notebook' => array_values(array_intersect(
                ['finish-binding-spiral', 'finish-binding-wire-o', 'finish-binding-hardcover'],
                $this->boundPrint($answers, true),
            )),
            'product-menu' => array_values(array_intersect(
                ['finish-binding-spiral', 'finish-binding-wire-o', 'finish-binding-hardcover'],
                $this->menu($answers),
            )),
            'uniform-polo' => $this->polo($answers),
            'product-basic-tshirt' => $this->basicTshirt($answers),
            'product-dtf-dtg-print' => $this->dtfDtgPrint($answers),
            'product-workwear', 'product-sweatshirt', 'product-apron', 'product-cap' => $this->textileGarment($productCode, $answers),
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
    /** A estrutura e a impressão são distintas; a base só entra quando foi solicitada. */
    private function rollUp(array $answers): array
    {
        $required = ['material-frontlight-440g', 'process-large-format-print'];
        if ($this->isTrue($answers['stand_included'] ?? false)) $required[] = 'material-roll-up-stand';
        return $required;
    }

    /** Cada brinde exige sua base cadastrada e o serviço de personalização. */
    private function genericGift(string $productCode): array
    {
        $material = match ($productCode) {
            'product-long-drink-cup' => 'material-gift-long-drink-cup',
            'product-squeeze' => 'material-gift-squeeze',
            'product-lanyard' => 'material-gift-lanyard',
            'product-eco-gift' => 'material-eco-gift-base',
            default => '',
        };
        return [$material, 'process-gift-printing'];
    }

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

    /** Vincula tecido/base e técnica a todos os componentes próprios da peça têxtil. */
    private function textileGarment(string $productCode, array $answers): array
    {
        if (($answers['personalization'] ?? null) === 'silk-screen') {
            $this->validateSilkColors($answers);
        }
        $this->validateEmbroideryMatrixAnswer($answers);

        $base = match ($productCode) {
            'product-workwear' => 'material-brim',
            'product-sweatshirt' => 'material-sweatshirt-fabric',
            'product-apron' => match ($answers['fabric'] ?? '') {
                'brim' => 'material-brim', 'cotton' => 'material-cotton-menegotti',
                'polyester' => 'material-polyester', default => '',
            },
            'product-cap' => 'material-cap-base',
            default => '',
        };
        $technique = match ($answers['personalization'] ?? '') {
            'silk-screen' => ['process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink'],
            'dtf' => ['process-dtf', 'material-textile-dtf-transfer'],
            'embroidery' => ['process-computerized-embroidery'],
            'textile-vinyl' => ['process-textile-vinyl', 'material-textile-vinyl'],
            default => [],
        };
        if (($answers['personalization'] ?? null) === 'embroidery' && $this->isTrue($answers['embroidery_matrix'] ?? false)) {
            $technique[] = 'third-party-embroidery-matrix';
        }
        return [$base, ...$technique];
    }

    /** Alternativas de tecido e técnica impedem cobranças que não correspondem à peça configurada. */
    private function textileChoiceGroup(string $productCode): array
    {
        $bases = match ($productCode) {
            'workwear' => ['material-brim'],
            'sweatshirt' => ['material-sweatshirt-fabric'],
            'apron' => ['material-brim', 'material-cotton-menegotti', 'material-polyester'],
            'cap' => ['material-cap-base'],
            default => [],
        };
        return [...$bases, 'process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink',
            'process-dtf', 'material-textile-dtf-transfer', 'process-computerized-embroidery', 'third-party-embroidery-matrix',
            'process-textile-vinyl', 'material-textile-vinyl'];
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

    /** Material segue tipo/espessura e taxas de laser/router ficam separadas; dobra marcada nunca fica fora da ficha. */
    private function acrylicCutout(array $answers): array
    {
        $material = match ($answers['plastic_type'] ?? '') {
            'acrylic-crystal', 'acrylic-color' => match ((string) ($answers['thickness_mm'] ?? '')) {
                '2' => 'material-acrylic-cast-2mm',
                '3' => 'material-acrylic-cast-3mm',
                '4' => 'material-acrylic-cast-4mm',
                '5' => 'material-acrylic-cast-5mm',
                '6' => 'material-acrylic-cast-6mm',
                '8' => 'material-acrylic-cast-8mm',
                '10' => 'material-acrylic-cast-10mm',
                default => '',
            },
            'ps' => 'material-ps-sheet',
            'expanded-pvc' => 'material-expanded-pvc-sheet',
            'polycarbonate' => 'material-polycarbonate-sheet',
            default => '',
        };
        $cutProcess = match ($answers['cut_process'] ?? '') {
            'laser' => 'process-laser-cut',
            'router' => 'process-router-cut',
            default => '',
        };
        $required = [$material, $cutProcess];
        if ($this->isTrue($answers['thermal_bend'] ?? false)) $required[] = 'process-thermal-bending';

        return $required;
    }

    private function polo(array $answers): array
    {
        if (($answers['personalization'] ?? null) === 'silk-screen') {
            $this->validateSilkColors($answers);
        }
        $this->validateEmbroideryMatrixAnswer($answers);

        $fabric = match ($answers['fabric'] ?? '') {
            'piquet' => 'material-piquet', 'dryfit' => 'material-dryfit',
            'cotton' => 'material-cotton-menegotti', default => '',
        };
        $process = match ($answers['personalization'] ?? '') {
            'silk-screen' => ['process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink'],
            'embroidery' => ['process-computerized-embroidery'],
            'dtf' => ['process-dtf-print-size', 'material-textile-dtf-transfer'],
            'textile-vinyl' => ['process-textile-vinyl', 'material-textile-vinyl'], default => [],
        };
        if (($answers['personalization'] ?? null) === 'embroidery' && $this->isTrue($answers['embroidery_matrix'] ?? false)) {
            $process[] = 'third-party-embroidery-matrix';
        }
        return [$fabric, ...$process];
    }

    /** Confere cores inteiras e não negativas antes de exigir os insumos de serigrafia. */
    private function validateSilkColors(array $answers): void
    {
        $counts = [];
        foreach (['silk_front_colors', 'silk_back_colors'] as $field) {
            $value = $answers[$field] ?? null;
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer === false || $integer < 0 || $integer > 1000) {
                throw ValidationException::withMessages(["answers.{$field}" => 'Informe uma quantidade inteira entre zero e mil cores.']);
            }
            $counts[] = $integer;
        }
        if (array_sum($counts) < 1) {
            throw ValidationException::withMessages(['answers.silk_front_colors' => 'Informe ao menos uma cor na frente ou no verso para calcular a serigrafia.']);
        }
    }

    /** O operador precisa dizer explicitamente se a matriz já existe ou deve ser produzida. */
    private function validateEmbroideryMatrixAnswer(array $answers): void
    {
        if (($answers['personalization'] ?? null) === 'embroidery' && ! array_key_exists('embroidery_matrix', $answers)) {
            throw ValidationException::withMessages(['answers.embroidery_matrix' => 'Informe se a matriz de bordado já existe ou precisa ser produzida.']);
        }
    }

    /** Relaciona todas as técnicas da camiseta aos insumos técnicos próprios de cada processo. */
    private function basicTshirt(array $answers): array
    {
        if (($answers['personalization'] ?? null) === 'silk-screen') {
            $this->validateSilkColors($answers);
        }
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

    /** Escolhe uma única técnica e os insumos correspondentes para a aplicação DTF/DTG avulsa. */
    private function dtfDtgPrint(array $answers): array
    {
        return match ($answers['technique'] ?? '') {
            'dtf' => ['process-dtf-print-size', 'material-textile-dtf-transfer'],
            'dtg' => ['process-dtg-print-size', 'material-textile-dtg-ink'],
            default => [],
        };
    }

    private function isTrue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
