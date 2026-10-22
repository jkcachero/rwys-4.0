import ProfileController from './ProfileController'
import SecurityController from './SecurityController'
import ProviderConnectionController from './ProviderConnectionController'
import AgentAccessController from './AgentAccessController'

const Settings = {
    ProfileController: Object.assign(ProfileController, ProfileController),
    SecurityController: Object.assign(SecurityController, SecurityController),
    ProviderConnectionController: Object.assign(ProviderConnectionController, ProviderConnectionController),
    AgentAccessController: Object.assign(AgentAccessController, AgentAccessController),
}

export default Settings