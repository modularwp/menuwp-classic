=== MenuWP Classic ===
Contributors: modularwp
Tags: menu, navigation, sync, etch
Requires at least: 6.8
Tested up to: 6.8
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin that enables the menu editor functionality and syncs WordPress menus to Etch loops.

== Description ==

MenuWP is a WordPress plugin that enables the menu editor functionality and automatically syncs WordPress navigation menus to Etch loops. The plugin ensures your WordPress menus are kept in sync with your Etch configuration, providing seamless integration between WordPress and Etch.

= Key Features =

* Enables WordPress menu editor functionality
* Automatically syncs WordPress menus to Etch loops
* Conflict detection and resolution
* Override option for syncing despite conflicts
* Real-time sync status notifications
* AJAX-powered menu synchronization

= How It Works =

The plugin automatically syncs your WordPress navigation menus to the `etch_loops` option field whenever menus are created, updated, or deleted. It intelligently detects conflicts when menu slugs already exist in Etch loops and provides options to override conflicts when needed.

Menu synchronization happens automatically when:
* A new menu is created
* An existing menu is updated
* Menu items are added, removed, or modified
* A menu is deleted

= Conflict Detection =

The plugin automatically detects when:
* A menu slug conflicts with an existing Etch loop entry
* An Etch loop entry has been modified externally
* Menu data is out of sync with Etch loops

When conflicts are detected, you can choose to allow syncing despite the conflict using the override checkbox.

== Developer Hooks ==

= `mdlr_menu_item_json` =

Filter each menu item as it is converted to the JSON structure that is sent to Etch.

Parameters:
* `$json_item` (array) The JSON-ready associative array for the current menu item.
* `$item` (WP_Post) The raw menu item object.

Example: send the menu item description

add_filter( 'mdlr_menu_item_json', function( $json_item, $item ) {
    if ( ! empty( $item->description ) ) {
        $json_item['description'] = $item->description;
    }
    return $json_item;
}, 10, 2 );

Example: send a custom field

add_filter( 'mdlr_menu_item_json', function( $json_item, $item ) {
    $icon = get_post_meta( $item->ID, '_menu_item_icon', true );
    if ( ! empty( $icon ) ) {
        $json_item['icon'] = $icon;
    }
    return $json_item;
}, 10, 2 );

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Navigate to Plugins > Add New
3. Search for "MenuWP"
4. Click "Install Now"
5. Click "Activate"

= Manual Installation =

1. Upload the `menuwp` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress

= After Installation =

1. Navigate to Appearance > Menus in your WordPress admin
2. Create or edit menus as usual
3. Menus will automatically sync to Etch loops
4. Watch for conflict notices if any issues arise

== Frequently Asked Questions ==

= Does this plugin require any special configuration? =

No, the plugin works automatically once activated. Simply use the WordPress menu editor as you normally would.

= What happens if there's a conflict? =

The plugin will detect conflicts and show a notice. You can choose to override the conflict using the checkbox provided in the admin notice.

= What permissions are required? =

Users need the `edit_theme_options` capability to sync menus. This is typically available to administrators and users with menu editing permissions.

= Can I disable syncing for specific menus? =

Currently, the plugin syncs all menus automatically. If you need to prevent syncing for a specific menu, you can remove it from the Etch loops manually or use the conflict detection to prevent automatic syncing.

== Screenshots ==

1. Menu sync notice in the WordPress menu editor
2. Conflict detection and override option
3. Real-time sync status notifications

== Changelog ==

= 0.4.0 =
* Fix double-encoding of HTML entities in menu item labels (e.g., "&amp;" becoming "&amp;amp;")
* Improve out-of-sync notice message to clarify possible causes

= 0.3.0 =
* Fix sync polling getting stuck indefinitely when sync fails silently
* Add animated ellipsis to "Syncing to Etch..." notice for visual feedback
* Add 15-second polling timeout with clear error message when sync stalls
* Show override checkbox after timeout so users can force-sync
* Always signal sync failure regardless of override state (catches null returns from early bail-outs)
* Catch PHP Errors in addition to Exceptions during sync (Throwable)
* Add sync_failed transient check to AJAX polling handler for faster failure detection

= 0.2.0 =
* Add `mdlr_menu_item_json` filter to extend the menu item JSON sent to Etch
* Add developer documentation and examples for the new filter

= 0.1.1 =
* Initial release
* Automatic menu synchronization to Etch loops
* Conflict detection and resolution
* Override option for syncing despite conflicts
* Admin notices for sync status
* AJAX-powered menu synchronization

== Upgrade Notice ==

= 0.4.0 =
* Fixes double-encoding of HTML entities in menu item labels.
* Improves the out-of-sync notice to clarify possible causes.

= 0.3.0 =
* Updates key naming to work with Etch v1.0.
* Fixes a bug where the sync polling could get stuck indefinitely.
* Adds a 15-second timeout with animated ellipsis and a clear error message.

= 0.2.0 =
Adds the `mdlr_menu_item_json` filter so developers can customize the menu item JSON sent to Etch.

= 0.1.1 =
Initial release of MenuWP. Install and activate to start syncing WordPress menus to Etch loops automatically.
