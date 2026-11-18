<?php
/**
 * Server render of the "Event Details" block.
 *
 * @var array $attributes Block attributes.
 *
 * @package RMD\EventsOrganizer
 */

defined( 'ABSPATH' ) || exit;

echo RMD\EventsOrganizer\Blocks::render_event_details( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes.
