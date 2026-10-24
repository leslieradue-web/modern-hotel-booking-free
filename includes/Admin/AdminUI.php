<?php declare(strict_types=1);

namespace MHBO\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminUI
 *
 * Provides standardized UI components for the MHBO admin interface.
 * Implements WordPress Best Practices 2026.
 */
class AdminUI {

	/**
	 * Renders a standardized page header.
	 *
	 * @param string                            $title       The main page title.
	 * @param string                            $subtitle    Optional subtitle or description.
	 * @param array<int, array<string, string>> $actions Optional array of action buttons [ ['label' => '', 'url' => '', 'class' => 'button-primary'] ].
	 * @param array<int, array<string, string>> $breadcrumbs Optional array of breadcrumbs [ ['label' => '', 'url' => ''] ].
	 */
	public static function render_header( string $title, string $subtitle = '', array $actions = array(), array $breadcrumbs = array() ): void {
		?>
		<div class="mhbo-admin-page-header mhbo-animate-in">
			<div class="mhbo-header-content">
				<?php if ( count( $breadcrumbs ) > 0 ) : ?>
					<nav class="mhbo-breadcrumbs" style="margin-bottom: 12px;">
						<?php foreach ( $breadcrumbs as $crumb ) : ?>
							<?php
							$crumb_label = (string) ( $crumb['label'] ?? '' );
							$crumb_url   = (string) ( $crumb['url'] ?? '' );
							if ( '' === $crumb_label || ( empty( $crumb_url ) && $crumb_label === $title ) ) {
								continue;
							}
							?>
							<?php if ( ! empty( $crumb_url ) ) : ?>
								<a href="<?php echo esc_url( $crumb_url ); ?>"><?php echo esc_html( $crumb_label ); ?></a>
								<span class="mhbo-breadcrumb-sep">/</span>
							<?php else : ?>
								<span><?php echo esc_html( $crumb_label ); ?></span>
								<span class="mhbo-breadcrumb-sep">/</span>
							<?php endif; ?>
						<?php endforeach; ?>
						<span class="mhbo-breadcrumb-current"><?php echo esc_html( $title ); ?></span>
					</nav>
				<?php endif; ?>
				
				<h1><?php echo esc_html( $title ); ?></h1>
				<?php if ( $subtitle ) : ?>
					<span class="subtitle"><?php echo esc_html( $subtitle ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( count( $actions ) > 0 ) : ?>
				<div class="mhbo-header-actions">
					<?php foreach ( $actions as $action ) : ?>
						<a href="<?php echo esc_url( $action['url'] ); ?>" class="button <?php echo esc_attr( $action['class'] ?? 'button-secondary' ); ?>"<?php echo ! empty( $action['target'] ) ? ' target="' . esc_attr( $action['target'] ) . '"' : ''; ?>>
							<?php echo esc_html( $action['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the start of a stylized card.
	 *
	 * @param string $title       Optional card title.
	 * @param string $extra_class Additional CSS classes.
	 */
	public static function render_card_start( string $title = '', string $extra_class = '' ): void {
		$full_class = trim( 'mhbo-card ' . $extra_class );
		?>
		<div class="<?php echo esc_attr( $full_class ); ?> mhbo-animate-in">
			<?php if ( $title ) : ?>
				<h3 class="mhbo-card-title"><?php echo esc_html( $title ); ?></h3>
			<?php endif; ?>
			<div class="mhbo-card-content">
		<?php
	}

	/**
	 * Renders the end of a stylized card.
	 */
	public static function render_card_end(): void {
		?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a tabbed navigation bar.
	 *
	 * @param array<string, string> $tabs Associative array of tabs [ 'slug' => 'Label' ].
	 * @param string                $active_tab The slug of the currently active tab.
	 * @param string                $base_url   The base URL for the tab links.
	 * @param array<string, string> $icons Optional mapping of tab slugs to Dashicons [ 'slug' => 'dashicons-...' ].
	 */
	public static function render_tabs( array $tabs, string $active_tab, string $base_url, array $icons = array() ): void {
		?>
		<nav class="nav-tab-wrapper wp-clearfix" style="border-bottom: 2px solid #dcdcde; margin-bottom: 30px; display: flex; gap: 5px;">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<?php $has_icon = isset( $icons[ $slug ] ) && '' !== $icons[ $slug ]; ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base_url ) ); ?>" 
					class="nav-tab <?php echo esc_attr( $active_tab === $slug ? 'nav-tab-active' : '' ); ?>"
					style="display: flex; align-items: center; gap: 8px;">
					<?php if ( $has_icon ) : ?>
						<span class="dashicons <?php echo esc_attr( $icons[ $slug ] ); ?>" style="font-size: 18px; width: 18px; height: 18px;"></span>
					<?php endif; ?>
					<span><?php echo esc_html( $label ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Renders a status badge.
	 *
	 * @param string $text The badge text.
	 * @param string $status The status slug (pending, confirmed, cancelled).
	 */
	public static function render_status_badge( string $text, string $status ): void {
		$status_class = 'mhbo-status-' . sanitize_html_class( $status );
		?>
		<span class="mhbo-status-badge <?php echo esc_attr( $status_class ); ?>">
			<?php echo esc_html( $text ); ?>
		</span>
		<?php
	}

	/**
	 * Renders a standardized, accessible pagination bar with per-page selector.
	 *
	 * @param int             $total_items Total number of items.
	 * @param int             $per_page Items per page (-1 for all).
	 * @param int             $current_page Current page number (1-based).
	 * @param string          $base_url Base URL with preserved query parameters.
	 * @param array<int, int> $per_page_options Allowed per-page settings.
	 */
	public static function render_pagination(
		int $total_items,
		int $per_page,
		int $current_page,
		string $base_url,
		array $per_page_options = array( 10, 25, 50, 100, -1 )
	): void {
		$total_pages  = ( $per_page > 0 ) ? max( 1, (int) ceil( $total_items / $per_page ) ) : 1;
		$current_page = max( 1, min( $current_page, $total_pages ) );

		if ( $per_page > 0 ) {
			$start_item = $total_items > 0 ? ( ( $current_page - 1 ) * $per_page ) + 1 : 0;
			$end_item   = min( $total_items, $current_page * $per_page );
		} else {
			$start_item = $total_items > 0 ? 1 : 0;
			$end_item   = $total_items;
		}
		?>
		<div class="mhbo-table-pagination" aria-label="<?php esc_attr_e( 'Table pagination navigation', 'modern-hotel-booking' ); ?>">
			<!-- 1. Results Summary Counter -->
			<div class="mhbo-pagination-info">
				<?php if ( $total_items <= 0 ) : ?>
					<span class="mhbo-pagination-empty"><?php esc_html_e( 'No bookings found', 'modern-hotel-booking' ); ?></span>
				<?php elseif ( $per_page <= 0 || $total_pages === 1 ) : ?>
					<span class="mhbo-pagination-text">
						<?php
						printf(
							/* translators: %s: formatted total items count */
							esc_html__( 'Showing all %s bookings', 'modern-hotel-booking' ),
							'<strong class="mhbo-pagination-count">' . esc_html( number_format_i18n( $total_items ) ) . '</strong>'
						);
						?>
					</span>
				<?php else : ?>
					<span class="mhbo-pagination-text">
						<?php
						printf(
							/* translators: 1: formatted start item index, 2: formatted end item index, 3: formatted total item count */
							esc_html__( 'Showing %1$s–%2$s of %3$s bookings', 'modern-hotel-booking' ),
							'<strong class="mhbo-pagination-range">' . esc_html( number_format_i18n( $start_item ) ) . '</strong>',
							'<strong class="mhbo-pagination-range">' . esc_html( number_format_i18n( $end_item ) ) . '</strong>',
							'<strong class="mhbo-pagination-count">' . esc_html( number_format_i18n( $total_items ) ) . '</strong>'
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<!-- 2. Per-Page Selector (10, 25, 50, 100, All) -->
			<div class="mhbo-per-page-group">
				<span class="mhbo-per-page-label">
					<span class="dashicons dashicons-list-view" style="font-size:14px; width:14px; height:14px;"></span>
					<?php esc_html_e( 'Show:', 'modern-hotel-booking' ); ?>
				</span>
				<div class="mhbo-per-page-pills" role="group" aria-label="<?php esc_attr_e( 'Bookings per page', 'modern-hotel-booking' ); ?>">
					<?php
					foreach ( $per_page_options as $opt ) :
						$is_all    = ( $opt === -1 );
						$label     = $is_all ? _x( 'All', 'Show all records per page', 'modern-hotel-booking' ) : (string) $opt;
						$opt_val   = $is_all ? 'all' : (string) $opt;
						$is_active = ( $opt === $per_page ) || ( $is_all && $per_page <= 0 );
						$opt_url   = add_query_arg(
							array(
								'per_page' => $opt_val,
								'paged'    => 1,
							),
							$base_url 
						);
						?>
						<a href="<?php echo esc_url( $opt_url ); ?>"
							class="mhbo-per-page-pill <?php echo $is_active ? 'active' : ''; ?>"
							data-per-page="<?php echo esc_attr( $opt_val ); ?>"
							aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>">
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- 3. Navigation Controls (First, Prev, Page numbers, Next, Last, Jump) -->
			<?php if ( $total_pages > 1 ) : ?>
				<nav class="mhbo-pagination-nav" aria-label="<?php esc_attr_e( 'Bookings page navigation', 'modern-hotel-booking' ); ?>">
					<!-- First Page -->
					<?php if ( $current_page > 1 ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'paged', 1, $base_url ) ); ?>" 
							class="mhbo-page-btn mhbo-page-nav-btn mhbo-page-first" 
							title="<?php esc_attr_e( 'First page', 'modern-hotel-booking' ); ?>"
							aria-label="<?php esc_attr_e( 'First page', 'modern-hotel-booking' ); ?>">
							<span class="dashicons dashicons-controls-skipback"></span>
						</a>
						<a href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ); ?>" 
							class="mhbo-page-btn mhbo-page-nav-btn mhbo-page-prev" 
							title="<?php esc_attr_e( 'Previous page', 'modern-hotel-booking' ); ?>"
							aria-label="<?php esc_attr_e( 'Previous page', 'modern-hotel-booking' ); ?>">
							<span class="dashicons dashicons-arrow-left-alt2"></span>
						</a>
					<?php else : ?>
						<span class="mhbo-page-btn mhbo-page-nav-btn mhbo-page-first disabled" aria-disabled="true">
							<span class="dashicons dashicons-controls-skipback"></span>
						</span>
						<span class="mhbo-page-btn mhbo-page-nav-btn mhbo-page-prev disabled" aria-disabled="true">
							<span class="dashicons dashicons-arrow-left-alt2"></span>
						</span>
					<?php endif; ?>

					<!-- Numbered Pages with Sliding Window -->
					<div class="mhbo-pagination-pages">
						<?php
						$window_size   = 2; // Show 2 pages around current
						$pages_to_show = array();

						$pages_to_show[] = 1;
						$end_page        = min( $total_pages - 1, $current_page + $window_size );
						for ( $i = max( 2, $current_page - $window_size ); $i <= $end_page; $i++ ) {
							$pages_to_show[] = $i;
						}
						if ( $total_pages > 1 ) {
							$pages_to_show[] = $total_pages;
						}
						$pages_to_show = array_unique( $pages_to_show );
						sort( $pages_to_show );

						$last_p = 0;
						foreach ( $pages_to_show as $p ) :
							if ( $last_p > 0 && $p - $last_p > 1 ) :
								echo '<span class="mhbo-page-dots" aria-hidden="true">&hellip;</span>';
							endif;
							$last_p = $p;

							if ( $p === $current_page ) :
								?>
								<span class="mhbo-page-btn mhbo-page-num active" aria-current="page">
									<?php echo (int) $p; ?>
								</span>
								<?php
							else :
								/* translators: %d: page number */
								$page_aria_label = sprintf( __( 'Page %d', 'modern-hotel-booking' ), (int) $p );
								?>
								<a href="<?php echo esc_url( add_query_arg( 'paged', $p, $base_url ) ); ?>" 
									class="mhbo-page-btn mhbo-page-num" 
									aria-label="<?php echo esc_attr( $page_aria_label ); ?>">
									<?php echo (int) $p; ?>
								</a>
								<?php
							endif;
						endforeach;
						?>
					</div>

					<!-- Next & Last Page -->
					<?php if ( $current_page < $total_pages ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ); ?>" 
							class="mhbo-page-btn mhbo-page-nav-btn mhbo-page-next" 
							title="<?php esc_attr_e( 'Next page', 'modern-hotel-booking' ); ?>"
							aria-label="<?php esc_attr_e( 'Next page', 'modern-hotel-booking' ); ?>">
							<span class="dashicons dashicons-arrow-right-alt2"></span>
						</a>
						<a href="<?php echo esc_url( add_query_arg( 'paged', $total_pages, $base_url ) ); ?>" 
							class="mhbo-page-btn mhbo-page-nav-btn mhbo-page-last" 
							title="<?php esc_attr_e( 'Last page', 'modern-hotel-booking' ); ?>"
							aria-label="<?php esc_attr_e( 'Last page', 'modern-hotel-booking' ); ?>">
							<span class="dashicons dashicons-controls-skipforward"></span>
						</a>
					<?php else : ?>
						<span class="mhbo-page-btn mhbo-page-nav-btn mhbo-page-next disabled" aria-disabled="true">
							<span class="dashicons dashicons-arrow-right-alt2"></span>
						</span>
						<span class="mhbo-page-btn mhbo-page-nav-btn mhbo-page-last disabled" aria-disabled="true">
							<span class="dashicons dashicons-controls-skipforward"></span>
						</span>
					<?php endif; ?>

					<!-- Jump Input for Direct Jump -->
					<?php if ( $total_pages > 5 ) : ?>
						<div class="mhbo-pagination-jump">
							<label for="mhbo-page-jump-input" class="screen-reader-text"><?php esc_html_e( 'Jump to page', 'modern-hotel-booking' ); ?></label>
							<input type="number" 
									id="mhbo-page-jump-input" 
									class="mhbo-jump-input" 
									min="1" 
									max="<?php echo (int) $total_pages; ?>" 
									value="<?php echo (int) $current_page; ?>" 
									data-base-url="<?php echo esc_url( remove_query_arg( 'paged', $base_url ) ); ?>" 
									title="<?php esc_attr_e( 'Type page number and press Enter to jump', 'modern-hotel-booking' ); ?>">
							<span class="mhbo-jump-total">/ <?php echo (int) $total_pages; ?></span>
						</div>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}
}
