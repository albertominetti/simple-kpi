import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// base: './' -> asset relativi, così il dist/ funziona anche in una
// sottocartella (es. /dashboard/) oltre che nella webroot.
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
