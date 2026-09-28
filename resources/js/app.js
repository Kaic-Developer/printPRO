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
        const managedCodes = productCode === 'product-dtf-dtg-print'
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
                    : [];
        const colors = ['silk_front_colors', 'silk_back_colors'].reduce((total, key) => total + (Number(panel.querySelector(`[data-wizard-field="${key}"] input`)?.value) || 0), 0);
        const stitches = Number(panel.querySelector('[data-wizard-field="estimated_stitches"] input')?.value) || 0;
        panel.querySelectorAll('[data-component-code]').forEach((row) => {
            const wasManaged = row.classList.contains('wizard-quantity-managed');
            const managed = managedCodes.includes(row.dataset.componentCode);
            const quantityInput = row.querySelector('[name$="[quantity]"]');
            const quantityBlock = quantityInput?.closest('.wizard-component-quantity');
            const autoLabel = row.querySelector('[data-wizard-managed-label]');
            if (wasManaged && !managed && quantityInput) {
                quantityInput.value = '';
                quantityInput.disabled = false;
                const checkbox = row.querySelector('[name$="[selected]"]');
                if (checkbox) checkbox.checked = false;
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
                        'material-silk-screen-screen': `Calculada uma vez nesta linha: ${colors} tela(s), conforme as cores na frente e no verso.`,
                        'material-silk-screen-film': `Calculado uma vez nesta linha: ${colors} fotolito(s), conforme as cores na frente e no verso.`,
                        'material-silk-screen-ink': `Calculada por peça: ${colors} aplicação(ões) de cor × quantidade de peças.`,
                        'process-silk-screen': `Calculado por peça: ${colors} aplicação(ões) de cor × quantidade de peças.`,
                        'process-computerized-embroidery': `Calculado por peça: ${stitches.toLocaleString('pt-BR')} pontos ÷ 1.000 × quantidade do pedido.`,
                        'third-party-embroidery-matrix': 'Uma matriz para esta linha/arte. Se já estiver pronta, marque “Matriz necessária” como Não.',
                    })[code] ?? 'Consumo calculado pela ficha técnica e multiplicado pela quantidade do pedido.';
                    autoLabel.textContent = message;
                }
            }
            row.classList.toggle('wizard-quantity-managed', managed);
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
            'product-printed-adhesive': ['width_m', 'height_m', 1000],
            'print-business-card': ['width_mm', 'height_mm', 1],
            'product-acrylic-cutout': ['width_mm', 'height_mm', 1],
            'product-presentation-folder': ['open_width_mm', 'open_height_mm', 1],
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
            'product-printed-adhesive': { key: 'material', values: { monomeric: 'material-vinyl-monomeric', polymeric: 'material-vinyl-polymeric', perforated: 'material-vinyl-perforated', frosted: 'material-vinyl-frosted', 'static-cling': 'material-vinyl-static-cling' } },
            'print-business-card': { key: 'stock', values: { 'couche-250g': 'material-cardstock-250g', 'couche-300g': 'material-cardstock-300g', 'pvc-075': 'material-card-pvc-075' } },
            'product-presentation-folder': { key: 'stock', values: { 'couche-300g': 'material-couche-300g' } },
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
            result.textContent = `Estimativa: ${stock}. Aproveitamento de área: ${utilization}%. Grade retangular simplificada; confirme o corte com a produção.`;
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
                if (event.target.matches('[data-nesting-toggle]')) updateNestingAvailability(panel);
                if (event.target.matches('[data-nesting-field="material_type"]')) {
                    updateNestingAvailability(panel);
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
