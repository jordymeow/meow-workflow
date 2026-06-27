import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  NekoBlock, NekoButton, NekoEmpty, NekoStatus, NekoModal, NekoWrapper, NekoColumn, NekoMessage, NekoSpinner
} from '@neko-ui';
import { useState } from 'react';
import styled from 'styled-components';
import { Sparkles, Zap, Clock, Webhook, Anchor, Rss, Play, Pencil, Trash2, Loader2 } from 'lucide-react';
import { api } from '../helpers/api';
import { formatRelative } from '../helpers/time';
import AuthoringInput from '../components/AuthoringInput';
import ExamplesModal from '../components/ExamplesModal';
import NewWorkflowModal from '../components/NewWorkflowModal';
import SetupAssistant, { markAiBuilt, isSetupAssistantDismissed } from '../components/SetupAssistant';

const FlowList = styled.div`
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

const FlowRow = styled.div`
  display: grid;
  /* minmax(0, 1fr) lets the name/meta cell actually shrink (1fr alone won't
     go below content width), and the fixed columns stay lean so the layout
     survives a half-width window without wrapping. */
  grid-template-columns: 32px minmax(0, 1fr) 110px 96px 96px;
  align-items: center;
  gap: 12px;
  padding: 14px 18px;
  border-bottom: 1px solid #eef0f4;
  cursor: pointer;
  transition: background 0.12s;
  &:last-child { border-bottom: 0; }
  &:hover { background: #f8fafc; }
`;

const TriggerBadge = styled.span`
  width: 32px;
  height: 32px;
  border-radius: 9px;
  background: ${(p) => p.$bg || '#fff7ed'};
  color: ${(p) => p.$color || '#ea580c'};
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
`;

const FlowName = styled.div`
  font-size: 14px;
  font-weight: 600;
  color: #111827;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
`;

const FlowMeta = styled.div`
  font-size: 11.5px;
  color: #6b7280;
  margin-top: 2px;
  display: flex;
  align-items: center;
  gap: 6px;
  /* Never wrap "1 step" into "1 / step" — clip the tail instead. */
  white-space: nowrap;
  overflow: hidden;
  > span { flex-shrink: 0; }
`;

const MetaDot = styled.span`
  width: 3px;
  height: 3px;
  border-radius: 50%;
  background: #cbd5e1;
`;

// Failure indicator beside the name — a fixed-size dot can't wrap or clip,
// whatever the column width; the tooltip carries the words.
const FailedDot = styled.span`
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ef4444;
  flex-shrink: 0;
  box-shadow: 0 0 0 3px #fee2e2;
`;

const Cell = styled.div`
  font-size: 13px;
  color: #4b5563;
`;

const RowActions = styled.div`
  display: flex;
  gap: 2px;
  justify-content: flex-end;
`;

// Quiet ghost icon buttons for list rows — neutral at rest, tinted circle on
// hover. The loud filled circles read as alerts, not row tools.
const IconBtn = styled.button`
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: 0;
  background: transparent;
  color: #94a3b8;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.12s, color 0.12s;
  &:hover {
    background: ${(p) => (p.$danger ? '#fee2e2' : '#eff6ff')};
    color: ${(p) => (p.$danger ? '#dc2626' : 'var(--neko-blue, hsl(217 80% 42%))')};
  }
  &:disabled { opacity: 0.45; cursor: default; }
  @keyframes mwflow-spin { to { transform: rotate(360deg); } }
  svg.spinning { animation: mwflow-spin 0.9s linear infinite; }
`;

const ButtonRow = styled.div`
  display: flex;
  gap: 8px;
`;

const ModalTitle = styled.p`
  font-family: var(--neko-font-family);
  font-weight: bold;
  font-size: 18px;
  line-height: 22px;
  margin: 0 0 15px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
`;

// Mirrors NekoModal's built-in .button-group footer (grey bar that sits
// flush with the modal's bottom edge) so children-mode looks identical to
// content-mode.
const ModalFooter = styled.div`
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  background: #f0f0f0;
  padding: 10px;
  margin: 15px -15px -15px -15px;
`;

const TRIGGER_META = {
  manual:   { Icon: Zap,     label: 'Manual',   bg: '#fff7ed', color: '#ea580c' },
  schedule: { Icon: Clock,   label: 'Schedule', bg: '#ecfdf5', color: '#059669' },
  webhook:  { Icon: Webhook, label: 'Webhook',  bg: '#eff6ff', color: '#1d4ed8' },
  hook:     { Icon: Anchor,  label: 'WordPress event',  bg: '#f5f3ff', color: '#7c3aed' },
  rss:      { Icon: Rss,     label: 'RSS',              bg: '#fff7ed', color: '#ea580c' }
};

export default function FlowsScreen({ onOpen }) {
  const qc = useQueryClient();
  const flowsQuery = useQuery({ queryKey: ['flows'], queryFn: api.flows });
  const [pendingDelete, setPendingDelete] = useState(null);
  const [showNew, setShowNew] = useState(false);
  const [showExamples, setShowExamples] = useState(false);
  const [showAuthoring, setShowAuthoring] = useState(false);
  const [assistantDismissed, setAssistantDismissed] = useState(() => isSetupAssistantDismissed());
  const [authoringPrompt, setAuthoringPrompt] = useState('');
  // Structured error: { kind, message, details? }. null when no error.
  const [authoringError, setAuthoringError] = useState(null);

  const aiAvailable = !!window.mwflow?.aiEngine?.available;

  const openAuthoring = () => {
    setAuthoringPrompt('');
    setAuthoringError(null);
    setShowAuthoring(true);
  };
  const closeAuthoring = () => {
    setShowAuthoring(false);
    setAuthoringPrompt('');
    setAuthoringError(null);
  };

  const author = useMutation({
    mutationFn: (prompt) => api.author({ prompt, mode: 'new' }),
    onSuccess: (result) => {
      // 200-OK response that came back with validation errors instead of a flow.
      if (result.errors && !result.flow_id) {
        setAuthoringError({
          kind: result.error_kind || 'validation',
          message: result.message || "The AI's plan didn't quite line up with the available steps.",
          details: result.errors
        });
        return;
      }
      if (result.flow_id) {
        markAiBuilt();
        qc.invalidateQueries({ queryKey: ['flows'] });
        closeAuthoring();
        onOpen(result.flow_id);
      }
    },
    onError: (err) => {
      setAuthoringError({
        kind: err.kind || 'unknown',
        message: err.message || 'Failed to author the workflow.',
        details: null
      });
    }
  });
  const submitAuthoring = (overridePrompt) => {
    const value = (overridePrompt ?? authoringPrompt).trim();
    if (!value || author.isPending) return;
    setAuthoringError(null);
    author.mutate(value);
  };

  const createBlank = useMutation({
    mutationFn: () => {
      // Just the trigger, placed where the vertical flow starts — the canvas
      // onboarding hint points at the Add-step toolbar for the first action.
      // No guessed default step: Send Email was wrong more often than right.
      return api.createFlow({
        name: 'Untitled workflow',
        definition: {
          nodes: [
            {
              id: 'trigger',
              type: 'trigger',
              position: { x: 320, y: 60 },
              data: { kind: 'trigger', integration: 'core', trigger: 'manual', label: 'Manual', params: {} }
            }
          ],
          edges: []
        },
        trigger_type: 'manual'
      });
    },
    onSuccess: (flow) => {
      qc.invalidateQueries({ queryKey: ['flows'] });
      onOpen(flow.id);
    }
  });

  const remove = useMutation({
    mutationFn: (id) => api.deleteFlow(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['flows'] });
      setPendingDelete(null);
    }
  });

  // Run a manual flow straight from the list — same stepwise loop as the
  // editor's Test once, so long flows don't hit PHP timeouts.
  const [runFeedback, setRunFeedback] = useState(null);
  const runNow = useMutation({
    mutationFn: async (flow) => {
      let run = await api.testFlow(flow.id, {}, { stepwise: true });
      let guard = 0;
      while (run.status === 'running' && guard++ < 200) {
        run = await api.advanceRun(run.run_id);
      }
      return run;
    },
    onSuccess: (run, flow) => {
      qc.invalidateQueries({ queryKey: ['runs'] });
      setRunFeedback(run.status === 'done'
        ? { ok: true, message: `“${flow.name}” ran successfully — see the Runs tab for details.` }
        : { ok: false, message: `“${flow.name}” failed: ${run.error || 'a step failed.'}` });
    },
    onError: (err, flow) => {
      setRunFeedback({ ok: false, message: `“${flow.name}” failed: ${err.message || 'unknown error.'}` });
    }
  });

  const flows = flowsQuery.data || [];

  // One entry point for all three creation paths (example / AI / blank) —
  // the chooser modal presents them with context instead of a button pile.
  const headerActions = (
    <ButtonRow>
      <NekoButton className="primary" icon="plus" onClick={() => setShowNew(true)}>
        New workflow
      </NekoButton>
    </ButtonRow>
  );

  const flowsBlock = (
      <NekoBlock
        className="primary"
        title="Workflows"
        subtitle={flows.length > 0 ? `${flows.length} workflow${flows.length === 1 ? '' : 's'}` : null}
        action={headerActions}
      >
        {runFeedback && (
          <NekoMessage
            variant={runFeedback.ok ? 'success' : 'danger'}
            style={{ marginBottom: 12 }}
            onClick={() => setRunFeedback(null)}
          >
            {runFeedback.message}
          </NekoMessage>
        )}
        {flowsQuery.isLoading ? (
          <Loading><NekoSpinner /></Loading>
        ) : flows.length === 0 ? (
          <NekoEmpty
            icon="dashboard"
            title="No workflows yet"
            subtitle="Start from a working example, describe one to the AI, or build from a blank canvas."
            action={headerActions}
          />
        ) : (
          <FlowList>
            {flows.map((flow) => {
              const meta = TRIGGER_META[flow.trigger_type] || TRIGGER_META.manual;
              const Icon = meta.Icon;
              const stepCount = flow.step_count || 0;
              return (
                <FlowRow key={flow.id} onClick={() => onOpen(flow.id)}>
                  <TriggerBadge $bg={meta.bg} $color={meta.color}>
                    <Icon size={15} />
                  </TriggerBadge>
                  <div style={{ minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 }}>
                      <FlowName>{flow.name}</FlowName>
                      {flow.last_run_status === 'failed' && (
                        <FailedDot title="The most recent run of this workflow failed — check the Runs tab." />
                      )}
                    </div>
                    <FlowMeta>
                      <span>{meta.label}</span>
                      <MetaDot />
                      <span>{stepCount} step{stepCount === 1 ? '' : 's'}</span>
                    </FlowMeta>
                  </div>
                  <Cell style={{ color: '#94a3b8', fontSize: 12 }}>
                    {formatRelative(flow.updated)}
                  </Cell>
                  <Cell>
                    <NekoStatus status={flow.is_active ? 'success' : 'paused'} dot>
                      {flow.is_active ? 'Active' : 'Paused'}
                    </NekoStatus>
                  </Cell>
                  <RowActions onClick={(e) => e.stopPropagation()}>
                    {flow.trigger_type === 'manual' && (
                      <IconBtn
                        type="button"
                        onClick={() => { setRunFeedback(null); runNow.mutate(flow); }}
                        disabled={runNow.isPending}
                        title="Run now"
                      >
                        {runNow.isPending && runNow.variables?.id === flow.id
                          ? <Loader2 size={16} className="spinning" />
                          : <Play size={16} />}
                      </IconBtn>
                    )}
                    <IconBtn type="button" onClick={() => onOpen(flow.id)} title="Edit">
                      <Pencil size={15} />
                    </IconBtn>
                    <IconBtn type="button" $danger onClick={() => setPendingDelete(flow)} title="Delete">
                      <Trash2 size={15} />
                    </IconBtn>
                  </RowActions>
                </FlowRow>
              );
            })}
          </FlowList>
        )}
      </NekoBlock>
  );

  return (
    <>
      {/* Setup Assistant lives in its own block, in a column to the right of
          the workflows list (same layout as AI Engine's dashboard). Once
          dismissed, the list takes the full width. */}
      {assistantDismissed ? flowsBlock : (
        <NekoWrapper>
          <NekoColumn minimal>
            {flowsBlock}
          </NekoColumn>
          <NekoColumn minimal>
            <SetupAssistant
              flows={flows}
              aiAvailable={aiAvailable}
              onInstallExample={() => setShowExamples(true)}
              onBuildWithAi={openAuthoring}
              onOpenFlow={onOpen}
              onDismiss={() => setAssistantDismissed(true)}
            />
          </NekoColumn>
        </NekoWrapper>
      )}

      <NewWorkflowModal
        isOpen={showNew}
        onClose={() => setShowNew(false)}
        aiAvailable={aiAvailable}
        onExamples={() => setShowExamples(true)}
        onBuildWithAi={openAuthoring}
        onBlank={() => createBlank.mutate()}
      />

      <ExamplesModal
        isOpen={showExamples}
        onClose={() => setShowExamples(false)}
        onOpenFlow={(id) => { setShowExamples(false); onOpen(id); }}
      />

      {pendingDelete && (
        <NekoModal
          isOpen
          title="Delete workflow?"
          content={`This will permanently remove "${pendingDelete.name}" and all of its runs.`}
          okButton={{
            label: 'Delete',
            className: 'danger',
            onClick: () => remove.mutate(pendingDelete.id)
          }}
          cancelButton={{ label: 'Cancel', onClick: () => setPendingDelete(null) }}
        />
      )}

      {showAuthoring && (
        <NekoModal isOpen size="larger">
          <ModalTitle>
            <Sparkles size={16} color="#4f46e5" />
            Build a workflow with AI
          </ModalTitle>
          <AuthoringInput
            value={authoringPrompt}
            onChange={setAuthoringPrompt}
            onSubmit={(override) => submitAuthoring(override)}
            busy={author.isPending}
            error={authoringError}
            autoFocus
          />
          <ModalFooter>
            <NekoButton className="danger" onClick={closeAuthoring}>Cancel</NekoButton>
            <NekoButton
              ai
              icon="sparkles"
              onClick={() => submitAuthoring()}
              disabled={author.isPending || !authoringPrompt.trim()}
              isBusy={author.isPending}
            >
              {author.isPending ? 'Building…' : 'Build'}
            </NekoButton>
          </ModalFooter>
        </NekoModal>
      )}
    </>
  );
}
