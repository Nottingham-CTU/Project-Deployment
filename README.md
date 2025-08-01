# Project Deployment

This module can be used to compare a project with a different *source* project (which can be on a
different REDCap server). The module will attempt to connect to the source server to obtain a
representation of all the project metadata and settings, referred to within the module as the
*project object*, and compare it with the target project. The project object can also be downloaded
as a JSON file.

The project object is based upon REDCap's built-in XML export, but there are a few key differences
which make it more useful for performing project comparisons, such as:

* The project object is in JSON format and presented with attributes on separate lines, which makes
  it easier to perform a comparison in file comparison software.
* Large data blobs are removed, files are represented by a hash value.
* Unique IDs which are not copied between projects are removed.
* Data Access Groups are always removed (unless DAG deployment is enabled).
* Field SQL is included on SQL fields.
* Form locking/e-signatures settings are included.
* External Module Settings are included.

The Project Deployment module is capable of automatically deploying updates to the following
features in a project:

* Data Dictionary
* Arms / Events / Event-Instrument mapping
* Form Display Logic
* Data Quality Rules *(new rules only)*
* Alerts *(not including automated survey invitations)*
* User Roles *(named roles only, not user-specific permissions or user-role assignments)*

The list of changes for deployment will list any outstanding changes and any which are not
automatically deployed can be set manually (the project object files can be compared in file
comparison software to identify the exact differences). The module will attempt to detect and
display errors which occur during change deployment but you should always verify that deployment has
applied all changes as expected.

Note that if there are changes for multiple features, the successful deployment for features later
in the list may depend upon successful deployment for features earlier in the list. In particular if
data dictionary changes have not been deployed, it may prevent successful deployment of changes for
other features if those changes reference fields or instruments which have been added to the data
dictionary.


## How to Set Up

This module is intended to be enabled on all instances of a REDCap project. For example, you might
have a development, testing, and live server. In this case, you would set up the module as
follows:

* On the development server, enable the module but do not configure a source project.
* On the testing server, enable the module and enter the base URL of the development REDCap server,
  and the project ID and/or API token of the development REDCap project.
* On the live server, enable the module and enter the base URL of the testing REDCap server, and the
  project ID and/or API token of the testing REDCap project.

The REDCap server base URL is the URL up to the slash (`/`) before the REDCap version number.

There are 3 ways in which the module can connect to a source project:

* **REDCap External Modules API:** The user on the source project must be set up with an API token
  and have the *External Modules API* user privilege. The API token must be entered in the module
  project settings on the target project, doing this indicates that this is the connection method
  which will be used. Entering a project ID in the module project settings is optional but if
  entered it will be checked against the project ID which is linked to the API token.
* **Login session:** When first loading the *deploy changes* page on the target project, you will be
  presented with a login prompt to provide your username/password for the source server (this
  account must have access to the source project). The session will then remain valid and you won't
  have to log in again until the session expires (on either server) or you logout from the target
  server.<br>
  To use this connection method, the project ID must be specified in the module project settings on
  the target server, and the API token field must be left blank.<br>
  *This connection method will not work if the source server uses an authentication method which has
  an intermediate step and/or does not rely on just a username and password. This includes source
  servers with two factor authentication enabled (unless the target server is exempt).*
* **Client-side connection:** This connection method is a fallback option for projects set up to use
  the *login session* method if the target server is unable to connect to the source server for any
  reason (e.g. due to firewall configuration). It must be enabled in the module system settings by
  a REDCap administrator before it can be used.<br>
  If a client-side connection is being performed, you will see a *fetch source data* button instead
  of the login prompt. You will need to make sure you are logged in to the source server in your
  browser, then you can click the button to retrieve the source data.


## Using the Module

Once enabled, this module will provide a *deploy changes to this project* link (or *download project
object* if a source project has not been configured). From this page you can download the project
object for comparison, and if a source project has been configured it will also attempt to perform a
comparison and list the project features where changes exist. If changes to a feature can be
deployed automatically, a checkbox will be shown next to the feature and you can choose the features
for which you wish to deploy changes.

If the Project Deployment module is unable to get data from the source server, this may be because:

* Your credentials (API token or username/password) are incorrect.
* You have entered an API token and a project ID in the module project settings, but the API token
  corresponds to a different project ID than the one entered.
* Your account on the source server does not have access to the project.
* The Project Deployment module has not been enabled on the source project.
* Your account on the source server has not been provided access to the Project Deployment module
  and the project object on the source project.
* The target server is unable to connect to the source server (e.g. due to firewall configuration)
  and client-side connections have not been enabled.

Administrators have the option of specifying an allowlist of source servers in the module system
settings. If any servers have been listed here, only those servers can be used as source servers.


## System Settings

REDCap administrators are able to configure the following module system settings:

* **External modules to exclude from deployment**<br>
  Specify the directory names of any external modules which should be excluded from comparisons by
  the Project Deployment module. Use this for modules which by their nature will have different
  settings on the source and target projects. The Project Deployment module itself is always
  excluded and doesn't need to be listed here.
* **Default source REDCap server to import from**<br>
  Specify the REDCap server to use if one is not specified in the project settings.
* **Source server allowlist**<br>
  If any REDCap servers are listed here, only those servers can be used as source servers.
* **Allow client-side connections to the source REDCap server**<br>
  This enables the client-side connection mode described above.
* **Project name matching**<br>
  Adjust how the module compares project names between source and target projects.
  * Full &mdash; Checks for an exact match.
  * Prefix &mdash; Checks that the name of one project starts with the name of the other.<br>
    e.g. *My Project* and *My Project (test)* will match.
  * Excluding regular expression match &mdash; Anything matching the regular expression will be
    removed before an exact match is performed.<br>
    e.g. With regex `[ ]*\((dev|test)\)$`, *My Project (dev)* and *My Project (test)* will match.
  * Disabled &mdash; Project name differences are ignored.
* **Enable Data Access Group deployment**<br>
  Include Data Access Groups in the project object.
