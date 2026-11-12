// Reads the latest GitHub release for a repo at build time.
// If the repo is private, has no releases, or GitHub can't be reached,
// this returns null and the page shows a "no release yet" state instead of failing.
import { marked } from 'marked';

export interface ReleaseAsset {
  name: string;
  url: string;
  size: number;
  sha256?: string;
}

export interface ReleaseInfo {
  version: string;
  date: Date;
  pageUrl: string;
  notesHtml: string;
  assets: ReleaseAsset[];
  primary?: ReleaseAsset;
}

const cache = new Map<string, Promise<ReleaseInfo | null>>();

function env(name: string): string | undefined {
  return (import.meta.env as Record<string, string | undefined>)[name] ?? process.env[name];
}

export function getLatestRelease(
  repo: string | undefined,
  kind: 'apk' | 'firmware' | 'none',
): Promise<ReleaseInfo | null> {
  if (!repo || kind === 'none') return Promise.resolve(null);
  const key = `${repo}:${kind}`;
  if (!cache.has(key)) cache.set(key, load(repo, kind));
  return cache.get(key)!;
}

async function load(repo: string, kind: 'apk' | 'firmware'): Promise<ReleaseInfo | null> {
  if (env('MOCK_RELEASES')) return mockRelease(repo, kind);

  const headers: Record<string, string> = {
    Accept: 'application/vnd.github+json',
    'User-Agent': 'quartz-site-build',
  };
  // Optional: a token raises GitHub's rate limit and lets the build see private repos.
  const token = env('GITHUB_TOKEN');
  if (token) headers.Authorization = `Bearer ${token}`;

  try {
    const res = await fetch(`https://api.github.com/repos/${repo}/releases/latest`, { headers });
    if (!res.ok) {
      console.warn(`[quartz] No release for ${repo} (GitHub answered ${res.status}).`);
      return null;
    }
    const data = await res.json();
    const assets: ReleaseAsset[] = (data.assets ?? []).map((a: any) => ({
      name: a.name,
      url: a.browser_download_url,
      size: a.size,
      sha256: typeof a.digest === 'string' && a.digest.startsWith('sha256:') ? a.digest.slice(7) : undefined,
    }));
    return {
      version: data.tag_name ?? data.name,
      date: new Date(data.published_at ?? data.created_at),
      pageUrl: data.html_url,
      notesHtml: await marked.parse(data.body ?? ''),
      assets,
      primary: pickPrimary(assets, kind),
    };
  } catch (err) {
    console.warn(`[quartz] Couldn't reach GitHub for ${repo}:`, (err as Error).message);
    return null;
  }
}

function pickPrimary(assets: ReleaseAsset[], kind: 'apk' | 'firmware'): ReleaseAsset | undefined {
  const exts = kind === 'apk' ? ['.apk'] : ['.bin', '.uf2', '.zip'];
  return assets.find((a) => exts.some((e) => a.name.toLowerCase().endsWith(e)));
}

// Fake data so you can see the download box before publishing a real release.
// Run `npm run dev:mock` to use it.
async function mockRelease(repo: string, kind: 'apk' | 'firmware'): Promise<ReleaseInfo> {
  const name = repo.split('/')[1];
  const file = kind === 'apk' ? `${name}-1.2.0.apk` : `${name}-1.2.0.bin`;
  const asset: ReleaseAsset = {
    name: file,
    url: `https://github.com/${repo}/releases/download/v1.2.0/${file}`,
    size: 14_830_112,
    sha256: '3f9a1c0e7b52d84e6a0f2c91b7d3e5a8c4f60b2d9e1a7c3b5d8f0e2a4c6b8d0f',
  };
  return {
    version: 'v1.2.0',
    date: new Date('2026-11-10'),
    pageUrl: `https://github.com/${repo}/releases/tag/v1.2.0`,
    notesHtml: await marked.parse(
      '- Sample release notes from the mock release.\n- Real notes come from the GitHub release description.\n- Fixed a thing that was broken.',
    ),
    assets: [asset],
    primary: asset,
  };
}

export function formatSize(bytes: number): string {
  if (bytes >= 1_000_000) return `${(bytes / 1_000_000).toFixed(1)} MB`;
  return `${Math.round(bytes / 1000)} KB`;
}
