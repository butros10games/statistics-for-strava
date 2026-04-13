import {eventBus, Events} from "../core/event-bus";

export default class ModalManager {
    constructor(router) {
        this.router = router;
        this.initializedRoots = new WeakSet();
        this.modalSkeletonNode = document.getElementById('modal-skeleton');
        this.modalContent = this.modalSkeletonNode.querySelector('#modal-content');
        this.modalSpinner = this.modalSkeletonNode.querySelector('.spinner');
        this.modal = null;
        this.handleModalTriggerClick = (event) => {
            const node = event.target.closest('a[data-model-content-url]');
            if (!node || !event.currentTarget.contains(node)) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const modalId = node.getAttribute('data-model-content-url');
            this.open(modalId);
            this.router.pushCurrentRouteToHistoryState(modalId);
        };

        this.modalContent.addEventListener('submit', (event) => {
            const formNode = event.target.closest('form');
            if (!formNode || formNode.hasAttribute('data-modal-submit-disabled')) {
                return;
            }

            const method = ((event.submitter?.getAttribute('formmethod') ?? formNode.getAttribute('method')) || 'get').toUpperCase();
            if (method !== 'POST') {
                return;
            }

            event.preventDefault();
            this.submitForm(formNode, event.submitter);
        });
    }

    init(rootNode) {
        if (this.initializedRoots.has(rootNode)) {
            return;
        }

        this.initializedRoots.add(rootNode);
        rootNode.addEventListener('click', this.handleModalTriggerClick, true);
    }

    open(modalId) {
        this.close();
        const fetchUrl = this.buildModalFetchUrl(modalId);

        // Show loading state.
        this.modalSpinner.classList.remove('hidden');
        this.modalSpinner.classList.add('flex');

        this.modal = new Modal(this.modalSkeletonNode, {
            placement: 'center',
            closable: true,
            backdropClasses: 'bg-gray-900/50 fixed inset-0 z-1400',
            onShow: async () => {
                const response = await fetch(fetchUrl, {cache: 'no-store'});
                // Remove loading state.
                this.modalSpinner.classList.add('hidden');
                this.modalSpinner.classList.remove('flex');

                this.modalContent.innerHTML = await response.text();
                const modalName = modalId.replace(/^\/+/, '').replaceAll('/', '-');
                eventBus.emit(Events.MODAL_LOADED, {node: this.modalSkeletonNode, modalName});
                // Modal close event listeners.
                const closeButton = this.modalContent.querySelector('button.close');
                if (closeButton) {
                    this.modalContent.querySelector('button.close').addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        this.modal.hide();
                        this.router.pushCurrentRouteToHistoryState();
                    });
                }

                document.body.addEventListener('keydown', (e) => {
                    if (e.key !== 'Escape') {
                        return;
                    }
                    this.router.pushCurrentRouteToHistoryState();
                }, {once: true});

                this._arrowKeyHandler = (e) => {
                    if (e.target.closest('input, textarea, select, [contenteditable]')) {
                        return;
                    }
                    if (e.key === 'ArrowLeft') {
                        const prev = this.modalContent.querySelector('a[data-nav-prev][data-model-content-url]');
                        if (prev) {
                            e.preventDefault();
                            prev.click();
                        }
                    } else if (e.key === 'ArrowRight') {
                        const next = this.modalContent.querySelector('a[data-nav-next][data-model-content-url]');
                        if (next) {
                            e.preventDefault();
                            next.click();
                        }
                    }
                };
                document.addEventListener('keydown', this._arrowKeyHandler);

                document.body.addEventListener('click', (e) => {
                    if (e.target.id !== 'modal-skeleton') {
                        return;
                    }
                    this.router.pushCurrentRouteToHistoryState();
                }, {once: true});

                // Re-register nav items that may have been added dynamically
                const newNavItems = this.modalSkeletonNode.querySelectorAll('a[data-router-navigate]:not([data-router-disabled])');
                this.router.registerNavItems(newNavItems);
            },
            onHide: () => {
                this.modalContent.innerHTML = '';
                if (this._arrowKeyHandler) {
                    document.removeEventListener('keydown', this._arrowKeyHandler);
                    this._arrowKeyHandler = null;
                }
            }
        });

        this.modal.show();
    }

    buildModalFetchUrl(modalId) {
        try {
            const modalUrl = new URL(modalId, window.location.origin);
            if (!modalUrl.searchParams.has('redirectTo')) {
                modalUrl.searchParams.set('redirectTo', this.currentRedirectTarget());
            }

            return `${modalUrl.pathname}${modalUrl.search}${modalUrl.hash}`;
        } catch {
            return modalId;
        }
    }

    currentRedirectTarget() {
        const currentRoute = this.router.currentRoute();
        const currentModal = location.hash.replace('#', '');

        return currentModal ? `${currentRoute}#${currentModal}` : currentRoute;
    }

    async submitForm(formNode, submitter) {
        const action = submitter?.getAttribute('formaction') ?? formNode.getAttribute('action') ?? window.location.href;
        const method = ((submitter?.getAttribute('formmethod') ?? formNode.getAttribute('method')) || 'POST').toUpperCase();
        const formData = new FormData(formNode);
        const requestedRedirectTarget = formData.get('redirectTo');

        if (submitter?.getAttribute('name')) {
            formData.append(submitter.getAttribute('name'), submitter.getAttribute('value') ?? '');
        }

        const response = await fetch(action, {
            method,
            body: formData,
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (response.redirected || (response.ok && typeof requestedRedirectTarget === 'string' && requestedRedirectTarget !== '')) {
            const {route, modal} = this.resolveNavigationTarget(response.url, typeof requestedRedirectTarget === 'string' ? requestedRedirectTarget : null);
            this.close();
            this.router.navigateTo(route, modal, true);

            return;
        }

        this.modalContent.innerHTML = await response.text();
        const modalName = (action || '').replace(/^\/+/u, '').replaceAll('/', '-');
        eventBus.emit(Events.MODAL_LOADED, {node: this.modalSkeletonNode, modalName});
    }

    resolveNavigationTarget(responseUrl, requestedRedirectTarget = null) {
        const responseTarget = this.parseAppUrl(responseUrl);
        const requestedTarget = requestedRedirectTarget ? this.parseAppUrl(requestedRedirectTarget) : null;

        if (requestedTarget && responseTarget.route === requestedTarget.route && !responseTarget.modal && requestedTarget.modal) {
            return requestedTarget;
        }

        return responseTarget;
    }

    parseAppUrl(url) {
        const parsedUrl = new URL(url, window.location.origin);

        return {
            route: parsedUrl.pathname,
            modal: parsedUrl.hash.replace('#', '') || null,
        };
    }

    close() {
        if (!this.modal) {
            return;
        }

        this.modal.hide();
    }
}