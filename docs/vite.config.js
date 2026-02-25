import { defineConfig } from 'vite';

export default defineConfig({
  build: {
    outDir: 'public/assets',
    manifest: true,
    emptyOutDir: false,
    rollupOptions: {
      input: [
		'assets/css/docs.css',
		'assets/js/docs.js'
	  ],
    },
  },
});
