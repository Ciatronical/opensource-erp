// Chrome fuer die Portal-Wiedergabe liegt im Projekt, nicht im Home des
// jeweiligen Nutzers: Webserver (www-data) und Cron (work) nutzen denselben.
const { join } = require('path');
module.exports = { cacheDirectory: join(__dirname, '.cache') };
