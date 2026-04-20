import {useEffect, useMemo, useRef, useState} from 'react';
import {v5DarkTheme, v5Theme} from '../lib/echarts-themes';

interface EChartsThemeRegistry {
    registerTheme: (name: string, theme: Record<string, unknown>) => void;
    init: (element: HTMLElement, theme?: string) => EChartsInstance;
}

interface EChartsInstance {
    setOption: (options: Record<string, unknown>) => void;
    resize: () => void;
    dispose: () => void;
}

declare global {
    interface Window {
        echarts?: EChartsThemeRegistry;
    }
}

let themesRegistered = false;

function readThemeName(): 'v5' | 'v5-dark' {
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'v5-dark' : 'v5';
}

function ensureThemesRegistered(echarts: EChartsThemeRegistry) {
    if (themesRegistered) {
        return;
    }

    echarts.registerTheme('v5', v5Theme());
    echarts.registerTheme('v5-dark', v5DarkTheme());
    themesRegistered = true;
}

interface EChartPanelProps {
    title: string;
    options: Record<string, unknown>;
    heightClassName?: string;
}

export function EChartPanel({title, options, heightClassName = 'h-72'}: EChartPanelProps) {
    const chartNodeRef = useRef<HTMLDivElement | null>(null);
    const [themeName, setThemeName] = useState<'v5' | 'v5-dark'>(() => readThemeName());
    const serializedOptions = useMemo(() => JSON.stringify(options), [options]);

    useEffect(() => {
        const observer = new MutationObserver(() => {
            setThemeName(readThemeName());
        });

        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-theme'],
        });

        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        const chartNode = chartNodeRef.current;
        const echarts = window.echarts;

        if (!chartNode || !echarts) {
            return;
        }

        ensureThemesRegistered(echarts);

        const chart = echarts.init(chartNode, themeName);
        chart.setOption(JSON.parse(serializedOptions) as Record<string, unknown>);

        const resizeObserver = new ResizeObserver(() => {
            if (chartNode.offsetParent) {
                chart.resize();
            }
        });
        resizeObserver.observe(chartNode);

        return () => {
            resizeObserver.disconnect();
            chart.dispose();
        };
    }, [serializedOptions, themeName]);

    return (
        <div className="rounded-[28px] border border-gray-200 bg-white/92 p-5 shadow-sm dark:border-gray-800 dark:bg-gray-950/40">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">{title}</h3>
            <div ref={chartNodeRef} className={`mt-4 ${heightClassName}`} />
        </div>
    );
}