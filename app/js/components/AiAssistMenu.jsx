import { useState, useRef, useEffect } from 'react';
import styled from 'styled-components';
import { Sparkles, ChevronDown, FileText, PlusCircle, Wrench } from 'lucide-react';
import { NekoModal, NekoButton, NekoMessage } from '@neko-ui';
import { api } from '../helpers/api';
import { prettyName } from '../editor/stepVisual';

// Resolve a suggested step's raw integration/action ids into friendly,
// Title-Cased names a beginner can read (e.g. "Send Email", not "send_email").
function describeStep(step) {
  const integrations = window.mwflow?.integrations || [];
  const integration = integrations.find((i) => i.id === step.integration);
  const action = integration?.actions?.find((a) => a.id === step.action);
  return {
    name: prettyName(action?.name || step.action || 'Step'),
    integrationName: integration?.name || step.integration || ''
  };
}

const Wrapper = styled.div`
  position: relative;
  display: inline-flex;
  /* Keep the dropdown chevron aligned with the NekoButton label. */
  .mwflow-aiassist-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  svg.chev {
    transition: transform 0.15s;
    transform: rotate(${(p) => (p.$open ? '180deg' : '0deg')});
    opacity: 0.85;
  }
`;

const Menu = styled.div`
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  z-index: 50;
  min-width: 230px;
  background: #fff;
  border: 1px solid rgba(15, 23, 42, 0.1);
  border-radius: 12px;
  padding: 6px;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06), 0 12px 32px rgba(15, 23, 42, 0.16);
  animation: mwflowMenuIn 0.12s ease-out;
  @keyframes mwflowMenuIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
  }
`;

const Item = styled.button`
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 9px 10px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: ${(p) => (p.$disabled ? '#cbd5e1' : '#1f2937')};
  font-size: 13px;
  font-weight: 500;
  text-align: left;
  cursor: ${(p) => (p.$disabled ? 'not-allowed' : 'pointer')};
  transition: background 0.1s;
  svg { color: ${(p) => (p.$disabled ? '#cbd5e1' : '#6366f1')}; flex-shrink: 0; }
  &:hover { background: ${(p) => (p.$disabled ? 'transparent' : '#f5f3ff')}; }
`;

const MenuDivider = styled.div`
  height: 1px;
  background: rgba(15, 23, 42, 0.07);
  margin: 5px 6px;
`;

const ExplainBody = styled.div`
  font-size: 13.5px;
  line-height: 1.55;
  color: #1f2937;
  white-space: pre-wrap;
`;

const SuggestionCard = styled.div`
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 14px;
  margin-top: 8px;
`;

const SuggestionTitle = styled.div`
  font-weight: 700;
  font-size: 13px;
  color: #0f172a;
`;

const SuggestionMeta = styled.div`
  font-size: 11.5px;
  color: #64748b;
  margin-top: 2px;
`;

const SuggestionWhy = styled.div`
  font-size: 12.5px;
  color: #475569;
  margin-top: 10px;
  line-height: 1.45;
`;

const ModalFooter = styled.div`
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding-top: 14px;
  margin-top: 14px;
  border-top: 1px solid #e2e8f0;
`;

const Spinner = styled.div`
  padding: 28px;
  text-align: center;
  color: #64748b;
  font-size: 13px;
`;

/**
 * AI assist dropdown for the editor top bar. Three modes:
 *  - Explain  → fetches a plain-English paragraph and shows it in a modal.
 *  - Suggest  → fetches a next-step proposal and lets the user apply it.
 *  - Fix      → given the last failed run, fetches a corrected flow definition.
 *
 * Receives helpers from Editor: snapshot accessors + setDefinition.
 */
export default function AiAssistMenu({
  getDefinition,
  selectedNodeId,
  lastError,
  onApplyStep,         // (step) => void  — adds a new node + edge
  onReplaceDefinition  // (definition, meta) => void — wholesale replace
}) {
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState(null); // 'explain' | 'suggest' | 'fix' | null
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);
  const wrapRef = useRef(null);

  // Close the dropdown on outside click / Escape.
  useEffect(() => {
    if (!open) return;
    const onDown = (e) => { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey); };
  }, [open]);

  const run = async (which) => {
    setOpen(false);
    setMode(which);
    setBusy(true);
    setResult(null);
    setError(null);
    try {
      const ctx = { definition: getDefinition() };
      if (which === 'suggest' && selectedNodeId) ctx.after_step_id = selectedNodeId;
      if (which === 'fix' && lastError) ctx.error = lastError;
      const res = await api.author({ prompt: '', mode: which, context: ctx, persist: false });
      setResult(res);
    }
    catch (e) {
      setError(e.message || 'AI assist failed.');
    }
    finally {
      setBusy(false);
    }
  };

  const close = () => { setMode(null); setResult(null); setError(null); };

  const applySuggestion = () => {
    const sug = result?.suggestion?.step;
    if (sug && onApplyStep) {
      onApplyStep({
        id: sug.id,
        integration: sug.integration,
        action: sug.action,
        params: sug.params || {}
      });
    }
    close();
  };

  const applyFix = () => {
    if (result?.definition && onReplaceDefinition) {
      onReplaceDefinition(result.definition, {
        name: result.name,
        trigger_type: result.trigger_type,
        trigger_config: result.trigger_config
      });
    }
    close();
  };

  return (
    <>
      <Wrapper ref={wrapRef} $open={open}>
        <NekoButton ai icon="sparkles" onClick={() => setOpen((o) => !o)} title="AI assist">
          <span className="mwflow-aiassist-label">
            AI assist
            <ChevronDown className="chev" size={14} />
          </span>
        </NekoButton>
        {open && (
          <Menu role="menu">
            <Item role="menuitem" onClick={() => run('explain')}>
              <FileText size={16} /> Explain this workflow
            </Item>
            <Item role="menuitem" onClick={() => run('suggest')}>
              <PlusCircle size={16} />
              {selectedNodeId ? 'Suggest a step after this one' : 'Suggest the next step'}
            </Item>
            <MenuDivider />
            <Item
              role="menuitem"
              $disabled={!lastError}
              onClick={() => lastError && run('fix')}
              title={lastError ? '' : 'Available after a step fails'}
            >
              <Wrench size={16} /> Fix the last error
            </Item>
          </Menu>
        )}
      </Wrapper>

      {mode && (
        <NekoModal isOpen size="normal">
          <div style={{ padding: '4px 4px 8px', minWidth: 420 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
              <Sparkles size={16} color="#4f46e5" />
              <strong>
                {mode === 'explain' && 'About this workflow'}
                {mode === 'suggest' && 'Suggested next step'}
                {mode === 'fix' && 'Suggested repair'}
              </strong>
            </div>

            {busy && <Spinner>AI Engine is thinking…</Spinner>}
            {error && <NekoMessage variant="danger">{error}</NekoMessage>}

            {!busy && !error && mode === 'explain' && (
              <ExplainBody>{result?.message || '—'}</ExplainBody>
            )}

            {!busy && !error && mode === 'suggest' && result?.suggestion?.step && (() => {
              const d = describeStep(result.suggestion.step);
              return (
                <SuggestionCard>
                  <SuggestionTitle>{d.name}</SuggestionTitle>
                  <SuggestionMeta>{d.integrationName}</SuggestionMeta>
                  {result.suggestion.why && (
                    <SuggestionWhy>{result.suggestion.why}</SuggestionWhy>
                  )}
                </SuggestionCard>
              );
            })()}

            {!busy && !error && mode === 'fix' && result?.errors && (
              <NekoMessage variant="warning">
                AI couldn't propose a fix:
                <ul style={{ marginTop: 6 }}>
                  {result.errors.slice(0, 4).map((e, i) => <li key={i}>{e}</li>)}
                </ul>
              </NekoMessage>
            )}

            {!busy && !error && mode === 'fix' && result?.definition && (
              <NekoMessage variant="info">
                A repaired workflow has been generated. Applying will replace your current steps.
              </NekoMessage>
            )}

            <ModalFooter>
              <NekoButton className="secondary" onClick={close}>Close</NekoButton>
              {mode === 'suggest' && result?.suggestion?.step && (
                <NekoButton className="primary" onClick={applySuggestion}>Add this step</NekoButton>
              )}
              {mode === 'fix' && result?.definition && (
                <NekoButton className="primary" onClick={applyFix}>Replace workflow</NekoButton>
              )}
            </ModalFooter>
          </div>
        </NekoModal>
      )}
    </>
  );
}
