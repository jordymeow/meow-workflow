import { useMemo, useState } from 'react';
import styled from 'styled-components';
import { NekoTypo, NekoSelect, NekoOption, NekoInput } from '@neko-ui';
import { ChevronDown, ChevronRight, Copy, Check, Link2, AlertTriangle, SlidersHorizontal, Pencil } from 'lucide-react';
import InputField from '../components/InputField';
import { StepIcon, prettyName, previewRunValue, nestedRefs } from './stepVisual';
import { PanelHead, PanelBody, Field, Callout } from './panelKit';

const Sub = styled.div`
  font-size: 11.5px;
  color: #6b7280;
`;

const SectionToggle = styled.button`
  background: transparent;
  border: 0;
  padding: 4px 0;
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
  text-align: left;
  &:hover { color: var(--neko-blue, hsl(217 80% 42%)); }
`;

const SectionLabel = styled.div`
  font-size: 10.5px;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-bottom: -4px;
`;

/* ---- Categorised sections: Settings / Error handling / Output ---- */

const Section = styled.div`
  border: 1px solid #eef0f4;
  border-radius: 10px;
  overflow: hidden;
`;

const SectionHead = styled.button`
  width: 100%;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border: 0;
  text-align: left;
  /* Static heads (Settings) read as a plain label; collapsible heads
     (Error handling / Output) get the tappable grey bar. */
  background: ${(p) => (p.$static ? 'transparent' : '#f8fafc')};
  cursor: ${(p) => (p.$static ? 'default' : 'pointer')};
  &:hover { background: ${(p) => (p.$static ? 'transparent' : '#f1f5f9')}; }
`;

const SectionIcon = styled.span`
  display: inline-flex;
  color: ${(p) => p.$color || '#64748b'};
  flex-shrink: 0;
`;

const SectionName = styled.span`
  font-size: 12px;
  font-weight: 700;
  color: #334155;
  text-transform: uppercase;
  letter-spacing: 0.04em;
`;

const SectionMeta = styled.span`
  margin-left: auto;
  font-size: 11.5px;
  color: #94a3b8;
  font-weight: 500;
  text-transform: none;
  letter-spacing: 0;
  display: flex;
  align-items: center;
  gap: 6px;
`;

const SectionBody = styled.div`
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  border-top: 1px solid #eef0f4;
`;

const ReferenceHint = styled.div`
  font-size: 11.5px;
  color: #94a3b8;
  margin: 2px 0 8px;
  line-height: 1.4;
`;

/* ---- Reference id row (the #id other steps use) ---- */

const RefIdRow = styled.div`
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: -6px;
`;

const RefIdChip = styled.button`
  display: inline-flex;
  align-items: center;
  gap: 5px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #475569;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 999px;
  cursor: pointer;
  &:hover { border-color: #c7d2fe; color: var(--neko-blue, hsl(217 80% 42%)); }
  svg { color: #94a3b8; }
  &:hover svg { color: var(--neko-blue, hsl(217 80% 42%)); }
`;

const RefIdInput = styled.input`
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
  font-weight: 600;
  color: #0f172a;
  border: 1px solid var(--neko-blue, hsl(217 80% 42%));
  border-radius: 999px;
  padding: 2px 8px;
  width: 160px;
  outline: none !important;
  box-shadow: none !important;
`;

const RefIdHint = styled.span`
  font-size: 10.5px;
  color: #94a3b8;
`;

/**
 * Click-to-edit reference id. Renaming rewrites every {{ id.… }} usage in the
 * flow (see Editor.renameNodeId), so it's always safe.
 */
function RefIdEditor({ node, definition, onRenameId }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(node.id);

  const start = () => { setDraft(node.id); setEditing(true); };
  const commit = () => {
    setEditing(false);
    const cleaned = draft.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/^_+|_+$/g, '');
    if (!cleaned || cleaned === node.id || cleaned === 'trigger') return;
    const taken = (definition?.nodes || []).some((n) => n.id === cleaned && n.id !== node.id);
    if (taken) return;
    onRenameId?.(cleaned);
  };

  return (
    <RefIdRow>
      {editing ? (
        <RefIdInput
          autoFocus
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onBlur={commit}
          onKeyDown={(e) => {
            if (e.key === 'Enter') commit();
            if (e.key === 'Escape') { e.stopPropagation(); setEditing(false); }
          }}
        />
      ) : (
        <RefIdChip type="button" onClick={start} title="Rename this step's reference — every {{ }} using it updates automatically">
          #{node.id} <Pencil size={10} />
        </RefIdChip>
      )}
      <RefIdHint>used as {`{{ ${node.id}.… }}`}</RefIdHint>
    </RefIdRow>
  );
}

const RefList = styled.div`
  display: flex;
  flex-direction: column;
  border: 1px solid #eef0f4;
  border-radius: 8px;
  overflow: hidden;
`;

// One compact line per output. Click anywhere to copy its {{ reference }}.
const RefRow = styled.button`
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  background: white;
  border: 0;
  border-bottom: 1px solid #f1f5f9;
  padding: 7px 10px;
  cursor: pointer;
  text-align: left;
  font: inherit;
  transition: background 0.1s;
  &:last-child { border-bottom: 0; }
  &:hover { background: #f5f8ff; }
`;

const RefCode = styled.code`
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11.5px;
  color: #1d4ed8;
  white-space: nowrap;
  flex-shrink: 0;
`;

const RefName = styled.span`
  font-size: 11px;
  color: #94a3b8;
  margin-left: auto;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
`;

const RefIcon = styled.span`
  flex-shrink: 0;
  color: ${(p) => (p.$copied ? '#10b981' : '#cbd5e1')};
  display: inline-flex;
`;

const RunBlock = styled.pre`
  margin: 0;
  background: ${(p) => (p.$error ? '#fef2f2' : '#0b1220')};
  color: ${(p) => (p.$error ? '#b91c1c' : '#cbd5e1')};
  ${(p) => (p.$error ? 'border: 1px solid #fecaca;' : '')}
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 11px;
  max-height: 200px;
  overflow: auto;
  white-space: pre-wrap;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
`;

// Readable key/value view of a step's last-run output (falls back to raw JSON
// for arrays / primitives).
const RunKV = styled.div`
  display: flex;
  flex-direction: column;
  border: 1px solid #eef0f4;
  border-radius: 8px;
  overflow: hidden;
`;

const RunKVRow = styled.div`
  display: flex;
  gap: 10px;
  padding: 7px 10px;
  border-bottom: 1px solid #f1f5f9;
  font-size: 11.5px;
  &:last-child { border-bottom: 0; }
`;

const RunKVKey = styled.span`
  flex-shrink: 0;
  min-width: 92px;
  color: #64748b;
  font-weight: 600;
`;

const RunKVVal = styled.span`
  color: #0f172a;
  word-break: break-word;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
`;

function formatRunValue(v) {
  if (v === null || v === undefined) return '—';
  if (typeof v === 'object') return JSON.stringify(v);
  if (typeof v === 'boolean') return v ? 'true' : 'false';
  return String(v);
}

function RunOutput({ output }) {
  if (output && typeof output === 'object' && !Array.isArray(output)) {
    const entries = Object.entries(output);
    if (entries.length === 0) return <RunBlock>(no output)</RunBlock>;
    return (
      <RunKV>
        {entries.map(([k, v]) => (
          <RunKVRow key={k}>
            <RunKVKey>{k}</RunKVKey>
            <RunKVVal>{formatRunValue(v)}</RunKVVal>
          </RunKVRow>
        ))}
      </RunKV>
    );
  }
  return <RunBlock>{JSON.stringify(output, null, 2)}</RunBlock>;
}

function CopyableRefRow({ ref_, label }) {
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(ref_);
      setCopied(true);
      setTimeout(() => setCopied(false), 1200);
    } catch (_) { /* silent */ }
  };
  return (
    <RefRow type="button" onClick={copy} title="Click to copy">
      <RefIcon $copied={copied}>{copied ? <Check size={13} /> : <Copy size={13} />}</RefIcon>
      <RefCode>{ref_}</RefCode>
      <RefName>{label}</RefName>
    </RefRow>
  );
}

// One-line summary of the failure policy, shown in the collapsed section head.
function policySummary(policy) {
  const onFail = policy.on_failure || 'stop';
  const base = onFail === 'continue' ? 'Continue'
    : onFail === 'go_to_failure_branch' ? 'Failure branch'
    : 'Stop';
  const retries = Number(policy.retry_count || 0);
  return retries > 0 ? `${base} · ${retries} retr${retries === 1 ? 'y' : 'ies'}` : base;
}

// Build the grouped {{ reference }} list a field's "Insert data" picker shows:
// site/date globals, the trigger's outputs, then every *other* step's outputs.
// When a test run exists, each item carries the REAL value it produced —
// seeing your own data next to the token is what makes references click.
function buildTokens(node, definition, integrations, stepsByNode, triggerSample) {
  const groups = [{
    label: 'Site & date',
    items: [
      { token: '{{ site_name }}', name: 'Site name' },
      { token: '{{ site_url }}', name: 'Site URL' },
      { token: '{{ admin_email }}', name: 'Admin email' },
      { token: '{{ today_human }}', name: 'Today, e.g. June 3, 2026' },
      { token: '{{ today }}', name: 'Today (2026-06-03)' },
      { token: '{{ now }}', name: 'Date and time now' },
      { token: '{{ year }}', name: 'Current year' }
    ]
  }];
  const nodes = definition?.nodes || [];
  const triggerNode = nodes.find((n) => n.type === 'trigger');
  if (triggerNode) {
    // Resolve the trigger's outputs by type. A "hook" (WordPress event) trigger
    // exposes the chosen event's named fields ({{ trigger.post_id }}, …); the
    // other types map to the core trigger metadata (hook→wp_hook there).
    const ttype = triggerNode.data.trigger;
    let outs = [];
    if (ttype === 'hook' && triggerNode.data.event) {
      // The chosen event may come from any integration (WordPress, WooCommerce…).
      for (const integration of integrations) {
        const t = integration.triggers?.find((x) => x.id === triggerNode.data.event);
        if (t) { outs = t.outputs || []; break; }
      }
    } else {
      const core = integrations.find((i) => i.id === 'core');
      const coreId = ttype === 'hook' ? 'wp_hook' : ttype;
      outs = core?.triggers?.find((t) => t.id === coreId)?.outputs || [];
    }
    // A webhook payload has whatever fields the caller sent, so once a sample
    // has been captured, list its real fields ({{ trigger.url }}) instead of
    // the generic "body" entry. Values come from the sample when there's no run.
    const sampleFields = ttype === 'webhook' && triggerSample && typeof triggerSample === 'object' && !Array.isArray(triggerSample)
      ? Object.keys(triggerSample).map((k) => ({ id: k, name: k }))
      : [];
    if (sampleFields.length) outs = sampleFields;
    const sampleByNode = sampleFields.length ? { trigger: { output: triggerSample } } : null;
    if (outs.length) {
      groups.push({
        label: 'Trigger',
        items: outs.flatMap((o) => {
          const raw = stepsByNode[triggerNode.id]?.output?.[o.id]
            ?? stepsByNode.trigger?.output?.[o.id]
            ?? (sampleFields.length ? triggerSample[o.id] : undefined);
          return [{
            token: `{{ trigger.${o.id} }}`,
            name: o.name,
            value: previewRunValue(stepsByNode, triggerNode.id, o.id)
              ?? previewRunValue(stepsByNode, 'trigger', o.id)
              ?? previewRunValue(sampleByNode, 'trigger', o.id)
          }, ...nestedRefs(`trigger.${o.id}`, o.name, raw)];
        })
      });
    }
  }
  for (const n of nodes) {
    if (n.id === node.id || n.type === 'trigger') continue;
    const it = integrations.find((i) => i.id === n.data.integration);
    const sp = it?.actions?.find((a) => a.id === n.data.action);
    const outs = sp?.outputs || [];
    if (!outs.length) continue;
    // After a test run, JSON outputs are expanded into their nested paths.
    groups.push({
      label: prettyName(sp?.name || n.data.action),
      items: outs.flatMap((o) => [{
        token: `{{ ${n.id}.${o.id} }}`,
        name: o.name,
        value: previewRunValue(stepsByNode, n.id, o.id)
      }, ...nestedRefs(`${n.id}.${o.id}`, o.name, stepsByNode[n.id]?.output?.[o.id])])
    });
  }
  return groups;
}

export default function Inspector({ node, integrations, definition, lastRun, triggerSample, onChange, onClose, onDelete, onSelectNode, onRenameId }) {
  // node_id → step (with output) from the last test run, for value previews.
  const stepsByNode = useMemo(() => {
    const out = {};
    for (const s of (lastRun?.steps || [])) out[s.node_id] = s;
    return out;
  }, [lastRun]);
  const tokens = useMemo(
    () => buildTokens(node, definition, integrations, stepsByNode, triggerSample),
    [node, definition, integrations, stepsByNode, triggerSample]
  );
  // Context the fields use to render {{ reference }} chips below their inputs.
  const refContext = useMemo(
    () => ({ nodes: definition?.nodes || [], integrations, onSelectNode, stepsByNode }),
    [definition, integrations, onSelectNode, stepsByNode]
  );
  // Actions can be deleted from here; the trigger never can.
  const deletable = node.data.kind !== 'trigger';
  const integration = integrations.find((i) => i.id === node.data.integration);
  const spec = integration && (
    node.data.kind === 'trigger'
      ? integration.triggers.find((t) => t.id === node.data.trigger)
      : integration.actions.find((a) => a.id === node.data.action)
  );
  const params = node.data.params || {};
  const policy = node.data.policy || {};
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [showPolicy, setShowPolicy] = useState(false);
  const [showOutputs, setShowOutputs] = useState(false);

  const setPolicy = (patch) => onChange({ policy: { ...policy, ...patch } });

  const { basic, advanced } = useMemo(() => {
    const list = spec?.inputs || [];
    return {
      basic: list.filter((f) => !f.advanced),
      advanced: list.filter((f) => f.advanced)
    };
  }, [spec]);

  const setParam = (id, value) => onChange({ params: { ...params, [id]: value } });

  if (!spec) {
    return (
      <>
        <PanelHead
          icon={<StepIcon icon={node.data.icon} color={integration?.color} size={32} />}
          title={node.data.label || node.id}
          subtitle={`${node.data.integration} · ${node.data.action || node.data.trigger}`}
          onClose={onClose}
          onDelete={deletable ? onDelete : null}
        />
        <PanelBody>
          <NekoTypo p>
            This step needs a plugin that isn’t active right now, so it can’t run.
            Re-activate the plugin it came from, or delete this step from the workflow.
          </NekoTypo>
        </PanelBody>
      </>
    );
  }

  const outputs = spec.outputs || [];
  const status = node.data._lastRun?.status;

  return (
    <>
      <PanelHead
        icon={<StepIcon icon={spec.icon} color={integration?.color} size={32} />}
        title={prettyName(spec.name)}
        subtitle={`${integration?.name} · ${node.data.kind === 'trigger' ? 'Trigger' : 'Action'}`}
        onClose={onClose}
        onDelete={deletable ? onDelete : null}
      />
      <PanelBody>
        {node.data.kind !== 'trigger' && onRenameId && (
          <RefIdEditor node={node} definition={definition} onRenameId={onRenameId} />
        )}
        {spec.description && <Callout>{spec.description}</Callout>}

      {/* Last run first: after a test, what this step produced (or why it
          failed) is what you came to see. Buried under Settings and Output it
          was routinely missed, and people asked how to see the response. */}
      {node.data._lastRun && (
        <>
          <SectionLabel style={{ color: status === 'done' ? '#059669' : '#dc2626' }}>
            Last run · {status}
          </SectionLabel>
          {node.data._lastRun.error
            ? <RunBlock $error>{node.data._lastRun.error}</RunBlock>
            : <RunOutput output={node.data._lastRun.output} />}
        </>
      )}

      {/* ── 1. Settings — how to run this step ────────────────────── */}
      <Section>
        <SectionHead $static as="div">
          <SectionIcon><SlidersHorizontal size={14} /></SectionIcon>
          <SectionName>Settings</SectionName>
        </SectionHead>
        <SectionBody>
          {basic.length === 0 && advanced.length === 0 && (
            <Sub>This step has no settings to configure.</Sub>
          )}
          {basic.map((field) => (
            <InputField
              key={field.id}
              field={field}
              value={params[field.id] ?? field.default ?? ''}
              onChange={(v) => setParam(field.id, v)}
              tokens={tokens}
              refContext={refContext}
            />
          ))}
          {advanced.length > 0 && (
            <>
              <SectionToggle type="button" onClick={() => setShowAdvanced((s) => !s)}>
                {showAdvanced ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                Advanced ({advanced.length})
              </SectionToggle>
              {showAdvanced && advanced.map((field) => (
                <InputField
                  key={field.id}
                  field={field}
                  value={params[field.id] ?? field.default ?? ''}
                  onChange={(v) => setParam(field.id, v)}
                  tokens={tokens}
                  refContext={refContext}
                />
              ))}
            </>
          )}
        </SectionBody>
      </Section>

      {/* ── 2. Error handling — what happens if it fails ──────────── */}
      {node.data.kind !== 'trigger' && (
        <Section>
          <SectionHead type="button" onClick={() => setShowPolicy((s) => !s)}>
            <SectionIcon $color="#d97706"><AlertTriangle size={14} /></SectionIcon>
            <SectionName>Error handling</SectionName>
            <SectionMeta>
              {policySummary(policy)}
              {showPolicy ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
            </SectionMeta>
          </SectionHead>
          {showPolicy && (
            <SectionBody>
              <Field label="If it fails">
                <NekoSelect
                  scrolldown
                  value={policy.on_failure || 'stop'}
                  onChange={(v) => setPolicy({ on_failure: v })}
                  name="on_failure"
                >
                  <NekoOption value="stop" label="Stop the workflow" />
                  <NekoOption value="continue" label="Continue to the next step" />
                  <NekoOption value="go_to_failure_branch" label="Follow the failure branch" />
                </NekoSelect>
              </Field>
              <Field label="Retry attempts">
                <NekoSelect
                  scrolldown
                  value={String(policy.retry_count ?? 0)}
                  onChange={(v) => setPolicy({ retry_count: Number(v) })}
                  name="retry_count"
                >
                  <NekoOption value="0" label="No retry" />
                  <NekoOption value="1" label="1 retry" />
                  <NekoOption value="2" label="2 retries" />
                  <NekoOption value="3" label="3 retries" />
                </NekoSelect>
              </Field>
              {(policy.retry_count ?? 0) > 0 && (
                <Field label="Wait between retries (seconds)">
                  <NekoInput
                    type="number"
                    min={0}
                    max={60}
                    value={String(policy.retry_delay_seconds ?? 5)}
                    onChange={(v) => setPolicy({ retry_delay_seconds: Number(v) })}
                  />
                </Field>
              )}
            </SectionBody>
          )}
        </Section>
      )}

      {/* ── 3. Output — values other steps can use ────────────────── */}
      {outputs.length > 0 && (
        <Section>
          <SectionHead type="button" onClick={() => setShowOutputs((s) => !s)}>
            <SectionIcon $color="#1d4ed8"><Link2 size={14} /></SectionIcon>
            <SectionName>Output</SectionName>
            <SectionMeta>
              {outputs.length} value{outputs.length === 1 ? '' : 's'}
              {showOutputs ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
            </SectionMeta>
          </SectionHead>
          {showOutputs && (
            <SectionBody>
              <ReferenceHint>
                Click a value to copy it, then paste it into a later step.
                {status ? '' : ' Run the flow once and the nested fields of JSON outputs are listed here too.'}
              </ReferenceHint>
              <RefList>
                {outputs.flatMap((o) => [
                  <CopyableRefRow
                    key={o.id}
                    ref_={`{{ ${node.id}.${o.id} }}`}
                    label={o.name}
                  />,
                  ...nestedRefs(`${node.id}.${o.id}`, o.name, node.data._lastRun?.output?.[o.id]).map((it) => (
                    <CopyableRefRow key={it.token} ref_={it.token} label={it.name} />
                  ))
                ])}
              </RefList>
            </SectionBody>
          )}
        </Section>
      )}

      </PanelBody>
    </>
  );
}
