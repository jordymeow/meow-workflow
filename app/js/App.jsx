import { useState } from 'react';
import { NekoUI, NekoPage, NekoHeader, NekoWrapper, NekoColumn, NekoTabs, NekoTab, NekoButton } from '@neko-ui';
import FlowsScreen from './screens/FlowsScreen';
import RunsScreen from './screens/RunsScreen';
import SettingsScreen from './screens/SettingsScreen';
import Editor from './editor/Editor';

const TAB_TITLES = {
  flows: 'Workflows',
  runs: 'Runs',
  settings: 'Settings'
};

export default function App() {
  const [editor, setEditor] = useState(null);
  const [tab, setTab] = useState('flows');

  const openEditor = (flowId) => setEditor(flowId);
  const closeEditor = () => setEditor(null);

  // Editor view is full-screen — it has its own top bar with a Back button.
  if (editor) {
    return (
      <NekoUI>
        <NekoPage>
          <NekoHeader title="Meow Workflow" section="Editor" subtitle="By Meow Apps">
            <NekoButton className="header" icon="arrow-left" onClick={closeEditor}>
              All workflows
            </NekoButton>
          </NekoHeader>
          <NekoWrapper>
            <NekoColumn fullWidth>
              <Editor flowId={editor} onBack={closeEditor} />
            </NekoColumn>
          </NekoWrapper>
        </NekoPage>
      </NekoUI>
    );
  }

  return (
    <NekoUI>
      <NekoPage>
        <NekoHeader title="Meow Workflow" section={TAB_TITLES[tab]} subtitle="By Meow Apps" />
        <NekoWrapper>
          <NekoColumn fullWidth>
            <NekoTabs keepTabOnReload onChange={(_idx, tabAttr) => tabAttr?.key && setTab(tabAttr.key)}>
              <NekoTab title="Workflows" key="flows">
                <FlowsScreen onOpen={openEditor} />
              </NekoTab>
              <NekoTab title="Runs" key="runs">
                <RunsScreen />
              </NekoTab>
              <NekoTab title="Settings" key="settings">
                <SettingsScreen />
              </NekoTab>
            </NekoTabs>
          </NekoColumn>
        </NekoWrapper>
      </NekoPage>
    </NekoUI>
  );
}
