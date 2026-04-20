import {TrainingPlanEditor} from './training-plan-editor';

interface TrainingPlanCreateModalProps {
    basePath: string;
    isOpen: boolean;
    trainingPlanId?: string;
    afterTrainingPlanId?: string;
    targetRaceEventId?: string;
    onClose: () => void;
    onSaved: () => void;
}

export function TrainingPlanCreateModal({
    basePath,
    isOpen,
    trainingPlanId,
    afterTrainingPlanId,
    targetRaceEventId,
    onClose,
    onSaved,
}: TrainingPlanCreateModalProps) {
    if (!isOpen) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 md:p-8">
            <button type="button" className="absolute inset-0 bg-gray-950/55 backdrop-blur-sm" aria-label="Close modal overlay" onClick={onClose} />
            <section className="relative z-10 max-h-[92vh] w-full max-w-5xl overflow-hidden rounded-[36px] border border-white/70 bg-white/92 shadow-[0_45px_120px_-45px_rgba(15,23,42,0.65)] backdrop-blur-xl dark:border-gray-800 dark:bg-gray-950/94 dark:shadow-none">
                <div className="border-b border-gray-200/80 px-6 py-5 dark:border-gray-800 md:px-8">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <div className="section-kicker">React modal flow</div>
                            <h2 className="mt-4 text-3xl font-semibold tracking-tight text-gray-900 dark:text-white">Open the live plan editor without leaving the preview shell.</h2>
                            <p className="mt-3 max-w-3xl text-sm leading-7 text-gray-600 dark:text-gray-300">
                                The same shared editor now powers both the route-sized plan workspace and this modal. That keeps the UX aligned while quietly deleting a future maintenance headache.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-gray-200 bg-white text-gray-600 transition hover:border-gray-300 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-gray-600"
                            aria-label="Close training plan modal"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                <div className="max-h-[calc(92vh-132px)] overflow-y-auto px-6 py-6 md:px-8 md:py-8">
                    <TrainingPlanEditor
                        basePath={basePath}
                        mode="modal"
                        query={{trainingPlanId, afterTrainingPlanId, targetRaceEventId}}
                        onSaved={() => {
                            onSaved();
                            onClose();
                        }}
                        onCancel={onClose}
                    />
                </div>
            </section>
        </div>
    );
}
