import styled, { css, keyframes } from 'styled-components';
import { Handle, Position } from '@xyflow/react';
import { Plus } from 'lucide-react';
import { StepIcon, nodeDisplayName, ROUTER_COLORS, normalizeBranches } from '../stepVisual';

const Card = styled.div`
  background: white;
  border: 1.5px solid ${(p) => (p.$selected ? 'var(--neko-blue, hsl(217 80% 42%))' : '#e4e6eb')};
  border-radius: 14px;
  min-width: 210px;
  max-width: 260px;
  padding: 12px 14px;
  display: flex;
  align-items: center;
  gap: 10px;
  box-shadow: ${(p) =>
    p.$selected
      ? '0 8px 24px rgba(36, 99, 235, 0.18)'
      : '0 1px 3px rgba(15, 23, 42, 0.07), 0 4px 14px rgba(15, 23, 42, 0.04)'};
  transition: box-shadow 0.18s, transform 0.18s;
  &:hover { transform: translateY(-1px); }
`;

const Body = styled.div`
  flex: 1;
  min-width: 0;
`;

const Title = styled.div`
  font-size: 13.5px;
  font-weight: 700;
  color: #111827;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
`;

const Subtitle = styled.div`
  font-size: 11px;
  color: #6b7280;
  margin-top: 1px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
`;

const pulse = keyframes`
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
`;

// Status badge during/after a test run. z-index keeps it above the card —
// it renders before the card in the DOM and would otherwise paint underneath.
const RunBadge = styled.div`
  position: absolute;
  top: -8px;
  right: 14px;
  z-index: 5;
  font-size: 10px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 999px;
  background: ${(p) => p.$bg};
  color: white;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  box-shadow: 0 2px 6px rgba(0,0,0,0.12);
  ${(p) => p.$pulse && css`animation: ${pulse} 1.1s ease-in-out infinite;`}
`;

const BADGES = {
  done:    { bg: '#10b981', label: '✓ done' },
  failed:  { bg: '#ef4444', label: '✕ failed' },
  running: { bg: '#3b82f6', label: 'running', pulse: true }
};

// The step's reference id, always visible top-left — it's how other steps
// address this one ({{ id.output }}), so it should never be a mystery.
export const IdBadge = styled.div`
  position: absolute;
  top: -8px;
  left: 12px;
  z-index: 5;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 9px;
  font-weight: 600;
  line-height: 1.5;
  color: #64748b;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  padding: 0 6px;
  border-radius: 999px;
  pointer-events: none;
  user-select: none;
`;

const Wrap = styled.div`
  position: relative;
  &:hover .mwflow-add-after { opacity: 1; transform: translateX(-50%) scale(1); }
`;

// Hover "+" to add (and auto-wire) a step right after this one. Sits below the
// node since the flow runs top-to-bottom.
const AddAfterBtn = styled.button`
  position: absolute;
  bottom: -34px;
  left: 50%;
  transform: translateX(-50%) scale(0.85);
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 1px solid #d6dbe3;
  background: #fff;
  color: var(--neko-blue, hsl(217 80% 42%));
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  opacity: 0;
  z-index: 4;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.12);
  transition: opacity 0.12s, transform 0.12s, background 0.12s;
  &:hover { background: var(--neko-blue, hsl(217 80% 42%)); color: #fff; }
`;

// TRUE / FALSE outputs for condition nodes. Each tag straddles the bottom
// border of the card, with its handle dot right below — branches leave the
// node downwards, matching the top-to-bottom flow direction.
const BranchTag = styled.span`
  position: absolute;
  bottom: -9px;
  transform: translateX(-50%);
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.06em;
  line-height: 1.5;
  padding: 1px 8px;
  border-radius: 6px;
  color: ${(p) => p.$color};
  background: ${(p) => p.$bg};
  border: 1px solid ${(p) => p.$color}33;
  pointer-events: none;
  user-select: none;
  z-index: 3;
`;

const BRANCH_HANDLE_STYLE = {
  true:  { width: 10, height: 10, background: '#10b981', border: '2px solid #fff', boxShadow: '0 0 0 1.5px #10b981' },
  false: { width: 10, height: 10, background: '#ef4444', border: '2px solid #fff', boxShadow: '0 0 0 1.5px #ef4444' }
};

const routerHandleStyle = (color) => ({
  width: 10, height: 10, background: color,
  border: '2px solid #fff', boxShadow: `0 0 0 1.5px ${color}`
});

export default function ActionNode({ id, data, selected }) {
  const status = data._lastRun?.status;
  const integrations = window.mwflow?.integrations || [];
  const meta = integrations.find((i) => i.id === data.integration);
  const action = meta?.actions?.find((a) => a.id === data.action);
  const color = data.color || meta?.color || '#3b82f6';
  const icon = action?.icon || data.icon;
  const integrationName = meta?.name || data.integration;

  const isCondition = data.action === 'condition';
  const isRouter = data.action === 'router';
  const isForeach = data.action === 'foreach';
  const branches = isRouter
    ? normalizeBranches(data.params?.branches).filter((b) => b.name)
    : [];

  // Resolved live from the registry so it always matches the catalogue name
  // (Title-Cased), never a stale snapshot stored when the node was created.
  const title = nodeDisplayName({ id, type: 'action', data }, integrations);

  return (
    <Wrap className="mwflow-node">
      <Handle type="target" position={Position.Top} />
      <IdBadge>#{id}</IdBadge>
      {BADGES[status] && (
        <RunBadge $bg={BADGES[status].bg} $pulse={BADGES[status].pulse}>
          {BADGES[status].label}
        </RunBadge>
      )}
      <Card $selected={selected}>
        <StepIcon icon={icon} color={color} size={30} />
        <Body>
          <Title>{title}</Title>
          <Subtitle>{integrationName}</Subtitle>
        </Body>
      </Card>
      {isCondition && (
        <>
          <BranchTag style={{ left: '30%' }} $color="#047857" $bg="#ecfdf5">TRUE</BranchTag>
          <BranchTag style={{ left: '70%' }} $color="#b91c1c" $bg="#fef2f2">FALSE</BranchTag>
        </>
      )}
      {isForeach && (
        <>
          <BranchTag style={{ left: '30%' }} $color="#4f46e5" $bg="#eef2ff">EACH ITEM</BranchTag>
          <BranchTag style={{ left: '70%' }} $color="#047857" $bg="#ecfdf5">DONE</BranchTag>
        </>
      )}
      {branches.map((b, i) => (
        <BranchTag
          key={b.id}
          style={{ left: `${((i + 1) / (branches.length + 1)) * 100}%` }}
          $color={ROUTER_COLORS[i % ROUTER_COLORS.length]}
          $bg="#fff"
        >
          {b.name.toUpperCase()}
        </BranchTag>
      ))}
      {/* Branching nodes expose one source handle per branch so users wire
          each path visually instead of guessing. The runner routes on the
          edge's sourceHandle ('true'/'false' for condition, the branch name
          for router). The handle dots sit below the bottom edge, each right
          under its tag. */}
      {isCondition ? (
        <>
          <Handle id="true" type="source" position={Position.Bottom} style={{ ...BRANCH_HANDLE_STYLE.true, left: '30%', bottom: -20 }} />
          <Handle id="false" type="source" position={Position.Bottom} style={{ ...BRANCH_HANDLE_STYLE.false, left: '70%', bottom: -20 }} />
        </>
      ) : isForeach ? (
        <>
          {/* The id-less handle is the default source: auto-wired and
              AI-authored edges (no sourceHandle) attach here = the loop body.
              'done' is the runner's reserved after-the-last-item branch. */}
          <Handle type="source" position={Position.Bottom} style={{ ...routerHandleStyle('#6366f1'), left: '30%', bottom: -20 }} />
          <Handle id="done" type="source" position={Position.Bottom} style={{ ...routerHandleStyle('#10b981'), left: '70%', bottom: -20 }} />
        </>
      ) : branches.length > 0 ? (
        branches.map((b, i) => (
          <Handle
            key={b.id}
            id={b.id}
            type="source"
            position={Position.Bottom}
            style={{
              ...routerHandleStyle(ROUTER_COLORS[i % ROUTER_COLORS.length]),
              left: `${((i + 1) / (branches.length + 1)) * 100}%`,
              bottom: -20
            }}
          />
        ))
      ) : (
        <Handle type="source" position={Position.Bottom} />
      )}
      {!isCondition && data._onAddAfter && (
        <AddAfterBtn
          className="mwflow-add-after"
          onClick={(e) => { e.stopPropagation(); data._onAddAfter(id); }}
          title="Add a step after this"
        >
          <Plus size={14} />
        </AddAfterBtn>
      )}
    </Wrap>
  );
}
