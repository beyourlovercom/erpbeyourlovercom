const sidebar = document.querySelector('#module-sidebar');
const storage = {collapsed: 'module_sidebar_collapsed', active: 'active_module', groups: 'expanded_menu_groups'};
const savedCollapsed = window.localStorage.getItem(storage.collapsed) === 'true';
const reopen = document.querySelector('.reopen-sidebar');
const syncSidebarState = (collapsed) => {
    sidebar?.classList.toggle('is-collapsed', collapsed);
    if (reopen) reopen.style.display = collapsed ? 'block' : '';
};
syncSidebarState(savedCollapsed);
document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => button.addEventListener('click', () => {
    const collapsed = !sidebar.classList.contains('is-collapsed');
    syncSidebarState(collapsed);
    window.localStorage.setItem(storage.collapsed, String(collapsed));
}));
document.querySelectorAll('.group-toggle').forEach((button) => button.addEventListener('click', () => {
    const group = button.closest('.menu-group');
    const expanded = group.classList.toggle('is-closed') === false;
    button.setAttribute('aria-expanded', String(expanded));
    window.localStorage.setItem(storage.groups, JSON.stringify([...document.querySelectorAll('.menu-group')].filter((item) => !item.classList.contains('is-closed')).map((item) => item.dataset.menuGroup)));
}));
document.querySelectorAll('.rail-item').forEach((item) => item.addEventListener('click', () => window.localStorage.setItem(storage.active, document.body.dataset.module)));
