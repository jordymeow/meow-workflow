import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import styled, { createGlobalStyle } from 'styled-components';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { NekoButton } from '@neko-ui';
import AiAssistMenu from '../components/AiAssistMenu';
import Canvas from './Canvas';
import CanvasToolbar from './CanvasToolbar';
import ModulePickerModal from './ModulePickerModal';
import Inspector from './Inspector';
import TriggerConfigPanel from './TriggerConfigPanel';
import { nodeDisplayName, deriveTriggerLabel } from './stepVisual';
import { Power, PowerOff, Play } from 'lucide-react';
import { api } from '../helpers/api';

const FAVORITES_KEY = 'mwflow.favorites';

function loadFavorites() {
  try {
    const raw = localStorage.getItem(FAVORITES_KEY);
    const arr = raw ? JSON.parse(raw) : [];
    return new Set(Array.isArray(arr) ? arr : []);
  } catch { return new Set(); }
}

// The canvas needs the vertical space: while the editor is open, the admin
// notices WordPress prints above the app (the Meow review banner, update nags)
// are hidden. They come back on the Workflows list.
const EditorGlobalStyle = createGlobalStyle`
  body.mwflow-editor-open #wpbody-content .notice:not(.inline) { display: none !important; }
`;

const Shell = styled.div`
  display: grid;
  grid-template-columns: 1fr ${(p) => (p.$inspectorOpen ? '360px' : '0px')};
  grid-template-rows: 52px 1fr;
  grid-template-areas:
    "topbar topbar"
    "canvas inspector";
  height: calc(100vh - 200px);
  min-height: 540px;
  background: white;
  border: 1px solid var(--neko-border, #e4e6eb);
  border-radius: 12px;
  overflow: hidden;
  transition: grid-template-columns 0.18s ease;
`;

const PanelScroll = styled.div`
  flex: 1;
  overflow: auto;
  min-height: 0;
`;

const TopBar = styled.div`
  grid-area: topbar;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 14px;
  border-bottom: 1px solid var(--neko-border, #e4e6eb);
  background: #fbfbfd;
`;

const TitleInput = styled.input`
  flex: 1;
  min-width: 120px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 6px;
  padding: 6px 10px;
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
  &:hover { background: #f1f5f9; }
  &:focus {
    outline: none;
    border-color: var(--neko-blue, hsl(217 80% 42%));
    background: white;
  }
  &::placeholder { color: #94a3b8; font-weight: 500; }
`;

// Right-aligned action cluster. Two groups separated by a divider:
//   state  ( active toggle · publish )   |   actions ( AI assist · Test once )
const Actions = styled.div`
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
`;

const VDivider = styled.span`
  width: 1px;
  height: 24px;
  background: var(--neko-border, #e4e6eb);
  flex-shrink: 0;
`;

// Active/Paused as one icon toggle: lit green when the flow runs on its
// trigger, muted when paused. Click flips it.
const ActiveToggle = styled.button`
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  padding: 0 12px 0 11px;
  border-radius: 999px;
  border: 1px solid ${(p) => (p.$active ? 'transparent' : '#e2e8f0')};
  background: ${(p) => (p.$active ? '#dcfce7' : '#f1f5f9')};
  color: ${(p) => (p.$active ? '#15803d' : '#64748b')};
  font-size: 12.5px;
  font-weight: 650;
  white-space: nowrap;
  cursor: pointer;
  transition: background 0.12s, color 0.12s, border-color 0.12s;
  &:hover {
    background: ${(p) => (p.$active ? '#bbf7d0' : '#e9eef4')};
    border-color: ${(p) => (p.$active ? 'transparent' : '#cbd5e1')};
  }
`;

const SaveChip = styled.span`
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11.5px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 999px;
  white-space: nowrap;
  background: ${(p) =>
    p.$state === 'dirty' ? '#fef3c7' :
    p.$state === 'saving' ? '#dbeafe' :
    p.$state === 'error' ? '#fee2e2' :
    p.$state === 'unpublished' ? '#fef3c7' :
    '#dcfce7'};
  color: ${(p) =>
    p.$state === 'dirty' ? '#92400e' :
    p.$state === 'saving' ? '#1d4ed8' :
    p.$state === 'error' ? '#b91c1c' :
    p.$state === 'unpublished' ? '#92400e' :
    '#15803d'};
`;

const SaveDot = styled.span`
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
  background: currentColor;
  animation: ${(p) => (p.$state === 'saving' ? 'mwflow-pulse 1.2s infinite' : 'none')};
  @keyframes mwflow-pulse {
    0%, 100% { transform: scale(1); opacity: 0.85; }
    50% { transform: scale(1.4); opacity: 1; }
  }
`;

const Toast = styled.div`
  position: absolute;
  top: 70px;
  left: 50%;
  transform: translateX(-50%);
  background: ${(p) => (p.$ok ? '#dcfce7' : '#fee2e2')};
  color: ${(p) => (p.$ok ? '#15803d' : '#b91c1c')};
  border: 1px solid ${(p) => (p.$ok ? '#86efac' : '#fecaca')};
  padding: 10px 14px 10px 16px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  box-shadow: 0 8px 28px rgba(15, 23, 42, 0.14);
  z-index: 10;
  cursor: pointer;
  animation: mwflow-toast-in 0.22s ease;
  @keyframes mwflow-toast-in {
    from { opacity: 0; transform: translate(-50%, -8px); }
    to   { opacity: 1; transform: translate(-50%, 0); }
  }
  &:hover { box-shadow: 0 10px 32px rgba(15, 23, 42, 0.18); }
`;

const ToastClose = styled.span`
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.06);
  font-size: 11px;
  font-weight: 700;
  margin-left: 2px;
`;

const ToastAction = styled.button`
  border: 0;
  background: rgba(0, 0, 0, 0.08);
  color: inherit;
  font: inherit;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 999px;
  cursor: pointer;
  transition: background 0.12s;
  &:hover { background: rgba(0, 0, 0, 0.16); }
`;

const SAVE_DEBOUNCE_MS = 800;

/**
 * Build a comparable JSON snapshot from the editor's working state. Strips
 * fields ReactFlow injects at runtime (measured dimensions, selection flags,
 * the transient _lastRun overlay) so they don't show up as "unsaved" diffs.
 */
function snapshot({ name, definition, triggerType, triggerConfig, isActive }) {
  const nodes = (definition?.nodes || []).map((n) => ({
    id: n.id,
    type: n.type,
    position: n.position,
    data: stripTransientData(n)
  }));
  const edges = (definition?.edges || []).map((e) => ({
    id: e.id,
    source: e.source,
    target: e.target,
    sourceHandle: e.sourceHandle || undefined,
    targetHandle: e.targetHandle || undefined
  }));
  return JSON.stringify({ name, triggerType, triggerConfig, isActive: !!isActive, nodes, edges });
}

function stripTransientData(node) {
  const { _lastRun, ...rest } = node.data || {};
  // The trigger node's label/event are a live reflection of triggerType +
  // triggerConfig (both already in the snapshot). They're re-derived every time
  // the editor opens, so counting them here made a freshly-installed example
  // look edited the instant you opened it ("Draft saved · not live" before any
  // change). Exclude them — the trigger's real state is captured separately.
  if (node.type === 'trigger' || rest.kind === 'trigger') {
    const { label, event, ...triggerRest } = rest;
    return triggerRest;
  }
  return rest;
}

/**
 * Short, human-readable node id: the action's slug plus the lowest free
 * number — "random1", "send_email2". These are what users see in
 * {{ references }}, so readability beats opaqueness.
 */
function shortNodeId(actionId, nodes) {
  const base = String(actionId || 'step').toLowerCase().replace(/[^a-z0-9_]/g, '_');
  const used = new Set((nodes || []).map((n) => n.id));
  let i = 1;
  while (used.has(`${base}${i}`)) i++;
  return `${base}${i}`;
}

function escapeRegExp(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Rewrite every {{ oldId.… }} reference inside a params tree (strings at any
 * depth — conditions rows, branch rows, plain inputs) to use the new id.
 */
function rewriteRefs(value, oldId, newId) {
  if (typeof value === 'string') {
    // Matches "{{ oldId" followed by '.', '|', '}}' or whitespace — never a
    // longer id that merely starts with the same characters.
    const re = new RegExp(`\\{\\{(\\s*)${escapeRegExp(oldId)}(?=[.|\\s}])`, 'g');
    return value.replace(re, `{{$1${newId}`);
  }
  if (Array.isArray(value)) return value.map((v) => rewriteRefs(v, oldId, newId));
  if (value && typeof value === 'object') {
    const out = {};
    for (const [k, v] of Object.entries(value)) out[k] = rewriteRefs(v, oldId, newId);
    return out;
  }
  return value;
}

export default function Editor({ flowId, onBack }) {
  const qc = useQueryClient();
  const flow = useQuery({ queryKey: ['flow', flowId], queryFn: () => api.flow(flowId), enabled: !!flowId });
  const integrations = useQuery({ queryKey: ['integrations'], queryFn: api.integrations });

  const [name, setName] = useState('');
  const [definition, setDefinition] = useState({ nodes: [], edges: [] });
  const [triggerType, setTriggerType] = useState('manual');
  const [triggerConfig, setTriggerConfig] = useState({});
  const [isActive, setIsActive] = useState(false);
  const [selectedNodeId, setSelectedNodeId] = useState(null);
  const [saveState, setSaveState] = useState('saved'); // saved | dirty | saving | error
  const [hasUnpublished, setHasUnpublished] = useState(false);
  const [lastRun, setLastRun] = useState(null);
  const [toast, setToast] = useState(null); // { ok: boolean, message: string }
  const toastTimerRef = useRef(null);
  const lastAddedRef = useRef(null);

  const showToast = useCallback((ok, message, action = null) => {
    if (toastTimerRef.current) clearTimeout(toastTimerRef.current);
    setToast({ ok, message, action });
    // Long-lived toasts: success 5s, failure 8s, actionable 7s. Tap to dismiss.
    toastTimerRef.current = setTimeout(() => setToast(null), action ? 7000 : (ok ? 5000 : 8000));
  }, []);
  useEffect(() => () => { if (toastTimerRef.current) clearTimeout(toastTimerRef.current); }, []);
  const savedSnapshotRef = useRef(null); // JSON of last persisted state
  const saveTimerRef = useRef(null);

  useEffect(() => {
    document.body.classList.add('mwflow-editor-open');
    return () => document.body.classList.remove('mwflow-editor-open');
  }, []);

  // Module picker modal + toolbar favourites (persisted across sessions).
  const [pickerOpen, setPickerOpen] = useState(false);
  const [favorites, setFavorites] = useState(loadFavorites);
  const toggleFavorite = useCallback((key) => {
    setFavorites((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key); else next.add(key);
      try { localStorage.setItem(FAVORITES_KEY, JSON.stringify([...next])); } catch (_) {}
      return next;
    });
  }, []);

  // Hydrate from server ONCE per flow. The snapshot becomes the reference we
  // compare against to decide whether the flow is "dirty" — avoids false
  // "Unsaved" flickers from React 18 strict-mode effect re-runs.
  //
  // Every auto-save refetches the flow, so this must not re-hydrate on each
  // refetch: anything typed between the save request and its response would be
  // overwritten by the server copy and, since the snapshot then matched, lost
  // for good. Later refetches only merge what the server owns: the unpublished
  // flag and the webhook token it mints on activation.
  const hydratedFlowIdRef = useRef(null);
  useEffect(() => {
    if (!flow.data) return;
    if (hydratedFlowIdRef.current !== flow.data.id) {
      hydratedFlowIdRef.current = flow.data.id;
      const next = {
        name: flow.data.name,
        definition: flow.data.definition || { nodes: [], edges: [] },
        triggerType: flow.data.trigger_type || 'manual',
        triggerConfig: flow.data.trigger_config || {},
        isActive: !!flow.data.is_active
      };
      setName(next.name);
      setDefinition(next.definition);
      setTriggerType(next.triggerType);
      setTriggerConfig(next.triggerConfig);
      setIsActive(next.isActive);
      setHasUnpublished(!!flow.data.has_unpublished_changes);
      savedSnapshotRef.current = snapshot(next);
      setSaveState('saved');
      return;
    }
    setHasUnpublished(!!flow.data.has_unpublished_changes);
    const token = flow.data.trigger_config?.token;
    if (token) {
      setTriggerConfig((c) => (c.token === token ? c : { ...c, token }));
    }
  }, [flow.data]);

  // Keep the canvas trigger node a pure reflection of the flow's trigger
  // type + config. Without this, changing the trigger type in the panel left
  // the node showing its old kind/label (the two were separate state). Now the
  // flow's triggerType/triggerConfig is the single source and the node derives
  // from it — they can never drift. Also self-heals legacy flows on open.
  useEffect(() => {
    const ints = integrations.data || [];
    setDefinition((d) => {
      const idx = d.nodes.findIndex((n) => n.type === 'trigger' || n.data?.kind === 'trigger');
      if (idx === -1) return d;
      const node = d.nodes[idx];
      const derived = deriveTriggerLabel(triggerType, triggerConfig, ints);
      // For hook triggers with no registered event, deriveTriggerLabel can only
      // return the raw hook name. A stored label (e.g. a template's friendly
      // "When a comment is posted") is better — keep it rather than overwrite it
      // with "comment_post". Genuine trigger-type changes still re-derive below.
      const rawHookFallback = triggerType === 'hook' && derived === (triggerConfig.hook || '');
      const label = (rawHookFallback && node.data.trigger === triggerType && node.data.label)
        ? node.data.label
        : derived;
      const event = triggerType === 'hook' ? (triggerConfig.event || '') : '';
      if (node.data.trigger === triggerType
        && node.data.label === label
        && (node.data.event || '') === event) {
        return d; // already in sync — no spurious dirty/save
      }
      const nodes = d.nodes.slice();
      nodes[idx] = { ...node, data: { ...node.data, kind: 'trigger', trigger: triggerType, label, event } };
      return { ...d, nodes };
    });
  }, [triggerType, triggerConfig, integrations.data]);

  const performSave = useCallback(async () => {
    setSaveState('saving');
    const snapshotBefore = snapshot({ name, definition, triggerType, triggerConfig, isActive });
    try {
      // Persist only the fields we own; strip ReactFlow runtime additions
      // (measured dims, selection) before writing.
      const cleanDef = JSON.parse(snapshotBefore);
      await api.updateFlow(flowId, {
        name, definition: { nodes: cleanDef.nodes, edges: cleanDef.edges },
        trigger_type: triggerType,
        trigger_config: triggerConfig,
        is_active: isActive
      });
      savedSnapshotRef.current = snapshotBefore;
      setSaveState('saved');
      // Auto-save writes to the draft only, so assume there is now something to
      // publish. The refetch below corrects this from the server, which compares
      // draft and published (editing back to the live version reads as live).
      setHasUnpublished(true);
      qc.invalidateQueries({ queryKey: ['flows'] });
      qc.invalidateQueries({ queryKey: ['flow', flowId] });
    }
    catch (err) {
      console.error(err);
      setSaveState('error');
    }
  }, [flowId, name, definition, triggerType, triggerConfig, isActive, qc]);

  const publish = useMutation({
    mutationFn: () => api.publishFlow(flowId),
    onSuccess: () => {
      setHasUnpublished(false);
      qc.invalidateQueries({ queryKey: ['flows'] });
      qc.invalidateQueries({ queryKey: ['flow', flowId] });
      showToast(true, 'Workflow published — live triggers now use this version.');
    },
    onError: (err) => showToast(false, err.message || 'Publish failed.')
  });

  // Debounced auto-save: only fire when the JSON snapshot differs from what
  // was last persisted. This is immune to React strict-mode double effects
  // and to hydration triggering a state update.
  useEffect(() => {
    if (!flow.data || !savedSnapshotRef.current) return;
    const current = snapshot({ name, definition, triggerType, triggerConfig, isActive });
    if (current === savedSnapshotRef.current) {
      // State matches what's on the server — no need to save.
      if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
      setSaveState((s) => (s === 'saved' ? s : 'saved'));
      return;
    }
    setSaveState('dirty');
    if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
    saveTimerRef.current = setTimeout(() => { performSave(); }, SAVE_DEBOUNCE_MS);
    return () => { if (saveTimerRef.current) clearTimeout(saveTimerRef.current); };
  }, [name, definition, triggerType, triggerConfig, isActive, flow.data, performSave]);

  const testRun = useMutation({
    mutationFn: async () => {
      if (saveTimerRef.current) { clearTimeout(saveTimerRef.current); }
      await performSave();
      // Stepwise run: the server executes one step per request, and we push
      // each intermediate state into lastRun so the canvas badges update live
      // (pending → running → done/failed) while the test progresses.
      let run = await api.testFlow(flowId, {}, { stepwise: true });
      setLastRun(run);
      let guard = 0;
      while (run.status === 'running' && guard++ < 200) {
        run = await api.advanceRun(run.run_id);
        setLastRun(run);
      }
      return run;
    },
    onSuccess: (run) => {
      setLastRun(run);
      qc.invalidateQueries({ queryKey: ['runs'] });
      if (run.status === 'done') {
        showToast(true, 'Test run finished — all steps succeeded.');
      } else {
        showToast(false, run.error || 'Test run failed. Open the step settings to fix.');
      }
    },
    onError: (err) => showToast(false, err.message || 'Test run failed.')
  });

  // The Inspector renders the step's output under "Last run" — definition
  // nodes never carry _lastRun themselves, so graft it on here.
  const selectedNode = useMemo(() => {
    const node = definition.nodes.find((n) => n.id === selectedNodeId) || null;
    if (!node) return null;
    const step = (lastRun?.steps || []).find((s) => s.node_id === node.id);
    return step ? { ...node, data: { ...node.data, _lastRun: step } } : node;
  }, [definition, selectedNodeId, lastRun]);

  const updateNode = useCallback((id, partial) => {
    setDefinition((d) => ({
      ...d,
      nodes: d.nodes.map((n) => (n.id === id ? { ...n, data: { ...n.data, ...partial } } : n))
    }));
  }, []);

  /**
   * Rename a node's reference id and rewrite every {{ oldId.… }} usage across
   * all steps' params, plus the edges that point at the node. References never
   * silently break on rename.
   */
  const renameNodeId = useCallback((oldId, newId) => {
    if (!newId || newId === oldId) return;
    setDefinition((d) => ({
      nodes: d.nodes.map((n) => ({
        ...n,
        id: n.id === oldId ? newId : n.id,
        data: { ...n.data, params: rewriteRefs(n.data.params || {}, oldId, newId) }
      })),
      edges: d.edges.map((e) => ({
        ...e,
        source: e.source === oldId ? newId : e.source,
        target: e.target === oldId ? newId : e.target
      }))
    }));
    setSelectedNodeId((cur) => (cur === oldId ? newId : cur));
  }, []);

  const deleteSelected = useCallback(() => {
    if (!selectedNodeId) return;
    const node = definition.nodes.find((n) => n.id === selectedNodeId);
    if (!node) return;
    // Every workflow needs its trigger — never let it be removed.
    if (node.type === 'trigger') {
      showToast(false, 'The trigger can’t be deleted — every workflow needs one.');
      return;
    }
    const removedEdges = definition.edges.filter(
      (e) => e.source === selectedNodeId || e.target === selectedNodeId
    );
    setDefinition((d) => ({
      nodes: d.nodes.filter((n) => n.id !== selectedNodeId),
      edges: d.edges.filter((e) => e.source !== selectedNodeId && e.target !== selectedNodeId)
    }));
    setSelectedNodeId(null);
    // Offer a one-click undo so an accidental delete (incl. the Delete key)
    // never loses work.
    showToast(true, `Deleted “${nodeDisplayName(node, integrations.data || [])}”.`, {
      label: 'Undo',
      onClick: () => {
        setDefinition((d) => ({ nodes: [...d.nodes, node], edges: [...d.edges, ...removedEdges] }));
        setSelectedNodeId(node.id);
      }
    });
  }, [selectedNodeId, definition, showToast]);

  const addNodeFromSpec = useCallback((spec, position) => {
    const isTrigger = spec.kind === 'trigger';
    const id = shortNodeId(isTrigger ? 'trigger' : spec.id, definition.nodes);

    let anchorNode = null;
    if (!position) {
      anchorNode =
        definition.nodes.find((n) => n.id === selectedNodeId) ||
        definition.nodes.find((n) => n.id === lastAddedRef.current) ||
        definition.nodes.slice().sort((a, b) => (b.position?.x || 0) - (a.position?.x || 0))[0];
    }

    // Flows run top-to-bottom (vertical) — WordPress admin is narrow, so we
    // stack new steps below their anchor rather than off to the right.
    const finalPosition = position
      ? position
      : anchorNode
        ? { x: anchorNode.position?.x || 0, y: (anchorNode.position?.y || 0) + 150 }
        : { x: 320, y: 80 };

    const node = {
      id,
      type: isTrigger ? 'trigger' : 'action',
      position: finalPosition,
      data: {
        kind: spec.kind,
        integration: spec.integration,
        [isTrigger ? 'trigger' : 'action']: spec.id,
        label: spec.name,
        color: spec.color,
        icon: spec.icon,
        params: {}
      }
    };

    setDefinition((d) => {
      const newEdges = [...d.edges];
      // Auto-wire from the anchor — but not from branching nodes (condition /
      // router), whose edges need a branch handle the user picks by dragging.
      if (!position && anchorNode && !isTrigger
        && anchorNode.data.action !== 'condition' && anchorNode.data.action !== 'router') {
        newEdges.push({
          id: `e_${Date.now()}_${Math.floor(Math.random() * 999)}`,
          source: anchorNode.id,
          target: id
        });
      }
      return { nodes: [...d.nodes, node], edges: newEdges };
    });
    setSelectedNodeId(id);
    lastAddedRef.current = id;
  }, [definition.nodes, selectedNodeId]);

  // Inline "+" on a node → select it as the anchor and open the picker so the
  // next chosen step is added (and auto-wired) right after it.
  const addAfter = useCallback((nodeId) => {
    setSelectedNodeId(nodeId);
    setPickerOpen(true);
  }, []);

  // Tidy the canvas into a clean vertical layout: depth (longest path from the
  // trigger) drives the row, siblings of a branch spread across the column.
  const autoArrange = useCallback(() => {
    setDefinition((d) => {
      const { nodes, edges } = d;
      if (nodes.length === 0) return d;
      const indeg = new Map(nodes.map((n) => [n.id, 0]));
      edges.forEach((e) => { if (indeg.has(e.target)) indeg.set(e.target, indeg.get(e.target) + 1); });

      // Longest-path depth so every node sits below all of its parents.
      const depth = new Map(nodes.map((n) => [n.id, 0]));
      for (let i = 0; i < nodes.length; i++) {
        let changed = false;
        edges.forEach((e) => {
          if (!depth.has(e.source) || !depth.has(e.target)) return;
          const nd = depth.get(e.source) + 1;
          if (nd > depth.get(e.target)) { depth.set(e.target, nd); changed = true; }
        });
        if (!changed) break;
      }

      const layers = {};
      nodes.forEach((n) => { const dd = depth.get(n.id) || 0; (layers[dd] ||= []).push(n.id); });

      const V_GAP = 150, H_GAP = 300, X0 = 360, Y0 = 60;
      const byId = new Map(nodes.map((n) => [n.id, n]));
      const pos = new Map();
      Object.keys(layers).map(Number).sort((a, b) => a - b).forEach((layer) => {
        const ids = layers[layer].slice().sort((a, b) => {
          const na = byId.get(a), nb = byId.get(b);
          return (na.position?.x || 0) - (nb.position?.x || 0) || (na.position?.y || 0) - (nb.position?.y || 0);
        });
        const count = ids.length;
        ids.forEach((id, i) => {
          pos.set(id, { x: X0 + (i - (count - 1) / 2) * H_GAP, y: Y0 + layer * V_GAP });
        });
      });

      return { ...d, nodes: nodes.map((n) => ({ ...n, position: pos.get(n.id) || n.position })) };
    });
  }, []);

  // Keyboard shortcuts — bound to the document so they work regardless of focus
  // unless the user is typing into a real input/textarea/contenteditable.
  useEffect(() => {
    const isTypingTarget = (el) => {
      if (!el) return false;
      if (el.isContentEditable) return true;
      const tag = el.tagName;
      return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
    };

    const onKeyDown = (e) => {
      const cmd = e.metaKey || e.ctrlKey;
      if (cmd && (e.key === 's' || e.key === 'S')) {
        e.preventDefault();
        if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
        performSave();
        return;
      }
      if (cmd && e.key === 'Enter') {
        e.preventDefault();
        if (!testRun.isPending) testRun.mutate();
        return;
      }
      if (e.key === 'Escape') {
        if (!isTypingTarget(e.target)) setSelectedNodeId(null);
        return;
      }
      // Delete only when not typing; xyflow handles Delete on selected nodes,
      // but we tie it to our selectedNodeId for consistency.
      if ((e.key === 'Backspace' || e.key === 'Delete') && !isTypingTarget(e.target)) {
        if (selectedNodeId) {
          e.preventDefault();
          deleteSelected();
        }
      }
    };

    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [performSave, deleteSelected, selectedNodeId, testRun]);

  /**
   * Apply a step proposed by AI Assist → Suggest. Inserts a new action node
   * after the selected (or last) node and auto-wires the edge. Re-uses the
   * existing addNodeFromSpec helper so positioning + auto-fit are consistent.
   */
  const applyAuthoredStep = useCallback((step) => {
    if (!step || !step.integration || !step.action) return;
    const id = shortNodeId(step.action, definition.nodes);
    const isTrigger = false;
    const anchorNode =
      definition.nodes.find((n) => n.id === selectedNodeId) ||
      definition.nodes.find((n) => n.id === lastAddedRef.current) ||
      definition.nodes.slice().sort((a, b) => (b.position?.x || 0) - (a.position?.x || 0))[0];

    const position = anchorNode
      ? { x: (anchorNode.position?.x || 0) + 280, y: anchorNode.position?.y || 200 }
      : { x: 200, y: 200 };

    const node = {
      id,
      type: 'action',
      position,
      data: {
        kind: 'action',
        integration: step.integration,
        action: step.action,
        params: step.params || {}
      }
    };

    setDefinition((d) => {
      const newEdges = [...d.edges];
      if (anchorNode && !isTrigger) {
        newEdges.push({
          id: `e_${Date.now()}_${Math.floor(Math.random() * 999)}`,
          source: anchorNode.id,
          target: id
        });
      }
      return { nodes: [...d.nodes, node], edges: newEdges };
    });
    setSelectedNodeId(id);
    lastAddedRef.current = id;
  }, [definition.nodes, selectedNodeId]);

  /**
   * Replace the entire flow with an AI-repaired definition. Used by AI Assist
   * → Fix the last error. Updates the trigger config too if the repair touched it.
   */
  const applyReplaceDefinition = useCallback((def, meta = {}) => {
    if (!def) return;
    setDefinition(def);
    if (meta.trigger_type) setTriggerType(meta.trigger_type);
    if (meta.trigger_config) setTriggerConfig(meta.trigger_config);
    if (meta.name && !name) setName(meta.name);
    setSelectedNodeId(null);
  }, [name]);

  if (!flow.data) {
    return <div style={{ padding: 40, color: '#6b7280' }}>Loading workflow…</div>;
  }

  // The draft/live model is split into two controls so it's legible at a glance:
  // a STATUS chip (what state your work is in) and the Publish ACTION button.
  // Editing auto-saves to a draft; the live triggers keep running the published
  // version until Publish — the chip spells that out so it's never a surprise.
  const saveChip = (() => {
    if (saveState === 'saving' || saveState === 'dirty') return { state: 'saving', label: 'Saving draft…' };
    if (saveState === 'error') return { state: 'error', label: 'Save failed' };
    if (hasUnpublished) return { state: 'unpublished', label: 'Draft saved · not live' };
    return { state: 'saved', label: 'Live · up to date' };
  })();

  const canPublish = hasUnpublished && saveState !== 'saving' && saveState !== 'dirty' && saveState !== 'error';
  const publishCtl = hasUnpublished
    ? {
        label: 'Publish', className: 'primary', icon: 'rocket-launch',
        onClick: () => publish.mutate(),
        disabled: !canPublish || publish.isPending, isBusy: publish.isPending
      }
    : { label: 'Published', className: 'secondary', icon: 'check', disabled: true };

  const isTriggerSelected = selectedNode?.type === 'trigger';
  const inspectorOpen = !!selectedNode;

  return (
    <Shell $inspectorOpen={inspectorOpen} style={{ position: 'relative' }}>
      <EditorGlobalStyle />
      {toast && (
        <Toast $ok={toast.ok} onClick={() => setToast(null)} title="Dismiss">
          <span>{toast.ok ? '✓' : '✕'}</span>
          <span>{toast.message}</span>
          {toast.action && (
            <ToastAction
              onClick={(e) => { e.stopPropagation(); toast.action.onClick(); setToast(null); }}
            >
              {toast.action.label}
            </ToastAction>
          )}
          <ToastClose>×</ToastClose>
        </Toast>
      )}
      <TopBar>
        <TitleInput
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Untitled workflow"
        />

        <Actions>
          {/* Build helpers on the left of the cluster… */}
          {window.mwflow?.aiEngine?.available && (
            <AiAssistMenu
              getDefinition={() => definition}
              selectedNodeId={selectedNodeId}
              lastError={lastRun?.status === 'failed' ? (lastRun?.error || 'A step failed.') : null}
              onApplyStep={(step) => applyAuthoredStep(step)}
              onReplaceDefinition={(def, meta) => applyReplaceDefinition(def, meta)}
            />
          )}
          <NekoButton
            className="secondary"
            onClick={() => testRun.mutate()}
            disabled={testRun.isPending}
            isBusy={testRun.isPending}
          >
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
              <Play size={14} /> Test once
            </span>
          </NekoButton>

          <VDivider />

          {/* …state on the right, with Publish — the "save to live" — in the
              prime top-right spot. */}
          <ActiveToggle
            $active={isActive}
            onClick={() => setIsActive(!isActive)}
            title={isActive
              ? 'Active — runs automatically on its trigger. Click to pause.'
              : 'Paused — never runs on its own (Test once still works). Click to activate.'}
          >
            {isActive ? <Power size={14} /> : <PowerOff size={14} />}
            {isActive ? 'Active' : 'Paused'}
          </ActiveToggle>

          <SaveChip
            $state={saveChip.state}
            title={saveChip.state === 'unpublished'
              ? 'Your edits are auto-saved as a draft. Live triggers keep running the published version until you click Publish.'
              : saveChip.state === 'saved'
                ? 'The live version matches your editor — nothing left to publish.'
                : saveChip.state === 'error'
                  ? 'The draft couldn’t be saved — your last change isn’t stored yet.'
                  : 'Auto-saving your changes to the draft…'}
          >
            <SaveDot $state={saveChip.state} />
            {saveChip.label}
          </SaveChip>

          <span style={{ display: 'inline-flex', minWidth: 116, justifyContent: 'flex-end' }}
            title={hasUnpublished ? 'You have changes that aren’t live yet — click to publish them.' : 'The live version is up to date.'}>
            <NekoButton
              className={publishCtl.className}
              icon={publishCtl.icon}
              onClick={publishCtl.onClick}
              disabled={publishCtl.disabled}
              isBusy={publishCtl.isBusy}
            >
              {publishCtl.label}
            </NekoButton>
          </span>
        </Actions>
      </TopBar>

      <Canvas
        definition={definition}
        setDefinition={setDefinition}
        selectedNodeId={selectedNodeId}
        setSelectedNodeId={setSelectedNodeId}
        lastRun={lastRun}
        onAddAfter={addAfter}
        onAutoArrange={autoArrange}
        onDropSpec={(spec, position) => addNodeFromSpec(spec, position)}
      >
        <CanvasToolbar
          integrations={integrations.data || []}
          favorites={favorites}
          onOpenPicker={() => setPickerOpen(true)}
          onAdd={(spec) => addNodeFromSpec(spec)}
        />
      </Canvas>

      {inspectorOpen && (
        <InspectorWrap>
          <PanelScroll>
            {isTriggerSelected ? (
              <TriggerConfigPanel
                flowId={flowId}
                triggerType={triggerType}
                triggerConfig={triggerConfig}
                isActive={isActive}
                triggerSample={flow.data?.trigger_sample}
                captureSample={flow.data?.capture_sample}
                onClose={() => setSelectedNodeId(null)}
                onChange={(type, config) => {
                  setTriggerType(type);
                  setTriggerConfig(config);
                }}
              />
            ) : (
              <Inspector
                node={selectedNode}
                integrations={integrations.data || []}
                definition={definition}
                lastRun={lastRun}
                triggerSample={flow.data?.trigger_sample}
                onClose={() => setSelectedNodeId(null)}
                onDelete={deleteSelected}
                onChange={(partial) => updateNode(selectedNode.id, partial)}
                onSelectNode={(id) => setSelectedNodeId(id)}
                onRenameId={(newId) => renameNodeId(selectedNode.id, newId)}
              />
            )}
          </PanelScroll>
        </InspectorWrap>
      )}

      <ModulePickerModal
        isOpen={pickerOpen}
        onClose={() => setPickerOpen(false)}
        integrations={integrations.data || []}
        favorites={favorites}
        onToggleFavorite={toggleFavorite}
        onPick={(spec) => addNodeFromSpec(spec)}
      />
    </Shell>
  );
}

const InspectorWrap = styled.aside`
  grid-area: inspector;
  border-left: 1px solid var(--neko-border, #e4e6eb);
  background: white;
  display: flex;
  flex-direction: column;
  overflow: hidden;
`;
