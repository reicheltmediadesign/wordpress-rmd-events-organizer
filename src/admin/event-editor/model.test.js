import {
	addDays,
	convertEntry,
	daysBetween,
	entryState,
	excludeDate,
	newSingle,
	normalizeData,
	setCancelled,
	setDateStatus,
	weekdayOf,
} from './model';

describe( 'date helpers', () => {
	test( 'addDays crosses month and year ends', () => {
		expect( addDays( '2026-12-30', 3 ) ).toBe( '2027-01-02' );
		expect( addDays( 'nope', 3 ) ).toBe( 'nope' );
	} );

	test( 'daysBetween never goes negative', () => {
		expect( daysBetween( '2026-12-12', '2026-12-14' ) ).toBe( 2 );
		expect( daysBetween( '2026-12-14', '2026-12-12' ) ).toBe( 0 );
	} );

	test( 'weekdayOf', () => {
		expect( weekdayOf( '2026-01-01' ) ).toBe( 'thu' );
		expect( weekdayOf( '' ) ).toBe( '' );
	} );
} );

describe( 'convertEntry', () => {
	test( 'keeps id, start and times when a date becomes a rule', () => {
		const single = {
			...newSingle( '2026-12-12' ),
			id: 'abcd1234',
			end_date: '2026-12-14',
			start_time: '10:00',
		};
		const rule = convertEntry( single, 'rule', '2026-01-01' );

		expect( rule.id ).toBe( 'abcd1234' );
		expect( rule.type ).toBe( 'rule' );
		expect( rule.from ).toBe( '2026-12-12' );
		expect( rule.duration_days ).toBe( 3 );
		expect( rule.start_time ).toBe( '10:00' );

		const back = convertEntry( rule, 'single', '2026-01-01' );
		expect( back.start_date ).toBe( '2026-12-12' );
		expect( back.end_date ).toBe( '2026-12-14' );
	} );
} );

describe( 'overrides and exclusions', () => {
	const schedule = normalizeData( {} ).schedule;

	test( 'cancel and restore a date', () => {
		const cancelled = setCancelled( schedule, 'aaaa:2026-06-06', true );
		expect( cancelled.overrides[ 'aaaa:2026-06-06' ].status ).toBe(
			'cancelled'
		);
		expect(
			setCancelled( cancelled, 'aaaa:2026-06-06', false ).overrides
		).toEqual( {} );
	} );

	test( 'excluding a rule date drops its override', () => {
		const cancelled = setCancelled( schedule, 'aaaa:2026-06-06', true );
		const excluded = excludeDate( cancelled, 'aaaa:2026-06-06' );

		expect( excluded.overrides ).toEqual( {} );
		expect( excluded.exclusions ).toEqual( [
			{ from: '2026-06-06', to: '2026-06-06' },
		] );
		expect( excludeDate( schedule, 'aaaa' ) ).toBe( schedule );
	} );
} );

describe( 'entryState', () => {
	const single = {
		id: 'aaaa',
		type: 'single',
		start_date: '2026-12-12',
		end_date: '2026-12-14',
	};
	const none = { overrides: {} };

	it( 'tells past, upcoming and undated entries apart', () => {
		expect( entryState( single, none, {}, true, '2026-12-14' ) ).toBe(
			'upcoming'
		);
		expect( entryState( single, none, {}, true, '2026-12-15' ) ).toBe(
			'past'
		);
		expect(
			entryState(
				{ ...single, start_date: '' },
				none,
				{},
				true,
				'2026-01-01'
			)
		).toBe( '' );
	} );

	it( 'marks upcoming dates that are cancelled or without us', () => {
		const withdrawn = setDateStatus( none, 'aaaa', 'withdrawn' );
		expect( entryState( single, withdrawn, {}, true, '2026-11-01' ) ).toBe(
			'off'
		);
		// Only a market can take place without us.
		expect( entryState( single, withdrawn, {}, false, '2026-11-01' ) ).toBe(
			'upcoming'
		);
		expect(
			entryState(
				single,
				none,
				{ status: 'cancelled' },
				false,
				'2026-11-01'
			)
		).toBe( 'off' );
		expect(
			setDateStatus( withdrawn, 'aaaa', '' ).overrides.aaaa
		).toBeUndefined();
	} );

	it( 'treats a rule as upcoming until its last date has passed', () => {
		const rule = {
			id: 'bbbb',
			type: 'rule',
			from: '2026-01-06',
			until: '',
		};
		expect( entryState( rule, none, {}, false, '2030-01-01' ) ).toBe(
			'upcoming'
		);
		expect(
			entryState(
				{ ...rule, until: '2026-06-30' },
				none,
				{},
				false,
				'2026-07-01'
			)
		).toBe( 'past' );
	} );
} );

describe( 'normalizeData', () => {
	test( 'fills gaps and ignores garbage', () => {
		const data = normalizeData( {
			kind: 'party',
			schedule: { entries: 'x', overrides: [] },
			location: { id: '7' },
		} );

		expect( data.kind ).toBe( 'event' );
		expect( data.schedule.entries ).toEqual( [] );
		expect( data.schedule.overrides ).toEqual( {} );
		expect( data.location.id ).toBe( 7 );
		expect( data.details.status ).toBe( 'scheduled' );
	} );
} );
