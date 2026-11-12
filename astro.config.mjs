// @ts-check
import { defineConfig } from 'astro/config';

// Change `site` to wherever the Quartz site will live.
export default defineConfig({
  site: 'https://quartz.riseordie.net',
  // Outputs /notes/index.html etc. so plain Apache hosting (GoDaddy) serves clean URLs.
  build: { format: 'directory' },
  trailingSlash: 'ignore',
});
