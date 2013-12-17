=== MU Extender ===
Contributors:      10up
Donate link:       http://thorsten-ott.de
Tags: 
Requires at least: 3.5.1
Tested up to:      3.8
Stable tag:        0.1.0
License:           GPLv2 or later
License URI:       http://www.gnu.org/licenses/gpl-2.0.html

MU-Plugins drop-in that provides finer grained control over plugins and settings

== Description ==

This plugin gives you more control over which extensions should be loaded.
You can setup configuration files for different environments (determined by `define('WP_ENV')`) and also enable deactivation on a per IP, USER, TIME basis.

Config files are in `configurations/`. The hierarchy of the inclusion is as follows:
 - wp-config.php
 - if exists load configurations/<WP_ENV>-<blog_id>-conf.php
 - if exists load configurations/<WP_ENV>-conf.php
 - if exists load configurations/default-conf.php
 - then require the extensions/<extension_dir>/extension-conf.php allowing to set default configuration
 - each setting should be set using `if ( defined() )` checks

Extensions that will be loaded need to be dropped in the `extensions/` folder and can have a config file that defines the supported features for this extension. The config file is called `extentions/<extension>/extension-conf.php`.

Make sure that these extensions do not rely on `register_activation_hook()` or `register_deactivation_hook` as these methods will never be called.

Each of the extensions can support the following features defined in WordPress README format.

Here an example:
```
<?php
/*
DEFINE_DEACTIVATION: TRUE
DASHBOARD_DEACTIVATION: TRUE
TIMED_DEACTIVATION: TRUE
USER_DEACTIVATION: TRUE
IP_DEACTIVATION: TRUE
*/
```

The feature priority is like this:
 - by default all extensions are active.
 - de-activation will be checked from top to bottom.
 - the first request for deactivation will deactivate the extension
 - DEFINE_DEACTIVATION
 - DASHBOARD_DEACTIVATION
 - IP_DEACTIVATION
 - USER_DEACTIVATION
 - TIMED_DEACTIVATION

== Installation ==

= Manual Installation =

1. Upload the entire `/mu-extender` directory and the mu-extender.php file to the `/wp-content/mu-plugins/` directory.

== TODO ==

- Clear / view other deactivation events for an extension
- create extension config file dummies for newly added extensions

== Note ==

When enabling the USER_DEACTIVATION feature it is necessary that the extension is loaded after the user is set. Therefore these extensions will be loaded on the `set_current_user` action hook.
