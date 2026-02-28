import { defineConfig } from 'vite';

export default defineConfig({
  build: {
    outDir: 'public',
    manifest: true,
    emptyOutDir: false,
    rollupOptions: {
      input: 'assets/css/site.css',
    },
  },
});
