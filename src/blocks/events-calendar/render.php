<?php
/**
 * Server render of the "Events Calendar" block.
 *
 * @var array $attributes Block attributes.
 *
 * @package RMD\EventsOrganizer
 */

defined( 'ABSPATH' ) || exit;

echo RMD\EventsOrganizer\Blocks::render_events_calendar( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes.
