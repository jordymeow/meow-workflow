import styled from 'styled-components';
import { Plus } from 'lucide-react';
import { StepIcon, prettyName } from './stepVisual';

const Bar = styled.div`
  position: absolute;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 6;
  display: flex;
  align-items: center;
  gap: 2px;
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: saturate(180%) blur(12px);
  border: 1px solid rgba(15, 23, 42, 0.08);
  border-radius: 16px;
  padding: 7px 8px;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06), 0 12px 32px rgba(15, 23, 42, 0.12);
  max-width: calc(100% - 32px);
`;

// Round "+" — the universal "add" affordance; the picker title says the rest.
const AddButton = styled.button`
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  border: 0;
  border-radius: 50%;
  background: var(--neko-blue, hsl(217 80% 42%));
  color: white;
  cursor: pointer;
  box-shadow: 0 1px 2px rgba(29, 78, 216, 0.4), 0 2px 8px rgba(29, 78, 216, 0.25);
  transition: filter 0.12s, transform 0.12s, box-shadow 0.12s;
  &:hover { filter: brightness(1.06); transform: translateY(-1px); box-shadow: 0 2px 4px rgba(29,78,216,0.4), 0 6px 16px rgba(29,78,216,0.3); }
  &:active { transform: translateY(0); }
`;

const Divider = styled.span`
  width: 1px;
  height: 22px;
  background: rgba(15, 23, 42, 0.1);
  margin: 0 5px;
  flex-shrink: 0;
`;

const QuickButton = styled.button`
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 38px;
  padding: 0 12px 0 8px;
  border: 0;
  border-radius: 10px;
  background: transparent;
  color: #334155;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.12s;
  &:hover { background: #f1f5f9; }
`;

const QuickName = styled.span`
  max-width: 130px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`;

// Built-in quick logic always shown in the toolbar — the "if/else and stuff"
// the user reaches for most when wiring a flow. Icons/colours are resolved
// from the core integration so they match the rest of the editor.
const QUICK_LOGIC = [
  { integration: 'core', id: 'condition',  label: 'Condition' },
  { integration: 'core', id: 'delay',      label: 'Delay' },
  { integration: 'core', id: 'router',     label: 'Branch' },
  { integration: 'core', id: 'send_email', label: 'Email' }
];

/**
 * Floating toolbar pinned to the bottom-centre of the canvas. Lets the user
 * add steps without the old left sidebar:
 *   [+ Add step] | quick logic (If/else, Delay, Branch, Email) | ★ favourites
 */
export default function CanvasToolbar({ integrations, favorites, onOpenPicker, onAdd }) {
  // Resolve a logic/favourite key into a full spec via the integrations data.
  const specFor = (integration, id) => {
    const it = (integrations || []).find((i) => i.id === integration);
    const a = it?.actions?.find((x) => x.id === id);
    if (!it || !a) return null;
    return {
      kind: 'action', id: a.id, integration: it.id, name: a.name,
      color: it.color, logo_url: it.logo_url, icon: a.icon
    };
  };

  const favSpecs = [...(favorites || [])]
    .map((k) => {
      const [integration, id] = k.split(':');
      return specFor(integration, id);
    })
    .filter(Boolean);

  return (
    <Bar>
      <AddButton onClick={onOpenPicker} title="Add a step">
        <Plus size={19} />
      </AddButton>

      <Divider />

      {QUICK_LOGIC.map(({ integration, id, label }) => {
        const s = specFor(integration, id);
        if (!s) return null;
        return (
          <QuickButton key={`${integration}:${id}`} title={`Add ${label}`} onClick={() => onAdd(s)}>
            <StepIcon icon={s.icon} color={s.color} size={18} />
            <QuickName>{label}</QuickName>
          </QuickButton>
        );
      })}

      {favSpecs.length > 0 && <Divider />}
      {favSpecs.map((s) => (
        <QuickButton
          key={`${s.integration}:${s.id}`}
          title={`Add ${prettyName(s.name)}`}
          onClick={() => onAdd(s)}
        >
          <StepIcon icon={s.icon} color={s.color} size={18} />
          <QuickName>{prettyName(s.name)}</QuickName>
        </QuickButton>
      ))}
    </Bar>
  );
}
