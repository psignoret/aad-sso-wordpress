[![Stories in Ready](https://badge.waffle.io/psignoret/aad-sso-wordpress.png?label=ready&title=Ready)](https://waffle.io/psignoret/aad-sso-wordpress)
# Sign Sign-on with Azure Active Directory (for WordPress)

A WordPress plugin that allows organizations to use their Azure Active Directory
user accounts to sign in to WordPress. Organizations with Office 365 already have
Azure Active Directory and can use this plugin for all of their users.

- Azure AD group membership can be used to determine access and role.
- New users can be registered on-the-fly based on their Azure AD profile.
- Can always fall back to regular username and password login.

*This is a work in progress, please feel free to contact me for help. This plugin is provided as-is, with no guarantees or assurances.*

In the typical flow:

1. User attempts to access the admin section of the blog (`wp-admin`). At the sign in page, they are given a link to sign in with their Azure Active Directory organization account (e.g. an Office 365 account).
2. After signing in, the user is redirected back to the blog with a JSON Web Token (JWT), containing a minimal set of claims.
3. The plugin uses these claims to attempt to find a WordPress user with an email address or login name that matches the Azure Active Directory user.
4. If one is found, the user is authenticated in WordPress as that user. If one is not found, the WordPress user will (optionally) be auto-provisioned on-the-fly.
5. (Optional) Membership to certain groups in Azure AD can be mapped to roles in WordPress, and group membership can be used to restrict access.

## Getting Started

The following instructions will get you started. In this case, we will be configuring the plugin to use the user roles configured in WordPress.

### 1. Download and activate the plugin

This plugin is not yet registered in the WordPress plugin directory (coming soon!), but you can still install it manually:

1. Download the plugin using `git` or with the 'Download ZIP' link on the right.
2. Place the `aad-sso-wordpress` folder in your WordPress' plugin folder. Normally, this is `<yourblog>/wp-content/plugins`.
3. Activate the plugin in the WordPress admin console, under **Plugins** > **Installed Plugins**.

### 2. Register an Azure Active Directory application

For these steps, you must have an Azure subscription with access to the Azure Active Directory tenant that you would like to use with your blog.

1. Sign in to the [Azure portal](https://manage.windowsazure.com), and navigate to the **Active Directory** section. Choose the directory (tenant) that you would like to use. This should be the directory containing the users and (optionally) groups that will have access to your WordPress blog.
3. Under the **Applications** tab, click **Add** to register a new application. Choose 'Add an application my organization is developing', and a recognizable name. Choose values for sign-in URL and app ID URL. The blog's URL is usually a good choice.
4. When the app is created, under the **Configure** tab, generate a key (it will be visible once only, after you save). <br /> IMPORTANT: This value is a secret! You should never share this with anyone.
5. Add a reply URL with the format: `https://<your blog url>/wp-login.php`, or whichever page your blog uses to sign in users. (Note: This page must invoke the `authenticate` action.)
6. Grant the application permission to call Azure AD Graph API. This is done by adding permissions to Windows Azure Active Directory, and checking Delegated Permission to "Enable sign-on and read users' profile" and "Read directory data". (The latter is currently only needed if Azure AD group to WordPress role mapping is used.)
   ![Application permissions to Azure AD Graph API](https://cloud.githubusercontent.com/assets/231140/6990496/fcf02fb0-da21-11e4-9d60-1e6e2fd2cef1.png)
7. Save the application (and copy the secret key, which will appear after saving).

### 3. Configure the plugin

Once the plugin is activated, update your settings from the WordPress admin console under **Settings** > **Azure AD**. Basic settings to include are:

<dl>
  <dt>Display name</dt>
  <dd>
    The display name of the organization, used only in the link in the login page.
  </dd>

  <dt>Client ID</dt>
  <dd>
    The application's client ID. (Copy this from the Azure AD application's configuration page.)
  </dd>
    
  <dt>Client Secret</dt>
  <dd>
    The client secret key. (Copy this from the Azure AD application's configuration page.)
  </dd>

  <dt>Reply URL</dt>
  <dd>
    The URL that Azure AD will send the user to after authenticating. This is usually the blog's sign-in page, which is the default value.
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

## Advanced

### Refreshing the OpenID Connect configuration cache

Most of the OpenID Connect endpoints and configuration (e.g. signing keys, etc.) are obtained from the OpenID Connect configuration endpoint. These values are cached for one hour, but can always be forced to re-load by adding `aadsso_reload_openid_config=1` to the query string in the login page. (This shouldn't really be needed, but it has shown to be useful during development.)
