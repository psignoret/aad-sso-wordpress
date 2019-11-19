=== Sign Sign-on with Azure Active Directory (for WordPress) ===
Contributors: psignoret, blobaugh, welcher, jtsternberg, christianhennen, hajekj
Tags: azure-ad, sso, azure-active-directory, office-365
Requires at least: 4.1
Tested up to: 5.2.3
Requires PHP: 5.6
Stable tag: 0.7.0
License: MIT
License URI: https://github.com/psignoret/aad-sso-wordpress/blob/master/LICENSE.md

Sign in to WordPress using your organization's Azure Active Directory accounts (the same ones used to sign in to Office 365).

== Description ==

A WordPress plugin that allows organizations to use their Azure Active Directory
user accounts to sign in to WordPress. Organizations with Office 365 already have
Azure Active Directory (Azure AD) and can use this plugin for all of their users.

* Azure AD group membership can be used to determine access and role.
* New users can be registered on-the-fly based on their Azure AD profile.
* Can always fall back to regular username and password login.

This is a work in progress, please feel free to contact me for help. This plugin is provided as-is, with no guarantees or assurances.

In the typical flow:

1. User attempts to log in to the blog (`wp-admin`). At the sign in page, they are given a link to sign in with their Azure Active Directory work or school account (e.g. an Office 365 account).
2. After signing in, the user is redirected back to the blog with an authorization code, which the plugin exchanges for a ID token, containing a minimal set of claims about the signed in user, and an access token, which can be used to query Azure AD for additional details about the user.
3. The plugin uses the claims in the ID token to attempt to find a WordPress user with an email address or login name that matches the Azure AD user.
4. If one is found, the user is authenticated in WordPress as that user account. If one is not found, the WordPress user will (optionally) be auto-provisioned on-the-fly.
5. (Optional) Membership to certain groups in Azure AD can be mapped to roles in WordPress, and group membership can be used to restrict access.

== Installation ==

### 1. Download and activate the plugin

Download and active the plugin from WordPress.org repository.

### 2. Register an Azure Active Directory application

With these steps, you will create an Azure AD app registration. This will provide your WordPress site with an application identity in your organization's Azure AD tenant.

1. Sign in to the [**Azure portal**](https://portal.azure.com), and ensure you are signed in to the directory which has the users you'd like to allow to sign in. (This will typically be your organization's directory.) You can view which directory you're signed in to (and switch directories if needed) by clicking on your username in the upper right-hand corner.

2. Navigate to the [**Azure Active Directory**](https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade) blade, and enter the [**App registrations**](https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/RegisteredApps) section.

    ![Clicking Azure Active Directory](https://user-images.githubusercontent.com/231140/29496874-6cf6f722-85dc-11e7-8898-89db80593ffc.png) <br />
    ![Clicking App registrations](https://user-images.githubusercontent.com/231140/29496884-9b3693ae-85dc-11e7-89a0-77e80979af23.png)

3. Choose **New registration**.

    ![Clicking New registration](https://user-images.githubusercontent.com/231140/66044424-cf882b80-e521-11e9-9f76-1e0d83ff8467.png)<br />

4. Fill out the initial form as follows:

    * **Name**: Enter your site's name. This will be displayed to users at the Azure AD sign-in page, in the sign-in logs, and in any consent prompt users may come across.

    * **Supported account types**: Choose "Accounts in this organizational directory" if you only expect users from one organization to sign in to your app. Otherwise, choose "Accounts in any organizational directory" to allow users from _any_ Azure AD tenant to sign in.
        
      > **Note**: This plugin does not yet support the third option, "Accounts in any organizational directory and personal Microsoft accounts".

    * **Redirect URI**: Leave the redirect URI type set to "Web", and provide a URL matching the format `https://<your blog url>/wp-login.php`, or whichever page your blog uses to sign in users.
    
      > **Note**: If you're not sure what to enter here, you can leave it empty for now and come back and update this (under Azure AD > App registrations > Authentication) later. The plugin itself will tell you what URL to use.

      > **Note**: The page must invoke the `authenticate` action. (By default, this will be `wp-login.php`.) 

4. After clicking **Register**, enter the **API permissions** section. 

    ![API permissions](https://user-images.githubusercontent.com/231140/66045425-03fce700-e524-11e9-82ae-8772fa4e9724.png)

5. Verify that the delegated permission *User.Read* for Microsoft Graph is already be selected. This permission is all you need if you do not require mapping Azure AD group membership to WordPress roles. 

    ![User.Read delegated permission for Microsoft Graph](https://user-images.githubusercontent.com/231140/66046005-23484400-e525-11e9-9712-fed4c5273040.png)

   > **Note**: If you do wish to map Azure AD groups to WordPress roles, you must also select the delegated permission *Directory.Read.All* (click "Add a permission" > Microsoft Graph > Delegated > *Directory.Read.All*).
    
   > **Important**: Some permissions *require* administrator consent before it can be used, and in some organizations, administrator consent is required for *any* permission. A tenant administrator can use the **Grant admin consent** option to grant there permissions (i.e. consent) on behalf of all users in the organization.

6. Under **Certificates & secrets**, create a new client secret. Provide a description and choose a duration (I recommend no longer than two years). After clicking **Add**, the secret value will appear. Copy it, as this is the only time it will be available.

    ![Creating a new secret key](https://user-images.githubusercontent.com/231140/66046096-52f74c00-e525-11e9-93ce-62581e097aaa.png)

8. Switch to the **Overview** section and keep the tab open, as you will need to copy some fields when configuring the plugin.

    ![App overview page](https://user-images.githubusercontent.com/231140/66046578-5b9c5200-e526-11e9-810f-027d31d99148.png)

### 3. Configure the plugin

Once the plugin is activated, update your settings from the WordPress admin console under **Settings** > **Azure AD**. Basic settings to include are:
**Enable Azure AD group to WP role association** - Check this to enable Azure AD group-based WordPress roles.
**Default WordPress role if not in Azure AD group** - This is the default role that users will be assigned to if matching Azure AD group to WordPress roles is enabled. If this is not set, and the user authenticating does not belong to any of the groups defined, they will be denied access.
**WordPress role to Azure AD group map** - For each of the blog's WordPress roles, there is a field for the ObjectId of the Azure AD group that will be associated with that role.

== Frequently Asked Questions ==

For more configuration information and bug reports, please visit [plugin's repo on GitHub](https://github.com/psignoret/aad-sso-wordpress).