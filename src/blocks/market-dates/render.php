<?php
/**
 * Server render of the "Market Dates" block.
 *
 * @var array $attributes Block attributes.
 *
 * @package RMD\EventsOrganizer
 */

defined( 'ABSPATH' ) || exit;

echo RMD\EventsOrganizer\Blocks::render_market_dates( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes.
