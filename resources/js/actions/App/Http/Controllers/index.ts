import Api from './Api'
import WorkspaceController from './WorkspaceController'
import AIConnectionController from './AIConnectionController'
import Settings from './Settings'

const Controllers = {
    Api: Object.assign(Api, Api),
    WorkspaceController: Object.assign(WorkspaceController, WorkspaceController),
    AIConnectionController: Object.assign(AIConnectionController, AIConnectionController),
    Settings: Object.assign(Settings, Settings),
}

export default Controllers