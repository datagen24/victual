'use strict';

// Simulated dates, and the windows an operation is allowed to land in.
//
// Everything is UTC. Both stacks run TZ=UTC and the faked clock is set in UTC, so a local
// timezone anywhere in here would be a second clock disagreeing with the first.
//
// **Day zero is the anchor, and the anchor is historical.** The year spans anchor-365 to
// anchor-1, so it never contains "today": a run must not depend on what the real calendar
// says while it is going on. Because the clock is faked and InfluxDB queries carry absolute
// bounds, a historical anchor costs nothing — which is what lets a committed baseline stay
// comparable months later.

const MS_PER_DAY = 86400000;

function parseAnchor(text) {
	// Accepts a date or a full instant; a bare date means midnight, which is where a
	// simulated year should start rather than at whatever time the run happened to begin.
	const iso = /^\d{4}-\d{2}-\d{2}$/.test(text) ? `${text}T00:00:00Z` : `${text.replace(' ', 'T')}Z`;
	const ms = Date.parse(iso);
	if (Number.isNaN(ms)) throw new Error(`anchor "${text}" is not a date or an instant`);
	return ms;
}

function fmtDate(ms) {
	return new Date(ms).toISOString().slice(0, 10);
}

function fmtDateTime(ms) {
	return new Date(ms).toISOString().slice(0, 19).replace('T', ' ');
}

// A calendar for one run. `dayMs(n)` is midnight on simulated day n, counting from the
// start of the year rather than from the anchor, because every emitter thinks in "week 7,
// Tuesday" and nothing thinks in "83 days before the anchor".
function makeCalendar({ anchor, days = 365 }) {
	const anchorMs = parseAnchor(anchor);
	const startMs = anchorMs - days * MS_PER_DAY;

	const cal = {
		anchor,
		anchorMs,
		startMs,
		days,

		dayMs: (n) => startMs + n * MS_PER_DAY,
		date: (n) => fmtDate(startMs + n * MS_PER_DAY),
		at: (n, hour = 0, minute = 0) => fmtDateTime(startMs + n * MS_PER_DAY + hour * 3600000 + minute * 60000),

		// 0 = Monday. The narrative is written in weekdays because a household is.
		weekday: (n) => (new Date(startMs + n * MS_PER_DAY).getUTCDay() + 6) % 7,
		week: (n) => Math.floor(n / 7),
		month: (n) => new Date(startMs + n * MS_PER_DAY).getUTCMonth(),
		dayOfMonth: (n) => new Date(startMs + n * MS_PER_DAY).getUTCDate(),
		isMonthStart: (n) => new Date(startMs + n * MS_PER_DAY).getUTCDate() === 1,
		isLeapDay: (n) => {
			const d = new Date(startMs + n * MS_PER_DAY);
			return d.getUTCMonth() === 1 && d.getUTCDate() === 29;
		},

		// A date offset from a simulated day, for best-before dates that must fall outside
		// the year as well as inside it.
		dateOffset: (n, deltaDays) => fmtDate(startMs + (n + deltaDays) * MS_PER_DAY),

		// The interval an operation on day n is allowed to be observed in. A whole day by
		// default; an hour when the operation belongs to an intra-day sequence, because id
		// ordering can prove two same-day events happened in order but cannot prove one
		// landed in the right hour.
		dayWindow: (n) => ({ from: fmtDateTime(startMs + n * MS_PER_DAY), to: fmtDateTime(startMs + (n + 1) * MS_PER_DAY) }),
		hourWindow: (n, hour) => ({
			from: fmtDateTime(startMs + n * MS_PER_DAY + hour * 3600000),
			to: fmtDateTime(startMs + n * MS_PER_DAY + (hour + 1) * 3600000)
		})
	};

	return cal;
}

module.exports = { makeCalendar, parseAnchor, fmtDate, fmtDateTime, MS_PER_DAY };
