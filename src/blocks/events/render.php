<?php
/**
 * Server render of the "Events" block.
 *
 * @var array $attributes Block attributes.
 *
 * @package RMD\EventsOrganizer
 */

defined( 'ABSPATH' ) || exit;

echo RMD\EventsOrganizer\Blocks::render_events( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes.
