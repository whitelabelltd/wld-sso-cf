# Cloudflare Zero Trust SSO for WordPress
![Support Level](https://img.shields.io/badge/support-active-green.svg) ![WordPress tested up to version](https://img.shields.io/badge/WordPress-v6.4%20tested-success.svg) ![PHP tested with version](https://img.shields.io/badge/PHP-v8.2%20tested-success.svg)

> Allows Single Sign On (SSO) using Cloudflare Zero Trust. This plugin is not meant for general-distribution, but is available for public perusal.

## Requirements

* PHP 8.0+
* [WordPress](http://wordpress.org) 6.3+

## Updates

Updates use the built-in WordPress update system to pull from GitHub releases.

## Cloudflare Access
You will need to add the WordPress site as a [self-hosted SaaS app](https://developers.cloudflare.com/cloudflare-one/applications/configure-apps/self-hosted-apps/) on Cloudflare Zero Trust.

When setup using the App Launcher, visiting the wp-login.php page will require the user to be logged into Cloudflare (CF) Access (it will redirect them otherwise to login using CF Access).

**Warning:** Setting up CF Access using the app launcher will block any other users wanting to login to WordPress that cannot login to CF Access.

### Cloudflare App Launcher
The Path should point to 'wp-login.php', with the subdomain and domain matching your site. Ensure the site is proxied (orange cloud) in your Cloudflare DNS.

## How it Works
When a Cloudflare Team Domain is added, WordPress will auto get the JWT Certificates from Cloudflare on a regular basis (once a day using WP-Cron). This allows the plugin to check the request from Cloudflare Access for SSO is valid as well as determine the user.

If a WP User matches the one validated by Cloudflare, it logs the user in to WordPress. Users are matched using the Email address. Should no WP User exist with the email address, the login is denied, unless the option is enabled for creating a new WP User.

You can login by going to the Cloudflare Zero Trust App Dashboard and clicking on your app or just visit the WP-Login Page, it will then login the relevant user automatically.

## Hosting Support
Some hosting providers handle HTTP headers differently then others, the providers listed below are supported.
The plugin has filters that can be used to add support for most hosting environments.

- Cloudways
- Flywheel
- SpinupWP
- Rocket.Net

### Filters
- `wld_sso_cf_override_on_cf_network_check` - (bool) default value `FALSE` - Allows overriding the check if the site is behind Cloudflare.
- `wld_sso_cf_network_check_ip_cf_header` - (string) default value `HTTP_CF_CONNECTING_IP` - Which HTTP Header should we be using to get the IP that should match Cloudflare.
- `wld_sso_cf_network_check_ip_user_header` - (string) default value `REMOTE_ADDR` - Which HTTP Header should we be using to get the IP that should match the user.

## Changelog

A complete listing of all notable changes to this plugin are documented in [`CHANGELOG.md`](../master/CHANGELOG.md).



