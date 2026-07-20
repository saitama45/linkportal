require('dotenv').config({ path: require('path').resolve(__dirname, '.env.e2e') });
const { backupDb } = require('./support/backup');
const { artisan } = require('./support/artisan');

// Remove the kept demo documents (run when you're done with the walkthrough).
// Backs up the database first.
console.log('[purge-demo] Backing up the database first...');
backupDb();

const res = artisan('purge-demo');
console.log('[purge-demo] Removed demo documents:', JSON.stringify(res.purged_demo));
