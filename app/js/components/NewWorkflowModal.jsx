import styled from 'styled-components';
import { NekoModal, NekoButton } from '@neko-ui';
import { Sparkles, LayoutTemplate, SquarePen, ChevronRight } from 'lucide-react';

const ModalTitle = styled.p`
  font-family: var(--neko-font-family);
  font-weight: bold;
  font-size: 18px;
  line-height: 22px;
  margin: 0 0 6px;
`;

const Subtitle = styled.div`
  font-size: 12.5px;
  color: #475569;
  margin-bottom: 14px;
  line-height: 1.45;
`;

const Options = styled.div`
  display: flex;
  flex-direction: column;
  gap: 10px;
`;

const Option = styled.button`
  display: flex;
  align-items: center;
  gap: 14px;
  width: 100%;
  text-align: left;
  background: white;
  border: 1px solid #e4e6eb;
  border-radius: 12px;
  padding: 14px 16px;
  cursor: pointer;
  transition: border-color 0.12s, box-shadow 0.12s, transform 0.12s;

  &:hover {
    border-color: #bfdbfe;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.07);
    transform: translateY(-1px);
  }
`;

const OptionIcon = styled.span`
  width: 40px;
  height: 40px;
  border-radius: 11px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: ${(p) => p.$bg};
  color: ${(p) => p.$color};
`;

const OptionBody = styled.span`
  flex: 1;
  min-width: 0;
`;

const OptionTitle = styled.span`
  display: block;
  font-size: 13.5px;
  font-weight: 700;
  color: #0f172a;
`;

const OptionDesc = styled.span`
  display: block;
  font-size: 12px;
  color: #64748b;
  line-height: 1.45;
  margin-top: 2px;
`;

const Recommended = styled.span`
  display: inline-block;
  margin-left: 8px;
  padding: 1px 8px;
  border-radius: 999px;
  background: #ecfdf5;
  color: #047857;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  vertical-align: 1px;
`;

const ModalFooter = styled.div`
  display: flex;
  justify-content: flex-end;
  background: #f0f0f0;
  padding: 10px;
  margin: 15px -15px -15px -15px;
`;

/**
 * The single entry point for creating a workflow — three clear paths
 * (example, AI, blank) presented once, instead of scattered buttons.
 */
export default function NewWorkflowModal({ isOpen, onClose, aiAvailable, onExamples, onBuildWithAi, onBlank }) {
  if (!isOpen) return null;

  return (
    <NekoModal isOpen size="large" onRequestClose={onClose} shouldCloseOnOverlayClick>
      <ModalTitle>New workflow</ModalTitle>
      <Subtitle>How would you like to start?</Subtitle>

      <Options>
        <Option onClick={() => { onClose(); onExamples(); }}>
          <OptionIcon $bg="#eef2ff" $color="#4f46e5"><LayoutTemplate size={20} /></OptionIcon>
          <OptionBody>
            <OptionTitle>
              Start from an example
              <Recommended>Recommended</Recommended>
            </OptionTitle>
            <OptionDesc>
              Working workflows you can install and adapt — email alerts, AI drafts, WooCommerce orders, and more.
            </OptionDesc>
          </OptionBody>
          <ChevronRight size={16} color="#94a3b8" />
        </Option>

        {aiAvailable && (
          <Option onClick={() => { onClose(); onBuildWithAi(); }}>
            <OptionIcon $bg="#ecfdf5" $color="#059669"><Sparkles size={20} /></OptionIcon>
            <OptionBody>
              <OptionTitle>Build with AI</OptionTitle>
              <OptionDesc>
                Describe what should happen in plain English and AI Engine assembles the workflow for you.
              </OptionDesc>
            </OptionBody>
            <ChevronRight size={16} color="#94a3b8" />
          </Option>
        )}

        <Option onClick={() => { onClose(); onBlank(); }}>
          <OptionIcon $bg="#f8fafc" $color="#475569"><SquarePen size={20} /></OptionIcon>
          <OptionBody>
            <OptionTitle>Start blank</OptionTitle>
            <OptionDesc>
              An empty canvas with just a trigger — wire everything up yourself.
            </OptionDesc>
          </OptionBody>
          <ChevronRight size={16} color="#94a3b8" />
        </Option>
      </Options>

      <ModalFooter>
        <NekoButton className="secondary" onClick={onClose}>Cancel</NekoButton>
      </ModalFooter>
    </NekoModal>
  );
}
