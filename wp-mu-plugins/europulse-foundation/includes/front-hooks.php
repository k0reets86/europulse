<?php

add_action('wp_enqueue_scripts', function () {
	$path = WPMU_PLUGIN_DIR . '/europulse-foundation.css';

	if (! file_exists($path)) {
		return;
	}

	wp_enqueue_style(
		'europulse-foundation',
		content_url('/mu-plugins/europulse-foundation.css'),
		[],
		(string) filemtime($path)
	);

	$normalize_path = WPMU_PLUGIN_DIR . '/europulse-normalize.css';

	if (file_exists($normalize_path)) {
		wp_enqueue_style(
			'europulse-normalize',
			content_url('/mu-plugins/europulse-normalize.css'),
			['europulse-foundation'],
			(string) filemtime($normalize_path)
		);
	}

	if (is_singular('post') && europulse_has_video()) {
		wp_enqueue_style('wp-mediaelement');
		wp_enqueue_script('wp-mediaelement');
		wp_add_inline_script(
			'wp-mediaelement',
			"document.addEventListener('DOMContentLoaded',function(){if(!window.MediaElementPlayer){return;}document.querySelectorAll('.entry-content video, .wp-block-video video').forEach(function(video){if(video.dataset.europulseMejs==='1'){return;}video.dataset.europulseMejs='1';try{new MediaElementPlayer(video,{stretching:'responsive'});}catch(e){}});});",
			'after'
		);
	}
}, 20);

add_filter('blocksy:footer:current_section_id', function ($section_id, $value) {
	if (is_array($value) && ! empty($value['current_section'])) {
		return $value['current_section'];
	}

	return $section_id;
}, 10, 2);

add_filter('wp_nav_menu_args', function ($args) {
	if (! empty($args['theme_location']) && in_array($args['theme_location'], ['menu_1', 'menu_mobile'], true)) {
		$lang = function_exists('pll_current_language') ? pll_current_language('slug') : 'de';
		$menu = (int) get_option('europulse_primary_menu_' . $lang, 0);

		if (! $menu) {
			$menu = (int) get_option('europulse_primary_menu_de', 37);
		}

		$args['menu'] = $menu;
	}

	return $args;
});

add_filter('nav_menu_link_attributes', function ($atts, $item, $args, $depth) {
	if (! is_object($item) || (($item->type ?? '') !== 'taxonomy') || (($item->object ?? '') !== 'category')) {
		return $atts;
	}

	$term_id = (int) ($item->object_id ?? 0);
	if ($term_id <= 0) {
		return $atts;
	}

	$lang = function_exists('pll_current_language') ? (string) pll_current_language('slug') : 'de';
	if ($lang === '') {
		$lang = 'de';
	}

	if (function_exists('pll_get_term')) {
		$translated = (int) (pll_get_term($term_id, $lang) ?: $term_id);
		if ($translated > 0) {
			$term_id = $translated;
		}
	}

	$link = function_exists('europulse_category_archive_url')
		? europulse_category_archive_url($term_id, $lang)
		: get_term_link($term_id, 'category');
	if (! is_wp_error($link) && is_string($link) && $link !== '') {
		$atts['href'] = $link;
	}

	return $atts;
}, 20, 4);

add_filter('query_loop_block_query_vars', function ($query, $block) {
	if (is_admin() || ! function_exists('pll_current_language')) {
		return $query;
	}

	$lang = pll_current_language('slug');

	if ($lang) {
		$query['lang'] = $lang;
	}

	if (! empty($query['tax_query']) && is_array($query['tax_query'])) {
		foreach ($query['tax_query'] as $index => $tax_query) {
			if (! is_array($tax_query) || empty($tax_query['taxonomy']) || empty($tax_query['terms']) || ! function_exists('pll_get_term')) {
				continue;
			}

			$translated_terms = [];

			foreach ((array) $tax_query['terms'] as $term_id) {
				$translated_terms[] = pll_get_term((int) $term_id, $lang) ?: (int) $term_id;
			}

			$query['tax_query'][$index]['terms'] = array_values(array_filter($translated_terms));
		}
	}

	return $query;
}, 10, 2);

add_filter('post_class', function ($classes, $class, $post_id) {
	if (europulse_is_breaking($post_id)) {
		$classes[] = 'europulse-breaking';
	}

	if (europulse_is_sponsored($post_id)) {
		$classes[] = 'europulse-sponsored';
	}

	if (europulse_has_video($post_id)) {
		$classes[] = 'europulse-has-video';
	}

	return $classes;
}, 10, 3);

add_action('get_template_part_template-parts/content', function ($slug, $name, $args) {
	if ($name !== 'none') {
		return;
	}

	if (! (is_category() || is_tag() || is_tax())) {
		return;
	}

	$lang = function_exists('pll_current_language') ? (string) pll_current_language('slug') : 'de';

	$messages = [
		'de' => [
			'title' => 'Hier entsteht gerade etwas.',
			'body'  => 'In dieser Rubrik gibt es noch keine veröffentlichten Beiträge. Schauen Sie bald wieder vorbei oder besuchen Sie unsere Startseite.',
			'cta'   => 'Zur Startseite',
		],
		'en' => [
			'title' => 'New stories coming soon.',
			'body'  => 'No articles have been published in this section yet. Please check back later or visit the homepage.',
			'cta'   => 'Go to homepage',
		],
		'uk' => [
			'title' => 'Скоро тут з\'являться нові матеріали.',
			'body'  => 'У цій рубриці поки що немає опублікованих матеріалів. Завітайте пізніше або перейдіть на головну сторінку.',
			'cta'   => 'На головну',
		],
	];

	$copy = $messages[$lang] ?? $messages['de'];

	$home = function_exists('pll_home_url') ? pll_home_url($lang) : home_url('/');

	echo '<div class="europulse-empty-archive" style="margin:2rem 0 1rem;padding:1.75rem 1.5rem;background:#f5f7fb;border-radius:8px;text-align:center;">';
	echo '<h2 style="margin:0 0 0.5rem;font-size:1.25rem;color:#1a3268;">' . esc_html($copy['title']) . '</h2>';
	echo '<p style="margin:0 0 1rem;color:#4b5563;">' . esc_html($copy['body']) . '</p>';
	echo '<a href="' . esc_url($home) . '" style="display:inline-block;padding:0.6rem 1.25rem;background:#1a3268;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">' . esc_html($copy['cta']) . '</a>';
	echo '</div>';
}, 10, 3);

add_filter('get_search_form', function ($form) {
	if (is_admin() || ! (is_category() || is_tag() || is_tax())) {
		return $form;
	}

	global $wp_query;
	if ($wp_query instanceof WP_Query && (int) $wp_query->post_count === 0) {
		return '';
	}

	return $form;
}, 20);

function europulse_localize_shared_widget_content(string $content): string {
	if (is_admin() || ! function_exists('pll_current_language')) {
		return $content;
	}

	$lang = (string) pll_current_language('slug');
	if (! in_array($lang, ['de', 'en', 'uk'], true)) {
		return $content;
	}

	$copy = [
		'de' => [
			'important' => 'Wichtig im Blick',
			'description' => 'Ukrainisch betriebenes, mehrsprachiges Nachrichten- und Blogprojekt über die Ukraine, Europa und das Leben in Deutschland.',
			'sections' => 'Rubriken',
			'service' => 'Redaktion & Service',
			'search' => 'Suche',
			'home' => 'Startseite',
		],
		'en' => [
			'important' => 'Important Now',
			'description' => 'A Ukrainian-operated multilingual news and blog project covering Ukraine, Europe and life in Germany.',
			'sections' => 'Sections',
			'service' => 'Editorial & Service',
			'search' => 'Search',
			'home' => 'Home',
		],
		'uk' => [
			'important' => 'Важливо зараз',
			'description' => 'Український багатомовний новинний і блоговий проєкт про Україну, Європу та життя в Німеччині.',
			'sections' => 'Рубрики',
			'service' => 'Редакція і сервіс',
			'search' => 'Пошук',
			'home' => 'Головна',
		],
	][$lang];

	$content = str_replace(
		[
			'>Wichtig im Blick<',
			'>Ruhiges Nachrichtenportal für Deutschland, Europa, die Ukraine und Community-Themen.<',
			'>Rubriken<',
			'>Redaktion &amp; Service<',
			'>Search<',
			'placeholder="Search"',
			'aria-label="Search"',
		],
		[
			'>' . esc_html($copy['important']) . '<',
			'>' . esc_html($copy['description']) . '<',
			'>' . esc_html($copy['sections']) . '<',
			'>' . esc_html($copy['service']) . '<',
			'>' . esc_html($copy['search']) . '<',
			'placeholder="' . esc_attr($copy['search']) . '"',
			'aria-label="' . esc_attr($copy['search']) . '"',
		],
		$content
	);

	$category_slugs = [
		'Deutschland' => 'deutschland',
		'München' => 'muenchen',
		'Bayern' => 'bayern',
		'Ukraine' => 'ukraine',
		'Politik' => 'politik',
		'Wirtschaft' => 'wirtschaft',
		'Welt' => 'welt',
		'Sport' => 'sport',
		'Meinung' => 'meinung',
		'Leben in Deutschland' => 'leben-in-deutschland',
		'Kultur' => 'kultur',
		'Community' => 'community',
	];

	$content = preg_replace_callback(
		'/<a\b[^>]*>(Startseite|Deutschland|München|Bayern|Ukraine|Politik|Wirtschaft|Welt|Sport|Meinung|Leben in Deutschland|Kultur|Community)<\/a>/u',
		static function (array $matches) use ($lang, $copy, $category_slugs): string {
			$label = (string) ($matches[1] ?? '');
			if ($label === 'Startseite') {
				$home = function_exists('pll_home_url') ? pll_home_url($lang) : home_url('/');
				return '<a href="' . esc_url($home) . '">' . esc_html($copy['home']) . '</a>';
			}

			$slug = $category_slugs[$label] ?? '';
			if ($slug === '' || ! function_exists('europulse_category_archive_url')) {
				return $matches[0];
			}

			$url = europulse_category_archive_url($slug, $lang);
			if ($url === '') {
				return $matches[0];
			}

			$name = $label;
			if (class_exists('EPV2_Taxonomy_Map')) {
				$mapped = EPV2_Taxonomy_Map::map($slug, $lang);
				$term_id = (int) ($mapped['term_id'] ?? 0);
				$term = $term_id > 0 ? get_term($term_id, 'category') : null;
				if ($term instanceof WP_Term && ! is_wp_error($term)) {
					$name = (string) $term->name;
				}
			}

			return '<a href="' . esc_url($url) . '">' . esc_html($name) . '</a>';
		},
		$content
	);

	return is_string($content) ? $content : '';
}

add_filter('widget_block_content', 'europulse_localize_shared_widget_content', 20);

function europulse_cookie_page_url(string $type): string {
	$lang = function_exists('europulse_current_lang') ? europulse_current_lang() : 'de';
	$ids = [
		'cookie' => ['de' => 42, 'uk' => 290, 'en' => 291],
		'privacy' => ['de' => 3, 'uk' => 294, 'en' => 295],
	];
	$post_id = (int) ($ids[$type][$lang] ?? $ids[$type]['de'] ?? 0);
	if ($post_id > 0) {
		$url = get_permalink($post_id);
		if (is_string($url) && $url !== '') {
			return $url;
		}
	}
	return home_url('/');
}

function europulse_cookie_banner_copy(): array {
	$lang = function_exists('europulse_current_lang') ? europulse_current_lang() : 'de';
	$copy = [
		'de' => [
			'title' => 'Cookie-Einstellungen',
			'body' => 'Wir verwenden notwendige Cookies für Betrieb, Sicherheit, Sprache und Einwilligungsverwaltung. Optionale Analyse- oder Werbetechnologien werden nur nach Ihrer Zustimmung aktiviert.',
			'accept' => 'Alle akzeptieren',
			'essential' => 'Nur notwendige',
			'settings' => 'Einstellungen',
			'privacy' => 'Datenschutz',
		],
		'uk' => [
			'title' => 'Налаштування cookie',
			'body' => 'Ми використовуємо необхідні cookie для роботи сайту, безпеки, мови та керування згодою. Необовʼязкова аналітика або реклама активуються лише після вашої згоди.',
			'accept' => 'Прийняти всі',
			'essential' => 'Лише необхідні',
			'settings' => 'Налаштування',
			'privacy' => 'Конфіденційність',
		],
		'en' => [
			'title' => 'Cookie settings',
			'body' => 'We use necessary cookies for site operation, security, language and consent management. Optional analytics or advertising technologies are activated only after your consent.',
			'accept' => 'Accept all',
			'essential' => 'Essential only',
			'settings' => 'Settings',
			'privacy' => 'Privacy',
		],
	];
	return $copy[$lang] ?? $copy['de'];
}

function europulse_cookie_consent_value(): string {
	$value = isset($_COOKIE['ep_cookie_consent']) ? sanitize_key((string) wp_unslash($_COOKIE['ep_cookie_consent'])) : '';
	return in_array($value, ['all', 'essential'], true) ? $value : '';
}

add_action('wp_head', function (): void {
	if (is_admin()) {
		return;
	}
	$granted = europulse_cookie_consent_value() === 'all';
	$state = $granted ? 'granted' : 'denied';
	?>
	<script>
		window.dataLayer = window.dataLayer || [];
		function gtag(){dataLayer.push(arguments);}
		gtag('consent', 'default', {
			ad_storage: '<?php echo esc_js($state); ?>',
			analytics_storage: '<?php echo esc_js($state); ?>',
			ad_user_data: '<?php echo esc_js($state); ?>',
			ad_personalization: '<?php echo esc_js($state); ?>',
			wait_for_update: 500
		});
	</script>
	<?php
}, 0);

add_action('wp_footer', function (): void {
	if (is_admin() || europulse_cookie_consent_value() !== '') {
		return;
	}
	$copy = europulse_cookie_banner_copy();
	?>
	<div class="europulse-cookie-banner" data-ep-cookie-banner role="dialog" aria-live="polite" aria-label="<?php echo esc_attr($copy['title']); ?>">
		<div class="europulse-cookie-banner__copy">
			<strong><?php echo esc_html($copy['title']); ?></strong>
			<p><?php echo esc_html($copy['body']); ?></p>
			<div class="europulse-cookie-banner__links">
				<a href="<?php echo esc_url(europulse_cookie_page_url('cookie')); ?>"><?php echo esc_html($copy['settings']); ?></a>
				<a href="<?php echo esc_url(europulse_cookie_page_url('privacy')); ?>"><?php echo esc_html($copy['privacy']); ?></a>
			</div>
		</div>
		<div class="europulse-cookie-banner__actions">
			<button type="button" class="europulse-cookie-banner__secondary" data-ep-cookie-choice="essential"><?php echo esc_html($copy['essential']); ?></button>
			<button type="button" class="europulse-cookie-banner__primary" data-ep-cookie-choice="all"><?php echo esc_html($copy['accept']); ?></button>
		</div>
	</div>
	<script>
		(() => {
			const banner = document.querySelector('[data-ep-cookie-banner]');
			if (! banner) {
				return;
			}
			const consentState = (choice) => choice === 'all' ? 'granted' : 'denied';
			const applyConsent = (choice) => {
				const expires = new Date(Date.now() + 180 * 24 * 60 * 60 * 1000).toUTCString();
				document.cookie = 'ep_cookie_consent=' + encodeURIComponent(choice) + '; expires=' + expires + '; path=/; secure; SameSite=Lax';
				window.dataLayer = window.dataLayer || [];
				if (typeof window.gtag === 'function') {
					const state = consentState(choice);
					window.gtag('consent', 'update', {
						ad_storage: state,
						analytics_storage: state,
						ad_user_data: state,
						ad_personalization: state
					});
				}
				banner.hidden = true;
			};
			banner.querySelectorAll('[data-ep-cookie-choice]').forEach((button) => {
				button.addEventListener('click', () => applyConsent(button.dataset.epCookieChoice || 'essential'));
			});
		})();
	</script>
	<?php
}, 30);
