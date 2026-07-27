# Sign Sign-on with Microsoft Entra ID (for WordPress)

A WordPress plugin that allows organizations to use their Microsoft Entra ID (formerly known as Azure Active Directory) user accounts to sign in to WordPress. Organizations with Office 365 already have Microsoft Entra ID (Microsoft Entra ID) and can use this plugin for all of their users.

The plugin uses Microsoft identity platform v2 endpoints and supports PHP 5.6 through PHP 8.5.

- Microsoft Entra ID group membership can be used to determine access and role.
- New users can be registered on-the-fly based on their Microsoft Entra ID profile.
- Missing profile photos can be imported from Microsoft Graph, with an optional user refresh control.
- Can always fall back to regular username and password login.

*This is a work in progress, please feel free to contact me for help. This plugin is provided as-is, with no guarantees or assurances.*

In the typical flow:

1. User attempts to log in to the blog. At the sign in page, they are given a link to sign in with their Microsoft Entra ID work or school account (e.g. a Microsoft 365 account).
2. After signing in, the user is redirected back to the blog with an authorization code, which the plugin exchanges for a ID token, containing a minimal set of claims about the signed in user, and an access token, which can be used to query Microsoft Entra ID for additional details about the user.
3. The plugin uses the claims in the ID token to attempt to find a WordPress user with an email address or login name that matches the Microsoft Entra ID user.
4. If one is found, the user is authenticated in WordPress as that user account. If one is not found, the WordPress user will (optionally) be auto-provisioned on-the-fly.
5. (Optional) Membership to certain groups in Microsoft Entra ID can be mapped to roles in WordPress, and group membership can be used to restrict access.

## Getting Started

The following instructions will get you started. In this case, we will be configuring the plugin to use the user roles configured in WordPress.

### 1. Download and activate the plugin

This plugin is not yet registered in the WordPress plugin directory (coming soon!), but you can still install it manually:

1. Download the plugin using `git` or with the 'Download ZIP' link on the right.
2. Place the `aad-sso-wordpress` folder in your WordPress' plugin folder. Normally, this is `<your-blog>/wp-content/plugins`.
3. Activate the plugin in the WordPress admin console, under **Plugins** > **Installed Plugins**.

### 2. Register a Microsoft Entra ID application

With these steps, you will create a Microsoft Entra ID app registration. This will provide your WordPress site with an application identity in your organization's Microsoft Entra ID tenant.

1. Sign in to the [**Azure portal**](https://portal.azure.com), and ensure you are signed in to the directory which has the users you'd like to allow to sign in. (This will typically be your organization's directory.) You can view which directory you're signed in to (and switch directories if needed) by clicking on your username in the upper right-hand corner.

2. Navigate to the [**Microsoft Entra ID**](https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade) blade, and enter the [**App registrations**](https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/RegisteredApps) section.

    ![Clicking Microsoft Entra ID](https://user-images.githubusercontent.com/231140/29496874-6cf6f722-85dc-11e7-8898-89db80593ffc.png) <br />
    ![Clicking App registrations](https://user-images.githubusercontent.com/231140/29496884-9b3693ae-85dc-11e7-89a0-77e80979af23.png)

3. Choose **New registration**.

    ![Clicking New registration](https://user-images.githubusercontent.com/231140/66044424-cf882b80-e521-11e9-9f76-1e0d83ff8467.png)<br />

4. Fill out the initial form as follows:

    * **Name**: Enter your site's name. This will be displayed to users at the Microsoft Entra ID sign-in page, in the sign-in logs, in the list of Microsoft Entra apps, and in any consent prompt users may come across.

    * **Supported account types**: Choose "Accounts in this organizational directory" if you only expect users in your organization (including guest users who have been invited) to sign in to your app. Otherwise, choose "Accounts in any organizational directory" to allow users from _any_ Microsoft Entra ID tenant to sign in.
        
      > **Note**: This plugin does not yet support the third option, "Accounts in any organizational directory and personal Microsoft accounts".

    * **Redirect URI**: Leave the redirect URI type set to "Web", and provide a URL matching the format `https://<your blog url>/wp-login.php`, or whichever page your blog uses to sign in users.
    
      > **Note**: If you're not sure what to enter here, you can leave it empty for now and come back and update this (under Microsoft Entra ID > App registrations > Authentication) later. The plugin itself will tell you exactly what URL to use.

      > **Note**: The URL you provide must invoke the `authenticate` action. (By default, this will be `wp-login.php`.)

4. After clicking **Register**, enter the **API permissions** section. 

    ![API permissions](https://user-images.githubusercontent.com/231140/66045425-03fce700-e524-11e9-82ae-8772fa4e9724.png)

5. Verify that the delegated permission *User.Read* for Microsoft Graph is already be selected. This permission is all you need if you do not require mapping Microsoft Entra ID group membership to WordPress roles.

    ![User.Read delegated permission for Microsoft Graph](https://user-images.githubusercontent.com/231140/66046005-23484400-e525-11e9-9712-fed4c5273040.png)

   > **Note**: Group-to-role mapping checks membership for the signed-in user through `/me/checkMemberGroups`. The delegated *User.Read* permission is sufficient for this operation.
    
   > **Important**: Some permissions *require* administrator consent before it can be used, and in some organizations, administrator consent is required for *any* permission. A tenant administrator can use the **Grant admin consent** option to grant the permissions (i.e. consent) on behalf of all users in the organization.

6. Under **Certificates & secrets**, configure either a client secret or upload a public certificate. For certificate authentication, keep the matching PEM private key secure; it will be encrypted before the plugin stores it in WordPress.

    ![Creating a new secret key](https://user-images.githubusercontent.com/231140/66046096-52f74c00-e525-11e9-93ce-62581e097aaa.png)

8. Switch to the **Overview** section and keep the tab open, as you will need to copy some fields when configuring the plugin.

    ![App overview page](https://user-images.githubusercontent.com/231140/66046578-5b9c5200-e526-11e9-810f-027d31d99148.png)

### 3. Configure the plugin

Once the plugin is activated in WordPress (step 1), update your settings from the WordPress admin console under **Settings** > **Microsoft Entra ID**. Basic settings to include are:

<dl>
  <dt>Display name</dt>
  <dd>
    The display name of the organization, used in the link on the WordPress login page which will start the Microsoft Entra ID sign-in process.
  </dd>

  <dt>Client ID</dt>
  <dd>
    The Application ID. (Copy this from the Microsoft Entra ID app registration's **Overview** page.)
  </dd>
    
  <dt>Client Secret</dt>
  <dd>
    The client secret. (You copied this from the Microsoft Entra ID app registration's **Certificates & secrets** page.)
  </dd>

  <dt>Client authentication</dt>
  <dd>
    Choose <strong>Client secret</strong> to keep the existing behavior, or
    <strong>Certificate</strong> to authenticate the application with a signed client assertion.
  </dd>

  <dt>Client certificate and certificate private key</dt>
  <dd>
    For certificate authentication, paste the PEM public certificate uploaded to the Entra app
    registration and its matching PEM RSA private key. An encrypted private key may be protected
    with a passphrase; the passphrase is used only while saving and is never stored.
  </dd>

  <dt>Reply URL</dt>
  <dd>
    The URL that Microsoft Entra ID will send the user to after authenticating. This is usually the blog's sign-in page, which is the default value. Ensure that the reply URL configured in Microsoft Entra ID matches this value.
  </dd>
</dl>

### Certificate authentication and private-key storage

Certificate authentication replaces the `client_secret` parameter in the authorization-code token
exchange with a short-lived, RS256-signed `client_assertion`. The resulting access token remains a
delegated Microsoft Graph token for the signed-in user.

The settings page can generate a self-signed certificate locally:

1. Under **Settings > Microsoft Entra ID**, choose a validity period and select
   **Generate self-signed certificate**.
2. Download the public `.cer` file. The generated private key is never included in this download.
3. In the Entra app registration, open **Certificates & secrets > Certificates**, upload the
   downloaded public certificate, and wait for the credential to appear.
4. Return to WordPress, select **Certificate (private key JWT)**, and save.

Generation automatically selects certificate authentication and fills the stored public-certificate
field. Upload the downloaded public certificate to Entra immediately after generation. Replacing a
certificate that is already active can interrupt SSO until the replacement public certificate is
uploaded to Entra.

The private key is never rendered back into the settings page. Before it is written to the
`aadsso_settings` WordPress option, it is encrypted with AES-256-CBC and authenticated with
HMAC-SHA-256. Separate encryption and authentication keys are derived from secrets held outside the
database.

By default, the plugin derives its key from WordPress `AUTH_KEY` and `SECURE_AUTH_KEY`. You may
instead add a dedicated random value of at least 32 characters to `wp-config.php`:

```php
define( 'AADSSO_PRIVATE_KEY_ENCRYPTION_KEY', 'replace-with-a-long-random-value' );
```

Back up this value securely. Changing it (or changing the WordPress keys when the dedicated value
is not defined) makes the stored private key unreadable, and the certificate credentials will need
to be entered again.

### Microsoft Graph profile photo synchronization

Under **Settings > Microsoft Entra ID**, the **Enable Microsoft Graph profile photo sync** checkbox
controls photo synchronization across the site. When enabled, an Entra sign-in imports the signed-in
user's Microsoft Graph photo only when that user does not already have a local profile photo.

Users can force a refresh later with **Sync latest photo from Microsoft** on their own WordPress
profile. Sites using Ultimate Member also get an owner-only **Microsoft Photo** profile tab with the
same control. A forced refresh replaces the existing local photo.

The photo request uses the sign-in session's delegated access token and the Graph
`/me/photo/$value` endpoint. The existing delegated `User.Read` permission is sufficient, and the
access token is not stored. Photos use the same Ultimate Member directory and metadata keys as
`um-graph-photo-sync`, preserving existing local photos and avatar behavior when replacing that
plugin.

Turning the setting off disables automatic and user-initiated synchronization and hides the user
controls. It does not delete or stop displaying already imported photos. Deactivate
`um-graph-photo-sync` when enabling this functionality to avoid duplicate avatar filters and sync
attempts.

### Preserving settings during updates

Plugin configuration is preserved during activation, replacement, deactivation, and uninstall by
default. The plugin maintains a backup of the last non-empty `aadsso_settings` option and restores
it automatically if the primary option is unexpectedly removed. The explicit **Reset Settings**
control removes both copies.

For a deliberate data-removing uninstall, define the following in `wp-config.php` before deleting
the plugin:

```php
define( 'AADSSO_DELETE_DATA_ON_UNINSTALL', true );
```

### 4. (Optional) Set WordPress roles based on Microsoft Entra ID group membership

The Single Sign-on with Microsoft Entra ID plugin can be configured to set different WordPress roles based on the user's membership to a set of user-defined groups. This is a great way to control who has access to the site, and under what role.

This is also configured **Settings** > **Microsoft Entra ID** (from the WordPress admin console). The following fields should be included:

<dl>
  <dt>Enable Microsoft Entra ID group to WordPress role association</dt>
  <dd>
    Check this to enable Microsoft Entra ID group-based WordPress roles.
  </dd>

  <dt>Default WordPress role if not in Microsoft Entra ID group</dt>
  <dd>
    This is the default role that users will be assigned to if matching Microsoft Entra ID group to WordPress roles is enabled. If this is not set, and the user authenticating does not belong to any of the groups defined, they will be denied access.
  </dd>

  <dt>WordPress role to Microsoft Entra ID group map</dt>
  <dd>
    For each of the blog's WordPress roles, there is a field for the ObjectId of the Microsoft Entra ID group that will be associated with that role.
  </dd>
</dl>

> **Note**: Group-to-role mapping uses the signed-in user's delegated *User.Read* permission with Microsoft Graph. See step 5 of *Register a Microsoft Entra ID application*, above, for more details.

## Example settings

The different fields that can be defined in the settings JSON in **Settings** > **Microsoft Entra ID** are documented in [Settings.php](Settings.php). The following may give you an idea of the typical scenarios that may be encountered.

### Minimal

Users are matched by their email address in WordPress, and whichever role they have in WordPress is maintained.

| Setting | Example value
| --- | ---
| Display name | Contoso
| Client ID | 9054eff5-bfef-4cc5-82fd-8c35534e48f9
| Client Secret | NTY5MmE5Yj...
| Reply URL | https://www.example.com/blog/wp-login.php
| Field to match to UPN | Email Address

### Match on username alias

Users are matched by their login names in WordPress and the alias portion of their Microsoft Entra ID UserPrincipalName. Whichever role they have in WordPress is maintained.

| Setting | Example value
| --- | ---
| Display name | Contoso
| Client ID | 9054eff5-bfef-4cc5-82fd-8c35534e48f9
| Client Secret | NTY5MmE5Yj...
| Reply URL | https://www.example.com/blog/wp-login.php
| Field to match to UPN | Login Name
| Match on alias of the UPN | Yes

### Group membership-based roles, no default role

Users are matched by their login names in WordPress, and WordPress roles are dictated by membership to a given Microsoft Entra ID group. Access is denied if they are not members of any of these groups.

| Setting | Example value
| --- | ---
| Display name | Contoso
| Client ID | 9054eff5-bfef-4cc5-82fd-8c35534e48f9
| Client Secret | NTY5MmE5Yj...
| Reply URL | https://www.example.com/blog/wp-login.php
| Field to match to UPN | Login Name
| Enable Microsoft Entra ID group to WordPress role association | Yes
| Default WordPress role if not in Microsoft Entra ID group | (None, deny access)
| WordPress role to Microsoft Entra ID group map | <table><tr><td>Administrator</td><td>5d1915c4-2373-42ba-9796-7c092fa1dfc6</td></tr><tr><td>Editor</td><td>21c0f87b-4b65-48c1-9231-2f9295ef601c</td></tr><tr><td>Author</td><td>f5784693-11e5-4812-87db-8c6e51a18ffd</td></tr><tr><td>Contributor</td><td>780e055f-7e64-4e34-9ff3-012910b7e5ad</td></tr><tr><td>Subscriber</td><td>f1be9515-0aeb-458a-8c0a-30a03c1afb67</td></tr></table>

### Group membership-based roles with default role

Users are matched by their login names in WordPress, and WordPress roles are dictated by membership to a given Microsoft Entra ID group. If the user is not a part of any of these groups, they are assigned the *Author* role.

| Setting | Example value
| --- | ---
| Display name | Contoso
| Client ID | 9054eff5-bfef-4cc5-82fd-8c35534e48f9
| Client Secret | NTY5MmE5Yj...
| Reply URL | https://www.example.com/blog/wp-login.php
| Field to match to UPN | Login Name
| Enable Microsoft Entra ID group to WordPress role association | Yes
| Default WordPress role if not in Microsoft Entra ID group | Author
| WordPress role to Microsoft Entra ID group map | <table><tr><td>Administrator</td><td>5d1915c4-2373-42ba-9796-7c092fa1dfc6</td></tr><tr><td>Editor</td><td>21c0f87b-4b65-48c1-9231-2f9295ef601c</td></tr><tr><td>Author</td><td>f5784693-11e5-4812-87db-8c6e51a18ffd</td></tr><tr><td>Contributor</td><td>780e055f-7e64-4e34-9ff3-012910b7e5ad</td></tr><tr><td>Subscriber</td><td>f1be9515-0aeb-458a-8c0a-30a03c1afb67</td></tr></table>

### Group membership-based roles, default role, auto-provision

Users are matched by their email in WordPress, and WordPress roles are dictated by membership to a given Microsoft Entra ID group. If the user doesn't exist in WordPress yet, they will be auto-provisioned. If the user is not a part of any of these groups, they are assigned the *Subscriber* role.

| Setting | Example value
| --- | ---
| Display name | Contoso
| Client ID | 9054eff5-bfef-4cc5-82fd-8c35534e48f9
| Client Secret | NTY5MmE5Yj...
| Reply URL | https://www.example.com/blog/wp-login.php
| Field to match to UPN | Email Address
| Enable auto-provisioning | Yes
| Enable Microsoft Entra ID group to WordPress role association | Yes
| Default WordPress role if not in Microsoft Entra ID group | Subscriber
| WordPress role to Microsoft Entra ID group map | <table><tr><td>Administrator</td><td>5d1915c4-2373-42ba-9796-7c092fa1dfc6</td></tr><tr><td>Editor</td><td>21c0f87b-4b65-48c1-9231-2f9295ef601c</td></tr><tr><td>Author</td><td>f5784693-11e5-4812-87db-8c6e51a18ffd</td></tr><tr><td>Contributor</td><td>780e055f-7e64-4e34-9ff3-012910b7e5ad</td></tr><tr><td>Subscriber</td><td>f1be9515-0aeb-458a-8c0a-30a03c1afb67</td></tr></table>

## Groups

As described above, you can map Microsoft Entra ID groups to WordPress roles. Users who are members of the Microsoft Entra ID group will be granted the WordPress role(s) the groups were mapped to.

There are several ways Microsoft Entra ID groups can be created/managed. Some of them require the group owner/creator to be a tenant administrator, others not necessarily (depending on your organization's policy):

 * **Azure portal**. The Azure portal ([https://portal.azure.com](https://portal.azure.com)), under [Microsoft Entra ID](https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/Overview) > [Groups](https://portal.azure.com/#blade/Microsoft_AAD_IAM/GroupsManagementMenuBlade/AllGroups) > New group, allows admins and (optionally) users to create and manage groups.
 * **Access Panel**. The Microsoft Entra ID Access Panel ([https://myapps.microsoft.com](https://myapps.microsoft.com)) provides an interface for users to create and manage [groups](https://account.activedirectory.windowsazure.com/#/groups).
 * **Outlook**. The Outlook web interface ([https://outlook.office.com/](https://outlook.office.com/)) offers users the option to create Office 365 Groups. These groups are stored in Microsoft Entra ID and can be used with this plugin.
 * **Microsoft Teams**. Creating a team in Microsoft Teams ([https://teams.microsoft.com](https://teams.microsoft.com)) also results in an Office 365 Group getting created.
 * **Microsoft Graph PowerShell**. The [Microsoft Graph PowerShell module](https://learn.microsoft.com/en-us/powershell/microsoftgraph/get-started?view=graph-powershell-1.0) allows admins and (optionally) users to create and manage groups. (e.g. [New-MgGroup](https://learn.microsoft.com/en-us/powershell/module/microsoft.graph.groups/new-mggroup?view=graph-powershell-1.0), and [New-MgGroupMember](https://learn.microsoft.com/en-us/powershell/module/microsoft.graph.groups/new-mggroupmember?view=graph-powershell-1.0) cmdlets.)
 * **On-premises**. Many large organizations use Microsoft Entra Connect (formerly known as Azure AD Connect) to sync their on-premises AD to Microsoft Entra ID. This usually includes all on-premises AD groups and memberships. Once these groups are synced to Azrue AD, they can be used with this plugin.

## Advanced

### Refreshing the OpenID Connect configuration cache

Most of the OpenID Connect endpoints and configuration (e.g. signing keys, etc.) are obtained from the OpenID Connect configuration endpoint. These values are cached for one hour, but can always be forced to re-load by adding `aadsso_reload_openid_config=1` to the query string in the login page. (This shouldn't really be needed, but it has shown to be useful during development.)

### Bypassing automatic redirect to Microsoft Entra ID to prevent lockouts

If you've configured this plugin to automatically redirect to Microsoft Entra ID for sign-in, but something is misconfigured, you may find yourself locked out of your site's admin dashboard.

To log in to your site *without* automatically redirecting to Microsoft Entra ID (thus giving you an opportunity to enter a regular username and password), you can append `?aadsso_no_redirect=please` to the login URL. For example, if your login URL is `https://example.com/wp-login.php`, navigating to `https://example.com/wp-login.php?aadsso_no_redirect=please` will prevent any automatic redirects.
