export default class TrainingPlanAnalysisPromptManager {
    init(rootNode) {
        rootNode.querySelectorAll('[data-training-plan-analysis-prompt]').forEach((buttonNode) => {
            if (buttonNode.dataset.trainingPlanAnalysisPromptInitialized === 'true') {
                return;
            }

            buttonNode.dataset.trainingPlanAnalysisPromptInitialized = 'true';
            buttonNode.dataset.trainingPlanAnalysisPromptBaseClass = buttonNode.className;

            buttonNode.addEventListener('click', async (event) => {
                event.preventDefault();

                const copied = await this.copyText(this.buildPrompt(buttonNode));
                this.renderCopyState(buttonNode, copied);
            });
        });
    }

    buildPrompt(buttonNode) {
        const planTitle = (buttonNode.dataset.trainingPlanTitle ?? '').trim() || 'Untitled training plan';
        const exportUrl = this.toAbsoluteUrl(buttonNode.dataset.trainingPlanExportUrl ?? '');
        const plannerUrl = this.toAbsoluteUrl(buttonNode.dataset.trainingPlanPlannerUrl ?? '');

        return [
            'Please review my training plan using this JSON export as the source of truth:',
            exportUrl,
            '',
            `Plan title: ${planTitle}`,
            plannerUrl ? `Planner view: ${plannerUrl}` : null,
            '',
            'What I want from you:',
            '1. Summarize what should stay exactly as it is.',
            '2. Identify the top risks, weak spots, or missing elements in the plan.',
            '3. Suggest the smallest changes that would improve block order, load progression, recovery, taper timing, and race specificity.',
            '4. Call out any weeks or sessions that look overloaded, too light, or poorly timed.',
            '5. Give me a short watch list for what to monitor week to week.',
            '',
            'Please be concrete and refer to block names, weeks, dates, and sessions from the JSON.',
            'If something already looks good, say that too.',
            'If you cannot open URLs directly, tell me and I will paste the JSON export.',
        ].filter(Boolean).join('\n');
    }

    async copyText(value) {
        if (navigator.clipboard?.writeText) {
            try {
                await navigator.clipboard.writeText(value);

                return true;
            } catch {
                // Fall through to the textarea fallback below.
            }
        }

        const textareaNode = document.createElement('textarea');
        textareaNode.value = value;
        textareaNode.setAttribute('readonly', 'readonly');
        textareaNode.style.position = 'fixed';
        textareaNode.style.opacity = '0';
        textareaNode.style.pointerEvents = 'none';
        document.body.appendChild(textareaNode);
        textareaNode.focus();
        textareaNode.select();

        try {
            return document.execCommand('copy');
        } catch {
            return false;
        } finally {
            textareaNode.remove();
        }
    }

    renderCopyState(buttonNode, copied) {
        const defaultLabel = buttonNode.dataset.trainingPlanAnalysisDefaultLabel ?? 'Copy AI review prompt';
        const successLabel = buttonNode.dataset.trainingPlanAnalysisSuccessLabel ?? 'Prompt copied';
        const errorLabel = buttonNode.dataset.trainingPlanAnalysisErrorLabel ?? 'Copy failed';

        if (buttonNode._trainingPlanAnalysisPromptResetTimeout) {
            window.clearTimeout(buttonNode._trainingPlanAnalysisPromptResetTimeout);
        }

        this.updateButtonPresentation(buttonNode, copied ? successLabel : errorLabel, copied ? 'success' : 'error');

        buttonNode._trainingPlanAnalysisPromptResetTimeout = window.setTimeout(() => {
            this.updateButtonPresentation(buttonNode, defaultLabel, 'default');
        }, 2200);
    }

    updateButtonPresentation(buttonNode, label, state) {
        const labelNode = buttonNode.querySelector('[data-training-plan-analysis-label]');
        if (labelNode) {
            labelNode.textContent = label;
        }

        buttonNode.setAttribute('title', label);
        buttonNode.setAttribute('aria-label', label);

        buttonNode.classList.remove(
            'border-emerald-300',
            'bg-emerald-50',
            'text-emerald-700',
            'border-rose-300',
            'bg-rose-50',
            'text-rose-700',
        );

        if (state === 'success') {
            buttonNode.classList.add('border-emerald-300', 'bg-emerald-50', 'text-emerald-700');

            return;
        }

        if (state === 'error') {
            buttonNode.classList.add('border-rose-300', 'bg-rose-50', 'text-rose-700');
        }
    }

    toAbsoluteUrl(url) {
        if (!url) {
            return '';
        }

        try {
            return new URL(url, window.location.origin).href;
        } catch {
            return url;
        }
    }
}