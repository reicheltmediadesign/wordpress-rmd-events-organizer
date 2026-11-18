<?php
/**
 * Adds dates, place and details to the page of a single event, so themes
 * need no special template.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer;

use RMD\EventsOrganizer\Render\EventRenderer;

defined( 'ABSPATH' ) || exit;

final class SingleView {

	public static function init(): void {
		add_filter( 'the_content', [ self::class, 'add_details' ], 20 );
	}

	/**
	 * @param mixed $content
	 * @return mixed
	 */
	public static function add_details( $content ) {
		if ( ! is_string( $content ) || ! is_singular( PostTypes::EVENT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( empty( ( (array) Settings::get( 'display', [] ) )['single_details'] ) ) {
			return $content;
		}
		$post_id = (int) get_the_ID();
		if ( get_queried_object_id() !== $post_id || false !== strpos( $content, 'rmd-eo-event ' ) ) {
			return $content;
		}

		return EventRenderer::render( $post_id ) . $content;
	}
}
