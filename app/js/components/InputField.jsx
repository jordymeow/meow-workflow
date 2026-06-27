import { useMemo } from 'react';
import { NekoInput, NekoTextArea, NekoSelect, NekoOption, NekoCheckbox } from '@neko-ui';
import ConditionsField from './ConditionsField';
import BranchesField from './BranchesField';
import DataPicker from './DataPicker';
import RefChips from './RefChips';
import { Field, RequiredMark } from '../editor/panelKit';

// Field types that accept {{ expressions }} and so get the "Insert data" helper.
const EXPRESSION_TYPES = new Set([
  'string', 'longtext', 'url', 'email', 'expression', 'json',
  'post_id', 'user_id', 'attachment_id'
]);

/**
 * Pull dropdown values from `window.mwflow` for "smart" sources like AI Engine
 * models / envs. Returns `null` if the source isn't recognised (caller falls
 * back to plain text input then).
 */
function resolveOptionsSource(source) {
  if (!source) return null;
  const mw = window.mwflow || {};
  if (source === 'ai.models') {
    const models = mw.aiEngine?.models || [];
    if (!models.length) return null;
    return models.map((m) => ({ value: m.id, label: m.name || m.id }));
  }
  if (source === 'ai.envs') {
    const envs = mw.aiEngine?.envs || [];
    if (!envs.length) return null;
    return envs.map((e) => ({ value: e.id, label: e.name || e.id }));
  }
  if (source === 'code.snippets') {
    const snippets = mw.codeEngine?.snippets || [];
    if (!snippets.length) return null;
    return snippets.map((s) => ({ value: s.id, label: s.name }));
  }
  if (source === 'code.functions') {
    // Only function-scope snippets are callable by name.
    const fns = (mw.codeEngine?.snippets || []).filter((s) => s.functionName);
    if (!fns.length) return null;
    return fns.map((s) => ({ value: s.functionName, label: s.name }));
  }
  return null;
}

/**
 * Renders one input widget based on field.type, always as a full-width control
 * beneath its label (via the shared `Field`) so every row in the inspector and
 * the trigger panel lines up the same way.
 *
 * Adding a new type means adding a case here AND in `Meow_MWFLOW_Runner::coerce()`.
 *
 * Fields with `options_source` render as a dropdown sourced from `window.mwflow`
 * (e.g. AI Engine models). When the source is empty (engine not active), the
 * field falls back to a plain text input so it's always editable.
 */
export default function InputField({ field, value, onChange, tokens, refContext }) {
  const sourced = useMemo(() => resolveOptionsSource(field.options_source), [field.options_source]);

  // Append a {{ token }} to the field. Empty field → just the token; otherwise
  // append with a single separating space so it reads cleanly.
  const insertToken = (token) => {
    const cur = value == null ? '' : String(value);
    if (!cur) onChange(token);
    else onChange(/\s$/.test(cur) ? cur + token : `${cur} ${token}`);
  };
  const showPicker = !sourced && EXPRESSION_TYPES.has(field.type) && tokens && tokens.length > 0;
  const isEmpty = value === '' || value === undefined || value === null;
  const requiredWarning = field.required && field.type !== 'boolean' && isEmpty
    ? 'Required — fill this in before the workflow can run.'
    : null;

  let widget;
  if (sourced) {
    widget = (
      <NekoSelect scrolldown value={value ?? ''} onChange={(v) => onChange(v)} name={field.id}>
        <NekoOption value="" label={field.empty_label || '— Use default —'} />
        {sourced.map((opt) => (
          <NekoOption key={opt.value} value={opt.value} label={opt.label} />
        ))}
      </NekoSelect>
    );
  }
  else {
    switch (field.type) {
      case 'longtext':
        widget = (
          <NekoTextArea value={value || ''} placeholder={field.placeholder} onChange={(v) => onChange(v)} rows={3} />
        );
        break;
      case 'number':
        widget = (
          <NekoInput
            type="number"
            value={value === '' || value === undefined || value === null ? '' : String(value)}
            min={field.min}
            max={field.max}
            step={field.step || 1}
            onChange={(v) => onChange(v === '' ? '' : Number(v))}
          />
        );
        break;
      case 'boolean':
        // Checkbox carries its own inline label, so it gets no separate Field
        // label — just an optional hint below when the description adds detail.
        return (
          <Field hint={field.description && field.description !== field.name ? field.description : null}>
            <NekoCheckbox checked={!!value} label={field.name} onChange={(checked) => onChange(checked)} />
          </Field>
        );
      case 'select': {
        const options = (field.options || []).map((opt) =>
          typeof opt === 'object' ? opt : { value: opt, label: String(opt) }
        );
        widget = (
          <NekoSelect scrolldown value={value ?? ''} onChange={(v) => onChange(v)} name={field.id}>
            {!field.required && <NekoOption value="" label={field.empty_label || '— Use default —'} />}
            {options.map((opt) => (
              <NekoOption key={opt.value} value={opt.value} label={opt.label} />
            ))}
          </NekoSelect>
        );
        break;
      }
      case 'json':
        widget = (
          <NekoTextArea
            value={typeof value === 'string' ? value : (value ? JSON.stringify(value, null, 2) : '')}
            placeholder={field.placeholder || '{\n  "key": "value"\n}'}
            onChange={(v) => onChange(v)}
            rows={3}
          />
        );
        break;
      case 'expression':
        widget = (
          <NekoInput value={value ?? ''} placeholder={field.placeholder || '{{ get_post1.title }}'} onChange={(v) => onChange(v)} />
        );
        break;
      case 'conditions':
        widget = <ConditionsField value={value} onChange={onChange} />;
        break;
      case 'branches':
        widget = <BranchesField value={value} onChange={onChange} />;
        break;
      case 'post_id':
      case 'user_id':
      case 'attachment_id':
        widget = (
          <NekoInput value={value ?? ''} placeholder={field.placeholder || 'Numeric ID or {{ node.field }}'} onChange={(v) => onChange(v)} />
        );
        break;
      case 'url':
      case 'email':
      case 'string':
      default:
        widget = (
          <NekoInput value={value ?? ''} placeholder={field.placeholder} onChange={(v) => onChange(v)} />
        );
    }
  }

  // Every {{ reference }} in the field becomes a colored chip below it —
  // click to jump to the referenced step; red when the step no longer exists.
  const showChips = refContext && EXPRESSION_TYPES.has(field.type)
    && typeof value === 'string' && value.includes('{{');

  return (
    <Field
      label={<>{field.name}{field.required && <RequiredMark> *</RequiredMark>}</>}
      labelRight={showPicker ? <DataPicker groups={tokens} onInsert={insertToken} /> : null}
      hint={field.description}
      warning={requiredWarning}
    >
      {widget}
      {showChips && (
        <RefChips
          value={value}
          nodes={refContext.nodes}
          integrations={refContext.integrations}
          onSelectNode={refContext.onSelectNode}
          stepsByNode={refContext.stepsByNode}
        />
      )}
    </Field>
  );
}
