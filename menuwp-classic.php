<?php
/**
 * Plugin Name: MenuWP Classic
 * Plugin URI: https://menuwp.com
 * Description: A WordPress plugin that enables the menu editor functionality.
 * Version: 0.5.0
 * Author: ModularWP
 * Author URI: https://modularwp.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: menuwp
 * Domain Path: /languages
 *
 * @package Menu
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Menu Plugin Class
 *
 * This class handles the initialization and functionality of the Menu plugin.
 * It ensures the WordPress menu editor is enabled and provides related functionality.
 *
 * @since 0.1.0
 */
class MDLR_Menu {

	/**
	 * Queued menus for syncing at shutdown.
	 *
	 * Stores menu IDs, slugs, and their old menu items JSON for comparison.
	 * Format: array( $menu_id => array( 'menu_id' => int, 'menu_slug' => string, 'old_menu_json' => array ) )
	 *
	 * @since 0.1.0
	 * @var array
	 */
	private static $queued_menus = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 *
	 * @since 0.1.0
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'enable_menu_editor' ) );

		// Menu sync hooks.
		// Note: wp_update_nav_menu fires when menu object changes, but NOT when items are added/removed.
		// wp_update_nav_menu_item fires when individual menu items are saved.
		// We'll mark menus for syncing and do it at shutdown after items are saved.
		add_action( 'wp_create_nav_menu', array( $this, 'queue_menu_sync' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'queue_menu_sync' ) );
		add_action( 'wp_update_nav_menu_item', array( $this, 'queue_menu_sync_from_item' ), 10, 3 );
		add_action( 'wp_delete_nav_menu_item', array( $this, 'queue_menu_sync_from_item' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'sync_queued_menus' ), 999 );
		add_action( 'delete_term', array( $this, 'handle_term_deletion' ), 10, 4 );

		// Check menu sync status when editor loads.
		add_action( 'load-nav-menus.php', array( $this, 'check_menu_sync_status' ) );

		// Display admin notices for sync warnings.
		add_action( 'admin_notices', array( $this, 'display_sync_notice' ) );

		// AJAX handler for override checkbox.
		add_action( 'wp_ajax_mdlr_menu_set_sync_override', array( $this, 'handle_sync_override_ajax' ) );

		// AJAX handler to check if sync was completed.
		add_action( 'wp_ajax_mdlr_menu_check_sync_completed', array( $this, 'handle_check_sync_completed_ajax' ) );

		// Enqueue admin scripts for checkbox handling.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Enable WordPress menu editor functionality.
	 *
	 * This method ensures that the menu editor is available in the WordPress admin.
	 * It adds theme support for menus if not already present.
	 *
	 * @since 0.1.0
	 */
	public function enable_menu_editor() {
		// Add theme support for menus if not already present.
		// This enables the menu editor in WordPress admin.
		if ( ! current_theme_supports( 'menus' ) ) {
			add_theme_support( 'menus' );
		}
	}

	/**
	 * Queue a menu for syncing.
	 *
	 * Since wp_update_nav_menu fires before menu items are saved,
	 * we queue the menu ID to be synced at shutdown after items are saved.
	 * We also capture the OLD menu items here so we can compare them
	 * with etch_loops at shutdown to detect external modifications.
	 *
	 * @since 0.1.0
	 * @param int $menu_id The menu ID.
	 */
	public function queue_menu_sync( $menu_id ) {
		// Only queue if not already queued.
		if ( isset( self::$queued_menus[ $menu_id ] ) ) {
			return;
		}

		// Capture OLD menu items before they're modified.
		// This allows us to compare them with etch_loops at shutdown
		// to detect if etch_loops was modified externally.
		$old_menu_items = wp_get_nav_menu_items( $menu_id );
		$old_menu_json = array();
		if ( $old_menu_items && ! is_wp_error( $old_menu_items ) && ! empty( $old_menu_items ) ) {
			$old_menu_json = $this->convert_menu_items_to_json( $old_menu_items );
		}

		// Get menu object to retrieve slug for transient and storage.
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return;
		}

		// Store in static property so it's available at shutdown.
		// Cache the slug to avoid fetching menu object again at shutdown.
		self::$queued_menus[ $menu_id ] = array(
			'menu_id'       => $menu_id,
			'menu_slug'     => $menu->slug,
			'old_menu_json' => $old_menu_json,
		);

		// Set sync_in_progress transient for all queued menus.
		// Conflict checking will happen at shutdown during actual sync.
		$sync_in_progress_key = 'mdlr_menu_sync_in_progress_' . $menu->slug;
		set_transient( $sync_in_progress_key, true, MINUTE_IN_SECONDS );
	}

	/**
	 * Queue a menu for syncing when a menu item is updated or deleted.
	 *
	 * This catches cases where menu items are added/removed/modified
	 * but the menu object itself doesn't change (so wp_update_nav_menu doesn't fire).
	 *
	 * @since 0.1.0
	 * @param int   $menu_id         The menu ID.
	 * @param int   $menu_item_db_id The menu item database ID.
	 * @param array $args            Menu item arguments.
	 */
	public function queue_menu_sync_from_item( $menu_id, $menu_item_db_id = 0, $args = array() ) {
		// Queue the menu that this item belongs to.
		// Note: wp_update_nav_menu_item fires when items are being saved,
		// so we might not capture truly old items. But if the menu was already queued
		// from wp_update_nav_menu (which fires before items are saved), we'll use those old items.
		// If not already queued, capture old items now (they might already be partially changed).
		$this->queue_menu_sync( $menu_id );
	}

	/**
	 * Sync all queued menus at shutdown (after menu items are saved).
	 *
	 * The shutdown hook fires on both full page loads AND AJAX requests,
	 * so this works for both menu save scenarios.
	 *
	 * @since 0.1.0
	 */
	public function sync_queued_menus() {
		// Exit if not in admin or REST API request.
		if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Exit if no menus are queued.
		if ( empty( self::$queued_menus ) ) {
			return;
		}

		// Exit if user doesn't have permission to edit theme options.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		// Shutdown fires at the very end of the request (both full page loads and AJAX).
		// By this point, menu items have been saved to the database.
		// Sync each queued menu.
		foreach ( self::$queued_menus as $queued_menu ) {
			$menu_id = $queued_menu['menu_id'];
			$menu_slug = isset( $queued_menu['menu_slug'] ) ? $queued_menu['menu_slug'] : null;
			$old_menu_json = isset( $queued_menu['old_menu_json'] ) ? $queued_menu['old_menu_json'] : null;

			// If no slug cached, skip this menu (shouldn't happen, but safety check).
			if ( ! $menu_slug ) {
				continue;
			}

			try {
				$override_key = 'mdlr_menu_sync_override_' . $menu_slug;
				$override_enabled = get_transient( $override_key );
				$override_enabled = ( true === $override_enabled || 1 === (int) $override_enabled || '1' === $override_enabled );

				$sync_result = $this->sync_menu_to_etch_loops( $menu_id, $old_menu_json );
				
				// Clear sync in progress transient - sync completed (successfully or was prevented).
				$sync_in_progress_key = 'mdlr_menu_sync_in_progress_' . $menu_slug;
				delete_transient( $sync_in_progress_key );

				// If sync didn't succeed (returned anything other than true), mark as failed.
				if ( true !== $sync_result ) {
					$sync_failed_key = 'mdlr_menu_sync_failed_' . $menu_slug;
					set_transient( $sync_failed_key, true, MINUTE_IN_SECONDS );
				}
			} catch ( \Throwable $e ) {
				// Sync failed with exception - mark as failed.
				// Use cached slug for transient cleanup.
				$sync_in_progress_key = 'mdlr_menu_sync_in_progress_' . $menu_slug;
				delete_transient( $sync_in_progress_key );

				$sync_failed_key = 'mdlr_menu_sync_failed_' . $menu_slug;
				set_transient( $sync_failed_key, true, MINUTE_IN_SECONDS );
			}
		}

		// Clear the queue after syncing.
		self::$queued_menus = array();
	}

	/**
	 * Sync WordPress menu to etch_loops option field.
	 *
	 * @since 0.1.0
	 * @param int   $menu_id       The menu ID.
	 * @param array $old_menu_json Optional. Old menu items JSON (captured before changes).
	 */
	public function sync_menu_to_etch_loops( $menu_id, $old_menu_json = null ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return;
		}

		// Check if etch_loops option exists (Etch plugin is active).
		$etch_loops = get_option( 'etch_loops' );
		if ( false === $etch_loops ) {
			// Etch plugin is not active - set notice and don't sync.
			$notice_key = 'mdlr_menu_sync_notice_' . $menu->slug;
			set_transient( $notice_key, 'etch_not_active', HOUR_IN_SECONDS );
			return false;
		}

		// Check if user has enabled override for this menu.
		$override_key = 'mdlr_menu_sync_override_' . $menu->slug;
		$override_enabled = get_transient( $override_key );
		
		// Normalize override value (transients may store as 1/true).
		$override_enabled = ( true === $override_enabled || 1 === (int) $override_enabled || '1' === $override_enabled );

		// Check if there's a conflict or existing entry.
		$found_entry = $this->find_etch_loop_entry( $etch_loops, $menu->slug );

		// If there's a conflict (key field matches but array key is different), check for override.
		if ( false !== $found_entry && $found_entry['array_key'] !== $menu->slug ) {
			// Conflict exists: 'key' field matches but array key is different.
			// Check if user has enabled override.
			if ( ! $override_enabled ) {
				// No override - don't sync.
				return false;
			}
			// Override enabled - skip conflict check and proceed to sync.
		}

		// Check sync status from transient.
		// The transient should be set when the menu editor opens, but might not exist
		// during AJAX saves if the page wasn't loaded first.
		$transient_key = 'mdlr_menu_sync_enabled_' . $menu->slug;
		$sync_enabled = get_transient( $transient_key );

		// Determine if it's safe to sync by checking if etch_loops was modified externally.
		// We need to compare OLD menu items (before user's changes) with etch_loops.
		// Skip ALL checks if override is enabled - user explicitly wants to sync.
		if ( ! $override_enabled ) {
			if ( true === $sync_enabled ) {
				// Transient says sync is safe - items matched at page load.
				// Safe to sync user's changes.
			} elseif ( false === $sync_enabled ) {
				// Transient exists and is explicitly false - items were out of sync at page load.
				// We still need to check if they match using OLD menu items.
				if ( null === $old_menu_json && false !== $found_entry ) {
					// Don't have old menu items, can't verify - don't sync.
					return false;
				}
			} else {
				// Transient doesn't exist (likely AJAX save without page load).
				// We need to check using OLD menu items if we have them.
				if ( null === $old_menu_json ) {
					// Don't have old menu items to compare - can't verify if etch_loops was modified.
					// Only sync if no entry exists (safe first-time sync).
					if ( false !== $found_entry ) {
						// Entry exists but we can't verify if it was modified externally - don't sync.
						return false;
					}
				}
			}
		}

		// If we have old menu items, compare them with etch_loops to detect external modifications.
		// Skip this check if override is enabled.
		if ( null !== $old_menu_json && false !== $found_entry && ! $override_enabled ) {
			$etch_json = isset( $found_entry['entry']['config']['data'] ) ? $found_entry['entry']['config']['data'] : array();

			// Compare OLD menu items with etch_loops data.
			$old_menu_serialized = maybe_serialize( $old_menu_json );
			$etch_serialized = maybe_serialize( $etch_json );

			// If they don't match, etch_loops was modified externally - don't sync.
			if ( $old_menu_serialized !== $etch_serialized ) {
				return false;
			}
			// If they match, etch_loops wasn't modified externally - safe to sync user's changes.
		}

		// Get menu items - use default arguments for better compatibility.
		$menu_items = wp_get_nav_menu_items( $menu_id );

		// Convert menu items to JSON structure.
		$json_data = array();
		if ( $menu_items && ! is_wp_error( $menu_items ) && ! empty( $menu_items ) ) {
			$json_data = $this->convert_menu_items_to_json( $menu_items );
		}

		// Sanitize the key (convert hyphens to underscores for Etch compatibility).
		$sanitized_key = $this->sanitize_etch_key( $menu->slug );

		// Create menu entry.
		$menu_entry = array(
			'name'    => $menu->name,
			'key'     => $sanitized_key,
			'global'  => true,
			'config'  => array(
				'type' => 'json',
				'data' => $json_data,
			),
		);

		// Use menu slug as key to ensure consistent updates.
		$etch_loops[ $menu->slug ] = $menu_entry;

		// Save back to options.
		update_option( 'etch_loops', $etch_loops );

		// Clear override transient after successful sync.
		// This ensures the checkbox is unchecked on next page load.
		delete_transient( $override_key );

		// Clear sync notice transient after successful sync.
		// Since we just synced, any previous conflict is resolved.
		$notice_key = 'mdlr_menu_sync_notice_' . $menu->slug;
		delete_transient( $notice_key );

		// Also clear the sync enabled transient and set it to true since we just synced.
		$transient_key = 'mdlr_menu_sync_enabled_' . $menu->slug;
		set_transient( $transient_key, true, HOUR_IN_SECONDS );

		// Clear sync in progress transient.
		$sync_in_progress_key = 'mdlr_menu_sync_in_progress_' . $menu->slug;
		delete_transient( $sync_in_progress_key );

		// Set a transient to indicate sync was successful (used by JavaScript to update notice).
		$sync_completed_key = 'mdlr_menu_sync_completed_' . $menu->slug;
		set_transient( $sync_completed_key, true, MINUTE_IN_SECONDS );

		// Check if a key migration happened (hyphenated slug was converted to underscores).
		if ( $menu->slug !== $sanitized_key ) {
			// Only show migration message if this is the first time creating the loop with the underscore key.
			// If the existing entry already has the sanitized key, we've already notified the user.
			$should_notify_migration = true;

			if ( false !== $found_entry && isset( $found_entry['entry']['key'] ) && $found_entry['entry']['key'] === $sanitized_key ) {
				// Entry already exists with the sanitized key - user was already notified.
				$should_notify_migration = false;
			}

			if ( $should_notify_migration ) {
				// Set transient with the new key value so JS can show migration message.
				$key_migrated_key = 'mdlr_menu_key_migrated_' . $menu->slug;
				set_transient( $key_migrated_key, $sanitized_key, MINUTE_IN_SECONDS );
			}

			// Clear the needs_key_migration transient since migration is complete.
			$migration_key = 'mdlr_menu_needs_key_migration_' . $menu->slug;
			delete_transient( $migration_key );
		}

		// Return true to indicate successful sync.
		return true;
	}

	/**
	 * Sanitize a slug for use as an Etch loop key.
	 *
	 * Converts hyphens to underscores since Etch's template engine
	 * doesn't support hyphens in dot notation (e.g., loops.test-menu fails).
	 *
	 * @since 0.3.0
	 * @param string $slug The slug to sanitize.
	 * @return string The sanitized key with hyphens replaced by underscores.
	 */
	private function sanitize_etch_key( $slug ) {
		return str_replace( '-', '_', $slug );
	}

	/**
	 * Find etch_loops entry by menu slug.
	 *
	 * Checks both the array key and the 'key' field value to find an entry.
	 *
	 * @since 0.1.0
	 * @param array  $etch_loops The etch_loops option array.
	 * @param string $menu_slug  The menu slug to search for.
	 * @return array|false Returns array with 'array_key' and 'entry' if found, false otherwise.
	 */
	private function find_etch_loop_entry( $etch_loops, $menu_slug ) {
		// First check if array key matches.
		if ( isset( $etch_loops[ $menu_slug ] ) ) {
			return array(
				'array_key' => $menu_slug,
				'entry'     => $etch_loops[ $menu_slug ],
			);
		}

		// If not found, check 'key' field values.
		foreach ( $etch_loops as $array_key => $entry ) {
			if ( isset( $entry['key'] ) && $entry['key'] === $menu_slug ) {
				return array(
					'array_key' => $array_key,
					'entry'     => $entry,
				);
			}
		}

		return false;
	}

	/**
	 * Convert WordPress menu items to JSON structure.
	 *
	 * @since 0.1.0
	 * @param array $menu_items Array of menu item objects.
	 * @return array Converted menu items in JSON format.
	 */
	private function convert_menu_items_to_json( $menu_items ) {
		$json_items = array();
		$item_map = array();

		// Build item map with all menu items.
		foreach ( $menu_items as $item ) {
			// Decode HTML entities in title since WordPress stores them encoded.
			// Etch will re-encode when outputting, so we need raw characters here
			// to avoid double-encoding (e.g., "&amp;amp;" instead of "&amp;").
			$label = html_entity_decode( $item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			$json_item = array(
				'label' => $label,
				'url'   => $item->url,
			);

			// Add optional fields.
			if ( ! empty( $item->classes ) && is_array( $item->classes ) ) {
				$classes = array_filter( $item->classes );
				if ( ! empty( $classes ) ) {
					$json_item['classes'] = implode( ' ', $classes );
				}
			}

			if ( ! empty( $item->target ) ) {
				$json_item['target'] = $item->target;
			}

			/**
			 * Filter for extending what is sent to Etch for each menu item
			 *
			 * @since 0.2.0
			 * @param array   $json_item JSON-ready associative array for the current menu item.
			 * @param WP_Post $item      The raw menu item object.
			 */
			$json_item = apply_filters( 'mdlr_menu_item_json', $json_item, $item );

			$item_map[ $item->ID ] = $json_item;
		}

		// Build hierarchy - first add all child items to their parents.
		foreach ( $menu_items as $item ) {
			if ( 0 !== (int) $item->menu_item_parent && isset( $item_map[ $item->menu_item_parent ] ) ) {
				if ( ! isset( $item_map[ $item->menu_item_parent ]['children'] ) ) {
					$item_map[ $item->menu_item_parent ]['children'] = array();
				}
				$item_map[ $item->menu_item_parent ]['children'][] = &$item_map[ $item->ID ];
			}
		}

		// Then add top-level items to the final array.
		foreach ( $menu_items as $item ) {
			if ( 0 === (int) $item->menu_item_parent ) {
				$json_items[] = $item_map[ $item->ID ];
			}
		}

		return $json_items;
	}

	/**
	 * Handle term deletion to catch menu deletions early.
	 *
	 * We need to catch menu deletions early (before the term is deleted from the database)
	 * because once the term is deleted, we can't retrieve its properties (like the slug)
	 * to find and remove the corresponding entry from etch_loops.
	 *
	 * @since 0.1.0
	 * @param int    $term_id      The term ID being deleted.
	 * @param int    $tt_id        The term taxonomy ID.
	 * @param string $taxonomy     The taxonomy name.
	 * @param object $deleted_term The term object being deleted.
	 */
	public function handle_term_deletion( $term_id, $tt_id, $taxonomy, $deleted_term ) {
		// Only handle nav_menu taxonomy deletions.
		if ( 'nav_menu' !== $taxonomy ) {
			return;
		}

		// Check user permissions before deleting menu from etch_loops.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		// Use the deleted_term object provided by WordPress.
		if ( ! $deleted_term || is_wp_error( $deleted_term ) || ! isset( $deleted_term->slug ) ) {
			return;
		}

		// This is a menu being deleted, remove from etch_loops.
		// Check if etch_loops exists first - if not, nothing to delete.
		$etch_loops = get_option( 'etch_loops' );
		if ( false === $etch_loops ) {
			return;
		}

		// Check both array key and 'key' field value to find the entry.
		$found_entry = $this->find_etch_loop_entry( $etch_loops, $deleted_term->slug );

		if ( false !== $found_entry ) {
			unset( $etch_loops[ $found_entry['array_key'] ] );
			update_option( 'etch_loops', $etch_loops );
		}
	}

	/**
	 * Get menu ID from request with nonce verification.
	 *
	 * @since 0.1.0
	 * @param bool $strict Whether to require strict nonce verification. Default true.
	 * @return int The menu ID, or 0 if not found or nonce verification fails.
	 */
	private function get_menu_id_from_request( $strict = true ) {
		$menu_id = 0;

		// Check POST first (requires nonce verification).
		if ( isset( $_POST['menu'] ) ) {
			// Verify nonce for POST requests.
			if ( $strict ) {
				if ( isset( $_POST['_wpnonce'] ) ) {
					$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
					if ( ! wp_verify_nonce( $nonce, 'nav-menus' ) ) {
						return 0;
					}
				} else {
					// No nonce provided in POST - fail verification.
					return 0;
				}
			}
			$menu_id = (int) $_POST['menu'];
		} elseif ( isset( $_GET['menu'] ) ) {
			// Always require admin context for GET requests.
			if ( ! is_admin() ) {
				return 0;
			}

			// Verify nonce for GET requests if available.
			if ( $strict && isset( $_GET['_wpnonce'] ) ) {
				$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
				if ( ! wp_verify_nonce( $nonce, 'nav-menus' ) ) {
					return 0;
				}
			} elseif ( $strict && ! isset( $_GET['_wpnonce'] ) ) {
				// For strict mode, verify admin referer for GET requests without nonce.
				$referer = wp_get_referer();
				if ( ! $referer || false === strpos( $referer, admin_url() ) ) {
					return 0;
				}
			}
			// For lenient mode (display purposes), allow GET menu ID since we're in admin.
			$menu_id = (int) $_GET['menu'];
		} elseif ( get_user_meta( get_current_user_id(), 'nav_menu_recently_edited', true ) ) {
			// WordPress stores the last edited menu in user meta (no nonce needed for user meta).
			$menu_id = (int) get_user_meta( get_current_user_id(), 'nav_menu_recently_edited', true );
		}

		return $menu_id;
	}

	/**
	 * Check menu sync status when editor loads.
	 *
	 * Compares WordPress menu JSON with etch_loops data to determine
	 * if syncing should be enabled or disabled, and sets a transient accordingly.
	 *
	 * @since 0.1.0
	 */
	public function check_menu_sync_status() {
		// Get menu ID from request with nonce verification.
		$menu_id = $this->get_menu_id_from_request();

		if ( ! $menu_id ) {
			return;
		}

		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return;
		}

		// Don't clear override transient here - it needs to persist during the save operation.
		// We'll clear it only after successful sync or if user unchecks the box.
		// The transient will expire naturally after 5 minutes if not used.

		// Get current menu items and convert to JSON.
		$menu_items = wp_get_nav_menu_items( $menu_id );
		$wordpress_json = array();
		if ( $menu_items && ! is_wp_error( $menu_items ) && ! empty( $menu_items ) ) {
			$wordpress_json = $this->convert_menu_items_to_json( $menu_items );
		}

		// Get etch_loops data - check if option exists first.
		$etch_loops = get_option( 'etch_loops' );
		$transient_key = 'mdlr_menu_sync_enabled_' . $menu->slug;
		$notice_key = 'mdlr_menu_sync_notice_' . $menu->slug;

		// If etch_loops option doesn't exist, Etch plugin is not active.
		if ( false === $etch_loops ) {
			set_transient( $transient_key, false, HOUR_IN_SECONDS );
			set_transient( $notice_key, 'etch_not_active', HOUR_IN_SECONDS );
			return;
		}

		// Find entry checking both array key and 'key' field value.
		$found_entry = $this->find_etch_loop_entry( $etch_loops, $menu->slug );

		// If no entry found, safe to sync.
		if ( false === $found_entry ) {
			set_transient( $transient_key, true, HOUR_IN_SECONDS );
			delete_transient( $notice_key );
			return;
		}

		// Check if existing entry has a hyphenated key that needs migration.
		if ( isset( $found_entry['entry']['key'] ) ) {
			$existing_key = $found_entry['entry']['key'];
			if ( $existing_key !== $this->sanitize_etch_key( $existing_key ) ) {
				$migration_key = 'mdlr_menu_needs_key_migration_' . $menu->slug;
				set_transient( $migration_key, $existing_key, HOUR_IN_SECONDS );
			}
		}

		// Entry exists - check if it's a conflict (key field match but different array key).
		// If array key doesn't match menu slug, it's a conflict because 'key' values must be unique.
		if ( $found_entry['array_key'] !== $menu->slug ) {
			// Conflict: 'key' field matches but array key is different.
			// This means another entry already uses this 'key' value, so don't sync.
			set_transient( $transient_key, false, HOUR_IN_SECONDS );
			set_transient( $notice_key, 'slug_conflict', HOUR_IN_SECONDS );
			return;
		}

		// Entry exists with matching array key - compare JSON data.
		$etch_json = isset( $found_entry['entry']['config']['data'] ) ? $found_entry['entry']['config']['data'] : array();

		// Compare using serialized strings for exact matching.
		$wordpress_serialized = maybe_serialize( $wordpress_json );
		$etch_serialized = maybe_serialize( $etch_json );

		if ( $wordpress_serialized === $etch_serialized ) {
			// JSON matches - safe to sync.
			set_transient( $transient_key, true, HOUR_IN_SECONDS );
			delete_transient( $notice_key );
		} else {
			// JSON doesn't match - disable sync and set notice type.
			set_transient( $transient_key, false, HOUR_IN_SECONDS );

			// Determine notice type: existing menu mismatch vs new menu with existing slug.
			// If WordPress menu is empty but etch_loops has data, it's a new menu with slug conflict.
			if ( empty( $wordpress_json ) && ! empty( $etch_json ) ) {
				// New menu but etch_loops entry exists.
				set_transient( $notice_key, 'slug_conflict', HOUR_IN_SECONDS );
			} else {
				// Existing menu but out of sync.
				set_transient( $notice_key, 'out_of_sync', HOUR_IN_SECONDS );
			}
		}
	}

	/**
	 * Display admin notice for menu sync warnings.
	 *
	 * @since 0.1.0
	 */
	public function display_sync_notice() {
		// Only show on nav-menus page.
		$screen = get_current_screen();
		if ( ! $screen || 'nav-menus' !== $screen->id ) {
			return;
		}

		// Get menu ID from request with lenient nonce verification (for display purposes).
		$menu_id = $this->get_menu_id_from_request( false );

		if ( ! $menu_id ) {
			return;
		}

		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return;
		}

		// Check for key migration warning (hyphenated key needs to be converted to underscores).
		$migration_key = 'mdlr_menu_needs_key_migration_' . $menu->slug;
		$old_key = get_transient( $migration_key );
		if ( false !== $old_key ) {
			$new_key = $this->sanitize_etch_key( $old_key );
			?>
			<div class="notice notice-warning mdlr-menu-key-migration-notice">
				<p>
					<?php
					printf(
						/* translators: 1: old key with hyphens, 2: new key with underscores */
						esc_html__( 'The menu key \'%1$s\' contains hyphens which are no longer supported by Etch. Saving this menu will update the key to \'%2$s\'. After this menu is synced, please update any Etch templates that reference this loop.', 'menuwp' ),
						esc_html( $old_key ),
						esc_html( $new_key )
					);
					?>
				</p>
			</div>
			<?php
		}

		// Check for sync in progress status (to show "Syncing..." message).
		$sync_in_progress_key = 'mdlr_menu_sync_in_progress_' . $menu->slug;
		$sync_in_progress = get_transient( $sync_in_progress_key );

		// Also check for conflict notice (original behavior).
		$notice_key = 'mdlr_menu_sync_notice_' . $menu->slug;
		$notice_type = get_transient( $notice_key );

		// Check override state.
		$override_key = 'mdlr_menu_sync_override_' . $menu->slug;
		$override_enabled = get_transient( $override_key );
		$override_enabled = ( true === $override_enabled || 1 === (int) $override_enabled || '1' === $override_enabled );

		// Determine notice state and message.
		$notice_class = 'notice-info';
		$show_checkbox = true;

		// Show notice only if: sync in progress, override enabled, OR there's a conflict notice.
		// This matches the old behavior (notice only when conflict exists) but also allows
		// showing "Syncing..." for normal syncs that are in progress.
		if ( ! $sync_in_progress && ! $override_enabled && ! $notice_type ) {
			return; // No reason to show notice - don't show anything.
		}

		// Priority order: etch_not_active first (no checkbox), then conflict notices (if no override), then sync in progress.
		// Note: JavaScript handles success/failure messages - PHP only shows "Syncing..." and conflict messages.

		// If Etch plugin is not active, show warning and don't offer override option.
		if ( 'etch_not_active' === $notice_type ) {
			// Clear sync_in_progress if it exists - we're not syncing.
			if ( $sync_in_progress ) {
				delete_transient( $sync_in_progress_key );
			}
			$message = __( 'Please activate the Etch plugin to sync menus with Etch.', 'menuwp' );
			$notice_class = 'notice-warning';
			$show_checkbox = false;
		} elseif ( $notice_type && ! $override_enabled ) {
			// If there's a conflict notice AND override is NOT enabled, show conflict message (don't sync).
			// Also clear sync_in_progress if it was incorrectly set.
			// Clear sync_in_progress if it exists - we're not syncing due to conflict.
			if ( $sync_in_progress ) {
				delete_transient( $sync_in_progress_key );
			}

			// Original conflict/warning message (only show if there's a conflict notice and override is disabled).
			if ( 'slug_conflict' === $notice_type ) {
				$message = __( 'This menu ID is already being used by an Etch loop. This menu will be saved to WordPress but not sync to Etch to prevent overwriting it.', 'menuwp' );
			} else {
				$message = __( 'The Etch loop associated with this menu is no longer in sync. This can happen if the Etch loop is editited, or if page titles or other items referenced by this menu have been changed. This menu will be saved to WordPress but not to Etch.', 'menuwp' );
			}
			$notice_class = 'notice-info';
			$show_checkbox = true;
		} elseif ( $sync_in_progress || $override_enabled ) {
			// Sync in progress - show syncing message.
			// This applies to normal sync cases (no conflict) and conflict cases with override enabled.
			$message = __( 'Syncing to Etch...', 'menuwp' );
			$notice_class = 'notice-info';
			$show_checkbox = false;
		} elseif ( $notice_type ) {
			// Fallback: conflict notice exists (shouldn't reach here, but just in case).
			if ( 'slug_conflict' === $notice_type ) {
				$message = __( 'This menu ID is already being used by an Etch loop. This menu will be saved to WordPress but not sync to Etch to prevent overwriting it.', 'menuwp' );
			} else {
				$message = __( 'The Etch loop associated with this menu has been modified or is no longer in sync. This menu will be saved to WordPress but not to Etch.', 'menuwp' );
			}
			$notice_class = 'notice-info';
			$show_checkbox = true;
		}

		// Determine if notice should be dismissible.
		// Only make it dismissible if NOT showing a conflict message (i.e., when syncing).
		// Conflict messages should never be dismissible.
		$is_dismissible = '';
		if ( $notice_type && ! $override_enabled ) {
			// Showing conflict message - NOT dismissible.
			$is_dismissible = '';
		} elseif ( $sync_in_progress || $override_enabled ) {
			// Showing "Syncing..." message - dismissible.
			$is_dismissible = ' is-dismissible';
		}
		
		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?><?php echo esc_attr( $is_dismissible ); ?> mdlr-menu-sync-notice" data-menu-slug="<?php echo esc_attr( $menu->slug ); ?>" data-menu-name="<?php echo esc_attr( $menu->name ); ?>">
			<p>
				<?php echo wp_kses_post( $message ); ?>
			</p>
			<?php if ( $show_checkbox ) : ?>
			<p>
				<label>
					<input type="checkbox" class="mdlr-menu-sync-override" <?php checked( (bool) $override_enabled ); ?>>
					<?php echo esc_html__( 'Allow syncing to Etch despite the conflict', 'menuwp' ); ?>
				</label>
			</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Enqueue admin scripts for checkbox handling.
	 *
	 * @since 0.1.0
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only on nav-menus page.
		if ( 'nav-menus.php' !== $hook ) {
			return;
		}

		// Get plugin URL for asset paths.
		$plugin_url = plugin_dir_url( __FILE__ );

		// Enqueue script with dependency on jQuery.
		wp_enqueue_script(
			'mdlr-menu-sync',
			$plugin_url . 'js/wp-menu-sync.js',
			array( 'jquery' ),
			filemtime( __DIR__ . '/js/wp-menu-sync.js' ),
			true
		);

		// Localize script to pass PHP values to JavaScript.
		wp_localize_script(
			'mdlr-menu-sync',
			'mdlrMenuSync',
			array(
				'nonce' => wp_create_nonce( 'mdlr_menu_sync_override' ),
				'i18n'  => array(
					'syncedToEtch'               => __( 'has been synced to Etch.', 'menuwp' ),
					'menuSuccessfullySynced'      => __( 'Menu successfully synced to Etch.', 'menuwp' ),
					'failedToSyncMenu'           => __( 'Failed to sync menu to Etch. Please try again.', 'menuwp' ),
					'allowSyncingDespiteConflict' => __( 'Allow syncing to Etch despite the conflict', 'menuwp' ),
					'syncTimedOut'               => __( 'Sync to Etch timed out. Please try saving the menu again.', 'menuwp' ),
					/* translators: %s is the new menu key with underscores instead of hyphens */
					'keyMigrated'                => __( "The new menu key is '%s'. Please update any Etch templates that reference this loop.", 'menuwp' ),
				),
			)
		);
	}

	/**
	 * Handle AJAX request to set/clear sync override.
	 *
	 * @since 0.1.0
	 */
	public function handle_sync_override_ajax() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mdlr_menu_sync_override' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'menuwp' ) ) );
		}

		// Check user permissions.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'menuwp' ) ) );
		}

		// Get menu slug.
		if ( ! isset( $_POST['menu_slug'] ) || empty( $_POST['menu_slug'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Menu slug required.', 'menuwp' ) ) );
		}

		$menu_slug = sanitize_text_field( wp_unslash( $_POST['menu_slug'] ) );
		$override = isset( $_POST['override'] ) && 1 === (int) $_POST['override'];
		$override_key = 'mdlr_menu_sync_override_' . $menu_slug;

			if ( $override ) {
				// Set override transient (5 minutes expiry).
				set_transient( $override_key, true, 5 * MINUTE_IN_SECONDS );
				wp_send_json_success( array( 'message' => __( 'Override enabled.', 'menuwp' ) ) );
			} else {
				// Clear override.
				delete_transient( $override_key );
				wp_send_json_success( array( 'message' => __( 'Override cleared.', 'menuwp' ) ) );
			}
	}

	/**
	 * Handle AJAX request to check if sync was completed.
	 *
	 * @since 0.1.0
	 */
	public function handle_check_sync_completed_ajax() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mdlr_menu_sync_override' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'menuwp' ) ) );
		}

		// Check user permissions.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'menuwp' ) ) );
		}

		// Get menu slug.
		if ( ! isset( $_POST['menu_slug'] ) || empty( $_POST['menu_slug'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Menu slug required.', 'menuwp' ) ) );
		}

		$menu_slug = sanitize_text_field( wp_unslash( $_POST['menu_slug'] ) );
		$sync_completed_key = 'mdlr_menu_sync_completed_' . $menu_slug;
		$sync_completed = get_transient( $sync_completed_key );

		// If sync was completed, clear the transient and return success.
		if ( false !== $sync_completed ) {
			delete_transient( $sync_completed_key );

			// Check if a key migration happened.
			$key_migrated_key = 'mdlr_menu_key_migrated_' . $menu_slug;
			$key_migrated = get_transient( $key_migrated_key );

			$response_data = array( 'sync_completed' => true );

			if ( false !== $key_migrated ) {
				delete_transient( $key_migrated_key );
				$response_data['key_migrated'] = $key_migrated;
			}

			wp_send_json_success( $response_data );
		}

		// Check if sync failed.
		$sync_failed_key = 'mdlr_menu_sync_failed_' . $menu_slug;
		$sync_failed = get_transient( $sync_failed_key );

		if ( false !== $sync_failed ) {
			delete_transient( $sync_failed_key );
			wp_send_json_success( array( 'sync_failed' => true ) );
		}

		// Sync not completed.
		wp_send_json_success( array( 'sync_completed' => false ) );
	}
}

// Initialize the plugin.
new MDLR_Menu();