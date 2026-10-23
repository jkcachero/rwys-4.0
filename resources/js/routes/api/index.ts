import webhooks from './webhooks'
import v1 from './v1'

const api = {
    webhooks: Object.assign(webhooks, webhooks),
    v1: Object.assign(v1, v1),
}

export default api