'use strict';

// Deterministic pseudo-randomness for the year generator.
//
// Inline rather than a dependency: a test fixture that reaches for npm to get a number
// generator has bought a supply-chain input for eleven lines of arithmetic, and the
// harness's one dependency (playwright, in the ui phase) is the exception package.json
// argues for by name.
//
// **Streams, not one sequence, and that is the load-bearing part.** With a single stream,
// adding an emitter shifts every draw after it and the whole year changes — the same hazard
// scenarios/index.js warns about ("adding a scenario in the middle changes what every later
// scenario sees"), except here it would be invisible and would invalidate a committed
// baseline for no stated reason. Each emitter draws from a stream seeded by hashing its own
// name with the run seed, so a new emitter leaves every other emitter's year byte-identical.

function mulberry32(a) {
	return function next() {
		a |= 0;
		a = (a + 0x6d2b79f5) | 0;
		let t = Math.imul(a ^ (a >>> 15), 1 | a);
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
	};
}

// FNV-1a over the stream name, mixed with the run seed. Cheap, and stable across Node
// versions in a way `crypto.createHash` would also be but at more ceremony than a label
// needs.
function streamFor(runSeed, name) {
	let h = 0x811c9dc5;
	for (let i = 0; i < name.length; i++) {
		h ^= name.charCodeAt(i);
		h = Math.imul(h, 0x01000193);
	}
	return mulberry32((h ^ runSeed) >>> 0);
}

// The drawing helpers. Every one of them takes the stream explicitly — there is no ambient
// generator to reach for, because `Math.random` anywhere in this directory would make the
// plan unreproducible and the failure would look like a flaky suite rather than a bug.
function intBetween(rng, lo, hi) {
	return lo + Math.floor(rng() * (hi - lo + 1));
}

function pick(rng, array) {
	return array[Math.floor(rng() * array.length)];
}

// Picks `n` distinct elements, or as many as there are.
function sample(rng, array, n) {
	const pool = [...array];
	const out = [];
	while (out.length < n && pool.length > 0) {
		out.push(pool.splice(Math.floor(rng() * pool.length), 1)[0]);
	}
	return out;
}

function weighted(rng, pairs) {
	const total = pairs.reduce((sum, [, w]) => sum + w, 0);
	let roll = rng() * total;
	for (const [value, w] of pairs) {
		roll -= w;
		if (roll <= 0) return value;
	}
	return pairs[pairs.length - 1][0];
}

// True with probability p.
function chance(rng, p) {
	return rng() < p;
}

module.exports = { mulberry32, streamFor, intBetween, pick, sample, weighted, chance };
