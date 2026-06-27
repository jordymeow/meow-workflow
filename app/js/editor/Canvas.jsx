import { useCallback, useEffect, useMemo, useRef } from 'react';
import styled from 'styled-components';
import {
  ReactFlow, ReactFlowProvider, useReactFlow,
  Background, Controls, MiniMap,
  addEdge, applyEdgeChanges, applyNodeChanges
} from '@xyflow/react';
import { Wand2 } from 'lucide-react';
import ActionNode from './nodes/ActionNode';
import TriggerNode from './nodes/TriggerNode';

// Floating "tidy up" control — re-lays the graph into a clean vertical flow.
const TidyButton = styled.button`
  position: absolute;
  top: 16px;
  right: 16px;
  z-index: 6;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  height: 34px;
  padding: 0 13px;
  border: 1px solid rgba(15, 23, 42, 0.1);
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: saturate(180%) blur(8px);
  color: #334155;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
  transition: background 0.12s, color 0.12s, box-shadow 0.12s;
  &:hover { color: var(--neko-blue, hsl(217 80% 42%)); box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12); }
`;

const Wrap = styled.div`
  grid-area: canvas;
  background: var(--mwflow-canvas-bg, #f6f8fb);
  position: relative;
`;

const OnboardingOverlay = styled.div`
  position: absolute;
  pointer-events: none;
  z-index: 5;
  top: 24px;
  left: 50%;
  transform: translateX(-50%);
  background: white;
  border: 1px solid #cbd5e1;
  border-radius: 12px;
  padding: 14px 18px;
  box-shadow: 0 6px 20px rgba(15, 23, 42, 0.07);
  display: flex;
  align-items: center;
  gap: 12px;
  max-width: 540px;
  font-size: 13px;
  color: #334155;
`;

const Hint = styled.span`
  background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
  border: 1px solid #bfdbfe;
  color: #1d4ed8;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 11.5px;
  white-space: nowrap;
`;

const nodeTypes = {
  action: ActionNode,
  trigger: TriggerNode
};

function CanvasInner({ definition, setDefinition, selectedNodeId, setSelectedNodeId, lastRun, onDropSpec, onAddAfter, onAutoArrange, children }) {
  const wrapperRef = useRef(null);
  const { screenToFlowPosition, fitView } = useReactFlow();
  const prevNodeCountRef = useRef(definition.nodes.length);

  // Re-fit the viewport whenever a node is added so the new node is in frame.
  // (Skips when nodes are deleted or just moved.)
  useEffect(() => {
    const count = definition.nodes.length;
    if (count > prevNodeCountRef.current) {
      const t = setTimeout(() => fitView({ padding: 0.2, duration: 250 }), 30);
      prevNodeCountRef.current = count;
      return () => clearTimeout(t);
    }
    prevNodeCountRef.current = count;
  }, [definition.nodes.length, fitView]);

  const lastRunByNode = useMemo(() => {
    if (!lastRun?.steps) return {};
    const out = {};
    for (const s of lastRun.steps) out[s.node_id] = s;
    return out;
  }, [lastRun]);

  const nodesWithRun = useMemo(() =>
    definition.nodes.map((n) => {
      // While a test run is in flight, the node the server is currently
      // executing shows a pulsing "running" badge.
      let step = lastRunByNode[n.id] || null;
      if (!step && lastRun?.status === 'running' && n.id === lastRun.current_step_id) {
        step = { status: 'running' };
      }
      return {
        ...n,
        selected: n.id === selectedNodeId,
        data: { ...n.data, _lastRun: step, _onAddAfter: onAddAfter }
      };
    }),
    [definition.nodes, selectedNodeId, lastRunByNode, lastRun, onAddAfter]
  );

  // Style branch edges by sourceHandle so users see which line is which branch
  // (mirrors the green/red handle styling in ActionNode). Selected edges get a
  // much thicker stroke + glow — the xyflow default is too subtle to notice,
  // especially once our inline branch colors override it. While a test run is
  // in flight, edges along the executed path animate so the flow visibly flows.
  const styledEdges = useMemo(() => {
    const running = lastRun?.status === 'running';
    return (definition.edges || []).map((e) => {
      const branchColor = e.sourceHandle === 'true' ? '#10b981'
        : e.sourceHandle === 'false' ? '#ef4444' : null;
      const stroke = branchColor
        || (e.selected ? 'var(--neko-blue, hsl(217 80% 42%))' : '#b1b1b7');
      const style = {
        stroke,
        strokeWidth: e.selected ? 3.5 : branchColor ? 2 : 1.5
      };
      if (e.selected) {
        style.filter = 'drop-shadow(0 1px 3px rgba(15, 23, 42, 0.35))';
      }
      // An edge is "live" when its source step has finished and its target is
      // running or already done — i.e. data actually travelled down it.
      let animated = false;
      if (running) {
        const src = lastRunByNode[e.source];
        const tgt = lastRunByNode[e.target];
        const tgtActive = (tgt && (tgt.status === 'done' || tgt.status === 'failed'))
          || e.target === lastRun.current_step_id;
        if (src && src.status === 'done' && tgtActive) {
          animated = true;
          style.stroke = branchColor || 'var(--neko-blue, hsl(217 80% 42%))';
          style.strokeWidth = Math.max(style.strokeWidth, 2);
        }
      }
      return { ...e, style, animated };
    });
  }, [definition.edges, lastRun, lastRunByNode]);

  const onNodesChange = useCallback((changes) => {
    setDefinition((d) => ({ ...d, nodes: applyNodeChanges(changes, d.nodes) }));
  }, [setDefinition]);

  const onEdgesChange = useCallback((changes) => {
    setDefinition((d) => ({ ...d, edges: applyEdgeChanges(changes, d.edges) }));
  }, [setDefinition]);

  const onConnect = useCallback((connection) => {
    setDefinition((d) => ({ ...d, edges: addEdge({ ...connection, id: `e_${Date.now()}` }, d.edges) }));
  }, [setDefinition]);

  const onDragOver = useCallback((event) => {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
  }, []);

  const onDrop = useCallback((event) => {
    event.preventDefault();
    const raw = event.dataTransfer.getData('application/mwflow-spec');
    if (!raw) return;
    let spec;
    try { spec = JSON.parse(raw); } catch { return; }
    const position = screenToFlowPosition({ x: event.clientX, y: event.clientY });
    onDropSpec(spec, position);
  }, [screenToFlowPosition, onDropSpec]);

  const showOnboarding = definition.nodes.length <= 1;

  const tidy = useCallback(() => {
    onAutoArrange?.();
    setTimeout(() => fitView({ padding: 0.2, duration: 300 }), 40);
  }, [onAutoArrange, fitView]);

  return (
    <Wrap ref={wrapperRef} onDragOver={onDragOver} onDrop={onDrop}>
      {onAutoArrange && definition.nodes.length >= 2 && (
        <TidyButton onClick={tidy} title="Auto-arrange into a clean vertical layout">
          <Wand2 size={15} /> Tidy up
        </TidyButton>
      )}
      {showOnboarding && (
        <OnboardingOverlay>
          <span style={{ fontSize: 22 }}>👋</span>
          <div>
            <div style={{ fontWeight: 700, marginBottom: 2 }}>Add your first step</div>
            <div style={{ fontSize: 12, color: '#64748b' }}>
              Use the <Hint>Add step</Hint> toolbar below — new steps chain onto your trigger automatically.
            </div>
          </div>
        </OnboardingOverlay>
      )}
      <ReactFlow
        nodes={nodesWithRun}
        edges={styledEdges}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onConnect={onConnect}
        onNodeClick={(_, node) => setSelectedNodeId(node.id)}
        onPaneClick={() => setSelectedNodeId(null)}
        nodeTypes={nodeTypes}
        fitView
        proOptions={{ hideAttribution: true }}
      >
        <Background gap={20} size={1.4} color="var(--mwflow-canvas-dot, #d8dee9)" />
        <Controls showInteractive={false} position="bottom-left" />
        {definition.nodes.length >= 5 && (
          <MiniMap pannable zoomable maskColor="rgba(15, 23, 42, 0.06)" />
        )}
      </ReactFlow>
      {children}
    </Wrap>
  );
}

export default function Canvas(props) {
  return (
    <ReactFlowProvider>
      <CanvasInner {...props} />
    </ReactFlowProvider>
  );
}
