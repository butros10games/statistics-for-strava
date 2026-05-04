import type {ComponentType, ReactNode, SVGProps} from 'react';
import {NavLink, type NavLinkRenderProps} from 'react-router-dom';
import {buildAppPath, type ReactPreviewBootstrap} from '../lib/bootstrap';

interface AppShellProps {
    bootstrap: ReactPreviewBootstrap;
    sidebarCollapsed: boolean;
    darkMode: boolean;
    onToggleSidebar: () => void;
    onToggleDarkMode: () => void;
    children: ReactNode;
}

type IconComponent = ComponentType<SVGProps<SVGSVGElement>>;

interface NavigationLink {
    to: string;
    label: string;
    icon: IconComponent;
    badge?: number | string;
}

interface ExternalLink {
    href: string;
    label: string;
    icon: IconComponent;
}

const DashboardIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 6.025A7.5 7.5 0 1 0 17.975 14H10V6.025Z" />
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13.5 3c-.169 0-.334.014-.5.025V11h7.975c.011-.166.025-.331.025-.5A7.5 7.5 0 0 0 13.5 3Z" />
    </svg>
);

const ActivitiesIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 9h6m-6 3h6m-6 3h6M6.996 9h.01m-.01 3h.01m-.01 3h.01M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z" />
    </svg>
);

const GearIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="m10.051 8.102-3.778.322-1.994 1.994a.94.94 0 0 0 .533 1.6l2.698.316m8.39 1.617-.322 3.78-1.994 1.994a.94.94 0 0 1-1.595-.533l-.4-2.652m8.166-11.174a1.366 1.366 0 0 0-1.12-1.12c-1.616-.279-4.906-.623-6.38.853-1.671 1.672-5.211 8.015-6.31 10.023a.932.932 0 0 0 .162 1.111l.828.835.833.832a.932.932 0 0 0 1.111.163c2.008-1.102 8.35-4.642 10.021-6.312 1.475-1.478 1.133-4.77.855-6.385Zm-2.961 3.722a1.88 1.88 0 1 1-3.76 0 1.88 1.88 0 0 1 3.76 0Z" />
    </svg>
);

const SegmentsIcon: IconComponent = (props) => (
    <svg viewBox="0 0 256 256" fill="currentColor" aria-hidden="true" {...props}>
        <path d="M217.45557,38.544a35.9967,35.9967,0,0,0-57.937,40.96679L79.5105,159.51855a36.05906,36.05906,0,0,0-40.96607,7.0254H38.544a36.00029,36.00029,0,1,0,57.93737,9.94531L176.4895,96.48145A35.99663,35.99663,0,0,0,217.45557,38.544ZM72.48584,200.48535a12.00027,12.00027,0,0,1-16.97119-16.9707h-.00049a12.00044,12.00044,0,0,1,16.97168,16.9707Zm128-128a12.01673,12.01673,0,0,1-16.969.00244l-.0022-.00244a12.0001,12.0001,0,1,1,16.97119,0Z" />
    </svg>
);

const CalendarIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 10h16m-8-3V4M7 7V4m10 3V4M5 20h14a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1Zm3-7h.01v.01H8V13Zm4 0h.01v.01H12V13Zm4 0h.01v.01H16V13Zm-8 4h.01v.01H8V17Zm4 0h.01v.01H12V17Zm4 0h.01v.01H16V17Z" />
    </svg>
);

const RacePlannerIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" />
    </svg>
);

const PlanManagerIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7V3m8 4V3m-9 8h10m-12 9h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2Z" />
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M7 14h4m2 0h4M7 18h10" />
    </svg>
);

const ChartIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4.5V19a1 1 0 0 0 1 1h15M7 14l4-4 4 4 5-5m0 0h-3.207M20 9v3.207" />
    </svg>
);

const HeatmapIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeWidth="2" d="M3 8.70938C3 7.23584 3 6.49907 3.39264 6.06935C3.53204 5.91678 3.70147 5.79466 3.89029 5.71066C4.42213 5.47406 5.12109 5.70705 6.51901 6.17302C7.58626 6.52877 8.11989 6.70665 8.6591 6.68823C8.85714 6.68147 9.05401 6.65511 9.24685 6.60952C9.77191 6.48541 10.2399 6.1734 11.176 5.54937L12.5583 4.62778C13.7574 3.82843 14.3569 3.42876 15.0451 3.3366C15.7333 3.24444 16.4168 3.47229 17.7839 3.92799L18.9487 4.31624C19.9387 4.64625 20.4337 4.81126 20.7169 5.20409C21 5.59692 21 6.11871 21 7.16229V15.2907C21 16.7642 21 17.501 20.6074 17.9307C20.468 18.0833 20.2985 18.2054 20.1097 18.2894C19.5779 18.526 18.8789 18.293 17.481 17.827C16.4137 17.4713 15.8801 17.2934 15.3409 17.3118C15.1429 17.3186 14.946 17.3449 14.7532 17.3905C14.2281 17.5146 13.7601 17.8266 12.824 18.4507L11.4417 19.3722C10.2426 20.1716 9.64311 20.5713 8.95493 20.6634C8.26674 20.7556 7.58319 20.5277 6.21609 20.072L5.05132 19.6838C4.06129 19.3538 3.56627 19.1888 3.28314 18.7959C3 18.4031 3 17.8813 3 16.8377V8.70938Z" />
        <path stroke="currentColor" strokeWidth="2" d="M9 6.63867V20.5" />
        <path stroke="currentColor" strokeWidth="2" d="M15 3V17" />
    </svg>
);

const TrophyIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" {...props}>
        <path d="M21,4H18V3a1,1,0,0,0-1-1H7A1,1,0,0,0,6,3V4H3A1,1,0,0,0,2,5V8a4,4,0,0,0,4,4H7.54A6,6,0,0,0,11,13.91V16H10a3,3,0,0,0-3,3v2a1,1,0,0,0,1,1h8a1,1,0,0,0,1-1V19a3,3,0,0,0-3-3H13V13.91A6,6,0,0,0,16.46,12H18a4,4,0,0,0,4-4V5A1,1,0,0,0,21,4ZM6,10A2,2,0,0,1,4,8V6H6V8a6,6,0,0,0,.35,2Zm8,8a1,1,0,0,1,1,1v1H9V19a1,1,0,0,1,1-1ZM16,8A4,4,0,0,1,8,8V4h8Zm4,0a2,2,0,0,1-2,2h-.35A6,6,0,0,0,18,8V6h2Z" />
    </svg>
);

const MilestonesIcon: IconComponent = (props) => (
    <svg viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true" {...props}>
        <path d="M80-200v-80h240v-240h240v-240h320v80H640v240H400v240H80Z" />
    </svg>
);

const RewindIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3M3.22302 14C4.13247 18.008 7.71683 21 12 21c4.9706 0 9-4.0294 9-9 0-4.97056-4.0294-9-9-9-3.72916 0-6.92858 2.26806-8.29409 5.5M7 9H3V5" />
    </svg>
);

const ChallengesIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="m8.032 12 1.984 1.984 4.96-4.96m4.55 5.272.893-.893a1.984 1.984 0 0 0 0-2.806l-.893-.893a1.984 1.984 0 0 1-.581-1.403V7.04a1.984 1.984 0 0 0-1.984-1.984h-1.262a1.983 1.983 0 0 1-1.403-.581l-.893-.893a1.984 1.984 0 0 0-2.806 0l-.893.893a1.984 1.984 0 0 1-1.403.581H7.04A1.984 1.984 0 0 0 5.055 7.04v1.262c0 .527-.209 1.031-.581 1.403l-.893.893a1.984 1.984 0 0 0 0 2.806l.893.893c.372.372.581.876.581 1.403v1.262a1.984 1.984 0 0 0 1.984 1.984h1.262c.527 0 1.031.209 1.403.581l.893.893a1.984 1.984 0 0 0 2.806 0l.893-.893a1.985 1.985 0 0 1 1.403-.581h1.262a1.984 1.984 0 0 0 1.984-1.984V15.7c0-.527.209-1.031.581-1.403Z" />
    </svg>
);

const PhotosIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinejoin="round" strokeWidth="2" d="M4 18V8a1 1 0 0 1 1-1h1.5l1.707-1.707A1 1 0 0 1 8.914 5h6.172a1 1 0 0 1 .707.293L17.5 7H19a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1Z" />
        <path stroke="currentColor" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
    </svg>
);

const AccountIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19a1 1 0 0 1-1.106-1.447l.323-.646A1 1 0 0 0 8.382 15.5H7.618a1 1 0 0 0-.835 1.407l.323.646A1 1 0 0 1 6 19H5a1 1 0 0 1-.894-.553l-.764-1.528a1 1 0 0 1 .2-1.15l.457-.457a1 1 0 0 0 0-1.414l-.457-.457a1 1 0 0 1-.2-1.15l.764-1.528A1 1 0 0 1 5 8h1a1 1 0 0 1 1.106 1.447l-.323.646A1 1 0 0 0 7.618 11.5h.764a1 1 0 0 0 .835-1.407l-.323-.646A1 1 0 0 1 10 8h4a1 1 0 0 1 1.106 1.447l-.323.646A1 1 0 0 0 15.618 11.5h.764a1 1 0 0 0 .835-1.407l-.323-.646A1 1 0 0 1 18 8h1a1 1 0 0 1 .894.553l.764 1.528a1 1 0 0 1-.2 1.15l-.457.457a1 1 0 0 0 0 1.414l.457.457a1 1 0 0 1 .2 1.15l-.764 1.528A1 1 0 0 1 19 19h-1a1 1 0 0 1-1.106-1.447l.323-.646A1 1 0 0 0 16.382 15.5h-.764a1 1 0 0 0-.835 1.407l.323.646A1 1 0 0 1 14 19h-4Z" />
        <path stroke="currentColor" strokeWidth="2" d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
    </svg>
);

const BadgeIcon: IconComponent = (props) => (
    <svg viewBox="0 0 508 508" fill="currentColor" aria-hidden="true" {...props}>
        <path d="M465.7 96.55H324.9v-21.6c0-7.8-6.3-14.1-14.1-14.1H197.2c-7.8 0-14.1 6.3-14.1 14.1v21.6H42.3c-23.3 0-42.3 19-42.3 42.3v265.9c0 23.3 19 42.3 42.3 42.3h423.3c23.3 0 42.3-19 42.3-42.3v-265.9c.1-23.3-18.9-42.3-42.2-42.3Zm-169-7.5v32.1h-85.4v-32.1h85.4Zm169 329.8H42.3c-7.8 0-14.1-6.3-14.1-14.1v-265.9c0-7.8 6.3-14.1 14.1-14.1h140.8v10.5c0 7.8 6.3 14.1 14.1 14.1h113.6c7.8 0 14.1-6.3 14.1-14.1v-10.5h140.7c7.8 0 14.1 6.3 14.1 14.1v265.9h.1c0 7.8-6.3 14.1-14.1 14.1Z" />
        <path d="M440.6 173.65H254c-7.8 0-14.1 6.3-14.1 14.1s6.3 14.1 14.1 14.1h186.6c7.8 0 14.1-6.3 14.1-14.1s-6.3-14.1-14.1-14.1Zm0 68.3H254c-7.8 0-14.1 6.3-14.1 14.1s6.3 14.1 14.1 14.1h186.6c7.8 0 14.1-6.3 14.1-14.1 0-7.8-6.3-14.1-14.1-14.1Zm0 68.4H254c-7.8 0-14.1 6.3-14.1 14.1 0 7.8 6.3 14.1 14.1 14.1h186.6c7.8 0 14.1-6.3 14.1-14.1s-6.3-14.1-14.1-14.1Zm-260.5-149.9H67.4c-7.8 0-14.1 6.3-14.1 14.1v130.5c0 7.8 6.3 14.1 14.1 14.1h112.7c7.8 0 14.1-6.3 14.1-14.1v-130.5c0-7.7-6.3-14.1-14.1-14.1Zm-14.1 130.5H81.6v-102.2H166v102.2Zm14.1 63.9H67.4c-7.8 0-14.1 6.3-14.1 14.1 0 7.8 6.3 14.1 14.1 14.1h112.7c7.8 0 14.1-6.3 14.1-14.1s-6.3-14.1-14.1-14.1Z" />
    </svg>
);

const RecoveryIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 21c4.97 0 9-4.03 9-9 0-4.216-2.9-7.755-6.812-8.733M12 21c-4.97 0-9-4.03-9-9 0-4.216 2.9-7.755 6.812-8.733M12 21v-4m0-10V3m5.657 3.343-2.829 2.829M9.172 14.828l-2.829 2.829m11.314 0-2.829-2.829M9.172 9.172 6.343 6.343" />
    </svg>
);

const BlocksIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6Zm0 9.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25A2.25 2.25 0 0 1 13.5 8.25V6Zm0 9.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
    </svg>
);

const RaceEventsIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0" />
    </svg>
);

const PreviewIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3.75 7.5A2.25 2.25 0 0 1 6 5.25h12A2.25 2.25 0 0 1 20.25 7.5v9A2.25 2.25 0 0 1 18 18.75H6A2.25 2.25 0 0 1 3.75 16.5v-9Z" />
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8.25 9.75h7.5M8.25 14.25h4.5" />
    </svg>
);

const RoadmapIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 12h6m6 0h6M9 12a2 2 0 1 0 0 0Zm6 0a2 2 0 1 0 0 0ZM12 5.25v13.5" />
    </svg>
);

const ExternalLinkIcon: IconComponent = (props) => (
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13.5 6H7.5A2.25 2.25 0 0 0 5.25 8.25v8.25A2.25 2.25 0 0 0 7.5 18.75h8.25A2.25 2.25 0 0 0 18 16.5v-6" />
        <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 5.25h3.75V9m0-3.75-8.25 8.25" />
    </svg>
);

const previewLinks: NavigationLink[] = [
    {to: '/', label: 'Overview', icon: PreviewIcon},
    {to: '/roadmap', label: 'Roadmap', icon: RoadmapIcon},
];

function navLinkClass({isActive}: NavLinkRenderProps) {
    return [
        'group flex items-center gap-2 rounded-lg px-1.5 py-1.5 text-sm font-medium transition sidebar-collapsed:justify-center sidebar-collapsed:px-1.5',
        isActive
            ? 'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-white'
            : 'text-gray-700 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white',
    ].join(' ');
}

function navIconClass(isActive: boolean) {
    return `h-[17px] w-[17px] shrink-0 ${isActive ? 'text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400'}`;
}

function badgeClass(isActive: boolean) {
    return [
        'sidebar-collapsed:hidden rounded-full px-1.5 py-0 text-[9px] font-medium',
        isActive
            ? 'bg-white text-gray-700 dark:bg-gray-700 dark:text-gray-100'
            : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    ].join(' ');
}

function externalLinkClass() {
    return 'group flex items-center gap-2 rounded-lg px-1.5 py-1.5 text-[13px] font-medium text-gray-700 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white sidebar-collapsed:justify-center sidebar-collapsed:px-1.5';
}

function sectionLinksClass() {
    return 'space-y-0.5 py-1';
}

export function AppShell({
    bootstrap,
    sidebarCollapsed,
    darkMode,
    onToggleSidebar,
    onToggleDarkMode,
    children,
}: AppShellProps) {
    const isPreview = bootstrap.experience === 'preview';

    const activitySection: NavigationLink[] = [
        {to: '/activities', label: 'Activities', icon: ActivitiesIcon, badge: bootstrap.counts.activities},
        ...(bootstrap.counts.hasGear ? [{to: '/gear', label: 'Gear', icon: GearIcon}] : []),
        {to: '/segments', label: 'Segments', icon: SegmentsIcon},
    ];

    const trainingSection: NavigationLink[] = [
        {to: '/monthly-stats', label: 'Training calendar', icon: CalendarIcon},
        {to: '/race-planner', label: 'Race planner', icon: RacePlannerIcon},
        {to: '/training-plans', label: 'Plan manager', icon: PlanManagerIcon},
        {to: '/eddington', label: 'Eddington', icon: ChartIcon},
        {to: '/heatmap', label: 'Heatmap', icon: HeatmapIcon},
        ...(bootstrap.counts.hasBestEfforts ? [{to: '/best-efforts', label: 'Best efforts', icon: TrophyIcon}] : []),
        {to: '/milestones', label: 'Milestones', icon: MilestonesIcon},
        {to: '/rewind', label: 'Rewind', icon: RewindIcon},
    ];

    const librarySection: NavigationLink[] = [
        ...(bootstrap.counts.challenges > 0 ? [{to: '/challenges', label: 'Challenges', icon: ChallengesIcon, badge: bootstrap.counts.challenges}] : []),
        ...(bootstrap.counts.photos > 0 ? [{to: '/photos', label: 'Photos', icon: PhotosIcon, badge: bootstrap.counts.photos}] : []),
    ];

    const utilitySection: NavigationLink[] = [
        {to: '/account/settings', label: 'Account settings', icon: AccountIcon},
        {to: '/badges', label: 'Badges', icon: BadgeIcon},
        {to: '/race-events', label: 'Race events', icon: RaceEventsIcon},
        {to: '/training-blocks', label: 'Training blocks', icon: BlocksIcon},
        {to: '/recovery-check-in', label: 'Recovery check-in', icon: RecoveryIcon},
    ];

    const quickLinks: ExternalLink[] = isPreview
        ? [
            {href: buildAppPath(bootstrap.basePath, 'dashboard'), label: 'Classic dashboard', icon: ExternalLinkIcon},
            {href: buildAppPath(bootstrap.basePath, 'training-plans'), label: 'Classic plan manager', icon: ExternalLinkIcon},
            {href: buildAppPath(bootstrap.basePath, 'ai/chat'), label: 'AI chat', icon: ExternalLinkIcon},
        ]
        : [
            {href: buildAppPath(bootstrap.basePath, 'badge.html'), label: 'Badge export', icon: ExternalLinkIcon},
            {href: buildAppPath(bootstrap.basePath, 'ai/chat'), label: 'AI chat', icon: ExternalLinkIcon},
        ];

    return (
        <div className="min-h-screen bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-white">
            <header className="fixed inset-x-0 top-0 z-40 border-b border-gray-200 bg-white px-3.5 py-1.5 dark:border-gray-800 dark:bg-gray-950">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-2">
                        <button
                            type="button"
                            onClick={onToggleSidebar}
                            className="hidden rounded-lg p-1.5 text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-200 md:inline-flex dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white"
                            aria-label={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                        >
                            <svg className="h-5 w-5" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true">
                                <path d={sidebarCollapsed ? 'M500-592v224q0 14 12 19t22-5l98-98q12-12 12-28t-12-28l-98-98q-10-10-22-5t-12 19ZM200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h560q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Zm120-80v-560H200v560h120Zm80 0h360v-560H400v560Zm-80 0H200h120Z' : 'M660-368v-224q0-14-12-19t-22 5l-98 98q-12 12-12 28t12 28l98 98q10 10 22 5t12-19ZM200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h560q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Zm120-80v-560H200v560h120Zm80 0h360v-560H400v560Zm-80 0H200h120Z'} />
                            </svg>
                        </button>

                        <a href={buildAppPath(bootstrap.basePath, 'dashboard')} className="flex min-w-0 items-center gap-2">
                            <img
                                src={buildAppPath(bootstrap.basePath, 'assets/images/logo.svg')}
                                alt="Statistics for Strava"
                                className="h-[26px] w-[26px] rounded-full"
                            />
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-1 text-sm font-semibold text-gray-900 sm:text-base md:text-[1.75rem] dark:text-white">
                                    <span className="truncate">Statistics for Strava</span>
                                    {bootstrap.subtitle ? <span className="hidden text-gray-300 md:inline">|</span> : null}
                                    {bootstrap.subtitle ? <span className="hidden text-gray-500 md:inline dark:text-gray-400">{bootstrap.subtitle}</span> : null}
                                </div>
                            </div>
                        </a>
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={onToggleDarkMode}
                            className="inline-flex items-center rounded-lg p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white"
                            aria-label={darkMode ? 'Enable light mode' : 'Enable dark mode'}
                        >
                            {darkMode ? (
                                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 3v2.25M12 18.75V21m6.364-15.364-1.591 1.591M7.227 16.773l-1.591 1.591M21 12h-2.25M5.25 12H3m15.364 6.364-1.591-1.591M7.227 7.227 5.636 5.636M15.75 12A3.75 3.75 0 1 1 12 8.25 3.75 3.75 0 0 1 15.75 12Z" />
                                </svg>
                            ) : (
                                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 12.79A9 9 0 0 1 11.21 3a7.5 7.5 0 1 0 9.79 9.79Z" />
                                </svg>
                            )}
                        </button>

                        <div className="flex items-center gap-1.5 rounded-full border border-gray-200 bg-white p-0.5 pl-2 dark:border-gray-700 dark:bg-gray-900">
                            <div className="hidden text-right text-[13px] sm:block">
                                <div className="font-medium text-gray-900 dark:text-white">{bootstrap.athlete.name}</div>
                                <div className="text-xs text-gray-500 dark:text-gray-400">Workspace</div>
                            </div>
                            {bootstrap.profilePictureUrl ? (
                                <img src={bootstrap.profilePictureUrl} alt={bootstrap.athlete.name} className="h-[26px] w-[26px] rounded-full object-cover" />
                            ) : (
                                <div className="flex h-[26px] w-[26px] items-center justify-center rounded-full bg-strava-orange text-sm font-semibold text-white">
                                    {bootstrap.athlete.initial}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </header>

            <aside className="fixed left-0 top-0 z-30 h-screen w-56 border-r border-gray-200 bg-white pt-14 dark:border-gray-800 dark:bg-gray-950 sidebar-collapsed:w-20">
                <div className="h-full overflow-hidden">
                    <div className="sidebar-collapsed:max-h-full max-h-[calc(100vh-104px)] overflow-y-auto px-2 py-3.5 sidebar-collapsed:px-1.5 sidebar-collapsed:py-3">
                        {isPreview ? (
                            <ul className="space-y-1 pb-2">
                                {previewLinks.map((link) => (
                                    <li key={link.to}>
                                        <NavLink to={link.to} end={link.to === '/'} className={navLinkClass}>
                                            {({isActive}) => (
                                                <>
                                                    <link.icon className={navIconClass(isActive)} />
                                                    <span className="flex-1 whitespace-nowrap sidebar-collapsed:hidden">{link.label}</span>
                                                </>
                                            )}
                                        </NavLink>
                                    </li>
                                ))}
                            </ul>
                        ) : null}

                        <ul className={sectionLinksClass()}>
                            <li>
                                <NavLink to="/dashboard" className={navLinkClass}>
                                    {({isActive}) => (
                                        <>
                                            <DashboardIcon className={navIconClass(isActive)} />
                                            <span className="flex-1 whitespace-nowrap sidebar-collapsed:hidden">Dashboard</span>
                                        </>
                                    )}
                                </NavLink>
                            </li>
                        </ul>

                        <ul className={sectionLinksClass()}>
                            {activitySection.map((link) => (
                                <li key={link.to}>
                                    <NavLink to={link.to} className={navLinkClass}>
                                        {({isActive}) => (
                                            <>
                                                <link.icon className={navIconClass(isActive)} />
                                                <span className="flex-1 whitespace-nowrap sidebar-collapsed:hidden">{link.label}</span>
                                                {link.badge ? <span className={badgeClass(isActive)}>{link.badge}</span> : null}
                                            </>
                                        )}
                                    </NavLink>
                                </li>
                            ))}
                        </ul>

                        <ul className={sectionLinksClass()}>
                            {trainingSection.map((link) => (
                                <li key={link.to}>
                                    <NavLink to={link.to} className={navLinkClass}>
                                        {({isActive}) => (
                                            <>
                                                <link.icon className={navIconClass(isActive)} />
                                                <span className="flex-1 whitespace-nowrap sidebar-collapsed:hidden">{link.label}</span>
                                                {link.badge ? <span className={badgeClass(isActive)}>{link.badge}</span> : null}
                                            </>
                                        )}
                                    </NavLink>
                                </li>
                            ))}
                        </ul>

                        {librarySection.length > 0 ? (
                            <ul className={sectionLinksClass()}>
                                {librarySection.map((link) => (
                                    <li key={link.to}>
                                        <NavLink to={link.to} className={navLinkClass}>
                                            {({isActive}) => (
                                                <>
                                                    <link.icon className={navIconClass(isActive)} />
                                                    <span className="flex-1 whitespace-nowrap sidebar-collapsed:hidden">{link.label}</span>
                                                    {link.badge ? <span className={badgeClass(isActive)}>{link.badge}</span> : null}
                                                </>
                                            )}
                                        </NavLink>
                                    </li>
                                ))}
                            </ul>
                        ) : null}

                        <ul className={sectionLinksClass()}>
                            {utilitySection.map((link) => (
                                <li key={link.to}>
                                    <NavLink to={link.to} className={navLinkClass}>
                                        {({isActive}) => (
                                            <>
                                                <link.icon className={navIconClass(isActive)} />
                                                <span className="flex-1 whitespace-nowrap sidebar-collapsed:hidden">{link.label}</span>
                                            </>
                                        )}
                                    </NavLink>
                                </li>
                            ))}
                        </ul>

                        <div className="border-t border-gray-200 pt-2.5 dark:border-gray-800">
                            <div className="px-1.5 pb-1 text-[9px] font-semibold uppercase tracking-[0.18em] text-gray-400 sidebar-collapsed:hidden dark:text-gray-500">
                                {isPreview ? 'Classic routes' : 'Quick links'}
                            </div>
                            <ul className="space-y-1">
                                {quickLinks.map((link) => (
                                    <li key={link.href}>
                                        <a href={link.href} className={externalLinkClass()}>
                                            <link.icon className="h-[17px] w-[17px] shrink-0 text-gray-500 dark:text-gray-400" />
                                            <span className="flex-1 whitespace-nowrap sidebar-collapsed:hidden">{link.label}</span>
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>

                    <div className="absolute bottom-0 left-0 w-full border-t border-gray-200 bg-white p-1.5 text-center text-xs text-gray-500 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-400">
                        <div className="sidebar-collapsed:hidden flex items-center justify-center gap-1">
                            <span className="rounded-sm bg-gray-100 px-2.5 py-0.5 font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                Workspace
                            </span>
                        </div>
                        <div className="mt-1 flex items-center justify-center gap-1">
                            <img
                                className="h-3"
                                src={buildAppPath(bootstrap.basePath, 'assets/images/strava/powered-by-strava.svg')}
                                alt="Powered by Strava"
                            />
                            <span className="sidebar-collapsed:hidden">&amp; ☕</span>
                        </div>
                    </div>
                </div>
            </aside>

            <main className="min-h-screen p-4 pt-20 text-gray-900 transition-[margin] duration-300 md:ml-56 dark:text-white sidebar-collapsed:md:ml-20">
                <div className="mx-auto max-w-[1260px]">{children}</div>
            </main>
        </div>
    );
}
