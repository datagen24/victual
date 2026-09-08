// The RFC 8785 oracle the PHP canonicalizer is checked against.
//
//   node .devtools/labels/canonical-json-oracle.js <corpus.jsonl>
//
// Correct by construction rather than by testing, which is the only reason a differential
// test against it means anything: JSON.stringify already produces RFC 8785's number layout,
// because RFC 8785's number layout *is* ECMAScript's Number::toString; JSON.stringify already
// escapes exactly the characters RFC 8785 escapes; and Array.prototype.sort on strings already
// compares UTF-16 code units, which is the member ordering the specification names. So the
// whole of it is the four lines of canon() below, and nothing in it is a reimplementation of
// something the PHP side also had to write.
//
// The corpus is one JSON object per line: {"doc": <document>, "php": "<canonical text>"}.
// Documents whose canonical form PHP refuses carry "php": null and are not compared - a
// refusal is asserted by the vectors in the PHP program, not here.

function canon(value)
{
	if (value === null || typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string')
	{
		return JSON.stringify(value);
	}

	if (Array.isArray(value))
	{
		return '[' + value.map(canon).join(',') + ']';
	}

	return '{' + Object.keys(value).sort().map(k => JSON.stringify(k) + ':' + canon(value[k])).join(',') + '}';
}

const path = process.argv[2];

if (!path)
{
	console.error('usage: node canonical-json-oracle.js <corpus.jsonl>');
	process.exit(2);
}

const lines = require('fs').readFileSync(path, 'utf8').split('\n').filter(l => l.length > 0);

let compared = 0;

for (const [index, line] of lines.entries())
{
	const record = JSON.parse(line);

	if (record.php === null)
	{
		continue;
	}

	const expected = canon(record.doc);

	if (expected !== record.php)
	{
		console.error(`Line ${index + 1} differs.`);
		console.error(`  oracle: ${expected}`);
		console.error(`  php   : ${record.php}`);
		process.exit(1);
	}

	compared++;
}

console.log(`  oracle agreed on ${compared} documents`);
