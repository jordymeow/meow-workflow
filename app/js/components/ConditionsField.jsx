import styled from 'styled-components';
import { NekoButton, NekoInput, NekoSelect, NekoOption } from '@neko-ui';
import { Plus, X } from 'lucide-react';

const Wrap = styled.div`
  display: flex;
  flex-direction: column;
  gap: 8px;
`;

const Row = styled.div`
  display: grid;
  grid-template-columns: 1fr 28px;
  grid-template-rows: auto auto;
  grid-template-areas:
    "field   remove"
    "compare remove";
  gap: 6px;
  align-items: center;
  background: white;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 8px;
`;

const FieldCell = styled.div`
  grid-area: field;
`;

const CompareCell = styled.div`
  grid-area: compare;
  display: grid;
  grid-template-columns: minmax(120px, 1fr) minmax(0, 1.4fr);
  gap: 6px;
`;

const CompareCellSolo = styled.div`
  grid-area: compare;
`;

const RemoveBtn = styled.button`
  grid-area: remove;
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

// Operators that don't compare against a "value" (just check the left field).
const NO_VALUE_OPS = new Set(['is_empty', 'is_not_empty', 'is_true', 'is_false']);

const OPERATORS = [
  { value: 'equals',        label: 'equals' },
  { value: 'not_equals',    label: 'does not equal' },
  { value: 'contains',      label: 'contains' },
  { value: 'not_contains',  label: 'does not contain' },
  { value: 'starts_with',   label: 'starts with' },
  { value: 'ends_with',     label: 'ends with' },
  { value: 'is_empty',      label: 'is empty' },
  { value: 'is_not_empty',  label: 'is not empty' },
  { value: 'greater_than',  label: 'is greater than' },
  { value: 'less_than',     label: 'is less than' },
  { value: 'is_true',       label: 'is true' },
  { value: 'is_false',      label: 'is false' }
];

export default function ConditionsField({ value, onChange }) {
  const conditions = Array.isArray(value) ? value : [];

  const update = (idx, patch) => {
    const next = conditions.map((c, i) => (i === idx ? { ...c, ...patch } : c));
    onChange(next);
  };

  const add = () => {
    onChange([...conditions, { field: '', op: 'equals', value: '' }]);
  };

  const remove = (idx) => {
    onChange(conditions.filter((_, i) => i !== idx));
  };

  return (
    <Wrap>
      {conditions.length === 0 && (
        <Empty>No conditions yet. Click <strong>Add condition</strong> below to start.</Empty>
      )}
      {conditions.map((cond, i) => {
        const needsValue = !NO_VALUE_OPS.has(cond.op);
        return (
          <Row key={i}>
            <FieldCell>
              <NekoInput
                value={cond.field || ''}
                onChange={(v) => update(i, { field: v })}
                placeholder="{{ trigger.value }}"
              />
            </FieldCell>
            {needsValue ? (
              <CompareCell>
                <NekoSelect
                  value={cond.op || 'equals'}
                  onChange={(v) => update(i, { op: v })}
                  name={`op_${i}`}
                >
                  {OPERATORS.map((op) => (
                    <NekoOption key={op.value} value={op.value} label={op.label} />
                  ))}
                </NekoSelect>
                <NekoInput
                  value={cond.value || ''}
                  onChange={(v) => update(i, { value: v })}
                  placeholder="value"
                />
              </CompareCell>
            ) : (
              <CompareCellSolo>
                <NekoSelect
                  value={cond.op || 'equals'}
                  onChange={(v) => update(i, { op: v })}
                  name={`op_${i}`}
                >
                  {OPERATORS.map((op) => (
                    <NekoOption key={op.value} value={op.value} label={op.label} />
                  ))}
                </NekoSelect>
              </CompareCellSolo>
            )}
            <RemoveBtn type="button" onClick={() => remove(i)} title="Remove">
              <X size={14} />
            </RemoveBtn>
          </Row>
        );
      })}
      <AddRow>
        <NekoButton className="secondary" icon="plus" onClick={add}>
          Add condition
        </NekoButton>
      </AddRow>
    </Wrap>
  );
}
