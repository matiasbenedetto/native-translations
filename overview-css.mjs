// Manage the vendored @wordpress/dataviews stylesheet for the Overview app.
//
// @wordpress/scripts' CSS extraction drops the bundled-package CSS import and
// emits no file, so we vendor a copy of the DataViews stylesheet into the build
// output. This script keeps that copy honest:
//
//   node overview-css.mjs copy    Refresh the committed copy from node_modules.
//   node overview-css.mjs check   Fail (exit 1) if the committed copy has drifted
//                                 from the installed @wordpress/dataviews version.
//
// The source path is resolved via require.resolve (not a hardcoded string) so a
// future relocation of build-style/style.css surfaces as a clear resolve error.
import { readFileSync, writeFileSync, existsSync } from 'fs';
import { createRequire } from 'module';
import { dirname, join } from 'path';

const require = createRequire(import.meta.url);
const DEST = 'wp-ai-translate/build/overview/style-index.css';
const mode = process.argv[2];

// Resolve the package root via its package.json (build-style/style.css is not
// exposed in the package's `exports` map, so it can't be require.resolve'd
// directly). Deriving the path from the resolved package still surfaces a clear
// error if the stylesheet is ever relocated.
let src, version;
try {
  const pkgJson = require.resolve('@wordpress/dataviews/package.json');
  version = require('@wordpress/dataviews/package.json').version;
  src = join(dirname(pkgJson), 'build-style', 'style.css');
} catch ( e ) {
  console.error('ERROR: could not resolve @wordpress/dataviews. ' + e.message);
  process.exit(1);
}
if (!existsSync(src)) {
  console.error(
    `ERROR: ${src} not found — @wordpress/dataviews ${version} may have moved its ` +
    'stylesheet. Update overview-css.mjs.'
  );
  process.exit(1);
}

if (mode === 'copy') {
  writeFileSync(DEST, readFileSync(src));
  console.log(`copied DataViews ${version} stylesheet -> ${DEST}`);
} else if (mode === 'check') {
  let committed;
  try {
    committed = readFileSync(DEST, 'utf8');
  } catch {
    console.error(`ERROR: ${DEST} is missing. Run \`npm run build:overview-css\`.`);
    process.exit(1);
  }
  if (committed !== readFileSync(src, 'utf8')) {
    console.error(
      `ERROR: ${DEST} has drifted from the installed @wordpress/dataviews ` +
      `(${version}). The bundled JS is rebuilt on install but this CSS is not — ` +
      'they are now out of sync. Run `npm run build:overview-css` and commit the result.'
    );
    process.exit(1);
  }
  console.log(`OK: vendored DataViews stylesheet matches installed ${version}.`);
} else {
  console.error('Usage: node overview-css.mjs <copy|check>');
  process.exit(2);
}
