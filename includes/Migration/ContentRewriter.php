<?php
/**
 * Finds pages, posts, templates and reusable blocks that use shortcodes or
 * blocks of Events Manager, and rewrites them to those of this plugin. The
 * original content of every changed post is kept, so each one can be put back.
 *
 * @package RMD\EventsOrganizer
 */

namespace RMD\EventsOrganizer\Migration;

use RMD\EventsOrganizer\Domain\Em\ContentConverter;
use RMD\EventsOrganizer\Domain\Em\ShortcodeMapper;
use RMD\EventsOrganizer\PostTypes;

defined( 'ABSPATH' ) || exit;

final class ContentRewriter {

	/** Post meta: content of the post before it was converted. */
	public const BACKUP = '_rmd_eo_em_content_backup';

	/** Options of that plugin naming the pages it filled itself => role. */
	private const OWN_PAGES = [
		'dbem_events_page'      => ContentConverter::ROLE_EVENTS,
		'dbem_locations_page'   => 'locations',
		'dbem_categories_page'  => 'categories',
		'dbem_tags_page'        => 'tags',
		'dbem_my_bookings_page' => 'bookings',
	];

	/**
	 * Everything that needs a look: posts with something to convert, posts
	 * with something that cannot be converted, and posts converted already.
	 *
	 * @return list<array{id: int, title: string, type: string, changes: list<array<string, mixed>>, open: int, converted: bool}>
	 *         "open" counts the changes a conversion would make now.
	 */
	public static function scan(): array {
		$rows = [];
		foreach ( self::candidates() as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$result = ContentConverter::convert( (string) $post->post_content, Targets::resolvers(), self::role( $post_id ) );
			$backup = metadata_exists( 'post', $post_id, self::BACKUP );
			if ( [] === $result['changes'] && ! $backup ) {
				continue;
			}
			$type   = get_post_type_object( $post->post_type );
			$rows[] = [
				'id'        => $post_id,
				'title'     => '' !== $post->post_title ? $post->post_title : '#' . $post_id,
				'type'      => $type ? (string) $type->labels->singular_name : $post->post_type,
				'changes'   => $result['changes'],
				'open'      => $result['content'] === $post->post_content ? 0 : count( array_filter( $result['changes'], static fn( array $change ): bool => '' !== $change['to'] ) ),
				'converted' => $backup,
			];
		}
		return $rows;
	}

	/**
	 * @return bool True when the post was changed.
	 */
	public static function apply( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post_id, self::candidates(), true ) ) {
			return false;
		}
		$result = ContentConverter::convert( (string) $post->post_content, Targets::resolvers(), self::role( $post_id ) );
		if ( $result['content'] === $post->post_content ) {
			return false;
		}
		if ( ! metadata_exists( 'post', $post_id, self::BACKUP ) ) {
			add_post_meta( $post_id, self::BACKUP, wp_slash( (string) $post->post_content ), true );
		}
		$updated = wp_update_post(
			wp_slash(
				[
					'ID'           => $post_id,
					'post_content' => $result['content'],
				]
			),
			true
		);
		return ! is_wp_error( $updated );
	}

	/**
	 * @return int Number of posts changed.
	 */
	public static function apply_all(): int {
		$count = 0;
		foreach ( self::candidates() as $post_id ) {
			$count += self::apply( $post_id ) ? 1 : 0;
		}
		return $count;
	}

	/**
	 * Puts the content back that the post had before it was converted.
	 */
	public static function undo( int $post_id ): bool {
		if ( ! get_post( $post_id ) || ! metadata_exists( 'post', $post_id, self::BACKUP ) ) {
			return false;
		}
		$updated = wp_update_post(
			wp_slash(
				[
					'ID'           => $post_id,
					'post_content' => (string) get_post_meta( $post_id, self::BACKUP, true ),
				]
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return false;
		}
		delete_post_meta( $post_id, self::BACKUP );
		return true;
	}

	/**
	 * @return int Number of posts put back.
	 */
	public static function undo_all(): int {
		$count = 0;
		foreach ( self::converted() as $post_id ) {
			$count += self::undo( $post_id ) ? 1 : 0;
		}
		return $count;
	}

	/**
	 * @return list<int>
	 */
	public static function converted(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A plain list of ids, needed once per page view.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC", self::BACKUP ) ) );
	}

	/**
	 * Role of a page that plugin filled itself, or ''.
	 */
	public static function role( int $post_id ): string {
		foreach ( self::OWN_PAGES as $option => $role ) {
			if ( (int) get_option( $option ) === $post_id ) {
				return $role;
			}
		}
		return '';
	}

	/**
	 * Id of the page that showed the list of events, or 0.
	 */
	public static function events_page(): int {
		$page_id = (int) get_option( 'dbem_events_page' );
		$page    = $page_id > 0 ? get_post( $page_id ) : null;
		return $page && 'page' === $page->post_type && 'trash' !== $page->post_status ? $page_id : 0;
	}

	/**
	 * Posts whose content may contain something of that plugin, its own pages
	 * and the posts converted before.
	 *
	 * @return list<int>
	 */
	private static function candidates(): array {
		static $found = null;
		if ( null === $found ) {
			$found = self::search();
		}
		$ids = $found;

		foreach ( array_keys( self::OWN_PAGES ) as $option ) {
			$page_id = (int) get_option( $option );
			$page    = $page_id > 0 ? get_post( $page_id ) : null;
			if ( $page && 'page' === $page->post_type && 'trash' !== $page->post_status ) {
				$ids[] = $page_id;
			}
		}
		$ids = array_values( array_unique( array_merge( $ids, self::converted() ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * @return list<int>
	 */
	private static function search(): array {
		global $wpdb;

		$likes = [ $wpdb->prepare( 'post_content LIKE %s', '%' . $wpdb->esc_like( 'wp:events-manager/' ) . '%' ) ];
		foreach ( ShortcodeMapper::TAGS as $tag ) {
			$likes[] = $wpdb->prepare( 'post_content LIKE %s', '%' . $wpdb->esc_like( '[' . $tag ) . '%' );
		}
		$skip = [ EmReader::POST_TYPE_EVENT, EmReader::POST_TYPE_RECURRING, EmReader::POST_TYPE_LOCATION, PostTypes::EVENT, PostTypes::LOCATION, 'revision', 'nav_menu_item', 'attachment' ];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Every part is prepared above.
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND post_type NOT IN ( '" . implode( "', '", array_map( 'esc_sql', $skip ) ) . "' ) AND ( " . implode( ' OR ', $likes ) . ' ) ORDER BY ID ASC'
			)
		);
		// phpcs:enable
	}
}
