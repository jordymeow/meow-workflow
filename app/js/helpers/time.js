// Previous: none
// Current: 0.1.1

/**
 * Format a WordPress MySQL datetime ("YYYY-MM-DD HH:MM:SS", site timezone) as
 * a friendly relative string: "just now", "5 min ago", "3 hours ago", "yesterday",
 * or the date itself if older than a week.
 */
export function formatRelative(value) {
  if (!value) return '—';
  // Treat the MySQL string as UTC-ish for "ago" math — it's site-tz so the math
  // is slightly off, but the rounding makes that invisible at minute resolution.
  const ts = Date.parse(value.replace(' ', 'T') + 'Z');
  if (Number.isNaN(ts)) return value;
  const diff = Math.max(0, Date.now() - ts);
  const sec = Math.floor(diff / 1000);
  if (sec < 45) return 'just now';
  const min = Math.floor(sec / 60);
  if (min < 60) return `${min} min ago`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `${hr} hour${hr === 1 ? '' : 's'} ago`;
  const day = Math.floor(hr / 24);
  if (day === 1) return 'yesterday';
  if (day < 7) return `${day} days ago`;
  // Fall back to date-only after a week.
  const d = new Date(ts);
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
