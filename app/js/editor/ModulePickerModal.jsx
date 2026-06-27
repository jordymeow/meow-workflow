import { useEffect, useMemo, useRef, useState } from 'react';
import styled from 'styled-components';
import { NekoModal, NekoButton } from '@neko-ui';
import { Search, Star } from 'lucide-react';
import { StepIcon, prettyName } from './stepVisual';

const Head = styled.div`
  margin-bottom: 14px;
`;

const Title = styled.p`
  font-family: var(--neko-font-family);
  font-weight: 800;
  font-size: 19px;
  line-height: 24px;
  margin: 0;
  color: #0f172a;
`;

const Subtitle = styled.p`
  margin: 3px 0 0;
  font-size: 12.5px;
  color: #64748b;
`;

const SearchWrap = styled.div`
  display: flex;
  align-items: center;
  gap: 9px;
  border: 1px solid #d6dbe3;
  border-radius: 10px;
  padding: 0 12px;
  height: 42px;
  background: #fff;
  margin-bottom: 12px;
  transition: border-color 0.12s, box-shadow 0.12s;
  &:focus-within {
    border-color: var(--neko-blue, hsl(217 80% 42%));
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
  }
  input {
    flex: 1;
    font-size: 14px;
    color: #0f172a;
    &::placeholder { color: #94a3b8; }
    /* The wrapper draws the focus ring (focus-within above). !important so
       neither wp-admin's input styles nor NekoUI's global :focus-visible ring
       can draw a second border inside it, whatever their injection order. */
    &, &:focus, &:focus-visible {
      border: 0 !important;
      outline: none !important;
      box-shadow: none !important;
      background: transparent !important;
    }
  }
`;

const Chips = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 8px;
`;

const Chip = styled.button`
  border: 1px solid ${(p) => (p.$active ? 'transparent' : '#e2e8f0')};
  background: ${(p) => (p.$active ? 'var(--neko-blue, hsl(217 80% 42%))' : '#fff')};
  color: ${(p) => (p.$active ? '#fff' : '#475569')};
  font-size: 12px;
  font-weight: 600;
  padding: 5px 12px;
  border-radius: 999px;
  cursor: pointer;
  transition: background 0.12s, color 0.12s, border-color 0.12s;
  &:hover { border-color: ${(p) => (p.$active ? 'transparent' : '#c7d2fe')}; }
`;

const GroupLabel = styled.div`
  font-size: 11px;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin: 26px 4px 8px;
  display: flex;
  align-items: center;
  gap: 8px;
`;

const Body = styled.div`
  max-height: 56vh;
  overflow: auto;
  margin: 0 -4px;
  padding: 4px;
  /* Only the very first section label sits flush with the top. A plain
     :first-child on the label itself matched EVERY label (each one is the
     first child of its own group wrapper), killing the breathing room
     between sections. */
  > ${GroupLabel}:first-child,
  > div:first-child > ${GroupLabel} { margin-top: 2px; }
`;

const Grid = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 8px;
`;

const Item = styled.div`
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 11px;
  padding: 11px 36px 11px 12px;
  border: 1px solid #e8eaef;
  border-radius: 10px;
  cursor: pointer;
  background: white;
  transition: border-color 0.12s, box-shadow 0.12s, transform 0.12s;
  &:hover {
    border-color: #bfdbfe;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.07);
    transform: translateY(-1px);
  }
`;

const ItemBody = styled.div`
  min-width: 0;
`;

const ItemName = styled.div`
  font-size: 13px;
  font-weight: 650;
  color: #0f172a;
`;

const ItemDesc = styled.div`
  font-size: 11.5px;
  color: #94a3b8;
  margin-top: 2px;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
`;

const StarBtn = styled.button`
  position: absolute;
  top: 9px;
  right: 9px;
  width: 24px;
  height: 24px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 7px;
  background: transparent;
  cursor: pointer;
  color: ${(p) => (p.$on ? '#f59e0b' : '#cbd5e1')};
  &:hover { background: #f1f5f9; color: ${(p) => (p.$on ? '#d97706' : '#f59e0b')}; }
`;

const Footer = styled.div`
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  background: #f0f0f0;
  padding: 10px;
  margin: 15px -15px -15px -15px;
`;

const Empty = styled.div`
  padding: 30px;
  text-align: center;
  color: #94a3b8;
  font-size: 13px;
`;

// Core actions that are "logic / flow control" rather than integration calls.
const LOGIC_IDS = new Set(['condition', 'router', 'foreach', 'random', 'delay', 'set_var', 'log', 'send_email', 'http_request']);

export default function ModulePickerModal({
  isOpen, onClose, integrations, onPick, favorites, onToggleFavorite
}) {
  const [query, setQuery] = useState('');
  const [category, setCategory] = useState('all');
  const searchRef = useRef(null);
  const q = query.trim().toLowerCase();

  // Reset + focus the search whenever the modal opens.
  useEffect(() => {
    if (!isOpen) return;
    setQuery('');
    setCategory('all');
    const t = setTimeout(() => searchRef.current?.focus(), 60);
    return () => clearTimeout(t);
  }, [isOpen]);

  // Close on Escape. Capture phase + stopPropagation so it fires before (and
  // instead of) the editor's own Escape handler, regardless of focus.
  useEffect(() => {
    if (!isOpen) return;
    const onKey = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); onClose(); }
    };
    window.addEventListener('keydown', onKey, true);
    return () => window.removeEventListener('keydown', onKey, true);
  }, [isOpen, onClose]);

  // Build display groups: Logic first (from core), then each integration's
  // remaining actions.
  const groups = useMemo(() => {
    const out = [];
    const matches = (name) => !q || name.toLowerCase().includes(q);

    const core = integrations.find((i) => i.id === 'core');
    if (core) {
      const logic = (core.actions || [])
        .filter((a) => LOGIC_IDS.has(a.id) && matches(a.name))
        .map((a) => toSpec(core, a));
      if (logic.length) out.push({ key: 'logic', label: 'Logic & Flow', items: logic });
    }

    for (const it of integrations) {
      const items = (it.actions || [])
        .filter((a) => !(it.id === 'core' && LOGIC_IDS.has(a.id)))
        .filter((a) => matches(a.name) || matches(it.name))
        .map((a) => toSpec(it, a));
      if (items.length) out.push({ key: it.id, label: it.name, items });
    }
    return out;
  }, [integrations, q]);

  // Category chips, derived from the available groups.
  const chips = useMemo(
    () => [{ key: 'all', label: 'All' }, ...groups.map((g) => ({ key: g.key, label: g.label }))],
    [groups]
  );

  const visibleGroups = category === 'all' ? groups : groups.filter((g) => g.key === category);

  const favoriteSpecs = useMemo(() => {
    const byKey = {};
    for (const it of integrations) {
      for (const a of (it.actions || [])) byKey[`${it.id}:${a.id}`] = toSpec(it, a);
    }
    return [...favorites].map((k) => byKey[k]).filter(Boolean)
      .filter((s) => !q || s.name.toLowerCase().includes(q));
  }, [favorites, integrations, q]);

  const showFavorites = category === 'all' && favoriteSpecs.length > 0;

  const renderItem = (spec) => {
    const key = `${spec.integration}:${spec.id}`;
    const isFav = favorites.has(key);
    return (
      <Item key={key} onClick={() => { onPick(spec); onClose(); }}>
        <StepIcon icon={spec.icon} color={spec.color} size={28} />
        <ItemBody>
          <ItemName>{prettyName(spec.name)}</ItemName>
          {spec.description && <ItemDesc>{spec.description}</ItemDesc>}
        </ItemBody>
        <StarBtn
          $on={isFav}
          title={isFav ? 'Remove from toolbar favorites' : 'Add to toolbar favorites'}
          onClick={(e) => { e.stopPropagation(); onToggleFavorite(key); }}
        >
          <Star size={14} fill={isFav ? 'currentColor' : 'none'} />
        </StarBtn>
      </Item>
    );
  };

  return (
    <NekoModal isOpen={isOpen} size="larger" onRequestClose={onClose} shouldCloseOnOverlayClick>
      <Head>
        <Title>Add a step</Title>
        <Subtitle>Pick what happens next. Star a step to pin it to the toolbar.</Subtitle>
      </Head>

      <SearchWrap>
        <Search size={16} color="#94a3b8" />
        <input
          ref={searchRef}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search steps…"
        />
      </SearchWrap>

      {chips.length > 2 && (
        <Chips>
          {chips.map((c) => (
            <Chip key={c.key} $active={category === c.key} onClick={() => setCategory(c.key)}>
              {c.label}
            </Chip>
          ))}
        </Chips>
      )}

      <Body>
        {showFavorites && (
          <>
            <GroupLabel><Star size={12} /> Favorites</GroupLabel>
            <Grid>{favoriteSpecs.map(renderItem)}</Grid>
          </>
        )}
        {visibleGroups.map((g) => (
          <div key={g.key}>
            <GroupLabel>{g.label}</GroupLabel>
            <Grid>{g.items.map(renderItem)}</Grid>
          </div>
        ))}
        {visibleGroups.length === 0 && !showFavorites && (
          <Empty>No steps match “{query}”.</Empty>
        )}
      </Body>

      <Footer>
        <NekoButton className="primary" onClick={onClose}>Done</NekoButton>
      </Footer>
    </NekoModal>
  );
}

function toSpec(integration, action) {
  return {
    kind: 'action',
    id: action.id,
    integration: integration.id,
    name: action.name,
    description: action.description || '',
    color: integration.color,
    logo_url: integration.logo_url,
    icon: action.icon
  };
}
