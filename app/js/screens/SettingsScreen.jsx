import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useRef, useState } from 'react';
import styled from 'styled-components';
import { NekoBlock, NekoMessage, NekoSpacer, NekoButton, NekoModal, NekoWrapper, NekoColumn, NekoCheckbox, NekoInput } from '@neko-ui';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { api } from '../helpers/api';
import { resetSetupAssistant } from '../components/SetupAssistant';
import { StepIcon, StepGlyph } from '../editor/stepVisual';

const NotifyEmailRow = styled.div`
  margin-top: 14px;
  max-width: 420px;
`;

const NotifyHint = styled.div`
  font-size: 12px;
  color: #64748b;
  margin-top: 6px;
`;

// A representative icon per integration, drawn from the same vocabulary the
// canvas steps use so Settings and the editor read as one product. Unknown
// (third-party) integrations fall back to their first action's icon, then a box.
const INTEGRATION_ICONS = {
  core: 'git-branch',
  wordpress: 'globe',
  'ai-engine': 'sparkles',
  'code-engine': 'square-function',
  'seo-engine': 'search',
  'social-engine': 'send',
  woocommerce: 'shopping-cart'
};
const iconFor = (it) => INTEGRATION_ICONS[it.id] || it.actions?.[0]?.icon || 'box';

const Grid = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 14px;
`;

const Card = styled.div`
  display: flex;
  flex-direction: column;
  background: white;
  border: 1px solid #e4e6eb;
  border-radius: 10px;
  overflow: hidden;
  transition: box-shadow 0.15s, transform 0.15s;
  &:hover {
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
    transform: translateY(-1px);
  }
`;

const CardHead = styled.button`
  display: flex;
  align-items: center;
  gap: 13px;
  padding: 14px 16px;
  width: 100%;
  text-align: left;
  background: transparent;
  border: 0;
  cursor: pointer;
  font: inherit;
`;

const ExpandIcon = styled.span`
  margin-left: auto;
  flex-shrink: 0;
  color: #cbd5e1;
  display: inline-flex;
`;

const BuiltInTag = styled.span`
  display: inline-block;
  padding: 1px 8px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #64748b;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  /* "BUILT-IN" must never break at the hyphen onto two lines; if the card is
     too narrow it drops to its own line whole (see Name's flex-wrap). */
  white-space: nowrap;
  flex-shrink: 0;
`;

const StepsLabel = styled.div`
  font-size: 10.5px;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin: 14px 0 7px;
  &:first-child { margin-top: 0; }
`;

const StepChips = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
`;

const StepChip = styled.span`
  display: inline-block;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  background: ${(p) => (p.$trigger ? '#fff7ed' : '#eff6ff')};
  color: ${(p) => (p.$trigger ? '#c2410c' : '#1d4ed8')};
  border: 1px solid ${(p) => (p.$trigger ? '#fed7aa' : '#dbeafe')};
`;

const CardBody = styled.div`
  flex: 1;
  min-width: 0;
`;

const Name = styled.div`
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 4px 8px;
`;

const Version = styled.span`
  font-size: 11px;
  color: #94a3b8;
  font-weight: 500;
`;

const Description = styled.div`
  font-size: 12.5px;
  color: #64748b;
  margin-top: 4px;
  line-height: 1.45;
`;

const CardCount = styled.div`
  margin-top: 7px;
  font-size: 11px;
  font-weight: 600;
  color: #94a3b8;
  letter-spacing: 0.01em;
`;

const ModalTitle = styled.span`
  display: inline-flex;
  align-items: center;
  gap: 10px;
`;

const ModalDescription = styled.p`
  margin: 0 0 6px;
  font-size: 13px;
  color: #475569;
  line-height: 1.5;
`;

// Bento grid of what's enabled. Colourful tile = installed (with a live count
// of what it contributes); muted tile = not installed.
const InfoGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  gap: 10px;
  margin-top: 16px;
`;

const Tile = styled.div`
  position: relative;
  min-height: 78px;
  border-radius: 14px;
  padding: 13px 13px 12px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  overflow: hidden;
  transition: transform 0.14s ease, box-shadow 0.14s ease;
  ${(p) => p.$on
    ? `
      color: #fff;
      background-color: ${p.$color};
      background-image:
        linear-gradient(150deg, rgba(255,255,255,0.20), rgba(255,255,255,0) 46%),
        ${p.$grad};
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.16),
                  inset 0 -10px 22px rgba(0,0,0,0.16),
                  0 4px 12px rgba(15,23,42,0.10);
      &:hover { transform: translateY(-1px); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.18), inset 0 -10px 22px rgba(0,0,0,0.16), 0 8px 20px rgba(15,23,42,0.16); }
    `
    : `
      color: #94a3b8;
      background: #f4f6f9;
      border: 1.5px dashed #e2e8f0;
    `}
`;

const TileIcon = styled.span`
  display: inline-flex;
  opacity: 0.95;
`;

const TileName = styled.div`
  font-size: 13px;
  font-weight: 800;
  line-height: 1.2;
`;

const TileStat = styled.div`
  font-size: 11px;
  font-weight: 600;
  opacity: ${(p) => (p.$on ? 0.92 : 1)};
`;

// Short role explanation at the top of each integrations block.
const BlockIntro = styled.p`
  margin: 0 0 14px;
  font-size: 12.5px;
  color: #475569;
  line-height: 1.55;
  b { color: #0f172a; }
`;

// Numbered step badge for the three integration sections. The block title
// sits on the brand-blue workspace, so the badge is white-on-blue to read.
const NumberBadge = styled.span`
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: #fff;
  color: var(--neko-blue, hsl(217 80% 42%));
  font-size: 13px;
  font-weight: 800;
  margin-right: 11px;
  vertical-align: 2px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18);
`;

const numberedTitle = (n, text) => <span><NumberBadge>{n}</NumberBadge>{text}</span>;

// Muted placeholder when an engine that would add steps isn't installed.
const MissingCard = styled.div`
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  background: #fafbfc;
  border: 1.5px dashed #e2e8f0;
  border-radius: 10px;
  color: #94a3b8;
  font-size: 12.5px;
  line-height: 1.45;
  b { color: #64748b; }
`;

const DiagnosticsHeader = styled.button`
  width: 100%;
  text-align: left;
  background: transparent;
  border: 0;
  padding: 0;
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  color: #475569;
  font-size: 13px;
  font-weight: 600;
  &:hover { color: var(--neko-blue, hsl(217 80% 42%)); }
`;

const LogPre = styled.pre`
  margin: 12px 0 0;
  background: #0b1220;
  color: #cbd5e1;
  padding: 14px 16px;
  border-radius: 8px;
  font-size: 11.5px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  max-height: 320px;
  overflow: auto;
  white-space: pre-wrap;
`;

const ButtonRow = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 16px;
  /* NekoButton ships a 5px fallback sibling margin; with gap it double-spaces
     and the wrapped Reset ends up indented. Zero them — gap is the spacing. */
  > * { margin: 0 !important; }
`;

// Destructive action sits on its own line, separated, so Reset never wraps up
// against the safe actions or looks misaligned at any width.
const DangerRow = styled.div`
  display: flex;
  margin-top: 10px;
  padding-top: 12px;
  border-top: 1px solid #eef0f4;
  > * { margin: 0 !important; }
`;

const MaintenanceHelp = styled.p`
  margin: 0;
  font-size: 12.5px;
  color: #475569;
  line-height: 1.5;
  strong { color: #0f172a; }
`;

const BUILT_IN_IDS = new Set(['core', 'wordpress']);

// The integrations worth surfacing in the "Information" bento, in display
// order. Installed ones render colourful with a live count; the rest show as
// "Not installed" so people see what plugging in another engine would add.
const FEATURE_TILES = [
  // Curated, harmonious palette tuned as a set — distinct hues, even saturation,
  // each a soft top-left→bottom-right gradient for depth. `color` is the solid
  // base (shadow tint + fallback); `grad` is the painted face.
  { id: 'core',          label: 'Core',          icon: 'git-branch',      color: '#455062', grad: 'linear-gradient(145deg, #6b7689 0%, #455062 100%)' },
  { id: 'wordpress',     label: 'WordPress',     icon: 'globe',           color: '#1d6b90', grad: 'linear-gradient(145deg, #3196c7 0%, #1d6b90 100%)' },
  { id: 'ai-engine',     label: 'AI Engine',     icon: 'sparkles',        color: '#0e9a6e', grad: 'linear-gradient(145deg, #34d399 0%, #0e9a6e 100%)' },
  { id: 'code-engine',   label: 'Code Engine',   icon: 'square-function', color: '#5356e2', grad: 'linear-gradient(145deg, #828bf8 0%, #5356e2 100%)' },
  { id: 'seo-engine',    label: 'SEO Engine',    icon: 'search',          color: '#0b8fcf', grad: 'linear-gradient(145deg, #38bdf8 0%, #0b8fcf 100%)' },
  { id: 'social-engine', label: 'Social Engine', icon: 'send',            color: '#d83a8f', grad: 'linear-gradient(145deg, #f472b6 0%, #d83a8f 100%)' },
  { id: 'woocommerce',   label: 'WooCommerce',   icon: 'shopping-cart',   color: '#7e45ba', grad: 'linear-gradient(145deg, #b274e2 0%, #7e45ba 100%)' }
];

// Core's internal pseudo-hook triggers (hooks starting with `__`) surface
// through the trigger panel, not as named events — hide them from the lists.
const visibleTriggers = (it) => (it.triggers || []).filter((t) => t.hook && !t.hook.startsWith('__'));

/**
 * One integration card: who it is + a count of what it adds. Clicking opens a
 * modal with the full trigger/action list — cleaner than expanding inline.
 */
function IntegrationCard({ it, onOpen }) {
  const triggers = visibleTriggers(it);
  const actions = it.actions || [];
  const total = triggers.length + actions.length;
  const parts = [];
  if (triggers.length) parts.push(`${triggers.length} trigger${triggers.length === 1 ? '' : 's'}`);
  if (actions.length) parts.push(`${actions.length} action${actions.length === 1 ? '' : 's'}`);

  return (
    <Card>
      <CardHead type="button" onClick={() => onOpen(it)} title="View triggers and actions">
        <StepIcon icon={iconFor(it)} color={it.color} size={42} />
        <CardBody>
          <Name>
            {it.name}
            {it.version && <Version>v{it.version}</Version>}
            {BUILT_IN_IDS.has(it.id) && <BuiltInTag>Built-in</BuiltInTag>}
          </Name>
          <Description>{it.description || 'No description provided.'}</Description>
          {total > 0 && <CardCount>{parts.join(' · ')}</CardCount>}
        </CardBody>
        <ExpandIcon><ChevronRight size={16} /></ExpandIcon>
      </CardHead>
    </Card>
  );
}

/**
 * Modal listing an integration's triggers and actions in full — the question
 * people actually have when they click a card.
 */
function IntegrationModal({ it, onClose }) {
  const triggers = it ? visibleTriggers(it) : [];
  const actions = it ? it.actions || [] : [];

  return (
    <NekoModal
      isOpen={!!it}
      onRequestClose={onClose}
      title={it ? (
        <ModalTitle>
          <StepIcon icon={iconFor(it)} color={it.color} size={30} />
          <span>{it.name}</span>
          {it && it.version && <Version>v{it.version}</Version>}
        </ModalTitle>
      ) : ''}
      content={it && (
        <div>
          <ModalDescription>{it.description || 'No description provided.'}</ModalDescription>
          {triggers.length === 0 && actions.length === 0 && (
            <StepsLabel style={{ marginTop: 4 }}>No triggers or actions registered.</StepsLabel>
          )}
          {triggers.length > 0 && (
            <>
              <StepsLabel>Triggers ({triggers.length})</StepsLabel>
              <StepChips>
                {triggers.map((t) => <StepChip key={t.id} $trigger>{t.name}</StepChip>)}
              </StepChips>
            </>
          )}
          {actions.length > 0 && (
            <>
              <StepsLabel>Actions ({actions.length})</StepsLabel>
              <StepChips>
                {actions.map((a) => <StepChip key={a.id}>{a.name}</StepChip>)}
              </StepChips>
            </>
          )}
        </div>
      )}
      cancelButton={{ label: 'Close', onClick: onClose }}
    />
  );
}

export default function SettingsScreen() {
  const qc = useQueryClient();
  const integrationsQuery = useQuery({ queryKey: ['integrations'], queryFn: api.integrations });
  const settingsQuery = useQuery({ queryKey: ['settings'], queryFn: api.settings });
  const saveSettings = useMutation({
    mutationFn: (data) => api.updateSettings(data),
    onSuccess: (data) => qc.setQueryData(['settings'], data)
  });
  const settings = settingsQuery.data || { notify_failures: true, notify_email: '' };
  // null while untouched; the field saves on blur so typing doesn't spam saves.
  const [notifyEmail, setNotifyEmail] = useState(null);
  const integrations = integrationsQuery.data || [];

  const [diagnosticsOpen, setDiagnosticsOpen] = useState(false);
  const [pendingReset, setPendingReset] = useState(false);
  const [maintenanceFeedback, setMaintenanceFeedback] = useState(null);
  const [detail, setDetail] = useState(null);
  const fileInputRef = useRef(null);

  const logs = useQuery({
    queryKey: ['logs'],
    queryFn: api.logs,
    enabled: diagnosticsOpen,
    refetchInterval: diagnosticsOpen ? 5000 : false
  });
  const clearLogs = useMutation({
    mutationFn: api.clearLogs,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['logs'] })
  });

  // Maintenance actions —————————————————————————————————————————————
  const exportSettings = useMutation({
    mutationFn: api.exportSettings,
    onSuccess: (payload) => {
      // Trigger a file download client-side. No server-side temp files needed.
      const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `meow-workflow-export-${new Date().toISOString().slice(0, 10)}.json`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      const count = (payload.flows || []).length;
      setMaintenanceFeedback({ kind: 'success', message: `Exported ${count} workflow${count === 1 ? '' : 's'} to JSON.` });
    },
    onError: (err) => setMaintenanceFeedback({ kind: 'error', message: err.message || 'Export failed.' })
  });

  const importSettings = useMutation({
    mutationFn: api.importSettings,
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['flows'] });
      const imported = result.imported || 0;
      const skipped = (result.skipped || []).length;
      setMaintenanceFeedback({
        kind: 'success',
        message: `Imported ${imported} workflow${imported === 1 ? '' : 's'}${skipped ? `, skipped ${skipped} malformed entries` : ''}. Imported workflows are paused — review and activate them.`
      });
    },
    onError: (err) => setMaintenanceFeedback({ kind: 'error', message: err.message || 'Import failed.' })
  });

  const resetSettings = useMutation({
    mutationFn: api.resetSettings,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['flows'] });
      qc.invalidateQueries({ queryKey: ['runs'] });
      qc.invalidateQueries({ queryKey: ['logs'] });
      setPendingReset(false);
      setMaintenanceFeedback({ kind: 'success', message: 'All workflows, runs, and logs have been deleted.' });
    },
    onError: (err) => {
      setPendingReset(false);
      setMaintenanceFeedback({ kind: 'error', message: err.message || 'Reset failed.' });
    }
  });

  const triggerImport = () => fileInputRef.current?.click();
  const handleFile = (e) => {
    const file = e.target.files?.[0];
    e.target.value = ''; // allow re-selecting same file
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      try {
        const data = JSON.parse(String(reader.result));
        importSettings.mutate(data);
      } catch (err) {
        setMaintenanceFeedback({ kind: 'error', message: 'That file isn\'t valid JSON.' });
      }
    };
    reader.readAsText(file);
  };

  // Each group plays a different role — that's the story, not a connector
  // catalog: foundation (always there), AI (one engine powers it), your own
  // functions (Code Engine = the extensibility point), and whatever installed
  // plugins bring along.
  const groups = useMemo(() => ({
    foundation: integrations.filter((it) => BUILT_IN_IDS.has(it.id)),
    ai: integrations.find((it) => it.id === 'ai-engine') || null,
    functions: integrations.find((it) => it.id === 'code-engine') || null,
    plugins: integrations.filter(
      (it) => !BUILT_IN_IDS.has(it.id) && it.id !== 'ai-engine' && it.id !== 'code-engine'
    )
  }), [integrations]);

  // One bento tile per known engine, with what it currently contributes. Code
  // Engine is counted by its functions (the fn_ actions), everything else by
  // total steps (triggers + actions), since that's the meaningful number.
  const tiles = useMemo(() => FEATURE_TILES.map((t) => {
    const it = integrations.find((i) => i.id === t.id);
    if (!it) return { ...t, on: false };
    const triggers = (it.triggers || []).filter((x) => x.hook && !x.hook.startsWith('__')).length;
    const actions = it.actions || [];
    const isCode = t.id === 'code-engine';
    const count = isCode ? actions.filter((a) => a.id.startsWith('fn_')).length : triggers + actions.length;
    const unit = isCode ? 'function' : 'step';
    return { ...t, on: true, count, unit };
  }), [integrations]);

  return (
    <>
      <NekoWrapper>
        {/* Left: the three places steps come from, numbered. */}
        <NekoColumn minimal>

          <NekoBlock className="primary" title={numberedTitle(1, 'Essentials')} subtitle="The steps every workflow builds on.">
            <BlockIntro>
              <b>Core</b> and <b>WordPress</b> are always here — logic, branching, posts, users, email.{' '}
              <b>AI Engine</b> adds text, image and JSON steps. Click a card to see what's inside.
            </BlockIntro>
            <Grid>
              {groups.foundation.map((it) => <IntegrationCard key={it.id} it={it} onOpen={setDetail} />)}
              {groups.ai && <IntegrationCard it={groups.ai} onOpen={setDetail} />}
            </Grid>
            {!groups.ai && (
              <MissingCard style={{ marginTop: 8 }}>
                <span><b>AI Engine</b> isn't installed — without it there are no AI steps and no Build with AI.</span>
              </MissingCard>
            )}
          </NekoBlock>

          <NekoBlock className="primary" title={numberedTitle(2, 'From Your Plugins')} subtitle="Steps the plugins you run bring along.">
            <BlockIntro>
              Plugins that support Meow Workflow add their steps here automatically, and remove them
              when deactivated. Nothing to set up.
            </BlockIntro>
            {groups.plugins.length > 0 ? (
              <Grid>
                {groups.plugins.map((it) => <IntegrationCard key={it.id} it={it} onOpen={setDetail} />)}
              </Grid>
            ) : (
              <MissingCard>
                <span>None detected. WooCommerce, SEO Engine and Social Engine add their steps automatically when active.</span>
              </MissingCard>
            )}
          </NekoBlock>

          <NekoBlock className="primary" title={numberedTitle(3, 'Your Functions')} subtitle="Anything else — written by you or AI."
            action={groups.functions && (
              <NekoButton
                className="secondary"
                onClick={() => { window.location.href = 'admin.php?page=mwcode_settings'; }}
              >
                Open Code Engine
              </NekoButton>
            )}
          >
            <BlockIntro>
              Write a function in <b>Code Engine</b> — or describe it to its AI ("create a WooCommerce
              coupon"). It appears in the step picker the moment it's active.
            </BlockIntro>
            {groups.functions ? (
              <Grid><IntegrationCard it={groups.functions} onOpen={setDetail} /></Grid>
            ) : (
              <MissingCard>
                <span><b>Code Engine</b> isn't installed — install it to turn your own functions into steps.</span>
              </MissingCard>
            )}
          </NekoBlock>

        </NekoColumn>

        {/* Right: the why, then developer + maintenance tooling. */}
        <NekoColumn minimal>

          <NekoBlock className="primary" title="How it works">
            <BlockIntro>
              Zapier, Make and n8n make you wait for someone to build each connector. Meow Workflow
              runs <b>inside WordPress</b>, so it already reaches your posts, users, and plugins. Need
              something we don't ship? Ask <b>Code Engine</b>'s AI to write the function — it becomes a
              step instantly. No catalog, no waiting.
            </BlockIntro>
            <InfoGrid>
              {tiles.map((t) => (
                <Tile key={t.id} $on={t.on} $color={t.color} $grad={t.grad}>
                  <TileIcon><StepGlyph icon={t.icon} size={19} color={t.on ? '#fff' : '#cbd5e1'} /></TileIcon>
                  <div>
                    <TileName>{t.label}</TileName>
                    <TileStat $on={t.on}>
                      {t.on ? `${t.count} ${t.unit}${t.count === 1 ? '' : 's'}` : 'Not installed'}
                    </TileStat>
                  </div>
                </Tile>
              ))}
            </InfoGrid>
          </NekoBlock>

          <NekoSpacer />

          <NekoBlock className="primary" title="For Developers">
            <MaintenanceHelp style={{ marginBottom: 14 }}>
              Add your own actions and triggers with the <strong>mwflow_register_integration</strong> filter — see <strong>classes/integrations/</strong> for examples.
            </MaintenanceHelp>

            <DiagnosticsHeader type="button" onClick={() => setDiagnosticsOpen((v) => !v)}>
              {diagnosticsOpen ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
              Diagnostics log
              <span style={{ color: '#94a3b8', fontWeight: 400, marginLeft: 6 }}>
                (mwflow.log, last entries)
              </span>
            </DiagnosticsHeader>
            {diagnosticsOpen && (
              <>
                <LogPre>{logs.data?.log || '(empty)'}</LogPre>
                <div style={{ marginTop: 10 }}>
                  <NekoButton className="secondary" icon="trash" onClick={() => clearLogs.mutate()}>
                    Clear log
                  </NekoButton>
                </div>
              </>
            )}
          </NekoBlock>

          <NekoBlock className="primary" title="Notifications">
            <NekoCheckbox
              label="Email me when a live workflow fails"
              description="At most one email per workflow per hour, with the failed step, the error and a link to the run. Test runs never send one."
              checked={!!settings.notify_failures}
              disabled={settingsQuery.isLoading}
              onChange={(v) => saveSettings.mutate({ notify_failures: !!v })}
            />
            <NotifyEmailRow>
              <NekoInput
                value={notifyEmail ?? settings.notify_email ?? ''}
                placeholder="Site admin email"
                onChange={(v) => setNotifyEmail(v)}
                onBlur={() => {
                  if (notifyEmail === null) return;
                  saveSettings.mutate({ notify_email: notifyEmail });
                  setNotifyEmail(null);
                }}
              />
              <NotifyHint>Where to send them. Leave empty to use the site admin email.</NotifyHint>
            </NotifyEmailRow>
          </NekoBlock>

          <NekoBlock className="primary" title="Maintenance">
        <MaintenanceHelp>
          <strong>Export</strong> downloads every workflow as JSON; <strong>Import</strong> restores them, paused, so you can review first; <strong>Reset</strong> deletes every workflow, run, and log.
        </MaintenanceHelp>
        {maintenanceFeedback && (
          <NekoMessage
            variant={maintenanceFeedback.kind === 'error' ? 'danger' : 'success'}
            style={{ marginTop: 12 }}
          >
            {maintenanceFeedback.message}
          </NekoMessage>
        )}
        <ButtonRow>
          <NekoButton
            className="primary"
            icon="download"
            onClick={() => { setMaintenanceFeedback(null); exportSettings.mutate(); }}
            isBusy={exportSettings.isPending}
          >
            Export Settings
          </NekoButton>
          <NekoButton
            className="secondary"
            icon="file-upload"
            onClick={() => { setMaintenanceFeedback(null); triggerImport(); }}
            isBusy={importSettings.isPending}
          >
            Import Settings
          </NekoButton>
          <NekoButton
            className="secondary"
            icon="sparkles"
            onClick={() => {
              resetSetupAssistant();
              setMaintenanceFeedback({ kind: 'success', message: 'The Setup Assistant is back on the Workflows tab.' });
            }}
          >
            Show Setup Assistant
          </NekoButton>
        </ButtonRow>
        <DangerRow>
          <NekoButton
            className="danger"
            icon="trash"
            onClick={() => { setMaintenanceFeedback(null); setPendingReset(true); }}
          >
            Reset Settings
          </NekoButton>
        </DangerRow>
        <input
          ref={fileInputRef}
          type="file"
          accept="application/json,.json"
          onChange={handleFile}
          style={{ display: 'none' }}
        />
      </NekoBlock>

        </NekoColumn>
      </NekoWrapper>

      <IntegrationModal it={detail} onClose={() => setDetail(null)} />

      {pendingReset && (
        <NekoModal
          isOpen
          title="Reset everything?"
          content="This will permanently delete every workflow, every run record, and the diagnostics log on this site. There is no undo."
          okButton={{
            label: 'Yes, delete everything',
            className: 'danger',
            onClick: () => resetSettings.mutate()
          }}
          cancelButton={{ label: 'Cancel', onClick: () => setPendingReset(false) }}
        />
      )}
    </>
  );
}
