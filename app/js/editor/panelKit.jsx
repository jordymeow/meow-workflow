import styled from 'styled-components';
import { X, Trash2 } from 'lucide-react';

/**
 * Shared building blocks for the right-hand panel (trigger config + step
 * inspector) so both read as one tidy, consistent surface:
 *  - one sticky header with the icon, title and the × close,
 *  - a single content gutter,
 *  - full-width form fields (label above control) that never clip.
 */

// Sticky header: icon + title/subtitle on the left, × close on the right.
const HeadRow = styled.div`
  position: sticky;
  top: 0;
  z-index: 2;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 16px 18px 14px;
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: saturate(180%) blur(8px);
  border-bottom: 1px solid #eef0f4;
`;

const HeadText = styled.div`
  flex: 1;
  min-width: 0;
  padding-top: 1px;
`;

const HeadTitle = styled.div`
  font-size: 14.5px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.25;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
`;

const HeadSub = styled.div`
  font-size: 11.5px;
  color: #64748b;
  margin-top: 2px;
  line-height: 1.45;
`;

const HeadActions = styled.div`
  display: flex;
  align-items: center;
  gap: 2px;
  margin: -2px -4px 0 0;
`;

const IconBtn = styled.button`
  width: 28px;
  height: 28px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #94a3b8;
  cursor: pointer;
  transition: background 0.12s, color 0.12s;
  &:hover { background: ${(p) => (p.$danger ? '#fee2e2' : '#f1f5f9')}; color: ${(p) => (p.$danger ? '#dc2626' : '#334155')}; }
`;

export function PanelHead({ icon, title, subtitle, onClose, onDelete }) {
  return (
    <HeadRow>
      {icon}
      <HeadText>
        <HeadTitle>{title}</HeadTitle>
        {subtitle && <HeadSub>{subtitle}</HeadSub>}
      </HeadText>
      <HeadActions>
        {onDelete && (
          <IconBtn $danger onClick={onDelete} title="Delete this step" aria-label="Delete step">
            <Trash2 size={15} />
          </IconBtn>
        )}
        {onClose && (
          <IconBtn onClick={onClose} title="Close" aria-label="Close panel">
            <X size={16} />
          </IconBtn>
        )}
      </HeadActions>
    </HeadRow>
  );
}

// Content gutter — everything below the header lives here, on one consistent
// left/right margin with a steady vertical rhythm.
export const PanelBody = styled.div`
  padding: 16px 18px 28px;
  display: flex;
  flex-direction: column;
  gap: 15px;
`;

// One form field: label on top, full-width control below, optional hint.
const FieldWrap = styled.div`
  display: flex;
  flex-direction: column;
  gap: 6px;
  /* Force NekoUI controls (and native inputs) to fill the column so long
     option labels like "When a post is published" never wrap or clip. */
  .neko-select,
  .neko-input,
  .neko-textarea,
  input,
  select,
  textarea { width: 100%; box-sizing: border-box; }
  .neko-select .neko-select-option { width: 100%; box-sizing: border-box; }`;

// Variant of the field label that highlights a required asterisk.
export const RequiredMark = styled.span`
  color: #ef4444;
  font-weight: 700;
`;

const FieldLabelRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-height: 18px;
`;

const FieldLabel = styled.label`
  font-size: 12px;
  font-weight: 650;
  color: #334155;
  letter-spacing: 0.01em;
`;

const FieldHint = styled.div`
  font-size: 11.5px;
  color: ${(p) => (p.$tone === 'warn' ? '#d97706' : '#94a3b8')};
  font-weight: ${(p) => (p.$tone === 'warn' ? 600 : 400)};
  line-height: 1.45;
`;

export function Field({ label, labelRight, hint, warning, children }) {
  return (
    <FieldWrap>
      {(label || labelRight) && (
        <FieldLabelRow>
          {label ? <FieldLabel>{label}</FieldLabel> : <span />}
          {labelRight}
        </FieldLabelRow>
      )}
      {children}
      {hint && <FieldHint>{hint}</FieldHint>}
      {warning && <FieldHint $tone="warn">{warning}</FieldHint>}
    </FieldWrap>
  );
}

// A soft callout used for the plain-English "what this does" summary line.
export const Callout = styled.div`
  background: #f8fafc;
  border: 1px solid #eef2f7;
  border-radius: 10px;
  padding: 11px 13px;
  font-size: 12.5px;
  color: #475569;
  line-height: 1.5;
`;

// Reference chips, e.g. {{ trigger.post_id }} — Post ID. Tidy, aligned rows.
export const RefBlock = styled.div`
  display: flex;
  flex-direction: column;
  gap: 6px;
`;

export const RefBlockLabel = styled.div`
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: #94a3b8;
`;

export const RefRow = styled.div`
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: #64748b;
`;

export const RefCode = styled.code`
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
  background: #0f172a;
  color: #e2e8f0;
  padding: 3px 8px;
  border-radius: 6px;
  white-space: nowrap;
`;
