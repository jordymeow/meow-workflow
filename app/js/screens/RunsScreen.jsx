import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import styled from 'styled-components';
import { NekoBlock, NekoButton, NekoStatus, NekoEmpty, NekoSpinner } from '@neko-ui';
import { ChevronDown, ChevronRight, Zap, Clock, Webhook, Anchor } from 'lucide-react';
import { api } from '../helpers/api';
import { formatRelative } from '../helpers/time';
import { nodeDisplayName } from '../editor/stepVisual';

const RunList = styled.div`
  display: flex;
  flex-direction: column;
  border: 1px solid #e4e6eb;
  border-radius: 10px;
  overflow: hidden;
  background: white;
`;

const Loading = styled.div`
  display: flex;
  justify-content: center;
  padding: 48px 0;
`;

const RunRow = styled.div`
  display: grid;
  grid-template-columns: 20px 32px 1fr 130px 110px;
  align-items: center;
  gap: 14px;
  padding: 14px 18px;
  border-bottom: 1px solid #eef0f4;
  cursor: pointer;
  transition: background 0.12s;
  &:last-child { border-bottom: 0; }
  &:hover { background: #f8fafc; }
  ${(p) => p.$open && `background: #f1f5f9;`}
`;

const Caret = styled.span`
  color: #94a3b8;
  display: flex;
  align-items: center;
`;

const TriggerBadge = styled.span`
  width: 32px;
  height: 32px;
  border-radius: 9px;
  background: ${(p) => p.$bg};
  color: ${(p) => p.$color};
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
`;

const RunName = styled.div`
  font-size: 14px;
  font-weight: 600;
  color: #111827;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
`;

const RunMeta = styled.div`
  font-size: 11.5px;
  color: #6b7280;
  margin-top: 2px;
`;

const Cell = styled.div`
  font-size: 12.5px;
  color: #475569;
`;

const Timeline = styled.div`
  background: #fafbfc;
  padding: 14px 24px 18px 70px;
  border-bottom: 1px solid #eef0f4;
  display: flex;
  flex-direction: column;
  gap: 10px;
`;

const Step = styled.div`
  background: white;
  border: 1px solid #e4e6eb;
  border-radius: 8px;
  padding: 10px 12px;
  display: grid;
  grid-template-columns: 22px 1fr auto;
  gap: 10px;
  align-items: start;
`;

const StepIndex = styled.span`
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  font-size: 11px;
  font-weight: 700;
  background: ${(p) =>
    p.$status === 'running' ? '#dbeafe' :
    p.$status === 'failed'  ? '#fee2e2' :
    '#dcfce7'};
  color: ${(p) =>
    p.$status === 'running' ? '#1d4ed8' :
    p.$status === 'failed'  ? '#b91c1c' :
    '#15803d'};
  ${(p) => p.$status === 'running' && `
    animation: mwflow-step-pulse 1.2s infinite;
    @keyframes mwflow-step-pulse {
      0%, 100% { transform: scale(1); opacity: 1; }
      50% { transform: scale(1.15); opacity: 0.85; }
    }
  `}
`;

const StepBody = styled.div`
  min-width: 0;
`;

const StepName = styled.div`
  font-size: 13px;
  font-weight: 600;
  color: #0f172a;
`;

const StepOutput = styled.pre`
  margin: 4px 0 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
  color: #475569;
  background: transparent;
  white-space: pre-wrap;
  max-height: 80px;
  overflow: auto;
`;

const Error = styled.div`
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #991b1b;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 12.5px;
`;

const TRIGGER_META = {
  manual:   { Icon: Zap,     bg: '#fff7ed', color: '#ea580c' },
  schedule: { Icon: Clock,   bg: '#ecfdf5', color: '#059669' },
  webhook:  { Icon: Webhook, bg: '#eff6ff', color: '#1d4ed8' },
  hook:     { Icon: Anchor,  bg: '#f5f3ff', color: '#7c3aed' }
};

const statusToNeko = (s) => {
  if (s === 'done') return 'success';
  if (s === 'failed' || s === 'error') return 'error';
  if (s === 'running') return 'pending';
  if (s === 'pending' || s === 'queued') return 'idle';
  return 'idle';
};

function previewOutput(output) {
  if (output == null) return null;
  if (typeof output === 'string') return output;
  if (typeof output === 'object') {
    try { return JSON.stringify(output, null, 2); }
    catch { return String(output); }
  }
  return String(output);
}

// "started → finished" as a friendly duration. MySQL datetimes only carry
// seconds, so sub-second runs show as "<1s".
function formatDuration(started, finished) {
  if (!started || !finished) return null;
  const ms = new Date(finished.replace(' ', 'T')) - new Date(started.replace(' ', 'T'));
  if (Number.isNaN(ms) || ms < 0) return null;
  const s = Math.round(ms / 1000);
  if (s < 1) return '<1s';
  if (s < 60) return `${s}s`;
  const m = Math.floor(s / 60);
  return `${m}m ${s % 60}s`;
}

function findStepName(nodeId, flowDef, integrations) {
  if (!flowDef) return nodeId;
  const node = (flowDef.nodes || []).find((n) => n.id === nodeId);
  if (!node) return nodeId;
  return nodeDisplayName(node, integrations);
}

export default function RunsScreen() {
  // Poll the list while there's anything in flight so users see runs appear
  // and statuses update without manually reloading.
  const runs = useQuery({
    queryKey: ['runs'],
    queryFn: api.runs,
    refetchInterval: (q) => (q.state.data || []).some((r) => r.status === 'running') ? 2000 : false
  });
  const flows = useQuery({ queryKey: ['flows'], queryFn: api.flows });
  const integrations = useQuery({ queryKey: ['integrations'], queryFn: api.integrations });
  const [openId, setOpenId] = useState(null);
  const detail = useQuery({
    queryKey: ['run', openId],
    queryFn: () => api.run(openId),
    enabled: !!openId,
    refetchInterval: (q) => q.state.data?.status === 'running' ? 1500 : false
  });
  const detailFlow = useQuery({
    queryKey: ['flow', detail.data?.flow_id],
    queryFn: () => api.flow(detail.data.flow_id),
    enabled: !!detail.data?.flow_id
  });

  const flowsById = useMemo(() => {
    const map = {};
    for (const f of (flows.data || [])) { map[f.id] = f; }
    return map;
  }, [flows.data]);

  const list = runs.data || [];

  return (
    <NekoBlock
      className="primary"
      title="Recent runs"
      subtitle={list.length > 0 ? `${list.length} run${list.length === 1 ? '' : 's'}` : null}
    >
      {runs.isLoading ? (
        <Loading><NekoSpinner /></Loading>
      ) : list.length === 0 ? (
        <NekoEmpty
          icon="timer-outline"
          title="No runs yet"
          subtitle="Open a workflow and press Test once to see a run appear here."
        />
      ) : (
        <RunList>
          {list.map((r) => {
            const flow = flowsById[r.flow_id];
            const triggerType = flow?.trigger_type || 'manual';
            const meta = TRIGGER_META[triggerType] || TRIGGER_META.manual;
            const Icon = meta.Icon;
            const isOpen = openId === r.id;
            return (
              <div key={r.id}>
                <RunRow $open={isOpen} onClick={() => setOpenId(isOpen ? null : r.id)}>
                  <Caret>
                    {isOpen ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                  </Caret>
                  <TriggerBadge $bg={meta.bg} $color={meta.color}>
                    <Icon size={15} />
                  </TriggerBadge>
                  <div style={{ minWidth: 0 }}>
                    <RunName>{flow?.name || `Workflow #${r.flow_id}`}</RunName>
                    <RunMeta>Run #{r.id}</RunMeta>
                  </div>
                  <Cell style={{ color: '#94a3b8' }}>
                    {formatRelative(r.started_at)}
                    {formatDuration(r.started_at, r.finished_at) && (
                      <span style={{ marginLeft: 8, color: '#cbd5e1' }}>·</span>
                    )}
                    {formatDuration(r.started_at, r.finished_at) && (
                      <span style={{ marginLeft: 8 }}>{formatDuration(r.started_at, r.finished_at)}</span>
                    )}
                  </Cell>
                  <Cell>
                    <NekoStatus status={statusToNeko(r.status)} dot>{r.status}</NekoStatus>
                  </Cell>
                </RunRow>
                {isOpen && (
                  <Timeline>
                    {detail.isLoading && <Cell>Loading…</Cell>}
                    {detail.data?.error && <Error>{detail.data.error}</Error>}
                    {(detail.data?.steps || []).map((s, i) => {
                      const status = s.status || 'done';
                      const name = findStepName(s.node_id, detailFlow.data?.definition, integrations.data || []);
                      const preview = previewOutput(s.output);
                      return (
                        <Step key={`${s.node_id}-${i}`}>
                          <StepIndex $status={status}>{status === 'running' ? '⋯' : i + 1}</StepIndex>
                          <StepBody>
                            <StepName>{name}</StepName>
                            {status === 'failed' && s.error && (
                              <StepOutput style={{ color: '#b91c1c' }}>{s.error}</StepOutput>
                            )}
                            {status !== 'failed' && preview && <StepOutput>{preview}</StepOutput>}
                          </StepBody>
                          <NekoStatus status={statusToNeko(status)} dot iconSize={12} />
                        </Step>
                      );
                    })}
                    {(!detail.data?.steps || detail.data.steps.length === 0) && !detail.isLoading && (
                      <Cell>No steps recorded.</Cell>
                    )}
                  </Timeline>
                )}
              </div>
            );
          })}
        </RunList>
      )}
    </NekoBlock>
  );
}
