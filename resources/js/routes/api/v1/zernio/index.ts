import accounts from './accounts'
import posts from './posts'
import scheduledPosts from './scheduled-posts'

const zernio = {
    accounts: Object.assign(accounts, accounts),
    posts: Object.assign(posts, posts),
    scheduledPosts: Object.assign(scheduledPosts, scheduledPosts),
}

export default zernio