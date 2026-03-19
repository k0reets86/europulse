<?php

$widgets = get_option('widget_block', []);

$widgets['5']['content'] = <<<'HTML'
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">EuroPulse</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Ruhiges Nachrichtenportal für Deutschland, Europa, die Ukraine und Community-Themen.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
HTML;

$widgets['6']['content'] = <<<'HTML'
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Rubriken</h3>
<!-- /wp:heading -->
<!-- wp:list -->
<ul><li><a href="/">Startseite</a></li><li><a href="/?cat=14">Deutschland</a></li><li><a href="/?cat=16">Ukraine</a></li><li><a href="/?cat=18">Europa</a></li><li><a href="/?cat=22">Politik</a></li><li><a href="/?cat=24">Wirtschaft</a></li><li><a href="/?cat=26">Leben in Deutschland</a></li><li><a href="/?cat=28">Kultur</a></li><li><a href="/?cat=30">Sport</a></li><li><a href="/?cat=46">Community</a></li></ul>
<!-- /wp:list --></div>
<!-- /wp:group -->
HTML;

$widgets['7']['content'] = <<<'HTML'
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Redaktion &amp; Service</h3>
<!-- /wp:heading -->
<!-- wp:list -->
<ul><li><a href="/?page_id=36">Über uns</a></li><li><a href="/?page_id=37">Kontakt</a></li><li><a href="/?page_id=38">Werbung</a></li><li><a href="/?page_id=39">Community einreichen</a></li><li><a href="/?page_id=40">Korrekturen</a></li><li><a href="/?page_id=310">Archiv</a></li></ul>
<!-- /wp:list --></div>
<!-- /wp:group -->
HTML;

$widgets['8']['content'] = <<<'HTML'
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Rechtliches</h3>
<!-- /wp:heading -->
<!-- wp:list -->
<ul><li><a href="/?page_id=41">Impressum</a></li><li><a href="/?page_id=3">Datenschutz</a></li><li><a href="/?page_id=42">Cookie-Einstellungen</a></li><li><a href="/?page_id=43">Nutzungsbedingungen</a></li></ul>
<!-- /wp:list --></div>
<!-- /wp:group -->
HTML;

update_option('widget_block', $widgets);

echo "footer widgets updated\n";
