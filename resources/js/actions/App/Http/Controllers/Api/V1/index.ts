import AgentContextController from './AgentContextController'
import AgentCapabilityController from './AgentCapabilityController'
import ZernioAccountController from './ZernioAccountController'
import ZernioAccountStatusController from './ZernioAccountStatusController'
import ZernioPostController from './ZernioPostController'
import ZernioPostAnalyticsController from './ZernioPostAnalyticsController'
import ZernioPostPublishController from './ZernioPostPublishController'
import AgentOperationController from './AgentOperationController'

const V1 = {
    AgentContextController: Object.assign(AgentContextController, AgentContextController),
    AgentCapabilityController: Object.assign(AgentCapabilityController, AgentCapabilityController),
    ZernioAccountController: Object.assign(ZernioAccountController, ZernioAccountController),
    ZernioAccountStatusController: Object.assign(ZernioAccountStatusController, ZernioAccountStatusController),
    ZernioPostController: Object.assign(ZernioPostController, ZernioPostController),
    ZernioPostAnalyticsController: Object.assign(ZernioPostAnalyticsController, ZernioPostAnalyticsController),
    ZernioPostPublishController: Object.assign(ZernioPostPublishController, ZernioPostPublishController),
    AgentOperationController: Object.assign(AgentOperationController, AgentOperationController),
}

export default V1