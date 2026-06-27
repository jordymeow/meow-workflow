import styled from 'styled-components';
import { CircleAlert, Globe, Zap } from 'lucide-react';
import { StepIcon, nodeDisplayName, previewRunValue } from '../editor/stepVisual';

// Site/date globals seeded into every run (see Runner::site_globals).
const GLOBAL_LABELS = {
  site_name: 'Site name',
  site_url: 'Site URL',
  admin_email: 'Admin email',
  now: 'Now',
  today: 'Today',
  today_human: 'Today',
  year: 'Year'
};

const Wrap = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 6px;
`;

const Chip = styled.button`
  display: inline-flex;
  align-items: center;
  gap: 5px;
  max-width: 100%;
  border: 1px solid ${(p) => p.$color}55;
  background: ${(p) => p.$color}10;
  color: #334155;
  font-size: 10.5px;
  font-weight: 600;
  line-height: 1;
  padding: 3px 9px 3px 4px;
  border-radius: 999px;
  cursor: ${(p) => (p.$clickable ? 'pointer' : 'default')};
  transition: background 0.1s, border-color 0.1s;
  ${(p) => p.$clickable && `
    &:hover { background: ${p.$color}22; border-color: ${p.$color}99; }
  `}
`;

const ChipText = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`;

const ChipPath = styled.span`
  color: #94a3b8;
  font-weight: 500;
`;

const MiniIcon = styled.span`
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: ${(p) => p.$bg};
  color: white;
  flex-shrink: 0;
`;

// Extract unique {{ id.path }} references (filters after | are ignored).
function parseRefs(value) {
  const out = [];
  const seen = new Set();
  const re = /\{\{\s*([a-zA-Z0-9_][a-zA-Z0-9_-]*)((?:\.[a-zA-Z0-9_.-]+)?)\s*(?:\|[^}]*)?\}\}/g;
  let m;
  while ((m = re.exec(String(value || ''))) !== null) {
    const id = m[1];
    const path = m[2] ? m[2].slice(1) : '';
    const key = `${id}.${path}`;
    if (seen.has(key)) continue;
    seen.add(key);
    out.push({ id, path });
  }
  return out;
}

/**
 * Shows each {{ reference }} used in a field as a colored chip right below it:
 * the referenced step's icon + name (click selects that step on the canvas),
 * orange for the trigger, gray for site/date globals, red when the reference
 * points at a step that no longer exists.
 */
export default function RefChips({ value, nodes, integrations, onSelectNode, stepsByNode }) {
  const refs = parseRefs(value);
  if (refs.length === 0) return null;

  // Append the value from the last test run to a chip tooltip when we have it.
  const withValue = (base, id, path) => {
    const v = previewRunValue(stepsByNode, id, path);
    return v !== undefined ? `${base}\nLast run: ${v}` : base;
  };

  return (
    <Wrap>
      {refs.map(({ id, path }) => {
        const suffix = path ? <ChipPath>·&nbsp;{path}</ChipPath> : null;

        if (id === 'trigger') {
          const triggerNode = (nodes || []).find((n) => n.type === 'trigger');
          return (
            <Chip
              key={`trigger.${path}`}
              type="button"
              $color="#ea580c"
              $clickable={!!(triggerNode && onSelectNode)}
              onClick={() => triggerNode && onSelectNode?.(triggerNode.id)}
              title={withValue("The trigger's payload", triggerNode?.id || 'trigger', path)}
            >
              <MiniIcon $bg="#ea580c"><Zap size={10} /></MiniIcon>
              <ChipText>Trigger {suffix}</ChipText>
            </Chip>
          );
        }

        if (GLOBAL_LABELS[id]) {
          return (
            <Chip key={id} type="button" $color="#94a3b8" $clickable={false} title="Always available">
              <MiniIcon $bg="#94a3b8"><Globe size={10} /></MiniIcon>
              <ChipText>{GLOBAL_LABELS[id]}</ChipText>
            </Chip>
          );
        }

        const node = (nodes || []).find((n) => n.id === id);
        if (node) {
          const integration = (integrations || []).find((i) => i.id === node.data.integration);
          const color = node.data.color || integration?.color || '#3b82f6';
          return (
            <Chip
              key={`${id}.${path}`}
              type="button"
              $color={color}
              $clickable={!!onSelectNode}
              onClick={() => onSelectNode?.(id)}
              title={withValue(`Used from step #${id} — click to open it`, id, path)}
            >
              <StepIcon icon={node.data.icon} color={color} size={16} />
              <ChipText>{nodeDisplayName(node, integrations)} {suffix}</ChipText>
            </Chip>
          );
        }

        return (
          <Chip
            key={`${id}.${path}`}
            type="button"
            $color="#ef4444"
            $clickable={false}
            title="No step with this reference exists — the value will be empty at runtime."
          >
            <MiniIcon $bg="#ef4444"><CircleAlert size={10} /></MiniIcon>
            <ChipText>{id} — missing</ChipText>
          </Chip>
        );
      })}
    </Wrap>
  );
}
