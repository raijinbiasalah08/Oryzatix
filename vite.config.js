import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    resolve: {
        // Vite's native realpath syscall is denied by some managed Windows
        // workspaces even though the files are readable. Keeping resolved
        // paths stable avoids that Windows-only build failure.
        preserveSymlinks: process.platform === 'win32',
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/main.jsx', 'resources/js/offline-scanner.js'],
            refresh: true,
        }),
        react(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
