import AgentTokenController from './AgentTokenController'
import ProviderAccountAssignmentController from './ProviderAccountAssignmentController'
import ProfileController from './ProfileController'
import SecurityController from './SecurityController'
import ProviderConnectionController from './ProviderConnectionController'

const Settings = {
    AgentTokenController: Object.assign(AgentTokenController, AgentTokenController),
    ProviderAccountAssignmentController: Object.assign(ProviderAccountAssignmentController, ProviderAccountAssignmentController),
    ProfileController: Object.assign(ProfileController, ProfileController),
    SecurityController: Object.assign(SecurityController, SecurityController),
    ProviderConnectionController: Object.assign(ProviderConnectionController, ProviderConnectionController),
}

export default Settings