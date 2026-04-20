interface TrainingPlanAnalysisPromptOptions {
    planTitle: string;
    exportUrl: string;
    plannerUrl?: string;
}

export function buildTrainingPlanAnalysisPrompt({planTitle, exportUrl, plannerUrl}: TrainingPlanAnalysisPromptOptions): string {
    const safePlanTitle = planTitle.trim() || 'Untitled training plan';
    const absoluteExportUrl = toAbsoluteUrl(exportUrl);
    const absolutePlannerUrl = plannerUrl ? toAbsoluteUrl(plannerUrl) : '';

    return [
        'Please review my training plan using this JSON export as the source of truth:',
        absoluteExportUrl,
        '',
        `Plan title: ${safePlanTitle}`,
        absolutePlannerUrl ? `Planner view: ${absolutePlannerUrl}` : null,
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

export async function copyTextToClipboard(value: string): Promise<boolean> {
    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(value);

            return true;
        } catch {
            // Fall through to the textarea fallback.
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

function toAbsoluteUrl(url: string): string {
    if (!url) {
        return '';
    }

    try {
        return new URL(url, window.location.origin).href;
    } catch {
        return url;
    }
}
