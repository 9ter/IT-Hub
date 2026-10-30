// Render the template-based Word document; requires LibreOffice and TH Sarabun New.
// Usage: LIBREOFFICE_BIN=/path/to/soffice node WI/tools/print_wi.cjs
const {spawnSync} = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const root = path.resolve(__dirname, '..');
const source = path.join(root, 'WI-IT-003.docx');
const target = path.join(root, 'WI-IT-003.pdf');
if (!fs.existsSync(source)) throw Error('Run python3 WI/tools/build_wi.py first');
const started = Date.now();
const result = spawnSync(process.env.LIBREOFFICE_BIN || 'libreoffice',
  ['--headless', '--convert-to', 'pdf', '--outdir', root, source],
  {encoding: 'utf8', timeout: 120000});
if (result.stdout) process.stdout.write(result.stdout);
if (result.stderr) process.stderr.write(result.stderr);
if (result.error) throw result.error;
if (result.status !== 0 || !fs.existsSync(target) || fs.statSync(target).mtimeMs < started - 2000)
  throw Error('Word PDF conversion did not produce a current PDF');
console.log('Rendered WI-IT-003.pdf from the template-based Word document');
