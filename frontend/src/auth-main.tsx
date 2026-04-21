import React from 'react';
import ReactDOM from 'react-dom/client';
import {AuthPortalApp} from './auth-portal';
import './styles/app.css';

ReactDOM.createRoot(document.getElementById('react-root')!).render(
    <React.StrictMode>
        <AuthPortalApp />
    </React.StrictMode>
);
