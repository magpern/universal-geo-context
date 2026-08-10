<?php
/**
 * Plugin-wide admin UI component renderer.
 *
 * @package UniversalGeoContext
 */

declare( strict_types=1 );

namespace UniversalGeo\Admin;

use UniversalGeo\Diagnostics\OperationalStatus;

/**
 * Renders reusable admin design-system components for Universal Geo Context.
 *
 * @internal
 * @final
 */
final class AdminComponentRenderer {

	/**
	 * Valid status badge variants.
	 *
	 * @var list<string>
	 */
	public const BADGE_VARIANTS = array(
		'active',
		'warning',
		'error',
		'disabled',
		'recommended',
		'available',
		'missing',
		'misconfigured',
	);

	/**
	 * Renders a sub-page introduction block.
	 *
	 * @param string $title       Visible title.
	 * @param string $description Supporting description.
	 */
	public function page_intro( string $title, string $description = '' ): string {
		$description_html = '' !== $description
			? sprintf( '<p class="ugc-ui-page-intro__description">%s</p>', esc_html( $description ) )
			: '';

		return sprintf(
			'<header class="ugc-ui-page-intro"><h3 class="ugc-ui-page-intro__title">%s</h3>%s</header>',
			esc_html( $title ),
			$description_html
		);
	}

	/**
	 * Opens a feature section landmark for grouping related cards.
	 *
	 * @param string $title       Section landmark title.
	 * @param string $description Optional supporting description.
	 */
	public function feature_section_open( string $title, string $description = '' ): string {
		$description_html = '' !== $description
			? sprintf( '<p class="ugc-ui-feature-section__description">%s</p>', esc_html( $description ) )
			: '';

		return sprintf(
			'<section class="ugc-ui-feature-section"><header class="ugc-ui-feature-section__header"><h4 class="ugc-ui-feature-section__title">%1$s</h4>%2$s</header><div class="ugc-ui-feature-section__content">',
			esc_html( $title ),
			$description_html
		);
	}

	/**
	 * Closes a feature section landmark.
	 */
	public function feature_section_close(): string {
		return '</div></section>';
	}

	/**
	 * Opens a statistics card grid.
	 */
	public function statistics_grid_open(): string {
		return '<div class="ugc-ui-statistics-grid">';
	}

	/**
	 * Closes a statistics card grid.
	 */
	public function statistics_grid_close(): string {
		return '</div>';
	}

	/**
	 * Renders one statistics card.
	 *
	 * @param string $label Metric label.
	 * @param string $value Metric value.
	 * @param string $hint  Optional supporting hint.
	 */
	public function statistics_card( string $label, string $value, string $hint = '' ): string {
		$hint_html = '' !== $hint
			? sprintf( '<span class="ugc-ui-statistics-card__hint">%s</span>', esc_html( $hint ) )
			: '';

		return sprintf(
			'<div class="ugc-ui-statistics-card"><span class="ugc-ui-statistics-card__label">%1$s</span><strong class="ugc-ui-statistics-card__value">%2$s</strong>%3$s</div>',
			esc_html( $label ),
			esc_html( $value ),
			$hint_html
		);
	}

	/**
	 * Opens a settings card.
	 *
	 * @param string $title       Card title.
	 * @param string $description Short description.
	 * @param string $badge_html  Optional badge markup in header.
	 */
	public function settings_card_open( string $title, string $description = '', string $badge_html = '' ): string {
		$description_html = '' !== $description
			? sprintf( '<p class="ugc-ui-settings-card__description">%s</p>', esc_html( $description ) )
			: '';

		$badge = '' !== $badge_html
			? sprintf( '<div class="ugc-ui-settings-card__badge">%s</div>', $badge_html ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped badge from status_badge().
			: '';

		return sprintf(
			'<section class="ugc-ui-settings-card"><header class="ugc-ui-settings-card__header"><div class="ugc-ui-settings-card__heading"><h4 class="ugc-ui-settings-card__title">%1$s</h4>%2$s</div>%3$s</header><div class="ugc-ui-settings-card__divider" aria-hidden="true"></div><div class="ugc-ui-settings-card__body">',
			esc_html( $title ),
			$description_html,
			$badge
		);
	}

	/**
	 * Renders an optional settings card footer.
	 *
	 * @param string $html Footer markup (escaped by caller when dynamic).
	 */
	public function settings_card_footer( string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		return sprintf(
			'</div><footer class="ugc-ui-settings-card__footer">%s</footer></section>',
			$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller supplies escaped or static markup.
		);
	}

	/**
	 * Closes a settings card without a footer.
	 */
	public function settings_card_close(): string {
		return '</div></section>';
	}

	/**
	 * Renders a toggle row backed by a native checkbox.
	 *
	 * @param string                $name        Input name.
	 * @param bool                  $checked     Whether checked.
	 * @param string                $label       Visible label.
	 * @param string                $description Optional description.
	 * @param array<string, string> $attrs       Extra input attributes.
	 */
	public function toggle_row(
		string $name,
		bool $checked,
		string $label,
		string $description = '',
		array $attrs = array()
	): string {
		$attr_html = $this->attr_html( $attrs );

		$description_html = '' !== $description
			? sprintf( '<span class="ugc-ui-toggle-row__description">%s</span>', esc_html( $description ) )
			: '';

		return sprintf(
			'<label class="ugc-ui-toggle-row"><input type="hidden" name="%1$s" value="0" /><input type="checkbox" name="%1$s" value="1"%2$s%3$s /><span class="ugc-ui-toggle-row__label">%4$s</span>%5$s</label>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			$attr_html,
			esc_html( $label ),
			$description_html
		);
	}

	/**
	 * Renders a select/dropdown row.
	 *
	 * @param string                $name        Field name.
	 * @param string                $label       Visible label.
	 * @param string                $description Optional description.
	 * @param string                $select_html Pre-built select element markup.
	 * @param array<string, string> $attrs       Extra attributes for the wrapper id.
	 */
	public function select_row(
		string $name,
		string $label,
		string $description,
		string $select_html,
		array $attrs = array()
	): string {
		$id = $attrs['id'] ?? sanitize_key( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );

		$description_html = '' !== $description
			? sprintf( '<span class="ugc-ui-field-row__description">%s</span>', esc_html( $description ) )
			: '';

		return sprintf(
			'<div class="ugc-ui-field-row ugc-ui-field-row--select"><label class="ugc-ui-field-row__label" for="%1$s">%2$s</label>%3$s<div class="ugc-ui-field-row__control">%4$s</div></div>',
			esc_attr( (string) $id ),
			esc_html( $label ),
			$description_html,
			$select_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by caller with escaped options.
		);
	}

	/**
	 * Renders a text input row.
	 *
	 * @param string                $name        Field name.
	 * @param string                $label       Visible label.
	 * @param string                $value       Current value.
	 * @param string                $description Optional description.
	 * @param array<string, string> $attrs       Extra input attributes.
	 */
	public function input_row(
		string $name,
		string $label,
		string $value,
		string $description = '',
		array $attrs = array()
	): string {
		return $this->field_row( 'text', $name, $label, $value, $description, $attrs );
	}

	/**
	 * Renders a number input row.
	 *
	 * @param string                $name        Field name.
	 * @param string                $label       Visible label.
	 * @param string                $value       Current value.
	 * @param string                $description Optional description.
	 * @param array<string, string> $attrs       Extra input attributes.
	 */
	public function number_row(
		string $name,
		string $label,
		string $value,
		string $description = '',
		array $attrs = array()
	): string {
		return $this->field_row( 'number', $name, $label, $value, $description, $attrs );
	}

	/**
	 * Renders a password input row.
	 *
	 * @param string                $name        Field name.
	 * @param string                $label       Visible label.
	 * @param string                $placeholder Placeholder text.
	 * @param string                $description Optional description.
	 * @param array<string, string> $attrs       Extra input attributes.
	 */
	public function password_row(
		string $name,
		string $label,
		string $placeholder = '',
		string $description = '',
		array $attrs = array()
	): string {
		$id        = $attrs['id'] ?? sanitize_key( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );
		$attr_html = $this->attr_html( array_diff_key( $attrs, array( 'id' => true ) ) );

		$description_html = '' !== $description
			? sprintf( '<span class="ugc-ui-field-row__description">%s</span>', esc_html( $description ) )
			: '';

		return sprintf(
			'<div class="ugc-ui-field-row ugc-ui-field-row--password"><label class="ugc-ui-field-row__label" for="%1$s">%2$s</label>%3$s<div class="ugc-ui-field-row__control"><input type="password" autocomplete="off" name="%4$s" id="%1$s" value="" placeholder="%5$s"%6$s /></div></div>',
			esc_attr( (string) $id ),
			esc_html( $label ),
			$description_html,
			esc_attr( $name ),
			esc_attr( $placeholder ),
			$attr_html
		);
	}

	/**
	 * Renders a textarea row.
	 *
	 * @param string                $name        Field name.
	 * @param string                $label       Visible label.
	 * @param string                $value       Current value.
	 * @param string                $description Optional description.
	 * @param array<string, string> $attrs       Extra textarea attributes.
	 */
	public function textarea_row(
		string $name,
		string $label,
		string $value,
		string $description = '',
		array $attrs = array()
	): string {
		$id        = $attrs['id'] ?? sanitize_key( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );
		$rows      = $attrs['rows'] ?? '4';
		$attr_html = $this->attr_html(
			array_diff_key(
				$attrs,
				array(
					'id'   => true,
					'rows' => true,
				)
			)
		);

		$description_html = '' !== $description
			? sprintf( '<span class="ugc-ui-field-row__description">%s</span>', esc_html( $description ) )
			: '';

		return sprintf(
			'<div class="ugc-ui-field-row ugc-ui-field-row--textarea"><label class="ugc-ui-field-row__label" for="%1$s">%2$s</label>%3$s<div class="ugc-ui-field-row__control"><textarea name="%4$s" id="%1$s" rows="%5$s"%6$s>%7$s</textarea></div></div>',
			esc_attr( (string) $id ),
			esc_html( $label ),
			$description_html,
			esc_attr( $name ),
			esc_attr( (string) $rows ),
			$attr_html,
			esc_textarea( $value )
		);
	}

	/**
	 * Renders a standardized status badge.
	 *
	 * @param string $label   Accessible label text.
	 * @param string $variant Badge variant.
	 */
	public function status_badge( string $label, string $variant = 'disabled' ): string {
		if ( ! in_array( $variant, self::BADGE_VARIANTS, true ) ) {
			$variant = 'disabled';
		}

		return sprintf(
			'<span class="ugc-ui-status-badge ugc-ui-status-badge--%1$s"><span class="ugc-ui-status-badge__dot" aria-hidden="true"></span><span class="ugc-ui-status-badge__label">%2$s</span></span>',
			esc_attr( $variant ),
			esc_html( $label )
		);
	}

	/**
	 * Renders a provider card.
	 *
	 * @param string $title         Provider title.
	 * @param string $description   Provider description.
	 * @param string $badge_label   Badge label.
	 * @param string $badge_variant Badge variant.
	 * @param string $body_html     Optional body markup (definition list, etc.).
	 * @param string $action_html   Optional action link markup.
	 */
	public function provider_card(
		string $title,
		string $description,
		string $badge_label,
		string $badge_variant,
		string $body_html = '',
		string $action_html = ''
	): string {
		$body = '' !== $body_html
			? sprintf( '<div class="ugc-ui-provider-card__body">%s</div>', $body_html ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by caller.
			: '';

		$action = '' !== $action_html
			? sprintf( '<footer class="ugc-ui-provider-card__footer">%s</footer>', $action_html ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by caller.
			: '';

		return sprintf(
			'<article class="ugc-ui-provider-card"><header class="ugc-ui-provider-card__header"><h5 class="ugc-ui-provider-card__title">%1$s</h5>%2$s</header><p class="ugc-ui-provider-card__description">%3$s</p>%4$s%5$s</article>',
			esc_html( $title ),
			$this->status_badge( $badge_label, $badge_variant ),
			esc_html( $description ),
			$body,
			$action
		);
	}

	/**
	 * Renders an information panel.
	 *
	 * @param string $title       Optional title.
	 * @param string $message     Message body (plain text).
	 * @param string $action_html Optional action markup.
	 */
	public function info_panel( string $title, string $message, string $action_html = '' ): string {
		return $this->panel( 'info', $title, $message, $action_html );
	}

	/**
	 * Renders a warning panel.
	 *
	 * @param string $title       Optional title.
	 * @param string $message     Message body (plain text).
	 * @param string $action_html Optional action markup.
	 */
	public function warning_panel( string $title, string $message, string $action_html = '' ): string {
		return $this->panel( 'warning', $title, $message, $action_html );
	}

	/**
	 * Renders a success panel.
	 *
	 * @param string $title       Optional title.
	 * @param string $message     Message body (plain text).
	 * @param string $action_html Optional action markup.
	 */
	public function success_panel( string $title, string $message, string $action_html = '' ): string {
		return $this->panel( 'success', $title, $message, $action_html );
	}

	/**
	 * Renders an empty state block.
	 *
	 * @param string $icon_class  Dashicon class.
	 * @param string $title       Title.
	 * @param string $message     Explanation.
	 * @param string $action_html Optional primary action markup.
	 */
	public function empty_state(
		string $icon_class,
		string $title,
		string $message,
		string $action_html = ''
	): string {
		$action = '' !== $action_html
			? sprintf( '<div class="ugc-ui-empty-state__actions">%s</div>', $action_html ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by caller.
			: '';

		return sprintf(
			'<div class="ugc-ui-empty-state"><span class="ugc-ui-empty-state__icon dashicons %1$s" aria-hidden="true"></span><h4 class="ugc-ui-empty-state__title">%2$s</h4><p class="ugc-ui-empty-state__message">%3$s</p>%4$s</div>',
			esc_attr( $icon_class ),
			esc_html( $title ),
			esc_html( $message ),
			$action
		);
	}

	/**
	 * Renders a quick actions panel.
	 *
	 * @param string                          $title   Panel title.
	 * @param array<int,array<string,string>> $actions Action definitions with label, url, description keys.
	 */
	public function quick_actions_panel( string $title, array $actions ): string {
		$items = '';

		foreach ( $actions as $action ) {
			$label = $action['label'] ?? '';
			$url   = $action['url'] ?? '';
			$desc  = $action['description'] ?? '';

			if ( '' === $label || '' === $url ) {
				continue;
			}

			$desc_html = '' !== $desc
				? sprintf( '<span class="ugc-ui-quick-action__description">%s</span>', esc_html( $desc ) )
				: '';

			$items .= sprintf(
				'<a class="ugc-ui-quick-action" href="%1$s"><span class="ugc-ui-quick-action__label">%2$s</span>%3$s</a>',
				esc_url( $url ),
				esc_html( $label ),
				$desc_html
			);
		}

		if ( '' === $items ) {
			return '';
		}

		return sprintf(
			'<section class="ugc-ui-quick-actions"><h4 class="ugc-ui-quick-actions__title">%s</h4><div class="ugc-ui-quick-actions__grid">%s</div></section>',
			esc_html( $title ),
			$items
		);
	}

	/**
	 * Renders the sticky save bar markup.
	 *
	 * @param string $scope Optional data attribute scope value.
	 */
	public function sticky_save_bar( string $scope = 'default' ): string {
		return sprintf(
			'<div class="ugc-ui-sticky-save submit" data-ugc-sticky-save data-ugc-sticky-scope="%1$s"><span class="ugc-ui-sticky-save__status" data-ugc-unsaved-indicator hidden>%2$s</span><button type="button" class="button button-link ugc-ui-sticky-save__discard" data-ugc-sticky-discard hidden>%3$s</button><button type="submit" name="save" value="%4$s" class="button button-primary ugc-ui-sticky-save__save">%4$s</button><span class="ugc-ui-sticky-save__saved" data-ugc-sticky-saved hidden>%5$s</span></div>',
			esc_attr( $scope ),
			esc_html__( 'Unsaved changes', 'universal-geo-context' ),
			esc_html__( 'Discard', 'universal-geo-context' ),
			esc_attr__( 'Save changes', 'universal-geo-context' ),
			esc_html__( 'All changes saved', 'universal-geo-context' )
		);
	}

	/**
	 * Renders pill navigation.
	 *
	 * @param string                         $aria_label Accessible nav label.
	 * @param array<int,array<string,mixed>> $items      Navigation items.
	 */
	public function pill_navigation( string $aria_label, array $items ): string {
		$list = '';

		foreach ( $items as $item ) {
			$url    = (string) ( $item['url'] ?? '' );
			$label  = (string) ( $item['label'] ?? '' );
			$icon   = (string) ( $item['icon'] ?? '' );
			$active = ! empty( $item['active'] );

			if ( '' === $url || '' === $label ) {
				continue;
			}

			$classes = array( 'ugc-ui-pill-nav__item' );

			if ( $active ) {
				$classes[] = 'ugc-ui-pill-nav__item--active';
			}

			$icon_html = '' !== $icon
				? sprintf( '<span class="ugc-ui-pill-nav__icon dashicons %s" aria-hidden="true"></span>', esc_attr( $icon ) )
				: '';

			$list .= sprintf(
				'<li class="%1$s"><a class="ugc-ui-pill-nav__link" href="%2$s"%3$s>%4$s<span class="ugc-ui-pill-nav__label">%5$s</span></a></li>',
				esc_attr( implode( ' ', $classes ) ),
				esc_url( $url ),
				$active ? ' aria-current="page"' : '',
				$icon_html,
				esc_html( $label )
			);
		}

		return sprintf(
			'<nav class="ugc-ui-pill-nav" aria-label="%1$s"><ul class="ugc-ui-pill-nav__list">%2$s</ul></nav>',
			esc_attr( $aria_label ),
			$list
		);
	}

	/**
	 * Opens a field group wrapper.
	 *
	 * @param string $extra_class Optional extra classes.
	 */
	public function field_group_open( string $extra_class = '' ): string {
		$classes = trim( 'ugc-ui-field-group ' . $extra_class );

		return sprintf( '<div class="%s">', esc_attr( $classes ) );
	}

	/**
	 * Closes a field group wrapper.
	 */
	public function field_group_close(): string {
		return '</div>';
	}

	/**
	 * Renders a readiness summary panel linking to diagnostics.
	 *
	 * @param OperationalStatus $status Readiness snapshot.
	 * @param string            $url    Diagnostics page URL.
	 */
	public function readiness_summary_panel( OperationalStatus $status, string $url ): string {
		$variants = array(
			OperationalStatus::STATE_READY           => array( 'active', __( 'Ready', 'universal-geo-context' ) ),
			OperationalStatus::STATE_DEGRADED        => array( 'warning', __( 'Degraded', 'universal-geo-context' ) ),
			OperationalStatus::STATE_ACTION_REQUIRED => array( 'warning', __( 'Action required', 'universal-geo-context' ) ),
			OperationalStatus::STATE_UNAVAILABLE     => array( 'error', __( 'Unavailable', 'universal-geo-context' ) ),
		);

		$pair  = $variants[ $status->state ] ?? $variants[ OperationalStatus::STATE_READY ];
		$badge = $this->status_badge( $pair[1], $pair[0] );
		$hint  = $status->consumer_usable
			? __( 'Consumers can rely on geographic context', 'universal-geo-context' )
			: __( 'Fix configuration before trusting visitor location', 'universal-geo-context' );

		if ( $status->simulation_active ) {
			$hint .= ' — ' . __( 'simulation active', 'universal-geo-context' );
		}

		return sprintf(
			'<a class="ugc-ui-health-summary" href="%1$s"><span class="ugc-ui-health-summary__label">%2$s</span>%3$s<span class="ugc-ui-health-summary__hint">%4$s</span></a>',
			esc_url( $url ),
			esc_html__( 'Visitor Location', 'universal-geo-context' ),
			$badge,
			esc_html( $hint )
		);
	}

	/**
	 * Renders a health summary panel linking to diagnostics.
	 *
	 * @param string $status One of critical, recommended, good.
	 * @param string $url    Diagnostics page URL.
	 */
	public function health_summary_panel( string $status, string $url ): string {
		$variants = array(
			'critical'    => array( 'error', __( 'Critical issues detected', 'universal-geo-context' ) ),
			'recommended' => array( 'warning', __( 'Recommended improvements available', 'universal-geo-context' ) ),
			'good'        => array( 'active', __( 'All systems healthy', 'universal-geo-context' ) ),
		);

		$pair  = $variants[ $status ] ?? $variants['good'];
		$badge = $this->status_badge( $pair[1], $pair[0] );

		return sprintf(
			'<a class="ugc-ui-health-summary" href="%1$s"><span class="ugc-ui-health-summary__label">%2$s</span>%3$s<span class="ugc-ui-health-summary__hint">%4$s</span></a>',
			esc_url( $url ),
			esc_html__( 'Overall Health', 'universal-geo-context' ),
			$badge,
			esc_html__( 'View full diagnostics report', 'universal-geo-context' )
		);
	}

	/**
	 * Renders a copy-report panel.
	 *
	 * @param string $textarea_id Element id.
	 * @param string $content     Read-only report text.
	 */
	public function copy_report_panel( string $textarea_id, string $content ): string {
		return sprintf(
			'<details class="ugc-ui-copy-report"><summary class="ugc-ui-copy-report__summary"><strong>%1$s</strong></summary><p class="ugc-ui-copy-report__description">%2$s</p><textarea class="ugc-ui-copy-report__textarea" id="%3$s" readonly rows="12" aria-label="%4$s">%5$s</textarea></details>',
			esc_html__( 'Copy report', 'universal-geo-context' ),
			esc_html__( 'Select the text below and copy it for support tickets. Values are already masked.', 'universal-geo-context' ),
			esc_attr( $textarea_id ),
			esc_attr__( 'Diagnostics report (read-only)', 'universal-geo-context' ),
			esc_textarea( $content )
		);
	}

	/**
	 * Builds attribute HTML from an associative array.
	 *
	 * @param array<string, string|null> $attrs Attributes.
	 */
	private function attr_html( array $attrs ): string {
		$html = '';

		foreach ( $attrs as $key => $attr_value ) {
			if ( null === $attr_value || '' === $attr_value ) {
				continue;
			}

			$html .= sprintf( ' %s="%s"', esc_attr( (string) $key ), esc_attr( (string) $attr_value ) );
		}

		return $html;
	}

	/**
	 * Renders a generic text/number field row.
	 *
	 * @param string                $type        Input type.
	 * @param string                $name        Field name.
	 * @param string                $label       Visible label.
	 * @param string                $value       Current value.
	 * @param string                $description Optional description.
	 * @param array<string, string> $attrs       Extra attributes.
	 */
	private function field_row(
		string $type,
		string $name,
		string $label,
		string $value,
		string $description = '',
		array $attrs = array()
	): string {
		$id        = $attrs['id'] ?? sanitize_key( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );
		$attr_html = $this->attr_html( array_diff_key( $attrs, array( 'id' => true ) ) );

		$description_html = '' !== $description
			? sprintf( '<span class="ugc-ui-field-row__description">%s</span>', esc_html( $description ) )
			: '';

		return sprintf(
			'<div class="ugc-ui-field-row ugc-ui-field-row--%1$s"><label class="ugc-ui-field-row__label" for="%2$s">%3$s</label>%4$s<div class="ugc-ui-field-row__control"><input type="%1$s" name="%5$s" id="%2$s" value="%6$s"%7$s /></div></div>',
			esc_attr( $type ),
			esc_attr( (string) $id ),
			esc_html( $label ),
			$description_html,
			esc_attr( $name ),
			esc_attr( $value ),
			$attr_html
		);
	}

	/**
	 * Renders a typed panel block.
	 *
	 * @param string $type        Panel type.
	 * @param string $title       Optional title.
	 * @param string $message     Message body.
	 * @param string $action_html Optional action markup.
	 */
	private function panel( string $type, string $title, string $message, string $action_html ): string {
		$title_html = '' !== $title
			? sprintf( '<h5 class="ugc-ui-panel__title">%s</h5>', esc_html( $title ) )
			: '';

		$action = '' !== $action_html
			? sprintf( '<div class="ugc-ui-panel__actions">%s</div>', $action_html ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by caller.
			: '';

		return sprintf(
			'<aside class="ugc-ui-panel ugc-ui-panel--%1$s" role="note">%2$s<p class="ugc-ui-panel__message">%3$s</p>%4$s</aside>',
			esc_attr( $type ),
			$title_html,
			esc_html( $message ),
			$action
		);
	}
}
