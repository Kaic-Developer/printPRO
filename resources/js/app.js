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
