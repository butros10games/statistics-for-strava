import path from 'node:path';
import {defineConfig} from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    root: path.resolve(__dirname, 'frontend'),
    base: './',
    plugins: [react(), tailwindcss()],
    server: {
        host: '0.0.0.0',
        port: 5174,
    },
    preview: {
        host: '0.0.0.0',
        port: 4174,
    },
    build: {
        cssCodeSplit: false,
        emptyOutDir: true,
        outDir: path.resolve(__dirname, 'public/react/dist'),
        rollupOptions: {
            input: {
                app: path.resolve(__dirname, 'frontend/src/main.tsx'),
                auth: path.resolve(__dirname, 'frontend/src/auth-main.tsx'),
            },
            output: {
                entryFileNames: (chunkInfo) => chunkInfo.name === 'auth' ? 'auth.js' : 'app.js',
                chunkFileNames: 'chunks/[name]-[hash].js',
                assetFileNames: (assetInfo) => assetInfo.name?.endsWith('.css')
                    ? 'app.css'
                    : 'assets/[name]-[hash][extname]',
            },
        },
    },
});
