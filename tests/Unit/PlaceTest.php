<?php
declare(strict_types=1);

namespace RMD\EventsOrganizer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\EventsOrganizer\Domain\Place;

final class PlaceTest extends TestCase {

	public function test_city_is_added_to_the_name(): void {
		self::assertSame( 'Klosterpark Altzella, Nossen', Place::label( 'Klosterpark Altzella', 'Nossen' ) );
		self::assertSame( 'Stadthalle, Freiberg', Place::label( ' Stadthalle ', ' Freiberg ' ) );
	}

	public function test_city_is_left_out_when_the_name_already_says_it(): void {
		self::assertSame( 'Meißen', Place::label( 'Meißen', 'Meißen' ) );
		self::assertSame( 'Kreuzgang Meißen', Place::label( 'Kreuzgang Meißen', 'meißen' ) );
		self::assertSame( 'Galopprennbahn Dresden', Place::label( 'Galopprennbahn Dresden', 'Dresden' ) );
		self::assertSame( 'Dresden-Neustadt, Markthalle', Place::label( 'Dresden-Neustadt, Markthalle', 'Dresden' ) );
	}

	public function test_city_inside_another_word_does_not_count(): void {
		self::assertSame( 'Hallenbad, Halle', Place::label( 'Hallenbad', 'Halle' ) );
		self::assertSame( 'Staucha, Stauchitz', Place::label( 'Staucha', 'Stauchitz' ) );
	}

	public function test_one_of_the_two_missing(): void {
		self::assertSame( 'Obermarkt', Place::label( 'Obermarkt', '' ) );
		self::assertSame( 'Freiberg', Place::label( '', 'Freiberg' ) );
		self::assertSame( '', Place::label( '', '' ) );
	}
}
