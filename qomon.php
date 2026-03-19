<?php
/**
 * Qomon
 *
 * @author            Qomon
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Qomon
 * Description:       Easily insert your Qomon form in your site. By adding a shortcode [qomon-form] or even by adding a custom block created by Qomon.
 * Requires at least: 6.1
 * Requires PHP:      7.0
 * Version:           2.0.0
 * Author:            Qomon
 * Author URI:        https://qomon.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       qomon
 */


/**
 * Exit if plugin accessed directly
 */
if (!defined('ABSPATH'))
	exit;


// ---------------------------------------------------------------------------
// Manifest cache — 2-hour TTL gives a 1-hour buffer if the hourly cron misses
// ---------------------------------------------------------------------------

/**
 * Fetches and caches the Qomon form manifest for a given base_id.
 *
 * Priority: transient (fast) → live CDN fetch → wp_options stale copy (fallback).
 * A successful CDN fetch is written through to both stores so wp_options always
 * holds the last known-good manifest, surviving object cache flushes.
 *
 * Returns the decoded manifest array, or false if all sources fail.
 */
if (!function_exists('wpqomon_get_manifest')) {
	function wpqomon_get_manifest($base_id, $bucket = 'cdn-form.qomon.org')
	{
		$hash          = md5($base_id . $bucket);
		$transient_key = 'qomon_manifest_' . $hash;
		$option_key    = 'qomon_manifest_fallback_' . $hash;

		$cached = get_transient($transient_key);
		if ($cached !== false) return $cached;

		// Transient expired or was flushed — try a live fetch first.
		$response = wp_remote_get("https://{$bucket}/{$base_id}/manifest.json");
		if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
			$manifest = json_decode(wp_remote_retrieve_body($response), true);
			if (is_array($manifest)) {
				set_transient($transient_key, $manifest, 2 * HOUR_IN_SECONDS);
				update_option($option_key, $manifest, false);
				return $manifest;
			}
		}

		// CDN unreachable — serve the last known-good copy from wp_options.
		$fallback = get_option($option_key, false);
		return $fallback;
	}
}


// ---------------------------------------------------------------------------
// Form registry — persists known form IDs so the cron can pre-warm them
// ---------------------------------------------------------------------------

/**
 * Registers a form ID in the persistent store so the hourly cron can keep
 * its manifest cached without waiting for a page render.
 */
if (!function_exists('wpqomon_register_form')) {
	function wpqomon_register_form($base_id, $form_type = '', $bucket = 'cdn-form.qomon.org')
	{
		$forms       = get_option('qomon_registered_forms', []);
		$key         = md5($base_id . $bucket);
		$forms[$key] = compact('base_id', 'form_type', 'bucket');
		update_option('qomon_registered_forms', $forms, false);
	}
}

/**
 * Force-refreshes the manifest cache for every registered form.
 * Called by the hourly cron and immediately after save_post discovers new forms.
 */
if (!function_exists('wpqomon_refresh_manifests')) {
	function wpqomon_refresh_manifests()
	{
		$forms = get_option('qomon_registered_forms', []);
		foreach ($forms as $form) {
			$cache_key = 'qomon_manifest_' . md5($form['base_id'] . $form['bucket']);
			delete_transient($cache_key);
			wpqomon_get_manifest($form['base_id'], $form['bucket']);
		}
	}
}


// ---------------------------------------------------------------------------
// save_post — scan content for Qomon blocks/shortcodes and prime the cache
// ---------------------------------------------------------------------------

/**
 * Recursively extracts Qomon block attributes from a parsed block tree,
 * handling blocks that may be nested inside reusable or group blocks.
 */
if (!function_exists('wpqomon_extract_blocks')) {
	function wpqomon_extract_blocks(array $blocks)
	{
		$found = [];
		foreach ($blocks as $block) {
			if ($block['blockName'] === 'create-block/qomon-form') {
				$found[] = $block['attrs'];
			}
			if (!empty($block['innerBlocks'])) {
				$found = array_merge($found, wpqomon_extract_blocks($block['innerBlocks']));
			}
		}
		return $found;
	}
}

add_action('save_post', function ($post_id, $post) {
	if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

	$content = $post->post_content;
	$changed = false;

	// Blocks
	if (has_blocks($content)) {
		foreach (wpqomon_extract_blocks(parse_blocks($content)) as $attrs) {
			$base_id   = $attrs['base_id'] ?? '';
			$form_type = $attrs['form_type'] ?? '';
			$bucket    = !empty($attrs['env']) ? $attrs['env'] : 'cdn-form.qomon.org';
			if ($base_id) {
				wpqomon_register_form($base_id, $form_type, $bucket);
				$changed = true;
			}
		}
	}

	// Shortcodes: [qomon-form id=xxx] or [qomon-form id="xxx" type=petition]
	preg_match_all('/\[qomon-form\b[^\]]*\bid=["\']?([^\s"\'\/\]]+)/i', $content, $matches);
	foreach ($matches[1] as $base_id) {
		wpqomon_register_form($base_id);
		$changed = true;
	}

	if ($changed) {
		wpqomon_refresh_manifests();
	}
}, 10, 2);


// ---------------------------------------------------------------------------
// Hourly cron — keeps manifests warm between page loads
// ---------------------------------------------------------------------------

register_activation_hook(__FILE__, function () {
	if (!wp_next_scheduled('qomon_refresh_manifests')) {
		wp_schedule_event(time(), 'hourly', 'qomon_refresh_manifests');
	}
});

register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook('qomon_refresh_manifests');
});

add_action('qomon_refresh_manifests', 'wpqomon_refresh_manifests');


// ---------------------------------------------------------------------------
// Frontend rendering
// ---------------------------------------------------------------------------

/**
 * Renders a Qomon form by fetching the manifest server-side and outputting
 * a pre-resolved <script type="module"> and data-style-link in the HTML.
 * Shared by both the shortcode and the block render_callback.
 */
if (!function_exists('wpqomon_render_form')) {
	function wpqomon_render_form($base_id, $form_type = '', $bucket = 'cdn-form.qomon.org')
	{
		// Fallback registration for forms not discovered via save_post (e.g. REST/raw inserts).
		wpqomon_register_form($base_id, $form_type, $bucket);

		$manifest = wpqomon_get_manifest($base_id, $bucket);
		if (!$manifest) return '<!-- Qomon: could not load manifest -->';

		$component    = $form_type === 'petition' ? 'qomonPetition' : 'qomonForm';
		$manifest_key = "src/webComponents/{$component}.js";
		if (!isset($manifest[$manifest_key])) return '<!-- Qomon: component not in manifest -->';

		$entry      = $manifest[$manifest_key];
		$script_url = "https://{$bucket}/{$base_id}/" . $entry['file'];

		// Vite may output CSS as an array; take the first entry.
		$css_file  = is_array($entry['css']) ? $entry['css'][0] : $entry['css'];
		$style_url = "https://{$bucket}/{$base_id}/" . $css_file;

		$handle = 'qomon-form-' . sanitize_key($base_id);
		wp_enqueue_script($handle, $script_url, [], null, false);

		return '<div class="qomon-form" data-base_id="' . esc_attr($base_id) . '" data-style-link="' . esc_attr($style_url) . '"></div>';
	}
}

/**
 * Adds type="module" to any enqueued script whose handle starts with "qomon-form-".
 * wp_enqueue_script() does not support ES modules natively.
 */
add_filter('script_loader_tag', function ($tag, $handle) {
	if (strpos($handle, 'qomon-form-') === 0) {
		return str_replace('<script ', '<script type="module" ', $tag);
	}
	return $tag;
}, 10, 2);


// ---------------------------------------------------------------------------
// Block registration — must run on both admin and frontend for render_callback
// ---------------------------------------------------------------------------

/**
 * Registers the block using the metadata loaded from the `block.json` file.
 * The render_callback handles server-side manifest resolution so no setup.js is needed.
 *
 * @see https://developer.wordpress.org/reference/functions/register_block_type/
 */
if (!function_exists('wpqomon_create_form_block')) {
	function wpqomon_create_form_block()
	{
		register_block_type(__DIR__ . '/build', [
			'render_callback' => function ($attributes) {
				$base_id   = $attributes['base_id'] ?? '';
				$form_type = $attributes['form_type'] ?? '';
				$bucket    = !empty($attributes['env']) ? $attributes['env'] : 'cdn-form.qomon.org';
				if (empty($base_id)) return '';
				return wpqomon_render_form($base_id, $form_type, $bucket);
			},
		]);
	}
}
add_action('init', 'wpqomon_create_form_block');


// ---------------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------------

if (is_admin()) {
	/**
	 * Load translations
	 */
	if (!function_exists('wpqomon_load_translations')) {
		function wpqomon_load_translations()
		{
			load_plugin_textdomain('qomon', '', 'qomon/languages/');
		}
	}
	add_action('plugins_loaded', 'wpqomon_load_translations');


	if (!function_exists('wpqomon_admin_page_contents')) {

		function wpqomon_admin_page_contents()
		{
			echo '<article style="padding: 12px;max-width:1000px;">
			<h1>
			<img style="width:40px; margin-right: 16px; vertical-align: middle;" src="' . esc_url(plugin_dir_url(__FILE__) . 'public/images/qomon-pink-shorted.svg') . '">
			' . esc_html(__('Welcome to Qomon for WordPress', 'qomon')) . '
			</h1>

			<p>
			<a href="' . esc_url(__('https://www.qomon.com', 'qomon')) . '" target="_blank">' . esc_html("Qomon") . '</a>
			' . esc_html(__(' is an organization that helps causes, NGOs, campaigns, elected officials, movements & businesses engage more citizens, take concrete action and amplify their impact.', 'qomon')) . '
			</p>
			<p>' . esc_html(__('Qomon allows, among other things, to create forms, customize their colors and fields, to consult the opinion of your contacts or allow new contacts, for example, to subscribe to your newsletter.', 'qomon')) . '</p>
			<p>' . esc_html(__('Integrate the form easily into your website with this plugin! ', 'qomon')) . '</p>
			<p>' . esc_html(__('If you are not a Qomon customer yet, and would like to use this feature, you can get more information and request a demo', 'qomon')) . '
			<a href="' . esc_url(__('https://www.qomon.com', 'qomon')) . '" target="_blank">
			' . esc_html(__('here', 'qomon')) . '</a>.
			</p>

			<h2 style="margin-top: 32px; font-size: 20px;">' . esc_html(__('Using the WordPress plugin', 'qomon')) . '</h2>
			<p>' . esc_html(__('To add a Qomon form to your page you have 2 options: ', 'qomon')) . '</p>

			<h3 style="margin-top: 24px;">' . esc_html(__('I. Adding through the Qomon Form Block', 'qomon')) . '</h3>
			<p>' . esc_html(__('Once activated you will be able to add a form to your page using a Qomon Form Block: ', 'qomon')) . '</p>
			<img style="width:244px" src="' . esc_url(plugin_dir_url(__FILE__) . 'public/images/qomon-form/block-search.png') . '">
			<p>' . esc_html(__('The block will appear, allowing you to add the id of your form to it:', 'qomon')) . '</p>
			<img style="width:424px" src="' . esc_url(plugin_dir_url(__FILE__) . 'public/images/qomon-form/block.png') . '">
			<p>' . esc_html(__('Specify your form type:', 'qomon')) . '</p>
			<img style="width:424px" src="' . esc_url(plugin_dir_url(__FILE__) . 'public/images/qomon-form/petition-type.png') . '">
			<p>' . esc_html(__('The published or previewed page will display the corresponding form:', 'qomon')) . '</p>
			<img style="width:424px" src="' . esc_url(plugin_dir_url(__FILE__) . 'public/images/qomon-form/form-example.png') . '">

			<h3 style="margin-top: 24px;">' . esc_html(__('II. Adding through the shortcode [qomon-form]', 'qomon')) . '</h3>
			<p>' . esc_html(__('In the same way you can add a shortcode block:', 'qomon')) . '</p>
			<img style="width:244px" src="' . esc_url(plugin_dir_url(__FILE__) . 'public/images/qomon-form/shortcode.png') . '">
			<p>' . esc_html(__('Once this block is on the page it will be necessary to write this code [qomon-form id=my-form-id] in the block, my-form-id will be to replace by the id of your form:', 'qomon')) . '</p>
			<img style="width:424px" src="' . esc_url(plugin_dir_url(__FILE__) . 'public/images/qomon-form/shortcode-filled.png') . '">
			<p>' . esc_html(__('The published or previewed page will display the corresponding form:', 'qomon')) . '</p>
			<img style="width:424px" src="' . esc_url(plugin_dir_url(__FILE__) . 'public/images/qomon-form/form-example.png') . '">

			<p>' . esc_html(__('Your form is now available, your signatories can fill out the form!', 'qomon')) . '</p>
			<p>' . esc_html(__('To go further in the customization of this one, or for any help concerning the plugin, you can consult', 'qomon')) . '
			<a href="' . esc_url(__('https://help.qomon.com/en/articles/7439238-how-can-i-integrate-a-qomon-form-on-my-website', 'qomon')) . '" target="_blank">'
				. esc_html(__('this page', 'qomon')) . '</a>.
			 </p>
			</article>';
		}
	}

	if (!function_exists('wpqomon_add_qomon_admin_menu')) {
		function wpqomon_add_qomon_admin_menu()
		{
			add_menu_page(
				'Qomon Plugin',
				'Qomon',
				'edit_themes',
				'qomon-plugin',
				'wpqomon_admin_page_contents',
				'' . 'data:image/svg+xml;base64,' . base64_encode('
			<svg xmlns="http://www.w3.org/2000/svg" version="1.1" xml:space="preserve"  width="20" height="auto" viewBox="0 0 512 512" style="fill:#a7aaad" role="img" aria-hidden="true" focusable="false">
			<path d="M255.292 0C112.719 0 0 113.196 0 255.304C0 385.598 93.7125 491.672 218.269 509.382V385.268C190.126 376.747 165.485 359.37 148.01 335.721C130.535 312.071 121.16 283.413 121.279 254.007C121.279 179.628 181.223 115.884 256 115.884C329.338 115.884 390.743 179.557 390.743 254.007C390.747 269.977 387.937 285.823 382.443 300.819L328.748 247.923L245.223 334.164L295.639 384.56L387.23 476.178L423.073 512L509.31 425.782L473.938 390.927C498.982 350.136 512.162 303.171 511.998 255.304C511.998 113.055 398.525 0 255.292 0Z"/>
			</svg>
			') . '',
				null
			);
		}
	}
	add_action('admin_menu', 'wpqomon_add_qomon_admin_menu');

} else {
	/**
	 * The [qomon-form] shortcode.
	 *
	 * Accepts an id and optional type (e.g. type=petition) and renders the form
	 * server-side with pre-resolved asset URLs from the cached manifest.
	 *
	 * Example: [qomon-form id=5652feb3-bc2a-4c0d-ba75-e5f68997f308]
	 * Example: [qomon-form id=5652feb3-bc2a-4c0d-ba75-e5f68997f308 type=petition]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Shortcode output.
	 */
	if (!function_exists('wpqomon_add_form_shortcode')) {
		function wpqomon_add_form_shortcode($atts = [])
		{
			$atts = shortcode_atts(['id' => '', 'type' => ''], $atts, 'qomon-form');
			if (empty($atts['id'])) return '';
			return wpqomon_render_form($atts['id'], $atts['type']);
		}
	}

	add_shortcode('qomon-form', 'wpqomon_add_form_shortcode');
}
