[![Stories in Ready](https://badge.waffle.io/psignoret/aad-sso-wordpress.png?label=ready&title=Ready)](https://waffle.io/psignoret/aad-sso-wordpress)
# Sign Sign-on with Azure Active Directory (for WordPress)

A WordPress plugin that allows organizations to use their Azure Active Directory
user accounts to sign in to WordPress. Organizations with Office 365 already have
Azure Active Directory (Azure AD) and can use this plugin for all of their users.

- Azure AD group membership can be used to determine access and role.
- New users can be registered on-the-fly based on their Azure AD profile.
- Can always fall back to regular username and password login.

*This is a work in progress, please feel free to contact me for help. This plugin is provided as-is, with no guarantees or assurances.*

In the typical flow:

1. User attempts to log in to the blog (`wp-admin`). At the sign in page, they are given a link to sign in with their Azure Active Directory organization account (e.g. an Office 365 account).
2. After signing in, the user is redirected back to the blog with an authorization code, which the plugin exchanges for a ID Token, containing a minimal set of claims about the signed in user, and an Access Token, which can be used to query Azure AD for additional details.
3. The plugin uses the claims in the ID Token to attempt to find a WordPress user with an email address or login name that matches the Azure AD user.
4. If one is found, the user is authenticated in WordPress as that user. If one is not found, the WordPress user will (optionally) be auto-provisioned on-the-fly.
5. (Optional) Membership to certain groups in Azure AD can be mapped to roles in WordPress, and group membership can be used to restrict access.

## Getting Started

The following instructions will get you started. In this case, we will be configuring the plugin to use the user roles configured in WordPress.

### 1. Download and activate the plugin

This plugin is not yet registered in the WordPress plugin directory (coming soon!), but you can still install it manually:

1. Download the plugin using `git` or with the 'Download ZIP' link on the right.
2. Place the `aad-sso-wordpress` folder in your WordPress' plugin folder. Normally, this is `<your-blog>/wp-content/plugins`.
3. Activate the plugin in the WordPress admin console, under **Plugins** > **Installed Plugins**.

### 2. Register an Azure Active Directory application

With these steps, you will register an application with Azure AD. This application identifies your WordPress site with Azure AD.

1. Sign in to the [**Azure portal**](https://portal.azure.com), and ensure you are signed in to the directory which has the users you'd like to allow to sign in. (This will typically be your organization's directory.) You can view which directory you're signed in to (and switch directories if needed) by clicking on your username in the upper right-hand corner.

2. Navigate to the [**Azure Active Directory**](https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade) blade, and enter the [**App registrations**](https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/RegisteredApps) section.

    ![Clicking Azure Active Directory](https://user-images.githubusercontent.com/231140/29496874-6cf6f722-85dc-11e7-8898-89db80593ffc.png) <br />
    ![Clicking App registrations](https://user-images.githubusercontent.com/231140/29496884-9b3693ae-85dc-11e7-89a0-77e80979af23.png)

3. Choose **New application registration**, and provide a name for your app. This will be the name displayed to users when signing in. Leave the default application type ("Web app / API"), provide the URL of your site as the "Sign-on URL", and click **Create**. When the app is created, select the newly-registered app from the list.

    ![Clicking New application registration](https://user-images.githubusercontent.com/231140/29496889-c5096a80-85dc-11e7-92e9-eafc49a2e0c6.png)<br />
    ![Creating new application](https://user-images.githubusercontent.com/231140/29496901-0d80184a-85dd-11e7-8121-7b0d6f2d5d48.png)<br />
    ![Selecting the newly-created app](https://user-images.githubusercontent.com/231140/29496910-38792bcc-85dd-11e7-9d01-7bded9a4090e.png)

4. Under **Reply URLs**, update the existing reply URL with the format: `https://<your blog url>/wp-login.php`, or whichever page your blog uses to sign in users, and click **Save**. (Note: This page must invoke the `authenticate` action.)

    ![Adding a reply URL](https://user-images.githubusercontent.com/231140/29496951-54b63d74-85de-11e7-848d-d1ed0b7ce105.png)

5. Under **Required permissions**, choose the "Windows Azure Active Directory" API. You will need at minimum delegated permissions to "Sign in and read user profile". If you wish to map Azure AD groups to WordPress roles, you will also need delegated permission to "Read directory data". Once you've selected the permissions, click **Save**.
    
    **Important**: The "Read directory data" delegated permissions requires a tenant administrator to consent to the application. The tenant administrator can use the **Grant Permissions** option to grant permissions (i.e. consent) on behalf of all users.

    ![Delegated permissions to sign in and read directory data](https://user-images.githubusercontent.com/231140/30487748-a6fe8e5a-9a34-11e7-8730-ce44472817cf.png)

7. Under **Keys**, provide a new secret key description and duration, and click **Save**. After saving, the secret key value will appear. Copy it, as this is the only time it will be available.

    ![Creating a new secret key](https://user-images.githubusercontent.com/231140/29496984-395c4f36-85df-11e7-9c0c-0ecc912585f3.png)

8. Keep a tab open with the app registration page, as you will need to copy some fields when configuring the plugin.

    ![App settings summary page](https://user-images.githubusercontent.com/231140/29496998-8e1afd92-85df-11e7-96e7-0170b57939d1.png)

### 3. Configure the plugin

Once the plugin is activated, update your settings from the WordPress admin console under **Settings** > **Azure AD**. Basic settings to include are:

<dl>
  <dt>Display name</dt>
  <dd>
    The display name of the organization, used only in the link in the login page.
  </dd>

  <dt>Client ID</dt>
  <dd>
    The Application ID. (Copy this from Azure AD application's configuration page.)
  </dd>
    
  <dt>Client Secret</dt>
  <dd>
    The secret key. (Copy this from the Azure AD application's configuration page.)
  </dd>

  <dt>Reply URL</dt>
  <dd>
    The URL that Azure AD will send the user to after authenticating. This is usually the blog's sign-in page, which is the default value. Ensure that the reply URL configured in Azure AD matches this value.
  </dd>
</dl>

### 4. (Optional) Set WordPress roles based on Azure AD group membership

The Single Sign-on with Azure AD plugin can be configured to set different WordPress roles based on the user's membership to a set of user-defined groups. This is a great way to control who has access to the blog, and under what role.

This is also configured **Settings** > **Azure AD** (from the WordPress admin console). The following fields should be included:

<dl>
  <dt>Enable Azure AD group to WP role association</dt>
  <dd>
    Check this to enable Azure AD group-based WordPress roles.
  </dd>

  <dt>Default WordPress role if not in Azure AD group</dt>
  <dd>
    This is the default role that users will be assigned to if matching Azure AD group to WordPress roles is enabled. If this is not set, and the user authenticating does not belong to any of the groups defined, they will be denied access.
  </dd>

  <dt>WordPress role to Azure AD group map</dt>
  <dd>
    For each of the blog's WordPress roles, there is a field for the ObjectId of the Azure AD group that will be associated with that role.
  </dd>
</dl>

## Example settings

The different fields that can be defined in the settings JSON in **Settings** > **Azure AD** are documented in [Settings.php](Settings.php). The following may give you an idea of the typical scenarios that may be encountered.

### Minimal

Users are matched by their email address in WordPress, and whichever role they have in WordPress is maintained.

| Setting | Example value
| --- | ---
| Display name | Contoso
| Client ID | 9054eff5-bfef-4cc5-82fd-8c35534e48f9
| Client Secret | NTY5MmE5YjMwMGY2MWQ0NjU5MzYxNjdjNzE1OGNiZmY=
| Reply URL | https://www.example.com/blog/wp-login.php
| Field to match to UPN | Email Address

### Match on username alias

Users are matched by their login names in WordPress and the alias portion of their Azure AD UserPrincipalName. Whichever role they have in WordPress is maintained.

| Setting | Example value
| --- | ---
| Display name | Contoso
| Client ID | 9054eff5-bfef-4cc5-82fd-8c35534e48f9
| Client Secret | NTY5MmE5YjMwMGY2MWQ0NjU5MzYxNjdjNzE1OGNiZmY=
| Reply URL | https://www.example.com/blog/wp-login.php
| Field to match to UPN | Login Name
| Match on alias of the UPN | Yes

### Group membership-based roles, no default role

Users are matched by their login names in WordPress, and WordPress roles are dictated by membership to a given Azure AD group. Access is denied if they are not members of any of these groups.

| Setting | Example value
| --- | ---
| Display name | Contoso
| Client ID | 9054eff5-bfef-4cc5-82fd-8c35534e48f9
| Client Secret | NTY5MmE5YjMwMGY2MWQ0NjU5MzYxNjdjNzE1OGNiZmY=
| Reply URL | https://www.example.com/blog/wp-login.php
| Field to match to UPN | Login Name
| Enable Azure AD group to WP role association | Yes
| Default WordPress role if not in Azure AD group | (None, deny access)
| WordPress role to Azure AD group map | <table><tr><td>Administrator</td><td>5d1915c4-2373-42ba-9796-7c092fa1dfc6</td></tr><tr><td>Editor</td><td>21c0f87b-4b65-48c1-9231-2f9295ef601c</td></tr><tr><td>Author</td><td>f5784693-11e5-4812-87db-8c6e51a18ffd</td></tr><tr><td>Contributor</td><td>780e055f-7e64-4e34-9ff3-012910b7e5ad</td></tr><tr><td>Subscriber</td><td>f1be9515-0aeb-458a-8c0a-30a03c1afb67</td></tr></table>

### Group membership-based roles with default role

Users are matched by their login names in WordPress, and WordPress roles are dictated by membership to a given Azure AD group. If the user is not a part of any of these groups, they are assigned the *Author* role.

| Setting | Example value
| --- | ---
| Display name | Contoso
| Client ID | 9054eff5-bfef-4cc5-82fd-8c35534e48f9
| Client Secret | NTY5MmE5YjMwMGY2MWQ0NjU5MzYxNjdjNzE1OGNiZmY=
| Reply URL | https://www.example.com/blog/wp-login.php
| Field to match to UPN | Login Name
| Enable Azure AD group to WordPress role association | Yes
| Default WordPress role if not in Azure AD group | Author
| WordPress role to Azure AD group map | <table><tr><td>Administrator</td><td>5d1915c4-2373-42ba-9796-7c092fa1dfc6</td></tr><tr><td>Editor</td><td>21c0f87b-4b65-48c1-9231-2f9295ef601c</td></tr><tr><td>Author</td><td>f5784693-11e5-4812-87db-8c6e51a18ffd</td></tr><tr><td>Contributor</td><td>780e055f-7e64-4e34-9ff3-012910b7e5ad</td></tr><tr><td>Subscriber</td><td>f1be9515-0aeb-458a-8c0a-30a03c1afb67</td></tr></table>

### Group membership-based roles, default role, auto-provision

Users are matched by their email in WordPress, and WordPress roles are dictated by membership to a given Azure AD group. If the user doesn't exist in WordPress yet, they will be auto-provisioned. If the user is not a part of any of these groups, they are assigned the *Subscriber* role.

| Setting | Example value
| --- | ---
| Display name | Contoso
| Client ID | 9054eff5-bfef-4cc5-82fd-8c35534e48f9
| Client Secret | NTY5MmE5YjMwMGY2MWQ0NjU5MzYxNjdjNzE1OGNiZmY=
| Reply URL | https://www.example.com/blog/wp-login.php
| Field to match to UPN | Email Address
| Enable auto-provisioning | Yes
| Enable Azure AD group to WP role association | Yes
| Default WordPress role if not in Azure AD group | Subscriber
| WordPress role to Azure AD group map | <table><tr><td>Administrator</td><td>5d1915c4-2373-42ba-9796-7c092fa1dfc6</td></tr><tr><td>Editor</td><td>21c0f87b-4b65-48c1-9231-2f9295ef601c</td></tr><tr><td>Author</td><td>f5784693-11e5-4812-87db-8c6e51a18ffd</td></tr><tr><td>Contributor</td><td>780e055f-7e64-4e34-9ff3-012910b7e5ad</td></tr><tr><td>Subscriber</td><td>f1be9515-0aeb-458a-8c0a-30a03c1afb67</td></tr></table>

## Groups

As described above, you can map Azure AD groups to WordPress roles. Users who are members of the Azure AD group will be granted the WordPress role(s) the groups were mapped to.

There are several ways Azure AD groups can be created/managed. Some of them require the group owner/creator to be a tenant administrator, others not necessarily (depending on your organization's policy):

 * **Azure portal**. The Azure portal ([https://portal.azure.com](https://portal.azure.com)), under [Azure Active Directory](https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/Overview) > [Users and groups](https://portal.azure.com/#blade/Microsoft_AAD_IAM/UserManagementMenuBlade/Overview/menuId/) > [All groups](https://portal.azure.com/#blade/Microsoft_AAD_IAM/UserManagementMenuBlade/All%20groups/menuId/) > New group, allows admins and (optionally) users to create and manage groups.
 * **Access Panel**. The Azure AD Access Panel ([https://myapps.microsoft.com](https://myapps.microsoft.com)) provides an interface for users to create and manage [groups](https://account.activedirectory.windowsazure.com/#/groups).
 * **Outlook**. The Outlook web interface ([https://outlook.office.com/](https://outlook.office.com/)) offers users the option to create Office 365 Groups. These groups are stored in Azure AD and can be used with this plugin.
 * **Microsoft Teams**. Creating a team in Microsoft Teams ([https://teams.microsoft.com](https://teams.microsoft.com)) also results in an Office 365 Group getting created.
 * **Azure AD PowerShell**. The [Azure AD PowerShell module](https://docs.microsoft.com/en-us/powershell/azure/active-directory/install-adv2?view=azureadps-2.0) allows admins and (optionally) users to create and manage groups. (e.g. [New-AzureADGroup](https://docs.microsoft.com/en-us/powershell/module/azuread/new-azureadgroup?view=azureadps-2.0), and [Add-AzureADGroupMember](https://docs.microsoft.com/en-us/powershell/module/azuread/add-azureadgroupmember?view=azureadps-2.0) cmdlets.)
 * **On-premises**. Many large organizations use Azure AD Connect to sync their on-premises AD to Azure AD. This usually includes all on-premises AD groups and memberships. Once these groups are synced to Azrue AD, they can be used with this plugin.

## Advanced

### Refreshing the OpenID Connect configuration cache

Most of the OpenID Connect endpoints and configuration (e.g. signing keys, etc.) are obtained from the OpenID Connect configuration endpoint. These values are cached for one hour, but can always be forced to re-load by adding `aadsso_reload_openid_config=1` to the query string in the login page. (This shouldn't really be needed, but it has shown to be useful during development.)

### Bypassing automatic redirect to Azure AD to prevent lockouts

If you've configured this plugin to automatically redirect to Azure AD for sign-in, but something is misconfigured, you may find yourself locked out of your site's admin dashboard.

To log in to your site *without* automatically redirecting to Azure AD (thus giving you an opportunity to enter a regular username and password), you can append `?aadsso_no_redirect=please` to the login URL. For example, if your login URL is `https://example.com/wp-login.php`, navigating to `https://example.com/wp-login.php?aadsso_no_redirect=please` will prevent any automatic redirects.