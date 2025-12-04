/**
 * AI Media SEO - Admin JavaScript
 *
 * @package AIMediaSEO
 * @since 1.0.0
 */

(function($) {
	'use strict';

	/**
	 * Main AI Media SEO object.
	 */
	const AIMediaSEO = {
		/**
		 * Initialize the plugin.
		 */
		init: function() {
			this.bindEvents();
			this.initDashboard();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Add event handlers here
		},

		/**
		 * Initialize dashboard.
		 */
		initDashboard: function() {
			const $dashboardRoot = $('#ai-media-dashboard-root');
			if ($dashboardRoot.length === 0) {
				return;
			}

			// Dashboard will be rendered by React in Phase 3
			$dashboardRoot.html('<p>Dashboard loading...</p>');
		},

		/**
		 * Show loading indicator.
		 */
		showLoading: function($element) {
			$element.append('<span class="ai-media-loading"></span>');
		},

		/**
		 * Hide loading indicator.
		 */
		hideLoading: function($element) {
			$element.find('.ai-media-loading').remove();
		},

		/**
		 * Show notice.
		 */
		showNotice: function(message, type = 'info') {
			const $notice = $('<div>')
				.addClass('ai-media-notice')
				.addClass('ai-media-notice-' + type)
				.html('<p>' + message + '</p>');

			$('.ai-media-seo-wrap').prepend($notice);

			setTimeout(function() {
				$notice.fadeOut(function() {
					$(this).remove();
				});
			}, 5000);
		},

		/**
		 * Make API request.
		 */
		apiRequest: function(endpoint, data = {}, method = 'GET') {
			return $.ajax({
				url: window.aiMediaSEO.apiUrl + endpoint,
				method: method,
				data: method === 'GET' ? data : JSON.stringify(data),
				contentType: 'application/json',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', window.aiMediaSEO.nonce);
				}
			});
		}
	};

	/**
	 * Initialize when DOM is ready.
	 */
	$(document).ready(function() {
		if (typeof window.aiMediaSEO !== 'undefined') {
			AIMediaSEO.init();
		}
	});

	// Expose to global scope
	window.AIMediaSEO = AIMediaSEO;

})(jQuery);
