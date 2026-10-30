=== UA Marketplaces – Rozetka & Prom.ua XML Feeds for WooCommerce ===
Contributors: bandido, olegkovalyov
Tags: rozetka, prom
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Rozetka and Prom.ua XML feeds from your WooCommerce catalog: category mapping, variations, brands. Built in the background, the URL never changes.

== Description ==

Sell your WooCommerce products on Rozetka and Prom.ua. The plugin builds an XML feed for each marketplace, and the marketplace downloads it on its own schedule. You paste the feed URL into the marketplace cabinet once.

= 👉 Why shops choose UA Marketplaces =

* **Works on any hosting.** Feeds are built in the background in steps of about 20 seconds, so large catalogs never hit the server time limit. No need to raise `max_execution_time`. Runs with WP-Cron, a server cron or WP-CLI.
* **The feed never breaks.** A new file replaces the old one only when it is complete and valid. If a build fails, the marketplace keeps getting yesterday's feed, not an empty one.
* **Follows the marketplace rules.** No empty parameters, unique names for variations, picture limits (15 for Rozetka, 10 for Prom), dimensions and weight for Prom.
* **Rozetka categories by name.** Search the Rozetka category directory with full paths; the directory updates itself.
* **Native WordPress settings.** Everything for one marketplace on one tab, a feeds list with build progress, pause and delete.
* **Painless upgrade from 1.x.** Feed URLs and settings move over automatically; nothing changes in the marketplace cabinets.
* **Up to date.** HPOS compatible, PHP 7.4 to 8.5, WooCommerce 8 to 11.

= 📦 What goes into the feed =

* Simple and variable products: every variation is its own offer, grouped under the product, with its own picture.
* Main picture and gallery, description, attributes as parameters, stock, availability, SKU.
* Brand from WooCommerce Brands, Perfect Brands for WooCommerce, a product attribute, a custom field, or one brand for the whole shop.
* Rozetka: products from the categories you mapped (and their subcategories); `name_ua` / `description_ua` tags if your texts are in Ukrainian.
* Prom.ua: every category with products, dimensions and weight, several attribute values joined with "|", one language with Polylang.

= 🛒 On the product =

* Leave a product out of Rozetka or out of Prom separately.
* Its own name for each marketplace, also in Quick Edit.
* Its own picture and description for Rozetka, and a picture for each variation.

= ⭐ Free or Pro =

The free plugin gives you both marketplaces with a daily update and the regular price.

[UA Marketplaces Pro](https://morkva.co.ua/shop/ua-marketplaces-pro/) adds:

* updates every hour, every 4 or 12 hours;
* sale price with the old price crossed out, Rozetka promo price;
* a price formula, with rounding for Prom;
* variation photo galleries (Woo Variation Gallery, Variation Images Gallery, Woodmart);
* a description template and the SKU or ID in the name;
* your own offer ID for a variation and a Rozetka category for a single product;
* Prom keywords, Prom catalog category and two languages with Polylang or WPML;
* your own folder and file name for the feed.

= 🛠️ For developers =

WP-CLI:

* `wp mrkv-uamp build <rozetka|promua|all> [--restart]` builds a feed in one process.
* `wp mrkv-uamp status` shows every feed's state.

Filters: `mrkv_uamp_offer` (one offer before it is written), `mrkv_uamp_categories`, `mrkv_uamp_mapper`, `mrkv_uamp_batch_budget`, `mrkv_uamp_settings_sections`. Action: `mrkv_uamp_feed_built`.

= 🔗 External services =

The plugin connects to two services run by morkva, the plugin's author:

1. **Rozetka category directory** at `https://api.morkva.co.ua/morkvafiles/woo-rozetka/categories.json` (and `categories.meta.json` next to it). It is downloaded once a week while you have a Rozetka feed, and when you click "Refresh" on the Rozetka tab. The request is a plain download: no data about your shop, products or customers is sent, apart from the standard WordPress user agent, which includes your site address.
2. **List of other morkva plugins** in the sidebar of the plugin's settings page, from `https://morkva.co.ua/wp-json/pluginManagementPro/v2`. It is requested when you open that page, at most every 12 hours. Nothing is sent apart from the standard WordPress user agent.

The plugin sends nothing to Rozetka or Prom.ua: the marketplaces download the XML file from your site.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Go to WooCommerce → UA Marketplace and add a feed for Rozetka, Prom.ua or both.
3. On the marketplace tab, fill in the shop name and brand. For Rozetka, map your categories to Rozetka categories.
4. Click "Build now" on the Feeds tab, copy the feed URL and paste it into the marketplace cabinet.

== Frequently Asked Questions ==

= Where do I find the feed URL? =

WooCommerce → UA Marketplace → Feeds, the "Copy" button next to the feed. Paste it into the Rozetka or Prom.ua cabinet once; it does not change.

= I updated from 1.x. Do I need to change anything in the marketplace cabinets? =

No. Feed URLs, category mapping and settings move over automatically, and the feeds are rebuilt in the background right after the update.

= The feed is not built, or I see a timeout or memory error =

Version 2.0 builds feeds in short background steps with the WooCommerce Action Scheduler. If WP-Cron is disabled on your site, set up a server cron for `wp-cron.php`, or run `wp mrkv-uamp build all` with WP-CLI. Errors are listed in WooCommerce → Status → Logs.

= Why is a product missing from the Rozetka feed? =

The Rozetka feed contains published simple and variable products from the categories you mapped to Rozetka (and their subcategories), unless "Leave out of the feed" is ticked on the product.

= How do I set the Rozetka category for a product? =

Map its WooCommerce category to a Rozetka category on the Rozetka tab: search by name or type the Rozetka category ID. A separate Rozetka category for a single product is available in Pro.

= How many photos go into the feed? =

The main picture and the product gallery: up to 15 for Rozetka and up to 10 for Prom.ua, the marketplaces' limits. A variation's own picture replaces the main one.

= How often is the feed updated? =

Once a day in the free plugin; every hour, 4 or 12 hours in Pro. "Build now" rebuilds it at any time.

= Rozetka rejects products without a brand =

Choose where the brand comes from on the Rozetka tab, or enter one brand for all products.

= Does it work with Polylang or WPML? =

With Polylang the Prom.ua feed is built in the site language. Two languages (`name_ua` and `description_ua`) are in Pro.

== Changelog ==

= 2.0.0 =
* Rewritten from scratch. Feeds are built in the background in short steps, so large catalogs no longer hit server time limits.
* The live feed is replaced only by a complete, valid file: marketplaces never see a broken or empty feed.
* New settings page under WooCommerce: one tab per marketplace, a feeds list with build progress, pause and delete.
* Rozetka category search by name with full paths; the category directory updates itself.
* Feeds follow the current Rozetka and Prom.ua requirements: no empty parameters, unique variation names, picture limits, Prom dimensions.
* Leave a product out of Rozetka and Prom.ua separately.
* Upgrading from 1.x keeps your feed URLs and settings.
* Ukrainian translation, WP-CLI commands. Requires WordPress 6.5 and PHP 7.4.

= 1.4.20 =
* Fix: the final step of the background build.

= 1.4.19 =
* Product name format check.

= 1.4.18 =
* Product names are decoded.

= 1.4.17 =
* WooCommerce Brands support.

= 1.4.16 =
* Prom: weight unit added to the weight parameter. Freemius SDK removed.

= 1.4.15 =
* Fix: feed creation error.

= 1.4.14 =
* Rozetka: SKU field.

= 1.4.13 =
* Tested with WordPress 6.9.

= 1.4.12 =
* Fixes: settings output, attributes.

= 1.4.11 =
* Tested with WordPress 6.8 and WooCommerce 9.8.

= 1.4.10 =
* Tested with WordPress 6.7 and WooCommerce 9.5.

= 1.4.9 =
* Prom: vendorCode fix.

= 1.4.8 =
* Tested with WordPress 6.6.

= 1.4.7 =
* Tested with WooCommerce 8.8.

= 1.4.6 =
* Faster database queries.

= 1.4.4 =
* Prom: quantity_in_stock tag, new availability rule. Tested with WooCommerce 6.9 and WordPress 6.0.

= 1.4.2 =
* Prom: background build mode, vendorCode instead of sku, Polylang support.

= 1.0 – 1.3 =
* Rozetka feed, Prom.ua feed (1.3), category mapping, scheduled updates, price coefficient, Quick Edit fields.

== Upgrade Notice ==

= 2.0.0 =
Major update. Feed URLs and settings are kept; feeds are rebuilt in the background after the update. Requires WordPress 6.5+ and PHP 7.4+.
