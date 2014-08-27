# Azure Active Directory Single Sign-on for WordPress

A WordPress plugin that allows organizations to use their Azure Active Directory
user accounts to sign in to WordPress. Organizations with Office 365 already have
Azure Active Directory and can use this plugin for all of their users.

- Security group membership can be used to
- Can always fall back to regular username and password login.

*This is a work in progress, please feel free to contact me for help.*

In the typical flow:

1. User attempts to access the admin section of the blog (`wp-admin`). At the sign in page, they are given a link to sign in with their Azure Active Directory organization account (e.g. an Office 365 account).
2. After signing in, the user is redirected back to the blog with a JSON Web Token (JWT), containing a minimal set of claims.
3. The plugin uses these claims to attempt to find a WordPress user with an email address or login name that matches the Azure Active Directory user.
4. If one is found, the user is authenticated in WordPress as that user.
5. (Optional) Membership to certain groups in Azure AD can be mapped to roles in WordPress.

## Getting Started

The following instructions will get you started. In this case, we will be configuring the plugin to use the user roles configured in WordPress.

### 1. Download the plugin

You can do this with `git` or with the 'Download ZIP' link on the right.

Place the `aad-sso-wordpress` folder in your WordPress' plugin folder. Normally, this is `<yourblog>/wp-content/plugins`.

### 2. Register an Azure Active Directory application

For these steps, you must have an Azure subscription with access to the Azure Active Directory tenant that you would like to use with your blog.

1. Sign in to the [Azure portal](https://manage.windowsazure.com), and navigate to the ACTIVE DIRECTORY section. Choose the directory (tenant) that you would like to use. This should be the directory containing the users and (optionally) groups that will have access to your WordPress blog.
3. Under the APPLICATIONS tab, click ADD to register a new application. Choose 'Add an application my organization is developing', and a recognizable name. Choose values for sign-in URL and App ID URL. The blog's URL is usually a good choice.
4. When the app is created, under the CONFIGURE tab, generate a key and copy the secret value (it will be visible once only, after you save).
5. Add a reply URL with the format: `https://<your blog url>/wp-login.php`. Note that this must be HTTPS (with the exception of `http://localhost/...`, which is acceptable for development use).

### 3. Configure the plugin

Configuration of the AADSSO plugin is currently done in a `Settings.json` file. This repo contains a `Settings.template.json` file that can be used as an example. Make a copy and rename it as `Settings.json`.

The minimal fields required are:

- `org_display_name` The display name of the organization, used only in the link in the login page.
- `client_id` The application's client ID (from the application configuration page)
- `client_secret` The client secret key (from the application configuration page)
- `field_to_match_to_upn` The WordPress field which will be used to match a UserPrincipalName (from AAD) to a WordPress user. Valid options are 'login', 'email' or 'slug'.


### 4. (Optional) Set WordPress roles based on Azure AD group membership

The AADSSO plugin can be configured to set different WordPress roles based on the user's membership to a set of user-defined groups. This is a great way to control who has access to the blog, and under what role.

The configuration is also done in `Settings.json`. The following fields should be included:

- `enable_aad_group_to_wp_role` Must be set to `true` to enable group-based roles.
- `aad_group_to_wp_role_map` Contains a key-value map of Azure Active Directory group object IDs (the keys) and WordPress roles (values). Valid values for roles are `'administrator'`, `'editor'`, `'author'`, `'contributor'`, `'subscriber'` and `''` (empty string).
- `default_wp_role` If a user signs in but is not a member of any groups defined in `aad_group_to_wp_role_map`, they are given this role in WordPress. If this is NULL (default) access will be denied.

## Example `Settings.json` files

The different fields that can be defined in `Settings.json` are documented in `Settings.php`. The following may give you an idea of the typical scenarios that may be encountered.

*Note: This will all eventually be replaced with a friendlier interface using WordPress settings.*

### Minimal

Users are matched by their email address in WordPress, and whichever role they have in WordPress is maintained.

	{
		"org_display_name": "Contoso",

		"client_id":     "9054eff5-bfef-4cc5-82fd-8c35534e48f9",
		"client_secret": "NTY5MmE5YjMwMGY2MWQ0NjU5MzYxNjdjNzE1OGNiZmY=",

		"field_to_match_to_upn": "email"
	}

### Groups membership-based roles (no default role)

Users are matched by their login names in WordPress, and WordPress roles are dictated by membership to a given Azure AD group.

	{
		"org_display_name": "Contoso",

		"client_id":     "9054eff5-bfef-4cc5-82fd-8c35534e48f9",
		"client_secret": "NTY5MmE5YjMwMGY2MWQ0NjU5MzYxNjdjNzE1OGNiZmY=",

		"field_to_match_to_upn": "login",

		"enable_aad_group_to_wp_role": true,
		"default_wp_role": null,
		"aad_group_to_wp_role_map": {
			"5d1915c4-2373-42ba-9796-7c092fa1dfc6": "administrator",
			"21c0f87b-4b65-48c1-9231-2f9295ef601c": "editor",
			"f5784693-11e5-4812-87db-8c6e51a18ffd": "author",
			"780e055f-7e64-4e34-9ff3-012910b7e5ad": "contributor",
			"f1be9515-0aeb-458a-8c0a-30a03c1afb67": "subscriber"
		}
	}

### Groups membership-based roles (with default role)

Users are matched by their login names in WordPress, and WordPress roles are dictated by membership to a given Azure AD group. If the user is not a part of any of these groups, they are assigned the `author` role.

	{
		"org_display_name": "Contoso",

		"client_id":     "9054eff5-bfef-4cc5-82fd-8c35534e48f9",
		"client_secret": "NTY5MmE5YjMwMGY2MWQ0NjU5MzYxNjdjNzE1OGNiZmY=",

		"field_to_match_to_upn": "login",

		"enable_aad_group_to_wp_role": true,
		"default_wp_role": "author",
		"aad_group_to_wp_role_map": {
			"5d1915c4-2373-42ba-9796-7c092fa1dfc6": "administrator",
			"21c0f87b-4b65-48c1-9231-2f9295ef601c": "editor",
			"f5784693-11e5-4812-87db-8c6e51a18ffd": "author",
			"780e055f-7e64-4e34-9ff3-012910b7e5ad": "contributor",
			"f1be9515-0aeb-458a-8c0a-30a03c1afb67": "subscriber"
		}
	}
