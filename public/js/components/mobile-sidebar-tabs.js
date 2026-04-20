export default class MobileSidebarTabs {
    init(rootNode) {
        rootNode.querySelectorAll('[data-mobile-sidebar-tabs]').forEach((container) => {
            if (container._mobileSidebarTabsInit) return;
            container._mobileSidebarTabsInit = true;

            const triggers = container.querySelectorAll('[data-mobile-tab-trigger]');
            const panels = container.querySelectorAll('[data-mobile-tab-panel]');

            if (triggers.length === 0 || panels.length === 0) return;

            const activate = (targetId) => {
                triggers.forEach((trigger) => {
                    const isActive = trigger.getAttribute('data-mobile-tab-trigger') === targetId;
                    trigger.classList.toggle('active', isActive);
                    trigger.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });
                panels.forEach((panel) => {
                    const isTarget = panel.getAttribute('data-mobile-tab-panel') === targetId;
                    panel.classList.toggle('hidden', !isTarget);
                    panel.classList.toggle('lg:flex', !isTarget);
                    panel.classList.toggle('lg:block', !isTarget);
                });
            };

            triggers.forEach((trigger) => {
                trigger.addEventListener('click', (e) => {
                    e.preventDefault();
                    activate(trigger.getAttribute('data-mobile-tab-trigger'));
                });
            });

            // On lg+ screens, always show both panels regardless of tab state
            const lgMediaQuery = window.matchMedia('(min-width: 1024px)');
            const handleBreakpoint = (mq) => {
                if (mq.matches) {
                    panels.forEach((panel) => {
                        panel.classList.remove('hidden');
                    });
                } else {
                    // Re-apply active tab state
                    const activeTab = container.querySelector('[data-mobile-tab-trigger].active');
                    if (activeTab) {
                        activate(activeTab.getAttribute('data-mobile-tab-trigger'));
                    }
                }
            };

            lgMediaQuery.addEventListener('change', handleBreakpoint);
            handleBreakpoint(lgMediaQuery);
        });
    }
}
