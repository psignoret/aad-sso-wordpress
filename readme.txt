=== Azure AD Single-Sign-On ===
Contributors: psignoret, blobaugh, welcher, jtsternberg, christianhennen, hajekj
Tags: azure-ad, sso, azure-active-directory
Requires at least: 4.1
Tested up to: 4.9.5
Requires PHP: 5.6
Stable tag: 0.6.4
License: MIT
License URI: https://github.com/psignoret/aad-sso-wordpress/blob/master/LICENSE.md

Sign in to WordPress using your organization's Azure Active Directory accounts (the same ones used to sign in to Office 365).

== Description ==
A WordPress plugin that allows organizations to use their Azure Active Directory user accounts to sign in to WordPress. Organizations with Office 365 already have Azure Active Directory (Azure AD) and can use this plugin for all of their users.

* Azure AD group membership can be used to determine access and role.
* New users can be registered on-the-fly based on their Azure AD profile.
* Can always fall back to regular username and password login.

This is a work in progress, please feel free to contact me for help. This plugin is provided as-is, with no guarantees or assurances.

In the typical flow:

1. User attempts to log in to the blog (wp-admin). At the sign in page, they are given a link to sign in with their Azure Active Directory organization account (e.g. an Office 365 account).
2. After signing in, the user is redirected back to the blog with an authorization code, which the plugin exchanges for a ID Token, containing a minimal set of claims about the signed in user, and an Access Token, which can be used to query Azure AD for additional details.
3. The plugin uses the claims in the ID Token to attempt to find a WordPress user with an email address or login name that matches the Azure AD user.
4. If one is found, the user is authenticated in WordPress as that user. If one is not found, the WordPress user will (optionally) be auto-provisioned on-the-fly.
5. (Optional) Membership to certain groups in Azure AD can be mapped to roles in WordPress, and group membership can be used to restrict access.

== Installation ==
### 1. Download and activate the plugin

Download and active the plugin from WordPress.org repository.

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
**Enable Azure AD group to WP role association** - Check this to enable Azure AD group-based WordPress roles.
**Default WordPress role if not in Azure AD group** - This is the default role that users will be assigned to if matching Azure AD group to WordPress roles is enabled. If this is not set, and the user authenticating does not belong to any of the groups defined, they will be denied access.
**WordPress role to Azure AD group map** - For each of the blog's WordPress roles, there is a field for the ObjectId of the Azure AD group that will be associated with that role.

== Frequently Asked Questions ==
For more configuration information and bug reports, please visit [plugin's repo on GitHub](https://github.com/psignoret/aad-sso-wordpress).