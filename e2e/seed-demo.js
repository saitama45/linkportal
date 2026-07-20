require('dotenv').config({ path: require('path').resolve(__dirname, '.env.e2e') });
const { backupDb } = require('./support/backup');
const { artisan } = require('./support/artisan');

// Seed editable demo documents (needs_validation, NOT approved) and keep them
// for a manual walkthrough. Backs up the database first.
console.log('[seed] Backing up the database first...');
backupDb();

const res = artisan('seed-demo');
console.log('\n[seed] Kept editable demo documents (status: needs validation):');
for (const d of res.seeded) {
  console.log(`   ${d.reference_no}  —  ${d.type}  (id ${d.id})`);
}
console.log('\nInspect them at /document-intake (Status filter: "needs validation").');
console.log('Open each one to edit fields, validate, submit, and approve — the workflow is yours to drive.');
console.log('They survive automated test runs. When you are finished: npm run qa:purge-demo');
