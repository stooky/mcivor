// @ts-check
import { defineConfig } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';

// https://astro.build/config
export default defineConfig({
  site: 'https://ccansam.com',
  integrations: [sitemap()],
  build: {
    // Inline CSS to eliminate render-blocking stylesheet requests
    inlineStylesheets: 'always',
  },
  vite: {
    plugins: [tailwindcss()],
  },
  image: {
    // Use sharp for image optimization
    service: {
      entrypoint: 'astro/assets/services/sharp',
      config: {
        limitInputPixels: false,
      },
    },
    // Default formats for optimization
    domains: [],
  },
});
