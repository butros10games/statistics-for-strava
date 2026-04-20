import {useEffect, useMemo, useState} from 'react';
import {BrowserRouter, Navigate, Route, Routes} from 'react-router-dom';
import {ActivitiesPage} from './pages/activities-page';
import {AppShell} from './components/app-shell';
import {BestEffortsPage} from './pages/best-efforts-page';
import {ChallengesPage} from './pages/challenges-page';
import {buildPreviewBasename, getReactBootstrap} from './lib/bootstrap';
import {EddingtonPage} from './pages/eddington-page';
import {GearPage} from './pages/gear-page';
import {HeatmapPage} from './pages/heatmap-page';
import {MilestonesPage} from './pages/milestones-page';
import {OverviewPage} from './pages/overview-page';
import {PhotosPage} from './pages/photos-page';
import {RacePlannerPage} from './pages/race-planner-page';
import {RewindPage} from './pages/rewind-page';
import {RoadmapPage} from './pages/roadmap-page';
import {SegmentsPage} from './pages/segments-page';
import {TrainingPlansPage} from './pages/training-plans-page';

function readDarkModePreference(): boolean {
    return window.localStorage.getItem('theme') === 'dark';
}

function readSidebarPreference(): boolean {
    return window.localStorage.getItem('sideNavCollapsed') === 'true';
}

export default function App() {
    const bootstrap = getReactBootstrap();
    const basename = useMemo(() => buildPreviewBasename(bootstrap.basePath), [bootstrap.basePath]);
    const [darkMode, setDarkMode] = useState<boolean>(readDarkModePreference);
    const [sidebarCollapsed, setSidebarCollapsed] = useState<boolean>(readSidebarPreference);

    useEffect(() => {
        document.documentElement.setAttribute('data-theme', darkMode ? 'dark' : 'light');
        window.localStorage.setItem('theme', darkMode ? 'dark' : 'light');
    }, [darkMode]);

    useEffect(() => {
        if (sidebarCollapsed) {
            document.documentElement.setAttribute('data-sidebar-collapsed', '');
        } else {
            document.documentElement.removeAttribute('data-sidebar-collapsed');
        }
        window.localStorage.setItem('sideNavCollapsed', String(sidebarCollapsed));
    }, [sidebarCollapsed]);

    return (
        <BrowserRouter basename={basename}>
            <AppShell
                bootstrap={bootstrap}
                sidebarCollapsed={sidebarCollapsed}
                darkMode={darkMode}
                onToggleSidebar={() => setSidebarCollapsed((current) => !current)}
                onToggleDarkMode={() => setDarkMode((current) => !current)}
            >
                <Routes>
                    <Route path="/" element={<OverviewPage bootstrap={bootstrap} />} />
                    <Route path="/activities" element={<ActivitiesPage bootstrap={bootstrap} />} />
                    <Route path="/best-efforts" element={<BestEffortsPage bootstrap={bootstrap} />} />
                    <Route path="/challenges" element={<ChallengesPage bootstrap={bootstrap} />} />
                    <Route path="/eddington" element={<EddingtonPage bootstrap={bootstrap} />} />
                    <Route path="/gear" element={<GearPage bootstrap={bootstrap} />} />
                    <Route path="/heatmap" element={<HeatmapPage bootstrap={bootstrap} />} />
                    <Route path="/milestones" element={<MilestonesPage bootstrap={bootstrap} />} />
                    <Route path="/photos" element={<PhotosPage bootstrap={bootstrap} />} />
                    <Route path="/race-planner" element={<RacePlannerPage bootstrap={bootstrap} />} />
                    <Route path="/race-planner/plan/:trainingPlanId" element={<RacePlannerPage bootstrap={bootstrap} />} />
                    <Route path="/rewind" element={<RewindPage bootstrap={bootstrap} />} />
                    <Route path="/rewind/:rewindOption" element={<RewindPage bootstrap={bootstrap} />} />
                    <Route path="/segments" element={<SegmentsPage bootstrap={bootstrap} />} />
                    <Route path="/training-plans" element={<TrainingPlansPage bootstrap={bootstrap} />} />
                    <Route path="/roadmap" element={<RoadmapPage />} />
                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </AppShell>
        </BrowserRouter>
    );
}
