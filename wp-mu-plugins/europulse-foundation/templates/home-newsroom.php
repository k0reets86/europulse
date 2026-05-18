<?php
get_header();

$page_for_posts = (int) get_option('page_for_posts');
$archive_title = $page_for_posts > 0 ? get_the_title($page_for_posts) : single_post_title('', false);
?>
<main id="main" class="site-main hfeed" itemscope="itemscope" itemtype="https://schema.org/CollectionPage">
	<div class="ct-container europulse-newsroom-layout" data-sidebar="right" data-vertical-spacing="top:bottom">
		<section class="europulse-newsroom-main">
			<article class="post page type-page status-publish">
				<header class="entry-header">
					<h1 class="page-title"><?php echo esc_html($archive_title !== '' ? $archive_title : europulse_t('all_news')); ?></h1>
				</header>
				<div class="entry-content">
					<?php echo do_shortcode('[europulse_latest_list posts="30" thumbs="0"]'); ?>
				</div>
			</article>
		</section>
		<aside class="europulse-newsroom-sidebar ct-hidden-sm ct-hidden-md" aria-label="<?php echo esc_attr(europulse_t('important_now')); ?>">
			<div class="europulse-newsroom-widget europulse-newsroom-widget--search">
				<?php get_search_form(); ?>
			</div>
			<div class="europulse-newsroom-widget europulse-newsroom-widget--important">
				<h3 class="europulse-newsroom-widget-title"><?php echo esc_html(europulse_t('important_now')); ?></h3>
				<?php echo do_shortcode('[europulse_most_read posts="5" thumbs="1"]'); ?>
			</div>
			<div class="europulse-newsroom-widget europulse-newsroom-widget--ad">
				<?php echo do_shortcode('[europulse_ad_slot slot="sidebar-rail" class="europulse-ad-slot--rail"]'); ?>
			</div>
		</aside>
	</div>
</main>
<?php
get_footer();
