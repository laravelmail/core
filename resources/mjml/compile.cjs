// MJML -> HTML for the template builder (#87). Reads MJML on stdin, writes JSON {html, errors} to stdout.
// Run by App\Services\Templates\MjmlCompiler; needs the `mjml` npm package next to it (resources/mjml/node_modules).
'use strict';
const mjml2html = require('mjml');
let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (c) => { input += c; });
process.stdin.on('end', async () => {
    try {
        const out = await Promise.resolve(mjml2html(input, { validationLevel: 'soft', keepComments: false, ignoreIncludes: true }));
        process.stdout.write(JSON.stringify({ html: out.html, errors: (out.errors || []).map((e) => e.formattedMessage || e.message) }));
    } catch (e) {
        process.stdout.write(JSON.stringify({ html: null, errors: [String(e && e.message ? e.message : e)] }));
        process.exitCode = 1;
    }
});

