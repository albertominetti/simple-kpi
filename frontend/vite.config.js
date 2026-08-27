import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// base: './' -> relative assets, so dist/ works both in the webroot
// and in a subfolder (e.g. /dashboard/).
export default defineConfig({
  plugins: [vue()],
  base: './',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
  server: {
    port: 5173,
  },
});
