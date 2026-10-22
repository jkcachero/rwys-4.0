import AgentApiController from './AgentApiController'
import WorkspaceController from './WorkspaceController'
import AIConnectionController from './AIConnectionController'
import Settings from './Settings'
import AgentWebhookController from './AgentWebhookController'

const Controllers = {
    AgentApiController: Object.assign(AgentApiController, AgentApiController),
    WorkspaceController: Object.assign(WorkspaceController, WorkspaceController),
    AIConnectionController: Object.assign(AIConnectionController, AIConnectionController),
    Settings: Object.assign(Settings, Settings),
    AgentWebhookController: Object.assign(AgentWebhookController, AgentWebhookController),
}

export default Controllers