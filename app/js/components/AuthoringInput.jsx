import { useEffect, useRef, useState } from 'react';
import styled from 'styled-components';
import { AlertCircle, AlertTriangle, ChevronDown, ChevronRight, Plug } from 'lucide-react';

const Wrap = styled.div`
  display: flex;
  flex-direction: column;
  gap: 12px;
`;

const Subtitle = styled.div`
  font-size: 12.5px;
  color: #475569;
  line-height: 1.45;
  kbd {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    padding: 1px 5px;
    font-size: 11px;
    font-family: inherit;
  }
`;

const PromptInput = styled.textarea`
  width: 100%;
  background: white;
  border: 1px solid #c7d2fe;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 13.5px;
  font-family: inherit;
  resize: vertical;
  min-height: 80px;
  max-height: 240px;
  box-sizing: border-box;
  &:focus {
    outline: none;
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
  }
  &::placeholder { color: #94a3b8; }
`;

const ChipRow = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
`;

const Chip = styled.button`
  background: rgba(255, 255, 255, 0.7);
  border: 1px solid #c7d2fe;
  color: #3730a3;
  border-radius: 999px;
  padding: 4px 12px;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.12s, border-color 0.12s;
  &:hover {
    background: #eef2ff;
    border-color: #6366f1;
  }
  &:disabled { opacity: 0.5; cursor: not-allowed; }
`;

const ErrorCard = styled.div`
  display: flex;
  gap: 10px;
  align-items: flex-start;
  background: ${(p) => (p.$kind === 'ai_unavailable' ? '#fff7ed' : '#fef2f2')};
  border: 1px solid ${(p) => (p.$kind === 'ai_unavailable' ? '#fed7aa' : '#fecaca')};
  border-radius: 8px;
  padding: 12px 14px;
`;

const ErrorIcon = styled.span`
  flex-shrink: 0;
  color: ${(p) => (p.$kind === 'ai_unavailable' ? '#c2410c' : '#b91c1c')};
  display: flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
`;

const ErrorBody = styled.div`
  flex: 1;
  min-width: 0;
`;

const ErrorTitle = styled.div`
  font-size: 13px;
  font-weight: 700;
  color: ${(p) => (p.$kind === 'ai_unavailable' ? '#7c2d12' : '#7f1d1d')};
  margin-bottom: 2px;
`;

const ErrorMessage = styled.div`
  font-size: 12.5px;
  color: ${(p) => (p.$kind === 'ai_unavailable' ? '#9a3412' : '#991b1b')};
  line-height: 1.45;
`;

const DetailsToggle = styled.button`
  margin-top: 6px;
  background: transparent;
  border: 0;
  padding: 0;
  font-size: 11.5px;
  color: #7f1d1d;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  opacity: 0.85;
  &:hover { opacity: 1; text-decoration: underline; }
`;

const ErrorList = styled.ul`
  margin: 6px 0 0;
  padding-left: 18px;
  font-size: 11.5px;
  color: #7f1d1d;
  line-height: 1.4;
  & li { margin-bottom: 2px; }
`;

const ERROR_TITLES = {
  ai_unavailable: 'AI Engine isn\'t available',
  ai_failed: 'AI Engine couldn\'t complete the request',
  invalid_output: 'The AI returned an unreadable response',
  validation: 'The AI\'s plan didn\'t match the available steps',
  empty_prompt: 'Please describe what the workflow should do',
  http_error: 'Couldn\'t reach the server',
  unknown: 'Something went wrong'
};

function ErrorPanel({ error }) {
  const [showDetails, setShowDetails] = useState(false);
  const kind = error.kind || 'unknown';
  const title = ERROR_TITLES[kind] || ERROR_TITLES.unknown;
  const Icon = kind === 'ai_unavailable' ? Plug : (kind === 'validation' ? AlertTriangle : AlertCircle);
  const hasDetails = Array.isArray(error.details) && error.details.length > 0;

  return (
    <ErrorCard $kind={kind}>
      <ErrorIcon $kind={kind}><Icon size={18} /></ErrorIcon>
      <ErrorBody>
        <ErrorTitle $kind={kind}>{title}</ErrorTitle>
        <ErrorMessage $kind={kind}>{error.message}</ErrorMessage>
        {hasDetails && (
          <>
            <DetailsToggle type="button" onClick={() => setShowDetails((v) => !v)}>
              {showDetails ? <ChevronDown size={12} /> : <ChevronRight size={12} />}
              {showDetails ? 'Hide details' : 'Show details'} ({error.details.length})
            </DetailsToggle>
            {showDetails && (
              <ErrorList>
                {error.details.slice(0, 6).map((e, i) => <li key={i}>{e}</li>)}
              </ErrorList>
            )}
          </>
        )}
      </ErrorBody>
    </ErrorCard>
  );
}

const RECIPES = [
  'When someone POSTs JSON to a webhook, summarise it with the AI and email the summary to admin@example.com.',
  'Every Monday at 8am, ask the AI to write a short brief about my latest published post and email it to me.',
  'When a new post is published, ask the AI for a 1-sentence excerpt and save it on the post.',
  'When a new comment is posted, ask the AI to classify it as spam, off-topic, or legitimate, and log the result.'
];

/**
 * Presentational prompt composer. State (value + submission) is owned by the
 * parent so the parent can wire a NekoModal's okButton to the same submit
 * action that the textarea triggers via ⌘/Ctrl+Enter.
 */
export default function AuthoringInput({
  value,
  onChange,
  onSubmit,
  busy = false,
  error = null,
  autoFocus = false
}) {
  const ref = useRef(null);

  useEffect(() => {
    if (autoFocus && ref.current) ref.current.focus();
  }, [autoFocus]);

  // Re-focus the textarea whenever a new error appears so the user can
  // immediately reword their prompt.
  useEffect(() => {
    if (error && ref.current) ref.current.focus();
  }, [error]);

  return (
    <Wrap>
      <Subtitle>
        Tell AI Engine what should happen, in plain English. <kbd>⌘</kbd> + <kbd>Enter</kbd> to build.
      </Subtitle>
      <PromptInput
        ref={ref}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder="e.g. Every Monday morning, ask the AI to summarise last week's posts and email me the summary."
        onKeyDown={(e) => {
          if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') onSubmit();
        }}
        disabled={busy}
      />
      <ChipRow>
        {RECIPES.map((r) => (
          // Fill the prompt only — the user reviews/edits and builds when ready.
          <Chip key={r} type="button" onClick={() => { onChange(r); ref.current?.focus(); }} disabled={busy}>
            {r.length > 60 ? r.slice(0, 57) + '…' : r}
          </Chip>
        ))}
      </ChipRow>
      {error && <ErrorPanel error={error} />}
    </Wrap>
  );
}
