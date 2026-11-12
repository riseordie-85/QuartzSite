// Geometry for the Quartz crystal: a quartz point with six facets, one per app.
// Facet numbers match each app's `order` field.

type Pt = [number, number];

const tip: Pt = [260, 30];
const sL: Pt = [168, 162];
const sCL: Pt = [228, 188];
const sCR: Pt = [308, 182];
const sR: Pt = [358, 150];
const bL: Pt = [168, 478];
const bCL: Pt = [228, 512];
const bCR: Pt = [308, 506];
const bR: Pt = [358, 468];

export interface Facet {
  n: number;
  points: Pt[];
  // How much of the stone color shows when the facet is resting.
  rest: number;
}

export const facets: Facet[] = [
  { n: 1, points: [tip, sL, sCL], rest: 0.8 },
  { n: 2, points: [tip, sCL, sCR], rest: 0.95 },
  { n: 3, points: [tip, sCR, sR], rest: 0.7 },
  { n: 4, points: [sL, sCL, bCL, bL], rest: 0.68 },
  { n: 5, points: [sCL, sCR, bCR, bCL], rest: 0.85 },
  { n: 6, points: [sCR, sR, bR, bCR], rest: 0.55 },
];

export const toPoints = (pts: Pt[]) => pts.map((p) => p.join(',')).join(' ');

// Where the light leaves the crystal, and where each colored ray ends.
export const exitPoint: Pt = [358, 318];
export const rayEnds: Pt[] = [
  [640, 196],
  [640, 250],
  [640, 304],
  [640, 358],
  [640, 412],
  [640, 466],
];

export const viewBox = '0 0 640 540';
// A tighter box that holds only the crystal, for small marks.
export const markViewBox = '150 20 226 504';
