import { useState, useRef, useEffect, useCallback, useMemo } from 'react';
import { createPortal } from 'react-dom';
import styled from 'styled-components';
import { Braces, Search } from 'lucide-react';

/**
 * Small "insert data" control shown next to a field's label. Opens a popover
 * listing the {{ references }} a beginner can drop into the field — site/date
 * globals, the trigger's outputs, and every earlier step's outputs — so nobody
 * has to memorise or hand-type expression syntax.
 *
 * The menu renders in a portal with fixed positioning so it's never clipped by
 * the inspector's scroll/rounded containers.
 *
 * `groups`: [{ label, items: [{ token, name }] }]
 */
const Wrap = styled.span`
  position: relative;
  display: inline-flex;
`;

const Btn = styled.button`
  display: inline-flex;
  align-items: center;
  gap: 4px;
  height: 22px;
  padding: 0 8px;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #fff;
  color: #475569;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.12s, border-color 0.12s, color 0.12s;
  &:hover { background: #f5f3ff; border-color: #c7d2fe; color: #4f46e5; }
`;

const Menu = styled.div`
  position: fixed;
  z-index: 99999;
  width: 280px;
  max-height: 380px;
  display: flex;
  flex-direction: column;
  background: #fff;
  border: 1px solid rgba(15, 23, 42, 0.1);
  border-radius: 12px;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06), 0 16px 40px rgba(15, 23, 42, 0.2);
  animation: mwflowDataIn 0.12s ease-out;
  @keyframes mwflowDataIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
  }
`;

// Search header stays pinned; only the list below scrolls. Typing here is how
// you find a token in a long flow instead of scrolling group by group.
const SearchRow = styled.div`
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 9px 11px;
  border-bottom: 1px solid #f1f5f9;
  color: #94a3b8;
  flex-shrink: 0;
  input {
    border: 0;
    outline: 0;
    background: transparent;
    width: 100%;
    font-size: 12.5px;
    color: #1f2937;
    &::placeholder { color: #b6c0cc; }
  }
`;

const List = styled.div`
  overflow: auto;
  padding: 6px;
`;

const GroupLabel = styled.div`
  position: sticky;
  top: 0;
  z-index: 1;
  background: #fff;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: #94a3b8;
  padding: 8px 8px 4px;
`;

const Empty = styled.div`
  padding: 22px 12px;
  text-align: center;
  font-size: 12px;
  color: #94a3b8;
`;

const Item = styled.button`
  display: flex;
  flex-direction: column;
  gap: 2px;
  width: 100%;
  text-align: left;
  border: 0;
  background: transparent;
  border-radius: 7px;
  padding: 6px 8px;
  cursor: pointer;
  transition: background 0.1s;
  &:hover { background: #f5f3ff; }
`;

const ItemCode = styled.code`
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
  color: #1d4ed8;
`;

const ItemName = styled.span`
  font-size: 11px;
  color: #94a3b8;
`;

// The real value from the last test run — seeing your own data next to the
// token is what makes {{ references }} click for beginners.
const ItemValue = styled.span`
  font-size: 11px;
  color: #047857;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
`;

const MENU_WIDTH = 270;

export default function DataPicker({ groups, onInsert }) {
  const [pos, setPos] = useState(null); // { top, left } when open, null when closed
  const [query, setQuery] = useState('');
  const btnRef = useRef(null);
  const menuRef = useRef(null);
  const searchRef = useRef(null);

  // Filter on both the token and its human name, so "title" finds
  // {{ fetch_post.title }} and "site" finds the Site name global. Empty groups
  // drop out so you never scroll past sections that don't match.
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return groups;
    return (groups || [])
      .map((g) => ({
        ...g,
        items: g.items.filter(
          (it) => it.token.toLowerCase().includes(q) || (it.name || '').toLowerCase().includes(q)
        )
      }))
      .filter((g) => g.items.length > 0);
  }, [groups, query]);

  const place = useCallback(() => {
    const r = btnRef.current?.getBoundingClientRect();
    if (!r) return;
    // Anchor the menu's right edge to the button's right edge, clamped to the viewport.
    const left = Math.max(8, Math.min(r.right - MENU_WIDTH, window.innerWidth - MENU_WIDTH - 8));
    setPos({ top: r.bottom + 6, left });
  }, []);

  const open = pos !== null;

  useEffect(() => {
    if (!open) return;
    const onDown = (e) => {
      if (btnRef.current?.contains(e.target)) return;
      if (menuRef.current?.contains(e.target)) return;
      setPos(null);
    };
    const onKey = (e) => { if (e.key === 'Escape') setPos(null); };
    // Focus the search field so you can start typing a token immediately.
    searchRef.current?.focus();
    // Any scroll/resize of the page moves the anchor — just close. Scrolling
    // INSIDE the menu is fine though (long token lists need it).
    const onScroll = (e) => {
      if (menuRef.current?.contains(e.target)) return;
      setPos(null);
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onScroll);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('scroll', onScroll, true);
      window.removeEventListener('resize', onScroll);
    };
  }, [open]);

  if (!groups || groups.length === 0) return null;

  return (
    <Wrap>
      <Btn
        ref={btnRef}
        type="button"
        onClick={() => { if (open) { setPos(null); } else { setQuery(''); place(); } }}
        title="Insert data from the trigger or an earlier step"
      >
        <Braces size={12} /> Insert data
      </Btn>
      {open && createPortal(
        <Menu ref={menuRef} style={{ top: pos.top, left: pos.left }}>
          <SearchRow>
            <Search size={14} />
            <input
              ref={searchRef}
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search data…"
              spellCheck={false}
            />
          </SearchRow>
          <List>
            {filtered.map((g) => (
              <div key={g.label}>
                <GroupLabel>{g.label}</GroupLabel>
                {g.items.map((it) => (
                  <Item
                    key={it.token}
                    type="button"
                    onClick={() => { onInsert(it.token); setPos(null); }}
                    title={it.value !== undefined ? `Last run: ${it.value}` : undefined}
                  >
                    <ItemCode>{it.token}</ItemCode>
                    <ItemName>{it.name}</ItemName>
                    {it.value !== undefined && <ItemValue>= {it.value}</ItemValue>}
                  </Item>
                ))}
              </div>
            ))}
            {filtered.length === 0 && <Empty>No data matches “{query}”.</Empty>}
          </List>
        </Menu>,
        document.body
      )}
    </Wrap>
  );
}
