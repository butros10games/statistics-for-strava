import {useNavigate, useSearchParams} from 'react-router-dom';
import {type ReactPreviewBootstrap} from '../lib/bootstrap';
import {TrainingPlanEditor} from '../components/training-plan-editor';

interface TrainingPlanEditorPageProps {
    bootstrap: ReactPreviewBootstrap;
}

export function TrainingPlanEditorPage({bootstrap}: TrainingPlanEditorPageProps) {
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();

    return (
        <TrainingPlanEditor
            basePath={bootstrap.basePath}
            mode="route"
            query={{
                trainingPlanId: searchParams.get('trainingPlanId') ?? undefined,
                afterTrainingPlanId: searchParams.get('afterTrainingPlanId') ?? undefined,
                targetRaceEventId: searchParams.get('targetRaceEventId') ?? undefined,
            }}
            onSaved={() => navigate('/training-plans')}
        />
    );
}