<?php
/**
 * Admin: the rail beside every screen ("Right now", a note, related screens).
 *
 * Local variables are populated by Admin::render_screen_content() in the
 * calling scope. Suppress the prefix sniff:
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * @package DiluxOneOffload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array{state: array{line: string, pill: string, label: string, why: string}, note: array{title: string, body: string[]}, links: array<int, array{label: string, url: string}>} $rail */
?>
<div class="diluxone-offload-rail-state">
	<div class="diluxone-offload-rail-state__label"><?php esc_html_e( 'Right now', 'diluxone-offload' ); ?></div>
	<p class="diluxone-offload-rail-state__line"><?php echo esc_html( $rail['state']['line'] ); ?></p>
	<span class="diluxone-offload-pill diluxone-offload-pill--<?php echo esc_attr( $rail['state']['pill'] ); ?>">
		<?php echo esc_html( $rail['state']['label'] ); ?>
		<?php if ( $rail['state']['why'] !== '' ) : ?>
			<span class="diluxone-offload-pill__why"><?php echo esc_html( $rail['state']['why'] ); ?></span>
		<?php endif; ?>
	</span>
</div>

<div class="diluxone-offload-rail-note">
	<span class="diluxone-offload-rail-note__title"><?php echo esc_html( $rail['note']['title'] ); ?></span>
	<?php foreach ( $rail['note']['body'] as $paragraph ) : ?>
		<p><?php echo esc_html( $paragraph ); ?></p>
	<?php endforeach; ?>
</div>

<?php if ( $rail['links'] !== array() ) : ?>
<div class="diluxone-offload-rail-links">
	<span class="diluxone-offload-rail-links__title"><?php esc_html_e( 'Related', 'diluxone-offload' ); ?></span>
	<ul>
		<?php foreach ( $rail['links'] as $item ) : ?>
			<li><a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li>
		<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>
