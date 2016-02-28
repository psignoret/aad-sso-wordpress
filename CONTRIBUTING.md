# Contributing to Sign Sign-on with Azure Active Directory (for WordPress)
This WordPress plugin is actively maintained by contributors like you.  Contributing is easy.

## Getting Started
If you would like to contribute, but you don't have any ideas where to begin, check the **main** [Issue Tracker](https://github.com/psignoret/aad-sso-wordpress/issues).  Issues submitted on your fork's Issue tracker may not be seen.

## Reporting Bugs
- Make sure the bug doesn't already exist (search the [Issue Tracker](https://github.com/psignoret/aad-sso-wordpress/issues)).  If the bug has already been submitted, use the discussion thread on the issue to add any helpful supporting details.
- Include any errors you receive.  Please redact any information that might identify your Azure AD instances.
- Include any steps to reproduce the bug.
- Include the expected output or behavior.  It should be clear _why_ you think you found a bug.

## Setting up to contribute.
You'll need
- Your own development WordPress installation
- A GitHub account
- A cursory knowledge of Git.

### 1. Fork the repository.
1. From the [psignoret/aad-sso-wordpress](https://github.com/psignoret/aad-sso-wordpress) page, fork the repository to your account.
2. Navigate to the `wp-content/plugins` folder on your WordPress installation
3. Clone your fork using `git clone <your-github-clone-url>`

### 2. Commit changes to your repository
1. Make changes to the plugin.  All code changes should adhere to [WordPress PHP Coding Standards](https://make.wordpress.org/core/handbook/best-practices/coding-standards/php/)
2. To commit changed files, repeat this workflow for every file
 1. `git status` to see added files
 2. `git add <file>` to add the file to the commit
3. `git commit` to commit all changes to the local repository
4. `git push` to push the local repository to your GitHub fork.

### 3. Make a pull request
1. Navigate to your GitHub fork (https://github.com/[your github username]/aad-sso-wordpress)
2. Click the "New Pull Request" button
3. Confirm your pull request and click "Create pull request"
4. Pay attention to your email and notifications.  Your commit will be reviewed before it is merged with the master branch. **This is a collaborative process**

### 4. Things to keep in mind
1. All strings that are presented to the user should be be `i18n` ready.  The text domain is set in the `AADSSO` constant.  Please use the constant for all references to the text domain.  Please read [i18n For WordPress Developers](https://codex.wordpress.org/I18n_for_WordPress_Developers) and [How To Internationalize Your Plugin](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/)
2. All class members and functions should not include `aadsso` prefixes.  However, any data that will exist outside the scope should be namespaced `aadsso_` to prevent conflicts.
3. WordPress Coding Style should be adhered to.  Before your pull request, check for common Paren Spacing and Yoda Style errors.
