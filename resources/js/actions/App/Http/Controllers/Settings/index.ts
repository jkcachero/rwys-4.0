import ProfileController from './ProfileController'
import SecurityController from './SecurityController'
import ProviderConnectionController from './ProviderConnectionController'

const Settings = {
    ProfileController: Object.assign(ProfileController, ProfileController),
    SecurityController: Object.assign(SecurityController, SecurityController),
    ProviderConnectionController: Object.assign(ProviderConnectionController, ProviderConnectionController),
}

export default Settings