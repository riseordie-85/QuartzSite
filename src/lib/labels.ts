export const statusLabel = {
  released: 'Released',
  beta: 'Beta',
  'in-development': 'In development',
  planned: 'Planned',
} as const;

const platformName = {
  phone: 'phones',
  fold: 'folding phones',
  tablet: 'tablets',
  'e-reader': 'Xteink e-readers',
} as const;

// ['phone', 'fold', 'tablet'] -> "For phones, folding phones and tablets"
export function platformText(platforms: (keyof typeof platformName)[]): string {
  const names = platforms.map((p) => platformName[p]);
  if (names.length === 0) return '';
  const list =
    names.length === 1 ? names[0] : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
  return `For ${list}`;
}
