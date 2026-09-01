const mysql = require('mysql2');
require('./env');

const pool = mysql.createPool({
  host: process.env.DB_HOST || 'localhost',
  port: Number(process.env.DB_PORT || 3306),
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'resok_portal',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
});

pool.getConnection((err, connection) => {
  if (err) {
    console.error('Database connection failed:', err.message);
    console.error('API routes that need the database will return errors until .env and MySQL are configured.');
    return;
  }
  console.log('Database connected');
  connection.release();
});

module.exports = pool.promise();
