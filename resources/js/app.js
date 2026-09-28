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
            const actualValue = controllingInput?.type === 'checkbox' ? (controllingInput.checked ? '1' : '0') : controllingInput?.value;
            const allowedValues = field.dataset.visibleIn?.split(',').filter(Boolean);
            const visible = allowedValues ? allowedValues.includes(actualValue) : actualValue === field.dataset.visibleEquals;
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

    const updateSelectedPreset = (line) => {
        const selectedCode = line.querySelector('[data-preset-select]').value;
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
        updateConditionalFields(panel);
        updateComponentSuggestions(panel);
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
                if (event.target.matches('select')) updateComponentSuggestions(panel);
            }
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
