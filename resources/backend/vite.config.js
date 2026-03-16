import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss()],
    server: {
        port: 5174,
        strictPort: true,
        cors: true,
    },
    build: {
        outDir: 'assets/dist',
        manifest: true,
        rollupOptions: {
            input: {
                dev: 'assets/js/dev.js',
            },
        },
    },
});
