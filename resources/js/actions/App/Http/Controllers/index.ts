import WorkspaceController from './WorkspaceController'
import Settings from './Settings'

const Controllers = {
    WorkspaceController: Object.assign(WorkspaceController, WorkspaceController),
    Settings: Object.assign(Settings, Settings),
}

export default Controllers