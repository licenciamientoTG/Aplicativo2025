const fs = require('fs');
const path = require('path');
const { Client } = require(process.env.SSH2_PATH);

const remoteDir = 'C:/Software/TareasProgramadas/conc';
const localDir = __dirname;
const config = JSON.stringify({
  driver: 'ODBC Driver 17 for SQL Server', server: '192.168.0.6',
  user: process.env.TG_DB_USER, password: process.env.TG_DB_PASSWORD,
}, null, 2);
const client = new Client();

function exec(command) {
  return new Promise((resolve, reject) => client.exec(command, (error, stream) => {
    if (error) return reject(error);
    let stdout = '', stderr = '';
    stream.on('data', data => stdout += data);
    stream.stderr.on('data', data => stderr += data);
    stream.on('close', code => code === 0 ? resolve(stdout) : reject(new Error(stderr || stdout || `exit ${code}`)));
  }));
}

client.on('ready', async () => {
  try {
    await exec(`powershell -NoProfile -Command "New-Item -ItemType Directory -Force -Path '${remoteDir}' | Out-Null"`);
    const sftp = await new Promise((resolve, reject) => client.sftp((error, value) => error ? reject(error) : resolve(value)));
    const upload = (source, destination) => new Promise((resolve, reject) => sftp.fastPut(source, destination, error => error ? reject(error) : resolve()));
    await upload(path.join(localDir, 'conciliacion_alertas.py'), `${remoteDir}/conciliacion_alertas.py`);
    await new Promise((resolve, reject) => sftp.writeFile(`${remoteDir}/config.json`, config, error => error ? reject(error) : resolve()));
    const task = 'schtasks /Create /TN "TotalGasConciliacionAlertas" /TR "\\\"C:\\python31210\\python.exe\\\" \\\"C:\\Software\\TareasProgramadas\\conc\\conciliacion_alertas.py\\\"" /SC MINUTE /MO 30 /RU SYSTEM /F';
    await exec(task);
    await exec('schtasks /Run /TN "TotalGasConciliacionAlertas"');
    console.log('Script y tarea programada instalados.');
  } catch (error) { console.error(error.message); process.exitCode = 1; }
  finally { client.end(); }
}).on('error', error => { console.error(error.message); process.exitCode = 1; })
  .connect({host:process.env.SSH_HOST, username:process.env.SSH_USER, password:process.env.SSH_PASSWORD, readyTimeout:15000});
