import './bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();

const sidebar = document.querySelector('.docs-sidebar');
sidebar?.querySelector('.mobile-docs-nav')?.addEventListener('click', event => {
    const open = sidebar.dataset.open !== 'true';
    sidebar.dataset.open = String(open);
    event.currentTarget.setAttribute('aria-expanded', String(open));
});
const links = [...document.querySelectorAll('.docs-nav a')];
document.querySelector('.docs-search')?.addEventListener('input', event => {
    const term = event.target.value.toLocaleLowerCase('es').normalize('NFD').replace(/\p{Diacritic}/gu, '');
    links.forEach(link => { link.hidden = !link.textContent.toLocaleLowerCase('es').normalize('NFD').replace(/\p{Diacritic}/gu, '').includes(term); });
    document.querySelector('.nav-empty').hidden = links.some(link => !link.hidden);
});
document.querySelectorAll('.copy-button').forEach(button => button.addEventListener('click', async () => {
    const status = document.querySelector('.copy-status');
    try {
        await navigator.clipboard.writeText(button.closest('.code-block').querySelector('pre').textContent);
        status.textContent = 'Ejemplo copiado';
    } catch { status.textContent = 'Selecciona el ejemplo para copiarlo'; }
    window.setTimeout(() => { status.textContent = ''; }, 3000);
}));
if (links.length) {
    const observer = new IntersectionObserver(entries => {
        entries.filter(entry => entry.isIntersecting).forEach(entry => {
            links.forEach(link => link.toggleAttribute('aria-current', false));
            links.find(link => link.hash === `#${entry.target.id}`)?.setAttribute('aria-current', 'location');
        });
    }, { rootMargin: '-100px 0px -65% 0px' });
    document.querySelectorAll('.docs-main > section').forEach(section => observer.observe(section));
}
