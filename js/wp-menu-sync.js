(function($) {

	// Inject CSS for animated ellipsis on the sync notice.
	var style = document.createElement('style');
	style.textContent =
		'@keyframes mdlr-ellipsis {' +
		'  0% { content: "."; }' +
		'  33% { content: ".."; }' +
		'  66% { content: "..."; }' +
		'}' +
		'.mdlr-ellipsis-animate::after {' +
		'  content: "...";' +
		'  display: inline-block;' +
		'  animation: mdlr-ellipsis 1.2s steps(1, end) infinite;' +
		'}';
	document.head.appendChild(style);

	// Poll counter for timeout.
	var pollCount = 0;
	var maxPolls = 15;

	// Handle checkbox changes.
	$(document).on('change', '.mdlr-menu-sync-override', function() {
		var checkbox = $(this);
		var menuSlug = checkbox.closest('.mdlr-menu-sync-notice').data('menu-slug');
		var override = checkbox.is(':checked') ? 1 : 0;

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'mdlr_menu_set_sync_override',
				menu_slug: menuSlug,
				override: override,
				nonce: mdlrMenuSync.nonce
			},
			success: function(response) {
				if (!response.success) {
					// Revert checkbox state on error.
					checkbox.prop('checked', !checkbox.is(':checked'));
				}
			}
		});
	});

	// Listen for AJAX menu save completion to start polling.
	$(document).ajaxComplete(function(event, xhr, settings) {
		if (settings.url && settings.url.indexOf('nav-menus.php') !== -1) {
			// Wait for WordPress to finish updating the DOM, then check if we should poll.
			setTimeout(function() {
				var notice = $('.mdlr-menu-sync-notice');
				var checkbox = notice.find('.mdlr-menu-sync-override');

				// If notice shows "Syncing..." (override enabled but not completed), start polling.
				if (notice.length && checkbox.is(':checked')) {
					// Reset poll counter and start polling for sync completion after save.
					pollCount = 0;
					// Small delay to let shutdown hook start (100ms is usually enough).
					setTimeout(function() {
						checkSyncCompleted();
					}, 100);
				}
			}, 50);
		}
	});

	/**
	 * Start the ellipsis animation on the notice text.
	 *
	 * Wraps the text in a span and adds the animation class. The CSS
	 * ::after pseudo-element cycles through dots.
	 */
	var startEllipsis = function(notice) {
		var firstP = notice.find('p:first');
		// Wrap text in a span if not already wrapped.
		if (!firstP.find('.mdlr-sync-text').length) {
			var text = firstP.text().replace(/\.+\s*$/, '');
			firstP.html('<span class="mdlr-sync-text mdlr-ellipsis-animate">' + $('<span>').text(text).html() + '</span>');
		} else {
			firstP.find('.mdlr-sync-text').addClass('mdlr-ellipsis-animate');
		}
	};

	/**
	 * Stop the ellipsis animation.
	 */
	var stopEllipsis = function(notice) {
		notice.find('.mdlr-sync-text').removeClass('mdlr-ellipsis-animate');
	};

	/**
	 * Handle polling timeout — show error and offer override checkbox.
	 */
	var handleTimeout = function(notice) {
		stopEllipsis(notice);

		notice.removeClass('notice-info notice-warning notice-success notice-error').addClass('notice-error');
		notice.find('p:first').html(mdlrMenuSync.i18n.syncTimedOut);

		// Show override checkbox so user has a path forward.
		var checkboxContainer = notice.find('label').closest('p');
		if (checkboxContainer.length) {
			checkboxContainer.show();
		} else {
			var checkboxHtml = '<p><label><input type="checkbox" class="mdlr-menu-sync-override"> ' + mdlrMenuSync.i18n.allowSyncingDespiteConflict + '</label></p>';
			notice.append(checkboxHtml);
		}
	};

	// Poll for sync completion after menu saves.
	var checkSyncCompleted = function() {
		var notice = $('.mdlr-menu-sync-notice');
		if (!notice.length) {
			return;
		}

		var menuSlug = notice.data('menu-slug');
		var noticeContent = notice.find('p:first');
		var noticeText = noticeContent.text().trim();

		// Only poll if notice shows "Syncing to Etch" - this means sync is in progress.
		// Don't poll if notice shows conflict/warning (checkbox unchecked) or success/error (already completed/failed).
		if (noticeText.indexOf('Syncing to Etch') !== 0) {
			return; // Don't poll - not syncing.
		}

		// Start ellipsis animation on the syncing notice.
		startEllipsis(notice);

		// Check if we've exceeded the poll limit.
		pollCount++;
		if (pollCount > maxPolls) {
			handleTimeout(notice);
			return;
		}

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'mdlr_menu_check_sync_completed',
				menu_slug: menuSlug,
				nonce: mdlrMenuSync.nonce
			},
			success: function(response) {
				if (response.success && response.data && response.data.sync_completed) {
					// Sync completed - update notice to success immediately.
					// Re-select notice in case WordPress replaced it.
					notice = $('.mdlr-menu-sync-notice');
					if (!notice.length) {
						return; // Notice was removed.
					}

					stopEllipsis(notice);

					// Get menu name from data attribute.
					var menuName = notice.data('menu-name');

					notice.removeClass('notice-info notice-warning notice-error notice-success').addClass('notice-success');
					var firstParagraph = notice.find('p:first');

					// Format success message like WordPress: <strong>Menu Name</strong> has been synced to Etch.
					var successMessage = menuName
						? '<strong>' + $('<div>').text(menuName).html() + '</strong> ' + mdlrMenuSync.i18n.syncedToEtch
						: mdlrMenuSync.i18n.menuSuccessfullySynced;

					firstParagraph.html(successMessage);

					// Hide checkbox if it exists (might not exist when PHP rendered "Syncing...").
					var checkboxContainer = notice.find('label').closest('p');
					if (checkboxContainer.length) {
						checkboxContainer.hide();
					}

					// Stop polling - sync is complete.
					return;
				}

				// Check if there's an error (sync failed).
				if (response.success && response.data && response.data.sync_failed) {
					// Sync failed - update notice to error immediately.
					// Re-select notice in case WordPress replaced it.
					notice = $('.mdlr-menu-sync-notice');
					if (!notice.length) {
						return; // Notice was removed.
					}

					stopEllipsis(notice);

					notice.removeClass('notice-info notice-warning notice-success notice-error').addClass('notice-error');
					notice.find('p:first').html(mdlrMenuSync.i18n.failedToSyncMenu);

					// Show checkbox if it exists, otherwise add it back.
					var checkboxContainer = notice.find('label').closest('p');
					if (checkboxContainer.length) {
						checkboxContainer.show();
					} else {
						// If checkbox container doesn't exist (wasn't rendered), add it back.
						var checkboxHtml = '<p><label><input type="checkbox" class="mdlr-menu-sync-override"> ' + mdlrMenuSync.i18n.allowSyncingDespiteConflict + '</label></p>';
						notice.append(checkboxHtml);
					}

					return; // Stop polling.
				}

				// Continue polling if sync not completed yet.
				setTimeout(checkSyncCompleted, 1000);
			},
			error: function() {
				// On AJAX error, stop polling.
				// Error state will be shown by PHP on next render.
				stopEllipsis(notice);
			}
		});
	};

	// Initial check on page load (in case page refreshed after save).
	pollCount = 0;
	setTimeout(checkSyncCompleted, 1000);
})(jQuery);
