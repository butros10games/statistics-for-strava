export default class ManualSyncManager {
    constructor() {
        this.initializedButtons = new WeakSet();
        this.activeButton = null;
        this.activeInterval = null;
        this.activeStartedAt = null;
    }

    init(rootNode) {
        rootNode.querySelectorAll('[data-manual-sync-button]').forEach((button) => {
            if (this.initializedButtons.has(button)) {
                return;
            }

            this.initializedButtons.add(button);
            button.addEventListener('click', async () => {
                await this.handleSync(button);
            });
        });
    }

    async handleSync(button) {
        if (this.activeButton) {
            return;
        }

        const card = button.closest('[data-manual-sync-card]');
        if (!card) {
            return;
        }

        const url = button.getAttribute('data-manual-sync-url');
        if (!url) {
            return;
        }

        const buttons = Array.from(document.querySelectorAll('[data-manual-sync-button]'));
        const statusNode = card.querySelector('[data-manual-sync-status]');
        const outputNode = card.querySelector('[data-manual-sync-output]');
        const progressNode = card.querySelector('[data-manual-sync-progress]');
        const provider = button.getAttribute('data-manual-sync-provider') ?? 'sync';

        this.activeButton = button;
        this.activeStartedAt = Date.now();
        this.setButtonsDisabled(buttons, true);
        this.setLoadingState(button, true);
        this.setProgressVisible(progressNode, true);
        this.renderStatus(statusNode, 'info', `Running ${provider} sync…`);
        this.renderOutput(outputNode, '');
        this.startElapsedTimer(statusNode, provider);

        try {
            const response = await fetch(url, {
                method: 'POST',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            const payload = await response.json().catch(() => ({
                message: 'The sync finished, but the server returned an unexpected response.',
            }));

            this.stopElapsedTimer();

            if (!response.ok) {
                const error = new Error(payload.message || 'The sync failed.');
                error.output = payload.output || '';

                throw error;
            }

            this.renderStatus(statusNode, 'success', payload.message || 'Sync completed successfully.');
            this.renderOutput(outputNode, payload.output || '');
            this.updateGarminLastImport(card, payload.lastImportedDay);
        } catch (error) {
            this.stopElapsedTimer();
            this.renderStatus(statusNode, 'error', error instanceof Error ? error.message : 'The sync failed.');
            this.renderOutput(outputNode, error instanceof Error && 'output' in error ? error.output : '');
        } finally {
            this.setProgressVisible(progressNode, false);
            this.setLoadingState(button, false);
            this.setButtonsDisabled(buttons, false);
            this.activeButton = null;
            this.activeStartedAt = null;
        }
    }

    setButtonsDisabled(buttons, disabled) {
        buttons.forEach((button) => {
            const shouldDisable = disabled || button.hasAttribute('data-manual-sync-disabled-original');
            if (disabled && button.disabled) {
                button.setAttribute('data-manual-sync-disabled-original', 'true');
            }
            if (!disabled && button.hasAttribute('data-manual-sync-disabled-original')) {
                button.disabled = true;
                button.removeAttribute('data-manual-sync-disabled-original');

                return;
            }

            button.disabled = shouldDisable;
        });
    }

    setLoadingState(button, loading) {
        const spinner = button.querySelector('[data-manual-sync-spinner]');
        const label = button.querySelector('[data-manual-sync-label]');
        if (!button.dataset.manualSyncIdleLabel && label?.textContent) {
            button.dataset.manualSyncIdleLabel = label.textContent.trim();
        }
        const idleLabel = button.dataset.manualSyncIdleLabel || label?.textContent || 'Sync now';
        const loadingLabel = button.dataset.manualSyncLoadingLabel || button.dataset.loadingText || 'Syncing…';

        button.setAttribute('aria-busy', loading ? 'true' : 'false');
        spinner?.classList.toggle('hidden', !loading);

        if (label) {
            label.textContent = loading ? loadingLabel : idleLabel;
        }
    }

    setProgressVisible(node, visible) {
        if (!node) {
            return;
        }

        node.classList.toggle('hidden', !visible);
    }

    renderStatus(node, tone, message) {
        if (!node) {
            return;
        }

        node.textContent = message;
        node.classList.remove('hidden', 'border-emerald-200', 'bg-emerald-50', 'text-emerald-800', 'border-amber-200', 'bg-amber-50', 'text-amber-800', 'border-red-200', 'bg-red-50', 'text-red-700');

        if (tone === 'success') {
            node.classList.add('rounded-lg', 'border', 'border-emerald-200', 'bg-emerald-50', 'px-4', 'py-3', 'text-emerald-800');

            return;
        }

        if (tone === 'error') {
            node.classList.add('rounded-lg', 'border', 'border-red-200', 'bg-red-50', 'px-4', 'py-3', 'text-red-700');

            return;
        }

        node.classList.add('rounded-lg', 'border', 'border-amber-200', 'bg-amber-50', 'px-4', 'py-3', 'text-amber-800');
    }

    renderOutput(node, output) {
        if (!node) {
            return;
        }

        const normalizedOutput = (output || '').trim();
        node.textContent = normalizedOutput;
        node.classList.toggle('hidden', normalizedOutput.length === 0);
    }

    startElapsedTimer(statusNode, provider) {
        this.stopElapsedTimer();

        this.activeInterval = window.setInterval(() => {
            if (!statusNode || null === this.activeStartedAt) {
                return;
            }

            const elapsedInSeconds = Math.max(1, Math.round((Date.now() - this.activeStartedAt) / 1000));
            statusNode.textContent = `Running ${provider} sync… ${elapsedInSeconds}s`;
        }, 1000);
    }

    stopElapsedTimer() {
        if (null === this.activeInterval) {
            return;
        }

        window.clearInterval(this.activeInterval);
        this.activeInterval = null;
    }

    updateGarminLastImport(card, lastImportedDay) {
        if (!lastImportedDay) {
            return;
        }

        const node = card.querySelector('[data-garmin-last-import]');
        if (!node) {
            return;
        }

        node.textContent = lastImportedDay;
    }
}
