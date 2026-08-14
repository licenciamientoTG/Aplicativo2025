const { Client } = require(process.env.SSH2_PATH);

const connection = new Client();
connection.on('ready', () => {
  connection.exec(process.env.REMOTE_COMMAND || 'schtasks /query /tn "TotalGasConciliacionAlertas" /fo list /v', (error, stream) => {
    if (error) throw error;
    stream.on('close', (code) => { connection.end(); process.exitCode = code || 0; });
    stream.on('data', (data) => process.stdout.write(data));
    stream.stderr.on('data', (data) => process.stderr.write(data));
  });
}).on('error', (error) => { console.error(error.message); process.exitCode = 1; })
  .connect({ host: process.env.SSH_HOST, username: process.env.SSH_USER, password: process.env.SSH_PASSWORD, readyTimeout: 15000 });
