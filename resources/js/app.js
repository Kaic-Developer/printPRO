import './bootstrap';

// A navegação permanece visível sem JS; o recolhimento é uma melhoria progressiva.
const toggle = document.querySelector('.menu-toggle');
const navigation = document.querySelector('#navigation');
if (toggle && navigation) {
    document.documentElement.classList.add('navigation-ready');
    const mobile = window.matchMedia('(max-width: 800px)');
    const setOpen = (open, focusNavigation = false, restoreFocus = false) => {
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Fechar menu' : 'Abrir menu');
        navigation.classList.toggle('is-open', open);
        navigation.inert = mobile.matches && !open;
        if (focusNavigation) navigation.querySelector('.nav-item[href]')?.focus();
        if (restoreFocus) toggle.focus();
    };
    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        setOpen(open, open, !open);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') setOpen(false, false, true);
    });
    document.addEventListener('click', (event) => {
        if (mobile.matches && !navigation.contains(event.target) && !toggle.contains(event.target)) setOpen(false);
    });
    mobile.addEventListener('change', () => setOpen(false));
    setOpen(false);
}

// Sem JS, a instrução continua visível e o servidor valida a unidade personalizada.
const unitSelect = document.querySelector('#unit');
const customUnit = document.querySelector('[data-custom-unit]');
if (unitSelect && customUnit) {
    const customInput = customUnit.querySelector('input');
    const updateCustomUnit = () => {
        const selected = unitSelect.value === 'custom';
        customUnit.hidden = !selected;
        customInput.required = selected;
        customInput.disabled = !selected;
    };
    unitSelect.addEventListener('change', updateCustomUnit);
    updateCustomUnit();
}

// A máscara monetária agrupa dígitos como centavos e não depende de ponto flutuante.
document.querySelectorAll('[data-money-input]').forEach((input) => {
    const formatMoney = () => {
        const digits = input.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
        if (!digits) {
            input.value = '';
            return;
        }
        const padded = digits.padStart(3, '0');
        const whole = padded.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        input.value = whole + ',' + padded.slice(-2);
    };
    input.addEventListener('input', formatMoney);
if (input.value) formatMoney();
});

// Cada linha de orçamento mantém seus próprios campos; ao trocar o produto, controles ocultos ficam desabilitados para não enviar respostas de outra ficha.
const quoteLines = document.querySelector('[data-quote-lines]');
const quoteLineList = quoteLines?.querySelector('[data-quote-line-list]');
const quoteLineTemplate = document.querySelector('#quote-line-template');
if (quoteLines && quoteLineList && quoteLineTemplate) {
    let nextLineIndex = 0;

    const updateLineVisibility = () => {
        const lines = [...quoteLineList.querySelectorAll('[data-quote-line]')];
        lines.forEach((line, lineNumber) => {
            line.querySelector('[data-line-number]').textContent = String(lineNumber + 1).padStart(2, '0');
            line.querySelector('[data-remove-quote-line]').hidden = lines.length === 1;
        });
    };

    const updateConditionalFields = (panel) => {
        const answers = [...panel.querySelectorAll('[data-wizard-field]')];
        answers.forEach((field) => {
            const controllingKey = field.dataset.visibleField;
            if (!controllingKey) return;
            const controllingInput = panel.querySelector(`[name$="[${CSS.escape(controllingKey)}]"]`);
            const controllingField = panel.querySelector(`[data-wizard-field="${CSS.escape(controllingKey)}"]`);
            const actualValue = controllingInput?.type === 'checkbox' ? (controllingInput.checked ? '1' : '0') : controllingInput?.value;
            const allowedValues = field.dataset.visibleIn?.split(',').filter(Boolean);
            const parentVisible = !controllingField || !controllingField.hidden;
            const visible = parentVisible && (allowedValues ? allowedValues.includes(actualValue) : actualValue === field.dataset.visibleEquals);
            field.hidden = !visible;
            field.querySelectorAll('input, select, textarea').forEach((control) => {
                control.disabled = !visible;
                control.required = visible && control.dataset.schemaRequired === 'true';
            });
        });
    };

    const updateComponentSuggestions = (panel) => {
        const variants = [...panel.querySelectorAll('[data-wizard-field] select')]
            .flatMap((select) => [...select.selectedOptions].map((option) => option.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')))
            .filter(Boolean);
        panel.querySelectorAll('[data-component-code]').forEach((row) => {
            const componentCode = row.dataset.componentCode.toLowerCase();
            const matches = variants.some((variant) => componentCode.includes(variant));
            row.classList.toggle('component-suggested-match', matches);
            const matchLabel = row.querySelector('[data-component-match-label]');
            if (matchLabel) matchLabel.hidden = !matches;
        });
    };

    const syncAcrylicComponentChoices = (panel) => {
        if (panel.dataset.presetPanel !== 'product-acrylic-cutout') return;
        const answer = (key) => panel.querySelector(`[data-wizard-field="${CSS.escape(key)}"] select`)?.value;
        const plasticType = answer('plastic_type');
        const thickness = answer('thickness_mm');
        const acrylicMaterials = {
            '2': 'material-acrylic-cast-2mm', '3': 'material-acrylic-cast-3mm', '4': 'material-acrylic-cast-4mm',
            '5': 'material-acrylic-cast-5mm', '6': 'material-acrylic-cast-6mm', '8': 'material-acrylic-cast-8mm',
            '10': 'material-acrylic-cast-10mm',
        };
        const materialCode = ['acrylic-crystal', 'acrylic-color'].includes(plasticType)
            ? acrylicMaterials[thickness]
            : ({ ps: 'material-ps-sheet', 'expanded-pvc': 'material-expanded-pvc-sheet', polycarbonate: 'material-polycarbonate-sheet' })[plasticType];
        const cutCode = ({ laser: 'process-laser-cut', router: 'process-router-cut' })[answer('cut_process')];
        const plasticMaterialCodes = ['material-acrylic-sheet', ...Object.values(acrylicMaterials), 'material-ps-sheet', 'material-expanded-pvc-sheet', 'material-polycarbonate-sheet'];

        if (materialCode) {
            panel.querySelectorAll('[data-component-code]').forEach((row) => {
                if (!plasticMaterialCodes.includes(row.dataset.componentCode)) return;
                const checkbox = row.querySelector('[name$="[selected]"]');
                if (checkbox) checkbox.checked = row.dataset.componentCode === materialCode;
            });
        }
        if (cutCode) {
            panel.querySelectorAll('[data-component-code="process-laser-cut"], [data-component-code="process-router-cut"]').forEach((row) => {
                const checkbox = row.querySelector('[name$="[selected]"]');
                if (checkbox) checkbox.checked = row.dataset.componentCode === cutCode;
            });
        }
        const thermalBending = panel.querySelector('[data-component-code="process-thermal-bending"] [name$="[selected]"]');
        if (thermalBending && ['0', '1'].includes(answer('thermal_bend'))) thermalBending.checked = answer('thermal_bend') === '1';
    };

    // Mantém a ficha do adesivo alinhada ao substrato, à laminação e ao recorte escolhidos.
    const syncAdhesiveComponentChoices = (panel) => {
        if (panel.dataset.presetPanel !== 'product-printed-adhesive') return;
        const answer = (key) => panel.querySelector(`[data-wizard-field="${CSS.escape(key)}"] select`)?.value;
        const materialCode = ({
            monomeric: 'material-vinyl-monomeric', polymeric: 'material-vinyl-polymeric',
            perforated: 'material-vinyl-perforated', frosted: 'material-vinyl-frosted',
            'static-cling': 'material-vinyl-static-cling',
        })[answer('material')];
        const laminateCode = ({
            gloss: 'material-vinyl-gloss-lamination', matte: 'material-vinyl-matte-lamination',
            'scratch-resistant': 'material-vinyl-scratch-lamination',
        })[answer('lamination')];
        const cutCode = answer('cut_type') === 'plotter' ? 'process-plotter-cut' : null;
        const controlled = [
            'material-vinyl-monomeric', 'material-vinyl-polymeric', 'material-vinyl-perforated',
            'material-vinyl-frosted', 'material-vinyl-static-cling', 'material-vinyl-gloss-lamination',
            'material-vinyl-matte-lamination', 'material-vinyl-scratch-lamination',
            'finish-vinyl-lamination', 'process-plotter-cut',
        ];
        const required = [materialCode, laminateCode, answer('lamination') !== 'none' ? 'finish-vinyl-lamination' : null, cutCode]
            .filter(Boolean);

        panel.querySelectorAll('[data-component-code]').forEach((row) => {
            const code = row.dataset.componentCode;
            if (!controlled.includes(code)) return;
            const checkbox = row.querySelector('[name$="[selected]"]');
            const visible = required.includes(code);
            row.hidden = !visible;
            if (checkbox) checkbox.checked = visible;
        });
    };

    // O acabamento de bastÃµes sÃ³ fica na ficha quando o assistente o inclui no pedido.
    const syncBannerComponents = (panel) => {
        const productCode = panel.dataset.presetPanel;
        if (!['product-banner', 'product-frontlight-banner'].includes(productCode)) return;
        const rodsIncluded = productCode === 'product-banner'
            ? panel.querySelector('[data-wizard-field="rods_cord"] select')?.value === '1'
            : [...(panel.querySelector('[data-wizard-field="finishing"] select')?.selectedOptions ?? [])]
                .some((option) => option.value === 'rods-cord');
        const row = panel.querySelector('[data-component-code="finish-banner-rods-cord"]');
        if (!row) return;
        row.hidden = !rodsIncluded;
        const checkbox = row.querySelector('[name$="[selected]"]');
        if (checkbox) checkbox.checked = rodsIncluded;
    };

    const syncRollUpAndGiftComponents = (panel) => {
        const productCode = panel.dataset.presetPanel;
        const giftMaterials = {
            'product-long-drink-cup': 'material-gift-long-drink-cup',
            'product-squeeze': 'material-gift-squeeze',
            'product-lanyard': 'material-gift-lanyard',
            'product-eco-gift': 'material-eco-gift-base',
        };
        const material = giftMaterials[productCode];
        if (productCode !== 'product-roll-up' && !material) return;
        const answer = (key) => panel.querySelector(`[data-wizard-field="${CSS.escape(key)}"] select`)?.value;
        const required = productCode === 'product-roll-up'
            ? ['material-frontlight-440g', 'process-large-format-print', ...(answer('stand_included') === '1' ? ['material-roll-up-stand'] : [])]
            : [material, 'process-gift-printing'];
        const controlled = productCode === 'product-roll-up'
            ? ['material-frontlight-440g', 'material-roll-up-stand', 'process-large-format-print']
            : [material, 'process-gift-printing'];
        panel.querySelectorAll('[data-component-code]').forEach((row) => {
            if (!controlled.includes(row.dataset.componentCode)) return;
            if (productCode === 'product-roll-up' && row.dataset.componentCode === 'material-roll-up-stand' && !['0', '1'].includes(answer('stand_included'))) return;
            const checkbox = row.querySelector('[name$="[selected]"]');
            if (checkbox) checkbox.checked = required.includes(row.dataset.componentCode);
        });
    };

    // Mantem somente a encadernacao escolhida na ficha e evita opcoes antigas ao trocar o select.
    const syncBindingComponents = (panel) => {
        if (!['product-agenda-notebook', 'product-menu'].includes(panel.dataset.presetPanel)) return;
        const bindingCode = ({
            spiral: 'finish-binding-spiral',
            'wire-o': 'finish-binding-wire-o',
            hardcover: 'finish-binding-hardcover',
        })[panel.querySelector('[data-wizard-field="binding"] select')?.value];
        ['finish-binding-spiral', 'finish-binding-wire-o', 'finish-binding-hardcover'].forEach((code) => {
            const row = panel.querySelector(`[data-component-code="${code}"]`);
            if (!row) return;
            const selected = code === bindingCode;
            row.hidden = !selected;
            const checkbox = row.querySelector('[name$="[selected]"]');
            if (checkbox) checkbox.checked = selected;
        });
    };

    // O numero de vias selecionado define o unico estoque autocopiativo da ficha.
    const syncCarbonlessComponents = (panel) => {
        if (panel.dataset.presetPanel !== 'product-carbonless-pads') return;
        const copies = panel.querySelector('[data-wizard-field="copies"] select')?.value;
        const materialCode = ({ '2': 'material-carbonless-2-part', '3': 'material-carbonless-3-part' })[copies];
        panel.querySelectorAll('[data-component-code]').forEach((row) => {
            const code = row.dataset.componentCode;
            if (!['material-carbonless-2-part', 'material-carbonless-3-part', 'process-sequential-numbering'].includes(code)) return;
            const selected = code === materialCode
                || (code === 'process-sequential-numbering' && panel.querySelector('[data-wizard-field="sequential_numbering"] select')?.value === '1');
            row.hidden = !selected;
            const checkbox = row.querySelector('[name$="[selected]"]');
            if (checkbox) checkbox.checked = selected;
        });
    };

    const syncTextilePrintDimensions = (panel) => {
        if (panel.dataset.presetPanel !== 'product-basic-tshirt') return;
        const selectedSize = panel.querySelector('[data-wizard-field="print_size"] select')?.value;
        const standardSizes = { a4: [210, 297], a3: [297, 420], a2: [420, 594] };
        const dimensions = standardSizes[selectedSize];
        ['print_width_mm', 'print_height_mm'].forEach((key, index) => {
            const input = panel.querySelector(`[data-wizard-field="${key}"] input`);
            if (!input) return;
            if (dimensions) {
                input.value = String(dimensions[index]);
                input.dataset.standardValue = input.value;
                input.readOnly = true;
            } else {
                if (input.dataset.standardValue && input.value === input.dataset.standardValue) input.value = '';
                delete input.dataset.standardValue;
                input.readOnly = selectedSize !== 'custom_area';
            }
        });
    };

    const updateWizardQuantityManagement = (panel) => {
        const productCode = panel.dataset.presetPanel;
        const personalization = panel.querySelector('[data-wizard-field="personalization"] select')?.value;
        const textileProducts = ['uniform-polo', 'product-basic-tshirt', 'product-workwear', 'product-sweatshirt', 'product-apron', 'product-cap'];
        const lamination = panel.querySelector('[data-wizard-field="lamination"] select')?.value;
        const laminationMaterial = ({
            gloss: 'material-vinyl-gloss-lamination',
            matte: 'material-vinyl-matte-lamination',
            'scratch-resistant': 'material-vinyl-scratch-lamination',
        })[lamination];
        const rodsIncluded = productCode === 'product-banner'
            ? panel.querySelector('[data-wizard-field="rods_cord"] select')?.value === '1'
            : productCode === 'product-frontlight-banner'
                && [...(panel.querySelector('[data-wizard-field="finishing"] select')?.selectedOptions ?? [])].some((option) => option.value === 'rods-cord');
        const areaPricedCodes = ['product-frontlight-banner', 'product-banner'].includes(productCode)
            ? ['process-large-format-print', ...(rodsIncluded ? ['finish-banner-rods-cord'] : [])]
            : productCode === 'product-printed-adhesive'
                ? ['process-large-format-print', 'process-adhesive-application', ...(laminationMaterial ? ['finish-vinyl-lamination', laminationMaterial] : [])]
                : [];
        const calculatedCodes = productCode === 'product-dtf-dtg-print'
            ? ({ dtf: ['process-dtf-print-size', 'material-textile-dtf-transfer'], dtg: ['process-dtg-print-size'] })[panel.querySelector('[data-wizard-field="technique"] select')?.value] ?? []
            : productCode === 'product-basic-tshirt'
            ? ({
                dtf: ['process-dtf-print-size', 'material-textile-dtf-transfer'],
                dtg: ['process-dtg-print-size'],
            })[personalization] ?? []
            : ['uniform-polo', 'product-sweatshirt', 'product-apron'].includes(productCode) && personalization === 'dtf'
                ? [productCode === 'uniform-polo' ? 'process-dtf-print-size' : 'process-dtf', 'material-textile-dtf-transfer']
            : textileProducts.includes(productCode) && personalization === 'silk-screen'
                ? ['process-silk-screen', 'material-silk-screen-screen', 'material-silk-screen-film', 'material-silk-screen-ink']
                : textileProducts.includes(productCode) && personalization === 'embroidery'
                    ? ['process-computerized-embroidery', ...(panel.querySelector('[data-wizard-field="embroidery_matrix"] select')?.value === '1' ? ['third-party-embroidery-matrix'] : [])]
                    : areaPricedCodes;
        const hasSheetNesting = panel.querySelector('[data-nesting-toggle]')?.checked === true
            && panel.querySelector('[data-nesting-field="material_type"]')?.value === 'sheet';
        const nestingProcessCodes = hasSheetNesting
            ? [
                'process-sheet-print',
                ...(productCode === 'product-flyer' ? ['process-cutting'] : []),
                ...(productCode === 'product-presentation-folder' && panel.querySelector('[data-wizard-field="die_cut"] select')?.value === '1'
                    ? ['process-die-cut-crease']
                    : []),
            ]
            : [];
        const foldProcessCodes = productCode === 'product-folder-print' ? ['process-folding'] : [];
        const labelProcessCodes = productCode === 'product-labels-roll-sheet'
            ? ['process-label-printing', 'process-label-die-cut']
            : [];
        const bindingCodes = {
            spiral: 'finish-binding-spiral',
            'wire-o': 'finish-binding-wire-o',
            hardcover: 'finish-binding-hardcover',
        };
        const bindingProcessCodes = ['product-agenda-notebook', 'product-menu'].includes(productCode)
            ? [bindingCodes[panel.querySelector('[data-wizard-field="binding"] select')?.value]].filter(Boolean)
            : [];
        const copies = panel.querySelector('[data-wizard-field="copies"] select')?.value;
        const carbonlessCodes = productCode === 'product-carbonless-pads'
            ? [({ '2': 'material-carbonless-2-part', '3': 'material-carbonless-3-part' })[copies], 'process-sheet-print'].filter(Boolean)
            : [];
        const dieCutNotRequested = productCode === 'product-presentation-folder'
            && panel.querySelector('[data-wizard-field="die_cut"] select')?.value === '0';
        const managedCodes = [...new Set([...calculatedCodes, ...nestingProcessCodes, ...foldProcessCodes, ...labelProcessCodes, ...bindingProcessCodes, ...carbonlessCodes])];
        const colors = ['silk_front_colors', 'silk_back_colors'].reduce((total, key) => total + (Number(panel.querySelector(`[data-wizard-field="${key}"] input`)?.value) || 0), 0);
        const stitches = Number(panel.querySelector('[data-wizard-field="estimated_stitches"] input')?.value) || 0;
        panel.querySelectorAll('[data-component-code]').forEach((row) => {
            const wasManaged = row.classList.contains('wizard-quantity-managed');
            const wasNestingManaged = row.classList.contains('nesting-quantity-managed');
            const managed = managedCodes.includes(row.dataset.componentCode);
            const nestingManaged = nestingProcessCodes.includes(row.dataset.componentCode);
            const quantityInput = row.querySelector('[name$="[quantity]"]');
            const quantityBlock = quantityInput?.closest('.wizard-component-quantity');
            const autoLabel = row.querySelector('[data-wizard-managed-label]');
            if (row.dataset.componentCode === 'process-die-cut-crease' && productCode === 'product-presentation-folder') {
                row.hidden = dieCutNotRequested;
                if (dieCutNotRequested) {
                    const checkbox = row.querySelector('[name$="[selected]"]');
                    if (checkbox) checkbox.checked = false;
                }
            }
            if (wasManaged && !managed && quantityInput) {
                quantityInput.value = '';
                quantityInput.disabled = false;
                const checkbox = row.querySelector('[name$="[selected]"]');
                if (checkbox && !wasNestingManaged) checkbox.checked = false;
            }
            if (managed && quantityInput) {
                quantityInput.disabled = true;
                const checkbox = row.querySelector('[name$="[selected]"]');
                if (checkbox) checkbox.checked = true;
            }
            if (quantityBlock) quantityBlock.hidden = managed;
            if (autoLabel) {
                autoLabel.hidden = !managed;
                if (managed) {
                    const code = row.dataset.componentCode;
                    const message = ({
                        'process-sheet-print': 'Calculado pelo total de folhas inteiras necessarias nesta ficha.',
                        'process-cutting': 'Calculado pelo total de folhas inteiras previsto no nesting.',
                        'process-die-cut-crease': 'Calculado pelo total de folhas inteiras previsto no nesting.',
                        'process-folding': 'Calculado pelo numero de dobras multiplicado pelas unidades do pedido.',
                        'process-label-printing': 'Calculado pela area efetivamente impressa nos rotulos, sem as sobras do nesting.',
                        'process-label-die-cut': 'Calculado com um corte por rotulo produzido.',
                        'finish-binding-spiral': 'Calculada uma encadernacao para cada exemplar acabado.',
                        'finish-binding-wire-o': 'Calculada uma encadernacao para cada exemplar acabado.',
                        'finish-binding-hardcover': 'Calculada uma encadernacao para cada exemplar acabado.',
                        'material-carbonless-2-part': 'Calculado pelas folhas por bloco, vias selecionadas e quantidade do pedido.',
                        'material-carbonless-3-part': 'Calculado pelas folhas por bloco, vias selecionadas e quantidade do pedido.',
                        'material-silk-screen-screen': `Calculada uma vez nesta linha: ${colors} tela(s), conforme as cores na frente e no verso.`,
                        'material-silk-screen-film': `Calculado uma vez nesta linha: ${colors} fotolito(s), conforme as cores na frente e no verso.`,
                        'material-silk-screen-ink': `Calculada por peça: ${colors} aplicação(ões) de cor × quantidade de peças.`,
                        'process-silk-screen': `Calculado por peça: ${colors} aplicação(ões) de cor × quantidade de peças.`,
                        'process-large-format-print': 'Calculado pela área vendida do trabalho, em m².',
                        'finish-banner-rods-cord': 'Calculado como um kit para cada banner produzido.',
                        'process-adhesive-application': 'Calculada pela área vendida do adesivo, em m².',
                        'finish-vinyl-lamination': 'Calculada pela área laminada do adesivo, em m².',
                        'material-vinyl-gloss-lamination': 'Calculado pela área final do adesivo, em m².',
                        'material-vinyl-matte-lamination': 'Calculado pela área final do adesivo, em m².',
                        'material-vinyl-scratch-lamination': 'Calculado pela área final do adesivo, em m².',
                        'process-computerized-embroidery': `Calculado por peça: ${stitches.toLocaleString('pt-BR')} pontos ÷ 1.000 × quantidade do pedido.`,
                        'third-party-embroidery-matrix': 'Uma matriz para esta linha/arte. Se já estiver pronta, marque “Matriz necessária” como Não.',
                    })[code] ?? 'Consumo calculado pela ficha técnica e multiplicado pela quantidade do pedido.';
                    autoLabel.textContent = message;
                }
            }
            row.classList.toggle('wizard-quantity-managed', managed);
            row.classList.toggle('nesting-quantity-managed', nestingManaged);
        });
    };

    const clearNestingPreview = (editor) => {
        editor.dataset.requestId = String(Number(editor.dataset.requestId || 0) + 1);
        editor.querySelector('[data-nesting-result]').textContent = 'Preencha as dimensões para ver uma estimativa simplificada.';
    };

    const updateNestingAvailability = (panel) => {
        panel.querySelectorAll('[data-nesting-editor]').forEach((editor) => {
            const toggle = editor.querySelector('[data-nesting-toggle]');
            const fields = editor.querySelector('[data-nesting-fields]');
            const enabled = toggle.checked && !panel.hidden;
            fields.hidden = !toggle.checked;
            fields.querySelectorAll('[data-nesting-field]').forEach((control) => {
                const isSheetLength = control.dataset.nestingField === 'material_length_mm';
                const isSheet = editor.querySelector('[data-nesting-field="material_type"]').value === 'sheet';
                control.disabled = !enabled || (isSheetLength && !isSheet);
                control.required = enabled && control.dataset.schemaRequired === 'true' && (!isSheetLength || isSheet);
            });
            const sheetLength = editor.querySelector('[data-nesting-field="material_length_mm"]');
            editor.querySelector('[data-nesting-sheet-length]').hidden = !enabled || editor.querySelector('[data-nesting-field="material_type"]').value !== 'sheet';
            sheetLength.required = enabled && editor.querySelector('[data-nesting-field="material_type"]').value === 'sheet';
            if (!toggle.checked) clearNestingPreview(editor);
        });
    };

    const syncNestingPieceDimensions = (panel) => {
        const editor = panel.querySelector('[data-nesting-editor]');
        if (!editor) return;
        const productCode = panel.dataset.presetPanel;
        const keyMap = {
            'sign-facade': ['width_m', 'height_m', 1000],
            'product-frontlight-banner': ['width_m', 'height_m', 1000],
            'product-banner': ['width_m', 'height_m', 1000],
            'product-roll-up': ['width_mm', 'height_mm', 1],
            'product-printed-adhesive': ['width_m', 'height_m', 1000],
            'print-business-card': ['width_mm', 'height_mm', 1],
            'product-acrylic-cutout': ['width_mm', 'height_mm', 1],
            'product-presentation-folder': ['open_width_mm', 'open_height_mm', 1],
            'product-folder-print': ['open_width_mm', 'open_height_mm', 1],
            'product-flyer': ['width_mm', 'height_mm', 1],
            'product-labels-roll-sheet': ['width_mm', 'height_mm', 1],
        };
        const mapping = keyMap[productCode];
        if (!mapping) return;
        const readAnswer = (key) => panel.querySelector(`[data-wizard-field="${CSS.escape(key)}"] input, [data-wizard-field="${CSS.escape(key)}"] select` )?.value;
        const width = Number(String(readAnswer(mapping[0]) ?? '').replace(',', '.')) * mapping[2];
        const length = Number(String(readAnswer(mapping[1]) ?? '').replace(',', '.')) * mapping[2];
        const widthInput = editor.querySelector('[data-nesting-field="piece_width_mm"]');
        const lengthInput = editor.querySelector('[data-nesting-field="piece_length_mm"]');
        widthInput.value = Number.isInteger(width) && width > 0 ? String(width) : '';
        lengthInput.value = Number.isInteger(length) && length > 0 ? String(length) : '';
    };

    const updateNestingMaterialOptions = (panel) => {
        const editor = panel.querySelector('[data-nesting-editor]');
        if (!editor) return;
        const productCode = panel.dataset.presetPanel;
        const answer = (key) => panel.querySelector(`[data-wizard-field="${CSS.escape(key)}"] input, [data-wizard-field="${CSS.escape(key)}"] select`)?.value;
        const schema = {
            'sign-facade': { key: 'acm_thickness', values: { '3mm': 'material-acm-3mm', '4mm': 'material-acm-4mm' } },
            'product-frontlight-banner': { key: 'material', values: { 'frontlight-440g': 'material-frontlight-440g', 'frontlight-500g': 'material-frontlight-500g', backlight: 'material-backlight', mesh: 'material-mesh', 'sublimation-fabric': 'material-sublimation-fabric' } },
            'product-banner': { key: 'rods_cord', values: { '0': 'material-frontlight-440g', '1': 'material-frontlight-440g' } },
            'product-roll-up': { key: 'stand_included', values: { '0': 'material-frontlight-440g', '1': 'material-frontlight-440g' } },
            'product-printed-adhesive': { key: 'material', values: { monomeric: 'material-vinyl-monomeric', polymeric: 'material-vinyl-polymeric', perforated: 'material-vinyl-perforated', frosted: 'material-vinyl-frosted', 'static-cling': 'material-vinyl-static-cling' } },
            'print-business-card': { key: 'stock', values: { 'couche-250g': 'material-cardstock-250g', 'couche-300g': 'material-cardstock-300g', 'pvc-075': 'material-card-pvc-075' } },
            'product-presentation-folder': { key: 'stock', values: { 'couche-300g': 'material-couche-300g' } },
            'product-flyer': { key: 'stock', values: { 'couche-250g': 'material-cardstock-250g', 'couche-300g': 'material-couche-300g', 'offset-90g': 'material-offset-90g' } },
            'product-folder-print': { key: 'stock', values: { 'couche-250g': 'material-cardstock-250g', 'couche-300g': 'material-couche-300g', 'offset-90g': 'material-offset-90g' } },
            'product-labels-roll-sheet': { key: 'format', values: { roll: 'material-label-roll-stock', sheet: 'material-label-sheet-stock' } },
            'product-acrylic-cutout': { key: 'plastic_type', values: { 'acrylic-crystal': 'material-acrylic-sheet', 'acrylic-color': 'material-acrylic-sheet', ps: 'material-ps-sheet', 'expanded-pvc': 'material-expanded-pvc-sheet', polycarbonate: 'material-polycarbonate-sheet' } },
        }[productCode];
        let allowedCode = schema ? schema.values[answer(schema.key)] : null;
        if (productCode === 'product-acrylic-cutout' && ['acrylic-crystal', 'acrylic-color'].includes(answer('plastic_type'))) {
            const thickness = answer('thickness_mm');
            allowedCode = ({ '2': 'material-acrylic-cast-2mm', '3': 'material-acrylic-cast-3mm', '4': 'material-acrylic-cast-4mm', '5': 'material-acrylic-cast-5mm', '6': 'material-acrylic-cast-6mm', '8': 'material-acrylic-cast-8mm', '10': 'material-acrylic-cast-10mm' })[thickness];
        }
        const materialSelect = editor.querySelector('[data-nesting-field="material_code"]');
        [...materialSelect.options].forEach((option) => {
            if (option.value) option.hidden = option.value !== allowedCode;
        });
        if (materialSelect.value && materialSelect.value !== allowedCode) {
            materialSelect.value = '';
            editor.querySelector('[data-nesting-toggle]').checked = false;
            activateNestingMaterial(editor);
            clearNestingPreview(editor);
        }
        editor.querySelector('[data-nesting-toggle]').disabled = !allowedCode;
        if (!allowedCode) editor.querySelector('[data-nesting-toggle]').checked = false;
        updateNestingAvailability(panel);
    };

    const activateNestingMaterial = (editor) => {
        const materialSelect = editor.querySelector('[data-nesting-field="material_code"]');
        const materialCode = materialSelect.value;
        const panel = editor.closest('[data-preset-panel]');
        panel.querySelectorAll('[data-component-code]').forEach((row) => {
            const wasManaged = row.classList.contains('component-nesting-managed');
            const isManaged = Boolean(materialCode) && row.dataset.componentCode === materialCode;
            if (wasManaged && !isManaged) {
                const previousCheckbox = row.querySelector('[name$="[selected]"]');
                if (previousCheckbox) previousCheckbox.checked = false;
            }
            row.classList.toggle('component-nesting-managed', isManaged);
            const quantity = row.querySelector('.wizard-component-quantity');
            if (quantity) quantity.setAttribute('aria-label', isManaged ? 'Consumo calculado pelo nesting' : 'Consumo informado manualmente');
        });
        if (!materialCode) return;
        const option = materialSelect.selectedOptions[0];
        const typeSelect = editor.querySelector('[data-nesting-field="material_type"]');
        const unit = option.dataset.unit;
        const type = ['m', 'm²'].includes(unit) ? 'roll' : 'sheet';
        typeSelect.value = type;
        editor.querySelector('[data-nesting-field="material_width_mm"]').value = option.dataset.stockWidth || '';
        editor.querySelector('[data-nesting-field="material_length_mm"]').value = option.dataset.stockLength || '';
        syncNestingPieceDimensions(panel);
        updateNestingAvailability(panel);
        const materialRow = panel.querySelector(`[data-component-code="${CSS.escape(materialCode)}"]`);
        const checkbox = materialRow?.querySelector('[name$="[selected]"]');
        if (checkbox) checkbox.checked = true;
    };

    const previewNesting = async (editor, line, panel) => {
        const toggle = editor.querySelector('[data-nesting-toggle]');
        const result = editor.querySelector('[data-nesting-result]');
        const controls = [...editor.querySelectorAll('[data-nesting-field]')];
        const invalid = controls.find((control) => !control.disabled && !control.checkValidity());
        if (!toggle.checked) {
            result.textContent = 'Ative a estimativa para informar as dimensões.';
            return;
        }
        if (invalid) {
            invalid.reportValidity();
            return;
        }

        const requestId = String(Number(editor.dataset.requestId || 0) + 1);
        editor.dataset.requestId = requestId;
        result.textContent = 'Calculando estimativa…';
        const body = new FormData();
        const token = line.closest('form')?.querySelector('input[name="_token"]')?.value;
        if (token) body.append('_token', token);
        controls.filter((control) => !control.disabled).forEach((control) => {
            body.append(control.dataset.nestingField, control.value);
        });

        const isCurrentRequest = () => line.isConnected
            && !panel.hidden
            && line.querySelector('[data-preset-select]').value === panel.dataset.presetPanel
            && editor.dataset.requestId === requestId;
        try {
            const response = await fetch(editor.querySelector('[data-nesting-preview]').dataset.endpoint, {
                method: 'POST',
                body,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const payload = await response.json();
            if (!response.ok) {
                const messages = Object.values(payload.errors ?? {}).flat();
                throw new Error(messages[0] ?? 'Não foi possível estimar. Confira as dimensões informadas.');
            }
            if (!isCurrentRequest()) return;
            const estimate = payload.estimate;
            if (!estimate.fits) {
                result.textContent = 'As peças não cabem no material informado, mesmo girando a orientação.';
                return;
            }
            const stock = estimate.material_type === 'sheet'
                ? `${estimate.sheets_required} chapa(s), com ${estimate.pieces_per_sheet} peça(s) por chapa`
                : `${estimate.roll_length_mm} mm de bobina, com ${estimate.pieces_per_row} peça(s) na largura`;
            const utilization = (estimate.utilization_basis_points / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const orientation = estimate.orientation === 'rotated' ? 'peças giradas' : 'orientação original';
            result.textContent = `Estimativa: ${stock}, ${orientation}. Aproveitamento de área: ${utilization}%. A orientação é automática e supõe que a arte pode girar; confirme o sentido e o corte com a produção.`;
        } catch (error) {
            if (isCurrentRequest()) result.textContent = error.message || 'Não foi possível concluir a estimativa.';
        }
    };

    const updateSelectedPreset = (line) => {
        const selectedCode = line.querySelector('[data-preset-select]').value;
        if (line.dataset.selectedPreset !== selectedCode) {
            line.querySelectorAll('[data-nesting-editor]').forEach(clearNestingPreview);
            line.dataset.selectedPreset = selectedCode;
        }
        line.querySelectorAll('[data-preset-panel]').forEach((panel) => {
            const active = panel.dataset.presetPanel === selectedCode;
            panel.hidden = !active;
            panel.querySelectorAll('input, select, textarea').forEach((control) => {
                control.disabled = !active;
                if (control.dataset.schemaRequired === 'true') control.required = active;
            });
            if (active) {
                updateConditionalFields(panel);
                updateComponentSuggestions(panel);
                syncAcrylicComponentChoices(panel);
                syncAdhesiveComponentChoices(panel);
                syncBannerComponents(panel);
                syncRollUpAndGiftComponents(panel);
                syncBindingComponents(panel);
                syncCarbonlessComponents(panel);
                syncTextilePrintDimensions(panel);
                updateWizardQuantityManagement(panel);
                updateNestingMaterialOptions(panel);
                updateNestingAvailability(panel);
            }
        });
    };

    const restoreQuoteLine = (line, item, lineIndex) => {
        const productSelect = line.querySelector('[data-preset-select]');
        productSelect.value = item.preset_code ?? '';
        updateSelectedPreset(line);
        const panel = [...line.querySelectorAll('[data-preset-panel]')]
            .find((candidate) => candidate.dataset.presetPanel === productSelect.value);
        if (!panel) return;

        const quantity = line.querySelector(`[name="items[${lineIndex}][quantity]"]`);
        if (quantity) quantity.value = item.quantity ?? '';
        panel.querySelectorAll('[name]').forEach((control) => {
            const answerPath = control.name.match(/\[answers\]\[([^\]]+)\](?:\[([^\]]+)\])?$/);
            if (!answerPath) return;
            const [, fieldKey, variantKey] = answerPath;
            const answer = item.answers?.[fieldKey];
            if (answer === undefined || answer === null) return;
            const value = variantKey ? answer[variantKey] : answer;
            if (control.multiple && Array.isArray(value)) {
                [...control.options].forEach((option) => { option.selected = value.includes(option.value); });
            } else if (control.type === 'checkbox') {
                control.checked = value === true || value === 1 || value === '1';
            } else if (!Array.isArray(value)) {
                control.value = String(value);
            }
        });
        (item.components ?? []).forEach((component) => {
            const row = [...panel.querySelectorAll('[data-component-code]')]
                .find((candidate) => candidate.dataset.componentCode === component.code);
            if (!row) return;
            const checkbox = row.querySelector('[name$="[selected]"]');
            const componentQuantity = row.querySelector('[name$="[quantity]"]');
            if (checkbox) checkbox.checked = component.selected === true || component.selected === 1 || component.selected === '1';
            if (componentQuantity) componentQuantity.value = component.quantity ?? '';
        });
        const nesting = item.nesting ?? {};
        if (Object.values(nesting).some((value) => value !== null && value !== '')) {
            const editor = panel.querySelector('[data-nesting-editor]');
            if (editor) {
                editor.querySelector('[data-nesting-toggle]').checked = true;
                Object.entries(nesting).forEach(([key, value]) => {
                    const control = editor.querySelector(`[data-nesting-field="${CSS.escape(key)}"]`);
                    if (control) control.value = value ?? '';
                });
                activateNestingMaterial(editor);
            }
        }
        updateConditionalFields(panel);
        updateComponentSuggestions(panel);
        syncAcrylicComponentChoices(panel);
        syncAdhesiveComponentChoices(panel);
        syncBannerComponents(panel);
        syncRollUpAndGiftComponents(panel);
        syncBindingComponents(panel);
        syncCarbonlessComponents(panel);
        syncTextilePrintDimensions(panel);
        updateWizardQuantityManagement(panel);
        updateNestingMaterialOptions(panel);
        updateNestingAvailability(panel);
    };

    const addQuoteLine = () => {
        const templateHtml = quoteLineTemplate.innerHTML.replaceAll('__INDEX__', String(nextLineIndex++));
        const fragmentTemplate = document.createElement('template');
        fragmentTemplate.innerHTML = templateHtml.trim();
        const line = fragmentTemplate.content.firstElementChild;
        quoteLineList.append(line);
        line.querySelectorAll('[required]').forEach((control) => { control.dataset.schemaRequired = 'true'; });
        line.querySelector('[data-preset-select]').addEventListener('change', () => updateSelectedPreset(line));
        line.addEventListener('change', (event) => {
            const field = event.target.closest('[data-wizard-field]');
            const panel = event.target.closest('[data-preset-panel]');
            if (field && panel) {
                updateConditionalFields(panel);
                syncAcrylicComponentChoices(panel);
                syncAdhesiveComponentChoices(panel);
                syncBannerComponents(panel);
                syncRollUpAndGiftComponents(panel);
                syncBindingComponents(panel);
                syncCarbonlessComponents(panel);
                syncTextilePrintDimensions(panel);
                updateWizardQuantityManagement(panel);
                if (event.target.matches('select')) updateComponentSuggestions(panel);
                if (panel.querySelector('[data-nesting-editor]')) {
                    updateNestingMaterialOptions(panel);
                    syncNestingPieceDimensions(panel);
                    clearNestingPreview(panel.querySelector('[data-nesting-editor]'));
                }
            }
            if (panel && event.target.matches('[name$="[selected]"]') && !event.target.checked) {
                const editor = panel.querySelector('[data-nesting-editor]');
                const selectedCode = editor?.querySelector('[data-nesting-field="material_code"]')?.value;
                if (event.target.closest('[data-component-code]')?.dataset.componentCode === selectedCode) {
                    editor.querySelector('[data-nesting-field="material_code"]').value = '';
                    activateNestingMaterial(editor);
                    clearNestingPreview(editor);
                }
            }
            const nestingEditor = event.target.closest('[data-nesting-editor]');
            if (nestingEditor && panel) {
                if (event.target.matches('[data-nesting-toggle]')) {
                    updateNestingAvailability(panel);
                    updateWizardQuantityManagement(panel);
                }
                if (event.target.matches('[data-nesting-field="material_type"]')) {
                    updateNestingAvailability(panel);
                    updateWizardQuantityManagement(panel);
                    clearNestingPreview(nestingEditor);
                }
                if (event.target.matches('[data-nesting-field="material_code"]')) {
                    activateNestingMaterial(nestingEditor);
                    clearNestingPreview(nestingEditor);
                }
                if (event.target.matches('[data-nesting-field]:not([data-nesting-field="material_code"])')) clearNestingPreview(nestingEditor);
            }
        });
        line.addEventListener('input', (event) => {
            const nestingEditor = event.target.closest('[data-nesting-editor]');
            if (nestingEditor && event.target.matches('[data-nesting-field]')) clearNestingPreview(nestingEditor);
        });
        line.querySelectorAll('[data-nesting-preview]').forEach((button) => {
            button.addEventListener('click', () => previewNesting(button.closest('[data-nesting-editor]'), line, button.closest('[data-preset-panel]')));
        });
        line.querySelector('[data-remove-quote-line]').addEventListener('click', () => {
            line.remove();
            updateLineVisibility();
        });
        updateSelectedPreset(line);
        updateLineVisibility();
        return line;
    };

    quoteLines.querySelector('[data-add-quote-line]').addEventListener('click', addQuoteLine);
    let previousItems = [];
    try {
        previousItems = JSON.parse(document.querySelector('#quote-old-items')?.textContent || '[]');
    } catch {
        previousItems = [];
    }
    if (Array.isArray(previousItems) && previousItems.length) {
        previousItems.forEach((item) => {
            const index = nextLineIndex;
            const line = addQuoteLine();
            restoreQuoteLine(line, item, index);
        });
    } else {
        addQuoteLine();
    }
}

// A estimativa geométrica é enviada ao endpoint do servidor; seus limites e validação não dependem do navegador.
const nestingForm = document.querySelector('[data-nesting-form]');
if (nestingForm) {
    const materialType = nestingForm.elements.namedItem('material_type');
    const sheetLengthGroup = nestingForm.querySelector('[data-sheet-length]');
    const sheetLength = nestingForm.elements.namedItem('material_length_mm');
    const result = nestingForm.querySelector('[data-nesting-result]');
    const updateMaterialType = () => {
        const usesSheet = materialType.value === 'sheet';
        sheetLengthGroup.hidden = !usesSheet;
        sheetLength.disabled = !usesSheet;
        sheetLength.required = usesSheet;
    };
    materialType.addEventListener('change', updateMaterialType);
    updateMaterialType();
    nestingForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        result.textContent = 'Calculando estimativa…';
        try {
            const response = await fetch(nestingForm.action, {
                method: 'POST',
                body: new FormData(nestingForm),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const payload = await response.json();
            if (!response.ok) {
                const messages = Object.values(payload.errors ?? {}).flat();
                throw new Error(messages[0] ?? 'Não foi possível estimar. Confira as dimensões informadas.');
            }
            const estimate = payload.estimate;
            if (!estimate.fits) {
                result.textContent = 'A peça não cabe no material informado, mesmo girando a orientação.';
                return;
            }
            const stock = estimate.material_type === 'sheet'
                ? `${estimate.sheets_required} chapa(s), com ${estimate.pieces_per_sheet} peça(s) por chapa`
                : `${estimate.roll_length_mm} mm de bobina, com ${estimate.pieces_per_row} peça(s) na largura`;
            const utilization = (estimate.utilization_basis_points / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            result.textContent = `Estimativa: ${stock}. Aproveitamento de área: ${utilization}%. Layout retangular simplificado, sujeito a conferência da produção.`;
        } catch (error) {
            result.textContent = error.message || 'Não foi possível concluir a estimativa.';
        }
    });
}
