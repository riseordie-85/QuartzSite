// Defines what a Quartz app page needs. Each Markdown file in src/content/apps/
// is checked against this schema at build time, so a typo shows up as a clear error.
import { defineCollection } from 'astro:content';
import { glob } from 'astro/loaders';
import { z } from 'astro/zod';

const apps = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/apps' }),
  schema: z.object({
    name: z.string(),
    tagline: z.string(),
    // Short line used in the app list on the home page.
    summary: z.string(),
    status: z.enum(['released', 'beta', 'in-development', 'planned']),
    // Which stone color the app uses. Also decides which crystal facet it lights.
    stone: z.enum(['rose', 'citrine', 'blue', 'amethyst', 'aventurine', 'clear']),
    // Position in the app list and on the crystal (1–6).
    order: z.number(),
    platforms: z.array(z.enum(['phone', 'fold', 'tablet', 'e-reader'])).default([]),
    // Public GitHub repo with the source code, as "owner/name". Shows a source link.
    // Leave it out for private repos, so visitors don't hit a 404.
    repo: z.string().optional(),
    // Public GitHub repo whose releases hold the downloads. Defaults to `repo`.
    // Use this when the code is private: publish releases to a public repo instead.
    releases: z.string().optional(),
    // What the download box offers: an Android APK, a firmware file, or nothing yet.
    download: z.enum(['apk', 'firmware', 'none']).default('none'),
    minAndroid: z.string().optional(),
    // Path under /public, e.g. /icons/notes.png. Falls back to a drawn Q mark.
    icon: z.string().optional(),
    features: z
      .array(
        z.object({
          title: z.string(),
          text: z.string(),
          planned: z.boolean().default(false),
        }),
      )
      .default([]),
    privacy: z.array(z.string()).default([]),
    screenshots: z
      .array(
        z.object({
          src: z.string(),
          alt: z.string(),
          device: z.enum(['phone', 'fold', 'tablet']).default('phone'),
        }),
      )
      .default([]),
    // Screenshot slots to show as placeholders until real ones are added.
    screenshotSlots: z.array(z.enum(['phone', 'fold', 'tablet'])).default([]),
    support: z
      .object({
        kofi: z.string().optional(),
        charity: z.object({ name: z.string(), url: z.string() }).optional(),
      })
      .optional(),
  }),
});

export const collections = { apps };
