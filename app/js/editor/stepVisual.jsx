import styled from 'styled-components';
import {
  GitBranch, Split, Timer, Variable, Mail, Globe, FileText, Search, Gauge,
  Type, AlignLeft, LineChart, BarChart, Pencil, Zap, ImagePlus, ScanEye,
  Braces, File, Shuffle, PlusSquare, Edit, User, Users, Image, List,
  ToggleLeft, Tag, Tags, Play, FileCode, Send, Dices, MessageSquare,
  ShoppingCart, Repeat, SquareFunction, Clock, Webhook, MousePointerClick, Plug, Box,
  Sparkles
} from 'lucide-react';

// Maps the kebab-case `icon` string each action declares (see classes/sdk.php
// and the integration files) to a lucide component. Drawn white on the
// integration-coloured tile so a step reads as "what it does" at a glance,
// with the colour telling you which plugin it belongs to.
const ICONS = {
  'git-branch': GitBranch,
  'split': Split,
  'timer': Timer,
  'variable': Variable,
  'mail': Mail,
  'globe': Globe,
  'file-text': FileText,
  'search': Search,
  'gauge': Gauge,
  'type': Type,
  'align-left': AlignLeft,
  'line-chart': LineChart,
  'bar-chart': BarChart,
  'pencil': Pencil,
  'zap': Zap,
  'image-plus': ImagePlus,
  'scan-eye': ScanEye,
  'braces': Braces,
  'file': File,
  'shuffle': Shuffle,
  'plus-square': PlusSquare,
  'edit': Edit,
  'user': User,
  'users': Users,
  'image': Image,
  'list': List,
  'toggle-left': ToggleLeft,
  'tag': Tag,
  'tags': Tags,
  'play': Play,
  'file-code': FileCode,
  'send': Send,
  'dices': Dices,
  'message-square': MessageSquare,
  'shopping-cart': ShoppingCart,
  'repeat': Repeat,
  'square-function': SquareFunction,
  'sparkles': Sparkles
};

// Trigger icons keyed by trigger id (triggers don't carry an `icon` field).
export const TRIGGER_ICONS = {
  manual: MousePointerClick,
  schedule: Clock,
  webhook: Webhook,
  wp_hook: Plug,
  hook: Plug
};

const Tile = styled.span`
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: #fff;
  background: ${(p) => p.$color || '#3b82f6'};
  width: ${(p) => p.$size}px;
  height: ${(p) => p.$size}px;
  border-radius: ${(p) => Math.round(p.$size * 0.28)}px;
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.18),
              inset 0 -1px 2px rgba(0, 0, 0, 0.12);
`;

/**
 * A coloured rounded tile with the action's white icon centred inside.
 * `icon` is the kebab name from the action schema; `color` the integration colour.
 */
export function StepIcon({ icon, color, size = 28 }) {
  const Glyph = ICONS[icon] || Box;
  return (
    <Tile $color={color} $size={size}>
      <Glyph size={Math.round(size * 0.56)} strokeWidth={2.1} absoluteStrokeWidth />
    </Tile>
  );
}

/**
 * Just the icon glyph (no coloured tile) — for surfaces that already supply
 * their own background, like the Settings "Information" bento where the tile is
 * the integration colour. Same icon vocabulary as StepIcon so they always match.
 */
export function StepGlyph({ icon, size = 18, color = '#fff', strokeWidth = 2.1 }) {
  const Glyph = ICONS[icon] || Box;
  return <Glyph size={size} color={color} strokeWidth={strokeWidth} absoluteStrokeWidth />;
}

// Router (Branch By Value) branches cycle through this palette — used by the
// node's handles/tags AND the inspector's branch rows so the colors match.
export const ROUTER_COLORS = ['#6366f1', '#0ea5e9', '#f59e0b', '#14b8a6', '#d946ef', '#84cc16'];

/**
 * Normalize router branch config into rows of { id, name, value }.
 * Accepts the modern array shape (BranchesField) or the legacy comma-separated
 * string where each token is name, value, and id at once (the token doubling
 * as the id keeps edges wired before the upgrade working).
 */
export function normalizeBranches(raw) {
  if (Array.isArray(raw)) {
    // Empty rows are kept — the inspector needs them while the user is still
    // typing. Display (ActionNode) and the runner skip them.
    return raw.map((b, i) => {
      const name = String(b?.name ?? b?.value ?? '').trim();
      const value = String(b?.value !== undefined && b?.value !== '' ? b.value : name);
      const id = String(b?.id || name || `b${i}`);
      return { id, name: name || value, value };
    });
  }
  return String(raw || '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
    .map((t) => ({ id: t, name: t, value: t }));
}

/**
 * Resolve a step output (optionally a dotted path into it) from a last-run
 * steps map, formatted as a short one-line preview. Returns undefined when
 * there's no run data — callers hide the preview entirely then.
 */
export function previewRunValue(stepsByNode, id, path) {
  const step = stepsByNode?.[id];
  if (!step || step.output === undefined || step.output === null) return undefined;
  let v = step.output;
  if (path) {
    for (const key of String(path).split('.')) {
      if (v && typeof v === 'object' && key in v) v = v[key];
      else return undefined;
    }
  }
  return previewValue(v);
}

export function previewValue(v) {
  if (v === undefined || v === null) return undefined;
  let s = typeof v === 'object' ? JSON.stringify(v) : String(v);
  s = s.replace(/\s+/g, ' ').trim();
  if (s === '') return undefined;
  return s.length > 64 ? s.slice(0, 61) + '…' : s;
}

/**
 * Flatten a JSON output (e.g. an HTTP response) into its leaf paths so the
 * editor can offer {{ gemini.json.candidates.0.content.parts.0.text }} as a
 * click-to-copy reference instead of leaving people to guess the syntax.
 * Leaves only, capped in depth, width and count so a big payload can't flood
 * the menu. Returns [] for scalars.
 */
export function nestedRefs(prefixToken, prefixName, raw, { maxDepth = 6, maxKeys = 20, maxItems = 80 } = {}) {
  const out = [];
  const walk = (v, path, depth) => {
    if (out.length >= maxItems) return;
    const isObj = v !== null && typeof v === 'object';
    if (!isObj || depth >= maxDepth) {
      if (path) out.push({ token: `{{ ${prefixToken}.${path} }}`, name: `${prefixName}.${path}`, value: previewValue(v) });
      return;
    }
    const keys = Array.isArray(v) ? v.map((_, i) => String(i)) : Object.keys(v);
    for (const k of keys.slice(0, maxKeys)) walk(v[k], path ? `${path}.${k}` : k, depth + 1);
  };
  walk(raw, '', 0);
  return out;
}

// Short acronyms that must stay upper-cased when we Title-Case a label.
const ACRONYMS = new Set([
  'SEO', 'AI', 'JSON', 'HTTP', 'HTTPS', 'URL', 'ID', 'API', 'HTML', 'CSV',
  'RSS', 'GA', 'UTM', 'SMS', 'PDF', 'CSS', 'SQL', 'IP'
]);

/**
 * The name shown for a node, resolved LIVE from the integration registry so it
 * always matches the catalogue (and is Title-Cased) instead of a stale label
 * snapshot stored at creation time. Triggers keep their descriptive label
 * (e.g. "Every Monday at 08:00"); actions resolve to the action's catalogue
 * name. Falls back to a stored label / id only when the source plugin is no
 * longer active, so a missing step still shows its last-known name.
 */
export function nodeDisplayName(node, integrations) {
  const data = node?.data || {};
  const isTrigger = node?.type === 'trigger' || data.kind === 'trigger';
  const integration = (integrations || []).find((i) => i.id === data.integration);
  if (isTrigger) {
    const t = integration?.triggers?.find((x) => x.id === data.trigger);
    return data.label || (t ? prettyName(t.name) : (data.trigger || 'Trigger'));
  }
  const action = integration?.actions?.find((a) => a.id === data.action);
  if (action) return prettyName(action.name);
  return data.label || (data.action ? prettyName(data.action) : (node?.id || 'Step'));
}

// HH:MM (24h) → "8:00 AM". Falls back gracefully on bad input.
function formatTime12(t) {
  const [h, m] = String(t || '09:00').split(':').map((n) => parseInt(n, 10));
  if (Number.isNaN(h)) return t || '09:00';
  const ampm = h >= 12 ? 'PM' : 'AM';
  const hr = ((h + 11) % 12) + 1;
  return `${hr}:${String(Number.isNaN(m) ? 0 : m).padStart(2, '0')} ${ampm}`;
}

/**
 * Human label for a flow trigger, derived live from its type + config so the
 * canvas trigger node always matches what the trigger panel shows. Keeping
 * this the single source means changing the trigger type or schedule time is
 * reflected on the node immediately — the two can't drift apart.
 */
export function deriveTriggerLabel(type, config, integrations) {
  const c = config || {};
  if (type === 'manual') return 'Manual';
  if (type === 'webhook') return 'Webhook';
  if (type === 'rss') return 'New RSS item';
  if (type === 'schedule') {
    const at = formatTime12(c.time || '09:00');
    switch (c.recurrence || 'daily') {
      case 'hourly':     return 'Every hour';
      case 'twicedaily': return `Twice a day (from ${at})`;
      case 'weekly': {
        const day = c.day || 'monday';
        const dayLabel = day.charAt(0).toUpperCase() + day.slice(1);
        return `Every ${dayLabel} at ${at}`;
      }
      default:           return `Every day at ${at}`;
    }
  }
  if (type === 'hook') {
    for (const integration of (integrations || [])) {
      const ev = (integration.triggers || []).find((t) => t.id === c.event);
      if (ev) return ev.name;
    }
    if (c.hook) return c.hook;
    return 'WordPress event';
  }
  return 'Trigger';
}

/**
 * Title-Case an action name for display ("Get user" → "Get User",
 * "HTTP request" → "HTTP Request", "Get SEO score" → "Get SEO Score").
 * Only applied to catalogue names, never to user-typed node labels.
 */
export function prettyName(name) {
  if (!name) return '';
  return String(name).replace(/[A-Za-z][A-Za-z0-9'’]*/g, (w) => {
    const up = w.toUpperCase();
    if (ACRONYMS.has(up)) return up;
    // Mixed-case words (getDeskTemperature, WooCommerce) are deliberate —
    // lower-casing their tails would mangle them.
    if (/[A-Z]/.test(w.slice(1))) return w;
    return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
  });
}
