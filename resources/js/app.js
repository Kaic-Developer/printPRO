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
