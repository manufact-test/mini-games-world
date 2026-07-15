import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

function trackedFiles(pattern) {
  const output = execFileSync('git', ['ls-files', '-z', pattern]);
  return output
    .toString('utf8')
    .split('\0')
    .filter(Boolean)
    .sort();
}

const files = trackedFiles('*.json');
const errors = [];

for (const file of files) {
  try {
    const source = readFileSync(file, 'utf8').replace(/^\uFEFF/, '');
    JSON.parse(source);
  } catch (error) {
    errors.push(`${file}: ${error instanceof Error ? error.message : String(error)}`);
  }
}

if (errors.length > 0) {
  console.error('Invalid JSON detected:');
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`JSON validation passed: ${files.length} files`);
