<?php
/**
 * Plugin Name: EuroPulse GA4
 * Description: Вставляет Google Analytics 4 (gtag.js) в <head>. Не грузится в
 * админке и для залогиненных редакторов, чтобы не засорять статистику.
 */

if (! defined('ABSPATH')) {
	exit;
}

const EUROPULSE_GA4_ID = 'G-02W79GRGP0';

add_action('wp_head', static function (): void {
	if (EUROPULSE_GA4_ID === '' || is_admin() || is_user_logged_in()) {
		return;
	}
	$id = EUROPULSE_GA4_ID;
	?>
<!-- Google tag (gtag.js) — EuroPulse -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($id); ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?php echo esc_js($id); ?>');
</script>
<?php
}, 1);
