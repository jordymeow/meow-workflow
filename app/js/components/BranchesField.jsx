import styled from 'styled-components';
import { NekoButton, NekoInput } from '@neko-ui';
import { X } from 'lucide-react';
import { ROUTER_COLORS, normalizeBranches } from '../editor/stepVisual';

const Wrap = styled.div`
  display: flex;
  flex-direction: column;
  gap: 8px;
`;

const Row = styled.div`
  display: grid;
  grid-template-columns: 10px minmax(90px, 0.7fr) minmax(0, 1fr) 28px;
  gap: 8px;
  align-items: center;
  background: white;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 8px;
`;

// Matches the handle dot on the canvas so users can tell which row is which.
const Dot = styled.span`
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: ${(p) => p.$color};
  flex-shrink: 0;
`;

const RemoveBtn = styled.button`
  align-self: stretch;
  background: transparent;
  border: 0;
  border-radius: 6px;
  padding: 0;
  cursor: pointer;
  color: #cbd5e1;
  display: flex;
  align-items: center;
  justify-content: center;
  &:hover { background: #fee2e2; color: #dc2626; }
`;

const Empty = styled.div`
  background: white;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  padding: 14px;
  text-align: center;
  color: #6b7280;
  font-size: 12.5px;
`;

const AddRow = styled.div`
  display: flex;
  justify-content: flex-start;
`;

let branchSeq = 0;

/**
 * Row editor for the router's branches: each row is { id, name, value }.
 * The name labels the connector on the step; the value is what the router
 * matches against. Ids are opaque and stable, so renaming a branch never
 * orphans the edges already wired to its handle.
 */
export default function BranchesField({ value, onChange }) {
  const branches = normalizeBranches(value);

  const update = (idx, patch) => {
    onChange(branches.map((b, i) => (i === idx ? { ...b, ...patch } : b)));
  };

  const add = () => {
    const id = `b_${Date.now().toString(36)}_${branchSeq++}`;
    onChange([...branches, { id, name: '', value: '' }]);
  };

  const remove = (idx) => {
    onChange(branches.filter((_, i) => i !== idx));
  };

  return (
    <Wrap>
      {branches.length === 0 && (
        <Empty>No branches yet. Click <strong>Add branch</strong> below to start.</Empty>
      )}
      {branches.map((b, i) => (
        <Row key={b.id}>
          <Dot $color={ROUTER_COLORS[i % ROUTER_COLORS.length]} />
          <NekoInput
            value={b.name || ''}
            onChange={(v) => update(i, { name: v })}
            placeholder="Name"
          />
          <NekoInput
            value={b.value || ''}
            onChange={(v) => update(i, { value: v })}
            placeholder="Value to match"
          />
          <RemoveBtn type="button" onClick={() => remove(i)} title="Remove branch">
            <X size={14} />
          </RemoveBtn>
        </Row>
      ))}
      <AddRow>
        <NekoButton className="secondary" icon="plus" onClick={add}>
          Add branch
        </NekoButton>
      </AddRow>
    </Wrap>
  );
}
