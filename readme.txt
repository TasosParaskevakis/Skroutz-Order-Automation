=== Skroutz Order Automator ===
Contributors: Tasos Paraskevakis
Tags: WooCommerce, webhook, automation, skroutz, orders
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Short Description ==
A plugin that handles incoming Skroutz webhooks to automate WooCommerce order creation.

== Description ==
Skroutz Order Automator is a plugin that handles incoming webhooks and automatically creates WooCommerce orders. The plugin provides an admin settings page – renamed "Skroutz Webhook Settings" – which lets you:
* Customize the webhook endpoint slug.
* Set an optional webhook secret for validation.
* Easily copy the complete webhook endpoint URL (including the secret if set) using a handy "Copy" button.

== Installation ==
1. Upload the entire `skroutz-order-automator` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin via the WordPress Admin > Plugins screen.
3. Navigate to **Skroutz Webhook Settings** in the admin menu.
4. Configure your webhook secret (if desired) and change the endpoint slug if needed.
5. Use the displayed webhook URL (with the copy button) to set up your Skroutz integration.

== Frequently Asked Questions ==
= How can I change the webhook endpoint URL? =
You can change the endpoint slug from the settings page. By entering a new slug, the plugin automatically updates the REST API route.

= How does the secret work? =
If you enter a secret in the settings, your webhook requests must include that secret as a query parameter (e.g. `?secret=yoursecret`). If the secret isn’t provided or doesn’t match, the webhook request will be rejected.

= How do I copy my webhook URL? =
A “Copy” button is displayed next to your webhook URL on the settings page. Clicking this button copies the full URL (including the secret if set) to your clipboard.

== Changelog ==
= 1.5 =
* Added the ability to customize the webhook endpoint slug.
* Reintroduced webhook secret verification.
* Renamed the settings page to "Skroutz Webhook Settings".
* Added a "Copy" button for easy copying of the webhook endpoint URL.
* Improved error handling in webhook data processing.

= 1.0 =
* Initial release with webhook handling and automated WooCommerce order creation.

== Upgrade Notice ==
= 1.5 =
This update introduces customizable endpoint slug and secret verification. Please update your Skroutz integration with the new webhook URL if you change these settings.

== License ==
This plugin is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.