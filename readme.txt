=== Sign Sign-on with Microsoft Entra ID (for WordPress) ===
Contributors: psignoret, blobaugh, welcher, jtsternberg, christianhennen, hajekj
Tags: entra-id, azure-ad, sso, azure-active-directory, office-365, microsoft-entra, entra
Requires at least: 4.1
Tested up to: 5.2.3
Requires PHP: 5.6
Stable tag: 0.11.5
License: MIT
License URI: https://github.com/psignoret/aad-sso-wordpress/blob/master/LICENSE.md

Sign in to WordPress using your organization's Microsoft Entra ID accounts (the same ones used to sign in to Office 365).

Uses Microsoft identity platform v2 endpoints and is compatible with PHP 5.6 through PHP 8.5.

== Description ==

A WordPress plugin that allows organizations to use their Microsoft Entra ID (formerly known as Azure Active Directory) user accounts to sign in to WordPress. Organizations with Office 365 already have Microsoft Entra ID (Microsoft Entra ID) and can use this plugin for all of their users.

* Microsoft Entra ID group membership can be used to determine access and role.
* New users can be registered on-the-fly based on their Microsoft Entra ID profile.
* Missing profile photos can be imported from Microsoft Graph, with an optional user refresh control.
* Can always fall back to regular username and password login.

This is a work in progress, please feel free to contact me for help. This plugin is provided as-is, with no guarantees or assurances.

In the typical flow:

1. User attempts to log in to the blog. At the sign in page, they are given a link to sign in with their Microsoft Entra ID work or school account (e.g. a Microsoft 365 account).
2. After signing in, the user is redirected back to the blog with an authorization code, which the plugin exchanges for a ID token, containing a minimal set of claims about the signed in user, and an access token, which can be used to query Microsoft Entra ID for additional details about the user.
3. The plugin uses the claims in the ID token to attempt to find a WordPress user with an email address or login name that matches the Microsoft Entra ID user.
4. If one is found, the user is authenticated in WordPress as that user account. If one is not found, the WordPress user will (optionally) be auto-provisioned on-the-fly.
5. (Optional) Membership to certain groups in Microsoft Entra ID can be mapped to roles in WordPress, and group membership can be used to restrict access.

== Installation ==

### 1. Download and activate the plugin

Download and active the plugin from WordPress.org repository.

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

For certificate authentication, select **Certificate (private key JWT)** and paste the PEM public
certificate uploaded to the Entra app registration together with its matching PEM RSA private key.
The private key is encrypted and authenticated before database storage, using key material from
`wp-config.php`. Its optional passphrase is used only while saving and is never stored. A dedicated
key can be configured with
`define( 'AADSSO_PRIVATE_KEY_ENCRYPTION_KEY', 'a-long-random-value-at-least-32-characters' );`.
Keep that value backed up: changing it makes the stored private key unreadable.

The plugin can also generate a 3072-bit RSA self-signed certificate on the settings page. Download
the resulting public `.cer`, upload it under the Entra app registration's **Certificates &
secrets > Certificates** page immediately after generation. Generation automatically selects
certificate authentication and fills the stored public-certificate field. The generated private
key is encrypted in the database and is never displayed or included in the download.

### Microsoft Graph profile photo synchronization

Enable **Microsoft Graph profile photo sync** under **Settings > Microsoft Entra ID** to import a
user's Microsoft photo when they sign in and do not already have a local profile photo. The same
delegated `User.Read` permission used by sign-in is sufficient; access tokens are not stored.

Users can select **Sync latest photo from Microsoft** on their own WordPress profile or in the
owner-only **Microsoft Photo** Ultimate Member profile tab. A user-requested sync replaces the
existing local photo so a newer Microsoft photo can be imported.

The setting is a site-wide switch. Turning it off prevents automatic imports and removes the
user-initiated sync controls, but does not delete or stop displaying photos that have already been
stored locally. Photo files and metadata are compatible with the `um-graph-photo-sync` plugin, so
that plugin should be deactivated when this version is enabled.

**Enable Microsoft Entra ID group to WordPress role association** - Check this to enable Microsoft Entra ID group-based WordPress roles.
**Default WordPress role if not in Microsoft Entra ID group** - This is the default role that users will be assigned to if matching Microsoft Entra ID group to WordPress roles is enabled. If this is not set, and the user authenticating does not belong to any of the groups defined, they will be denied access.
**WordPress role to Microsoft Entra ID group map** - For each of the blog's WordPress roles, there is a field for the ObjectId of the Microsoft Entra ID group that will be associated with that role.

== Frequently Asked Questions ==

For more configuration information and bug reports, please visit [plugin's repo on GitHub](https://github.com/psignoret/aad-sso-wordpress).
