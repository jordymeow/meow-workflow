import { useMemo, useState } from 'react';
import styled from 'styled-components';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { NekoSelect, NekoOption, NekoInput, NekoMessage, NekoButton } from '@neko-ui';
import { Zap, Clock, Webhook, Anchor, Radio, Rss } from 'lucide-react';
import { api } from '../helpers/api';
import { PanelHead, PanelBody, Field, Callout, RefBlock, RefBlockLabel, RefRow, RefCode } from './panelKit';

// Sentinel value for the "advanced: type a raw hook" option in the event picker.
const CUSTOM_HOOK = '__custom__';

const IconBubble = styled.span`
  width: 32px;
  height: 32px;
  border-radius: 9px;
  background: #fff7ed;
  color: #ea580c;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: inset 0 0 0 1px #fed7aa;
`;

const CopyRow = styled.div`
  display: flex;
  gap: 6px;
  align-items: stretch;
`;

const CodeInput = styled.input`
  flex: 1;
  padding: 8px 10px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11.5px;
  /* WP admin styles input[readonly] with a light grey background, which beats a
     single class on specificity and left the URL light-on-light (invisible). */
  &, &[readonly] {
    background: #0f172a;
    color: #e2e8f0;
  }
  &:focus { outline: none; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.3); }
`;

const HintList = styled.ul`
  margin: 0;
  padding-left: 16px;
  font-size: 11.5px;
  color: #94a3b8;
  line-height: 1.55;
  li { margin-bottom: 2px; }
  code { background: #0f172a; color: #cbd5e1; padding: 1px 5px; border-radius: 4px; font-size: 11px; }
`;

const SamplePanel = styled.div`
  background: ${(p) => (p.$listening ? '#eff6ff' : '#f8fafc')};
  border: 1px solid ${(p) => (p.$listening ? '#bfdbfe' : '#eef2f7')};
  border-radius: 10px;
  padding: 13px 14px;
`;

const SampleHeader = styled.div`
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12.5px;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 8px;
`;

const SampleStatus = styled.span`
  font-size: 11px;
  color: ${(p) => (p.$listening ? '#1d4ed8' : '#94a3b8')};
  font-weight: 600;
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 6px;
  ${(p) => p.$listening && `
    &::before {
      content: '';
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #3b82f6;
      animation: mwflow-listening 1.1s infinite;
    }
    @keyframes mwflow-listening {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.4; transform: scale(1.4); }
    }
  `}
`;

const SampleHint = styled.div`
  font-size: 11.5px;
  color: #64748b;
  line-height: 1.5;
  margin-bottom: 10px;
`;

const SamplePre = styled.pre`
  margin: 0 0 10px;
  background: #0f172a;
  color: #cbd5e1;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 11px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  max-height: 160px;
  overflow: auto;
  white-space: pre-wrap;
`;

const ICONS = { manual: Zap, schedule: Clock, webhook: Webhook, hook: Anchor, rss: Rss };

// Outputs every RSS run exposes — mirrors the trigger metadata in core.php.
const RSS_OUTPUTS = [
  { id: 'title', name: 'Item title' },
  { id: 'link', name: 'Item URL' },
  { id: 'content', name: 'Item content (plain text)' },
  { id: 'date', name: 'Published date' },
  { id: 'author', name: 'Author' }
];

const RECURRENCE_LABEL = {
  hourly: 'every hour',
  twicedaily: 'twice a day',
  daily: 'every day',
  weekly: 'once a week'
};

function summarise(type, config, isActive) {
  if (type === 'manual') {
    return 'Runs only when you press "Test once". Useful while you build the flow.';
  }
  if (type === 'schedule') {
    let when = RECURRENCE_LABEL[config.recurrence || 'daily'] || 'on a schedule';
    if ((config.recurrence || 'daily') === 'weekly') {
      const day = config.day || 'monday';
      when = `every ${day.charAt(0).toUpperCase()}${day.slice(1)}`;
    }
    const at = config.time || '09:00';
    return `${isActive ? 'Will run' : 'Would run, once activated,'} ${when} at ${at} in your site's timezone.`;
  }
  if (type === 'webhook') {
    if (!isActive) return 'Activate the flow to start accepting webhook calls.';
    return 'Any HTTP POST to the URL below fires this flow. Each field of the JSON body is available as {{ trigger.field }}, e.g. {{ trigger.url }}.';
  }
  if (type === 'hook') {
    if (config.eventName) return `Runs ${config.eventName.toLowerCase()}.`;
    if (!config.hook) return 'Choose which WordPress event should start this workflow.';
    return `Fires every time WordPress runs the "${config.hook}" action.`;
  }
  if (type === 'rss') {
    if (!config.feed_url) return 'Paste a feed URL — the flow will run once for every new item that appears.';
    const every = { hourly: 'hour', twicedaily: '12 hours', daily: 'day' }[config.recurrence || 'hourly'] || 'hour';
    return `${isActive ? 'Checks' : 'Once active, checks'} the feed every ${every} and runs once per new item. Existing items don't fire.`;
  }
  return '';
}

function CopyableCode({ value, label }) {
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      setTimeout(() => setCopied(false), 1400);
    } catch (_) { /* silent */ }
  };
  return (
    <CopyRow>
      <CodeInput value={value} readOnly aria-label={label} onClick={(e) => e.target.select()} />
      <NekoButton className="secondary" rounded icon={copied ? 'check' : 'copy'} onClick={copy} title={copied ? 'Copied' : 'Copy'} />
    </CopyRow>
  );
}

export default function TriggerConfigPanel({
  triggerType, triggerConfig, isActive, onChange, onClose,
  flowId, triggerSample, captureSample
}) {
  const setConfig = (key, value) => onChange(triggerType, { ...triggerConfig, [key]: value });
  const Icon = ICONS[triggerType] || Zap;
  const restBase = window.mwflow?.restUrl || '';
  const webhookUrl = triggerConfig.token ? `${restBase}/hook/${triggerConfig.token}` : '';

  const qc = useQueryClient();
  const toggleCapture = useMutation({
    mutationFn: (enabled) => api.toggleCaptureSample(flowId, enabled),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['flow', flowId] })
  });
  const canCapture = (triggerType === 'webhook' || triggerType === 'hook' || triggerType === 'rss') && !!flowId;
  // First field of the captured payload, shown as a ready-to-copy example.
  const sampleKeys = triggerSample && typeof triggerSample === 'object' && !Array.isArray(triggerSample) ? Object.keys(triggerSample) : [];
  const sampleExample = sampleKeys.length ? `{{ trigger.${sampleKeys[0]} }}` : '';

  // Friendly event list, sourced from every integration that registers real
  // hook triggers (WordPress, WooCommerce, third parties). Core's internal
  // pseudo-hooks (__mwflow_*__) are skipped. Selecting one sets the hook +
  // args behind the scenes so beginners never type a raw hook name.
  const integrations = useQuery({ queryKey: ['integrations'], queryFn: api.integrations });
  const wpEvents = useMemo(() => {
    const out = [];
    for (const integration of (integrations.data || [])) {
      for (const t of (integration.triggers || [])) {
        if (!t.hook || t.hook.startsWith('__')) continue;
        out.push({ id: t.id, name: t.name, hook: t.hook, args: t.args || 1, outputs: t.outputs || [] });
      }
    }
    return out;
  }, [integrations.data]);

  // The flow stores trigger_config.event when a known event is chosen.
  // Fall back to matching by hook for flows created before `event` existed.
  const selectedEvent = useMemo(() => {
    if (triggerConfig.event) return triggerConfig.event;
    const match = wpEvents.find((e) => e.hook === triggerConfig.hook);
    return match ? match.id : (triggerConfig.hook ? CUSTOM_HOOK : '');
  }, [triggerConfig.event, triggerConfig.hook, wpEvents]);

  const pickEvent = (eventId) => {
    if (eventId === CUSTOM_HOOK) {
      onChange('hook', { event: CUSTOM_HOOK, hook: triggerConfig.hook || '', priority: triggerConfig.priority || 10, args: triggerConfig.args || 1 });
      return;
    }
    const ev = wpEvents.find((e) => e.id === eventId);
    if (!ev) return;
    onChange('hook', { event: ev.id, hook: ev.hook, priority: 10, args: ev.args });
  };

  const currentEventOutputs = useMemo(() => {
    const ev = wpEvents.find((e) => e.id === selectedEvent);
    return ev ? ev.outputs : [];
  }, [selectedEvent, wpEvents]);

  return (
    <>
      <PanelHead
        icon={<IconBubble><Icon size={17} /></IconBubble>}
        title="Flow trigger"
        subtitle="How this workflow starts"
        onClose={onClose}
      />
      <PanelBody>
        <Callout>{summarise(triggerType, {
          ...triggerConfig,
          eventName: wpEvents.find((e) => e.id === selectedEvent)?.name
        }, isActive)}</Callout>

        <Field label="Trigger type">
          <NekoSelect scrolldown value={triggerType} onChange={(v) => onChange(v, {})} name="trigger_type">
            <NekoOption value="manual" label="Manual / Test once" />
            <NekoOption value="schedule" label="On a schedule" />
            <NekoOption value="webhook" label="Webhook (HTTP)" />
            <NekoOption value="hook" label="WordPress event" />
            <NekoOption value="rss" label="New RSS item" />
          </NekoSelect>
        </Field>

        {triggerType === 'rss' && (
          <>
            <Field label="Feed URL" hint="RSS or Atom. The flow runs once per new item — items already in the feed when you activate don't fire.">
              <NekoInput
                placeholder="https://example.com/feed"
                value={triggerConfig.feed_url || ''}
                onChange={(v) => setConfig('feed_url', v)}
              />
            </Field>
            <Field label="Check for new items">
              <NekoSelect scrolldown value={triggerConfig.recurrence || 'hourly'} name="rss_recurrence" onChange={(v) => setConfig('recurrence', v)}>
                <NekoOption value="hourly" label="Every hour" />
                <NekoOption value="twicedaily" label="Twice a day" />
                <NekoOption value="daily" label="Once a day" />
              </NekoSelect>
            </Field>
            <RefBlock>
              <RefBlockLabel>Available in later steps</RefBlockLabel>
              {RSS_OUTPUTS.map((o) => (
                <RefRow key={o.id}>
                  <RefCode>{`{{ trigger.${o.id} }}`}</RefCode>
                  <span>{o.name}</span>
                </RefRow>
              ))}
            </RefBlock>
          </>
        )}

        {triggerType === 'schedule' && (
          <>
            <Field label="Recurrence">
              <NekoSelect scrolldown value={triggerConfig.recurrence || 'daily'} name="recurrence" onChange={(v) => setConfig('recurrence', v)}>
                <NekoOption value="hourly" label="Every hour" />
                <NekoOption value="twicedaily" label="Twice daily" />
                <NekoOption value="daily" label="Every day" />
                <NekoOption value="weekly" label="Once a week" />
              </NekoSelect>
            </Field>
            {(triggerConfig.recurrence || 'daily') === 'weekly' && (
              <Field label="Day of the week">
                <NekoSelect scrolldown value={triggerConfig.day || 'monday'} name="schedule_day" onChange={(v) => setConfig('day', v)}>
                  <NekoOption value="monday" label="Monday" />
                  <NekoOption value="tuesday" label="Tuesday" />
                  <NekoOption value="wednesday" label="Wednesday" />
                  <NekoOption value="thursday" label="Thursday" />
                  <NekoOption value="friday" label="Friday" />
                  <NekoOption value="saturday" label="Saturday" />
                  <NekoOption value="sunday" label="Sunday" />
                </NekoSelect>
              </Field>
            )}
            <Field label="Time of day" hint="In your site's timezone.">
              <NekoInput type="time" value={triggerConfig.time || '09:00'} onChange={(v) => setConfig('time', v)} />
            </Field>
          </>
        )}

        {triggerType === 'webhook' && (
          webhookUrl ? (
            <Field label="Webhook URL" hint="POST JSON here to fire the flow. Each body field is available as {{ trigger.field }}, e.g. {{ trigger.url }}. Keep it secret: it contains a unique token.">
              <CopyableCode value={webhookUrl} label="Webhook URL" />
            </Field>
          ) : (
            <NekoMessage variant="info">
              {isActive
                ? 'Generating your webhook URL… it appears here once this change auto-saves (a second or two).'
                : 'To get a webhook URL: 1) flip the switch to Active in the top bar. The flow auto-saves and the URL appears here.'}
            </NekoMessage>
          )
        )}

        {triggerType === 'hook' && (
          <>
            <Field label="WordPress event">
              <NekoSelect scrolldown value={selectedEvent} onChange={pickEvent} name="wp_event">
                <NekoOption value="" label="— Choose an event —" />
                {wpEvents.map((e) => (
                  <NekoOption key={e.id} value={e.id} label={e.name} />
                ))}
                <NekoOption value={CUSTOM_HOOK} label="Advanced: custom hook…" />
              </NekoSelect>
            </Field>

            {selectedEvent && selectedEvent !== CUSTOM_HOOK && currentEventOutputs.length > 0 && (
              <RefBlock>
                <RefBlockLabel>Available in later steps</RefBlockLabel>
                {currentEventOutputs.map((o) => (
                  <RefRow key={o.id}>
                    <RefCode>{`{{ trigger.${o.id} }}`}</RefCode>
                    <span>{o.name}</span>
                  </RefRow>
                ))}
              </RefBlock>
            )}

            {selectedEvent === CUSTOM_HOOK && (
              <>
                <Field label="Hook name">
                  <NekoInput placeholder="e.g. woocommerce_order_status_completed" value={triggerConfig.hook || ''} onChange={(v) => setConfig('hook', v)} />
                </Field>
                <Field label="Priority">
                  <NekoInput type="number" value={triggerConfig.priority || 10} onChange={(v) => setConfig('priority', Number(v))} />
                </Field>
                <Field label="Number of arguments">
                  <NekoInput type="number" value={triggerConfig.args || 1} onChange={(v) => setConfig('args', Number(v))} />
                </Field>
                <HintList>
                  <li>Hook arguments are exposed as <code>{'{{ trigger.arg0 }}'}</code>, <code>{'{{ trigger.arg1 }}'}</code>, …</li>
                  <li><code>{'{{ trigger.value }}'}</code> aliases <code>arg0</code> for the common case.</li>
                </HintList>
              </>
            )}
          </>
        )}

        {canCapture && (
          <SamplePanel $listening={captureSample}>
            <SampleHeader>
              <Radio size={13} color={captureSample ? '#1d4ed8' : '#64748b'} />
              Sample payload
              <SampleStatus $listening={captureSample}>
                {captureSample ? 'Listening' : (triggerSample ? 'Captured' : 'None')}
              </SampleStatus>
            </SampleHeader>
            <SampleHint>
              {captureSample
                ? 'Send one real call to your trigger now. We\'ll store the payload here and Test once will use it. That call will not fire the flow.'
                : (triggerSample
                  ? `The last captured payload. Each field is available as {{ trigger.field }}${sampleExample ? `, e.g. ${sampleExample}` : ''}, and Test once runs against it.`
                  : 'Capture one real call to your trigger so Test once can replay it against your flow.')}
            </SampleHint>
            {triggerSample && !captureSample && (
              <SamplePre>{JSON.stringify(triggerSample, null, 2)}</SamplePre>
            )}
            <NekoButton
              className={captureSample ? 'danger' : 'secondary'}
              onClick={() => toggleCapture.mutate(!captureSample)}
              isBusy={toggleCapture.isPending}
            >
              {captureSample ? 'Stop listening' : (triggerSample ? 'Re-capture' : 'Capture next call')}
            </NekoButton>
          </SamplePanel>
        )}
      </PanelBody>
    </>
  );
}
