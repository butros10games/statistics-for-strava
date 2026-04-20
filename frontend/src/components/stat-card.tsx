type StatCardTone = 'slate' | 'orange' | 'emerald' | 'blue';

const toneClasses: Record<StatCardTone, string> = {
    slate: 'border-gray-200/80 bg-white/80 text-gray-900 dark:border-gray-800 dark:bg-gray-900/70 dark:text-gray-100',
    orange: 'border-orange-200 bg-orange-50/90 text-orange-950 dark:border-orange-800/60 dark:bg-orange-950/40 dark:text-orange-100',
    emerald: 'border-emerald-200 bg-emerald-50/90 text-emerald-950 dark:border-emerald-800/60 dark:bg-emerald-950/40 dark:text-emerald-100',
    blue: 'border-sky-200 bg-sky-50/90 text-sky-950 dark:border-sky-800/60 dark:bg-sky-950/40 dark:text-sky-100',
};

interface StatCardProps {
    label: string;
    value: string | number;
    hint: string;
    tone?: StatCardTone;
}

export function StatCard({label, value, hint, tone = 'slate'}: StatCardProps) {
    return (
        <div className={`metric-card ${toneClasses[tone]}`}>
            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">{label}</div>
            <div className="mt-3 text-3xl font-semibold tracking-tight">{value}</div>
            <p className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{hint}</p>
        </div>
    );
}
