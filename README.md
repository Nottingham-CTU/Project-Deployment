# Project Deployment

This module can be used to compare a project with a different *source* project (which can be on a
different REDCap server). The module will attempt to connect to the source server to obtain the
*project object* and compare it with the target project. The project object can also be downloaded
as a JSON file.

The project object is based upon REDCap's built-in XML export, but there are a few key differences
which make it more useful for performing project comparisons:

* The project object is in JSON format and presented with attributes on separate lines, which makes
  it easier to perform a comparison in file comparison software.
* Large data blobs are removed, files are represented by a hash value.
* Unique IDs which are not copied between projects are removed.
* Data Access Groups are always removed.
* External Module Settings are included.

The Project Deployment module is capable of automatically deploying updates to many of the features
in a project, but some settings may still require manual deployment. The list of changes for
deployment will list any outstanding changes and any which are not automatically deployed can be
set manually (the project object files can be compared in file comparison software to identify the
exact differences).


## How to Set Up

This module is intended to be enabled on all instances of a REDCap project. For example, you might
have a development, testing, and live server. In this case, you would set up the module as
follows:

* On the development server, enable the module but do not configure a source project.
* On the testing server, enable the module and enter the base URL of the development REDCap server
  and the project ID of the development REDCap project.
* On the live server, enable the module and enter the base URL of the testing REDCap server and the
  project ID of the testing REDCap project.

The REDCap server base URL is the URL up to the slash (`/`) before the REDCap version number.

If you are using two factor authentication, you must ensure that the target server is added as an
exempt IP address on the source server, otherwise the automated comparison features of this module
will not work properly (unless using client-side connections).


## Using the Module

Once enabled, this module will provide a *deploy changes to this project* page, which will always
provide the option to download the project object. If a source project has been configured, it will
also attempt to perform a comparison and list the project configuration areas where changes exist.

When you first load the deploy changes, you may be presented with a prompt to login to the source
server. Use your username and password for the source server (this account must have access to the
source project). The session will then remain valid and you won't have to log in again until the
session expires (on either server) or you logout from the target server.

If logging in to the source server does not work, this may be because:

* Your credentials are incorrect.
* Your account on the source server does not have access to the project.
* The Project Deployment module has not been enabled on the source project.
* Your account on the source server has not been provided access to the Project Deployment module
  and the project object on the source project.
* Two factor authentication is enabled on the source server and the target server is not exempt.
* The source server uses an authentication method which has an intermediate step and/or does not
  rely on just a username and password.

If you are having issues connecting to your source server, there is a client-side connections
option in the module system settings which can be enabled by an administrator. With this option
enabled, data from the source server can be fetched in-browser when deploying changes instead of via
a server-side request. If a client-side connection is being performed, you will see a *fetch source
data* button instead of the login prompt. If you are already authenticated to the source server in
your browser, the source data will then be retrieved when this button is clicked.

Administrators have the option of specifying an allowlist of source servers in the module system
settings. If any servers have been listed here, only those servers can be used as source servers.
