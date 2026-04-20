import {useEffect, useMemo, useState} from 'react';
import {BrowserRouter, Navigate, Route, Routes} from 'react-router-dom';
import {AppShell} from './components/app-shell';
import {buildPreviewBasename, getReactBootstrap} from './lib/bootstrap';
import {OverviewPage} from './pages/overview-page';
import {RoadmapPage} from './pages/roadmap-page';
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
                    <Route path="/training-plans" element={<TrainingPlansPage bootstrap={bootstrap} />} />
                    <Route path="/roadmap" element={<RoadmapPage />} />
                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </AppShell>
        </BrowserRouter>
    );
}
