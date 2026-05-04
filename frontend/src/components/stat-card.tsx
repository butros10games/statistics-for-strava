type StatCardTone = 'slate' | 'orange' | 'emerald' | 'blue';

const toneClasses: Record<StatCardTone, string> = {
    slate: 'border-gray-200 text-gray-900 dark:border-gray-800 dark:text-gray-100',
    orange: 'border-orange-200 bg-orange-50 text-orange-950 dark:border-orange-800/60 dark:bg-orange-950/40 dark:text-orange-100',
    emerald: 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-800/60 dark:bg-emerald-950/40 dark:text-emerald-100',
    blue: 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-800/60 dark:bg-sky-950/40 dark:text-sky-100',
};

interface StatCardProps {
    label: string;
    value: string | number;
    hint: string;
    tone?: StatCardTone;
    compact?: boolean;
}

export function StatCard({label, value, hint, tone = 'slate', compact = false}: StatCardProps) {
    return (
        <div className={`metric-card ${compact ? 'p-2.5' : ''} ${toneClasses[tone]}`}>
            <div className="text-[10px] font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">{label}</div>
            <div className={compact ? 'mt-1 text-[1.5rem] leading-none font-semibold tracking-tight' : 'mt-1.5 text-[1.9rem] leading-none font-semibold tracking-tight'}>{value}</div>
            <p className={compact ? 'mt-1 text-[12px] leading-4 text-gray-600 dark:text-gray-300' : 'mt-1.5 text-[13px] leading-5 text-gray-600 dark:text-gray-300'}>{hint}</p>
        </div>
    );
}
