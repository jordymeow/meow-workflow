// SetupAssistant.jsx
//
// Friendly, opinionated walk-through for the Workflows screen — same pattern
// as AI Engine's Setup Assistant (numbered coloured dots, progress bar,
// "Tell me more" info boxes, dismissible block) with Meow Workflow steps:
// Example → Test once → References → Branching → Activate → Build with AI.
//
// Dot colours mirror live state where detectable (flows exist, runs exist,
// a flow is active, an AI build happened); pure-learning steps turn green on
// "Got it". Dismissal is per-browser (localStorage).

import { useState, useCallback } from 'react';
import { useQuery } from '@tanstack/react-query';
import styled from 'styled-components';
import { NekoBlock, NekoTypo, NekoButton } from '@neko-ui';
import { api } from '../helpers/api';

const STORAGE_KEY = 'mwflow_setup_assistant';
const AI_BUILT_KEY = 'mwflow.gettingStarted.aiBuilt';

export const isSetupAssistantDismissed = () => {
  try {
    const s = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    return !!s.dismissed;
  }
  catch (e) { return false; }
};

export const resetSetupAssistant = () => {
  try { localStorage.removeItem(STORAGE_KEY); }
  catch (e) { /* ignore */ }
};

export function markAiBuilt() {
  try { localStorage.setItem(AI_BUILT_KEY, '1'); } catch (e) { /* ignore */ }
}

const STEP_COLORS = {
  default: '#e0e0e0',
  green: '#48c7be',
  orange: '#f0a030'
};

const ProgressBar = styled.div`
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 16px 0 18px;
  font-size: 12px;
  color: #666;
`;

const ProgressTrack = styled.div`
  flex: 1;
  height: 6px;
  background: rgba(0, 0, 0, 0.06);
  border-radius: 3px;
  overflow: hidden;
`;

const ProgressFill = styled.div`
  height: 100%;
  background: linear-gradient(90deg, #48c7be, #2ea99f);
  border-radius: 3px;
  transition: width 0.4s ease;
  width: ${(p) => p.$pct}%;
`;

const StyledStep = styled.div`
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 12px;
  margin: 0 -12px;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
  border-radius: 6px;
  transition: background 0.15s ease;

  ${(p) => p.$isNext && `
    background: rgba(72, 199, 190, 0.04);
    box-shadow: inset 2px 0 0 #48c7be;
  `}

  &:hover { background: rgba(0, 0, 0, 0.015); }
  &:last-child { border-bottom: none; }
`;

const StepNumber = styled.div`
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 13px;
  flex-shrink: 0;
  background: ${(p) => p.$color || STEP_COLORS.default};
  color: ${(p) => (p.$color && p.$color !== STEP_COLORS.default ? '#fff' : '#666')};
  transition: all 0.2s ease;
`;

const StepContent = styled.div`
  flex: 1;
  min-width: 0;
`;

const StepTitle = styled.div`
  font-weight: 600;
  font-size: 14px;
  margin-bottom: 4px;
  color: #1e1e1e;
`;

const StepDescription = styled.div`
  font-size: 13px;
  color: #555;
  line-height: 1.5;

  code {
    background: rgba(0, 0, 0, 0.05);
    padding: 1px 5px;
    border-radius: 4px;
    font-size: 12px;
  }
`;

const ChoiceButtons = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
`;

const ChoiceButton = styled.button`
  padding: 4px 14px;
  border-radius: 4px;
  border: 1px solid ${(p) => (p.$active ? '#48c7be' : '#ccc')};
  background: ${(p) => (p.$active ? '#48c7be' : '#fff')};
  color: ${(p) => (p.$active ? '#fff' : '#333')};
  cursor: pointer;
  font-size: 12px;
  font-weight: 500;
  transition: all 0.15s ease;

  &:hover { border-color: #48c7be; }
`;

const InfoBox = styled.div`
  margin-top: 8px;
  padding: 10px 14px;
  background: rgba(0, 0, 0, 0.03);
  border-radius: 6px;
  border-left: 3px solid #999;
  font-size: 13px;
  color: #555;
  line-height: 1.6;

  b { color: #1e1e1e; }
  code {
    background: rgba(0, 0, 0, 0.06);
    padding: 1px 5px;
    border-radius: 4px;
    font-size: 12px;
  }
`;

const CelebrationBox = styled.div`
  margin: 14px 0 4px;
  padding: 18px 22px;
  background: linear-gradient(135deg, rgba(72, 199, 190, 0.12), rgba(13, 125, 242, 0.08));
  border-radius: 10px;
  border: 1px solid rgba(72, 199, 190, 0.25);
  display: flex;
  align-items: center;
  gap: 14px;

  .emoji { font-size: 28px; line-height: 1; }
  .text {
    flex: 1;
    font-size: 14px;
    color: #1e1e1e;
    line-height: 1.45;
    b { display: block; font-size: 15px; margin-bottom: 2px; }
  }
`;

const getInitialState = () => {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored) return JSON.parse(stored);
  }
  catch (e) { /* ignore */ }
  return { dismissed: false, steps: {} };
};

const SetupAssistant = ({ flows, aiAvailable, onInstallExample, onBuildWithAi, onOpenFlow, onDismiss }) => {
  const [state, setState] = useState(getInitialState);
  const runs = useQuery({ queryKey: ['runs'], queryFn: api.runs, enabled: !state.dismissed });

  const persist = useCallback((next) => {
    setState(next);
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(next)); }
    catch (e) { /* ignore */ }
  }, []);

  const setChoice = useCallback((key, value) => {
    persist({ ...state, steps: { ...state.steps, [key]: value } });
  }, [state, persist]);

  const dismiss = useCallback(() => {
    persist({ ...state, dismissed: true });
    if (onDismiss) onDismiss();
  }, [state, persist, onDismiss]);

  if (state.dismissed) return null;

  // Live status from real data — the dots stay accurate whatever the user
  // clicks, exactly like AI Engine's assistant.
  const hasFlow = (flows || []).length > 0;
  const hasRun = (runs.data || []).length > 0;
  const hasActive = (flows || []).some((f) => f.is_active);
  let aiBuilt = false;
  try { aiBuilt = localStorage.getItem(AI_BUILT_KEY) === '1'; } catch (e) { /* ignore */ }
  const firstFlowId = (flows || [])[0]?.id;

  const infoColor = (choice) => {
    if (!choice) return STEP_COLORS.default;
    return choice === 'ok' ? STEP_COLORS.green : STEP_COLORS.orange;
  };
  const liveColor = (done, choice) => {
    if (done) return STEP_COLORS.green;
    if (choice === 'info') return STEP_COLORS.orange;
    return STEP_COLORS.default;
  };

  const stepStatuses = [
    hasFlow,
    hasRun,
    state.steps.references === 'ok',
    state.steps.branching === 'ok',
    hasActive,
    ...(aiAvailable ? [aiBuilt || state.steps.ai === 'ok'] : [])
  ];
  const total = stepStatuses.length;
  const greenSteps = stepStatuses.filter(Boolean).length;
  const allDone = greenSteps === total;
  const nextStepIndex = stepStatuses.findIndex((s) => !s);
  const isNext = (n) => nextStepIndex === (n - 1);

  return (
    <NekoBlock className="primary" title="Setup Assistant" action={
      <NekoButton className="secondary" onClick={dismiss}
        title="Dismiss the assistant. You can bring it back from Settings → Maintenance.">
        Dismiss
      </NekoButton>
    }>
      <NekoTypo p style={{ marginTop: 0, marginBottom: 4 }}>
        The fastest path through Meow Workflow. Every step is optional.
      </NekoTypo>

      <ProgressBar>
        <b>{greenSteps} of {total} complete</b>
        <ProgressTrack>
          <ProgressFill $pct={Math.round((greenSteps / total) * 100)} />
        </ProgressTrack>
      </ProgressBar>

      {allDone && <CelebrationBox>
        <div className="emoji" aria-hidden>🎉</div>
        <div className="text">
          <b>You're all set!</b>
          You know your way around Meow Workflow. Dismiss this assistant whenever you like.
        </div>
      </CelebrationBox>}

      {/* Step 1: Install an example ------------------------------------- */}
      <StyledStep $isNext={isNext(1)}>
        <StepNumber $color={liveColor(hasFlow, state.steps.example)}>1</StepNumber>
        <StepContent>
          <StepTitle>Install an Example Workflow</StepTitle>
          <StepDescription>
            Looking at a working flow is the fastest way to learn. Examples install <b>paused</b>, so nothing runs until you say so.
            {hasFlow && <>{' '}<b style={{ color: STEP_COLORS.green }}>Done!</b> You have workflows in the list.</>}
          </StepDescription>
          <ChoiceButtons>
            <ChoiceButton onClick={onInstallExample}>Open Examples</ChoiceButton>
            <ChoiceButton $active={state.steps.example === 'info'} onClick={() => setChoice('example', 'info')}>
              Tell me more
            </ChoiceButton>
          </ChoiceButtons>
          {state.steps.example === 'info' && <InfoBox>
            The gallery has categories: <b>Basics</b> need no extra plugin (try <b>Email Me When a Post Is Published</b>, or <b>Coin Flip</b> to learn branching), while <b>AI</b>, <b>WooCommerce</b>, and <b>SEO</b> examples light up when the matching plugin is active. Installing the same example twice just creates a second copy — delete what you don't keep.
          </InfoBox>}
        </StepContent>
      </StyledStep>

      {/* Step 2: Test once ----------------------------------------------- */}
      <StyledStep $isNext={isNext(2)}>
        <StepNumber $color={liveColor(hasRun, state.steps.test)}>2</StepNumber>
        <StepContent>
          <StepTitle>Run It With Test Once</StepTitle>
          <StepDescription>
            Open a workflow and press <b>Test once</b> — each step lights up as it runs, and clicking one shows what it produced.
            {hasRun && <>{' '}<b style={{ color: STEP_COLORS.green }}>Done!</b> Check the Runs tab for the history.</>}
          </StepDescription>
          <ChoiceButtons>
            {firstFlowId && <ChoiceButton onClick={() => onOpenFlow(firstFlowId)}>Open a Workflow</ChoiceButton>}
            <ChoiceButton $active={state.steps.test === 'info'} onClick={() => setChoice('test', 'info')}>
              Tell me more
            </ChoiceButton>
          </ChoiceButtons>
          {state.steps.test === 'info' && <InfoBox>
            Test once always runs your <b>draft</b>, never the published version — so you can experiment safely on a live workflow. Event-based workflows (a post is published, an order comes in…) are tested against <b>real data from your site</b>: the latest post, user, comment, or order. Webhook flows can <b>capture</b> a real call as their test sample.
          </InfoBox>}
        </StepContent>
      </StyledStep>

      {/* Step 3: References ---------------------------------------------- */}
      <StyledStep $isNext={isNext(3)}>
        <StepNumber $color={infoColor(state.steps.references)}>3</StepNumber>
        <StepContent>
          <StepTitle>Pass Data Between Steps</StepTitle>
          <StepDescription>
            Steps share data with <code>{'{{ references }}'}</code> — <code>{'{{ trigger.post_id }}'}</code> from the trigger, <code>{'{{ fetch_post.title }}'}</code> from an earlier step. Each step shows its <code>#id</code> top-left.
          </StepDescription>
          <ChoiceButtons>
            <ChoiceButton $active={state.steps.references === 'ok'} onClick={() => setChoice('references', 'ok')}>
              Got it
            </ChoiceButton>
            <ChoiceButton $active={state.steps.references === 'info'} onClick={() => setChoice('references', 'info')}>
              Tell me more
            </ChoiceButton>
          </ChoiceButtons>
          {state.steps.references === 'info' && <InfoBox>
            You never have to type references by hand: every field has an <b>Insert data</b> button listing everything available. Used references appear as <b>coloured chips</b> under the field — click one to jump to the step it comes from; a <b>red chip</b> means the reference is broken. Renaming a step's <code>#id</code> (click it in the side panel) safely rewrites every reference to it. Formatting filters work too: <code>{'{{ post.title | upper }}'}</code>, <code>{'{{ now | date:"F j" }}'}</code>, <code>{'{{ data | json }}'}</code>. In a JSON body, a reference inside quotes is escaped for you, so <code>{'{ "content": "{{ scrape.markdown }}" }'}</code> stays valid whatever the text contains.
          </InfoBox>}
        </StepContent>
      </StyledStep>

      {/* Step 4: Branching ------------------------------------------------ */}
      <StyledStep $isNext={isNext(4)}>
        <StepNumber $color={infoColor(state.steps.branching)}>4</StepNumber>
        <StepContent>
          <StepTitle>Branch Your Flows</StepTitle>
          <StepDescription>
            <b>Condition</b> splits a flow into true/false paths; <b>Branch By Value</b> routes down named paths (spam / insulting / ok…). The <b>Coin Flip</b> example is a 30-second way to see it work.
          </StepDescription>
          <ChoiceButtons>
            <ChoiceButton $active={state.steps.branching === 'ok'} onClick={() => setChoice('branching', 'ok')}>
              Got it
            </ChoiceButton>
            <ChoiceButton $active={state.steps.branching === 'info'} onClick={() => setChoice('branching', 'info')}>
              Tell me more
            </ChoiceButton>
          </ChoiceButtons>
          {state.steps.branching === 'info' && <InfoBox>
            Branching steps grow <b>coloured connectors</b> at their bottom edge — drag each one to the step that path should run. Anything not matched simply doesn't run. Every step also has <b>Error handling</b>: stop the flow, continue anyway, retry, or follow a dedicated failure branch for recovery logic.
          </InfoBox>}
        </StepContent>
      </StyledStep>

      {/* Step 5: Make it live --------------------------------------------- */}
      <StyledStep $isNext={isNext(5)}>
        <StepNumber $color={liveColor(hasActive, state.steps.live)}>5</StepNumber>
        <StepContent>
          <StepTitle>Make It Live</StepTitle>
          <StepDescription>
            <b>Active/Paused</b> controls whether the trigger fires; <b>Publish</b> controls which version runs. Edits stay in a draft until you publish.
            {hasActive && <>{' '}<b style={{ color: STEP_COLORS.green }}>Done!</b> At least one workflow is active.</>}
          </StepDescription>
          <ChoiceButtons>
            {firstFlowId && <ChoiceButton onClick={() => onOpenFlow(firstFlowId)}>Open a Workflow</ChoiceButton>}
            <ChoiceButton $active={state.steps.live === 'info'} onClick={() => setChoice('live', 'info')}>
              Tell me more
            </ChoiceButton>
          </ChoiceButtons>
          {state.steps.live === 'info' && <InfoBox>
            This separation is what makes editing safe: a live workflow keeps running its <b>published</b> version while you change the draft, and Test once always exercises the draft. When you're happy, hit <b>Publish</b> (top right) and the live version updates atomically. Pausing never loses anything — the trigger just stops firing.
          </InfoBox>}
        </StepContent>
      </StyledStep>

      {/* Step 6: Build with AI -------------------------------------------- */}
      {aiAvailable && (
        <StyledStep $isNext={isNext(6)}>
          <StepNumber $color={liveColor(aiBuilt || state.steps.ai === 'ok', state.steps.ai)}>6</StepNumber>
          <StepContent>
            <StepTitle>Build One With AI</StepTitle>
            <StepDescription>
              Describe a workflow in plain English — "when an order comes in, email me a summary" — and AI Engine assembles it from your installed steps.
              {aiBuilt && <>{' '}<b style={{ color: STEP_COLORS.green }}>Done!</b> You've built one.</>}
            </StepDescription>
            <ChoiceButtons>
              <ChoiceButton onClick={onBuildWithAi}>Build with AI</ChoiceButton>
              <ChoiceButton $active={state.steps.ai === 'info'} onClick={() => setChoice('ai', 'info')}>
                Tell me more
              </ChoiceButton>
            </ChoiceButtons>
            {state.steps.ai === 'info' && <InfoBox>
              Inside the editor, the <b>AI assist</b> menu goes further: <b>Explain</b> summarises any workflow in plain English, <b>Suggest</b> proposes a sensible next step, and <b>Fix</b> repairs a flow whose last test failed. The AI only ever uses steps that are actually installed on your site.
            </InfoBox>}
          </StepContent>
        </StyledStep>
      )}
    </NekoBlock>
  );
};

export default SetupAssistant;
