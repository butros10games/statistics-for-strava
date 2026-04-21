import {useMemo} from 'react';
import {useNavigate, useSearchParams} from 'react-router-dom';
import {PlannedSessionEditor} from '../components/planned-session-editor';
import type {ReactPreviewBootstrap} from '../lib/bootstrap';

interface PlannedSessionEditorPageProps {
    bootstrap: ReactPreviewBootstrap;
}

function buildMonthPreviewPath(day: string): string {
    return `/monthly-stats/month-${day.slice(0, 7)}`;
}

export function PlannedSessionEditorPage({bootstrap}: PlannedSessionEditorPageProps) {
    const [searchParams] = useSearchParams();
    const navigate = useNavigate();

    const query = useMemo(() => ({
        plannedSessionId: searchParams.get('plannedSessionId') ?? undefined,
        day: searchParams.get('day') ?? undefined,
    }), [searchParams]);

    return (
        <PlannedSessionEditor
            basePath={bootstrap.basePath}
            query={query}
            mode="route"
            onSaved={(day) => {
                navigate(buildMonthPreviewPath(day), {replace: true});
            }}
        />
    );
}
