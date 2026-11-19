/**
 * Pure helpers for the event editor state. The shape mirrors what
 * Domain\Validator produces in PHP; PHP stays the authority and validates
 * everything again on save.
 */

export function newId() {
	const bytes = new Uint8Array( 4 );
	if ( typeof window !== 'undefined' && window.crypto?.getRandomValues ) {
		window.crypto.getRandomValues( bytes );
	} else {
		for ( let i = 0; i < bytes.length; i++ ) {
			bytes[ i ] = Math.floor( Math.random() * 256 );
		}
	}
	return Array.from( bytes, ( byte ) =>
		byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );
}

const TIMES = {
	all_day: false,
	start_time: '',
	end_time: '',
	time_mode: 'continuous',
};

export function newSingle( date ) {
	return {
		id: newId(),
		type: 'single',
		start_date: date,
		end_date: date,
		...TIMES,
	};
}

export function newRule( date ) {
	return {
		id: newId(),
		type: 'rule',
		freq: 'weekly',
		interval: 1,
		weekdays: [],
		month_mode: 'day_of_month',
		nth: 0,
		from: date,
		until: '',
		count: 0,
		duration_days: 1,
		...TIMES,
	};
}

/**
 * Switches an entry between a single date and a rule, keeping its id, start
 * and times.
 *
 * @param {Object} entry Entry to convert.
 * @param {string} type  "single" or "rule".
 * @param {string} today Fallback date.
 * @return {Object} Converted entry.
 */
export function convertEntry( entry, type, today ) {
	if ( entry.type === type ) {
		return entry;
	}
	const times = {
		all_day: entry.all_day,
		start_time: entry.start_time,
		end_time: entry.end_time,
		time_mode: entry.time_mode,
	};
	if ( type === 'rule' ) {
		return {
			...newRule( entry.start_date || today ),
			...times,
			id: entry.id,
			duration_days: daysBetween( entry.start_date, entry.end_date ) + 1,
		};
	}
	const start = entry.from || today;
	return {
		...newSingle( start ),
		...times,
		id: entry.id,
		end_date: addDays( start, ( entry.duration_days || 1 ) - 1 ),
	};
}

export function normalizeData( raw ) {
	const data = raw && typeof raw === 'object' ? raw : {};
	const schedule =
		data.schedule && typeof data.schedule === 'object' ? data.schedule : {};
	return {
		kind: data.kind === 'market' ? 'market' : 'event',
		schedule: {
			version: 1,
			entries: Array.isArray( schedule.entries ) ? schedule.entries : [],
			exclusions: Array.isArray( schedule.exclusions )
				? schedule.exclusions
				: [],
			overrides:
				schedule.overrides &&
				typeof schedule.overrides === 'object' &&
				! Array.isArray( schedule.overrides )
					? schedule.overrides
					: {},
		},
		details: {
			status: 'scheduled',
			organizer_name: '',
			organizer_url: '',
			info_url: '',
			price_text: '',
			stall: '',
			stall_note: '',
			...( data.details && typeof data.details === 'object'
				? data.details
				: {} ),
		},
		location: {
			id: parseInt( data.location?.id, 10 ) || 0,
			new: null,
		},
	};
}

/**
 * Marks one date as cancelled or restores it.
 *
 * @param {Object}  schedule  Schedule.
 * @param {string}  key       Occurrence key.
 * @param {boolean} cancelled New state.
 * @return {Object} Updated schedule.
 */
export function setCancelled( schedule, key, cancelled ) {
	return setDateStatus( schedule, key, cancelled ? 'cancelled' : '' );
}

/**
 * Gives one date a status of its own or restores it.
 *
 * @param {Object} schedule Schedule.
 * @param {string} key      Occurrence key.
 * @param {string} next     'cancelled', 'withdrawn' (a market takes place without us) or '' to restore.
 * @return {Object} Updated schedule.
 */
export function setDateStatus( schedule, key, next ) {
	const overrides = { ...schedule.overrides };
	const current = overrides[ key ] || {};
	if ( next ) {
		overrides[ key ] = { ...current, status: next };
	} else {
		const { status, ...rest } = current;
		if ( Object.values( rest ).some( ( value ) => value ) ) {
			overrides[ key ] = rest;
		} else {
			delete overrides[ key ];
		}
	}
	return { ...schedule, overrides };
}

/**
 * Where an entry stands, for the coloured stripe beside it: 'past' (over),
 * 'off' (still to come, but cancelled or without us), 'upcoming' (still to
 * come and taking place) or '' while it has no date.
 *
 * @param {Object}  entry    Entry of the schedule.
 * @param {Object}  schedule Schedule, for the changes to single dates.
 * @param {Object}  details  Details, for the status of the whole event.
 * @param {boolean} isMarket Only a market can take place without us.
 * @param {string}  today    "YYYY-MM-DD".
 * @return {string} State.
 */
export function entryState( entry, schedule, details, isMarket, today ) {
	const isRule = entry.type === 'rule';
	const first = isRule ? entry.from : entry.start_date;
	if ( ! isDate( first ) ) {
		return '';
	}
	const last = isRule ? entry.until : entry.end_date || entry.start_date;
	if ( isDate( last ) && last < today ) {
		return 'past';
	}
	const off = ( status ) =>
		status === 'cancelled' || ( isMarket && status === 'withdrawn' );
	if (
		off( details?.status ) ||
		( ! isRule && off( schedule.overrides?.[ entry.id ]?.status ) )
	) {
		return 'off';
	}
	return 'upcoming';
}

/**
 * Leaves out one date of a rule. The key of a rule date is "<id>:<date>".
 *
 * @param {Object} schedule Schedule.
 * @param {string} key      Occurrence key.
 * @return {Object} Updated schedule.
 */
export function excludeDate( schedule, key ) {
	const date = key.split( ':' )[ 1 ];
	if ( ! isDate( date ) ) {
		return schedule;
	}
	const overrides = { ...schedule.overrides };
	delete overrides[ key ];
	return {
		...schedule,
		overrides,
		exclusions: [ ...schedule.exclusions, { from: date, to: date } ],
	};
}

export function hasRule( schedule ) {
	return schedule.entries.some( ( entry ) => entry.type === 'rule' );
}

export function isDate( value ) {
	return (
		typeof value === 'string' &&
		/^\d{4}-\d{2}-\d{2}$/.test( value ) &&
		! Number.isNaN( Date.parse( value + 'T00:00:00Z' ) )
	);
}

export function addDays( date, days ) {
	if ( ! isDate( date ) ) {
		return date;
	}
	const next = new Date( date + 'T00:00:00Z' );
	next.setUTCDate( next.getUTCDate() + days );
	return next.toISOString().slice( 0, 10 );
}

export function daysBetween( from, to ) {
	if ( ! isDate( from ) || ! isDate( to ) ) {
		return 0;
	}
	return Math.max(
		0,
		Math.round(
			( Date.parse( to + 'T00:00:00Z' ) -
				Date.parse( from + 'T00:00:00Z' ) ) /
				86400000
		)
	);
}

/**
 * Weekday id ("mon" … "sun") of a date.
 *
 * @param {string} date Date as YYYY-MM-DD.
 * @return {string} Weekday id, or an empty string.
 */
export function weekdayOf( date ) {
	if ( ! isDate( date ) ) {
		return '';
	}
	return [ 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ][
		new Date( date + 'T00:00:00Z' ).getUTCDay()
	];
}
