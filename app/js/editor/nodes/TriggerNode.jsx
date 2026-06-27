import styled from 'styled-components';
import { Handle, Position } from '@xyflow/react';
import { Zap, Clock, Webhook, AnchorIcon, Plus, Rss } from 'lucide-react';
import { IdBadge } from './ActionNode';

const Card = styled.div`
  background: linear-gradient(180deg, #fff7ed 0%, #ffedd5 100%);
  border: 1.5px solid ${(p) => (p.$selected ? 'var(--neko-blue, hsl(217 80% 42%))' : '#fdba74')};
  border-radius: 12px;
  min-width: 200px;
  max-width: 240px;
  padding: 10px 12px;
  display: flex;
  align-items: center;
  gap: 10px;
  box-shadow: ${(p) =>
    p.$selected
      ? '0 6px 18px rgba(36, 99, 235, 0.18)'
      : '0 1px 3px rgba(234, 88, 12, 0.10), 0 3px 10px rgba(234, 88, 12, 0.06)'};
  transition: box-shadow 0.18s, transform 0.18s;
  &:hover { transform: translateY(-1px); }
`;

const IconCircle = styled.span`
  width: 26px;
  height: 26px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #ea580c;
  color: white;
  flex-shrink: 0;
`;

const Body = styled.div`
  flex: 1;
  min-width: 0;
`;

const Title = styled.div`
  font-size: 13px;
  font-weight: 700;
  color: #7c2d12;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
`;

const Subtitle = styled.div`
  font-size: 10px;
  color: #c2410c;
  margin-top: 1px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 600;
`;

const ICONS = {
  manual: Zap,
  schedule: Clock,
  webhook: Webhook,
  hook: AnchorIcon,
  rss: Rss
};

const Wrap = styled.div`
  position: relative;
  &:hover .mwflow-add-after { opacity: 1; transform: translateX(-50%) scale(1); }
`;

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

export default function TriggerNode({ id, data, selected }) {
  const Icon = ICONS[data.trigger] || Zap;
  return (
    <Wrap className="mwflow-node">
      {/* The payload is always referenced as {{ trigger.* }} regardless of
          the node's internal id — teach that name, not the id. */}
      <IdBadge>#trigger</IdBadge>
      <Card $selected={selected}>
        <IconCircle><Icon size={16} /></IconCircle>
        <Body>
          <Title>{data.label || 'Trigger'}</Title>
          <Subtitle>Trigger</Subtitle>
        </Body>
      </Card>
      <Handle type="source" position={Position.Bottom} />
      {data._onAddAfter && (
        <AddAfterBtn
          className="mwflow-add-after"
          onClick={(e) => { e.stopPropagation(); data._onAddAfter(id); }}
          title="Add the first step"
        >
          <Plus size={14} />
        </AddAfterBtn>
      )}
    </Wrap>
  );
}
