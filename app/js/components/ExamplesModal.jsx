import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import styled from 'styled-components';
import { NekoModal, NekoButton, NekoMessage } from '@neko-ui';
import { Sparkles, Check } from 'lucide-react';
import { api } from '../helpers/api';

// Same chip pattern as the step picker, so the two galleries feel related.
const Chips = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
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

const CATEGORY_LABELS = {
  basics: 'Basics',
  ai: 'AI',
  woocommerce: 'WooCommerce',
  seo: 'SEO'
};

const ModalTitle = styled.p`
  font-family: var(--neko-font-family);
  font-weight: bold;
  font-size: 18px;
  line-height: 22px;
  margin: 0 0 6px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
`;

const Subtitle = styled.div`
  font-size: 12.5px;
  color: #475569;
  margin-bottom: 14px;
  line-height: 1.45;
`;

const Grid = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 12px;
`;

// The card list scrolls inside the modal; title, chips, and footer stay put.
// Without this, 13 templates make the modal taller than the screen.
const Body = styled.div`
  max-height: 60vh;
  overflow: auto;
  margin: 0 -4px;
  padding: 4px;
`;

const Card = styled.div`
  background: white;
  border: 1px solid #e4e6eb;
  border-radius: 10px;
  padding: 14px 14px 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  ${(p) => p.$disabled && `opacity: 0.7;`}
`;

const Name = styled.div`
  font-size: 13.5px;
  font-weight: 700;
  color: #0f172a;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
`;

const TagPill = styled.span`
  display: inline-block;
  padding: 1px 8px;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.05);
  color: #4b5563;
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
`;

const Desc = styled.div`
  font-size: 12px;
  color: #64748b;
  line-height: 1.45;
  flex: 1;
  min-height: 40px;
`;

const RequiresLine = styled.div`
  font-size: 11px;
  color: #94a3b8;
  margin-top: 2px;
`;

const InstallRow = styled.div`
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  margin-top: 4px;
`;

const InstalledTag = styled.span`
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11.5px;
  color: #15803d;
  font-weight: 600;
`;

const ModalFooter = styled.div`
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  background: #f0f0f0;
  padding: 10px;
  margin: 15px -15px -15px -15px;
`;

const INTEGRATION_LABELS = {
  'ai-engine': 'AI Engine',
  'seo-engine': 'SEO Engine',
  'code-engine': 'Code Engine',
  'social-engine': 'Social Engine',
  woocommerce: 'WooCommerce',
  wordpress: 'WordPress',
  core: 'Core'
};

const labelFor = (id) => INTEGRATION_LABELS[id] || id;

export default function ExamplesModal({ isOpen, onClose, onOpenFlow }) {
  const qc = useQueryClient();
  // Map of templateId → newly-created flowId, so each card's "Open" opens the
  // flow it actually installed (not just the most recent one).
  const [installedFlows, setInstalledFlows] = useState({});
  const [errorMessage, setErrorMessage] = useState(null);
  const [category, setCategory] = useState('all');

  const templates = useQuery({
    queryKey: ['templates'],
    queryFn: api.templates,
    enabled: isOpen
  });

  // Category chips, derived from what's actually registered.
  const categories = useMemo(() => {
    const seen = [];
    for (const t of (templates.data || [])) {
      const c = t.category || 'basics';
      if (!seen.includes(c)) seen.push(c);
    }
    return seen;
  }, [templates.data]);

  const install = useMutation({
    mutationFn: (id) => api.installTemplate(id),
    onSuccess: (flow, id) => {
      setInstalledFlows((m) => ({ ...m, [id]: flow?.id }));
      qc.invalidateQueries({ queryKey: ['flows'] });
      setErrorMessage(null);
    },
    onError: (err) => setErrorMessage(err.message || 'Failed to install example.')
  });

  if (!isOpen) return null;
  const list = (templates.data || []).filter(
    (t) => category === 'all' || (t.category || 'basics') === category
  );

  return (
    <NekoModal isOpen size="larger" onRequestClose={onClose} shouldCloseOnOverlayClick>
      <ModalTitle>
        <Sparkles size={16} color="#4f46e5" />
        Install example workflows
      </ModalTitle>
      <Subtitle>
        Each example installs as a paused workflow you can review and activate. Examples that need a plugin you don't have are disabled.
      </Subtitle>

      {categories.length > 1 && (
        <Chips>
          <Chip $active={category === 'all'} onClick={() => setCategory('all')}>All</Chip>
          {categories.map((c) => (
            <Chip key={c} $active={category === c} onClick={() => setCategory(c)}>
              {CATEGORY_LABELS[c] || c}
            </Chip>
          ))}
        </Chips>
      )}

      {errorMessage && (
        <NekoMessage variant="danger" style={{ marginBottom: 12 }}>
          {errorMessage}
        </NekoMessage>
      )}

      {templates.isLoading && <div style={{ padding: 20, color: '#6b7280' }}>Loading examples…</div>}

      {!templates.isLoading && list.length === 0 && (
        <NekoMessage variant="info">No example workflows registered.</NekoMessage>
      )}

      <Body>
      <Grid>
        {list.map((t) => {
          const installedFlowId = installedFlows[t.id];
          const installed = installedFlowId !== undefined;
          const canInstall = !!t.can_install;
          const missing = (t.missing_requires || []);
          return (
            <Card key={t.id} $disabled={!canInstall && !installed}>
              <Name>
                <span>{t.name}</span>
                {t.tag && <TagPill>{t.tag}</TagPill>}
              </Name>
              <Desc>{t.description}</Desc>
              <RequiresLine>
                {(t.requires || []).every((r) => r === 'wordpress' || r === 'core')
                  ? 'No extra plugins needed'
                  : `Needs: ${(t.requires || []).filter((r) => r !== 'wordpress' && r !== 'core').map(labelFor).join(' · ')}`}
              </RequiresLine>
              {missing.length > 0 && (
                <NekoMessage variant="warning" style={{ fontSize: 11.5, padding: '4px 8px' }}>
                  Activate {missing.map(labelFor).join(' & ')} to use this example.
                </NekoMessage>
              )}
              <InstallRow>
                {installed ? (
                  <>
                    <InstalledTag><Check size={13} /> Installed (paused)</InstalledTag>
                    <NekoButton
                      className="secondary"
                      onClick={() => onOpenFlow && installedFlowId && onOpenFlow(installedFlowId)}
                      disabled={!installedFlowId}
                    >
                      Open
                    </NekoButton>
                  </>
                ) : (
                  <NekoButton
                    className="primary"
                    icon="plus"
                    disabled={!canInstall || install.isPending}
                    isBusy={install.isPending && install.variables === t.id}
                    onClick={() => install.mutate(t.id)}
                  >
                    Install
                  </NekoButton>
                )}
              </InstallRow>
            </Card>
          );
        })}
      </Grid>
      </Body>

      <ModalFooter>
        <NekoButton className="primary" onClick={onClose}>Done</NekoButton>
      </ModalFooter>
    </NekoModal>
  );
}
