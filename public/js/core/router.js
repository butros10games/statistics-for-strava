import {eventBus, Events} from "./event-bus";

export default class Router {
    constructor(app) {
        this.app = app;
        this.appContent = app.querySelector('#js-loaded-content');
        this.spinner = app.querySelector('#spinner');
        this.menu = document.querySelector('aside');
        this.menuItems = document.querySelectorAll(
            'nav a[data-router-navigate]:not([data-router-disabled]), aside li a[data-router-navigate]:not([data-router-disabled])'
        );
        this.mobileNavTriggerEl = document.querySelector('[data-drawer-target="drawer-navigation"]');
    }

    showLoader() {
        this.spinner.classList.remove('hidden');
        this.spinner.classList.add('flex');
        this.appContent.classList.add('hidden');
    }

    hideLoader() {
        this.spinner.classList.remove('flex');
        this.spinner.classList.add('hidden');
        this.appContent.classList.remove('hidden');
    }

    determineActiveMenuLink(url) {
        const activeLink = document.querySelector(`aside li a[data-router-navigate="${url}"]`);
        if (activeLink) {
            return activeLink;
        }

        const newUrl = url.replace(/\/[^\/]*$/, '');
        if (newUrl === url || newUrl === '') {
            return null;
        }

        return this.determineActiveMenuLink(newUrl);
    }

    deferToNextFrame() {
        return new Promise(resolve => {
            window.requestAnimationFrame(() => resolve());
        });
    }

    async renderContent(page, modalId, serverRoute = false) {
        const isMobileNavTriggerVisible = this.mobileNavTriggerEl
            && window.getComputedStyle(this.mobileNavTriggerEl).display !== 'none';
        const isMobileNavOpen = this.menu?.getAttribute('aria-hidden') === 'false';

        if (document.activeElement instanceof HTMLElement && this.menu?.contains(document.activeElement)) {
            document.activeElement.blur();

            if (isMobileNavTriggerVisible) {
                this.mobileNavTriggerEl.focus();
            }
        }

        // Close mobile nav if open
        if (isMobileNavTriggerVisible && isMobileNavOpen) {
            await this.deferToNextFrame();

            this.mobileNavTriggerEl.dispatchEvent(
                new MouseEvent('click', {bubbles: true, cancelable: true, view: window})
            );
        }

        this.showLoader();

        const url = serverRoute ? page : `${page}.html`;
        const response = await fetch(url, {
            cache: 'no-store',
            headers: serverRoute ? {'X-Fragment-Request': '1'} : {},
        });
        this.appContent.innerHTML = await response.text();
        window.scrollTo(0, 0);

        this.hideLoader();

        this.app.setAttribute('data-router-current', page);
        this.app.setAttribute('data-modal-current', modalId);

        // Update active states
        this.menuItems.forEach(node => node.setAttribute('aria-selected', 'false'));

        const activeLink = this.determineActiveMenuLink(page);
        activeLink?.setAttribute('aria-selected', 'true');

        if (activeLink?.hasAttribute('data-router-sub-menu')) {
            activeLink.closest('ul')?.classList.remove('hidden');
        }

        // Re-register nav items that may have been added dynamically
        const newNavItems = document.querySelectorAll('main a[data-router-navigate]:not([data-router-disabled])');
        this.registerNavItems(newNavItems);

        const fullPageName = page
            .replace(window.statisticsForStrava.appUrl.basePath, '')
            .replace(/^\/+/, '')
            .replaceAll('/', '-');

        eventBus.emit(Events.PAGE_LOADED, {page: fullPageName, modalId});
    }

    registerNavItems(items) {
        items.forEach(link => {
            link.addEventListener('click', async e => {
                e.preventDefault();
                const route = link.getAttribute('data-router-navigate');
                const serverRoute = link.hasAttribute('data-router-server');

                await eventBus.emitAsync(Events.NAVIGATION_CLICKED, {link});

                this.navigateTo(
                    route,
                    null,
                    link.hasAttribute('data-router-force-reload'),
                    serverRoute
                );
            });
        });
    }

    registerBrowserBackAndForth() {
        window.onpopstate = e => {
            if (!e.state) return;
            this.renderContent(e.state.route, e.state.modal, e.state.serverRoute || false);
        };
    }

    navigateTo(route, modal, force = false, serverRoute = false) {
        const currentRoute = this.app.getAttribute('data-router-current');
        if (currentRoute === route && !force) return; // Avoid reloading same page.

        this.renderContent(route, modal, serverRoute);
        this.pushRouteToHistoryState(route, modal, serverRoute);
    }

    pushRouteToHistoryState(route, modal, serverRoute = false) {
        const fullRoute = modal ? `${route}#${modal}` : route;
        window.history.pushState({route, modal, serverRoute}, '', fullRoute);
    }

    pushCurrentRouteToHistoryState(modal) {
        this.pushRouteToHistoryState(this.currentRoute(), modal);
    }

    currentRoute() {
        const defaultRoute = '/dashboard';
        if (window.statisticsForStrava.appUrl.basePath === '') {
            // App is not served from a subpath.
            return location.pathname.replace('/', '') ? location.pathname : defaultRoute;
        }

        // App is served from a subpath.
        const base = '/' + window.statisticsForStrava.appUrl.basePath.replace(/^\/+|\/+$/g, '');
        const pathname = location.pathname.replace(/\/+$/, '');

        return pathname === base
            ? base + defaultRoute
            : location.pathname;
    }

    boot() {
        if (this.appContent === null) {
            // App content can be null if SYMFONY routing is used.
            return;
        }

        const route = this.currentRoute();
        const modal = location.hash.replace('#', '');
        const serverRoute = Array.from(document.querySelectorAll('a[data-router-navigate][data-router-server]'))
            .some(link => link.getAttribute('data-router-navigate') === route);

        this.registerNavItems(this.menuItems);
        this.registerBrowserBackAndForth();
        this.renderContent(route, modal, serverRoute);

        window.history.replaceState({route, modal, serverRoute}, '', route + location.hash);
    }
}
