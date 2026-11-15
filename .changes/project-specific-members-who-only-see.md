---
type: added
---
Project-specific members, who only see one project and can create their own projects ([#6894](https://github.com/coollabsio/coolify/issues/6894))

- Invite a member to one project from the project page, or from the team page with project scopes. A member with the new "Can create their own projects" option can create a project and deploy their own apps there, and the project they create becomes their project. The team members API now returns each member role, access grants, and the new option, and there is a new project members API. Project-specific members cannot view servers without one of their resources on them, or view private keys.
